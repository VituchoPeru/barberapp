<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;

/**
 * Plantillas de avisos (tabla plantillas_notificacion). Se busca la plantilla activa de la
 * barbería y, si no tiene, la global de la plataforma; si tampoco existe (instalación sin
 * `db:seed`), se usa el texto por defecto de DEFAULTS, que es el mismo que siembra el catálogo.
 * Variables con sintaxis {{variable}}; el resultado es texto plano (el HTML del correo se
 * arma aparte y escapa cada valor).
 */
final class Templates
{
    public const VARIABLES = ['barberia', 'sucursal', 'direccion', 'barbero', 'cliente', 'fecha', 'hora', 'cuando', 'servicios', 'codigo', 'accion', 'motivo'];

    /** Avisos de la plataforma al propietario sobre su barbería (Fase 9): solo estas variables. */
    public const VARIABLES_CUENTA = ['barberia', 'motivo'];

    /** Nombre legible de cada aviso (panel del superadministrador). */
    public const NOMBRES = [
        'reserva_confirmada' => 'Reserva confirmada',
        'reserva_recibida' => 'Reserva recibida (por confirmar)',
        'recordatorio' => 'Recordatorio de cita',
        'reserva_cancelada' => 'Cita cancelada por la barbería',
        'reserva_modificada' => 'Cita modificada por la barbería',
        'barberia_aprobada' => 'Barbería aprobada',
        'barberia_rechazada' => 'Solicitud de barbería rechazada',
        'barberia_suspendida' => 'Barbería suspendida',
        'barberia_reactivada' => 'Barbería reactivada',
    ];

    /** codigo → canal → [asunto/título, cuerpo] */
    public const DEFAULTS = [
        'reserva_confirmada' => [
            'push' => ['Reserva confirmada', 'Tu cita en {{barberia}} con {{barbero}} es el {{fecha}} a las {{hora}}. Código {{codigo}}.'],
            'email' => ['Tu cita en {{barberia}} está confirmada',
                "Hola {{cliente}},\n\nTu cita quedó confirmada:\n- Fecha: {{fecha}}\n- Hora: {{hora}}\n- Barbero: {{barbero}}\n- Servicios: {{servicios}}\n- Sede: {{sucursal}}, {{direccion}}\n- Código: {{codigo}}\n\nTe enviaremos un recordatorio antes de la cita."],
        ],
        'reserva_recibida' => [
            'push' => ['Reserva recibida', '{{barberia}} recibió tu solicitud para el {{fecha}} a las {{hora}} y la confirmará pronto. Código {{codigo}}.'],
            'email' => ['{{barberia}} recibió tu reserva',
                "Hola {{cliente}},\n\n{{barberia}} recibió tu solicitud y la confirmará pronto:\n- Fecha: {{fecha}}\n- Hora: {{hora}}\n- Barbero: {{barbero}}\n- Servicios: {{servicios}}\n- Sede: {{sucursal}}, {{direccion}}\n- Código: {{codigo}}"],
        ],
        'recordatorio' => [
            'push' => ['Tu cita es {{cuando}}', '{{barberia}} · {{sucursal}} con {{barbero}}. {{accion}}'],
            'email' => ['Recordatorio: tu cita en {{barberia}} es {{cuando}}',
                "Hola {{cliente}},\n\nTe recordamos tu cita:\n- Fecha: {{fecha}}\n- Hora: {{hora}}\n- Barbero: {{barbero}}\n- Servicios: {{servicios}}\n- Sede: {{sucursal}}, {{direccion}}\n- Código: {{codigo}}\n\n{{accion}}"],
        ],
        'reserva_cancelada' => [
            'push' => ['Cita cancelada', '{{barberia}} canceló tu cita del {{fecha}} a las {{hora}}. {{motivo}}'],
            'email' => ['{{barberia}} canceló tu cita',
                "Hola {{cliente}},\n\n{{barberia}} canceló tu cita del {{fecha}} a las {{hora}} (código {{codigo}}).\n{{motivo}}\n\nPuedes reservar otro horario cuando quieras desde BARBERAPP."],
        ],
        'reserva_modificada' => [
            'push' => ['Tu cita cambió', '{{barberia}} cambió tu cita: ahora es el {{fecha}} a las {{hora}} con {{barbero}}.'],
            'email' => ['{{barberia}} cambió tu cita',
                "Hola {{cliente}},\n\n{{barberia}} cambió tu cita. Estos son los nuevos datos:\n- Fecha: {{fecha}}\n- Hora: {{hora}}\n- Barbero: {{barbero}}\n- Sede: {{sucursal}}, {{direccion}}\n- Código: {{codigo}}\n\nSi el nuevo horario no te funciona, puedes reprogramar o cancelar desde BARBERAPP."],
        ],
        // ---------- Avisos de la plataforma al propietario (Fase 9)
        'barberia_aprobada' => [
            'push' => ['Tu barbería fue aprobada', '{{barberia}} ya está activa en BARBERAPP. Completa tu perfil y publícala en el marketplace.'],
            'email' => ['{{barberia}} ya está activa en BARBERAPP',
                "Hola,\n\nRevisamos y aprobamos {{barberia}}. Ya puedes recibir reservas.\n\nSiguientes pasos: completa tus servicios, barberos y horarios, y publica tu perfil en el marketplace desde el panel."],
        ],
        'barberia_rechazada' => [
            'push' => ['Solicitud no aprobada', 'No pudimos aprobar {{barberia}}. {{motivo}}'],
            'email' => ['No pudimos aprobar {{barberia}}',
                "Hola,\n\nRevisamos la solicitud de {{barberia}} y por ahora no pudimos aprobarla.\nMotivo: {{motivo}}\n\nSi crees que es un error, responde a este correo o escríbenos a soporte."],
        ],
        'barberia_suspendida' => [
            'push' => ['Barbería suspendida', '{{barberia}} fue suspendida y no recibe nuevas reservas. {{motivo}}'],
            'email' => ['{{barberia}} fue suspendida',
                "Hola,\n\n{{barberia}} fue suspendida en BARBERAPP y no recibe nuevas reservas mientras dure la suspensión.\nMotivo: {{motivo}}\n\nTus datos se conservan. Escríbenos a soporte para resolverlo."],
        ],
        'barberia_reactivada' => [
            'push' => ['Barbería reactivada', '{{barberia}} vuelve a estar activa y puede recibir reservas.'],
            'email' => ['{{barberia}} vuelve a estar activa',
                "Hola,\n\n{{barberia}} vuelve a estar activa en BARBERAPP y puede recibir reservas de nuevo."],
        ],
    ];

    /** @return list<string> variables que admite la plantilla */
    public static function variablesFor(string $codigo): array
    {
        return str_starts_with($codigo, 'barberia_') ? self::VARIABLES_CUENTA : self::VARIABLES;
    }

    /** @var array<string, array{0:string, 1:string}|null> */
    private static array $cache = [];

    /**
     * @param array<string, string> $vars
     * @return array{0:string, 1:string} [título o asunto, cuerpo]
     */
    public static function render(string $codigo, string $canal, ?int $tenantId, array $vars): array
    {
        [$titulo, $cuerpo] = self::find($codigo, $canal, $tenantId);
        return [self::fill($titulo, $vars), self::fill($cuerpo, $vars)];
    }

    /** @return array{0:string, 1:string} */
    public static function find(string $codigo, string $canal, ?int $tenantId): array
    {
        $key = "{$codigo}|{$canal}|" . ($tenantId ?? 0);
        if (!array_key_exists($key, self::$cache)) {
            $stmt = Database::connection()->prepare(
                "SELECT asunto, cuerpo FROM plantillas_notificacion
                  WHERE codigo = ? AND canal = ? AND idioma = 'es-PE' AND activo = 1 AND (tenant_id IS NULL OR tenant_id = ?)
                  ORDER BY tenant_id IS NULL LIMIT 1"
            );
            $stmt->execute([$codigo, $canal, $tenantId ?? 0]);
            $row = $stmt->fetch();
            self::$cache[$key] = $row ? [(string) ($row['asunto'] ?? ''), (string) $row['cuerpo']] : null;
        }
        $found = self::$cache[$key];
        if ($found !== null && $found[0] !== '') {
            return $found;
        }
        $def = self::DEFAULTS[$codigo][$canal] ?? self::DEFAULTS[$codigo]['push'] ?? ['BARBERAPP', ''];
        return $found !== null ? [$def[0], $found[1]] : $def;
    }

    /** @param array<string, string> $vars */
    public static function fill(string $text, array $vars): string
    {
        $out = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn (array $m): string => (string) ($vars[$m[1]] ?? ''), $text);
        // Una variable vacía al final de una línea no deja espacios ni puntos colgando.
        $out = (string) preg_replace('/[ \t]+$/m', '', $out);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $out));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
