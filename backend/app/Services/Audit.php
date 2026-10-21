<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Ip;
use Throwable;

/**
 * Bitácora de auditoría (tabla `auditoria`, solo INSERT).
 * Un fallo al auditar nunca interrumpe la petición: se registra en el log de errores.
 */
final class Audit
{
    /**
     * @param array<string, mixed>|null $nuevos
     * @param array<string, mixed>|null $anteriores
     */
    public static function record(
        string $accion,
        string $resultado = 'exito',
        ?Request $request = null,
        ?int $usuarioId = null,
        ?int $tenantId = null,
        ?string $entidad = null,
        ?int $entidadId = null,
        ?array $nuevos = null,
        ?array $anteriores = null
    ): void {
        try {
            if ($usuarioId === null && $request !== null) {
                $user = $request->attribute('auth_user');
                $usuarioId = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
            }
            if ($tenantId === null && $request !== null) {
                $tenant = $request->attribute('tenant');
                $tenantId = is_array($tenant) && isset($tenant['id']) ? (int) $tenant['id'] : null;
            }
            $stmt = Database::connection()->prepare(
                'INSERT INTO auditoria (tenant_id, usuario_id, accion, entidad, entidad_id, resultado, datos_anteriores, datos_nuevos, ip, user_agent, request_id, created_at)
                 VALUES (:tenant, :usuario, :accion, :entidad, :entidad_id, :resultado, :anteriores, :nuevos, :ip, :ua, :rid, :creado)'
            );
            $stmt->execute([
                'tenant' => $tenantId,
                'usuario' => $usuarioId,
                'accion' => substr($accion, 0, 100),
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'resultado' => $resultado,
                'anteriores' => $anteriores === null ? null : json_encode($anteriores, JSON_UNESCAPED_UNICODE),
                'nuevos' => $nuevos === null ? null : json_encode($nuevos, JSON_UNESCAPED_UNICODE),
                'ip' => $request ? Ip::toBinary($request->ip()) : null,
                'ua' => $request ? $request->userAgent() : null,
                'rid' => $request ? $request->attribute('request_id') : null,
                'creado' => Clock::nowSql(),
            ]);
        } catch (Throwable $e) {
            Logger::error('No se pudo registrar auditoría', ['accion' => $accion, 'exception' => $e]);
        }
    }

    /**
     * Atajo para intentos no autorizados (401/403/404 por aislamiento, límites de intentos).
     *
     * @param array<string, mixed> $datos
     */
    public static function denied(string $accion, ?Request $request = null, array $datos = [], ?int $usuarioId = null, ?int $tenantId = null): void
    {
        if ($request !== null) {
            $datos += ['ruta' => $request->method() . ' ' . $request->path()];
        }
        self::record($accion, 'denegado', $request, $usuarioId, $tenantId, null, null, $datos);
    }
}
