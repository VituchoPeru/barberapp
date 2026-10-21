<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Config;
use App\Core\Env;
use App\Helpers\Codes;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Datos de DEMOSTRACIÓN para desarrollo y pruebas manuales.
 *
 * - Todos los nombres incluyen "Demo" y los correos usan el dominio reservado .test,
 *   para que nunca se confundan con datos reales.
 * - Se niega a ejecutarse con APP_ENV distinto de local/testing.
 * - Requiere CatalogSeeder previo (roles y planes).
 */
final class DemoSeeder
{
    private const TZ = 'America/Lima';

    /** @var array<string, int> */
    private array $roles = [];

    /** @var list<string> */
    private array $summary = [];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<string> */
    public function run(): array
    {
        $env = (string) Config::get('app.env', 'production');
        if (!in_array($env, ['local', 'testing'], true)) {
            throw new RuntimeException('DemoSeeder solo puede ejecutarse en entornos local o testing.');
        }
        $exists = $this->db->query("SELECT COUNT(*) FROM tenants WHERE slug = 'demo-centro'")->fetchColumn();
        if ((int) $exists > 0) {
            return ['Los datos de demostración ya existen (tenant demo-centro). Use migrate:fresh --demo para recrearlos.'];
        }

        $this->roles = $this->db->query('SELECT codigo, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($this->roles) < 5) {
            throw new RuntimeException('Ejecute primero el CatalogSeeder (php bin/console db:seed).');
        }
        $planes = $this->db->query('SELECT codigo, id FROM planes')->fetchAll(PDO::FETCH_KEY_PAIR);

        $password = (string) (Env::get('DEMO_PASSWORD') ?: 'Demo2026!');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            // ---------- Usuarios ----------
            $superadmin = $this->usuario('superadmin@barberapp.test', $hash, 'Superadmin', 'Demo', (int) $this->roles['superadmin']);
            $propCentro = $this->usuario('propietario.centro@barberapp.test', $hash, 'Propietario', 'Demo Centro');
            $adminCentro = $this->usuario('admin.centro@barberapp.test', $hash, 'Administrador', 'Demo Centro');
            $barb1User = $this->usuario('barbero1.centro@barberapp.test', $hash, 'Barbero Uno', 'Demo');
            $barb2User = $this->usuario('barbero2.centro@barberapp.test', $hash, 'Barbero Dos', 'Demo');
            $propNorte = $this->usuario('propietario.norte@barberapp.test', $hash, 'Propietario', 'Demo Norte');
            $barb3User = $this->usuario('barbero1.norte@barberapp.test', $hash, 'Barbero Tres', 'Demo');
            $clienteUser = $this->usuario('cliente1@barberapp.test', $hash, 'Cliente Uno', 'Demo');
            $cliente2User = $this->usuario('cliente2@barberapp.test', $hash, 'Cliente Dos', 'Demo');

            // ---------- Tenants ----------
            $centro = $this->tenant('demo-centro', 'Barbería Demo Centro', 'activo', $propCentro, true);
            $norte = $this->tenant('demo-norte', 'Barbería Demo Norte', 'activo', $propNorte, true);
            $suspendida = $this->tenant('demo-suspendida', 'Barbería Demo Suspendida', 'suspendido', null, false);
            // Activa y publicada, con sucursal sin coordenadas (prueba de distancias).
            $sur = $this->tenant('demo-sur', 'Barbería Demo Sur', 'activo', null, true);
            // Activa pero NO publicada: solo accesible por su enlace directo o QR.
            $privada = $this->tenant('demo-privada', 'Barbería Demo Privada', 'activo', null, false);
            $this->exec(
                "UPDATE tenants SET descripcion = ?, telefono = '+51900000010', whatsapp = '+51900000010' WHERE id = ?",
                ['Barbería de demostración con dos sedes en Lima. Cortes clásicos, barba y atención para niños.', $centro]
            );
            $this->exec("UPDATE tenants SET descripcion = ?, telefono = '+51900000020' WHERE id = ?", ['Barbería de demostración en Lima Norte.', $norte]);
            $this->exec("UPDATE tenants SET descripcion = ? WHERE id = ?", ['Barbería de demostración especializada en afeitado clásico y color.', $sur]);

            // ---------- Membresías (un usuario puede tener roles distintos en barberías distintas) ----------
            $this->membresia($centro, $propCentro, 'propietario');
            $this->membresia($centro, $adminCentro, 'administrador');
            $this->membresia($centro, $barb1User, 'barbero');
            $this->membresia($centro, $barb2User, 'barbero');
            $this->membresia($norte, $propNorte, 'propietario');
            $this->membresia($norte, $barb3User, 'barbero');
            $this->membresia($norte, $barb2User, 'administrador');

            // ---------- Suscripciones y pagos ----------
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $subCentro = $this->suscripcion($centro, (int) $planes['profesional'], 'activa', '79.00', $now->modify('-10 days'), $now->modify('+20 days'));
            $this->suscripcion($norte, (int) $planes['basico'], 'prueba', '39.00', $now->modify('-2 days'), $now->modify('+12 days'));
            $this->suscripcion($suspendida, (int) $planes['basico'], 'suspendida', '39.00', $now->modify('-60 days'), $now->modify('-30 days'));
            $this->exec(
                "INSERT INTO pagos_saas (tenant_id, suscripcion_id, monto, metodo, estado, referencia_externa, periodo_inicio, periodo_fin, pagado_en, registrado_por, notas)
                 VALUES (?, ?, '79.00', 'transferencia', 'confirmado', 'DEMO-OP-0001', ?, ?, ?, ?, 'Pago de demostración')",
                [$centro, $subCentro, $now->modify('-10 days')->format('Y-m-d'), $now->modify('+20 days')->format('Y-m-d'), $now->modify('-10 days')->format('Y-m-d H:i:s'), $superadmin]
            );

            // ---------- Sucursales y horarios comerciales ----------
            // Coordenadas aproximadas del centro de cada distrito (solo demostración).
            $sedeCentro = $this->sucursal($centro, 'Sede Demo Cercado', 'sede-cercado', 'Av. Demo 123', 'Cercado de Lima', true, -12.046374, -77.042793);
            $sedeCentro2 = $this->sucursal($centro, 'Sede Demo Miraflores', 'sede-miraflores', 'Calle Demo 456', 'Miraflores', false, -12.121100, -77.029700);
            $sedeNorte = $this->sucursal($norte, 'Sede Demo Los Olivos', 'sede-los-olivos', 'Jr. Demo 789', 'Los Olivos', true, -11.992100, -77.070500);
            // Sin coordenadas: el marketplace no debe calcularle distancia.
            $sedeSur = $this->sucursal($sur, 'Sede Demo Chorrillos', 'sede-chorrillos', 'Av. Demo Sur 321', 'Chorrillos', true);
            $sedePrivada = $this->sucursal($privada, 'Sede Demo Surco', 'sede-surco', 'Calle Demo Privada 55', 'Santiago de Surco', true, -12.136900, -76.996900);
            foreach ([[$centro, $sedeCentro], [$centro, $sedeCentro2], [$norte, $sedeNorte], [$sur, $sedeSur], [$privada, $sedePrivada]] as [$t, $s]) {
                for ($dia = 1; $dia <= 6; $dia++) {
                    $this->exec(
                        "INSERT INTO horarios_sucursales (tenant_id, sucursal_id, dia_semana, hora_apertura, hora_cierre) VALUES (?, ?, ?, '09:00', '20:00')",
                        [$t, $s, $dia]
                    );
                }
            }

            // ---------- Políticas de reserva ----------
            foreach ([$centro, $norte, $suspendida, $sur, $privada] as $t) {
                $this->exec(
                    "INSERT INTO politicas_reserva (tenant_id, texto_politica_cancelacion) VALUES (?, 'Puedes cancelar o reprogramar sin costo hasta 2 horas antes de tu cita.')",
                    [$t]
                );
            }

            // ---------- Servicios ----------
            $catCortes = $this->insert('INSERT INTO categorias_servicio (tenant_id, nombre, orden) VALUES (?, ?, 1)', [$centro, 'Cortes']);
            $catBarba = $this->insert('INSERT INTO categorias_servicio (tenant_id, nombre, orden) VALUES (?, ?, 2)', [$centro, 'Barba']);
            $sCorte = $this->servicio($centro, $catCortes, 'Corte clásico', 30, '25.00', 1, true, 'corte');
            $sCorteBarba = $this->servicio($centro, $catCortes, 'Corte y barba', 45, '40.00', 2, true, 'corte_barba');
            $sBarba = $this->servicio($centro, $catBarba, 'Perfilado de barba', 20, '15.00', 3, true, 'barba');
            $sInfantil = $this->servicio($centro, $catCortes, 'Corte infantil', 30, '20.00', 4, true, 'infantil');
            $this->servicio($centro, null, 'Servicio inactivo de prueba', 15, '10.00', 9, false, 'color');
            $sNorteCorte = $this->servicio($norte, null, 'Corte moderno', 30, '30.00', 1, true, 'corte');
            $sNorteBarba = $this->servicio($norte, null, 'Barba con toalla caliente', 25, '18.00', 2, true, 'barba');
            $sSurAfeitado = $this->servicio($sur, null, 'Afeitado clásico con navaja', 40, '35.00', 1, true, 'afeitado');
            $sSurColor = $this->servicio($sur, null, 'Color de barba', 45, '60.00', 2, true, 'color');
            $sPrivCorte = $this->servicio($privada, null, 'Corte ejecutivo', 30, '45.00', 1, true, 'corte');

            // ---------- Barberos, servicios habilitados y jornada ----------
            $b1 = $this->barbero($centro, $barb1User, $sedeCentro, 'Barbero Uno Demo', 1);
            $b2 = $this->barbero($centro, $barb2User, $sedeCentro, 'Barbero Dos Demo', 2);
            $b3 = $this->barbero($norte, $barb3User, $sedeNorte, 'Barbero Tres Demo', 1);
            // Barberos sin cuenta de acceso (la cuenta es opcional).
            $b4 = $this->barbero($sur, null, $sedeSur, 'Barbero Cuatro Demo', 1);
            $b5 = $this->barbero($privada, null, $sedePrivada, 'Barbero Cinco Demo', 1);
            $bInactivo = $this->barbero($centro, null, $sedeCentro2, 'Barbero Inactivo Demo', 9);
            $this->exec('UPDATE barberos SET activo = 0 WHERE id = ?', [$bInactivo]);
            $this->exec("UPDATE barberos SET biografia = 'Barbero de demostración. Especialista en cortes clásicos y degradados.' WHERE id = ?", [$b1]);
            foreach ([[$sur, $b4, $sSurAfeitado], [$sur, $b4, $sSurColor], [$privada, $b5, $sPrivCorte], [$centro, $bInactivo, $sCorte]] as [$t, $b, $s]) {
                $this->exec('INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$t, $b, $s]);
            }
            foreach ([$sCorte, $sCorteBarba, $sBarba, $sInfantil] as $s) {
                $this->exec('INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$centro, $b1, $s]);
            }
            foreach ([$sCorte, $sBarba] as $s) {
                $this->exec('INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$centro, $b2, $s]);
            }
            foreach ([$sNorteCorte, $sNorteBarba] as $s) {
                $this->exec('INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$norte, $b3, $s]);
            }
            // Barbero Uno: jornada continua con descanso recurrente (Fase 4). Los demás: jornada partida.
            $this->exec("UPDATE barberos SET especialidad = 'Cortes clásicos y degradados' WHERE id = ?", [$b1]);
            for ($dia = 1; $dia <= 6; $dia++) {
                $this->exec("INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, '09:00', '20:00')", [$centro, $b1, $sedeCentro, $dia]);
                $this->exec("INSERT INTO descansos_barberos (tenant_id, barbero_id, dia_semana, hora_inicio, hora_fin, motivo) VALUES (?, ?, ?, '13:00', '14:00', 'Almuerzo')", [$centro, $b1, $dia]);
            }
            foreach ([[$centro, $b2, $sedeCentro], [$norte, $b3, $sedeNorte], [$sur, $b4, $sedeSur], [$privada, $b5, $sedePrivada]] as [$t, $b, $s]) {
                for ($dia = 1; $dia <= 6; $dia++) {
                    // Jornada partida: el descanso de 13:00 a 14:00 es el hueco entre tramos.
                    $this->exec("INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, '09:00', '13:00')", [$t, $b, $s, $dia]);
                    $this->exec("INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, '14:00', '20:00')", [$t, $b, $s, $dia]);
                }
            }

            // ---------- Clientes ----------
            $c1 = $this->insert('INSERT INTO clientes (usuario_id, nombres, apellidos, telefono, email) VALUES (?, ?, ?, ?, ?)', [$clienteUser, 'Cliente Uno', 'Demo', '+51900000001', 'cliente1@barberapp.test']);
            $c2 = $this->insert('INSERT INTO clientes (usuario_id, nombres, apellidos, telefono, email) VALUES (?, ?, ?, ?, ?)', [$cliente2User, 'Cliente Dos', 'Demo', '+51900000002', 'cliente2@barberapp.test']);
            $cMostrador = $this->insert('INSERT INTO clientes (usuario_id, nombres, apellidos, telefono) VALUES (NULL, ?, ?, ?)', ['Cliente Mostrador', 'Demo', '+51900000003']);
            $this->exec("INSERT INTO cliente_tenants (tenant_id, cliente_id, origen) VALUES (?, ?, 'marketplace')", [$centro, $c1]);
            $this->exec("INSERT INTO cliente_tenants (tenant_id, cliente_id, origen) VALUES (?, ?, 'qr')", [$norte, $c1]);
            $this->exec("INSERT INTO cliente_tenants (tenant_id, cliente_id, origen) VALUES (?, ?, 'enlace')", [$centro, $c2]);
            $this->exec("INSERT INTO cliente_tenants (tenant_id, cliente_id, origen, notas_internas) VALUES (?, ?, 'panel', 'Cliente registrado en mostrador (demo)')", [$centro, $cMostrador]);
            $this->exec('INSERT INTO favoritos (cliente_id, tenant_id) VALUES (?, ?)', [$c1, $centro]);
            foreach (['confirmacion', 'recordatorio_24h', 'recordatorio_2h', 'cancelacion', 'reprogramacion'] as $tipo) {
                $this->exec("INSERT INTO preferencias_notificacion (usuario_id, tenant_id, canal, tipo, habilitado) VALUES (?, NULL, 'push', ?, 1)", [$clienteUser, $tipo]);
            }
            $this->exec(
                "INSERT INTO dispositivos_push (usuario_id, plataforma, proveedor, token, token_hash, app_version, modelo) VALUES (?, 'android', 'fcm', ?, ?, '0.1.0', 'Demo')",
                [$clienteUser, 'demo-token-no-valido', hash('sha256', 'demo-token-no-valido')]
            );

            // ---------- Reservas ----------
            $manana = $this->proximoDiaLaborable();
            $r1 = $this->reserva($centro, $sedeCentro, $b1, $c1, 'confirmada', $manana . ' 10:00', [[$sCorteBarba, 'Corte y barba', 45, '40.00']], 'marketplace', $clienteUser);
            $r2 = $this->reserva($centro, $sedeCentro, $b1, $c2, 'pendiente', $manana . ' 11:00', [[$sCorte, 'Corte clásico', 30, '25.00'], [$sBarba, 'Perfilado de barba', 20, '15.00']], 'enlace', $cliente2User);
            $pasado = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('-7 days')->format('Y-m-d');
            $r3 = $this->reserva($centro, $sedeCentro, $b2, $c1, 'completada', $pasado . ' 16:00', [[$sCorte, 'Corte clásico', 30, '25.00']], 'app', $clienteUser);
            $r4 = $this->reserva($centro, $sedeCentro, $b2, $cMostrador, 'cancelada', $manana . ' 15:00', [[$sCorte, 'Corte clásico', 30, '25.00']], 'panel', $adminCentro);
            $this->reserva($norte, $sedeNorte, $b3, $c1, 'pendiente', $manana . ' 17:00', [[$sNorteCorte, 'Corte moderno', 30, '30.00']], 'qr', $clienteUser);

            // Recordatorio programado (cola de notificaciones de la Fase 8)
            $inicioR1 = $this->db->query('SELECT inicio_utc FROM reservas WHERE id = ' . (int) $r1)->fetchColumn();
            $this->exec(
                "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, titulo, cuerpo, estado, programada_para_utc, dedupe_key)
                 VALUES (?, ?, ?, 'push', 'recordatorio_24h', 'Tu cita es mañana', 'Recordatorio de demostración', 'programada', DATE_SUB(?, INTERVAL 24 HOUR), ?)",
                [$centro, $clienteUser, $r1, $inicioR1, "reserva:{$r1}:recordatorio_24h:push"]
            );

            // Bloqueo de agenda: vacaciones aprobadas del barbero 2 dentro de 14 días
            $vac = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('+14 days')->setTime(0, 0);
            $this->exec(
                "INSERT INTO bloqueos_agenda (tenant_id, barbero_id, sucursal_id, tipo, inicio_utc, fin_utc, motivo, estado, creado_por, aprobado_por)
                 VALUES (?, ?, NULL, 'vacaciones', ?, ?, 'Vacaciones (demo)', 'aprobado', ?, ?)",
                [$centro, $b2, $this->utc($vac), $this->utc($vac->modify('+2 days')), $propCentro, $propCentro]
            );

            // Solicitud pendiente del barbero 1 (ausencia por trámite), para el flujo de aprobación.
            $tramite = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('+21 days')->setTime(9, 0);
            $this->exec(
                "INSERT INTO bloqueos_agenda (tenant_id, barbero_id, sucursal_id, tipo, inicio_utc, fin_utc, motivo, estado, creado_por)
                 VALUES (?, ?, NULL, 'ausencia', ?, ?, 'Trámite personal (demo)', 'solicitado', ?)",
                [$centro, $b1, $this->utc($tramite), $this->utc($tramite->setTime(13, 0)), $barb1User]
            );

            $this->exec(
                "INSERT INTO auditoria (tenant_id, usuario_id, accion, entidad, entidad_id, resultado, datos_nuevos)
                 VALUES (NULL, ?, 'seed.demo', 'tenants', NULL, 'exito', JSON_OBJECT('tenants', JSON_ARRAY(?, ?, ?, ?, ?)))",
                [$superadmin, $centro, $norte, $suspendida, $sur, $privada]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->summary[] = 'Tenants: demo-centro y demo-norte (activos, en el marketplace), demo-sur (activo, sede sin coordenadas), demo-privada (activo, solo por enlace /b/demo-privada), demo-suspendida (suspendido)';
        $this->summary[] = 'Usuarios (*@barberapp.test): superadmin, propietario.centro, admin.centro, barbero1.centro, barbero2.centro, propietario.norte, barbero1.norte, cliente1, cliente2';
        $this->summary[] = 'Contraseña de todos los usuarios demo: valor de DEMO_PASSWORD en .env';
        $this->summary[] = "Reservas: 5 (ids {$r1}, {$r2}, {$r3}, {$r4} en demo-centro y 1 en demo-norte)";
        return $this->summary;
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<mixed> $params */
    private function exec(string $sql, array $params = []): void
    {
        $this->db->prepare($sql)->execute($params);
    }

    /** @param list<mixed> $params */
    private function insert(string $sql, array $params = []): int
    {
        $this->exec($sql, $params);
        return (int) $this->db->lastInsertId();
    }

    private function usuario(string $email, string $hash, string $nombres, string $apellidos, ?int $rolPlataforma = null): int
    {
        return $this->insert(
            'INSERT INTO usuarios (email, password_hash, nombres, apellidos, rol_plataforma_id, email_verificado_en, acepto_terminos_en, acepto_privacidad_en)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$email, $hash, $nombres, $apellidos, $rolPlataforma]
        );
    }

    private function tenant(string $slug, string $nombre, string $estado, ?int $propietario, bool $marketplace): int
    {
        return $this->insert(
            "INSERT INTO tenants (slug, nombre_comercial, email, descripcion, estado, publicado_marketplace, propietario_usuario_id, aprobado_en, suspendido_en, motivo_suspension)
             VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'activo', UTC_TIMESTAMP(), NULL), IF(? = 'suspendido', UTC_TIMESTAMP(), NULL), IF(? = 'suspendido', 'Suspensión de demostración', NULL))",
            [$slug, $nombre, $slug . '@barberapp.test', 'Barbería de demostración para pruebas de BARBERAPP.', $estado, $marketplace ? 1 : 0, $propietario, $estado, $estado, $estado]
        );
    }

    private function membresia(int $tenant, int $usuario, string $rol): void
    {
        $this->exec(
            "INSERT INTO membresias (tenant_id, usuario_id, rol_id, estado, aceptado_en) VALUES (?, ?, ?, 'activo', UTC_TIMESTAMP())",
            [$tenant, $usuario, $this->roles[$rol]]
        );
    }

    private function suscripcion(int $tenant, int $plan, string $estado, string $precio, DateTimeImmutable $inicio, DateTimeImmutable $fin): int
    {
        return $this->insert(
            'INSERT INTO suscripciones (tenant_id, plan_id, estado, precio_pactado, inicio_en, periodo_fin_en, prueba_fin_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenant, $plan, $estado, $precio, $inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), $estado === 'prueba' ? $fin->format('Y-m-d H:i:s') : null]
        );
    }

    private function sucursal(int $tenant, string $nombre, string $slug, string $direccion, string $distrito, bool $principal, ?float $lat = null, ?float $lng = null): int
    {
        return $this->insert(
            'INSERT INTO sucursales (tenant_id, nombre, slug, direccion, distrito, es_principal, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenant, $nombre, $slug, $direccion, $distrito, $principal ? 1 : 0, $lat, $lng]
        );
    }

    private function servicio(int $tenant, ?int $categoria, string $nombre, int $min, string $precio, int $orden, bool $activo = true, ?string $tipo = null): int
    {
        $tipoId = $tipo === null ? null : $this->db->query('SELECT id FROM tipos_servicio WHERE codigo = ' . $this->db->quote($tipo))->fetchColumn();
        return $this->insert(
            'INSERT INTO servicios (tenant_id, categoria_id, tipo_servicio_id, nombre, duracion_minutos, precio, orden, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenant, $categoria, $tipoId === false ? null : $tipoId, $nombre, $min, $precio, $orden, $activo ? 1 : 0]
        );
    }

    private function barbero(int $tenant, ?int $usuario, int $sucursal, string $nombre, int $orden): int
    {
        return $this->insert(
            'INSERT INTO barberos (tenant_id, usuario_id, sucursal_id, nombre_publico, orden) VALUES (?, ?, ?, ?, ?)',
            [$tenant, $usuario, $sucursal, $nombre, $orden]
        );
    }

    /**
     * @param list<array{0:int, 1:string, 2:int, 3:string}> $servicios [id, nombre, minutos, precio]
     */
    private function reserva(int $tenant, int $sucursal, int $barbero, int $cliente, string $estado, string $inicioLocal, array $servicios, string $canal, int $actor): int
    {
        $inicio = new DateTimeImmutable($inicioLocal, new DateTimeZone(self::TZ));
        $total = array_sum(array_column($servicios, 2));
        $precio = number_format(array_sum(array_map(static fn (array $s): float => (float) $s[3], $servicios)), 2, '.', '');
        $fin = $inicio->modify("+{$total} minutes");

        $id = $this->insert(
            "INSERT INTO reservas (tenant_id, codigo, sucursal_id, barbero_id, cliente_id, estado, inicio_utc, fin_utc, zona_horaria,
                                   duracion_total_minutos, precio_total, canal, confirmada_en, cancelada_en, cancelada_por, motivo_cancelacion,
                                   atencion_iniciada_en, atencion_finalizada_en, creado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $tenant, Codes::reserva(), $sucursal, $barbero, $cliente, $estado, $this->utc($inicio), $this->utc($fin), self::TZ,
                $total, $precio, $canal,
                in_array($estado, ['confirmada', 'completada'], true) ? gmdate('Y-m-d H:i:s') : null,
                $estado === 'cancelada' ? gmdate('Y-m-d H:i:s') : null,
                $estado === 'cancelada' ? $actor : null,
                $estado === 'cancelada' ? 'Cancelada por el cliente (demo)' : null,
                $estado === 'completada' ? $this->utc($inicio) : null,
                $estado === 'completada' ? $this->utc($fin) : null,
                $actor,
            ]
        );

        $cursor = $inicio;
        foreach ($servicios as $i => [$servicioId, $nombre, $min, $precioServ]) {
            $finServ = $cursor->modify("+{$min} minutes");
            $this->exec(
                'INSERT INTO reserva_servicios (tenant_id, reserva_id, servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenant, $id, $servicioId, $i + 1, $nombre, $min, $precioServ, $this->utc($cursor), $this->utc($finServ)]
            );
            $cursor = $finServ;
        }

        $actorTipo = $canal === 'panel' ? 'personal' : 'cliente';
        $this->exec(
            "INSERT INTO reserva_eventos (tenant_id, reserva_id, tipo, estado_nuevo, inicio_nuevo_utc, fin_nuevo_utc, barbero_nuevo_id, actor_tipo, actor_usuario_id)
             VALUES (?, ?, 'creada', 'pendiente', ?, ?, ?, ?, ?)",
            [$tenant, $id, $this->utc($inicio), $this->utc($fin), $barbero, $actorTipo, $actor]
        );
        if ($estado !== 'pendiente') {
            $tipo = $estado === 'confirmada' ? 'confirmada' : ($estado === 'completada' ? 'completada' : ($estado === 'cancelada' ? 'cancelada' : 'no_asistio'));
            $this->exec(
                "INSERT INTO reserva_eventos (tenant_id, reserva_id, tipo, estado_anterior, estado_nuevo, actor_tipo, actor_usuario_id)
                 VALUES (?, ?, ?, 'pendiente', ?, ?, ?)",
                [$tenant, $id, $tipo, $estado, $estado === 'completada' ? 'personal' : $actorTipo, $actor]
            );
        }

        $this->exec(
            'UPDATE cliente_tenants SET total_reservas = total_reservas + 1 WHERE tenant_id = ? AND cliente_id = ?',
            [$tenant, $cliente]
        );
        return $id;
    }

    private function utc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Próximo día de lunes a sábado (fecha local de Lima). */
    private function proximoDiaLaborable(): string
    {
        $d = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('+1 day');
        if ((int) $d->format('N') === 7) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }
}
