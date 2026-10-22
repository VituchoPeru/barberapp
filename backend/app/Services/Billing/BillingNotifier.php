<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Database;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\PlatformSettings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Avisos de suscripción a los propietarios (Fase 10): bandeja de la app y correo, con las
 * plantillas globales editables. Cada aviso lleva una clave de deduplicación: el cron puede
 * correr muchas veces sin repetirlo.
 */
final class BillingNotifier
{
    public static function paymentConfirmed(int $tenantId, int $subId, string $monto, string $moneda, int $pagoId): int
    {
        $sub = self::sub($subId);
        $tenant = self::tenant($tenantId);
        if ($sub === null || $tenant === null) {
            return 0;
        }
        return PlatformNotifier::tenantStatus($tenant, 'pago_confirmado', null, "pago:{$pagoId}", [
            'plan' => (string) $sub['plan_nombre'], 'fecha' => self::date((string) $sub['periodo_fin_en']), 'monto' => ($moneda === 'PEN' ? 'S/ ' : "{$moneda} ") . $monto,
        ], 'pages/panel/suscripcion.html');
    }

    /** Aviso «por vencer» (o «tu prueba termina») para el umbral de días alcanzado. */
    public static function expiring(int $tenantId, array $sub, int $umbral, int $dias): int
    {
        $tenant = self::tenant($tenantId);
        if ($tenant === null) {
            return 0;
        }
        $codigo = $sub['estado'] === 'prueba' ? 'prueba_por_vencer' : 'suscripcion_por_vencer';
        return PlatformNotifier::tenantStatus($tenant, $codigo, null, "sus:{$sub['id']}:{$sub['periodo_fin_en']}:{$umbral}d", [
            'plan' => (string) $sub['plan_nombre'], 'fecha' => self::date((string) $sub['periodo_fin_en']), 'dias' => (string) max(0, $dias),
        ], 'pages/panel/suscripcion.html');
    }

    public static function expired(int $tenantId, array $sub, string $restringeEn): int
    {
        $tenant = self::tenant($tenantId);
        if ($tenant === null) {
            return 0;
        }
        return PlatformNotifier::tenantStatus($tenant, 'suscripcion_vencida', null, "sus:{$sub['id']}:vencida", [
            'plan' => (string) $sub['plan_nombre'], 'fecha' => self::date($restringeEn),
        ], 'pages/panel/suscripcion.html');
    }

    public static function restricted(int $tenantId, string $desde): int
    {
        $tenant = self::tenant($tenantId);
        if ($tenant === null) {
            return 0;
        }
        return PlatformNotifier::tenantStatus($tenant, 'suscripcion_restringida', null, "restringida:{$tenantId}:{$desde}", [], 'pages/panel/suscripcion.html');
    }

    /** @return array<string, mixed>|null */
    private static function tenant(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT id, slug, nombre_comercial, propietario_usuario_id FROM tenants WHERE id = ? AND deleted_at IS NULL');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function sub(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT s.*, p.nombre plan_nombre FROM suscripciones s JOIN planes p ON p.id = s.plan_id WHERE s.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    private static function date(string $utc): string
    {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $local = $d->setTimezone(new DateTimeZone(PlatformSettings::timezone()));
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $local->format('j') . ' de ' . $meses[(int) $local->format('n')] . ' de ' . $local->format('Y');
    }
}
