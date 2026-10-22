<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Helpers\Clock;
use App\Repositories\UserRepository;
use PDO;
use Throwable;

/**
 * Política de retención (php bin/console privacidad:retencion, cron diario).
 * Los plazos están en config/privacy.php; 0 desactiva cada regla. Borra por lotes para no
 * bloquear tablas grandes. Las demás limpiezas ya existentes siguen en sus comandos:
 * auth:purge (sesiones, tokens), reservas:purge (idempotencia), notificaciones:purge (avisos).
 */
final class Retention
{
    private const BATCH = 5000;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<string, int> filas afectadas por regla */
    public function run(): array
    {
        $cfg = (array) Config::get('privacy.retencion', []);
        $out = [];

        $dias = (int) ($cfg['auditoria_dias'] ?? 0);
        $out['auditoria'] = $dias > 0 ? $this->purge('DELETE FROM auditoria WHERE created_at < ? ORDER BY id LIMIT ' . self::BATCH, $this->cutoff($dias)) : 0;

        $dias = (int) ($cfg['webhooks_dias'] ?? 0);
        $out['webhooks'] = $dias > 0
            ? $this->purge("DELETE FROM webhook_eventos WHERE estado IN ('procesado','ignorado','fallido') AND recibido_en < ? ORDER BY id LIMIT " . self::BATCH, $this->cutoff($dias))
            : 0;

        $dias = (int) ($cfg['solicitudes_dias'] ?? 0);
        $out['solicitudes'] = $dias > 0
            ? $this->purge("DELETE FROM solicitudes_datos WHERE estado IN ('atendida','rechazada') AND atendida_en < ? ORDER BY id LIMIT " . self::BATCH, $this->cutoff($dias))
            : 0;

        $dias = (int) ($cfg['cuentas_sin_verificar_dias'] ?? 0);
        $out['cuentas_sin_verificar'] = $dias > 0 ? $this->unverifiedAccounts($this->cutoff($dias)) : 0;

        return $out;
    }

    private function cutoff(int $days): string
    {
        return Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    private function purge(string $sql, string $cutoff): int
    {
        $total = 0;
        $stmt = $this->db->prepare($sql);
        do {
            $stmt->execute([$cutoff]);
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === self::BATCH);
        return $total;
    }

    /**
     * Cuentas que nunca confirmaron su correo y nunca usaron la plataforma: sin citas, sin
     * equipo y sin rol de plataforma. Se eliminan igual que si la persona lo pidiera (sin correo).
     */
    private function unverifiedAccounts(string $cutoff): int
    {
        $stmt = $this->db->prepare(
            "SELECT u.id FROM usuarios u
              WHERE u.email_verificado_en IS NULL AND u.deleted_at IS NULL AND u.estado = 'activo'
                AND u.rol_plataforma_id IS NULL AND u.created_at < ?
                AND NOT EXISTS (SELECT 1 FROM membresias m WHERE m.usuario_id = u.id AND m.estado <> 'revocado')
                AND NOT EXISTS (SELECT 1 FROM clientes c JOIN reservas r ON r.cliente_id = c.id WHERE c.usuario_id = u.id)
              ORDER BY u.id LIMIT 500"
        );
        $stmt->execute([$cutoff]);
        $users = new UserRepository($this->db);
        $deletion = new AccountDeletion($this->db);
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $user = $users->findById((int) $id);
            if ($user === null) {
                continue;
            }
            try {
                $deletion->delete($user, null, 'retencion');
                $n++;
            } catch (Throwable $e) {
                Logger::warning('Retención: no se pudo eliminar una cuenta sin verificar', ['usuario_id' => (int) $id, 'exception' => $e]);
            }
        }
        return $n;
    }
}
