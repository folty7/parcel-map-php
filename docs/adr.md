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
