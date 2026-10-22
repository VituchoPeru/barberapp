<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\AgendaRepository;
use App\Repositories\PanelClientRepository;
use App\Services\Audit;
use App\Services\Auth\Gate;
use App\Services\Booking\BookingPresenter;
use App\Services\Panel\ClientInput;
use App\Services\Panel\PanelBookingPresenter;

/**
 * Clientes de la barbería (Fase 6). Solo personas vinculadas a esta barbería.
 *
 *   GET  /api/v1/panel/clientes?q=&orden=recientes|nombre|reservas&page=   (clientes.ver)
 *   POST /api/v1/panel/clientes                     Cliente de mostrador  (reservas.crear)
 *   GET  /api/v1/panel/clientes/{id}                Ficha + resumen        (clientes.ver)
 *   GET  /api/v1/panel/clientes/{id}/reservas?tipo=proximas|pasadas       (clientes.ver)
 *   PUT  /api/v1/panel/clientes/{id}                Observaciones, bloqueo y datos de mostrador (clientes.notas)
 */
final class PanelClientsController extends BaseController
{
    public function index(Request $request): Response
    {
        $q = Validator::validate($request->allQuery(), ['q' => 'nullable|string|max:100', 'orden' => 'nullable|in:recientes,nombre,reservas']);
        $pg = $this->pagination($request, 20, 50);
        $page = $this->repo($request)->search((string) ($q['q'] ?? ''), Clock::nowSql(), (string) ($q['orden'] ?? 'recientes'), $pg['per_page'], $pg['offset']);
        return $this->ok(array_map(fn (array $c): array => $this->present($c), $page['items']), [
            'page' => $pg['page'], 'per_page' => $pg['per_page'], 'total' => $page['total'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = ClientInput::validate($request->json());
        $repo = $this->repo($request);
        $t = $this->tenant($request);
        [$id, $existia] = Database::transaction(static function () use ($repo, $data, $request, $t): array {
            $id = $repo->findByContact($data['telefono'], $data['email']);
            if ($id !== null) {
                return [$id, true];
            }
            $id = $repo->createCounterClient($data);
            Audit::record('cliente.registrado_mostrador', 'exito', $request, null, (int) $t['id'], 'clientes', $id);
            return [$id, false];
        });
        $c = $this->present((array) $repo->find($id, Clock::nowSql())) + ['ya_existia' => $existia];
        return $existia ? $this->ok($c) : $this->created($c);
    }

    public function show(Request $request): Response
    {
        $repo = $this->repo($request);
        $id = (int) $request->param('id');
        $c = $repo->find($id, Clock::nowSql());
        if ($c === null) {
            throw new NotFoundException('Cliente no encontrado.');
        }
        $perms = $this->perms($request);
        $out = $this->present($c) + ['resumen' => $repo->stats($id)];
        $out['resumen']['total_cobrado'] = in_array('reportes.financieros', $perms, true) || in_array('reservas.cobrar', $perms, true) ? $repo->paidTotal($id) : null;
        return $this->ok($out);
    }

    public function bookings(Request $request): Response
    {
        $id = (int) $request->param('id');
        if ($this->repo($request)->find($id, Clock::nowSql()) === null) {
            throw new NotFoundException('Cliente no encontrado.');
        }
        $q = Validator::validate($request->allQuery(), ['tipo' => 'nullable|in:proximas,pasadas']);
        $tipo = (string) ($q['tipo'] ?? 'proximas');
        $pg = $this->pagination($request, 10, 50);
        $agenda = new AgendaRepository((int) $this->tenant($request)['id']);
        $page = $agenda->forClient($id, $tipo, Clock::nowSql(), $pg['per_page'], $pg['offset']);
        $services = $agenda->servicesFor(array_map(static fn (array $r): int => (int) $r['id'], $page['items']));
        $perms = $this->perms($request);
        return $this->ok(
            array_map(static fn (array $r): array => PanelBookingPresenter::present($r, $services[(int) $r['id']] ?? [], $perms), $page['items']),
            ['tipo' => $tipo, 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'total' => $page['total']]
        );
    }

    public function update(Request $request): Response
    {
        $repo = $this->repo($request);
        $id = (int) $request->param('id');
        $before = $repo->find($id, Clock::nowSql());
        if ($before === null) {
            throw new NotFoundException('Cliente no encontrado.');
        }
        $input = $request->json();
        $data = Validator::validate($input, ['notas_internas' => 'nullable|string|max:2000', 'bloqueado' => 'nullable|boolean']);
        $link = [];
        if (array_key_exists('notas_internas', $data)) {
            $link['notas_internas'] = $data['notas_internas'] !== null && trim((string) $data['notas_internas']) !== '' ? trim((string) $data['notas_internas']) : null;
        }
        if (isset($data['bloqueado'])) {
            $link['bloqueado'] = (bool) $data['bloqueado'];
        }
        $contact = array_intersect_key($input, array_flip(['nombres', 'apellidos', 'telefono', 'email']));
        if ($contact !== [] && $before['usuario_id'] !== null) {
            throw new ValidationException(['nombres' => ['Este cliente tiene cuenta propia: sus datos de contacto solo los cambia él desde la app.']]);
        }
        $contactData = [];
        if ($contact !== []) {
            $clean = ClientInput::validate($contact + ['nombres' => $before['nombres']], true);
            foreach (array_keys($contact) as $k) {
                $contactData[$k] = $clean[$k];
            }
            if (isset($contactData['nombres']) && mb_strlen((string) $contactData['nombres']) < 2) {
                throw new ValidationException(['nombres' => ['Indica el nombre del cliente.']]);
            }
        }
        Database::transaction(static function () use ($repo, $id, $link, $contactData): void {
            $repo->updateLink($id, $link);
            $repo->updateCounterClient($id, $contactData);
        });
        $t = $this->tenant($request);
        Audit::record('cliente.actualizado', 'exito', $request, null, (int) $t['id'], 'clientes', $id,
            ['campos' => array_keys($link + $contactData), 'bloqueado' => $link['bloqueado'] ?? null],
            ['bloqueado' => (bool) $before['bloqueado']]);
        return $this->ok($this->present((array) $repo->find($id, Clock::nowSql())));
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function present(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'nombre' => trim($c['nombres'] . ' ' . ($c['apellidos'] ?? '')),
            'nombres' => (string) $c['nombres'],
            'apellidos' => $c['apellidos'],
            'telefono' => $c['telefono'],
            'email' => $c['email'],
            'tiene_cuenta' => $c['usuario_id'] !== null,
            'origen' => (string) $c['origen'],
            'bloqueado' => (bool) $c['bloqueado'],
            'notas_internas' => $c['notas_internas'],
            'total_reservas' => (int) $c['total_reservas'],
            'total_inasistencias' => (int) $c['total_inasistencias'],
            'primera_visita' => $c['primera_visita_en'] !== null ? BookingPresenter::iso((string) $c['primera_visita_en']) : null,
            'ultima_visita' => $c['ultima_visita_en'] !== null ? BookingPresenter::iso((string) $c['ultima_visita_en']) : null,
            'proxima_cita' => $c['proxima_cita_utc'] !== null ? BookingPresenter::iso((string) $c['proxima_cita_utc']) : null,
            'cliente_desde' => BookingPresenter::iso((string) $c['vinculado_en']),
        ];
    }

    private function repo(Request $request): PanelClientRepository
    {
        return new PanelClientRepository((int) $this->tenant($request)['id']);
    }

    /** @return list<string> */
    private function perms(Request $request): array
    {
        return Gate::rolePermissions(((array) $request->attribute('membership'))['roles'] ?? []);
    }
}
