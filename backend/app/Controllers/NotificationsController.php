<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\DeviceRepository;
use App\Repositories\InboxRepository;
use App\Repositories\MembershipRepository;
use App\Services\Audit;
use App\Services\Booking\BookingPresenter;
use App\Services\Notifications\Preferences;

/**
 * Avisos de la cuenta autenticada (Fase 8).
 *
 * GET    /api/v1/me/notificaciones                 Bandeja (?solo_no_leidas=1&page=)
 * GET    /api/v1/me/notificaciones/resumen         { no_leidas } (insignia de la campana)
 * POST   /api/v1/me/notificaciones/{id}/leer
 * POST   /api/v1/me/notificaciones/leer-todas
 * GET    /api/v1/me/preferencias-notificacion      Qué avisos recibe por push y correo
 * PUT    /api/v1/me/preferencias-notificacion
 * GET    /api/v1/me/dispositivos                   Teléfonos con avisos activados
 * POST   /api/v1/me/dispositivos                   Registra el token FCM de la app (Capacitor)
 * DELETE /api/v1/me/dispositivos                   Desactiva un token (al cerrar sesión en la app)
 * DELETE /api/v1/me/dispositivos/{id}              Desactiva un dispositivo desde Mi cuenta
 */
final class NotificationsController extends BaseController
{
    public function index(Request $request): Response
    {
        $p = $this->pagination($request, 20, 50);
        $now = Clock::nowUtc()->getTimestamp();
        $userId = $this->userId($request);
        $res = (new InboxRepository())->list($userId, (string) $request->query('solo_no_leidas', '') === '1', $p['per_page'], $p['offset']);
        $items = array_map(static function (array $n) use ($now, $userId): array {
            $datos = json_decode((string) ($n['datos'] ?? 'null'), true);
            $datos = is_array($datos) ? $datos : [];
            // "Confirmar asistencia" solo mientras tiene sentido: cita activa, futura, sin confirmar y de esta persona.
            $confirmar = ($datos['accion'] ?? null) === 'confirmar_asistencia'
                && in_array($n['reserva_estado'], ['pendiente', 'confirmada'], true)
                && $n['asistencia_confirmada_en'] === null
                && $n['reserva_inicio'] !== null && strtotime($n['reserva_inicio'] . ' UTC') > $now
                && (int) $n['reserva_cliente_usuario'] === $userId;
            return [
                'id' => (int) $n['id'],
                'tipo' => $n['tipo'],
                'titulo' => $n['titulo'],
                'cuerpo' => $n['cuerpo'],
                'fecha' => BookingPresenter::iso((string) $n['enviada_en']),
                'leida' => $n['leida_en'] !== null,
                'barberia' => $n['tenant_slug'] !== null ? ['slug' => $n['tenant_slug'], 'nombre' => $n['tenant_nombre']] : null,
                'reserva_id' => $n['reserva_id'] !== null && (int) $n['reserva_cliente_usuario'] === $userId ? (int) $n['reserva_id'] : null,
                'url' => isset($datos['url']) && is_string($datos['url']) && preg_match('#^pages/[a-z0-9/_-]+\.html(\?[a-z0-9=&_-]*)?$#i', $datos['url']) ? $datos['url'] : null,
                'accion' => $confirmar ? 'confirmar_asistencia' : null,
            ];
        }, $res['items']);
        return $this->ok($items, ['page' => $p['page'], 'per_page' => $p['per_page'], 'total' => $res['total'], 'no_leidas' => $res['no_leidas']]);
    }

    public function summary(Request $request): Response
    {
        return $this->ok(['no_leidas' => (new InboxRepository())->unread($this->userId($request))]);
    }

    public function read(Request $request): Response
    {
        if (!(new InboxRepository())->markRead($this->userId($request), (int) $request->param('id'), Clock::nowSql())) {
            throw new NotFoundException('Aviso no encontrado.');
        }
        return $this->summary($request);
    }

    public function readAll(Request $request): Response
    {
        (new InboxRepository())->markAllRead($this->userId($request), Clock::nowSql());
        return $this->summary($request);
    }

    // ------------------------------------------------------------------ preferencias

    public function preferences(Request $request): Response
    {
        return $this->ok($this->presentPreferences($this->userId($request)));
    }

    public function updatePreferences(Request $request): Response
    {
        $userId = $this->userId($request);
        $before = Preferences::forUser($userId);
        $after = Preferences::update($userId, $request->json());
        if ($after !== $before) {
            Audit::record('notificaciones.preferencias_actualizadas', 'exito', $request, $userId, null, 'usuarios', $userId, $after, $before);
        }
        return $this->ok($this->presentPreferences($userId));
    }

    /** @return array<string, mixed> */
    private function presentPreferences(int $userId): array
    {
        $prefs = Preferences::forUser($userId);
        $staff = (new MembershipRepository())->forUser($userId, ['activo']) !== [];
        $out = [];
        foreach (Preferences::CATEGORIAS as $k => $nombre) {
            if ($k === 'agenda' && !$staff) {
                continue;
            }
            $out[] = ['categoria' => $k, 'nombre' => $nombre] + $prefs[$k];
        }
        return ['categorias' => $out, 'bandeja' => 'Los avisos siempre quedan en tu bandeja de la app, aunque desactives push o correo.'];
    }

    // ------------------------------------------------------------------ dispositivos

    public function devices(Request $request): Response
    {
        return $this->ok((new DeviceRepository())->forUser($this->userId($request)));
    }

    public function registerDevice(Request $request): Response
    {
        $data = $this->validate($request, [
            'token' => 'required|string|min:20|max:1024',
            'plataforma' => 'required|in:android,ios,web',
            'app_version' => 'nullable|string|max:20',
            'modelo' => 'nullable|string|max:80',
        ]);
        if (preg_match('/[\s\x00-\x1F]/', (string) $data['token'])) {
            throw new ValidationException(['token' => ['El token del dispositivo no es válido.']]);
        }
        $userId = $this->userId($request);
        $repo = new DeviceRepository();
        $id = $repo->register($userId, (string) $data['token'], (string) $data['plataforma'], $data['app_version'] ?? null, $data['modelo'] ?? null, Clock::nowSql());
        Audit::record('dispositivo.registrado', 'exito', $request, $userId, null, 'dispositivos_push', $id, ['plataforma' => $data['plataforma']]);
        return $this->created(['id' => $id, 'dispositivos' => $repo->forUser($userId)]);
    }

    public function unregisterDevice(Request $request): Response
    {
        $data = $this->validate($request, ['token' => 'required|string|max:1024']);
        (new DeviceRepository())->unregister($this->userId($request), (string) $data['token'], Clock::nowSql());
        return $this->noContent(); // idempotente: no revela si el token existía
    }

    public function deactivateDevice(Request $request): Response
    {
        $userId = $this->userId($request);
        if (!(new DeviceRepository())->deactivate($userId, (int) $request->param('id'), Clock::nowSql())) {
            throw new NotFoundException('Dispositivo no encontrado.');
        }
        return $this->ok((new DeviceRepository())->forUser($userId));
    }

    private function userId(Request $request): int
    {
        return (int) ((array) $request->attribute('auth_user'))['id'];
    }
}
