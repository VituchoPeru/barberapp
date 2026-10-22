<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Database;
use App\Helpers\Clock;
use App\Helpers\Ip;
use PDO;

/**
 * Copia de los datos personales de una cuenta (derecho de acceso y portabilidad: JSON legible
 * por máquinas). Incluye solo lo que es de la persona: nunca datos de otros clientes, del
 * personal de la barbería ni las notas internas que el personal escribe sobre ella (esas las
 * gestiona cada barbería, responsable de ese tratamiento; se pueden pedir con una solicitud).
 */
final class DataExport
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<string, mixed> */
    public function build(int $userId): array
    {
        $iso = static fn ($v) => $v === null ? null : str_replace(' ', 'T', (string) $v) . 'Z';

        $u = $this->one(
            'SELECT u.id, u.email, u.nombres, u.apellidos, u.telefono, u.estado, u.email_verificado_en, u.ultimo_login_en, u.created_at,
                    u.acepto_terminos_en, u.terminos_version, u.acepto_privacidad_en, u.privacidad_version, r.codigo AS rol_plataforma
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_plataforma_id WHERE u.id = ?',
            [$userId]
        );

        $cliente = $this->one('SELECT id, nombres, apellidos, telefono, email, fecha_nacimiento, created_at FROM clientes WHERE usuario_id = ? AND deleted_at IS NULL', [$userId]);
        $barberias = [];
        $reservas = [];
        $favoritos = [];
        if ($cliente !== null) {
            $cid = (int) $cliente['id'];
            foreach ($this->all(
                'SELECT t.nombre_comercial, t.slug, ct.origen, ct.acepta_marketing, ct.primera_visita_en, ct.ultima_visita_en, ct.total_reservas, ct.total_inasistencias, ct.created_at
                   FROM cliente_tenants ct JOIN tenants t ON t.id = ct.tenant_id WHERE ct.cliente_id = ? ORDER BY ct.created_at',
                [$cid]
            ) as $r) {
                $barberias[] = [
                    'barberia' => $r['nombre_comercial'], 'enlace' => '/b/' . $r['slug'], 'origen' => $r['origen'],
                    'acepta_marketing' => (bool) $r['acepta_marketing'], 'primera_visita_en' => $iso($r['primera_visita_en']),
                    'ultima_visita_en' => $iso($r['ultima_visita_en']), 'total_reservas' => (int) $r['total_reservas'],
                    'total_inasistencias' => (int) $r['total_inasistencias'], 'desde' => $iso($r['created_at']),
                ];
            }
            $rows = $this->all(
                'SELECT r.id, r.codigo, r.estado, r.inicio_utc, r.fin_utc, r.zona_horaria, r.precio_total, r.moneda, r.canal, r.notas_cliente,
                        r.confirmada_en, r.asistencia_confirmada_en, r.cancelada_en, r.cancelada_por_tipo, r.motivo_cancelacion, r.reprogramaciones, r.created_at,
                        t.nombre_comercial AS barberia, s.nombre AS sucursal, b.nombre_publico AS barbero
                   FROM reservas r JOIN tenants t ON t.id = r.tenant_id JOIN sucursales s ON s.id = r.sucursal_id JOIN barberos b ON b.id = r.barbero_id
                  WHERE r.cliente_id = ? ORDER BY r.inicio_utc DESC',
                [$cid]
            );
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $servicios = [];
            $pagos = [];
            if ($ids !== []) {
                $in = implode(',', $ids);
                foreach ($this->all("SELECT reserva_id, nombre_servicio, duracion_minutos, precio FROM reserva_servicios WHERE reserva_id IN ({$in}) ORDER BY reserva_id, orden") as $s) {
                    $servicios[(int) $s['reserva_id']][] = ['servicio' => $s['nombre_servicio'], 'duracion_minutos' => (int) $s['duracion_minutos'], 'precio' => (string) $s['precio']];
                }
                foreach ($this->all("SELECT reserva_id, monto, moneda, metodo, pagado_en FROM reserva_pagos WHERE reserva_id IN ({$in}) AND anulado_en IS NULL ORDER BY pagado_en") as $p) {
                    $pagos[(int) $p['reserva_id']][] = ['monto' => (string) $p['monto'], 'moneda' => $p['moneda'], 'metodo' => $p['metodo'], 'pagado_en' => $iso($p['pagado_en'])];
                }
            }
            foreach ($rows as $r) {
                $reservas[] = [
                    'codigo' => $r['codigo'], 'barberia' => $r['barberia'], 'sucursal' => $r['sucursal'], 'barbero' => $r['barbero'],
                    'estado' => $r['estado'], 'inicio_utc' => $iso($r['inicio_utc']), 'fin_utc' => $iso($r['fin_utc']), 'zona_horaria' => $r['zona_horaria'],
                    'servicios' => $servicios[(int) $r['id']] ?? [], 'precio_previsto' => (string) $r['precio_total'], 'moneda' => $r['moneda'],
                    'pagos_registrados' => $pagos[(int) $r['id']] ?? [], 'canal' => $r['canal'], 'tus_notas' => $r['notas_cliente'],
                    'confirmada_en' => $iso($r['confirmada_en']), 'asistencia_confirmada_en' => $iso($r['asistencia_confirmada_en']),
                    'cancelada_en' => $iso($r['cancelada_en']), 'cancelada_por' => $r['cancelada_por_tipo'], 'motivo_cancelacion' => $r['motivo_cancelacion'],
                    'reprogramaciones' => (int) $r['reprogramaciones'], 'creada_en' => $iso($r['created_at']),
                ];
            }
            foreach ($this->all('SELECT t.nombre_comercial, t.slug, f.created_at FROM favoritos f JOIN tenants t ON t.id = f.tenant_id WHERE f.cliente_id = ? ORDER BY f.created_at', [$cid]) as $f) {
                $favoritos[] = ['barberia' => $f['nombre_comercial'], 'enlace' => '/b/' . $f['slug'], 'desde' => $iso($f['created_at'])];
            }
        }

        $equipo = array_map(static fn (array $m): array => [
            'barberia' => $m['nombre_comercial'], 'rol' => $m['rol'], 'estado' => $m['estado'], 'aceptado_en' => $iso($m['aceptado_en']), 'desde' => $iso($m['created_at']),
        ], $this->all(
            'SELECT t.nombre_comercial, r.codigo AS rol, m.estado, m.aceptado_en, m.created_at
               FROM membresias m JOIN tenants t ON t.id = m.tenant_id JOIN roles r ON r.id = m.rol_id WHERE m.usuario_id = ? ORDER BY m.created_at',
            [$userId]
        ));

        $sesiones = array_map(static fn (array $s): array => [
            'canal' => $s['canal'], 'dispositivo' => $s['nombre_dispositivo'], 'navegador' => $s['user_agent'], 'ip' => Ip::fromBinary($s['ip']),
            'inicio' => $iso($s['created_at']), 'ultimo_uso' => $iso($s['ultimo_uso_en']), 'vence' => $iso($s['expira_en']), 'cerrada_en' => $iso($s['revocada_en']),
        ], $this->all('SELECT canal, nombre_dispositivo, user_agent, ip, created_at, ultimo_uso_en, expira_en, revocada_en FROM sesiones WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 100', [$userId]));

        $dispositivos = array_map(static fn (array $d): array => [
            'plataforma' => $d['plataforma'], 'modelo' => $d['modelo'], 'version_app' => $d['app_version'], 'activo' => (bool) $d['activo'],
            'registrado_en' => $iso($d['created_at']), 'ultimo_aviso' => $iso($d['ultimo_uso_en']),
        ], $this->all('SELECT plataforma, modelo, app_version, activo, created_at, ultimo_uso_en FROM dispositivos_push WHERE usuario_id = ?', [$userId]));

        $preferencias = array_map(static fn (array $p): array => [
            'canal' => $p['canal'], 'categoria' => $p['tipo'], 'habilitado' => (bool) $p['habilitado'],
        ], $this->all('SELECT canal, tipo, habilitado FROM preferencias_notificacion WHERE usuario_id = ?', [$userId]));

        $avisos = array_map(static fn (array $n): array => [
            'canal' => $n['canal'], 'titulo' => $n['titulo'], 'texto' => $n['cuerpo'], 'estado' => $n['estado'],
            'programado' => $iso($n['programada_para_utc']), 'enviado' => $iso($n['enviada_en']), 'leido' => $iso($n['leida_en']),
        ], $this->all('SELECT canal, titulo, cuerpo, estado, programada_para_utc, enviada_en, leida_en FROM notificaciones WHERE usuario_id = ? ORDER BY id DESC LIMIT 500', [$userId]));

        $actividad = array_map(static fn (array $a): array => [
            'accion' => $a['accion'], 'resultado' => $a['resultado'], 'ip' => Ip::fromBinary($a['ip']), 'navegador' => $a['user_agent'], 'fecha' => $iso($a['created_at']),
        ], $this->all(
            'SELECT accion, resultado, ip, user_agent, created_at FROM auditoria WHERE usuario_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 500',
            [$userId, Clock::nowUtc()->modify('-365 days')->format('Y-m-d H:i:s')]
        ));

        $solicitudes = array_map(static fn (array $s): array => [
            'codigo' => $s['codigo'], 'tipo' => $s['tipo'], 'estado' => $s['estado'], 'detalle' => $s['detalle'], 'respuesta' => $s['respuesta'],
            'creada_en' => $iso($s['created_at']), 'atendida_en' => $iso($s['atendida_en']),
        ], $this->all('SELECT codigo, tipo, estado, detalle, respuesta, created_at, atendida_en FROM solicitudes_datos WHERE usuario_id = ? ORDER BY id DESC', [$userId]));

        return [
            'formato' => 'barberapp.datos-personales.v1',
            'generado_en' => $iso(Clock::nowSql()),
            'nota' => 'Copia de tus datos personales en BARBERAPP (Ley N.º 29733). Las notas internas que el personal de una barbería escribe sobre ti '
                . 'las gestiona esa barbería: pídelas con una solicitud de acceso desde Mi cuenta → Privacidad.',
            'cuenta' => $u === null ? null : [
                'email' => $u['email'], 'nombres' => $u['nombres'], 'apellidos' => $u['apellidos'], 'telefono' => $u['telefono'], 'estado' => $u['estado'],
                'email_verificado_en' => $iso($u['email_verificado_en']), 'ultimo_inicio_sesion' => $iso($u['ultimo_login_en']), 'creada_en' => $iso($u['created_at']),
                'rol_plataforma' => $u['rol_plataforma'],
                'consentimientos' => [
                    'terminos' => ['aceptado_en' => $iso($u['acepto_terminos_en']), 'version' => $u['terminos_version']],
                    'privacidad' => ['aceptado_en' => $iso($u['acepto_privacidad_en']), 'version' => $u['privacidad_version']],
                ],
            ],
            'perfil_cliente' => $cliente === null ? null : [
                'nombres' => $cliente['nombres'], 'apellidos' => $cliente['apellidos'], 'telefono' => $cliente['telefono'], 'email' => $cliente['email'],
                'fecha_nacimiento' => $cliente['fecha_nacimiento'], 'creado_en' => $iso($cliente['created_at']),
            ],
            'barberias_como_cliente' => $barberias,
            'reservas' => $reservas,
            'favoritos' => $favoritos,
            'equipo_de_barberias' => $equipo,
            'sesiones' => $sesiones,
            'dispositivos_con_avisos' => $dispositivos,
            'preferencias_de_avisos' => $preferencias,
            'avisos_recibidos' => $avisos,
            'actividad_de_seguridad_12_meses' => $actividad,
            'solicitudes_sobre_datos' => $solicitudes,
        ];
    }

    /** @param list<mixed> $params @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
