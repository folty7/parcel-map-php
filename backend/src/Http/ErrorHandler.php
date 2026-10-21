<?php

declare(strict_types=1);

namespace Viagem\Http;

use ErrorException;
use Throwable;

/**
 * Turns PHP warnings into exceptions and every exception into a JSON error response.
 *
 * HttpException messages are shown to the client; anything else is logged and returned
 * as a generic 500, with details only in debug mode.
 */
final readonly class ErrorHandler
{
    public function __construct(private bool $debug)
    {
    }

    public function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // silenced with @ or excluded by error_reporting
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function render(Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status, $e->headers);
        }

        error_log((string) $e); // goes to the php-fpm log, i.e. `docker compose logs php`

        $body = ['error' => 'Internal server error'];
        if ($this->debug) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return new JsonResponse($body, 500);
    }
}
