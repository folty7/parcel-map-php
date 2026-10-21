-- Builds the application tables from the raw VFR layers loaded by bin/import.sh into the staging schema.
-- Geometries are transformed to Web Mercator (EPSG:3857) once here, because vector tiles are cut in 3857.

BEGIN;

DROP TABLE IF EXISTS parcels, cadastral_areas;

CREATE TABLE cadastral_areas AS
SELECT k.kod AS code,
       k.nazev AS name,
       o.nazev AS municipality,
       ST_Multi(ST_Transform(k.originalnihranice, 3857))::geometry(MultiPolygon, 3857) AS geom
FROM staging.katastralniuzemi k
JOIN staging.obce o ON o.kod = k.obeckod;

ALTER TABLE cadastral_areas ADD PRIMARY KEY (code);

CREATE TABLE parcels AS
SELECT p.id,
       p.katastralniuzemikod AS area_code,
       -- Parcel number as written in the cadastre: "st. 123/4" for building plots, "820/13" otherwise
       CASE WHEN p.druhcislovanikod = 1 THEN 'st. ' ELSE '' END
           || p.kmenovecislo || COALESCE('/' || p.pododdelenicisla, '') AS number,
       p.druhpozemkukod AS land_type,
       p.zpusobyvyuzitipozemku AS land_use,
       p.vymeraparcely AS area_m2,
       ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(p.originalnihranice, 3857)), 3))
           ::geometry(MultiPolygon, 3857) AS geom
FROM staging.parcely p;

ALTER TABLE parcels ADD PRIMARY KEY (id);
CREATE INDEX parcels_geom_idx ON parcels USING gist (geom);

DROP SCHEMA staging CASCADE;

COMMIT;

ANALYZE parcels;
ANALYZE cadastral_areas;
