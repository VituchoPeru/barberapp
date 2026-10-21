<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Middleware\JsonBodyMiddleware;
use App\Services\Catalog\BarberService;
use App\Services\Catalog\CatalogPresenter;

/**
 * Barberos (Fase 4).
 *   GET    /api/v1/panel/barberos                 Lista + cuentas vinculables  (barberos, horarios o bloqueos)
 *   POST   /api/v1/panel/barberos                 Registrar                    (barberos.gestionar)
 *   GET    /api/v1/panel/barberos/{id}            Ficha con servicios, jornada y descansos
 *   PUT    /api/v1/panel/barberos/{id}            Editar, activar/desactivar   (barberos.gestionar)
 *   DELETE /api/v1/panel/barberos/{id}            Eliminar (sin reservas futuras)
 *   PUT    /api/v1/panel/barberos/{id}/servicios  Servicios que realiza        (barberos.gestionar)
 *   PUT    /api/v1/panel/barberos/{id}/horario    Jornada semanal y descansos  (horarios.gestionar)
 *   GET    /api/v1/panel/barberos/{id}/jornada    Jornada efectiva por fechas  (horarios.gestionar)
 *   POST   /api/v1/panel/barberos/{id}/foto       Foto (cuerpo binario image/*) (barberos.gestionar)
 *   DELETE /api/v1/panel/barberos/{id}/foto
 */
final class BarbersController extends BaseController
{
    public function index(Request $request): Response
    {
        $svc = $this->service($request);
        return $this->ok(array_map([CatalogPresenter::class, 'barber'], $svc->repo()->all()), [
            'cuentas_vinculables' => array_map(static fn (array $u): array => [
                'usuario_id' => (int) $u['usuario_id'],
                'nombre' => trim($u['nombres'] . ' ' . $u['apellidos']),
                'email' => $u['email'],
                'estado' => $u['estado'],
            ], $svc->repo()->linkableAccounts()),
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->ok($this->service($request)->detail((int) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return $this->created($this->service($request)->create($request->json(), $request));
    }

    public function update(Request $request): Response
    {
        return $this->ok($this->service($request)->update((int) $request->param('id'), $request->json(), $request));
    }

    public function destroy(Request $request): Response
    {
        $this->service($request)->delete((int) $request->param('id'), $request);
        return $this->noContent();
    }

    public function services(Request $request): Response
    {
        return $this->ok($this->service($request)->replaceServices((int) $request->param('id'), $request->json(), $request));
    }

    public function schedule(Request $request): Response
    {
        $r = $this->service($request)->replaceSchedule((int) $request->param('id'), $request->json(), $request);
        return $this->ok($r['barbero'], ['advertencias' => $r['advertencias']]);
    }

    public function workingTime(Request $request): Response
    {
        $t = $this->tenant($request);
        $hoy = Clock::utcToLocal(Clock::nowSql(), (string) $t['zona_horaria'])->format('Y-m-d');
        $q = Validator::validate($request->allQuery(), ['desde' => 'nullable|date', 'hasta' => 'nullable|date']);
        $desde = $q['desde'] ?? $hoy;
        $hasta = $q['hasta'] ?? (new \DateTimeImmutable($desde))->modify('+6 day')->format('Y-m-d');
        return $this->ok($this->service($request)->effectiveSchedule((int) $request->param('id'), $desde, $hasta));
    }

    public function uploadPhoto(Request $request): Response
    {
        $type = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));
        if (!in_array($type, JsonBodyMiddleware::IMAGE_TYPES, true)) {
            throw new HttpException(415, 'unsupported_media_type', 'Envía la imagen como JPG, PNG o WebP.');
        }
        return $this->ok($this->service($request)->uploadPhoto((int) $request->param('id'), $request->rawBody(), $request));
    }

    public function deletePhoto(Request $request): Response
    {
        return $this->ok($this->service($request)->deletePhoto((int) $request->param('id'), $request));
    }

    private function service(Request $request): BarberService
    {
        $t = $this->tenant($request);
        return new BarberService($t['id'], (string) $t['zona_horaria']);
    }
}
