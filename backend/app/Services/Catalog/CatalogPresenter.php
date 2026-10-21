<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Helpers\Clock;
use App\Helpers\Media;

/** Formato JSON estable (tipos correctos) de sucursales, servicios, barberos y bloqueos del panel. */
final class CatalogPresenter
{
    /** @param array<string, mixed> $s */
    public static function branch(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'nombre' => $s['nombre'],
            'slug' => $s['slug'],
            'direccion' => $s['direccion'],
            'referencia' => $s['referencia'],
            'distrito' => $s['distrito'],
            'provincia' => $s['provincia'],
            'departamento' => $s['departamento'],
            'ubigeo' => $s['ubigeo'],
            'latitud' => $s['latitud'] === null ? null : (float) $s['latitud'],
            'longitud' => $s['longitud'] === null ? null : (float) $s['longitud'],
            'telefono' => $s['telefono'],
            'email' => $s['email'],
            'zona_horaria' => $s['zona_horaria'],
            'es_principal' => (bool) $s['es_principal'],
            'activo' => (bool) $s['activo'],
            'barberos_activos' => isset($s['barberos_activos']) ? (int) $s['barberos_activos'] : null,
            'horario' => $s['horario'] ?? [],
        ];
    }

    /** @param array<string, mixed> $c */
    public static function category(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'nombre' => $c['nombre'],
            'orden' => (int) $c['orden'],
            'activo' => (bool) $c['activo'],
            'servicios' => isset($c['servicios']) ? (int) $c['servicios'] : null,
        ];
    }

    /** @param array<string, mixed> $s */
    public static function service(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'nombre' => $s['nombre'],
            'descripcion' => $s['descripcion'],
            'duracion_minutos' => (int) $s['duracion_minutos'],
            'precio' => (string) $s['precio'],
            'moneda' => $s['moneda'],
            'activo' => (bool) $s['activo'],
            'orden' => (int) $s['orden'],
            'categoria_id' => $s['categoria_id'] === null ? null : (int) $s['categoria_id'],
            'categoria' => $s['categoria'],
            'tipo' => $s['tipo'],
            'tipo_nombre' => $s['tipo_nombre'],
            'barberos' => isset($s['barberos']) ? (int) $s['barberos'] : null,
        ];
    }

    /** @param array<string, mixed> $b */
    public static function barber(array $b): array
    {
        return [
            'id' => (int) $b['id'],
            'nombre_publico' => $b['nombre_publico'],
            'especialidad' => $b['especialidad'],
            'biografia' => $b['biografia'],
            'foto_url' => Media::url($b['foto_url']),
            'activo' => (bool) $b['activo'],
            'acepta_reservas_online' => (bool) $b['acepta_reservas_online'],
            'orden' => (int) $b['orden'],
            'sucursal_id' => (int) $b['sucursal_id'],
            'sucursal' => $b['sucursal'],
            'usuario_id' => $b['usuario_id'] === null ? null : (int) $b['usuario_id'],
            'usuario_email' => $b['usuario_email'],
            'servicios' => isset($b['servicios']) && !is_array($b['servicios']) ? (int) $b['servicios'] : null,
            'horas_semana' => isset($b['minutos_semana']) ? round((int) $b['minutos_semana'] / 60, 1) : null,
        ];
    }

    /** @param array<string, mixed> $s */
    public static function barberService(array $s): array
    {
        return [
            'servicio_id' => (int) $s['servicio_id'],
            'nombre' => $s['nombre'],
            'activo' => (bool) $s['activo'],
            'duracion_estandar' => (int) $s['duracion_estandar'],
            'precio_estandar' => (string) $s['precio_estandar'],
            'duracion_minutos_override' => $s['duracion_minutos_override'] === null ? null : (int) $s['duracion_minutos_override'],
            'precio_override' => $s['precio_override'] === null ? null : (string) $s['precio_override'],
            'duracion_minutos' => (int) $s['duracion_minutos'],
            'precio' => (string) $s['precio'],
        ];
    }

    /** @param array<string, mixed> $t */
    public static function shift(array $t): array
    {
        return [
            'dia_semana' => (int) $t['dia_semana'],
            'hora_inicio' => $t['hora_inicio'],
            'hora_fin' => $t['hora_fin'],
            'sucursal_id' => (int) $t['sucursal_id'],
            'sucursal' => $t['sucursal'] ?? null,
            'vigente_desde' => $t['vigente_desde'] ?? null,
            'vigente_hasta' => $t['vigente_hasta'] ?? null,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function rest(array $d): array
    {
        return ['dia_semana' => (int) $d['dia_semana'], 'hora_inicio' => $d['hora_inicio'], 'hora_fin' => $d['hora_fin'], 'motivo' => $d['motivo']];
    }

    /** @param array<string, mixed> $b */
    public static function block(array $b, string $timezone): array
    {
        $ini = Clock::utcToLocal((string) $b['inicio_utc'], $timezone);
        $fin = Clock::utcToLocal((string) $b['fin_utc'], $timezone);
        $diaCompleto = $ini->format('H:i') === '00:00' && $fin->format('H:i') === '00:00';
        return [
            'id' => (int) $b['id'],
            'tipo' => $b['tipo'],
            'estado' => $b['estado'],
            'alcance' => $b['barbero_id'] !== null ? 'barbero' : ($b['sucursal_id'] !== null ? 'sucursal' : 'barberia'),
            'barbero_id' => $b['barbero_id'] === null ? null : (int) $b['barbero_id'],
            'barbero' => $b['barbero'] ?? null,
            'sucursal_id' => $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'],
            'sucursal' => $b['sucursal'] ?? null,
            'inicio_utc' => $ini->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'fin_utc' => $fin->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'inicio_local' => $ini->format('Y-m-d\TH:i'),
            'fin_local' => $fin->format('Y-m-d\TH:i'),
            'zona_horaria' => $timezone,
            'dia_completo' => $diaCompleto,
            // Para días completos, el último día incluido (fin es exclusivo: medianoche del día siguiente).
            'fecha_inicio' => $ini->format('Y-m-d'),
            'fecha_fin' => $diaCompleto ? $fin->modify('-1 day')->format('Y-m-d') : $fin->format('Y-m-d'),
            'motivo' => $b['motivo'],
            'creado_por' => $b['creado_por_nombre'] ?? null,
            'aprobado_por' => $b['aprobado_por_nombre'] ?? null,
        ];
    }
}
