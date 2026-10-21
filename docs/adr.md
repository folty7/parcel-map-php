# Architecture Decision Records

## ADR-0001: Multi-arch PostGIS image `imresamu/postgis`
- **Dátum:** 2026-10-19
- **Stav:** Prijaté
- **Kontext:** Aplikácia beží v Docker Compose a vývoj prebieha na Apple Silicon (arm64). Generovanie vektorových dlaždíc v PostGIS je výkonovo kritické.
- **Zvažované možnosti:**
  - `postgis/postgis` (oficiálny image): najznámejší, ale publikovaný len pre amd64, takže na arm64 beží cez emuláciu a je výrazne pomalší.
  - `imresamu/postgis`: ten istý build od jedného zo správcov PostGIS docker images, multi-arch (amd64 + arm64). Je však menej známy.
  - Vlastný image `postgres` + balík `postgresql-17-postgis-3`: plne natívny, ale pribudne ďalší Dockerfile na údržbu.
- **Rozhodnutie:** `imresamu/postgis:17-3.5`. Beží natívne na arm64 aj amd64 bez vlastného buildu.
- **Dôsledky:** Rovnaký výkon na Macu aj na Linuxe. Závislosť na image mimo oficiálnej organizácie; ak by prestal byť udržiavaný, prechod na vlastný Dockerfile je triviálny.

## ADR-0002: Dáta predom stiahnuté z RÚIAN VFR, nie live z WFS
- **Dátum:** 2026-10-19
- **Stav:** Prijaté
- **Kontext:** Zadanie nechá na nás, či dáta ťahať live zo služieb ČÚZK, alebo ich stiahnuť vopred, a z ktorého datasetu. Výkupcu zaujíma hlavne druh pozemku, výmera a spôsob využitia, a mapa musí byť plynulá.
- **Zvažované možnosti:**
  - Stiahnuť výmenný formát RÚIAN (VFR, súbor obce `OB_<kód>_UKSH`) a importovať do vlastnej DB: bohaté atribúty vrátane druhu pozemku a spôsobu využitia a výkon nezávisí od ČÚZK, ale dáta sú staré až mesiac (VFR vychádza mesačne).
  - Live dotazy na WFS ČÚZK: vždy aktuálne dáta bez importu, ale WFS má limit prvkov na odpoveď a je pomalý, takže väčšie územie sa plynulo zobraziť nedá; výpadok ČÚZK by znamenal nefunkčnú aplikáciu.
  - INSPIRE Cadastral Parcels (GML po KÚ): jednoduchšia schéma a denná aktualizácia, ale chýba druh pozemku aj spôsob využitia.
- **Rozhodnutie:** VFR, predom stiahnutý a importovaný do PostGIS. Je to jediný zdroj, ktorý má všetky údaje dôležité pre výkup, a vlastná DB umožní indexy, generalizáciu a vektorové dlaždice.
- **Dôsledky:** Aktuálnosť sa rieši opätovným spustením importu (VFR vychádza mesačne). Súbory obsahujú aj adresy a budovy, ktoré nepotrebujeme; import si z nich vyberie len vrstvy `Obce`, `KatastralniUzemi` a `Parcely`.

## ADR-0003: PostgreSQL + PostGIS ako úložisko
- **Dátum:** 2026-10-19
- **Stav:** Prijaté
- **Kontext:** Parcely treba uložiť tak, aby sa dalo rýchlo vybrať, čo je vo výreze mapy, a z toho vyrobiť vektorovú dlaždicu.
- **Zvažované možnosti:**
  - PostgreSQL + PostGIS: `ST_AsMVT` vyrobí vektorovú dlaždicu priamo v SQL, má GIST indexy, transformácie a zjednodušovanie geometrie, ale je to ďalšia služba.
  - SQLite + SpatiaLite: jeden súbor bez DB servera, ale nevie generovať MVT a rozšírenie sa v PHP zle nastavuje.
  - Bez DB, predgenerované dlaždice (tippecanoe → PMTiles): najrýchlejšie servovanie, ale backend by nerobil nič, detail parcely by nemal odkiaľ čítať a každá zmena znamená rebuild.
- **Rozhodnutie:** PostgreSQL 17 + PostGIS 3.5 v Docker Compose (ADR-0001).
- **Dôsledky:** Celá geopriestorová logika (transformácia, validácia geometrie, dlaždice) je v SQL. V Dockeri ďalšia služba nevadí.

## ADR-0004: Rozsah: 5 katastrálnych území obce Jičín
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Povinné minimum je KÚ Jičín a aspoň 3 ďalšie KÚ v okrese, celý okres je voliteľný. Hlavným kritériom zadania je plynulosť nad zvoleným rozsahom. Celý okres (111 obcí, 240 KÚ) by si vyžiadal zoznam obcí zo `ST_UZSZ`, sťahovanie 111 súborov a ďalšie optimalizácie dlaždíc na malých zoomoch.
- **Zvažované možnosti:**
  - Jeden súbor obce Jičín (`OB_572659_UKSH`): obsahuje 5 KÚ (Jičín, Popovice u Jičína, Robousy, Moravčice, Hubálov) a 16 950 parciel, takže splní minimum jedným súborom, ale nepokryje celý okres.
  - Celý okres so zoznamom obcí zo `ST_UZSZ` (vrstva `Obce`, `OkresKod = 3604`): viac ako zadanie vyžaduje, ale približne 30-krát viac parciel, takže na malých zoomoch by bola potrebná agregácia a cache dlaždíc.
  - Ručne vybrané KÚ z rôznych obcí: rozptýlené po okrese, ale potrebuje viac súborov a filtrovanie bez pridanej hodnoty pre používateľa.
- **Rozhodnutie:** Len obec Jičín. Splní povinné minimum s rezervou (5 KÚ namiesto 4) a import je jeden súbor bez zoznamu obcí.
- **Dôsledky:** Kód obce je premenná `MUNICIPALITY` v `bin/import.sh`. Rozšírenie na celý okres znamená spustiť import v cykle pre obce zo `ST_UZSZ`, tabuľky plniť cez `INSERT` namiesto `CREATE TABLE AS` a znova overiť výkon na malých zoomoch (ADR-0011).

## ADR-0005: Geometria parcely z `OriginalniHranice`
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Vrstva `Parcely` vo VFR má tri geometrie: `DefinicniBod` (bod vnútri parcely), `OriginalniHranice` (polygón) a `OriginalniHraniceOmpv` (multipolygón).
- **Zvažované možnosti:**
  - `OriginalniHranice`: presná hranica z katastra, vyplnená pri všetkých 16 950 parcelách, ale detailná, takže pri malom zoome ju treba zjednodušovať.
  - `OriginalniHraniceOmpv`: hranica podľa obrazu mapy s pozemkovými úpravami, ale v Jičíne je prázdna.
  - Iba `DefinicniBod`: malé dáta, ale bod nestačí na zobrazenie a klikanie na plochu parcely.
- **Rozhodnutie:** `OriginalniHranice`, pri importe prevedená do EPSG:3857, opravená cez `ST_MakeValid` a uložená ako `MultiPolygon`. `DefinicniBod` neimportujeme, lebo popisky ani súradnice parcely aplikácia nepotrebuje.
- **Dôsledky:** Generalizáciu pre malé zoomy riešime v PostGIS (ADR-0011). Ak pribudnú popisky parcelných čísel, `DefinicniBod` sa doplní ako ďalší stĺpec. BPEJ (`BonitovanyDil*`) je v dátach tiež dostupná a dá sa doplniť neskôr.

## ADR-0006: Import ako shell skript: ogr2ogr do staging schémy, potom SQL
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Dáta VFR sú zazipované GML v S-JTSK (EPSG:5514). Treba ich dostať do PostGIS v EPSG:3857 s pripraveným číslom parcely a indexom, opakovateľne a s čo najmenším množstvom kódu.
- **Zvažované možnosti:**
  - Shell skript `bin/import.sh` (curl → `ogr2ogr` do schémy `staging` → `backend/sql/import.sql`): málo kódu a všetka transformácia je v jednom čitateľnom SQL súbore, ale beží na hoste a predpokladá `docker compose` a bash.
  - PHP CLI príkaz s triedami Downloader, OgrRunner a PostProcessor: ukáže PHP kód, ale je to niekoľko tried, ktoré len obaľujú tie isté príkazy.
  - Parsovanie GML priamo v PHP (XMLReader) a vkladanie cez PDO: bez GDAL, ale veľa kódu na geometrie (multipolygóny, diery) a transformáciu súradníc.
- **Rozhodnutie:** Shell skript. `ogr2ogr` (GDAL, nainštalovaný v PHP kontajneri) nahrá vrstvy `Obce`, `KatastralniUzemi` a `Parcely` bez úprav. SQL v jednej transakcii postaví tabuľky `parcels` a `cadastral_areas` (transformácia do 3857, `ST_MakeValid`, číslo parcely ako `st. 123/4`, GIST index) a staging schému zmaže. PHP ostáva len pre HTTP API.
- **Dôsledky:** Import trvá asi 2 sekundy a je idempotentný: tabuľky sa postavia nanovo a aplikácia počas importu vidí starý stav až do `COMMIT`. Staging schéma sa vždy vytvorí nanovo, lebo `ogr2ogr -overwrite` s `-lco SCHEMA` existujúcu tabuľku neprepíše. Transformácia z S-JTSK používa predvolené parametre GDAL bez gridu, takže presnosť je rádovo do 1 m. Na mapu to stačí, na meranie nie.

## ADR-0007: Číselníky druhov a spôsobov využitia ako SQL súbor v repozitári
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Parcely nesú len kódy druhu pozemku (11 hodnôt, napr. 2 = orná půda) a spôsobu využitia (30 hodnôt). Názvy sú v súbore číselníkov `ST_UCIS`, ktorý GDAL nevie načítať ako vrstvy, lebo nemajú geometriu ani šablónu `.gfs`.
- **Zvažované možnosti:**
  - SQL súbor `backend/sql/codelists.sql` raz vygenerovaný zo `ST_UCIS`: názvy sú v DB a detail parcely ich získa obyčajným JOIN-om, ale pri zmene číselníka treba súbor pregenerovať.
  - PHP enum `LandType` a pole pre spôsoby využitia: typová kontrola, ale 41 ručne prepísaných hodnôt a ďalšie triedy.
  - Parsovať `ST_UCIS` pri každom importe (XMLReader alebo `grep`): vždy aktuálne, ale kód navyše pre dáta, ktoré sa takmer nemenia.
- **Rozhodnutie:** SQL súbor v repozitári s tabuľkami `land_types` a `land_uses`, ktorý `bin/import.sh` spustí pred `import.sql`. Je to najmenej kódu a údaje sú overiteľné voči zdroju uvedenému v hlavičke súboru.
- **Dôsledky:** Názvy druhov sú v DB, farby len vo frontende (podľa kódu). Import overil, že všetky kódy v dátach majú záznam v číselníkoch. Ak ČÚZK pridá nový kód, JOIN vráti `NULL` a detail parcely ukáže iba kód.

## ADR-0008: Čisté PHP s vlastným minimalistickým routerom
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** Backend musí byť v PHP, zadanie preferuje čisté PHP. Aplikácia má 3 GET endpointy s parametrami v ceste (`/api/parcels/{id}`, `/tiles/{z}/{x}/{y}.pbf`, `/api/health`).
- **Zvažované možnosti:**
  - Čisté PHP 8.3 s vlastným routerom (~80 riadkov): žiadna závislosť a celý kód je čitateľný na jednom mieste, ale bez cache a pokročilých funkcií.
  - Mikroframework (Slim 4) alebo knižnica `nikic/fast-route`: overený routing a middleware, ale závislosti a konvencie, ktoré na pár endpointov nie sú potrebné.
  - `switch` nad `$_SERVER['REQUEST_URI']` v `index.php`: najmenej kódu, ale neškáluje a mieša routing s logikou.
- **Rozhodnutie:** Čisté PHP s vlastným routerom. Vzory sa kompilujú do regexu s pomenovanými skupinami (`{z:\d+}` = vlastné obmedzenie). Handler je `callable(Request): Response`, takže controllery sa registrujú cez first-class callable (`$tiles->show(...)`). Router rozlišuje 404 (neznáma cesta) a 405 s hlavičkou `Allow` (zlá metóda).
- **Dôsledky:** Lineárne prehľadávanie ciest je pri troch routes zanedbateľné. Pri desiatkach routes alebo potrebe middleware by bolo vhodné prejsť na FastRoute alebo PSR-15.

## ADR-0009: Ručná DI v composition root (`Application`)
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** Controllery potrebujú závislosti (PDO, repozitár). Treba ich niekde vytvoriť bez frameworku.
- **Zvažované možnosti:**
  - Ručné skladanie v jednej triede `Application`: explicitné a bez mágie, ale pri raste aplikácie trieda narastie.
  - DI kontajner (napr. PHP-DI s autowiringom): menej kódu pri mnohých službách, ale skrytá konfigurácia a ďalšia závislosť.
  - Globálne singletony alebo statické gettery: jednoduché, ale ťažko testovateľné.
- **Rozhodnutie:** Ručná DI v `Application::createRouter()`. Závislosti sa vytvárajú cez konštruktory a všetko je vidieť na jednom mieste.
- **Dôsledky:** PHP-FPM nič nedrží medzi požiadavkami, takže sa `Application` aj PDO spojenie vytvárajú pri každej požiadavke, aj pri 404. Objekty stoja mikrosekundy (OPcache drží skompilovaný kód), spojenie do DB rádovo 1 ms. Ak by to vadilo, prichádza do úvahy `PDO::ATTR_PERSISTENT`, PgBouncer alebo lenivé vytváranie spojenia.

## ADR-0010: Jednotné spracovanie chýb ako JSON
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** API vracia JSON a binárne dlaždice. PHP warning vypísaný do výstupu by poškodil odpoveď a detaily výnimiek nesmú unikať klientovi.
- **Zvažované možnosti:**
  - Centrálny `ErrorHandler` + `HttpException`: jednotný formát `{error}` a kontrola nad tým, čo klient uvidí.
  - Ošetrovať chyby v každom controlleri: lokálna kontrola, ale opakovaný kód a ľahko sa niečo vynechá.
  - Spoliehať sa na predvolené správanie PHP: nulová práca, ale HTML chyby uprostred JSON a únik ciest k súborom.
- **Rozhodnutie:** `HttpException` nesie HTTP status a správu bezpečnú pre klienta (400/404/405). Ostatné výnimky sa zalogujú do php-fpm logu a klient dostane všeobecnú 500, detaily len pri `APP_DEBUG=1`. PHP warningy sa menia na `ErrorException` a `display_errors=Off` je v `docker/php/php.ini`.
- **Dôsledky:** Každá chyba má predvídateľný tvar. Fatálne chyby (napr. nedostatok pamäte) handler nezachytí, tie ostávajú len v logu.

## ADR-0011: Vektorové dlaždice z PostGIS pri každej požiadavke, bez diskovej cache
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Mapa musí ostať plynulá aj pri zobrazení celého územia (16 950 parciel v 5 KÚ). Prehliadač nemôže dostať všetky geometrie naraz v plnom detaile.
- **Zvažované možnosti:**
  - MVT cez `ST_AsMVT` pri každej požiadavke a cache len v prehliadači (`Cache-Control`): jeden SQL dotaz a žiadna invalidácia, ale každá nová dlaždica stojí jeden dotaz do DB.
  - MVT s diskovou cache v `var/tiles`, ktorú servuje nginx, a skriptom na predhriatie: opakované dlaždice bez PHP, ale treba atomický zápis, mazanie po importe a predhrievanie.
  - Celé dáta ako jeden GeoJSON: najmenej kódu na serveri, ale niekoľko MB na úvod a parsovanie v prehliadači.
- **Rozhodnutie:** MVT bez diskovej cache. Namerané časy generovania sú 12 až 50 ms na dlaždicu, takže cache by pri tomto rozsahu nepriniesla viditeľný rozdiel. Dlaždica má vrstvu `areas` (hranice KÚ) a od zoomu 10 (minimálny zoom mapy) aj vrstvu `parcels` len s atribútmi `id` a `land_type`; ostatné údaje prídu z API detailu. Geometria parciel je zjednodušená na toleranciu 1 obrazovkového pixela (`ST_Simplify`, s `preserveCollapsed`, aby malé parcely nezmizli). Na z12 to zmenšilo dlaždicu zo 727 kB na 369 kB (so gzipom 212 kB); na z10 je celé územie v jednej dlaždici s 321 kB (157 kB). Server dlaždice generuje do zoomu 16, nad ním ich MapLibre zväčšuje.
- **Dôsledky:** Nginx posiela `/api/` aj `/tiles/` rovno do PHP a gzipuje MVT. Na malých zoomoch tvorí väčšinu veľkosti počet parciel, nie počet bodov; ďalšie zmenšenie by vyžadovalo vynechať malé parcely, čo by v mape nechalo diery. Pri rozšírení na celý okres treba `MIN_PARCEL_ZOOM` zvýšiť, na malých zoomoch zobraziť agregované KÚ a vrátiť do hry diskovú cache alebo predgenerovanie (PMTiles).

## ADR-0012: Detail parcely ako typované pole z repozitára, bez doménových objektov
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** `GET /api/parcels/{id}` vracia jednu parcelu s názvami KÚ, druhu pozemku a spôsobu využitia.
- **Zvažované možnosti:**
  - Repozitár vráti riadok ako pole s PHPStan array shape a controller z neho poskladá JSON: najmenej kódu a PHPStan na leveli 8 aj tak kontroluje kľúče a typy, ale tvar dát nie je samostatná trieda.
  - Doménové objekty (readonly DTO `Parcel`, value object `ParcelNumber`, enum `LandType`): explicitné typy, ale ďalšie triedy, ktoré len prekladajú stĺpce na vlastnosti.
  - Vrátiť riadok z DB priamo ako JSON: najkratšie, ale API by prebralo názvy stĺpcov a zmena schémy by rozbila klienta.
- **Rozhodnutie:** Array shape v `ParcelRepository::find()` a tvar JSON v `ParcelController`. Číslo parcely (`st. 677/3`) je predpočítané pri importe a názvy kódov prídu z JOIN na číselníky (ADR-0007), takže doménové objekty by neniesli žiadnu logiku.
- **Dôsledky:** API má vlastné názvy kľúčov (`areaM2`, `cadastralArea`, `landType`) oddelené od stĺpcov. Ak pribudne logika nad parcelou (napr. výpočty pre výkup alebo BPEJ), bude dávať zmysel zaviesť DTO. Neznámy kód z číselníka vráti `name: null`. ID väčšie ako `PHP_INT_MAX` vráti 404.

## ADR-0013: Frontend ako jeden HTML súbor s MapLibre GL JS z CDN
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Frontend má zobraziť vektorové dlaždice, po kliknutí detail parcely a legendu druhov pozemku. Na výkon mapy framework nemá vplyv, mapu kreslí knižnica cez WebGL; rozhoduje množstvo kódu a to, či spustenie potrebuje Node.
- **Zvažované možnosti:**
  - Jeden `frontend/index.html` s MapLibre GL 4.7.1 z unpkg a čistým JS: žiadny build ani `node_modules` a nginx ho servuje priamo, ale bez typov a komponentov, takže pri väčšom UI by sa súbor ťažko udržiaval.
  - Vite + React + TypeScript s `react-map-gl`: typy a komponenty, menej kódu pri mnohých funkciách, ale build, závislosti a viac boilerplate pre jednu obrazovku.
  - Leaflet s pluginom na vektorové dlaždice: jednoduché API, ale MVT podporuje len cez plugin a rendering cez Canvas/SVG je pri tisícoch polygónov pomalší ako WebGL v MapLibre.
- **Rozhodnutie:** Jeden HTML súbor s MapLibre. Parcely sú zafarbené podľa `land_type` (farby a názvy v jednom poli `LAND_TYPES`, z ktorého sa skladá štýl aj legenda) a legenda vie jednotlivé druhy vypnúť cez `setFilter`. Klik načíta `/api/parcels/{id}`, predchádzajúci request zruší (`AbortController`) a vybranú parcelu zvýrazní filtrom na `id` prvku. Text v paneli sa vkladá cez `textContent`.
- **Dôsledky:** Spustenie nepotrebuje Node; `docker-compose.yml` pripája priamo `frontend/`. Aplikácia závisí od unpkg (online). Pri pribúdaní funkcií (vyhľadávanie, multi-výber, export) by sa oplatilo prejsť na Vite + TypeScript.

## ADR-0014: Mapový podklad OpenStreetMap
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Pod parcely treba podkladovú mapu. Zadanie ponúka OpenStreetMap, Mapy.cz alebo Mapbox. Podklad sú vo všetkých prípadoch rastrové dlaždice, takže výkon je rovnaký.
- **Zvažované možnosti:**
  - OpenStreetMap (`tile.openstreetmap.org`): bez registrácie a API kľúča, jedna URL v štýle mapy, ale v ČR slabšie detaily a server nie je určený na produkčnú záťaž.
  - Mapy.com (REST API dlaždíc): lepšia kartografia pre ČR a letecké snímky, ale treba registráciu, API kľúč viditeľný vo frontende a povinnú atribúciu.
  - Ortofoto ČÚZK: vidno, čo na pozemku reálne je, ale bez názvov a ciest, takže ako jediný podklad sa zle orientuje.
- **Rozhodnutie:** OSM. Aplikáciu spustí ktokoľvek bez registrácie a konfigurácie.
- **Dôsledky:** Na produkciu by bol potrebný vlastný podklad alebo Mapy.com s kľúčom. Prepínač podkladov (Mapy.com, ortofoto) a prekryv katastrálnej mapy (WMS ČÚZK) sú kandidáti na ďalšiu prácu.

## ADR-0015: nginx + php-fpm namiesto vstavaného servera PHP
- **Dátum:** 2026-10-19
- **Stav:** Prijaté
- **Kontext:** Mapa pri každom posune naraz žiada 10 až 20 dlaždíc a PHP potrebuje webserver.
- **Zvažované možnosti:**
  - nginx + php-fpm: php-fpm obslúži požiadavky paralelne a nginx servuje frontend bez PHP a gzipuje odpovede, ale pribudne kontajner a konfigurácia.
  - `php -S` (vstavaný server): nulová konfigurácia, ale je jednovláknový, takže dlaždice by stáli v rade a mapa by sekala.
  - Apache s `mod_php`: jeden kontajner pre web aj PHP, ale ťažší a pri statických súboroch a paralelných požiadavkách menej efektívny.
- **Rozhodnutie:** nginx 1.27 + php-fpm 8.3. Nginx servuje `frontend/index.html` priamo a `/api/` aj `/tiles/` posiela cez FastCGI do jediného vstupného bodu `public/index.php`.
- **Dôsledky:** `docker/nginx/default.conf` je pripojený ako jeden súbor; po jeho úprave treba nginx kontajner reštartovať (`reload` nevidí nový inode súboru). Nginx je pripravený aj na neskoršiu cache dlaždíc alebo HTTPS.
