<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use PDOException;
use Throwable;

/** Convierte cualquier excepción en una respuesta JSON consistente y la registra. */
final class ExceptionHandler
{
    public static function render(Throwable $e, ?Request $request = null): Response
    {
        if ($e instanceof HttpException) {
            if ($e->status() >= 500) {
                Logger::error($e->getMessage(), self::context($e, $request));
            }
            $response = Response::error($e->status(), $e->errorCode(), $e->getMessage(), $e->details());
            foreach ($e->headers() as $name => $value) {
                $response->setHeader($name, $value);
            }
            return $response;
        }

        Logger::error('Excepción no controlada', self::context($e, $request));

        $debug = (bool) Config::get('app.debug', false);
        $details = null;
        if ($debug) {
            $details = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ];
        }
        $code = $e instanceof PDOException ? 'database_error' : 'internal_error';
        return Response::error(500, $code, 'Ocurrió un error interno. Intente nuevamente.', $details);
    }

    /** @return array<string, mixed> */
    private static function context(Throwable $e, ?Request $request): array
    {
        return [
            'exception' => $e,
            'method' => $request ? $request->method() : null,
            'path' => $request ? $request->path() : null,
            'request_id' => $request ? $request->attribute('request_id') : null,
        ];
    }

    /** Registra manejadores globales para errores fatales/advertencias en modo HTTP. */
    public static function registerGlobalHandlers(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
