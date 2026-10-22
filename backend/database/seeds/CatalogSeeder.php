<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Services\Notifications\Templates;
use PDO;

/**
 * Datos de catálogo necesarios en TODOS los entornos (incluida producción).
 * Idempotente: puede ejecutarse varias veces sin duplicar registros.
 */
final class CatalogSeeder
{
    public const ROLES = [
        ['superadmin', 'Superadministrador', 'Operador de la plataforma BARBERAPP', 'plataforma'],
        ['propietario', 'Propietario de barbería', 'Dueño del negocio; control total de su barbería', 'tenant'],
        ['administrador', 'Administrador de barbería', 'Gestiona agenda, clientes y operación diaria', 'tenant'],
        ['barbero', 'Barbero', 'Atiende citas y gestiona su propia agenda', 'tenant'],
        ['cliente', 'Cliente', 'Reserva y gestiona sus citas', 'cliente'],
    ];

    /** codigo => [modulo, descripcion] */
    public const PERMISOS = [
        // Plataforma
        'plataforma.tenants.gestionar' => ['plataforma', 'Aprobar, activar o suspender barberías'],
        'plataforma.planes.gestionar' => ['plataforma', 'Crear y modificar planes SaaS'],
        'plataforma.suscripciones.gestionar' => ['plataforma', 'Administrar suscripciones'],
        'plataforma.pagos.gestionar' => ['plataforma', 'Registrar y consultar pagos SaaS'],
        'plataforma.reportes.ver' => ['plataforma', 'Indicadores globales e ingresos de la plataforma'],
        'plataforma.auditoria.ver' => ['plataforma', 'Consultar registros de auditoría'],
        'plataforma.configuracion.gestionar' => ['plataforma', 'Parámetros generales de la plataforma'],
        'plataforma.plantillas.gestionar' => ['plataforma', 'Plantillas de notificación globales'],
        'plataforma.usuarios.gestionar' => ['plataforma', 'Usuarios de plataforma'],
        // Barbería
        'negocio.perfil.gestionar' => ['negocio', 'Perfil comercial, logotipo, colores'],
        'negocio.suscripcion.ver' => ['negocio', 'Consultar la suscripción de la barbería'],
        'sucursales.gestionar' => ['sucursales', 'Registrar y editar sucursales'],
        'personal.gestionar' => ['personal', 'Invitar personal y asignar roles'],
        'barberos.gestionar' => ['barberos', 'Registrar, editar y activar barberos'],
        'servicios.gestionar' => ['servicios', 'Crear servicios, precios y duraciones'],
        'horarios.gestionar' => ['horarios', 'Configurar horarios de cualquier barbero'],
        'horarios.propios' => ['horarios', 'Configurar disponibilidad propia (si está habilitado)'],
        'bloqueos.gestionar' => ['bloqueos', 'Crear y aprobar bloqueos de agenda'],
        'bloqueos.solicitar' => ['bloqueos', 'Solicitar bloqueos de la agenda propia'],
        'politicas.gestionar' => ['politicas', 'Políticas de reserva y cancelación'],
        'reservas.ver_todas' => ['reservas', 'Ver la agenda de toda la barbería'],
        'reservas.ver_propias' => ['reservas', 'Ver la agenda propia'],
        'reservas.crear' => ['reservas', 'Registrar reservas desde el panel'],
        'reservas.reprogramar' => ['reservas', 'Reprogramar citas'],
        'reservas.cancelar' => ['reservas', 'Cancelar reservas'],
        'reservas.asistencia' => ['reservas', 'Registrar inicio, fin, asistencia o inasistencia'],
        'reservas.cobrar' => ['reservas', 'Registrar y anular cobros de citas'],
        'clientes.ver' => ['clientes', 'Consultar clientes e historial'],
        'clientes.notas' => ['clientes', 'Registrar observaciones autorizadas'],
        'reportes.operativos' => ['reportes', 'Reportes operativos'],
        'reportes.financieros' => ['reportes', 'Reportes de ingresos'],
        'notificaciones.gestionar' => ['notificaciones', 'Reglas de recordatorios y avisos a clientes'],
        // Cliente
        'cliente.reservar' => ['cliente', 'Crear reservas'],
        'cliente.reservas.gestionar' => ['cliente', 'Cancelar, reprogramar y confirmar asistencia'],
        'cliente.favoritos' => ['cliente', 'Gestionar barberías favoritas'],
        'cliente.perfil' => ['cliente', 'Administrar perfil y preferencias'],
    ];

    public const ROL_PERMISOS = [
        'superadmin' => ['plataforma.*'],
        'propietario' => [
            'negocio.*', 'sucursales.*', 'personal.*', 'barberos.*', 'servicios.*', 'horarios.gestionar',
            'bloqueos.gestionar', 'politicas.*', 'reservas.*', 'clientes.*', 'reportes.*', 'notificaciones.*',
        ],
        'administrador' => [
            'reservas.*', 'clientes.*', 'servicios.gestionar', 'horarios.gestionar', 'bloqueos.gestionar',
            'reportes.operativos', 'notificaciones.gestionar',
        ],
        'barbero' => ['reservas.ver_propias', 'reservas.asistencia', 'bloqueos.solicitar', 'horarios.propios'],
        'cliente' => ['cliente.*'],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function run(): void
    {
        $this->db->beginTransaction();
        try {
            $this->roles();
            $this->permisos();
            $this->rolPermisos();
            $this->planes();
            $this->plantillas();
            $this->configuraciones();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function roles(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, ambito) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), ambito = VALUES(ambito)'
        );
        foreach (self::ROLES as $rol) {
            $stmt->execute($rol);
        }
    }

    private function permisos(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO permisos (codigo, modulo, descripcion) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), descripcion = VALUES(descripcion)'
        );
        foreach (self::PERMISOS as $codigo => [$modulo, $descripcion]) {
            $stmt->execute([$codigo, $modulo, $descripcion]);
        }
    }

    private function rolPermisos(): void
    {
        $roles = $this->db->query('SELECT codigo, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        $permisos = $this->db->query('SELECT codigo, id FROM permisos')->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmt = $this->db->prepare('INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)');

        foreach (self::ROL_PERMISOS as $rol => $patterns) {
            foreach (array_keys($permisos) as $codigo) {
                foreach ($patterns as $pattern) {
                    if (fnmatch($pattern, (string) $codigo)) {
                        $stmt->execute([$roles[$rol], $permisos[$codigo]]);
                        break;
                    }
                }
            }
        }
    }

    private function planes(): void
    {
        // Precios referenciales del documento de negocio; editables desde el panel SaaS (Fase 10).
        $planes = [
            ['basico', 'Básico', 'Para barberías que inician: una sucursal y equipo pequeño.', '39.00', '390.00', 14, 1, 3,
                ['reportes_avanzados' => false, 'personalizacion' => false, 'canales' => ['push', 'interna']], 1],
            ['profesional', 'Profesional', 'Para barberías en crecimiento con más barberos.', '79.00', '790.00', 14, 2, 10,
                ['reportes_avanzados' => true, 'personalizacion' => true, 'canales' => ['push', 'interna', 'email']], 2],
            ['premium', 'Premium', 'Para cadenas con varias sucursales.', '149.00', '1490.00', 14, null, null,
                ['reportes_avanzados' => true, 'personalizacion' => true, 'canales' => ['push', 'interna', 'email', 'whatsapp']], 3],
        ];
        $stmt = $this->db->prepare(
            'INSERT INTO planes (codigo, nombre, descripcion, precio_mensual, precio_anual, dias_prueba, max_sucursales, max_barberos, caracteristicas, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)'
        );
        foreach ($planes as $p) {
            $p[8] = json_encode($p[8], JSON_UNESCAPED_UNICODE);
            $stmt->execute($p);
        }
    }

    private function plantillas(): void
    {
        // Textos por defecto de la Fase 8 (los mismos que usa Templates si falta la fila).
        $vars = json_encode(Templates::VARIABLES);
        $stmt = $this->db->prepare(
            "INSERT INTO plantillas_notificacion (tenant_id, codigo, canal, idioma, asunto, cuerpo, variables)
             VALUES (NULL, ?, ?, 'es-PE', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        );
        foreach (Templates::DEFAULTS as $codigo => $canales) {
            foreach ($canales as $canal => [$asunto, $cuerpo]) {
                $stmt->execute([$codigo, $canal, $asunto, $cuerpo, $vars]);
            }
        }
    }

    private function configuraciones(): void
    {
        $config = [
            ['plataforma.nombre', 'BARBERAPP', 'Nombre comercial de la plataforma', 1],
            ['plataforma.moneda', 'PEN', 'Moneda por defecto', 1],
            ['plataforma.zona_horaria', 'America/Lima', 'Zona horaria por defecto para nuevas barberías', 1],
            ['plataforma.aprobacion_manual_tenants', true, 'Las nuevas barberías requieren aprobación del superadministrador', 0],
            ['notificaciones.recordatorios_horas', [24, 2], 'Horas antes de la cita para enviar recordatorios', 0],
            ['notificaciones.reintentos_max', 5, 'Reintentos máximos de envío', 0],
        ];
        $stmt = $this->db->prepare(
            'INSERT INTO configuraciones (tenant_id, clave, valor, descripcion, es_publica)
             VALUES (NULL, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        foreach ($config as [$clave, $valor, $desc, $publica]) {
            $stmt->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE), $desc, $publica]);
        }
    }
}
