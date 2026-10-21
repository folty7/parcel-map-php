<?php

declare(strict_types=1);

namespace Viagem\Controller;

use Viagem\Http\HttpException;
use Viagem\Http\JsonResponse;
use Viagem\Http\Request;
use Viagem\Repository\ParcelRepository;

final readonly class ParcelController
{
    public function __construct(private ParcelRepository $parcels)
    {
    }

    public function show(Request $request): JsonResponse
    {
        // The route accepts digits only; ids above PHP_INT_MAX cannot exist, so they are a plain 404.
        $id = filter_var($request->routeParam('id'), FILTER_VALIDATE_INT);
        $parcel = $id === false ? null : $this->parcels->find($id);

        if ($parcel === null) {
            throw HttpException::notFound('Parcel not found');
        }

        return new JsonResponse([
            'id' => $parcel['id'],
            'number' => $parcel['number'],
            'areaM2' => $parcel['area_m2'],
            'cadastralArea' => ['code' => $parcel['area_code'], 'name' => $parcel['area_name']],
            'municipality' => $parcel['municipality'],
            'landType' => self::codeName($parcel['land_type'], $parcel['land_type_name']),
            'landUse' => self::codeName($parcel['land_use'], $parcel['land_use_name']),
            'links' => [
                // The RÚIAN parcel id works directly in the public cadastre viewer.
                'nahlizeniDoKn' => 'https://nahlizenidokn.cuzk.gov.cz/ZobrazObjekt.aspx?typ=parcela&id=' . $parcel['id'],
            ],
        ]);
    }

    /**
     * @return array{code: int, name: string|null}|null
     */
    private static function codeName(?int $code, ?string $name): ?array
    {
        return $code === null ? null : ['code' => $code, 'name' => $name];
    }
}
