<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\ClientRepository;
use App\Repositories\MarketplaceRepository;
use App\Repositories\MyBookingsRepository;
use App\Services\Audit;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\AvailabilityView;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\BookingService;
use App\Services\Booking\Idempotency;

/**
 * Motor de reservas para el cliente (Fase 5).
 *
 *   GET  /api/v1/disponibilidad                           Horarios libres (público)
 *   POST /api/v1/reservas                                 Reservar (Idempotency-Key obligatoria)
 *   GET  /api/v1/mis-reservas?tipo=proximas|pasadas       Mis citas en todas las barberías
 *   GET  /api/v1/mis-reservas/{id}                        Detalle con historial
 *   GET  /api/v1/mis-reservas/{id}/disponibilidad         Horarios para reprogramar (sin contar la propia cita)
 *   POST /api/v1/mis-reservas/{id}/cancelar
 *   POST /api/v1/mis-reservas/{id}/reprogramar            (Idempotency-Key obligatoria)
 *   POST /api/v1/mis-reservas/{id}/confirmar-asistencia
 *
 * La barbería se identifica por su slug público y se verifica en el backend; las reservas de
 * "Mis citas" se buscan siempre por la cuenta autenticada (nunca por un id de cliente enviado).
 */
final class BookingsController extends BaseController
{
    public function availability(Request $request): Response
    {
        $q = $request->allQuery();
        $p = Validator::validate($q, [
            'slug' => 'required|slug|max:80',
            'sucursal_id' => 'required|integer|min:1',
            'barbero_id' => 'nullable|integer|min:1',
        ]);
        $tenant = $this->tenantBySlug((string) $p['slug']);
        $svc = new AvailabilityService((int) $tenant['id']);
        $ctx = $svc->context((int) $p['sucursal_id'], $q['servicio_ids'] ?? null, isset($p['barbero_id']) ? (int) $p['barbero_id'] : null);
        return $this->ok(AvailabilityView::build($svc, $ctx, $q));
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $body = $request->json();
        $slug = is_string($body['slug'] ?? null) ? strtolower(trim($body['slug'])) : '';
        if ($slug === '') {
            throw new ValidationException(['slug' => ['Indica la barbería.']]);
        }
        $tenant = $this->tenantBySlug($slug);
        $svc = new BookingService((int) $tenant['id']);
        return Idempotency::run($request, (int) $user['id'], (int) $tenant['id'], 'reservas', static function () use ($svc, $user, $body, $request): array {
            $data = $svc->create($user, $body, $request);
            return ['status' => 201, 'data' => $data, 'recurso_id' => $data['id']];
        });
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $q = Validator::validate($request->allQuery(), ['tipo' => 'nullable|in:proximas,pasadas']);
        $tipo = $q['tipo'] ?? 'proximas';
        $pg = $this->pagination($request, 20, 50);
        // Con correo verificado, enlaza la ficha de mostrador con el mismo correo (ver citas previas).
        if (!empty($user['email_verificado_en'])) {
            if ((new ClientRepository())->ensureForUser($user)['vinculado_mostrador']) {
                Audit::record('cliente.vinculado_mostrador', 'exito', $request, (int) $user['id']);
            }
        }
        $repo = new MyBookingsRepository();
        $page = $repo->list((int) $user['id'], $tipo, Clock::nowSql(), $pg['per_page'], $pg['offset']);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $page['items']);
        $services = $repo->servicesFor($ids);
        $policies = $repo->policies(array_map(static fn (array $r): array => [(int) $r['tenant_id'], (int) $r['sucursal_id']], $page['items']));
        $items = array_map(static fn (array $r): array => BookingPresenter::present(
            $r,
            $services[(int) $r['id']] ?? [],
            BookingPolicy::fromRow($policies[$r['tenant_id'] . ':' . $r['sucursal_id']] ?? null)
        ), $page['items']);
        return $this->ok($items, ['tipo' => $tipo, 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'total' => $page['total']]);
    }

    public function show(Request $request): Response
    {
        [$svc, $id] = $this->own($request);
        return $this->ok($svc->presented($id, true));
    }

    public function rescheduleAvailability(Request $request): Response
    {
        [$svc, $id] = $this->own($request);
        $r = (array) $svc->repo()->find($id);
        if ($r['tenant_estado'] !== 'activo' || $r['tenant_restringido_en'] !== null) {
            throw new HttpException(409, 'tenant_unavailable', 'La barbería no está recibiendo reservas en este momento.');
        }
        $ids = array_map(static fn (array $s): int => (int) $s['servicio_id'], $svc->repo()->services($id));
        $av = new AvailabilityService((int) $r['tenant_id']);
        $policy = $av->policy((int) $r['sucursal_id']);
        $pool = (bool) $r['barbero_asignado_auto'] && $policy->permiteCualquierBarbero ? null : (int) $r['barbero_id'];
        $ctx = $av->context((int) $r['sucursal_id'], $ids, $pool);
        return $this->ok(AvailabilityView::build($av, $ctx, $request->allQuery(), $id) + [
            'reserva' => ['id' => $id, 'fecha' => Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria'])->format('Y-m-d'),
                'hora' => Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria'])->format('H:i'), 'barbero_id' => (int) $r['barbero_id']],
        ]);
    }

    public function cancel(Request $request): Response
    {
        [$svc, $id] = $this->own($request);
        return $this->ok($svc->cancel($id, $this->user($request), $request->json(), 'cliente', $request));
    }

    public function reschedule(Request $request): Response
    {
        [$svc, $id, $tenantId] = $this->own($request);
        $user = $this->user($request);
        $body = $request->json();
        return Idempotency::run($request, (int) $user['id'], $tenantId, 'reservas', static function () use ($svc, $id, $user, $body, $request): array {
            return ['status' => 200, 'data' => $svc->reschedule($id, $user, $body, 'cliente', $request), 'recurso_id' => $id];
        });
    }

    public function confirmAttendance(Request $request): Response
    {
        [$svc, $id] = $this->own($request);
        return $this->ok($svc->confirmAttendance($id, $this->user($request), $request));
    }

    /**
     * Reserva de la cuenta autenticada. Si existe pero es de otra persona responde 404 (no revela
     * que existe) y registra el intento en auditoría.
     *
     * @return array{0: BookingService, 1: int, 2: int}
     */
    private function own(Request $request): array
    {
        $id = (int) $request->param('id');
        $user = $this->user($request);
        $loc = (new MyBookingsRepository())->locate($id);
        if ($loc === null || $loc['usuario_id'] !== (int) $user['id']) {
            if ($loc !== null) {
                Audit::denied('reserva.acceso_ajeno', $request, ['reserva_id' => $id], (int) $user['id'], $loc['tenant_id']);
            }
            throw new NotFoundException('Reserva no encontrada.');
        }
        return [new BookingService($loc['tenant_id']), $id, $loc['tenant_id']];
    }

    /** @return array<string, mixed> */
    private function user(Request $request): array
    {
        return (array) $request->attribute('auth_user');
    }

    /** @return array<string, mixed> */
    private function tenantBySlug(string $slug): array
    {
        $tenant = (new MarketplaceRepository())->findActiveBySlug(strtolower($slug));
        if ($tenant === null) {
            throw new NotFoundException('Esta barbería no existe o no está recibiendo reservas en este momento.');
        }
        return $tenant;
    }
}
