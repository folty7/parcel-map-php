<?php

declare(strict_types=1);

namespace Viagem\Controller;

use Viagem\Http\HttpException;
use Viagem\Http\Request;
use Viagem\Http\Response;
use Viagem\Repository\ParcelRepository;

final readonly class TileController
{
    /** The frontend requests tiles up to this zoom and MapLibre overzooms them beyond it. */
    private const int MAX_ZOOM = 16;

    public function __construct(private ParcelRepository $parcels)
    {
    }

    public function show(Request $request): Response
    {
        $z = (int) $request->routeParam('z');
        $x = (int) $request->routeParam('x');
        $y = (int) $request->routeParam('y');

        if ($z > self::MAX_ZOOM || $x >= 2 ** $z || $y >= 2 ** $z) {
            throw HttpException::badRequest('Tile coordinates out of range');
        }

        $tile = $this->parcels->tile($z, $x, $y);

        return new Response(
            body: $tile,
            status: $tile === '' ? 204 : 200,
            headers: [
                'Content-Type' => 'application/vnd.mapbox-vector-tile',
                // Data changes at most monthly (new VFR import), so browsers may keep tiles for a day.
                'Cache-Control' => 'public, max-age=86400',
            ],
        );
    }
}
