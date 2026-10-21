<?php

declare(strict_types=1);

use Viagem\Application;
use Viagem\Http\JsonResponse;
use Viagem\Http\Request;
use Viagem\Infrastructure\Config;

require __DIR__ . '/../vendor/autoload.php';

try {
    $config = Config::fromEnvironment();
} catch (Throwable $e) {
    // Misconfiguration happens before the error handler exists, so answer here.
    error_log((string) $e);
    (new JsonResponse(['error' => 'Server misconfigured'], 500))->send();
    exit;
}

(new Application($config))
    ->handle(Request::fromGlobals())
    ->send();
