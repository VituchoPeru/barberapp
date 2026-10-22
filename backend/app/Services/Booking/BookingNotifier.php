<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Database;
use App\Helpers\Clock;

/**
 * Avisos de la Fase 5 en la bandeja interna (canal "interna", tabla notificaciones).
 * Los recordatorios programados y el envío por push o correo llegan con la Fase 8; aquí:
 *  - se avisa al cliente y al barbero cuando una cita se crea, se reprograma o se cancela;
 *  - al cancelar o reprogramar se anulan los recordatorios pendientes de esa cita
 *    (la Fase 8 programará los nuevos para la hora vigente).
 * Se escribe dentro de la transacción de la reserva: si la reserva falla, no queda aviso.
 */
final class BookingNotifier
{
    public function __construct(private int $tenantId)
    {
    }

    /** Anula los avisos programados de la cita (recordatorios). Devuelve cuántos se anularon. */
    public function cancelPending(int $reservaId): int
    {
        $stmt = Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'cancelada', ultimo_error = 'Cita cancelada o reprogramada'
              WHERE tenant_id = ? AND reserva_id = ? AND estado IN ('programada', 'fallida')"
        );
        $stmt->execute([$this->tenantId, $reservaId]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $r fila de BookingRepository::SELECT_DETAIL
     * @param string $evento creada | reprogramada | cancelada | asistencia_confirmada | confirmada
     */
    public function notify(array $r, string $evento, string $actor, int $secuencia = 0): void
    {
        $cuando = BookingPresenter::whenText($r);
        $codigo = (string) $r['codigo'];
        $cliente = trim($r['cliente_nombres'] . ' ' . ($r['cliente_apellidos'] ?? ''));
        $tenant = (string) $r['tenant_nombre'];

        $paraCliente = [
            'creada' => [$r['estado'] === 'confirmada' ? 'Reserva confirmada' : 'Reserva recibida',
                $r['estado'] === 'confirmada'
                    ? "Tu cita en {$tenant} es el {$cuando}. Código {$codigo}."
                    : "{$tenant} recibió tu solicitud para el {$cuando} y la confirmará pronto. Código {$codigo}."],
            'reprogramada' => ['Cita reprogramada', "Tu cita en {$tenant} ahora es el {$cuando}. Código {$codigo}."],
            'cancelada' => ['Cita cancelada', "Se canceló tu cita del {$cuando} en {$tenant}. Código {$codigo}."],
            'confirmada' => ['Reserva confirmada', "{$tenant} confirmó tu cita del {$cuando}. Código {$codigo}."],
        ];
        $paraBarbero = [
            'creada' => ['Nueva cita', "{$cliente} reservó para el {$cuando}. Código {$codigo}."],
            'reprogramada' => ['Cita reprogramada', "La cita de {$cliente} ahora es el {$cuando}. Código {$codigo}."],
            'cancelada' => ['Cita cancelada', "Se canceló la cita de {$cliente} del {$cuando}. Código {$codigo}."],
            'asistencia_confirmada' => ['Asistencia confirmada', "{$cliente} confirmó que asistirá el {$cuando}. Código {$codigo}."],
        ];

        // Quien hizo el cambio no necesita aviso de su propia acción, salvo el comprobante del cliente.
        if ($r['cliente_usuario_id'] !== null && isset($paraCliente[$evento]) && ($actor !== 'cliente' || $evento === 'creada')) {
            $this->insert((int) $r['cliente_usuario_id'], (int) $r['id'], "reserva_{$evento}", $paraCliente[$evento], "reserva:{$r['id']}:{$evento}:{$secuencia}:cliente");
        }
        if ($r['barbero_usuario_id'] !== null && isset($paraBarbero[$evento])) {
            $this->insert((int) $r['barbero_usuario_id'], (int) $r['id'], "reserva_{$evento}", $paraBarbero[$evento], "reserva:{$r['id']}:{$evento}:{$secuencia}:barbero");
        }
    }

    /** @param array{0:string, 1:string} $texto */
    private function insert(int $usuarioId, int $reservaId, string $tipo, array $texto, string $dedupe): void
    {
        $now = Clock::nowSql();
        Database::connection()->prepare(
            "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, titulo, cuerpo, datos, estado, programada_para_utc, enviada_en, dedupe_key)
             VALUES (?, ?, ?, 'interna', ?, ?, ?, ?, 'enviada', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([
            $this->tenantId, $usuarioId, $reservaId, $tipo, $texto[0], $texto[1],
            json_encode(['reserva_id' => $reservaId], JSON_UNESCAPED_UNICODE), $now, $now, substr($dedupe, 0, 191),
        ]);
    }
}
