<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Helpers\Clock;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\QueueWorker;
use App\Services\Notifications\Templates;

/**
 * Avisos de la plataforma a los propietarios de una barbería (aprobada, rechazada, suspendida,
 * reactivada). Son avisos de servicio sobre la cuenta, no de citas: van a la bandeja de la app
 * y por correo a los propietarios con correo verificado, sin depender de las reglas de avisos
 * de la barbería ni de las preferencias de citas (tenant_id NULL en la cola).
 * Los textos salen de las plantillas globales editables (Templates).
 */
final class PlatformNotifier
{
    /**
     * @param array<string, mixed>  $tenant fila de tenants (id, slug, nombre_comercial)
     * @param array<string, string> $extra  variables adicionales (avisos de suscripción, Fase 10)
     * @return int avisos encolados
     */
    public static function tenantStatus(array $tenant, string $codigo, ?string $motivo, string $dedupe, array $extra = [], string $url = 'pages/panel/inicio.html'): int
    {
        $vars = ['barberia' => (string) $tenant['nombre_comercial'], 'motivo' => (string) ($motivo ?? '')] + $extra;
        $now = Clock::nowSql();
        $db = Database::connection();
        $stmt = $db->prepare(
            "INSERT INTO notificaciones (tenant_id, usuario_id, canal, tipo, destino, titulo, cuerpo, datos, estado, programada_para_utc, enviada_en, max_intentos, dedupe_key)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        );
        $datos = json_encode(['tenant_slug' => (string) $tenant['slug'], 'plantilla' => $codigo, 'url' => $url . '?tenant=' . rawurlencode((string) $tenant['slug'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $n = 0;
        foreach (self::owners((int) $tenant['id'], isset($tenant['propietario_usuario_id']) ? (int) $tenant['propietario_usuario_id'] : null) as $uid) {
            [$t, $c] = Templates::render($codigo, 'push', null, $vars);
            $stmt->execute([$uid, 'interna', $codigo, null, mb_substr($t, 0, 160), $c, $datos, 'enviada', $now, $now, QueueWorker::maxAttempts(), substr("{$dedupe}:{$uid}:interna", 0, 191)]);
            $n += $stmt->rowCount() === 1 ? 1 : 0;
            $email = NotificationCenter::verifiedEmail($uid);
            if ($email !== null) {
                [$t, $c] = Templates::render($codigo, 'email', null, $vars);
                $stmt->execute([$uid, 'email', $codigo, $email, mb_substr($t, 0, 160), $c, $datos, 'programada', $now, null, QueueWorker::maxAttempts(), substr("{$dedupe}:{$uid}:email", 0, 191)]);
                $n += $stmt->rowCount() === 1 ? 1 : 0;
            }
        }
        return $n;
    }

    /** @return list<int> propietario registrado y propietarios con membresía activa */
    private static function owners(int $tenantId, ?int $propietario): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT m.usuario_id FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.tenant_id = ? AND r.codigo = 'propietario' AND m.estado = 'activo'"
        );
        $stmt->execute([$tenantId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($propietario !== null && !in_array($propietario, $ids, true)) {
            $ids[] = $propietario;
        }
        sort($ids);
        return $ids;
    }
}
