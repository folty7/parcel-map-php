<?php

declare(strict_types=1);

namespace Viagem\Repository;

use PDO;

final readonly class ParcelRepository
{
    /** Below this zoom the tile carries only cadastral area boundaries; parcels would be sub-pixel noise. */
    private const int MIN_PARCEL_ZOOM = 12;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Renders one Mapbox Vector Tile with layers "areas" (cadastral areas) and "parcels".
     * Returns an empty string when the tile has no features.
     *
     * Parcels are simplified to one screen pixel (a 512 px tile spans 40 075 km / 2^z of Web Mercator),
     * which halves the tile size at low zooms without a visible change. Parcels carry only id and land type;
     * the rest comes from the detail API, so the tiles stay small.
     */
    public function tile(int $z, int $x, int $y): string
    {
        $statement = $this->db->prepare(<<<'SQL'
            WITH tile AS (
                SELECT t.z, ST_TileEnvelope(t.z, t.x, t.y) AS envelope, 40075016.68 / 512 / 2 ^ t.z AS pixel
                FROM (SELECT CAST(:z AS int) AS z, CAST(:x AS int) AS x, CAST(:y AS int) AS y) t
            ),
            area_features AS (
                SELECT a.code, a.name, ST_AsMVTGeom(a.geom, tile.envelope) AS geom
                FROM cadastral_areas a, tile
                WHERE a.geom && tile.envelope
            ),
            parcel_features AS (
                SELECT p.id, p.land_type, ST_AsMVTGeom(ST_Simplify(p.geom, tile.pixel, true), tile.envelope) AS geom
                FROM parcels p, tile
                WHERE tile.z >= CAST(:min_parcel_zoom AS int) AND p.geom && tile.envelope
            )
            SELECT (SELECT ST_AsMVT(area_features, 'areas') FROM area_features)
                || (SELECT ST_AsMVT(parcel_features, 'parcels', 4096, 'geom', 'id') FROM parcel_features)
            SQL);
        $statement->bindValue('z', $z, PDO::PARAM_INT);
        $statement->bindValue('x', $x, PDO::PARAM_INT);
        $statement->bindValue('y', $y, PDO::PARAM_INT);
        $statement->bindValue('min_parcel_zoom', self::MIN_PARCEL_ZOOM, PDO::PARAM_INT);
        $statement->execute();

        /** @var resource|null $tile pdo_pgsql returns bytea as a stream (the PDO stubs do not know that) */
        $tile = $statement->fetchColumn();

        return is_resource($tile) ? (string) stream_get_contents($tile) : '';
    }
}
