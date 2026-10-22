<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Billing\Entitlements;
use App\Services\Notifications\NotificationMetrics;
use App\Services\Notifications\NotificationSettings;

/**
 * Avisos y recordatorios de la barbería en el panel (Fase 8).
 *
 * GET /api/v1/panel/notificaciones/reglas     Reglas vigentes (horas de recordatorio, avisos y canales)
 * PUT /api/v1/panel/notificaciones/reglas     Cambia las reglas (notificaciones.gestionar)
 * GET /api/v1/panel/notificaciones/metricas   ?desde&hasta: programados, enviados, fallos, confirmaciones, inasistencia
 */
final class PanelNotificationsController extends BaseController
{
    public function rules(Request $request): Response
    {
        return $this->ok($this->present(new NotificationSettings((int) $this->tenant($request)['id'])));
    }

    public function updateRules(Request $request): Response
    {
        $tenantId = (int) $this->tenant($request)['id'];
        $userId = (int) ((array) $request->attribute('auth_user'))['id'];
        $settings = new NotificationSettings($tenantId);
        [$before, $after] = $settings->update($request->json(), $userId);
        if ($after !== []) {
            Audit::record('notificaciones.reglas_actualizadas', 'exito', $request, $userId, $tenantId, 'configuraciones', null, $after, $before);
        }
        return $this->ok($this->present($settings));
    }

    public function metrics(Request $request): Response
    {
        $tenant = $this->tenant($request);
        return $this->ok((new NotificationMetrics((int) $tenant['id']))->report($request->allQuery(), (string) $tenant['zona_horaria']));
    }

    /** @return array<string, mixed> */
    private function present(NotificationSettings $settings): array
    {
        return $settings->all() + [
            'limites' => [
                'max_recordatorios' => NotificationSettings::MAX_RECORDATORIOS,
                'horas_min' => NotificationSettings::HORAS_MIN,
                'horas_max' => NotificationSettings::HORAS_MAX,
            ],
            // Canales que incluye el plan (Fase 10): un canal fuera del plan no se envía aunque esté encendido.
            'canales_plan' => array_values(array_filter(['push', 'email'], static fn (string $c): bool => Entitlements::for($settings->tenantId())->channel($c))),
        ];
    }
}
