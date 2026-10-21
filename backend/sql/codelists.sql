-- Code lists from RÚIAN ST_UCIS (20260803_ST_UCIS.xml.zip). They are small and stable, so they are
-- committed as data instead of being parsed on every import.

CREATE TABLE IF NOT EXISTS land_types (code smallint PRIMARY KEY, name text NOT NULL);
CREATE TABLE IF NOT EXISTS land_uses (code smallint PRIMARY KEY, name text NOT NULL);
TRUNCATE land_types, land_uses;

INSERT INTO land_types (code, name) VALUES
    (2, 'orná půda'),
    (3, 'chmelnice'),
    (4, 'vinice'),
    (5, 'zahrada'),
    (6, 'ovocný sad'),
    (7, 'trvalý travní porost'),
    (8, 'trvalý travní porost'),
    (10, 'lesní pozemek'),
    (11, 'vodní plocha'),
    (13, 'zastavěná plocha a nádvoří'),
    (14, 'ostatní plocha');

INSERT INTO land_uses (code, name) VALUES
    (1, 'skleník, pařeniště'),
    (2, 'školka'),
    (3, 'plantáž dřevin'),
    (4, 'les jiný než hospodářský'),
    (5, 'lesní pozemek, na kterém je budova'),
    (6, 'rybník'),
    (7, 'koryto vodního toku přirozené nebo upravené'),
    (8, 'koryto vodního toku umělé'),
    (9, 'vodní nádrž přírodní'),
    (10, 'vodní nádrž umělá'),
    (11, 'zamokřená plocha'),
    (12, 'společný dvůr'),
    (13, 'zbořeniště'),
    (14, 'dráha'),
    (15, 'dálnice'),
    (16, 'silnice'),
    (17, 'ostatní komunikace'),
    (18, 'ostatní dopravní plocha'),
    (19, 'zeleň'),
    (20, 'sportoviště a rekreační plocha'),
    (21, 'pohřebiště'),
    (22, 'kulturní a osvětová plocha'),
    (23, 'manipulační plocha'),
    (24, 'dobývací prostor'),
    (25, 'skládka'),
    (26, 'jiná plocha'),
    (27, 'neplodná půda'),
    (28, 'vodní plocha, na které je budova'),
    (29, 'fotovoltaická elektrárna'),
    (30, 'mez, stráň');
