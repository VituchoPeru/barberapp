<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Auth\Gate;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\AvailabilityView;
use App\Services\Booking\BookingService;
use App\Services\Booking\Idempotency;
use App\Services\Panel\AgendaService;

/**
 * Agenda del panel de la barbería (Fase 6).
 *
 *   GET  /api/v1/panel/agenda?fecha=|desde=&hasta=&vista=dia|semana&sucursal_id=&barbero_id=
 *   GET  /api/v1/panel/agenda/catalogo                  Servicios y barberos activos para el alta manual
 *   GET  /api/v1/panel/disponibilidad?sucursal_id=&servicio_ids=&barbero_id=&fecha=   Horarios libres para el personal
 *   POST /api/v1/panel/reservas                         Alta manual (Idempotency-Key obligatoria)
 *   GET  /api/v1/panel/reservas/{id}                    Detalle con historial y cobros
 *   POST /api/v1/panel/reservas/{id}/confirmar | iniciar | completar | no-asistio
 *   POST /api/v1/panel/reservas/{id}/cancelar           motivo obligatorio
 *   GET  /api/v1/panel/reservas/{id}/disponibilidad     Horarios para reprogramar
 *   POST /api/v1/panel/reservas/{id}/reprogramar        (Idempotency-Key obligatoria)
 *   PUT  /api/v1/panel/reservas/{id}/notas              Notas internas
 *   POST /api/v1/panel/reservas/{id}/pagos              Registrar cobro
 *   POST /api/v1/panel/reservas/{id}/pagos/{pago}/anular
 */
final class PanelAgendaController extends BaseController
{
    public function agenda(Request $request): Response
    {
        return $this->ok($this->svc($request)->agenda($request->allQuery()));
    }

    public function catalog(Request $request): Response
    {
        return $this->ok($this->svc($request)->repo()->bookingCatalog());
    }

    public function availability(Request $request): Response
    {
        $q = $request->allQuery();
        $p = \App\Helpers\Validator::validate($q, ['sucursal_id' => 'required|integer|min:1', 'barbero_id' => 'nullable|integer|min:1']);
        $av = new AvailabilityService((int) $this->tenant($request)['id']);
        $ctx = $av->context((int) $p['sucursal_id'], $q['servicio_ids'] ?? null, isset($p['barbero_id']) ? (int) $p['barbero_id'] : null, false);
        $ctx['policy'] = $ctx['policy']->forStaff();
        return $this->ok(AvailabilityView::build($av, $ctx, $q));
    }

    public function store(Request $request): Response
    {
        $svc = $this->svc($request);
        $user = $this->user($request);
        $body = $request->json();
        return Idempotency::run($request, (int) $user['id'], (int) $this->tenant($request)['id'], 'reservas', static function () use ($svc, $user, $body, $request): array {
            $data = $svc->create($user, $body, $request);
            return ['status' => 201, 'data' => $data, 'recurso_id' => $data['id']];
        });
    }

    public function show(Request $request): Response
    {
        return $this->ok($this->svc($request)->presented((int) $request->param('id'), true));
    }

    public function confirm(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'confirmar', $request));
    }

    public function start(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'iniciar', $request));
    }

    public function complete(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'completar', $request));
    }

    public function noShow(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'no_asistio', $request));
    }

    public function cancel(Request $request): Response
    {
        $svc = $this->svc($request);
        $id = (int) $request->param('id');
        $svc->visible($id);
        $body = $request->json();
        \App\Helpers\Validator::validate($body, ['motivo' => 'required|string|min:3|max:255']);
        (new BookingService((int) $this->tenant($request)['id']))->cancel($id, $this->user($request), $body, 'personal', $request);
        return $this->ok($svc->presented($id, true));
    }

    public function rescheduleAvailability(Request $request): Response
    {
        $svc = $this->svc($request);
        $id = (int) $request->param('id');
        $r = $svc->visible($id);
        $tenantId = (int) $this->tenant($request)['id'];
        $q = $request->allQuery();
        $p = \App\Helpers\Validator::validate($q, ['barbero_id' => 'nullable|integer|min:1']);
        $ids = array_map(static fn (array $s): int => (int) $s['servicio_id'], (new BookingService($tenantId))->repo()->services($id));
        $av = new AvailabilityService($tenantId);
        $ctx = $av->context((int) $r['sucursal_id'], $ids, isset($p['barbero_id']) ? (int) $p['barbero_id'] : null, false);
        $ctx['policy'] = $ctx['policy']->forStaff();
        return $this->ok(AvailabilityView::build($av, $ctx, $q, $id) + [
            'reserva' => [
                'id' => $id, 'barbero_id' => (int) $r['barbero_id'],
                'fecha' => Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria'])->format('Y-m-d'),
                'hora' => Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria'])->format('H:i'),
            ],
        ]);
    }

    public function reschedule(Request $request): Response
    {
        $svc = $this->svc($request);
        $id = (int) $request->param('id');
        $svc->visible($id);
        $tenantId = (int) $this->tenant($request)['id'];
        $user = $this->user($request);
        $body = $request->json();
        return Idempotency::run($request, (int) $user['id'], $tenantId, 'reservas', static function () use ($svc, $id, $tenantId, $user, $body, $request): array {
            (new BookingService($tenantId))->reschedule($id, $user, $body, 'personal', $request);
            return ['status' => 200, 'data' => $svc->presented($id, true), 'recurso_id' => $id];
        });
    }

    public function notes(Request $request): Response
    {
        return $this->ok($this->svc($request)->notes((int) $request->param('id'), $request->json(), $request));
    }

    public function pay(Request $request): Response
    {
        return $this->created($this->svc($request)->pay((int) $request->param('id'), $request->json(), $request));
    }

    public function voidPayment(Request $request): Response
    {
        return $this->ok($this->svc($request)->voidPayment((int) $request->param('id'), (int) $request->param('pago'), $request->json(), $request));
    }

    private function svc(Request $request): AgendaService
    {
        $membership = (array) $request->attribute('membership');
        return new AgendaService((int) $this->tenant($request)['id'], Gate::rolePermissions($membership['roles'] ?? []), (int) $this->user($request)['id']);
    }

    /** @return array<string, mixed> */
    private function user(Request $request): array
    {
        return (array) $request->attribute('auth_user');
    }
}
