<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Core\Database;
use App\Helpers\Clock;

/**
 * Avisos internos de las solicitudes de ausencia (Fase 7). Mismo esquema que BookingNotifier:
 * notificaciones del canal "interna" con dedupe_key única (un reintento no duplica el aviso).
 * El envío por push o correo llega con la Fase 8, que leerá estas mismas filas.
 *  - Barbero pide una ausencia  → aviso a quienes pueden aprobarla (bloqueos.gestionar).
 *  - Se aprueba o rechaza       → aviso al barbero que la pidió.
 */
final class BlockNotifier
{
    public function __construct(private int $tenantId)
    {
    }

    /** @param list<int> $usuarios */
    public function requested(int $bloqueoId, array $usuarios, string $barbero, string $cuando): void
    {
        foreach ($usuarios as $u) {
            $this->insert($u, 'bloqueo_solicitado', 'Solicitud de ausencia', "{$barbero} pidió bloquear su agenda: {$cuando}. Revísala en Ausencias.",
                $bloqueoId, "bloqueo:{$bloqueoId}:solicitado:{$u}");
        }
    }

    public function answered(int $bloqueoId, int $usuarioBarbero, bool $aprobado, string $cuando, ?string $respuesta): void
    {
        $titulo = $aprobado ? 'Ausencia aprobada' : 'Ausencia rechazada';
        $cuerpo = ($aprobado ? "Se aprobó tu solicitud: {$cuando}." : "No se aprobó tu solicitud: {$cuando}.")
            . ($respuesta !== null && $respuesta !== '' ? " Comentario: {$respuesta}" : '');
        $this->insert($usuarioBarbero, $aprobado ? 'bloqueo_aprobado' : 'bloqueo_rechazado', $titulo, $cuerpo, $bloqueoId, "bloqueo:{$bloqueoId}:respuesta:{$usuarioBarbero}");
    }

    private function insert(int $usuarioId, string $tipo, string $titulo, string $cuerpo, int $bloqueoId, string $dedupe): void
    {
        $now = Clock::nowSql();
        Database::connection()->prepare(
            "INSERT INTO notificaciones (tenant_id, usuario_id, canal, tipo, titulo, cuerpo, datos, estado, programada_para_utc, enviada_en, dedupe_key)
             VALUES (?, ?, 'interna', ?, ?, ?, ?, 'enviada', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([
            $this->tenantId, $usuarioId, $tipo, $titulo, mb_substr($cuerpo, 0, 1000),
            json_encode(['bloqueo_id' => $bloqueoId], JSON_UNESCAPED_UNICODE), $now, $now, substr($dedupe, 0, 191),
        ]);
    }
}
