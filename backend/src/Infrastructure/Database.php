<?php

declare(strict_types=1);

namespace Viagem\Infrastructure;

use PDO;

final class Database
{
    public static function connect(DatabaseConfig $config): PDO
    {
        return new PDO($config->dsn(), $config->user, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepared statements, so parameter types reach PostgreSQL as-is.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
