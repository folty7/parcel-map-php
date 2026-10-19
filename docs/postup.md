# Postup implementácie – krok po kroku

## Cieľová štruktúra repozitára
```
viagem-parcely/
├─ docker-compose.yml
├─ .env.example                 # DB heslo, MAPY_API_KEY (voliteľné)
├─ docker/
│  ├─ php/Dockerfile            # php:8.3-fpm + pdo_pgsql + gdal-bin + composer
│  └─ nginx/default.conf
├─ backend/
│  ├─ public/index.php          # front controller (jediný vstup HTTP)
│  ├─ bin/import                # CLI import dát
│  ├─ bin/warm-cache            # predhriatie dlaždíc
│  ├─ sql/001_schema.sql, 002_postprocess.sql
│  ├─ src/
│  │  ├─ Http/        Router, Request, Response, JsonResponse, HttpException, ErrorHandler
│  │  ├─ Controller/  TileController, ParcelController, SearchController, HealthController
│  │  ├─ Service/     TileService, TileCache
│  │  ├─ Repository/  ParcelRepository, CadastralAreaRepository
│  │  ├─ Domain/      Parcel, ParcelNumber, LandType (enum), TileCoord
│  │  ├─ Import/      MunicipalityResolver, VfrDownloader, OgrRunner, PostProcessor, ImportCommand
│  │  └─ Infrastructure/ Config, Database (PDO factory)
│  ├─ tests/
│  ├─ var/            # cache dlaždíc, stiahnuté dáta (v .gitignore)
│  ├─ composer.json, phpstan.neon, phpunit.xml
├─ frontend/          # Vite + React + TS
└─ README.md          # spustenie + zápisník
```

---

## FÁZA 1: MVP

### Krok 0: Príprava (15 min)
- Nainštaluj Docker Desktop, Node 20+, git; voliteľne QGIS na prezeranie dát.
- `git init`, `.gitignore` (`backend/var/`, `vendor/`, `node_modules/`, `frontend/dist/`, `.env`).
- **Hotovo, keď:** máš prázdny repozitár s README nadpisom.
- **Commit:** `chore: init repository`

### Krok 1: Docker infraštruktúra (45 min)
**Cieľ:** jeden príkaz spustí DB, PHP a nginx.
- `docker-compose.yml` so službami:
  - `db`: `postgis/postgis:16-3.4`, volume na dáta, healthcheck `pg_isready`, env z `.env`.
  - `php`: build z `docker/php/Dockerfile`, volume `./backend:/app`, `depends_on: db (healthy)`.
  - `nginx`: `nginx:1.27-alpine`, port `8080:80`, volumes `./backend:/app`, `./frontend/dist:/srv/frontend`, config.
- `docker/php/Dockerfile`: `FROM php:8.3-fpm`, `apt-get install -y libpq-dev gdal-bin unzip`, `docker-php-ext-install pdo_pgsql`, Composer skopírovaný z `composer:2` image.
- `docker/nginx/default.conf`:
  - `/` → `root /srv/frontend; try_files $uri /index.html;`
  - `/tiles/` → najprv `try_files /app/var/tiles/$z/$x/$y.pbf` (cache na disku), inak `fastcgi_pass php:9000` na `public/index.php`.
  - `/api/` → `fastcgi_pass php:9000`.
  - `gzip on; gzip_types application/json application/vnd.mapbox-vector-tile;`
- **Hotovo, keď:** `docker compose up -d` prejde a `docker compose exec db psql -U app -c "select postgis_full_version()"` vráti verziu. `docker compose exec php ogr2ogr --version` funguje.
- **Commit:** `feat: docker compose with postgis, php-fpm, nginx`

### Krok 2: PHP kostra (1 h)
**Cieľ:** čistá štruktúra, na ktorú sa budú vešať endpointy.
- `composer.json`: `"autoload": {"psr-4": {"Viagem\\": "src/"}}`, require-dev `phpunit/phpunit`, `phpstan/phpstan`; scripts `test`, `phpstan`.
- `Infrastructure/Config`: číta env (`DB_HOST`, `DB_NAME`, …).
- `Infrastructure/Database`: vytvorí `PDO` (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`).
- `Http/Request`: metóda, path, query; `Http/Router`: registrácia `get('/api/parcels/{id}', [ParcelController::class, 'show'])`, matching cez regex a parametre do controllera.
- `Http/JsonResponse` a `Response` (binárne telo + hlavičky pre dlaždice).
- `Http/ErrorHandler`: chyby a výnimky premení na JSON `{error: "..."}` so správnym HTTP kódom (`HttpException` → 404/400, inak 500 bez úniku detailov).
- `public/index.php`: `declare(strict_types=1)`, autoload, zostavenie závislostí (ručná DI, jednoduchá „composition root“ funkcia), dispatch.
- `HealthController`: `GET /api/health` → `{status: "ok", postgis: "<verzia>"}`.
- **Hotovo, keď:** `curl localhost:8080/api/health` vráti JSON a neexistujúca cesta vráti JSON 404.
- **Commit:** `feat: minimal php http layer with router and health endpoint`

### Krok 3: Prieskum dát (30–45 min, bez kódu, ale dôležitý)
**Cieľ:** pochopiť VFR skôr, než napíšeš import. Patrí to do zápisníka.
1. Nájdi kód obce Jičín (RÚIAN kód obce, ⚠️ overiť na vdp.cuzk.cz alebo v ST_UZSZ).
2. Stiahni súbor obce: `https://services.cuzk.cz/vfr/<YYYYMM>/<YYYYMMDD>_OB_<kód>_UKSH.xml.zip` (⚠️ overiť aktuálny adresár; súbory sú aj cez vdp.cuzk.cz).
3. `docker compose exec php ogrinfo -so /vsizip/var/data/<subor>.xml.zip`: zisti názvy vrstiev (očakávaj `Parcely`, `KatastralniUzemi`, `Obce`…).
4. `ogrinfo -so … Parcely`: zisti polia (očakávaj `Id`, `KmenoveCislo`, `PododdeleniCisla`, `DruhCislovaniKod`, `VymeraParcely`, `DruhPozemkuKod`, `ZpusobyVyuzitiPozemku`, `KatastralniUzemiKod` a geometrie `DefinicniBod`, `OriginalniHranice`; ⚠️ overiť presné názvy).
5. Voliteľne otvor súbor v QGIS a pozri sa naň.
6. Zisti zoznam obcí okresu Jičín: zo súboru `ST_UZSZ` (vrstvy `Okresy`, `Obce`) vyfiltruj obce s kódom okresu Jičín.
- **Hotovo, keď:** máš poznámky (vrstvy, polia, počet parciel v Jičíne, kód okresu) v `README` → sekcia Zápisník.
- **Commit:** `docs: notes on RUIAN VFR structure`

### Krok 4: Databázová schéma (30 min)
- `sql/001_schema.sql`:
  - `parcels(id bigint PRIMARY KEY, ku_kod int NOT NULL, kmenove_cislo int NOT NULL, pododdeleni int NULL, druh_cislovani smallint NOT NULL, druh_pozemku smallint NULL, zpusob_vyuziti smallint NULL, vymera int NULL, label text NOT NULL, geom geometry(MultiPolygon, 3857) NOT NULL, def_bod geometry(Point, 4326))`
  - `cadastral_areas(kod int PRIMARY KEY, nazev text, obec_kod int, obec_nazev text, geom geometry(MultiPolygon, 3857), parcel_count int)`
  - Indexy: `GIST(geom)` na oboch tabuľkách, `btree(ku_kod, kmenove_cislo, pododdeleni)`.
  - Schéma `staging` pre surové dáta z ogr2ogr.
- Prečo 3857 už v DB: dlaždice sú v 3857, takže transformáciu urobíme raz pri importe, nie pri každej dlaždici.
- **Hotovo, keď:** tabuľky existujú (`\d parcels`).
- **Commit:** `feat: database schema for parcels and cadastral areas`

### Krok 5: Import (2 h)
**Cieľ:** `docker compose exec php bin/import` naplní DB celým okresom, opakovateľne.
- `Import/MunicipalityResolver`: stiahne `ST_UZSZ` a cez `ogr2ogr` / `ogrinfo -sql` vytiahne kódy obcí okresu Jičín. Výsledok uloží do `var/data/municipalities.json`, aby sa nepýtal znova.
- `Import/VfrDownloader`: pre každú obec stiahne `OB_<kód>_UKSH.xml.zip` do `var/data/`. Ak súbor existuje, preskočí ho. Pri chybe 3× opakuje s pauzou. Na sťahovanie použi `curl` cez PHP rozšírenie alebo `file_get_contents` s kontextom.
- `Import/OgrRunner`: zostaví a spustí `ogr2ogr` cez `proc_open`, **každý argument cez `escapeshellarg`** (bezpečnosť), skontroluje návratový kód a pri chybe vyhodí výnimku so stderr. Príklad:
  `ogr2ogr -f PostgreSQL PG:"<dsn>" /vsizip/<zip> Parcely -nln staging.parcely -append -lco GEOMETRY_NAME=geom -a_srs EPSG:5514`
  To isté pre vrstvu `KatastralniUzemi` (+ `Obce` pre názvy). ⚠️ overiť, ktorý geometrický stĺpec chceme (`OriginalniHranice`), pri viacerých geometriách použi `-sql` alebo `-select`.
- `Import/PostProcessor`: spustí `sql/002_postprocess.sql`:
  - `INSERT INTO parcels … SELECT … ST_Multi(ST_MakeValid(ST_Transform(geom, 3857))) … ON CONFLICT (id) DO UPDATE …` (idempotentné).
  - `label`: `CASE WHEN pododdeleni IS NULL THEN kmenove_cislo::text ELSE kmenove_cislo||'/'||pododdeleni END`, pri stavebných parcelách s prefixom `st.`
  - `def_bod` transformovaný do 4326.
  - `cadastral_areas` + `parcel_count` (`GROUP BY ku_kod`).
  - `TRUNCATE staging.*`, `ANALYZE`.
- `Import/ImportCommand` (volá ho `bin/import`): orchestruje kroky a vypisuje priebeh (`[12/111] Kopidlno … 3 412 parciel`). Voľba `--only=<kód obce>` na rýchle testovanie.
- Postup: **najprv spusti pre 1 obec (Jičín)**, over v DB, až potom celý okres.
- **Hotovo, keď:** `SELECT count(*) FROM parcels` dáva rádovo stovky tisíc, `SELECT count(*) FROM cadastral_areas` zodpovedá počtu KÚ okresu a opakovaný import nevytvorí duplicity.
- **Commit:** `feat: import pipeline for RUIAN VFR data of Jičín district`

### Krok 6: Endpoint pre vektorové dlaždice (2 h), srdce výkonu
- `Domain/TileCoord`: readonly `z, x, y` s validáciou (`0 ≤ x,y < 2^z`, z v povolenom rozsahu 8–20). Mimo rozsahu vráti 400.
- `Service/TileService::render(TileCoord): string` spojí dve vrstvy:
  ```sql
  -- vrstva parciel (len od z 12)
  WITH b AS (SELECT ST_TileEnvelope(:z, :x, :y) AS env),
  q AS (
    SELECT p.id, p.druh_pozemku AS druh, p.label AS cislo,
           ST_AsMVTGeom(
             CASE WHEN :z < 15 THEN ST_SimplifyPreserveTopology(p.geom, :tol) ELSE p.geom END,
             b.env, 4096, 64, true) AS geom
    FROM parcels p, b
    WHERE p.geom && b.env
      AND (:z >= 15 OR ST_Area(p.geom) > :min_area)
  )
  SELECT ST_AsMVT(q, 'parcels', 4096, 'geom', 'id') FROM q;
  ```
  - `tol` = veľkosť pixela v metroch na danom zoome: `40075016.69 / (4096 * 2^z)` (× ~1–2).
  - `min_area` ≈ (2 px)² v metroch. Na malom zoome sa mikroparcely aj tak zlejú.
  - Druhá vrstva `areas` (hranice KÚ, `nazev`, `parcel_count`) na všetkých zoomoch. Výsledné bytea dlaždice sa dajú spojiť (`||`), MVT to podporuje.
- `Service/TileCache`: cesta `var/tiles/{z}/{x}/{y}.pbf`. Zápis **atomicky** (do dočasného súboru, potom `rename`), aby nginx nikdy neservoval polovičný súbor. Prázdne dlaždice tiež cachuj.
- `TileController`: cache hit → súbor; inak render → uloží → vráti. Hlavičky: `Content-Type: application/vnd.mapbox-vector-tile`, `Cache-Control: public, max-age=86400`. Prázdna dlaždica → 204.
- **Hotovo, keď:** `curl -o t.pbf localhost:8080/tiles/14/8900/5540.pbf` (⚠️ dopočítaj súradnice dlaždice nad Jičínom) vráti niekoľko desiatok kB. Druhé volanie je okamžité, lebo ho servuje nginx z disku.
- **Commit:** `feat: vector tile endpoint with zoom-based generalization and disk cache`

### Krok 7: Frontend kostra s mapou (1,5 h)
- `npm create vite@latest frontend -- --template react-ts`, potom `npm i maplibre-gl react-map-gl`.
- `vite.config.ts`: proxy `/api` a `/tiles` na `http://localhost:8080` (pre `npm run dev`).
- `src/Map.tsx`:
  - `<Map mapLib={maplibregl} initialViewState={{longitude: 15.35, latitude: 50.43, zoom: 10}} mapStyle={baseStyle}>`
  - `baseStyle`: raster zdroj OSM (`https://tile.openstreetmap.org/{z}/{x}/{y}.png`, atribúcia).
  - `<Source id="parcels" type="vector" tiles={[`${location.origin}/tiles/{z}/{x}/{y}.pbf`]} minzoom={8} maxzoom={16}>` (nad z 16 MapLibre dlaždice „overzoomuje“, takže netreba generovať z 17–20).
  - `<Layer>`: `areas-line` (hranice KÚ), `parcels-fill` (`source-layer: parcels`, polopriehľadná výplň, `minzoom: 12`), `parcels-line` (tenké hranice).
  - `maxBounds` okolo okresu, nech sa používateľ nestratí.
- **Hotovo, keď:** na z 10 vidíš hranice KÚ celého okresu a pri priblížení sa objavia parcely. Posun je plynulý.
- **Commit:** `feat: react + maplibre frontend rendering parcel tiles`

### Krok 8: Detail parcely – backend (45 min)
- `Domain/LandType` (backed enum `int`): kódy druhov pozemkov KN → slovenské/české názvy + farba pre legendu. ⚠️ overiť kódy v číselníku (`ST_UCIS` alebo dokumentácia KN): 2 orná půda, 3 chmelnice, 4 vinice, 5 zahrada, 6 ovocný sad, 7 TTP, 10 lesní pozemek, 11 vodní plocha, 13 zastavěná plocha, 14 ostatní plocha.
- Číselník spôsobov využitia: menší enum alebo tabuľka z `ST_UCIS`.
- `Domain/ParcelNumber`: value object, formátovanie „st. 123/4“.
- `Domain/Parcel`: readonly DTO + `toArray()`.
- `Repository/ParcelRepository::findById(int): ?Parcel` s **prepared statementom** (JOIN na `cadastral_areas` kvôli názvu KÚ a obce, `ST_X/ST_Y(def_bod)`).
- `ParcelController::show`: validácia `id` (len číslice), 404 ak neexistuje. JSON obsahuje aj `links.nahlizeni` = `https://nahlizenidokn.cuzk.cz/ZobrazObjekt.aspx?typ=parcela&id=<id>` (⚠️ overiť, že RÚIAN ID parcely funguje v tejto URL) a `links.mapy`.
- **Hotovo, keď:** `curl localhost:8080/api/parcels/<id>` vráti kompletný JSON a zlé `id` vráti 400/404.
- **Commit:** `feat: parcel detail API`

### Krok 9: Detail parcely – frontend (1 h)
- `interactiveLayerIds={['parcels-fill']}`, `onClick={e => setSelectedId(e.features?.[0]?.id)}`.
- Zvýraznenie: samostatná vrstva `parcels-selected` s `filter={['==', ['id'], selectedId ?? -1]}` (hrubý obrys). Je to jednoduchšie ako `feature-state`.
- `useParcel(id)` hook: `fetch('/api/parcels/'+id)` s abort pri rýchlom preklikávaní, stavy loading/error.
- `ParcelPanel.tsx`: bočný panel s údajmi, odkazmi a tlačidlom zavrieť. Na mobile sa zobrazí ako spodný panel.
- Kurzor `pointer` nad parcelami (`onMouseEnter/Leave`).
- **Hotovo, keď:** klik na ľubovoľnú parcelu zobrazí správne údaje (porovnaj 3–5 parciel s Nahlížením do KN).
- **Commit:** `feat: parcel selection and detail panel`

### Krok 10: Výkon – meranie a doladenie (1 h)
- `bin/warm-cache`: pre bbox okresu vygeneruje dlaždice z 8–13 (najdrahšie, pokrývajú veľké územie). Vypíše počet, celkovú veľkosť a priemerný čas.
- V Chrome DevTools:
  - **Network:** veľkosti dlaždíc (cieľ < ~300 kB), studená vs. cachovaná odozva.
  - **Performance:** záznam pri zoome z 10 → 16 a posune (cieľ: bez dlhých taskov, ~60 FPS).
- Ak sú dlaždice veľké: zvýš `tol` alebo `min_area`, posuň `minzoom` parciel na 13.
- Ak je studená dlaždica pomalá: `EXPLAIN ANALYZE` na dotaz (musí používať GIST index), `ANALYZE parcels`.
- Namerané čísla zapíš do zápisníka v README.
- **Commit:** `perf: tile cache warmup and tuning`

### Krok 11: Kvalita kódu (45 min)
- PHPStan level 8 bez chýb (`composer phpstan`).
- PHPUnit testy: `TileCoord` (validácia rozsahov), `Router` (matching a parametre), `ParcelNumber` (formátovanie), `LandType` (mapovanie kódov).
- Prejdi si kód: krátke metódy, jasné názvy, žiadne SQL v controlleroch, všetko cez prepared statements.
- **Commit:** `test: unit tests and static analysis`

### Krok 12: Build a README (45 min)
- Frontend build pre „produkčný“ beh: buď služba `frontend` (node:20) v compose, ktorá spraví `npm ci && npm run build` do `frontend/dist`, alebo jednoduchý krok v README. Cieľ je, aby sa celé riešenie dalo spustiť bez lokálneho Node.
- **README**:
  - Predpoklady (Docker, voľné porty, ~X GB miesta).
  - Spustenie (3 príkazy: `cp .env.example .env`, `docker compose up -d`, `docker compose exec php bin/import`) a koľko trvá import.
  - Architektúra (diagram z návrhu).
  - **Zápisník:** rozhodnutia a prečo (skrátene z `navrh.md`), čo ťa prekvapilo (napr. štruktúra VFR, stavebné vs. pozemkové čísla, výmera vs. plocha geometrie, jednovláknové `php -S`), čo by si s viac časom spravil inak (pozri koniec) a koľko času čo zabralo.
- **Hotovo, keď:** na čistom klone repozitára prejdú kroky z README.
- **Commit:** `docs: readme and notebook`, potom tag `v1.0-mvp`

---

## FÁZA 2: Bonusy (v poradí priority)

### B1: Farba podľa druhu pozemku + legenda (1 h)
- `parcels-fill` paint: `fill-color: ['match', ['get', 'druh'], 2, '#e6c35c', 7, '#9bd17a', 10, '#2f7d32', 5, '#b5d99c', 13, '#bdbdbd', 14, '#d7ccc8', /*…*/ '#cccccc']`. Farby ber z `LandType`. Môžeš ich vystaviť cez `/api/meta/land-types`, aby boli na jednom mieste.
- `Legend.tsx`: zoznam druhov s farbou a checkboxom. Vypnutie prepočíta `filter` vrstvy: `['in', ['get','druh'], ['literal', enabledCodes]]`.
- **Commit:** `feat: color parcels by land type with toggleable legend`

### B2: Vyhľadávanie parcely (1,5 h)
- `GET /api/areas`: zoznam KÚ (kód, názov, obec) pre autocomplete.
- `GET /api/search?ku=<kód>&q=st.%20123/4`: `ParcelNumber::parse()` (validácia), dotaz cez btree index. Vráti `id` + `bbox` (`ST_Extent` → 4326).
- Frontend: `SearchBox.tsx` (výber KÚ + pole čísla). Po nájdení `map.fitBounds(bbox)` a `setSelectedId(id)`.
- Testy: `ParcelNumber::parse` (rôzne formáty a chybné vstupy).
- **Commit:** `feat: search parcel by cadastral area and number`

### B3: Permalink (30 min)
- MapLibre `hash` option (`#zoom/lat/lng`) + `?parcel=<id>` (čítať pri štarte, zapisovať cez `history.replaceState` pri výbere).
- **Commit:** `feat: shareable permalinks`

### B4: Prepínač podkladov + katastrálna mapa (1 h)
- Podklady (raster zdroje v štýle):
  - OSM: `https://tile.openstreetmap.org/{z}/{x}/{y}.png`
  - Mapy.com (len ak je `VITE_MAPY_API_KEY`): `https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey=…` a `aerial`. Pridaj **logo a atribúciu** podľa podmienok.
  - ČÚZK ortofoto: WMTS/tile služba ČÚZK (⚠️ overiť aktuálnu URL na geoportal.cuzk.cz).
- Prekryv katastrálnej mapy: WMS ČÚZK `https://services.cuzk.cz/wms/wms.asp?SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&LAYERS=KN&STYLES=&FORMAT=image/png&TRANSPARENT=true&CRS=EPSG:3857&WIDTH=256&HEIGHT=256&BBOX={bbox-epsg-3857}` (⚠️ overiť názov vrstvy). Hodí sa na vizuálnu kontrolu našich dát.
- `BasemapSwitcher.tsx`.
- **Commit:** `feat: basemap switcher and cadastral WMS overlay`

### B5: Multi-výber + súčet + export (1,5 h)
- Shift+klik pridá alebo odoberie parcelu z výberu (stav `Set<number>`). Vrstva `parcels-selected` filtruje `['in', ['id'], ['literal', [...ids]]]`.
- Panel „Výber“: počet parciel, **súčet výmery** (ha) a rozpad podľa druhu pozemku.
- `GET /api/parcels/export?ids=1,2,3&format=csv|geojson` (limit napr. 1000 id, validácia). GeoJSON cez `ST_AsGeoJSON(ST_Transform(geom, 4326))`, CSV cez `fputcsv` do `php://temp`. Hlavička `Content-Disposition: attachment`.
- **Commit:** `feat: multi-select with area summary and CSV/GeoJSON export`

### B6: Choropleth KÚ na malých zoomoch (45 min)
- Pri importe dopočítaj do `cadastral_areas` napr. `orna_share` (podiel výmery ornej pôdy).
- Vrstva `areas-fill` (`maxzoom: 12`): `fill-color: ['interpolate', ['linear'], ['get','orna_share'], 0, '#f7fcf5', 1, '#00441b']` + malá legenda.
- **Commit:** `feat: choropleth of cadastral areas at low zoom`

### B7: Tooltip pri hoveri (20 min)
- `onMouseMove` → `Popup` s `cislo` z dlaždice, bez requestu na server.
- **Commit:** `feat: hover tooltip with parcel number`

---

## Čo napísať do „s viac časom by som…“ (inšpirácia)
- Automatický mesačný re-import (cron) s inkrementálnymi zmenami (VFR má aj zmenové súbory) a invalidáciou len dotknutých dlaždíc.
- Vlastníci cez **API KN** ČÚZK (vyžaduje registráciu a je spoplatnené), s ohľadom na GDPR.
- Napojenie na územný plán a BPEJ (bonita pôdy, ktorá určuje úradnú cenu poľnohospodárskej pôdy).
- Predgenerovanie celej pyramídy do PMTiles pre hosting bez DB (CDN).
- E2E testy (Playwright) a CI pipeline.

## Kontrolný zoznam pred odovzdaním
- [ ] Čistý klon → podľa README beží do 15 min.
- [ ] Celý okres na z 10 sa načíta do ~1 s, zoom a posun sú plynulé.
- [ ] Klik na parcelu zobrazí správne údaje (overené proti KN).
- [ ] Chrome aj Firefox.
- [ ] PHPStan a PHPUnit prejdú.
- [ ] README: predpoklady, spustenie, zápisník, čas.
- [ ] Zmysluplná história commitov.

## Zdroje
- [ČÚZK – Výměnný formát RÚIAN (VFR)](https://www.cuzk.gov.cz/ruian/Poskytovani-udaju-ISUI-RUIAN-VDP/Vymenny-format-RUIAN-(VFR).aspx)
- [Struktura a popis VFR](https://cuzk.gov.cz/ruian/Poskytovani-udaju-ISUI-RUIAN-VDP/Vymenny-format-RUIAN-(VFR)/DL058RR2-v5-0-Struktura-a-popis-VFR_final.aspx)
- [Archív VFR súborov](https://services.cuzk.cz/vfr/)
- [INSPIRE CP – stahovací služba (PDF)](https://services.cuzk.gov.cz/doc/inspire-cp-download.pdf)
- [Mapy.com – Map tiles API](https://developer.mapy.com/rest-api-mapy-cz/function/map-tiles/)
