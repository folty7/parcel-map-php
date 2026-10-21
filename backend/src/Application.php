<?php

declare(strict_types=1);

namespace Viagem;

use Throwable;
use Viagem\Controller\HealthController;
use Viagem\Http\ErrorHandler;
use Viagem\Http\Request;
use Viagem\Http\Response;
use Viagem\Http\Router;
use Viagem\Infrastructure\Config;
use Viagem\Infrastructure\Database;

/**
 * Composition root: wires dependencies by hand, registers routes and handles one request.
 */
final readonly class Application
{
    private ErrorHandler $errorHandler;

    public function __construct(private Config $config)
    {
        $this->errorHandler = new ErrorHandler($config->debug);
        $this->errorHandler->register();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->createRouter()->dispatch($request);
        } catch (Throwable $e) {
            return $this->errorHandler->render($e);
        }
    }

    private function createRouter(): Router
    {
        $db = Database::connect($this->config->database);

        $health = new HealthController($db);

        $router = new Router();
        $router->get('/api/health', $health->show(...));

        return $router;
    }
}
