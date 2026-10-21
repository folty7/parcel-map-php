<?php

declare(strict_types=1);

namespace Viagem\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed>  $query       parsed query string ($_GET)
     * @param array<string, string> $routeParams placeholders matched by the router, e.g. ['id' => '42']
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $routeParams = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';

        return new self(
            method: strtoupper($method),
            path: is_string($path) && $path !== '' ? $path : '/',
            query: $_GET,
        );
    }

    /**
     * @param array<string, string> $routeParams
     */
    public function withRouteParams(array $routeParams): self
    {
        return new self($this->method, $this->path, $this->query, $routeParams);
    }

    public function routeParam(string $name): string
    {
        return $this->routeParams[$name]
            ?? throw new \LogicException(sprintf('Route parameter "%s" is not defined.', $name));
    }

    /**
     * Returns a query string value, or null when missing or not a scalar (e.g. ?q[]=x).
     */
    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
