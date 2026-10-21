<?php

declare(strict_types=1);

namespace Viagem\Http;

final class JsonResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        parent::__construct(
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: $status,
            headers: ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
        );
    }
}
