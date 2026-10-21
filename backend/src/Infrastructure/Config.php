<?php

declare(strict_types=1);

namespace Viagem\Infrastructure;

use RuntimeException;

/**
 * Application configuration read from environment variables (set in docker-compose.yml).
 */
final readonly class Config
{
    public function __construct(
        public DatabaseConfig $database,
        public bool $debug,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            database: new DatabaseConfig(
                host: self::require('DB_HOST'),
                port: (int) self::optional('DB_PORT', '5432'),
                name: self::require('DB_NAME'),
                user: self::require('DB_USER'),
                password: self::require('DB_PASSWORD'),
            ),
            debug: filter_var(self::optional('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL),
        );
    }

    private static function require(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Missing required environment variable "%s".', $name));
        }

        return $value;
    }

    private static function optional(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
