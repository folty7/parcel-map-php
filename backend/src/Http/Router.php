<?php

declare(strict_types=1);

namespace Viagem\Http;

/**
 * Minimal router: maps "METHOD /path/{param}" patterns to handlers.
 *
 * Placeholders match one path segment by default ({id}), or a custom regex ({z:\d+}).
 * Matched values are passed to the handler via Request::routeParam().
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable(Request): Response}> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => self::compile($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * @throws HttpException 404 when no route matches the path, 405 when only the method differs
     */
    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = array_map(
                rawurldecode(...),
                array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY),
            );

            return ($route['handler'])($request->withRouteParams($params));
        }

        if ($allowedMethods !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowedMethods)));
        }

        throw HttpException::notFound(sprintf('No route for %s %s', $request->method, $request->path));
    }

    /**
     * Turns "/tiles/{z:\d+}/{y:\d+}.pbf" into "#^/tiles/(?P<z>\d+)/(?P<y>\d+)\.pbf$#".
     * Literal parts are escaped, so "." matches only a dot.
     */
    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}|[^{]+#',
            static fn (array $m): string => isset($m[1])
                ? sprintf('(?P<%s>%s)', $m[1], ($m[2] ?? '') !== '' ? $m[2] : '[^/]+')
                : preg_quote($m[0], '#'),
            $pattern,
        );

        return '#^' . $regex . '$#';
    }
}
