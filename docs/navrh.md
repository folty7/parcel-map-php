# Mapa parciel okresu Jičín – návrh riešenia

## 1. Čo ideme robiť a prečo

### 1.1 Biznis kontext
Doménou aplikácie je **výkup pozemkov**, typicky poľnohospodársku pôdu, lesy alebo trvalé trávne porasty. V Česku je pôda po reštitúciách a dedičských konaniach veľmi rozdrobená: jedno pole môže tvoriť desiatky parciel s rôznymi vlastníkmi. Práca výkupcu preto vyzerá zhruba takto:

1. **Vytipovať oblasť** (obec, katastrálne územie, okres).
2. **Pozrieť parcely na mape**: aké sú veľké, aký majú druh (orná pôda vs. les vs. zastavaná plocha), ako na seba nadväzujú.
3. **Zistiť vlastníka** parcely z listu vlastníctva v katastri nehnuteľností.
4. **Osloviť vlastníka** a dohodnúť výkup.

Naša aplikácia pokrýva **kroky 1–2** a pri kroku 3 pošle používateľa jedným klikom na oficiálny náhľad katastra (Nahlížení do KN), kde vlastníka nájde. Vlastníci v otvorených dátach nie sú, sú to osobné údaje.

**Prečo je kľúčová plynulosť:** analytik prechádza veľké územia. Ak mapa pri pohľade na celý okres seká alebo sa načítava sekundy, nástroj sa nedá používať. **Stovky tisíc polygónov sa nedajú naraz poslať do prehliadača**, takže to treba riešiť architektonicky.

**Prečo sú dôležité druh pozemku a výmera:** od nich priamo závisí cena a zaujímavosť pozemku pre výkup. Orná pôda sa oceňuje inak ako les a malé parcely bývajú pre výkup menej zaujímavé.

### 1.2 Čo konkrétne postavíme (jednou vetou)
Webovú mapu celého okresu Jičín, kde vidieť všetky parcely (zafarbené podľa druhu pozemku) a po kliknutí na parcelu sa zobrazí jej číslo, katastrálne územie, výmera, druh a využitie a odkaz do katastra. Celé to beží plynulo vďaka vektorovým dlaždiciam generovaným v PHP z databázy PostGIS.

### 1.3 Slovník pojmov

**Kataster a územie**
| Pojem | Vysvetlenie |
|---|---|
| **Katastr nemovitostí (KN)** | Štátny register nehnuteľností v ČR: parcely, budovy, vlastníci, práva. Vedie ho ČÚZK. |
| **ČÚZK** | Český úřad zeměměřický a katastrální. Poskytuje dáta katastra a mapové služby. |
| **Parcela** | Geometricky určený pozemok s parcelným číslom. Je to základná jednotka, ktorú zobrazujeme. |
| **Parcelné číslo** | Napr. `123/4`: **kmeňové číslo** 123, **pododdelenie** 4. Ak parcelu rozdelili, vzniknú `123/1`, `123/2` … |
| **Stavebná vs. pozemková parcela** | Stavebná („st. 123“) je pod budovou, pozemková („123“) je ostatná pôda. Číslujú sa samostatne, takže v jednom KÚ môže existovať aj „st. 123“, aj „123“. |
| **Katastrálne územie (KÚ)** | Územná jednotka katastra. Parcelné čísla sú jedinečné len v rámci KÚ. Obec môže mať viac KÚ. |
| **Obec / okres** | Administratívne jednotky. Okres Jičín má ~111 obcí a viac KÚ. |
| **Výmera** | Plocha parcely v m² podľa katastra. Nemusí presne sedieť s plochou spočítanou z geometrie. |
| **Druh pozemku** | Číselník KN: orná pôda, chmelnica, vinica, záhrada, ovocný sad, trvalý trávny porast (TTP), lesný pozemok, vodná plocha, zastavaná plocha a nádvorie, ostatná plocha. |
| **Spôsob využitia** | Podrobnejšie určenie, napr. „ostatná plocha – cesta“. |
| **List vlastníctva (LV)** | Dokument s vlastníkmi a právami k nehnuteľnostiam. Nie je v otvorených dátach. |
| **Nahlížení do KN** | Verejná webová aplikácia ČÚZK na prezeranie katastra vrátane LV. Odkážeme na ňu. |

**Dáta ČÚZK**
| Pojem | Vysvetlenie |
|---|---|
| **RÚIAN** | Registr územní identifikace, adres a nemovitostí: základný register s územnými prvkami (štát → okres → obec → KÚ → parcela, budova, adresa). Parcely do neho preberá z katastra. |
| **VFR** | Výmenný formát RÚIAN: XML/GML súbory na stiahnutie, mesačne aktualizované. Súbor obce `OB_<kód>_UKSH` obsahuje **kompletné dáta obce vrátane parciel a ich hraníc**. |
| **INSPIRE** | Smernica EÚ o zdieľaní priestorových dát. ČÚZK podľa nej publikuje aj parcely (téma *Cadastral Parcels*), ale s menej atribútmi. |
| **ISKN** | Informačný systém katastra nehnuteľností, interný zdroj, z ktorého sa plní RÚIAN. |

**Mapové služby a formáty**
| Pojem | Vysvetlenie |
|---|---|
| **WMS** | Web Map Service: server vráti hotový **obrázok** výrezu mapy. Na podklad je to jednoduché, ale nedá sa na neho klikať ani ho štýlovať. |
| **WMTS** | Ako WMS, ale s predrezanými obrázkovými dlaždicami, teda rýchlejšie. |
| **WFS** | Web Feature Service: server vráti **vektorové dáta** (geometrie + atribúty). Pri veľkých územiach je pomalý a obmedzený počtom prvkov. |
| **GML** | Geography Markup Language: XML formát pre geometrie. VFR je GML. |
| **Rastrová dlaždica** | Obrázok 256×256 px pre jeden výrez mapy na danom zoome (napr. OSM podklad). |
| **Vektorová dlaždica (MVT)** | Mapbox Vector Tile: binárny (protobuf) balík **geometrií a atribútov** pre jeden výrez. Prehliadač ho vykreslí sám, takže sa dá štýlovať, klikať a zvýrazňovať. Je to kľúčová technológia pre plynulosť. |
| **Zoom / pyramída dlaždíc** | Zoom 0 = celý svet v 1 dlaždici, každý ďalší zoom ×4 dlaždíc. Adresa dlaždice je `z/x/y`. Okres sa zmestí do pár dlaždíc na z 9, ale na z 16 je ich tisíce. |
| **Generalizácia (simplifikácia)** | Zjednodušenie geometrie (menej bodov) pri malom zoome, keď detail aj tak nevidno. Vďaka tomu sú dlaždice menšie a rýchlejšie. |
| **Choropleth** | Mapa, kde sú plochy zafarbené podľa hodnoty (napr. počet parciel v KÚ). |
| **Ortofoto** | Letecká snímka upravená tak, aby sedela na mapu. Pre výkup je užitočné vidieť, čo na pozemku reálne je. |

**Súradnicové systémy**
| Pojem | Vysvetlenie |
|---|---|
| **S-JTSK (EPSG:5514)** | Český národný súradnicový systém v metroch. ČÚZK v ňom dodáva dáta. |
| **WGS 84 (EPSG:4326)** | Zemepisná šírka a dĺžka (GPS). |
| **Web Mercator (EPSG:3857)** | Projekcia webových máp. Dlaždice z/x/y sú definované v nej, preto do nej dáta transformujeme. |
| **Definičný bod** | Bod vnútri parcely, ktorý dodáva RÚIAN. Hodí sa na popisky a „súradnice parcely“. |

**Technológie**
| Pojem | Vysvetlenie |
|---|---|
| **PostgreSQL + PostGIS** | Relačná databáza s rozšírením pre priestorové dáta (geometrie, priestorové indexy, transformácie, `ST_AsMVT`). |
| **GIST index** | Priestorový index v PostGIS. Umožní rýchlo nájsť parcely v danom výreze bez prechádzania všetkých riadkov. |
| **GDAL / ogr2ogr** | Pozri rozhodnutie 6. |
| **MapLibre GL JS** | Open-source JavaScriptová knižnica na vykresľovanie máp cez WebGL (grafickú kartu). Natívne vie MVT. |
| **php-fpm** | Proces manažér PHP, ktorý obsluhuje viac požiadaviek **paralelne**. Webserver (nginx) mu posiela PHP požiadavky. |
| **Docker Compose** | Spustí všetky služby (DB, PHP, nginx) jedným príkazom rovnako na každom počítači. |

---

## 2. Rozsah a podmienky
- **Rozsah:** celý okres Jičín (minimum je KÚ Jičín + 3 ďalšie KÚ).
- **Spúšťanie:** Docker Compose.
- **Backend:** čisté PHP 8.3, žiadny framework.
- **Priority:** plynulosť a výkon, funkčnosť, čitateľný a štruktúrovaný PHP kód.

## 3. Architektúra

```
          PRÍPRAVA DÁT (raz, offline)                               RUNTIME
┌──────────────────────────────────────────────┐   ┌───────────────────────────────────────────┐
│ php bin/import  (PHP CLI)                    │   │ Prehliadač: React + react-map-gl/MapLibre │
│  1. zoznam obcí okresu Jičín (ST_UZSZ)       │   │   ├─ podklad: OSM / Mapy.com / ortofoto   │
│  2. stiahne OB_<kód>_UKSH.xml.zip (VFR)      │   │   ├─ vrstva parciel: MVT dlaždice         │
│  3. ogr2ogr (GDAL) → staging tabuľky         │   │   └─ klik → /api/parcels/{id} → panel     │
│  4. SQL: transformácia 5514→3857, indexy     │   └────────────────┬──────────────────────────┘
└───────────────────────┬──────────────────────┘                    │ HTTP
                        ▼                                           ▼
               ┌──────────────────┐            ┌──────────────────────────────────────┐
               │ PostGIS          │◄───────────│ nginx ──► php-fpm 8.3 (čisté PHP)    │
               │ parcels,         │  ST_AsMVT  │  /tiles/{z}/{x}/{y}.pbf (+disk cache)│
               │ cadastral_areas  │            │  /api/parcels/{id}, /api/search …    │
               └──────────────────┘            │  nginx servuje aj frontend (dist/)   │
                                               └──────────────────────────────────────┘
```

**Tok pri používaní:** prehliadač si pýta dlaždice podľa výrezu. nginx vráti hotovú z disk cache, inak ju PHP vygeneruje v PostGIS, uloží a vráti. Po kliknutí na parcelu frontend zoberie `id` z dlaždice a pýta detail z API.

---

## 4. Rozhodnutia (2 možnosti, odporúčaná je prvá)

### 1. Dáta live z ČÚZK, alebo predom stiahnuť?
| | **A) Predom stiahnuť a importovať ✅** | B) Live dotazy na WFS ČÚZK |
|---|---|---|
| Prečo áno | Výkon nezávisí od ČÚZK. Vlastná DB umožní vlastné indexy, generalizáciu a dlaždice. Funguje offline. | Vždy aktuálne dáta; netreba DB ani import. |
| Prečo nie | Dáta zostarnú (VFR vychádza mesačne), dá sa vyriešiť mesačným re-importom. Import zaberie pár minút. | WFS má limit prvkov na odpoveď a je pomalý, takže celý okres sa plynulo zobraziť nedá. To je v rozpore s hlavnou požiadavkou. Navyše výpadok ČÚZK znamená nefunkčnú aplikáciu. |

### 2. Ktorý dataset ČÚZK?
| | **A) RÚIAN VFR: súbory obcí `OB_<kód>_UKSH` ✅** | B) INSPIRE Cadastral Parcels (GML po KÚ) |
|---|---|---|
| Prečo áno | Bohaté atribúty: číslo parcely, výmera, **druh pozemku**, **spôsob využitia**, KÚ, definičný bod. Súbory idú po obciach, takže okres = zoznam obcí. GDAL ho vie čítať. | Jednoduchšia schéma, súbor na každé KÚ, denná aktualizácia. |
| Prečo nie | Súbory sú väčšie (obsahujú aj adresy a budovy) a schéma je zložitejšia. | **Chýba druh pozemku a využitie**, pritom presne to výkupcu zaujíma. Zoznam KÚ okresu treba zisťovať inak. |

### 3. Úložisko dát
| | **A) PostgreSQL + PostGIS ✅** | B) SQLite + SpatiaLite |
|---|---|---|
| Prečo áno | `ST_AsMVT` vyrobí vektorovú dlaždicu priamo v SQL. Má GIST indexy a simplifikáciu a je to štandard v GIS praxi. | Jeden súbor, žiadny DB server. |
| Prečo nie | Ďalšia služba, čo pri Dockeri nevadí. | Nevie generovať MVT, takže dlaždice by sa museli predgenerovať iným nástrojom. Rozšírenie sa v PHP zle nastavuje. |

### 4. Ako dostať geometrie do prehliadača (jadro výkonu)
| | **A) Dynamické MVT z PostGIS cez PHP + disk cache ✅** | B) Predgenerovaný PMTiles súbor (tippecanoe), servovaný staticky |
|---|---|---|
| Prečo áno | Logika ostáva v PHP backende. Generuje sa len to, kam sa niekto pozrie, a potom sa to cachuje. Zmena štýlu alebo atribútov nepotrebuje rebuild. Celý tile server má ~150 riadkov PHP. | Najrýchlejšie možné servovanie a triviálny runtime. |
| Prečo nie | Prvá („studená“) dlaždica trvá desiatky ms. Rieši sa to cache a predhriatím nízkych zoomov. | Backend by nerobil takmer nič a logika by sa presunula do externého nástroja. Pribudne ďalší nástroj a pri každej zmene treba celý rebuild. |

Zamietnuté: **celý GeoJSON naraz**. Stovky MB do prehliadača by aplikáciu zabili.

**Stratégia podľa zoomu:**
- **z ≤ 11:** len hranice KÚ (+ neskôr choropleth). Celý okres je tak okamžite čitateľný.
- **z 12–14:** parcely so zjednodušenou geometriou. Vynechajú sa tie, čo by mali menej ako ~1 px.
- **z ≥ 15:** plné geometrie a od z 16 aj popisky parcelných čísel.
- V dlaždici sú len `id`, `druh` a `cislo`. Ostatné atribúty sa načítajú až po kliknutí, aby dlaždice ostali malé.

### 5. Backend: čisté PHP, alebo framework?
| | **A) Čisté PHP 8.3 + Composer (PSR-4 autoload), vlastný mini-router ✅** | B) Slim 4 / Symfony |
|---|---|---|
| Prečo áno | Malá aplikácia bez „mágie“: HTTP vrstva a štruktúra sú priamo v kóde a ľahko sa v nich orientuje. Využije moderné PHP: `strict_types`, readonly triedy, enumy. | Hotový routing, middleware a DI, menej boilerplate. |
| Prečo nie | Router, error handling a JSON odpovede treba napísať ručne (~100 riadkov). | Framework pridá závislosti a konvencie, ktoré na pár endpointov nie sú potrebné. Symfony je na 4 endpointy zbytočne ťažké. |

### 6. Import dát (a čo je GDAL)
**GDAL** (Geospatial Data Abstraction Library) je open-source knižnica a sada nástrojov, ktorá je „švajčiarsky nožík“ GIS sveta. Číta a zapisuje ~200 formátov priestorových dát (GML, Shapefile, GeoJSON, PostGIS…) a vie transformovať súradnicové systémy. Používa ju QGIS, PostGIS aj väčšina GIS softvéru. Jej nástroj **`ogr2ogr`** prevádza dáta z jedného formátu do druhého. U nás to bude jeden príkaz typu „zober tento VFR súbor a nalej vrstvu Parcely do tabuľky v PostGIS“. GDAL pozná štruktúru RÚIAN VFR, takže zložité GML nemusíme parsovať ručne.

| | **A) PHP CLI riadi import, ťažkú prácu robí `ogr2ogr` ✅** | B) Vlastný streamovaný parser GML v PHP (XMLReader) |
|---|---|---|
| Prečo áno | Overený a rýchly nástroj. PHP rieši logiku: zoznam obcí, sťahovanie s opakovaním, spúšťanie ogr2ogr, SQL post-process a výpis priebehu. Hotové za ~2 h. | Viac PHP kódu na ukážku, žiadna závislosť navyše. |
| Prečo nie | Závislosť na GDAL. V Dockeri je to len `apt-get install gdal-bin` v PHP image. | Parsovanie GML 3.2 geometrií (multipolygóny, diery, oblúky) je pracné a chybové. Na 4–12 h je to zlý obchod. |

### 7. Mapová knižnica
| | **A) MapLibre GL JS ✅** | B) Leaflet (+ VectorGrid) |
|---|---|---|
| Prečo áno | Kreslí cez WebGL na grafickej karte, takže desaťtisíce polygónov idú plynulo. Natívne MVT, zvýraznenie cez `feature-state`, farby podľa atribútu, bez API kľúča. | Najjednoduchšie API, veľa návodov. |
| Prečo nie | Štýlová špecifikácia (výrazy) sa chvíľu učí. | Kreslí cez Canvas/SVG v hlavnom vlákne, takže pri tisícoch polygónov seká. Výkon je pritom hlavné kritérium. |

### 8. Frontend: framework, alebo nie?
**Kľúčový fakt:** na výkone mapy framework **prakticky nič nemení**. Mapu kreslí MapLibre cez WebGL, mimo React/Vue render cyklu. Framework riadi len panel, legendu a vyhľadávanie, teda pár desiatok DOM prvkov. Bundle je väčší o ~45 kB (gzip), čo je pri lokálnom behu zanedbateľné. Jediné reálne riziko je dávať mapové dáta do React stavu, čomu sa treba vyhnúť. Rozhoduje teda **boilerplate**, a pri bonusoch (legenda s filtrami, vyhľadávanie, multi-výber, export) ho framework výrazne uberie.

| | **A) React + TypeScript + Vite + `react-map-gl/maplibre` ✅** | B) Vanilla TypeScript + Vite |
|---|---|---|
| Prečo áno | Deklaratívne `<Map><Source><Layer/></Source></Map>`. Klik cez `interactiveLayerIds` + `onClick`. Stav (vybraná parcela, filtre) sa automaticky prejaví v paneli aj na mape. Najmenej kódu pri bonusoch a najrozšírenejší ekosystém. | Najmenší bundle a žiadna abstrakcia. |
| Prečo nie | Treba si dať pozor, aby sa mapové dáta nedávali do React stavu (zbytočné prerendery). | Ručné prepájanie DOM a stavu (event listenery, prekresľovanie panela) rastie s každou feature a znamená viac boilerplate. |

(Vue 3 by bol rovnako dobrý, ale wrapper pre MapLibre je menej vyspelý ako `react-map-gl`.)

### 9. Mapový podklad
**Výkon:** podklad sú v oboch prípadoch rastrové dlaždice z CDN, takže výkon je **prakticky rovnaký**.
**Boilerplate:** Mapy.com cez REST API je v MapLibre **jedna URL**, rovnako ako OSM (`https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey=…`). Starý Mapy.cz JS SDK (SMap) **nepoužijeme**, je to samostatná mapová knižnica, ktorá by nahradila MapLibre.
**Kvalita:** Mapy.com má pre ČR lepšiu kartografiu (turistická mapa, poľné cesty, názvy tratí) a **vlastné letecké snímky do z 20**. Pre výkupcu je to reálne lepšie.

| | **A) Konfigurovateľný podklad: Mapy.com (ak je v `.env` kľúč) s fallbackom na OSM + ČÚZK ortofoto ✅** | B) Len OSM + ČÚZK ortofoto |
|---|---|---|
| Prečo áno | Najlepšia mapa pre ČR, keď je kľúč k dispozícii. Bez kľúča aplikácia stále funguje, takže na spustenie netreba nič registrovať. Prepínač podkladov pridáme aj tak. | Žiadna registrácia ani limity, menej konfigurácie. |
| Prečo nie | Treba registráciu a dodržať atribúciu (logo Mapy.com). Free tarif má mesačný limit kreditov a kľúč je viditeľný vo frontende (dá sa obmedziť na doménu). | OSM je v ČR slabší v detailoch a ortofoto ČÚZK je len snímka bez názvov. |

### 10. Webserver
| | **A) nginx + php-fpm ✅** | B) `php -S` (vstavaný server) |
|---|---|---|
| Prečo áno | Mapa naraz žiada 10–20 dlaždíc a php-fpm ich obslúži **paralelne**. nginx servuje cachované dlaždice a frontend bez PHP. | Nula konfigurácie. |
| Prečo nie | Pribudne kontajner a jeden nginx config. | Je **jednovláknový**, takže dlaždice by stáli v rade a mapa by sekala. Dobrá poznámka do zápisníka. |

---

## 5. Features

**MVP (povinné)**
1. Mapa celého okresu s plynulým zoomom a posunom (stratégia podľa zoomu).
2. Klik na parcelu zvýrazní parcelu a otvorí bočný panel: KÚ (názov + kód), parcelné číslo, typ (stavebná/pozemková), výmera, druh pozemku, spôsob využitia, súradnice, odkaz na Nahlížení do KN a na Mapy.com.
3. README a zápisník.

**Bonusy (v poradí priority)**
1. **Farba podľa druhu pozemku** + legenda s vypínaním druhov. Je to najrelevantnejšie pre výkup.
2. **Vyhľadávanie**: KÚ + parcelné číslo (napr. „Jičín, 1234/5“), potom zoom na parcelu.
3. **Permalink**: stav mapy a vybraná parcela v URL, dá sa poslať kolegovi.
4. **Prepínač podkladov** (OSM / Mapy.com / ortofoto) + prekryv katastrálnej mapy (WMS ČÚZK).
5. **Multi-výber** parciel so súčtom výmery a **exportom do CSV/GeoJSON**: „balík na výkup“.
6. **Choropleth KÚ** na malých zoomoch (počet parciel, podiel ornej pôdy).
7. **Tooltip** s číslom parcely pri prechode myšou.

