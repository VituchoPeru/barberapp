<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Núcleo HTTP: middleware global → router → middleware de ruta → controlador. */
final class Application
{
    /** @var list<mixed> */
    private array $globalMiddleware;

    /** @param list<mixed> $globalMiddleware */
    public function __construct(private Router $router, array $globalMiddleware = [])
    {
        $this->globalMiddleware = $globalMiddleware;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = Pipeline::run($this->globalMiddleware, $request, function (Request $req): Response {
                try {
                    return $this->router->dispatch($req);
                } catch (Throwable $e) {
                    // Se renderiza dentro del pipeline para que CORS y cabeceras de seguridad se apliquen también a errores.
                    return ExceptionHandler::render($e, $req);
                }
            });
        } catch (Throwable $e) {
            $response = ExceptionHandler::render($e, $request);
        }

        $requestId = $request->attribute('request_id');
        if (is_string($requestId)) {
            $response->withRequestId($requestId);
        }
        return $response;
    }
}
