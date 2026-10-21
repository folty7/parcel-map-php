<?php

declare(strict_types=1);

namespace Viagem\Controller;

use PDO;
use Viagem\Http\JsonResponse;
use Viagem\Http\Request;

final readonly class HealthController
{
    public function __construct(private PDO $db)
    {
    }

    public function show(Request $request): JsonResponse
    {
        // With ERRMODE_EXCEPTION query() throws on failure; the false check only satisfies the type.
        $statement = $this->db->query('SELECT postgis_lib_version()');
        $postgis = $statement === false ? null : $statement->fetchColumn();

        return new JsonResponse([
            'status' => 'ok',
            'php' => PHP_VERSION,
            'postgis' => $postgis,
        ]);
    }
}
