#!/usr/bin/env bash
# Imports parcels of the Jičín municipality (cadastral areas Jičín, Popovice u Jičína, Robousy,
# Moravčice and Hubálov) from RÚIAN VFR into PostGIS. Safe to run repeatedly.
#
# Usage (from the repository root, with `docker compose up -d` running):
#   bin/import.sh
set -euo pipefail
cd "$(dirname "$0")/.."

VFR_MONTH=${VFR_MONTH:-202608}
VFR_DATE=${VFR_DATE:-20260831}
MUNICIPALITY=${MUNICIPALITY:-572659} # RÚIAN code of the Jičín municipality
FILE="${VFR_DATE}_OB_${MUNICIPALITY}_UKSH.xml.zip"
DATA_DIR=backend/var/data # mounted as /app/var/data in the php container

psql() {
    docker compose exec -T -e PGOPTIONS='--client-min-messages=warning' db sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
}

mkdir -p "$DATA_DIR"
if [ ! -f "$DATA_DIR/$FILE" ]; then
    echo "Downloading $FILE"
    curl -fL --retry 3 -o "$DATA_DIR/$FILE.part" "https://services.cuzk.cz/vfr/$VFR_MONTH/$FILE"
    mv "$DATA_DIR/$FILE.part" "$DATA_DIR/$FILE"
fi

echo "DROP SCHEMA IF EXISTS staging CASCADE; CREATE SCHEMA staging;" | psql

# ogr2ogr reads the zipped GML directly; layers land in the staging schema as-is (S-JTSK).
for layer in Obce KatastralniUzemi Parcely; do
    echo "Loading $layer"
    docker compose exec -T php sh -c \
        'ogr2ogr -f PostgreSQL "PG:host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER password=$DB_PASSWORD" \
            "$1" "$2" -lco SCHEMA=staging -lco SPATIAL_INDEX=NONE' \
        _ "/vsizip//app/var/data/$FILE" "$layer"
done

echo "Building tables"
cat backend/sql/codelists.sql backend/sql/import.sql | psql

echo "SELECT a.name AS cadastral_area, count(*) AS parcels
      FROM parcels p JOIN cadastral_areas a ON a.code = p.area_code
      GROUP BY a.name ORDER BY parcels DESC;" | psql
