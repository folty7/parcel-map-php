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

## ADR-0002: Vlastný minimalistický router
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** Backend je v čistom PHP bez frameworku a potrebuje ~5 GET endpointov s parametrami v ceste (`/api/parcels/{id}`, `/tiles/{z}/{x}/{y}.pbf`).
- **Zvažované možnosti:**
  - Vlastný router (~80 riadkov): žiadna závislosť a kód je čitateľný na jednom mieste, ale bez cache a pokročilých funkcií.
  - `nikic/fast-route`: overený a rýchly, ale je to ďalšia závislosť pre pár ciest.
  - `switch` nad `$_SERVER['REQUEST_URI']` v `index.php`: najmenej kódu, ale neškáluje a mieša routing s logikou.
- **Rozhodnutie:** Vlastný router. Vzory sa kompilujú do regexu s pomenovanými skupinami (`{z:\d+}` = vlastné obmedzenie). Handler je `callable(Request): Response`, takže controllery sa registrujú cez first-class callable (`$health->show(...)`). Rozlišuje 404 (neznáma cesta) a 405 s hlavičkou `Allow` (zlá metóda).
- **Dôsledky:** Lineárne prehľadávanie ciest je pri jednotkách routes zanedbateľné. Pri desiatkach routes alebo potrebe middleware by bolo vhodné prejsť na FastRoute alebo PSR-15.

## ADR-0003: Ručná DI v composition root (`Application`)
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** Controllery potrebujú závislosti (PDO, neskôr repozitáre a služby). Treba ich niekde vytvoriť bez frameworku.
- **Zvažované možnosti:**
  - Ručné skladanie v jednej triede `Application`: explicitné a bez mágie, ale pri raste aplikácie trieda narastie.
  - DI kontajner (napr. PHP-DI s autowiringom): menej kódu pri mnohých službách, ale skrytá konfigurácia a ďalšia závislosť.
  - Globálne singletony / statické gettery: jednoduché, ale ťažko testovateľné.
- **Rozhodnutie:** Ručná DI v `Application::createRouter()`. Závislosti sa vytvárajú cez konštruktory a všetko je vidieť na jednom mieste.
- **Dôsledky:** PDO spojenie sa otvára pri každej PHP požiadavke, aj pri 404. Je to akceptovateľné, lebo takmer všetky endpointy DB potrebujú a cachované dlaždice obslúži nginx bez PHP. Ak by pribudli endpointy bez DB, spojenie bude treba vytvárať lenivo.

## ADR-0004: Jednotné spracovanie chýb ako JSON
- **Dátum:** 2026-10-20
- **Stav:** Prijaté
- **Kontext:** API vracia JSON a binárne dlaždice. PHP warning vypísaný do výstupu by poškodil odpoveď a detaily výnimiek nesmú unikať klientovi.
- **Zvažované možnosti:**
  - Centrálny `ErrorHandler` + `HttpException`: jednotný formát `{error}` a kontrola nad tým, čo klient uvidí.
  - Ošetrovať chyby v každom controlleri: lokálna kontrola, ale opakovaný kód a ľahko sa niečo vynechá.
  - Spoliehať sa na predvolené správanie PHP: nulová práca, ale HTML chyby uprostred JSON a únik ciest k súborom.
- **Rozhodnutie:** `HttpException` nesie HTTP status a správu bezpečnú pre klienta (400/404/405). Ostatné výnimky sa zalogujú do php-fpm logu a klient dostane všeobecnú 500, detaily len pri `APP_DEBUG=1`. PHP warningy sa menia na `ErrorException` a `display_errors=Off` je v `docker/php/php.ini`.
- **Dôsledky:** Každá chyba má predvídateľný tvar. Fatálne chyby (napr. nedostatok pamäte) handler nezachytí, tie ostávajú len v logu.

## ADR-0005: Zoznam obcí okresu odvodiť zo súboru `ST_UZSZ`
- **Dátum:** 2026-10-21
- **Stav:** Nahradené ADR-0007
- **Kontext:** VFR s parcelami sa vydáva po obciach (`OB_<kód>_UKSH`), takže import potrebuje zoznam kódov všetkých obcí okresu Jičín. Okres má 111 obcí.
- **Zvažované možnosti:**
  - Odvodiť ho zo `ST_UZSZ` (vrstva `Obce`, `OkresKod = 3604`): oficiálny a vždy aktuálny zdroj z tej istej dávky ako parcely, ale treba stiahnuť ďalší súbor (4,5 MB).
  - Natvrdo zapísaný zoznam v kóde: najjednoduchšie, ale pri zlúčení alebo rozdelení obcí zastará a nedá sa ľahko overiť.
  - Číselník ČSÚ alebo iná služba: ďalší zdroj s iným formátom a inou aktualizáciou.
- **Rozhodnutie:** `ST_UZSZ`. Kód okresu (3604) je jediná konštanta v konfigurácii, zoznam obcí aj KÚ sa z nej odvodí pri importe. Rovnaký súbor dá aj názvy obcí a KÚ.
- **Dôsledky:** Import sa dá ľahko presmerovať na iný okres. Filtrovanie a spájanie treba robiť jednoduchými exportmi (`ogr2ogr` → CSV/PostGIS), lebo `JOIN` cez `ogrinfo -sql` nad GML trvá viac ako 5 minút.

## ADR-0006: Geometria `OriginalniHranice` a číselníky zo `ST_UCIS`
- **Dátum:** 2026-10-21
- **Stav:** Nahradené ADR-0009 (časť o číselníkoch; voľba geometrie platí)
- **Kontext:** Vrstva `Parcely` má tri geometrie (`DefinicniBod`, `OriginalniHranice`, `OriginalniHraniceOmpv`). Druh pozemku a spôsob využitia sú len číselné kódy.
- **Zvažované možnosti:**
  - `OriginalniHranice` + `DefinicniBod`: vyplnené pri všetkých 16 950 parcelách obce Jičín. Je to presná hranica z katastra a bod na popisky, ale je detailná, takže pri malom zoome ju treba zjednodušovať.
  - `OriginalniHraniceOmpv`: hranica podľa obrazu mapy s pozemkovými úpravami, ale v Jičíne je prázdna.
  - Názvy kódov zapísať do kódu podľa dokumentácie, alebo ich načítať zo `ST_UCIS`: zapísané názvy sú jednoduché, `ST_UCIS` je oficiálny a obsahuje aj nečakané kódy (TTP má kódy 7 aj 8).
- **Rozhodnutie:** Importujeme `OriginalniHranice` ako polygón parcely a `DefinicniBod` ako jej bod. Druhy pozemkov (11 kódov) budú PHP enum `LandType` overený voči `ST_UCIS`, lebo sú malé a stabilné a enum nesie aj farbu do legendy. Spôsoby využitia (30 kódov) sa importujú ako tabuľka zo `ST_UCIS`.
- **Dôsledky:** Generalizáciu geometrie pre malé zoomy riešime sami v PostGIS. Ak ČÚZK pridá nový druh pozemku, enum treba ručne doplniť, inak sa parcela zobrazí s predvolenou farbou a názvom „neznámy“. BPEJ (`BonitovanyDil*`) je v dátach dostupná a dá sa doplniť ako bonus.

## ADR-0007: Rozsah: 5 katastrálnych území obce Jičín
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Povinné minimum je KÚ Jičín a aspoň 3 ďalšie KÚ v okrese. Celý okres (111 obcí, 240 KÚ) by si vyžiadal zoznam obcí zo `ST_UZSZ`, sťahovanie 111 súborov a robustnejší import.
- **Zvažované možnosti:**
  - Jeden súbor obce Jičín (`OB_572659_UKSH`): obsahuje 5 KÚ (Jičín, Popovice u Jičína, Robousy, Moravčice, Hubálov) a 16 950 parciel, čím splní minimum jedným súborom, ale nepokryje celý okres.
  - Celý okres podľa ADR-0005: viac ako zadanie vyžaduje, ale približne 30-krát viac parciel, takže import aj ladenie výkonu na malých zoomoch sú výrazne zložitejšie.
  - Ručne vybrané KÚ z rôznych obcí: rozptýlené po okrese, ale potrebuje viac súborov a filtrovanie bez pridanej hodnoty pre používateľa.
- **Rozhodnutie:** Importujeme len obec Jičín. Splní povinné minimum s rezervou (5 KÚ namiesto 4) a import je jeden súbor bez zoznamu obcí. ADR-0005 sa zatiaľ nepoužije.
- **Dôsledky:** Kód obce je premenná `MUNICIPALITY` v `bin/import.sh`, takže rozšírenie na celý okres znamená spustiť import v cykle pre zoznam obcí zo `ST_UZSZ` a tabuľky plniť cez `INSERT` namiesto `CREATE TABLE AS`. Výkon pri celom okrese (približne 30-krát viac parciel) treba overiť znova, hlavne generalizáciu geometrie na malých zoomoch.

## ADR-0008: Import ako shell skript: ogr2ogr do staging schémy, potom SQL
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Dáta VFR sú zazipované GML v S-JTSK. Treba ich dostať do PostGIS v EPSG:3857 s pripraveným číslom parcely a indexom, opakovateľne a s čo najmenším množstvom kódu.
- **Zvažované možnosti:**
  - Shell skript `bin/import.sh` (curl → `ogr2ogr` do schémy `staging` → `backend/sql/import.sql`): málo kódu a všetka transformácia je v jednom čitateľnom SQL súbore, ale beží na hoste a predpokladá `docker compose` a bash.
  - PHP CLI príkaz s triedami Downloader, OgrRunner a PostProcessor: ukáže PHP kód, ale je to niekoľko tried, ktoré len obaľujú tie isté príkazy.
  - Parsovanie GML priamo v PHP (XMLReader) a vkladanie cez PDO: bez GDAL, ale veľa kódu na geometrie a transformáciu súradníc.
- **Rozhodnutie:** Shell skript. `ogr2ogr` nahrá vrstvy `Obce`, `KatastralniUzemi` a `Parcely` bez úprav, SQL v jednej transakcii postaví tabuľky `parcels` a `cadastral_areas` (transformácia do 3857, `ST_MakeValid`, číslo parcely ako `st. 123/4`, GIST index) a staging schému zmaže. PHP ostáva len pre HTTP API.
- **Dôsledky:** Import trvá asi 2 sekundy a je idempotentný: tabuľky sa postavia nanovo, aplikácia počas importu vidí starý stav až do `COMMIT`. Transformácia z S-JTSK používa predvolené parametre GDAL bez gridu, takže presnosť je rádovo do 1 m. Na mapu to stačí, na meranie nie.

## ADR-0009: Číselníky druhov a spôsobov využitia ako SQL súbor v repozitári
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Parcely nesú len kódy druhu pozemku (11 hodnôt) a spôsobu využitia (30 hodnôt). Číselníky v `ST_UCIS` GDAL nevie načítať ako vrstvy, lebo nemajú geometriu ani šablónu `.gfs`.
- **Zvažované možnosti:**
  - SQL súbor `backend/sql/codelists.sql` raz vygenerovaný zo `ST_UCIS`: názvy sú v DB a detail parcely ich získa obyčajným JOIN-om, ale pri zmene číselníka treba súbor pregenerovať.
  - PHP enum `LandType` a pole pre spôsoby využitia (ADR-0006): typová kontrola, ale 41 ručne prepísaných hodnôt a ďalšie triedy.
  - Parsovať `ST_UCIS` pri každom importe (XMLReader alebo `grep`): vždy aktuálne, ale kód navyše pre dáta, ktoré sa takmer nemenia.
- **Rozhodnutie:** SQL súbor v repozitári s tabuľkami `land_types` a `land_uses`, ktorý `bin/import.sh` spustí pred `import.sql`. Je to najmenej kódu a údaje sú overiteľné voči zdroju uvedenému v hlavičke súboru.
- **Dôsledky:** Farby druhov pozemkov budú len vo frontende (podľa kódu), názvy len v DB. Import overil, že všetky kódy v dátach majú záznam v číselníkoch. Ak ČÚZK pridá nový kód, JOIN vráti `NULL` a detail parcely ukáže iba kód.

## ADR-0010: Vektorové dlaždice generované z PostGIS pri každej požiadavke, bez diskovej cache
- **Dátum:** 2026-10-21
- **Stav:** Nahradené ADR-0012 (časť o minimálnom zoome parciel; zvyšok platí)
- **Kontext:** Mapa musí ostať plynulá aj pri zobrazení celého územia (16 950 parciel v 5 KÚ).
- **Zvažované možnosti:**
  - MVT cez `ST_AsMVT` pri každej požiadavke a cache len v prehliadači (`Cache-Control`): jeden SQL dotaz a žiadna invalidácia, ale každá nová dlaždica stojí jeden dotaz do DB.
  - MVT s diskovou cache v `var/tiles` a `try_files` v nginx: opakované dlaždice bez PHP, ale treba atomický zápis, mazanie po importe a predhrievanie.
  - Celé dáta ako jeden GeoJSON: najmenej kódu na serveri, ale niekoľko MB na úvod a parsovanie v prehliadači.
- **Rozhodnutie:** MVT bez diskovej cache. Namerané časy generovania sú 12 až 45 ms na dlaždicu, takže cache by pri tomto rozsahu nepriniesla viditeľný rozdiel. Dlaždica má vrstvu `areas` (hranice KÚ) a od zoomu 12 aj vrstvu `parcels` s atribútmi `id` a `land_type`. Ostatné údaje prídu z API detailu. Geometria parciel je zjednodušená na toleranciu 1 obrazovkového pixela (`ST_Simplify`). Na z12 to zmenšilo dlaždicu zo 727 kB na 369 kB (so gzipom 212 kB). Server dlaždice generuje do zoomu 16, nad ním ich MapLibre zväčšuje.
- **Dôsledky:** Nginx posiela `/api/` aj `/tiles/` rovno do PHP a gzipuje MVT. Pri rozšírení na celý okres treba meranie zopakovať: dlaždice na malých zoomoch by obsahovali násobne viac parciel, a potom sa disková cache alebo predgenerovanie (napr. PMTiles) vráti do hry. Na z12 tvorí väčšinu veľkosti samotný počet parciel, nie počet bodov. Ďalšie zmenšenie by vyžadovalo vynechať malé parcely, čo by v mape nechalo diery.

## ADR-0011: Detail parcely ako typované pole z repozitára, bez doménových objektov
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** `GET /api/parcels/{id}` vracia jednu parcelu s názvami KÚ, druhu pozemku a spôsobu využitia.
- **Zvažované možnosti:**
  - Repozitár vráti riadok ako pole s PHPStan array shape a controller z neho poskladá JSON: najmenej kódu a PHPStan na leveli 8 aj tak kontroluje kľúče a typy, ale tvar dát nie je samostatná trieda.
  - Readonly DTO `Parcel` s `toArray()`: explicitný typ, ale ďalšia trieda, ktorá len prekladá stĺpce na vlastnosti.
  - Vrátiť riadok z DB priamo ako JSON: najkratšie, ale API by prebralo názvy stĺpcov a zmena schémy by rozbila klienta.
- **Rozhodnutie:** Array shape v `ParcelRepository::find()` a tvar JSON v `ParcelController`. Číslo parcely (`st. 677/3`) je predpočítané pri importe a názvy kódov prídu z JOIN na číselníky (ADR-0009), takže doménové objekty by neniesli žiadnu logiku.
- **Dôsledky:** API má vlastné názvy kľúčov (`areaM2`, `cadastralArea`, `landType`) oddelené od stĺpcov. Ak pribudne logika nad parcelou (napr. výpočty pre výkup alebo BPEJ), bude dávať zmysel zaviesť DTO. Neznámy kód z číselníka vráti `name: null`. ID väčšie ako `PHP_INT_MAX` vráti 404.

## ADR-0012: Frontend ako jeden HTML súbor s MapLibre z CDN, parcely od zoomu 10
- **Dátum:** 2026-10-21
- **Stav:** Prijaté
- **Kontext:** Frontend má zobraziť vektorové dlaždice, po kliknutí detail parcely a legendu druhov pozemku. Vite + React + TypeScript by si vyžiadal Node, build krok a kontajner alebo inštrukcie navyše. Pri teste v malom okne sa celé územie zmestilo až na zoom 11, no parcely sa zobrazovali od zoomu 12, takže úvodný pohľad ukazoval len hranice KÚ.
- **Zvažované možnosti:**
  - Jeden `frontend/index.html` s MapLibre GL 4.7.1 z unpkg a čistým JS: žiadny build ani `node_modules` a nginx ho servuje priamo, ale bez typov a komponentov, takže pri väčšom UI by sa súbor ťažko udržiaval.
  - Vite + React + TS: typy a komponenty, ale build, závislosti a viac boilerplate pre jednu obrazovku.
  - Leaflet s pluginom na vektorové dlaždice: jednoduché API, ale MVT podporuje len cez plugin a rendering cez Canvas/SVG je pri tisícoch polygónov pomalší ako WebGL v MapLibre.
- **Rozhodnutie:** Jeden HTML súbor s MapLibre. Podklad je OSM raster. Parcely sú zafarbené podľa `land_type` (farby a názvy v jednom poli `LAND_TYPES`, z ktorého sa skladá štýl aj legenda) a legenda vie jednotlivé druhy vypnúť cez `setFilter`. Klik načíta `/api/parcels/{id}`, predchádzajúci request zruší (`AbortController`) a vybranú parcelu zvýrazní filtrom na `id`. Text v paneli sa vkladá cez `textContent`. `MIN_PARCEL_ZOOM` sme znížili z 12 na 10 (minimálny zoom mapy): na z10 je všetkých 16 950 parciel v jednej dlaždici s 321 kB, na z11 je najväčšia dlaždica 411 kB. Nahrádza to časť ADR-0010 o zoome.
- **Dôsledky:** Spustenie nepotrebuje Node a v `docker-compose.yml` sa namiesto `frontend/dist` pripája priamo `frontend/`. Aplikácia závisí od unpkg a OSM dlaždíc (online). OSM tile server nie je určený na produkčnú záťaž, na produkciu by bol potrebný vlastný podklad alebo Mapy.com s API kľúčom. Pri rozšírení na celý okres treba `MIN_PARCEL_ZOOM` zvýšiť alebo na malých zoomoch zobraziť agregované KÚ.
