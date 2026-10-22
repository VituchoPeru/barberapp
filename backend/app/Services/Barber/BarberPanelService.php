<?php

declare(strict_types=1);

namespace App\Services\Barber;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Media;
use App\Helpers\Validator;
use App\Repositories\AgendaBlockRepository;
use App\Repositories\AgendaRepository;
use App\Repositories\BarberRepository;
use App\Repositories\BranchRepository;
use App\Repositories\TeamSettingsRepository;
use App\Services\Catalog\BarberService;
use App\Services\Catalog\CatalogPresenter;
use App\Services\Panel\AgendaService;
use App\Services\Panel\PanelBookingPresenter;
use App\Services\Schedule\AgendaBlockService;
use App\Services\Schedule\WorkingTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Panel del barbero (Fase 7): una vista móvil y simplificada de SU agenda.
 *
 * - El alcance es siempre el barbero vinculado a la cuenta (barberos.usuario_id), incluso si
 *   quien entra es el propietario que también atiende: aquí nunca se ve a otros trabajadores.
 * - Las citas se presentan sin datos de caja (cobrado, saldo, cobros) ni correo del cliente:
 *   el barbero ve lo que necesita para atender (nombre, teléfono, servicios, notas).
 * - Atender (iniciar, atendida, no asistió, confirmar) y las notas reutilizan AgendaService
 *   con el alcance fijado al barbero: mismas reglas, candados y auditoría que el panel.
 * - Ausencias: el barbero las solicita; según los ajustes del equipo esperan aprobación.
 */
final class BarberPanelService
{
    /** Acciones del panel que tienen sentido en la vista del barbero. */
    private const ACCIONES = ['confirmar', 'iniciar', 'completar', 'no_asistio', 'notas'];

    public const MAX_DIAS_PROXIMAS = 31;
    public const MAX_DIAS_HISTORIAL = 366;
    public const MAX_DIAS_HORARIO = 31;

    private AgendaRepository $agenda;
    private int $barberoId;

    /** @var array<string, mixed> */
    private array $barbero;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $branches = null;

    /** @param list<string> $perms permisos de quien opera en esta barbería */
    public function __construct(private int $tenantId, private array $perms, private int $userId, private string $tenantTimezone = 'America/Lima')
    {
        $this->agenda = new AgendaRepository($tenantId);
        $id = $this->agenda->barberIdForUser($userId);
        if ($id === null) {
            throw new HttpException(403, 'no_barber_profile', 'Tu cuenta no está vinculada a un barbero de esta barbería. Pide al propietario que la vincule.');
        }
        $this->barberoId = $id;
        $this->barbero = (array) (new BarberRepository($tenantId))->find($id);
    }

    public function barberId(): int
    {
        return $this->barberoId;
    }

    public function can(string $perm): bool
    {
        return in_array($perm, $this->perms, true);
    }

    /** AgendaService con el alcance fijado a este barbero (lo usan las acciones de atención). */
    public function agendaService(): AgendaService
    {
        return new AgendaService($this->tenantId, $this->perms, $this->userId, $this->barberoId);
    }

    // ================================================================== ajustes y perfil

    /** @return array<string, bool> */
    public function settings(): array
    {
        $team = (new TeamSettingsRepository($this->tenantId))->all();
        $gestiona = $this->can('bloqueos.gestionar');
        return [
            'puede_solicitar_ausencias' => $gestiona || $this->can('bloqueos.solicitar'),
            // Quien puede aprobar (p. ej. el propietario que también atiende) no espera su propia aprobación.
            'ausencias_requieren_aprobacion' => $team['ausencias_requieren_aprobacion'] && !$gestiona,
            'puede_editar_horario' => $this->can('horarios.gestionar') || ($this->can('horarios.propios') && $team['barbero_edita_horario']),
        ];
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        $tz = $this->timezone();
        return [
            'id' => $this->barberoId,
            'nombre' => (string) $this->barbero['nombre_publico'],
            'especialidad' => $this->barbero['especialidad'],
            'foto_url' => Media::url($this->barbero['foto_url']),
            'activo' => (bool) $this->barbero['activo'],
            'sucursal' => ['id' => (int) $this->barbero['sucursal_id'], 'nombre' => (string) $this->barbero['sucursal']],
            'zona_horaria' => $tz,
        ];
    }

    // ================================================================== agenda del día

    /**
     * Agenda de un día (por defecto hoy): citas de todas sus sedes, jornada efectiva,
     * descansos, bloqueos, la cita en atención y la siguiente.
     *
     * @param array<string, mixed> $query fecha?
     * @return array<string, mixed>
     */
    public function day(array $query): array
    {
        $q = Validator::validate($query, ['fecha' => 'nullable|date']);
        $tz = $this->timezone();
        $hoy = Clock::nowUtc()->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
        $fecha = (string) ($q['fecha'] ?? $hoy);
        [$desdeUtc, $hastaUtc] = $this->utcRange($fecha, $fecha, $tz);

        $citas = $this->presentRows($this->agenda->range(null, $this->barberoId, $desdeUtc, $hastaUtc));
        $barbers = new BarberRepository($this->tenantId);
        $blocks = new AgendaBlockRepository($this->tenantId);
        $ventanas = WorkingTime::windows(
            $barbers->shifts($this->barberoId), $barbers->breaks($this->barberoId), $this->branches(true),
            $blocks->approvedFor($this->barberoId, $desdeUtc, $hastaUtc), $fecha, $fecha
        );
        $branches = $this->branches();
        $jornada = array_map(static fn (array $w): array => [
            'sucursal_id' => $w['sucursal_id'], 'sucursal' => $branches[$w['sucursal_id']]['nombre'] ?? null,
            'inicio' => $w['inicio_local'], 'fin' => $w['fin_local'],
            'minutos' => $w['minutos'],
        ], $ventanas);
        $dia = (int) (new DateTimeImmutable($fecha))->format('N');
        $descansos = array_values(array_map(static fn (array $d): array => ['inicio' => $d['hora_inicio'], 'fin' => $d['hora_fin'], 'motivo' => $d['motivo']],
            array_filter($barbers->breaks($this->barberoId), static fn (array $d): bool => (int) $d['dia_semana'] === $dia)));

        $bloqueos = [];
        foreach ($blocks->search(['barbero_id' => $this->barberoId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc]) as $b) {
            if ($b['estado'] === 'rechazado' || ($b['barbero_id'] === null && $b['estado'] !== 'aprobado')) {
                continue;
            }
            $ini = Clock::utcToLocal((string) $b['inicio_utc'], $tz);
            $fin = Clock::utcToLocal((string) $b['fin_utc'], $tz);
            $bloqueos[] = [
                'id' => (int) $b['id'], 'tipo' => (string) $b['tipo'], 'estado' => (string) $b['estado'],
                'general' => $b['barbero_id'] === null, 'motivo' => $b['motivo'],
                'inicio' => $ini->format('Y-m-d') < $fecha ? '00:00' : $ini->format('H:i'),
                'fin' => $fin->format('Y-m-d') > $fecha ? '24:00' : $fin->format('H:i'),
            ];
        }

        $resumen = ['total' => 0, 'pendiente' => 0, 'confirmada' => 0, 'completada' => 0, 'cancelada' => 0, 'no_asistio' => 0, 'en_atencion' => 0, 'minutos_reservados' => 0];
        $enAtencion = null;
        $siguiente = null;
        $now = Clock::nowUtc()->getTimestamp();
        foreach ($citas as $c) {
            $resumen[$c['estado']]++;
            if ($c['estado'] !== 'cancelada') {
                $resumen['total']++;
                $resumen['minutos_reservados'] += $c['duracion_total_minutos'];
            }
            if ($c['en_atencion']) {
                $resumen['en_atencion']++;
                $enAtencion ??= $c['id'];
            } elseif ($siguiente === null && in_array($c['estado'], ['pendiente', 'confirmada'], true) && strtotime($c['fin_utc']) > $now) {
                $siguiente = $c['id'];
            }
        }
        $resumen['minutos_jornada'] = array_sum(array_column($jornada, 'minutos'));

        return [
            'barbero' => $this->profile(),
            'fecha' => $fecha,
            'hoy' => $hoy,
            'jornada' => $jornada,
            'descansos' => $descansos,
            'bloqueos' => $bloqueos,
            'citas' => $citas,
            'en_atencion_id' => $enAtencion,
            'siguiente_id' => $siguiente,
            'resumen' => $resumen,
            'solicitudes_pendientes' => $blocks->pendingCountForBarber($this->barberoId, Clock::nowSql()),
            'ajustes' => $this->settings(),
            'actualizado_en' => gmdate('Y-m-d\TH:i:s\Z', Clock::nowUtc()->getTimestamp()),
        ];
    }

    // ================================================================== próximas e historial

    /**
     * Próximas citas activas (desde ahora) para los siguientes días.
     *
     * @param array<string, mixed> $query dias? (1..31, por defecto 14)
     * @return array<string, mixed>
     */
    public function upcoming(array $query): array
    {
        $q = Validator::validate($query, ['dias' => 'nullable|integer|min:1|max:' . self::MAX_DIAS_PROXIMAS]);
        $dias = (int) ($q['dias'] ?? 14);
        $now = Clock::nowUtc();
        $rows = $this->agenda->upcoming(null, $this->barberoId, $now->format('Y-m-d H:i:s'), $now->modify("+{$dias} days")->format('Y-m-d H:i:s'), 300);
        return ['dias' => $dias, 'citas' => $this->presentRows($rows)];
    }

    /**
     * Citas pasadas del barbero (de la más reciente a la más antigua), paginadas.
     *
     * @param array<string, mixed> $query desde?, hasta? (AAAA-MM-DD locales; por defecto últimos 30 días), estado?, page, per_page
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function history(array $query, int $page, int $perPage): array
    {
        $q = Validator::validate($query, [
            'desde' => 'nullable|date', 'hasta' => 'nullable|date',
            'estado' => 'nullable|in:completada,no_asistio,cancelada,pendiente,confirmada',
        ]);
        $tz = $this->timezone();
        $hoy = Clock::nowUtc()->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
        $hasta = (string) ($q['hasta'] ?? $hoy);
        $desde = (string) ($q['desde'] ?? (new DateTimeImmutable($hasta))->modify('-29 days')->format('Y-m-d'));
        if ($hasta < $desde) {
            throw new ValidationException(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if ((new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days >= self::MAX_DIAS_HISTORIAL) {
            throw new ValidationException(['desde' => ['Consulta como máximo ' . self::MAX_DIAS_HISTORIAL . ' días.']]);
        }
        [$desdeUtc, $hastaUtc] = $this->utcRange($desde, $hasta, $tz);
        // El historial termina ahora: lo que aún no empieza está en "próximas".
        $hastaUtc = min($hastaUtc, Clock::nowSql());
        $res = $this->agenda->barberHistory($this->barberoId, $desdeUtc, $hastaUtc, $q['estado'] ?? null, $perPage, ($page - 1) * $perPage);
        $atendidas = $res['por_estado']['completada'];
        $inasist = $res['por_estado']['no_asistio'];
        return [
            'items' => $this->presentRows($res['items']),
            'meta' => [
                'desde' => $desde, 'hasta' => $hasta, 'page' => $page, 'per_page' => $perPage, 'total' => $res['total'],
                'por_estado' => $res['por_estado'],
                // Misma definición que los reportes (decisión 051): no asistió ÷ (atendidas + no asistió).
                'tasa_inasistencia' => $atendidas + $inasist > 0 ? round($inasist * 100 / ($atendidas + $inasist), 1) : null,
            ],
        ];
    }

    // ================================================================== detalle

    /** @return array<string, mixed> cita con historial y visitas anteriores del cliente con este barbero */
    public function detail(int $id): array
    {
        $svc = $this->agendaService();
        $row = $svc->visible($id);
        $out = $this->strip($svc->presented($id, true));
        $prev = $this->agenda->clientVisitsWithBarber((int) $row['cliente_id'], $this->barberoId, (string) $row['inicio_utc']);
        $services = $this->agenda->servicesFor(array_map(static fn (array $r): int => (int) $r['id'], $prev['items']));
        $out['visitas_previas'] = [
            'atendidas' => $prev['atendidas'],
            'ultimas' => array_map(static function (array $r) use ($services): array {
                $ini = Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria']);
                return [
                    'fecha' => $ini->format('Y-m-d'), 'hora' => $ini->format('H:i'), 'estado' => (string) $r['estado'],
                    'servicios' => array_map(static fn (array $s): string => (string) $s['nombre_servicio'], $services[(int) $r['id']] ?? []),
                ];
            }, $prev['items']),
        ];
        return $out;
    }

    /** @return array<string, mixed> */
    public function transition(int $id, string $accion, Request $request): array
    {
        $this->agendaService()->transition($id, $accion, $request);
        return $this->detail($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function notes(int $id, array $input, Request $request): array
    {
        $this->agendaService()->notes($id, $input, $request);
        return $this->detail($id);
    }

    // ================================================================== horario

    /**
     * Jornada semanal, descansos y jornada efectiva de los próximos días (con bloqueos).
     *
     * @param array<string, mixed> $query desde?, dias? (1..31, por defecto 14)
     * @return array<string, mixed>
     */
    public function schedule(array $query): array
    {
        $q = Validator::validate($query, ['desde' => 'nullable|date', 'dias' => 'nullable|integer|min:1|max:' . self::MAX_DIAS_HORARIO]);
        $tz = $this->timezone();
        $desde = (string) ($q['desde'] ?? Clock::nowUtc()->setTimezone(new DateTimeZone($tz))->format('Y-m-d'));
        $hasta = (new DateTimeImmutable($desde))->modify('+' . ((int) ($q['dias'] ?? 14) - 1) . ' days')->format('Y-m-d');
        $catalog = new BarberService($this->tenantId, $this->tenantTimezone);
        $detail = $catalog->detail($this->barberoId);
        $efectiva = $catalog->effectiveSchedule($this->barberoId, $desde, $hasta);

        $branches = $this->branches();
        $porDia = [];
        foreach ($efectiva['ventanas'] as $w) {
            $porDia[$w['fecha']][] = [
                'sucursal_id' => $w['sucursal_id'], 'sucursal' => $w['sucursal'],
                'inicio' => $w['inicio_local'], 'fin' => $w['fin_local'],
                'minutos' => $w['minutos'],
            ];
        }
        $dias = [];
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $f = $d->format('Y-m-d');
            $dias[] = ['fecha' => $f, 'dia_semana' => (int) $d->format('N'), 'ventanas' => $porDia[$f] ?? [], 'minutos' => array_sum(array_column($porDia[$f] ?? [], 'minutos'))];
        }

        $ajustes = $this->settings();
        return [
            'barbero' => $this->profile(),
            'semanal' => ['tramos' => $detail['horario'], 'descansos' => $detail['descansos']],
            'desde' => $desde,
            'hasta' => $hasta,
            'dias' => $dias,
            'minutos_totales' => $efectiva['minutos_totales'],
            'bloqueos' => (new AgendaBlockService($this->tenantId, $this->tenantTimezone))->listForBarber($this->barberoId, []),
            // Sedes y su horario de atención: el editor solo permite tramos dentro de ese horario.
            'sucursales' => array_values(array_map(static fn (int $id, array $b): array => [
                'id' => $id, 'nombre' => $b['nombre'], 'activo' => $b['activo'],
                'horario' => array_map(static fn (array $h): array => ['dia_semana' => (int) $h[0], 'apertura' => substr((string) $h[1], 0, 5), 'cierre' => substr((string) $h[2], 0, 5)], $b['horario']),
            ], array_keys($branches), $branches)),
            'ajustes' => $ajustes,
        ];
    }

    /**
     * El barbero cambia su propia jornada (solo si la barbería lo habilitó). Mismas reglas que en
     * el panel (WeeklySchedule, candado del barbero); las citas que quedan fuera se informan.
     *
     * @param array<string, mixed> $input {tramos, descansos}
     * @return array<string, mixed>
     */
    public function updateSchedule(array $input, Request $request): array
    {
        if (!$this->settings()['puede_editar_horario']) {
            throw new HttpException(403, 'schedule_locked', 'Tu barbería no permite que los barberos cambien su jornada. Pide el cambio al encargado o solicita una ausencia.');
        }
        $res = (new BarberService($this->tenantId, $this->tenantTimezone))->replaceSchedule($this->barberoId, $input, $request);
        return ['horario' => $this->schedule([]), 'advertencias' => $res['advertencias']];
    }

    // ================================================================== ausencias

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function blocks(array $query): array
    {
        return [
            'items' => (new AgendaBlockService($this->tenantId, $this->tenantTimezone))->listForBarber($this->barberoId, $query),
            'ajustes' => $this->settings(),
            'tipos' => AgendaBlockService::TIPOS_BARBERO,
            'sucursales' => array_values(array_map(static fn (int $id, array $b): array => ['id' => $id, 'nombre' => $b['nombre']], array_keys($this->branches(true)), $this->branches(true))),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function requestBlock(array $input, Request $request): array
    {
        $ajustes = $this->settings();
        if (!$ajustes['puede_solicitar_ausencias']) {
            throw new HttpException(403, 'forbidden', 'No tienes permiso para solicitar ausencias.');
        }
        return (new AgendaBlockService($this->tenantId, $this->tenantTimezone))->requestForBarber($this->barberoId, $input, $request, $ajustes['ausencias_requieren_aprobacion']);
    }

    public function withdrawBlock(int $id, Request $request): void
    {
        (new AgendaBlockService($this->tenantId, $this->tenantTimezone))->withdrawForBarber($this->barberoId, $id, $request);
    }

    // ================================================================== utilidades

    /**
     * @param list<array<string, mixed>> $rows filas de AgendaRepository
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows): array
    {
        $services = $this->agenda->servicesFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        return array_map(fn (array $r): array => $this->strip(PanelBookingPresenter::present($r, $services[(int) $r['id']] ?? [], $this->perms)), $rows);
    }

    /**
     * Quita lo que el barbero no necesita para atender: datos de caja y correo del cliente.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function strip(array $c): array
    {
        unset($c['pagado'], $c['saldo'], $c['cobros']);
        $c['cliente']['email'] = null;
        $c['acciones'] = array_intersect_key($c['acciones'], array_flip(self::ACCIONES));
        return $c;
    }

    /** @return array{0:string, 1:string} [desde, hasta) en UTC para un rango de fechas locales */
    private function utcRange(string $desde, string $hasta, string $tz): array
    {
        $zone = new DateTimeZone($tz);
        $utc = new DateTimeZone('UTC');
        return [
            (new DateTimeImmutable($desde . ' 00:00:00', $zone))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($hasta . ' 00:00:00', $zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** Zona horaria de la sede principal del barbero (o la de la barbería). */
    private function timezone(): string
    {
        $suc = (int) ($this->barbero['sucursal_id'] ?? 0);
        $tz = $this->branches()[$suc]['zona_horaria'] ?? $this->tenantTimezone;
        return WorkingTime::zone($tz)->getName();
    }

    /** @return array<int, array<string, mixed>> */
    private function branches(bool $onlyActive = false): array
    {
        $this->branches ??= (new BranchRepository($this->tenantId))->scheduleMap(false);
        return $onlyActive ? array_filter($this->branches, static fn (array $b): bool => $b['activo']) : $this->branches;
    }
}
