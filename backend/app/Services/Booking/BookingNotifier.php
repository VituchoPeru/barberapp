<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Database;
use App\Helpers\Clock;
use App\Services\Notifications\NotificationCenter;

/**
 * Avisos de una cita (Fases 5 y 8). Se escribe dentro de la transacción de la reserva: si la
 * reserva falla, no queda aviso.
 *  - Bandeja de la app (canal "interna"): cliente y barbero, cuando la cita se crea, se
 *    confirma, se reprograma o se cancela.
 *  - Push y correo (Fase 8, NotificationCenter): al cliente cuando la reserva queda
 *    registrada/confirmada o cuando la barbería la cancela o la cambia; al barbero, push con
 *    el mismo texto de su bandeja.
 *  - Recordatorios (Fase 8): se programan al crear y al reprogramar; al cancelar, reprogramar,
 *    atender o marcar inasistencia se anulan los pendientes.
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
            "UPDATE notificaciones SET estado = 'cancelada', ultimo_error = 'La cita cambió: el aviso ya no aplica', proximo_intento_utc = NULL
              WHERE tenant_id = ? AND reserva_id = ? AND estado = 'programada'"
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

        $center = new NotificationCenter($this->tenantId);
        // Quien hizo el cambio no necesita aviso de su propia acción, salvo el comprobante del cliente.
        if ($r['cliente_usuario_id'] !== null && isset($paraCliente[$evento]) && ($actor !== 'cliente' || $evento === 'creada')) {
            $dedupe = "reserva:{$r['id']}:{$evento}:{$secuencia}:cliente";
            $this->insert((int) $r['cliente_usuario_id'], (int) $r['id'], "reserva_{$evento}", $paraCliente[$evento], $dedupe, 'pages/cita.html?id=' . (int) $r['id']);
            $codigo = [
                'creada' => $r['estado'] === 'confirmada' ? 'reserva_confirmada' : 'reserva_recibida',
                'confirmada' => 'reserva_confirmada',
                'reprogramada' => 'reserva_modificada',
                'cancelada' => 'reserva_cancelada',
            ][$evento];
            $motivo = $evento === 'cancelada' && !empty($r['motivo_cancelacion']) ? 'Motivo: ' . $r['motivo_cancelacion'] : '';
            $center->clientEvent($r, $codigo, $dedupe, ['motivo' => $motivo]);
        }
        if (in_array($evento, ['creada', 'reprogramada'], true)) {
            $center->scheduleReminders($r);
        }
        if ($r['barbero_usuario_id'] !== null && isset($paraBarbero[$evento])) {
            $dedupe = "reserva:{$r['id']}:{$evento}:{$secuencia}:barbero";
            $url = 'pages/barbero/index.html?cita=' . (int) $r['id'];
            $this->insert((int) $r['barbero_usuario_id'], (int) $r['id'], "reserva_{$evento}", $paraBarbero[$evento], $dedupe, $url);
            $center->staffPush((int) $r['barbero_usuario_id'], "reserva_{$evento}", $paraBarbero[$evento][0], $paraBarbero[$evento][1],
                ['reserva_id' => (int) $r['id'], 'url' => $url], $dedupe);
        }
    }

    /** @param array{0:string, 1:string} $texto */
    private function insert(int $usuarioId, int $reservaId, string $tipo, array $texto, string $dedupe, string $url): void
    {
        $now = Clock::nowSql();
        Database::connection()->prepare(
            "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, titulo, cuerpo, datos, estado, programada_para_utc, enviada_en, dedupe_key)
             VALUES (?, ?, ?, 'interna', ?, ?, ?, ?, 'enviada', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([
            $this->tenantId, $usuarioId, $reservaId, $tipo, $texto[0], $texto[1],
            json_encode(['reserva_id' => $reservaId, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now, substr($dedupe, 0, 191),
        ]);
    }
}
