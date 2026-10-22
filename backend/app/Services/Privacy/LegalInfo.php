<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Config;
use App\Services\Platform\PlatformSettings;

/**
 * Textos legales vigentes y datos del responsable del tratamiento.
 * Los datos de la empresa se editan en el panel de la plataforma (Configuración → Datos legales):
 * no se inventan; si faltan, las páginas legales lo dicen.
 */
final class LegalInfo
{
    public static function terminosVersion(): string
    {
        return (string) Config::get('privacy.terminos_version', '2026-10-22');
    }

    public static function privacidadVersion(): string
    {
        return (string) Config::get('privacy.privacidad_version', '2026-10-22');
    }

    /** @return array<string, mixed> datos públicos para las páginas legales */
    public static function public(): array
    {
        $s = PlatformSettings::all();
        $responsable = [
            'nombre_comercial' => (string) $s['plataforma.nombre'],
            'razon_social' => (string) $s['plataforma.legal_razon_social'],
            'ruc' => (string) $s['plataforma.legal_ruc'],
            'domicilio' => (string) $s['plataforma.legal_domicilio'],
            'email_privacidad' => (string) ($s['plataforma.privacidad_email'] ?: $s['plataforma.soporte_email']),
            'registro_banco_datos' => (string) $s['plataforma.legal_registro_bdp'],
        ];
        $faltan = [];
        foreach (['razon_social', 'ruc', 'domicilio', 'email_privacidad'] as $k) {
            if ($responsable[$k] === '') {
                $faltan[] = $k;
            }
        }
        return [
            'terminos_version' => self::terminosVersion(),
            'privacidad_version' => self::privacidadVersion(),
            'responsable' => $responsable,
            'configuracion_completa' => $faltan === [],
            'datos_faltantes' => $faltan,
            'plazos_dias_habiles' => (array) Config::get('privacy.plazos_dias_habiles', []),
            'retencion' => (array) Config::get('privacy.retencion', []),
            'notificaciones_retencion_dias' => (int) Config::get('notifications.retention_days', 180),
            'copias_conservadas' => (int) Config::get('backup.keep', 14),
        ];
    }

    /**
     * Textos que la persona aún debe aceptar (versión nueva publicada después de su aceptación).
     *
     * @param array<string, mixed> $user fila de usuarios con terminos_version / privacidad_version
     * @return list<string> 'terminos' | 'privacidad'
     */
    public static function pending(array $user): array
    {
        $out = [];
        if (($user['terminos_version'] ?? null) !== self::terminosVersion()) {
            $out[] = 'terminos';
        }
        if (($user['privacidad_version'] ?? null) !== self::privacidadVersion()) {
            $out[] = 'privacidad';
        }
        return $out;
    }
}
