# Postup implementácie – krok po kroku

Kroky tak, ako prebehli.

## Výsledná štruktúra repozitára
```
viagem/
├─ docker-compose.yml           # db (PostGIS), php (php-fpm + GDAL), nginx
├─ .env.example                 # DB heslo, porty, APP_DEBUG
├─ bin/import.sh                # import dát: curl → ogr2ogr → SQL
├─ docker/
│  ├─ php/Dockerfile, php.ini   # php:8.3-fpm + pdo_pgsql + gdal-bin + composer
│  └─ nginx/default.conf        # frontend staticky, /api a /tiles do PHP, gzip
├─ backend/
│  ├─ public/index.php          # front controller (jediný vstup HTTP)
│  ├─ sql/codelists.sql         # číselníky druhov a spôsobov využitia (zo ST_UCIS)
│  ├─ sql/import.sql            # staging → parcels, cadastral_areas
│  ├─ src/
│  │  ├─ Application.php        # composition root: závislosti a routy
│  │  ├─ Http/                  # Router, Request, Response, JsonResponse, HttpException, ErrorHandler
│  │  ├─ Controller/            # TileController, ParcelController, HealthController
│  │  ├─ Repository/            # ParcelRepository (všetko SQL)
│  │  └─ Infrastructure/        # Config, DatabaseConfig, Database (PDO)
│  ├─ tests/Http/RouterTest.php
│  ├─ var/                      # stiahnuté dáta a cache (v .gitignore)
│  └─ composer.json, phpstan.neon, phpunit.xml
├─ frontend/index.html          # celý frontend: MapLibre z CDN, čisté JS
└─ docs/                        # navrh.md, postup.md, adr.md
```

---

### Krok 0: Príprava
- Docker Desktop, git; voliteľne QGIS na prezeranie dát.
- `git init`, `.gitignore` (`.env`, `backend/vendor/`, `backend/var/`).
- **Commit:** `init commit`

### Krok 1: Docker infraštruktúra
**Cieľ:** jeden príkaz spustí DB, PHP a nginx.
- `docker-compose.yml`:
  - `db`: `imresamu/postgis:17-3.5` (multi-arch, [ADR-0001](adr.md)), volume na dáta, healthcheck `pg_isready`, port 5433 na hoste.
  - `php`: build z `docker/php/Dockerfile`, volume `./backend:/app`, `depends_on: db (healthy)`.
  - `nginx`: `nginx:1.27-alpine`, port 8080, volumes `./backend:/app:ro`, `./frontend:/srv/frontend:ro` a config ([ADR-0015](adr.md)).
- `docker/php/Dockerfile`: `FROM php:8.3-fpm`, `libpq-dev gdal-bin unzip git`, `docker-php-ext-install pdo_pgsql`, Composer z `composer:2`.
- `docker/php/php.ini`: `display_errors = Off`, `log_errors = On`.
- **Hotovo, keď:** `docker compose up -d` prejde, `psql` vráti verziu PostGIS a `docker compose exec php ogr2ogr --version` funguje.
- **Commit:** `feat: docker compose with postgis, php-fpm, nginx`

### Krok 2: PHP kostra
**Cieľ:** čistá štruktúra, na ktorú sa vešajú endpointy ([ADR-0008](adr.md) až [ADR-0010](adr.md)).
- `composer.json`: PSR-4 `Viagem\\` → `src/`, dev závislosti `phpunit/phpunit` a `phpstan/phpstan` (level 8), scripts `test` a `phpstan`.
- `Infrastructure/Config` číta env (`DB_HOST`, `DB_NAME`, …, `APP_DEBUG`), `Infrastructure/Database` vytvorí `PDO` (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES => false`).
- `Http/Router`: vzory ako `/tiles/{z:\d+}/{x:\d+}/{y:\d+}.pbf` sa kompilujú do regexu, 404 a 405 s `Allow`.
- `Http/Request`, `Response`, `JsonResponse`, `HttpException`, `ErrorHandler` (warningy → výnimky, všetko → JSON `{error}`).
- `Application`: ručná DI a registrácia rout. `public/index.php`: autoload, config, dispatch.
- `HealthController`: `GET /api/health` → `{status, php, postgis}`.
- Testy routera (7 testov).
- **Hotovo, keď:** `curl localhost:8080/api/health` vráti JSON, neexistujúca cesta vráti JSON 404 a `composer phpstan` aj `composer test` prejdú.
- **Commit:** `feat: minimal php http layer with router and health endpoint`

### Krok 3: Prieskum dát (bez kódu)
**Cieľ:** pochopiť VFR skôr, než sa napíše import. Výsledky sú v README → Zápisník.
1. Najnovší mesiac v archíve `https://services.cuzk.cz/vfr/` je `202608`.
2. Kód obce Jičín v `ST_UZSZ` (vrstva `Obce`): `572659`, okres `3604`. Okres má 111 obcí a 240 KÚ.
3. `ogrinfo -so /vsizip/…/20260831_OB_572659_UKSH.xml.zip Parcely`: polia `Id`, `KmenoveCislo`, `PododdeleniCisla`, `DruhCislovaniKod`, `VymeraParcely`, `DruhPozemkuKod`, `ZpusobyVyuzitiPozemku`, `KatastralniUzemiKod` a geometrie `DefinicniBod`, `OriginalniHranice`, `OriginalniHraniceOmpv`.
4. Obec Jičín má 5 KÚ a 16 950 parciel. To samo splní povinné minimum, preto je rozsahom aplikácie táto obec ([ADR-0004](adr.md)).
5. Číselníky druhov a spôsobov využitia sú v `ST_UCIS`, TTP má kódy 7 aj 8.
- **Hotovo, keď:** poznámky (vrstvy, polia, počty, kódy) sú v README.

### Krok 4: Import
**Cieľ:** `bin/import.sh` naplní DB opakovateľne ([ADR-0005](adr.md) až [ADR-0007](adr.md)).
- `bin/import.sh`:
  - stiahne `${VFR_DATE}_OB_${MUNICIPALITY}_UKSH.xml.zip` do `backend/var/data/` (cez `.part`, ak ešte nie je stiahnutý),
  - zmaže a vytvorí schému `staging`,
  - `ogr2ogr` v `php` kontajneri nahrá vrstvy `Obce`, `KatastralniUzemi` a `Parcely` do `staging`,
  - spustí `backend/sql/codelists.sql` a `backend/sql/import.sql` a vypíše počty parciel po KÚ.
- `backend/sql/codelists.sql`: tabuľky `land_types` (11) a `land_uses` (30), raz vygenerované zo `ST_UCIS`.
- `backend/sql/import.sql` v jednej transakcii:
  - `cadastral_areas(code, name, municipality, geom)` z KÚ a obce,
  - `parcels(id, area_code, number, land_type, land_use, area_m2, geom)`: `ST_Transform` do 3857, `ST_MakeValid`, `ST_CollectionExtract(…, 3)`, `ST_Multi`; `number` ako `st. 123/4` alebo `820/13`,
  - primárne kľúče, GIST index na `parcels.geom`, zmazanie staging, `ANALYZE`.
- **Hotovo, keď:** import trvá pár sekúnd, počty sedia s prieskumom (Jičín 12 283, Robousy 2 445, Popovice 1 475, Moravčice 467, Hubálov 280), žiadna geometria nie je prázdna ani nevalidná, všetky kódy majú záznam v číselníku a druhé spustenie dá rovnaký výsledok.
- **Commit:** `feat: import parcels of Jičín`

### Krok 5: Endpoint pre vektorové dlaždice
**Cieľ:** `GET /tiles/{z}/{x}/{y}.pbf` vráti MVT ([ADR-0011](adr.md)).
- `ParcelRepository::tile()`: jeden SQL dotaz.
  - `ST_TileEnvelope(z, x, y)` a veľkosť pixela `40075016.68 / 512 / 2^z`.
  - Vrstva `areas`: `ST_AsMVTGeom` hraníc KÚ (`code`, `name`).
  - Vrstva `parcels` (od z10): `ST_AsMVTGeom(ST_Simplify(geom, pixel, true), envelope)`, atribúty `id` a `land_type`.
  - Obe vrstvy sa spoja cez `||`. Výsledok `bytea` príde z `pdo_pgsql` ako stream.
- `TileController`: validácia `z ≤ 16`, `x, y < 2^z` (inak 400), prázdna dlaždica 204, `Content-Type: application/vnd.mapbox-vector-tile`, `Cache-Control: public, max-age=86400`.
- `nginx`: `location ~ ^/(api|tiles)/` do PHP, `gzip_types` aj pre MVT.
- **Hotovo, keď:** dlaždice nad Jičínom sa generujú do 50 ms a najväčšia má so gzipom ~210 kB (z12). Namerané hodnoty sú v README.
- **Commit:** `feat: vector tile endpoint rendered by PostGIS with pixel-level simplification`

### Krok 6: Detail parcely
**Cieľ:** `GET /api/parcels/{id}` vráti JSON ([ADR-0012](adr.md)).
- `ParcelRepository::find()`: prepared statement, `JOIN cadastral_areas`, `LEFT JOIN land_types` a `land_uses`, výsledok typovaný ako PHPStan array shape.
- `ParcelController::show()`: `FILTER_VALIDATE_INT` (príliš veľké ID → 404), neexistujúca parcela 404, JSON `{id, number, areaM2, cadastralArea, municipality, landType, landUse, links.nahlizeniDoKn}`.
- **Hotovo, keď:** `curl localhost:8080/api/parcels/97638798010` vráti parcelu 1854/42 a údaje sedia s Nahlížením do KN. Neexistujúce ID vráti 404.
- **Commit:** `feat: parcel detail API with cadastral area and land type names`

### Krok 7: Frontend
**Cieľ:** mapa, klik a detail v jednom súbore bez buildu ([ADR-0013](adr.md), [ADR-0014](adr.md)).
- `frontend/index.html` s MapLibre GL 4.7.1 z unpkg:
  - podklad OSM, zdroj `cadastre` s `minzoom 10` a `maxzoom 16` (nad z16 overzoom), `bounds` na 5 KÚ, `maxBounds`, `minZoom 10`,
  - vrstvy `parcels-fill` (farba podľa `land_type` cez `match`), `parcels-line` (od z14), `areas-line` (čiarkované hranice KÚ), `parcel-selected` (filter na `id`),
  - legenda z poľa `LAND_TYPES` s checkboxmi (`setFilter`),
  - klik → `fetch('/api/parcels/' + id)` s `AbortController`, panel cez `textContent`, výmera v m² a ha, odkaz na Nahlížení do KN.
- `docker-compose.yml`: nginx pripája `./frontend`.
- **Hotovo, keď:** úvodný pohľad ukáže celé územie s parcelami, klik zobrazí správny detail, vypnutie druhu v legende skryje jeho parcely, a to v Chrome aj Firefoxe.
- **Commit:** `feat: single-file MapLibre frontend with parcel detail panel and land type legend`

### Krok 8: README, zápisník a overenie
- README: predpoklady s verziami, spustenie (4 príkazy), architektúra, endpointy, zápisník (postup, rozhodnutia, prieskum dát, čo prekvapilo, výkon, čo s viac časom).
- PHPUnit cache do `backend/var/cache/`, odstránenie nepoužívaných premenných z `.env.example`.
- Overenie dát: výmera z katastra voči ploche geometrie (súčet sa líši o 0,04 %), náhodné parcely voči Nahlížení do KN.
- **Hotovo, keď:** na čistej kópii repozitára prejde postup z README (overené: 41 s vrátane stiahnutia dát).
- **Commit:** `docs: readme with setup, architecture and notebook`

---

## Ďalšie kroky (v poradí priority)
1. **Celý okres:** import v cykle pre 111 obcí zo `ST_UZSZ`, agregované KÚ na malých zoomoch, disková cache alebo PMTiles.
2. **Vyhľadávanie** parcely podľa KÚ a čísla (`GET /api/search`) a zoom na ňu.
3. **Permalink:** zoom, poloha a vybraná parcela v URL.
4. **Prepínač podkladov** (Mapy.com, ortofoto ČÚZK) a prekryv katastrálnej mapy (WMS ČÚZK).
5. **Multi-výber** parciel so súčtom výmery a exportom do CSV alebo GeoJSON.
6. **BPEJ** (bonita pôdy) v detaile parcely.
7. **Tooltip** s číslom parcely pri prechode myšou (vyžaduje `number` v dlaždici na vysokých zoomoch).

## Kontrolný zoznam pred odovzdaním
- [x] Čistá kópia → podľa README beží (41 s).
- [x] Celé územie sa načíta naraz, dlaždice do 50 ms.
- [x] Klik na parcelu zobrazí správne údaje (overené proti KN).
- [x] PHPStan a PHPUnit prejdú.
- [ ] Firefox.
- [ ] Všetky commity v gite a push.

## Zdroje
- [ČÚZK – Výměnný formát RÚIAN (VFR)](https://www.cuzk.gov.cz/ruian/Poskytovani-udaju-ISUI-RUIAN-VDP/Vymenny-format-RUIAN-(VFR).aspx)
- [Struktura a popis VFR](https://cuzk.gov.cz/ruian/Poskytovani-udaju-ISUI-RUIAN-VDP/Vymenny-format-RUIAN-(VFR)/DL058RR2-v5-0-Struktura-a-popis-VFR_final.aspx)
- [Archív VFR súborov](https://services.cuzk.cz/vfr/)
- [INSPIRE CP – stahovací služba (PDF)](https://services.cuzk.gov.cz/doc/inspire-cp-download.pdf)
- [MapLibre GL JS – dokumentácia štýlov](https://maplibre.org/maplibre-style-spec/)
- [PostGIS – ST_AsMVT](https://postgis.net/docs/ST_AsMVT.html)
