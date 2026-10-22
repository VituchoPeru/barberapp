<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Clock;
use App\Repositories\BookingRepository;

/**
 * Planificador de recordatorios (lo ejecuta el worker antes de procesar la cola).
 *
 * Los recordatorios se programan al reservar y al reprogramar. El planificador es la red de
 * seguridad: crea los que falten cuya hora de envío está entre "hace 30 min" y "dentro de
 * 15 min" (p. ej. si la barbería agregó un recordatorio de 12 h a sus reglas, o una cita
 * creada antes de la Fase 8). Nunca crea un recordatorio cuya hora de envío es anterior al
 * último cambio de la cita, para no enviar "tu cita es en 2 horas" a quien reservó hace 5 min.
 * La dedupe_key hace que repetirlo no duplique nada.
 */
final class ReminderPlanner
{
    public function plan(): int
    {
        $now = Clock::nowUtc()->getTimestamp();
        $grace = 60 * (int) Config::get('notifications.worker.grace_minutes', 30);
        $ahead = 60 * (int) Config::get('notifications.worker.lookahead_minutes', 15);
        $stmt = Database::connection()->prepare(
            "SELECT r.id, r.tenant_id, r.inicio_utc, r.updated_at, r.reprogramaciones
               FROM reservas r
               JOIN tenants t ON t.id = r.tenant_id AND t.estado = 'activo'
               JOIN clientes c ON c.id = r.cliente_id AND c.usuario_id IS NOT NULL
              WHERE r.estado IN ('pendiente', 'confirmada') AND r.inicio_utc > ? AND r.inicio_utc <= ?
              ORDER BY r.inicio_utc
              LIMIT 2000"
        );
        $stmt->execute([
            gmdate('Y-m-d H:i:s', $now + NotificationSettings::HORAS_MIN * 3600 - $grace),
            gmdate('Y-m-d H:i:s', $now + NotificationSettings::HORAS_MAX * 3600 + $ahead),
        ]);
        $created = 0;
        $centers = [];
        foreach ($stmt->fetchAll() as $row) {
            $tenantId = (int) $row['tenant_id'];
            $centers[$tenantId] ??= new NotificationCenter($tenantId);
            $inicio = strtotime($row['inicio_utc'] . ' UTC');
            $changed = strtotime($row['updated_at'] . ' UTC');
            $due = false;
            foreach ($centers[$tenantId]->settings()->hours() as $h) {
                $sendAt = $inicio - $h * 3600;
                if ($sendAt >= $now - $grace && $sendAt <= $now + $ahead && $sendAt >= $changed) {
                    $due = true;
                    break;
                }
            }
            if (!$due || $this->hasAll((int) $row['id'], (int) $row['reprogramaciones'], $centers[$tenantId]->settings()->hours())) {
                continue;
            }
            $booking = (new BookingRepository($tenantId))->find((int) $row['id']);
            if ($booking !== null) {
                $created += Database::transaction(static fn (): int => $centers[$tenantId]->scheduleReminders($booking, $grace));
            }
        }
        return $created;
    }

    /** @param list<int> $hours */
    private function hasAll(int $reservaId, int $version, array $hours): bool
    {
        if ($hours === []) {
            return true;
        }
        $keys = array_map(static fn (int $h): string => "rec:{$reservaId}:{$version}:{$h}h:interna", $hours);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM notificaciones WHERE dedupe_key IN ({$in})");
        $stmt->execute($keys);
        return (int) $stmt->fetchColumn() === count($keys);
    }
}
