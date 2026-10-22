<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Repositories\TenantScopedRepository;
use App\Services\Billing\Entitlements;

/**
 * Reglas de avisos de una barbería (Fase 8), guardadas en `configuraciones` con el prefijo
 * `notificaciones.` y tenant_id. Sin fila propia se usa el parámetro global de la plataforma
 * (tenant_id NULL) y, si tampoco existe, el valor por defecto de AJUSTES.
 */
final class NotificationSettings extends TenantScopedRepository
{
    public const MAX_RECORDATORIOS = 3;
    public const HORAS_MIN = 1;
    public const HORAS_MAX = 72;

    /** clave → [valor por defecto, descripción] */
    public const AJUSTES = [
        'recordatorios_horas' => [[24, 2], 'Horas antes de la cita en que se envían los recordatorios'],
        'pedir_confirmacion' => [true, 'Los recordatorios piden al cliente confirmar su asistencia'],
        'aviso_confirmacion' => [true, 'Avisar al cliente cuando su reserva queda registrada o confirmada'],
        'aviso_cancelacion' => [true, 'Avisar al cliente cuando la barbería cancela su cita'],
        'aviso_modificacion' => [true, 'Avisar al cliente cuando la barbería cambia la fecha, hora o barbero de su cita'],
        'canal_push' => [true, 'Enviar avisos push a la app'],
        'canal_email' => [true, 'Enviar avisos por correo electrónico'],
    ];

    /** @var array<int, array<string, mixed>> caché por barbería durante la petición o la ejecución del worker */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (isset(self::$cache[$this->tenantId])) {
            return self::$cache[$this->tenantId];
        }
        $out = array_map(static fn (array $a) => $a[0], self::AJUSTES);
        // Global primero (tenant_id NULL), luego el de la barbería, que lo reemplaza.
        $rows = $this->fetchAll(
            "SELECT clave, valor FROM configuraciones
              WHERE (tenant_id IS NULL OR tenant_id = :t) AND clave LIKE 'notificaciones.%'
              ORDER BY tenant_id IS NOT NULL",
            ['t' => $this->tenantId]
        );
        foreach ($rows as $r) {
            $k = substr((string) $r['clave'], 15);
            if (!array_key_exists($k, $out)) {
                continue;
            }
            $v = json_decode((string) $r['valor'], true);
            $out[$k] = $k === 'recordatorios_horas' ? self::normalizeHours($v) : (bool) $v;
        }
        return self::$cache[$this->tenantId] = $out;
    }

    /** @return list<int> horas de recordatorio vigentes (de mayor a menor) */
    public function hours(): array
    {
        return $this->all()['recordatorios_horas'];
    }

    public function channelEnabled(string $canal): bool
    {
        if ($canal === 'interna') {
            return true; // la bandeja de la app siempre recibe el aviso
        }
        $all = $this->all();
        // El canal debe estar incluido en el plan de la barbería (Fase 10).
        return (bool) ($all['canal_' . $canal] ?? false) && Entitlements::for($this->tenantId)->channel($canal);
    }

    /**
     * Valida y guarda los cambios. Devuelve [antes, después] de las claves cambiadas.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function update(array $input, int $usuarioId): array
    {
        $before = $this->all();
        $changes = self::clean($input, $before);
        foreach ($changes as $k => $v) {
            $this->execute(
                'INSERT INTO configuraciones (tenant_id, clave, valor, descripcion, es_publica, actualizado_por)
                 VALUES (:t, :c, :v, :d, 0, :u)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)',
                ['t' => $this->tenantId, 'c' => 'notificaciones.' . $k, 'v' => json_encode($v), 'd' => self::AJUSTES[$k][1], 'u' => $usuarioId]
            );
        }
        unset(self::$cache[$this->tenantId]);
        return [array_intersect_key($before, $changes), $changes];
    }

    /**
     * Valida un cambio de reglas contra los valores actuales y devuelve solo las claves que cambian.
     * Lo usan el panel de la barbería y los valores globales del superadministrador (Fase 9).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $before
     * @return array<string, mixed>
     */
    public static function clean(array $input, array $before): array
    {
        $changes = [];
        $errors = [];
        foreach (self::AJUSTES as $k => $_) {
            if (!array_key_exists($k, $input) || $input[$k] === null) {
                continue;
            }
            $v = $input[$k];
            if ($k === 'recordatorios_horas') {
                if (!is_array($v)) {
                    $errors[$k][] = 'Debe ser una lista de horas.';
                    continue;
                }
                $clean = [];
                foreach ($v as $h) {
                    if (!is_int($h) && !(is_string($h) && ctype_digit($h))) {
                        $errors[$k][] = 'Cada recordatorio debe ser un número entero de horas.';
                        continue 2;
                    }
                    $h = (int) $h;
                    if ($h < self::HORAS_MIN || $h > self::HORAS_MAX) {
                        $errors[$k][] = 'Cada recordatorio debe estar entre ' . self::HORAS_MIN . ' y ' . self::HORAS_MAX . ' horas antes de la cita.';
                        continue 2;
                    }
                    $clean[$h] = $h;
                }
                if (count($clean) > self::MAX_RECORDATORIOS) {
                    $errors[$k][] = 'Puedes programar hasta ' . self::MAX_RECORDATORIOS . ' recordatorios por cita.';
                    continue;
                }
                $v = self::normalizeHours(array_values($clean));
            } else {
                if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) {
                    $errors[$k][] = 'Debe ser verdadero o falso.';
                    continue;
                }
                $v = (bool) $v;
            }
            if ($v !== ($before[$k] ?? null)) {
                $changes[$k] = $v;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $changes;
    }

    /**
     * Valores globales de la plataforma (tenant_id NULL), que usa toda barbería sin regla propia.
     *
     * @return array<string, mixed>
     */
    public static function globalDefaults(): array
    {
        $out = array_map(static fn (array $a) => $a[0], self::AJUSTES);
        $rows = Database::connection()
            ->query("SELECT clave, valor FROM configuraciones WHERE tenant_id IS NULL AND clave LIKE 'notificaciones.%'")
            ->fetchAll();
        foreach ($rows as $r) {
            $k = substr((string) $r['clave'], 15);
            if (array_key_exists($k, $out)) {
                $v = json_decode((string) $r['valor'], true);
                $out[$k] = $k === 'recordatorios_horas' ? self::normalizeHours($v) : (bool) $v;
            }
        }
        return $out;
    }

    /** Limpia la caché (pruebas y worker de larga duración). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @param mixed $v
     * @return list<int>
     */
    private static function normalizeHours($v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $h = array_values(array_unique(array_filter(array_map('intval', $v), static fn (int $x): bool => $x >= self::HORAS_MIN && $x <= self::HORAS_MAX)));
        rsort($h);
        return array_slice($h, 0, self::MAX_RECORDATORIOS);
    }
}
