<?php

declare(strict_types=1);

namespace Viagem\Http;

use RuntimeException;

/**
 * An error that should reach the client with a specific HTTP status and a safe-to-show message.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, $message);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(405, 'Method not allowed', ['Allow' => implode(', ', $allowedMethods)]);
    }
}
