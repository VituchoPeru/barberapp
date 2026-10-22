<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;

/**
 * Preferencias de avisos de cada persona (tabla preferencias_notificacion).
 *
 * Categorías:
 *   recordatorios → recordatorios antes de la cita (cliente)
 *   cambios       → reserva registrada/confirmada, cancelada o cambiada por la barbería (cliente)
 *   agenda        → citas nuevas, cambios y solicitudes en la agenda (personal de la barbería)
 * Canales que la persona controla: push y email. La bandeja de la app (interna) siempre se llena.
 * Sin fila = habilitado. Una fila con tenant_id concreto prevalece sobre la global (tenant_id NULL).
 */
final class Preferences
{
    public const CATEGORIAS = [
        'recordatorios' => 'Recordatorios de mis citas',
        'cambios' => 'Cambios en mis citas',
        'agenda' => 'Avisos de mi agenda (personal de barbería)',
    ];
    public const CANALES = ['push', 'email'];
    /** El personal solo recibe avisos de agenda por push y en la bandeja. */
    public const SIN_CORREO = ['agenda'];

    public static function allowed(int $usuarioId, ?int $tenantId, string $canal, string $categoria): bool
    {
        if ($canal === 'interna') {
            return true;
        }
        if (in_array($categoria, self::SIN_CORREO, true) && $canal === 'email') {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT habilitado FROM preferencias_notificacion
              WHERE usuario_id = ? AND canal = ? AND tipo = ? AND (tenant_id IS NULL OR tenant_id = ?)
              ORDER BY tenant_id IS NULL LIMIT 1'
        );
        $stmt->execute([$usuarioId, $canal, $categoria, $tenantId ?? 0]);
        $v = $stmt->fetchColumn();
        return $v === false ? true : (bool) $v;
    }

    /** @return array<string, array<string, bool>> categoria → canal → habilitado (globales del usuario) */
    public static function forUser(int $usuarioId): array
    {
        $out = [];
        foreach (array_keys(self::CATEGORIAS) as $cat) {
            foreach (self::CANALES as $canal) {
                if (!(in_array($cat, self::SIN_CORREO, true) && $canal === 'email')) {
                    $out[$cat][$canal] = true;
                }
            }
        }
        $stmt = Database::connection()->prepare(
            'SELECT canal, tipo, habilitado FROM preferencias_notificacion WHERE usuario_id = ? AND tenant_id IS NULL'
        );
        $stmt->execute([$usuarioId]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($out[$r['tipo']][$r['canal']])) {
                $out[$r['tipo']][$r['canal']] = (bool) $r['habilitado'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input categoria → {push: bool, email: bool}
     * @return array<string, array<string, bool>>
     */
    public static function update(int $usuarioId, array $input): array
    {
        $current = self::forUser($usuarioId);
        $errors = [];
        $changes = [];
        foreach ($input as $cat => $canales) {
            if (!isset($current[$cat])) {
                $errors[(string) $cat][] = 'Categoría de aviso desconocida.';
                continue;
            }
            if (!is_array($canales)) {
                $errors[(string) $cat][] = 'Indica los canales como {"push": true, "email": false}.';
                continue;
            }
            foreach ($canales as $canal => $v) {
                if (!isset($current[$cat][$canal])) {
                    $errors["{$cat}.{$canal}"][] = 'Canal no disponible para esta categoría.';
                    continue;
                }
                if (!is_bool($v)) {
                    $errors["{$cat}.{$canal}"][] = 'Debe ser verdadero o falso.';
                    continue;
                }
                $changes[] = [$cat, (string) $canal, $v];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO preferencias_notificacion (usuario_id, tenant_id, canal, tipo, habilitado) VALUES (?, NULL, ?, ?, ?)
             ON DUPLICATE KEY UPDATE habilitado = VALUES(habilitado)'
        );
        foreach ($changes as [$cat, $canal, $v]) {
            $stmt->execute([$usuarioId, $canal, $cat, $v ? 1 : 0]);
        }
        return self::forUser($usuarioId);
    }
}
