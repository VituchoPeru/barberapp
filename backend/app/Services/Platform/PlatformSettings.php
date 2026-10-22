<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\Notifications\NotificationSettings;
use DateTimeZone;

/**
 * Parámetros generales de la plataforma (tabla configuraciones con tenant_id NULL).
 *
 * Solo se editan las claves declaradas aquí (lista blanca con su tipo). Los avisos usan los
 * valores globales de NotificationSettings como punto de partida de cada barbería: una
 * barbería que guardó su propia regla no cambia cuando el superadministrador cambia la global.
 */
final class PlatformSettings
{
    /** clave => [tipo, valor por defecto, descripción, pública] */
    public const CLAVES = [
        'plataforma.nombre' => ['texto', 'BARBERAPP', 'Nombre comercial de la plataforma', true],
        'plataforma.soporte_email' => ['email', '', 'Correo de soporte que se muestra a las barberías (vacío = sin configurar)', true],
        'plataforma.zona_horaria' => ['zona', 'America/Lima', 'Zona horaria por defecto para nuevas barberías y para los reportes globales', true],
        'plataforma.moneda' => ['moneda', 'PEN', 'Moneda por defecto de planes y pagos', true],
        'plataforma.aprobacion_manual_tenants' => ['bool', true, 'Las nuevas barberías requieren aprobación del superadministrador', false],
    ];

    public const MONEDAS = ['PEN', 'USD'];

    /** @return array<string, mixed> plataforma.* con valores vigentes */
    public static function all(): array
    {
        $out = array_map(static fn (array $c) => $c[1], self::CLAVES);
        $stmt = Database::connection()->query("SELECT clave, valor FROM configuraciones WHERE tenant_id IS NULL AND clave LIKE 'plataforma.%'");
        foreach ($stmt->fetchAll() as $r) {
            if (array_key_exists((string) $r['clave'], $out)) {
                $v = json_decode((string) $r['valor'], true);
                $out[(string) $r['clave']] = self::CLAVES[(string) $r['clave']][0] === 'bool' ? (bool) $v : (string) ($v ?? '');
            }
        }
        return $out;
    }

    public static function timezone(): string
    {
        $tz = (string) self::all()['plataforma.zona_horaria'];
        return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'America/Lima';
    }

    /**
     * Vista completa para el panel: parámetros y valores globales de avisos.
     *
     * @return array<string, mixed>
     */
    public static function view(): array
    {
        $values = self::all();
        $params = [];
        foreach (self::CLAVES as $k => [$tipo, , $desc, $publica]) {
            $params[] = ['clave' => $k, 'tipo' => $tipo, 'valor' => $values[$k], 'descripcion' => $desc, 'publica' => $publica];
        }
        $avisos = NotificationSettings::globalDefaults();
        $reglas = [];
        foreach (NotificationSettings::AJUSTES as $k => [, $desc]) {
            $reglas[] = ['clave' => $k, 'valor' => $avisos[$k], 'descripcion' => $desc];
        }
        $override = (int) Database::connection()->query("SELECT COUNT(DISTINCT tenant_id) FROM configuraciones WHERE tenant_id IS NOT NULL AND clave LIKE 'notificaciones.%'")->fetchColumn();
        return [
            'parametros' => $params,
            'avisos' => $reglas,
            'avisos_limites' => ['max_recordatorios' => NotificationSettings::MAX_RECORDATORIOS, 'horas_min' => NotificationSettings::HORAS_MIN, 'horas_max' => NotificationSettings::HORAS_MAX],
            'barberias_con_reglas_propias' => $override,
            'monedas' => self::MONEDAS,
        ];
    }

    /**
     * Valida y guarda. Acepta {parametros: {clave: valor}, avisos: {clave: valor}}.
     * Devuelve [antes, después] de lo que cambió (para la auditoría).
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function update(array $input, int $usuarioId): array
    {
        $params = is_array($input['parametros'] ?? null) ? $input['parametros'] : [];
        $avisosIn = is_array($input['avisos'] ?? null) ? $input['avisos'] : [];
        $before = self::all();
        $changes = [];
        $errors = [];

        foreach ($params as $k => $v) {
            if (!isset(self::CLAVES[$k])) {
                $errors[$k][] = 'Parámetro desconocido.';
                continue;
            }
            $tipo = self::CLAVES[$k][0];
            $clean = self::cleanValue($tipo, $v);
            if (is_array($clean)) {
                $errors[$k][] = $clean['error'];
                continue;
            }
            if ($clean !== $before[$k]) {
                $changes[$k] = $clean;
            }
        }

        $avisosBefore = NotificationSettings::globalDefaults();
        try {
            $avisos = NotificationSettings::clean($avisosIn, $avisosBefore);
        } catch (ValidationException $e) {
            foreach ((array) (($e->details() ?? [])['fields'] ?? []) as $k => $msgs) {
                $errors['avisos.' . $k] = $msgs;
            }
            $avisos = [];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO configuraciones (tenant_id, clave, valor, descripcion, es_publica, actualizado_por)
             VALUES (NULL, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
        );
        foreach ($changes as $k => $v) {
            $stmt->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), self::CLAVES[$k][2], self::CLAVES[$k][3] ? 1 : 0, $usuarioId]);
        }
        foreach ($avisos as $k => $v) {
            $stmt->execute(['notificaciones.' . $k, json_encode($v), NotificationSettings::AJUSTES[$k][1], 0, $usuarioId]);
        }
        NotificationSettings::flush();

        $antes = array_intersect_key($before, $changes);
        foreach ($avisos as $k => $_) {
            $antes['notificaciones.' . $k] = $avisosBefore[$k];
        }
        $despues = $changes;
        foreach ($avisos as $k => $v) {
            $despues['notificaciones.' . $k] = $v;
        }
        return [$antes, $despues];
    }

    /**
     * @param mixed $v
     * @return mixed valor limpio, o ['error' => mensaje]
     */
    private static function cleanValue(string $tipo, $v)
    {
        switch ($tipo) {
            case 'bool':
                return is_bool($v) || in_array($v, [0, 1, '0', '1'], true) ? (bool) $v : ['error' => 'Debe ser verdadero o falso.'];
            case 'texto':
                $v = is_string($v) ? trim($v) : '';
                return $v !== '' && mb_strlen($v) <= 60 ? $v : ['error' => 'Escribe un nombre de 1 a 60 caracteres.'];
            case 'email':
                $v = is_string($v) ? trim($v) : '';
                return $v === '' || (strlen($v) <= 190 && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : ['error' => 'Correo electrónico inválido.'];
            case 'zona':
                return is_string($v) && in_array($v, DateTimeZone::listIdentifiers(), true) ? $v : ['error' => 'Zona horaria desconocida (p. ej. America/Lima).'];
            case 'moneda':
                return is_string($v) && in_array($v, self::MONEDAS, true) ? $v : ['error' => 'Moneda no admitida.'];
        }
        return ['error' => 'Tipo desconocido.'];
    }
}
