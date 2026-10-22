<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Repositories\BookingRepository;
use App\Services\Audit;
use App\Services\Auth\RateLimiter;
use App\Services\Booking\BookingService;
use App\Services\Notifications\AttendanceToken;
use App\Services\Notifications\BookingMessages;

/**
 * Confirmar asistencia desde el recordatorio, sin iniciar sesión (Fase 8).
 *
 * POST /api/v1/asistencia/consultar  { token }  Resumen mínimo de la cita del enlace
 * POST /api/v1/asistencia/confirmar  { token }  Registra "asistirá" (idempotente)
 *
 * El token firmado identifica UNA cita y su horario vigente. No muestra teléfono, correo ni
 * importes. Un token inválido cuenta para el límite de intentos por IP. No confirmar nunca
 * cancela la cita: la barbería decide qué hacer con quien no confirmó.
 */
final class AttendanceController extends BaseController
{
    public function show(Request $request): Response
    {
        [$r, $services] = $this->resolve($request);
        return $this->ok($this->present($r, $services));
    }

    public function confirm(Request $request): Response
    {
        [$r] = $this->resolve($request);
        if (!$this->canConfirm($r)) {
            throw new HttpException(409, 'booking_not_active', 'Esta cita ya no está activa o ya pasó: no se puede confirmar la asistencia.');
        }
        $tenantId = (int) $r['tenant_id'];
        $user = ['id' => (int) $r['cliente_usuario_id']];
        (new BookingService($tenantId))->confirmAttendance((int) $r['id'], $user, $request);
        Audit::record('reserva.asistencia_confirmada_enlace', 'exito', $request, $user['id'], $tenantId, 'reservas', (int) $r['id']);
        $repo = new BookingRepository($tenantId);
        return $this->ok($this->present((array) $repo->find((int) $r['id']), $repo->services((int) $r['id'])));
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function resolve(Request $request): array
    {
        $limiter = new RateLimiter();
        $limiter->ensureNotLimited('asistencia_ip', $request->ip());
        $token = $request->json()['token'] ?? null;
        $data = is_string($token) ? AttendanceToken::parse($token) : null;
        $r = null;
        if ($data !== null) {
            $repo = new BookingRepository($data['tenant_id']);
            $r = $repo->find($data['reserva_id']);
        }
        if ($data === null || $r === null || $r['cliente_usuario_id'] === null) {
            $limiter->hit('asistencia_ip', $request->ip());
            throw new HttpException(404, 'invalid_link', 'El enlace no es válido. Abre tu cita desde la app para confirmar tu asistencia.');
        }
        if (strtotime($r['inicio_utc'] . ' UTC') !== $data['inicio_ts']) {
            throw new HttpException(410, 'booking_changed', 'Tu cita cambió de horario después de este aviso. Revisa los datos actualizados en la app.');
        }
        return [$r, (new BookingRepository($data['tenant_id']))->services((int) $r['id'])];
    }

    /** @param array<string, mixed> $r */
    private function canConfirm(array $r): bool
    {
        return in_array($r['estado'], ['pendiente', 'confirmada'], true) && strtotime($r['inicio_utc'] . ' UTC') > Clock::nowUtc()->getTimestamp();
    }

    /**
     * @param array<string, mixed>        $r
     * @param list<array<string, mixed>> $services
     * @return array<string, mixed>
     */
    private function present(array $r, array $services): array
    {
        $v = BookingMessages::vars($r, $services);
        return [
            'reserva_id' => (int) $r['id'],
            'codigo' => $r['codigo'],
            'estado' => $r['estado'],
            'barberia' => $v['barberia'],
            'sucursal' => $v['sucursal'],
            'direccion' => $v['direccion'],
            'barbero' => $v['barbero'],
            'servicios' => $v['servicios'],
            'fecha' => $v['fecha'],
            'hora' => $v['hora'],
            'cuando' => $v['cuando'],
            'asistencia_confirmada' => $r['asistencia_confirmada_en'] !== null,
            'puede_confirmar' => $this->canConfirm($r) && $r['asistencia_confirmada_en'] === null,
        ];
    }
}
