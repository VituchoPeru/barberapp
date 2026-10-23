<?php

declare(strict_types=1);

namespace App\Services\Docs;

use PDO;

/**
 * Diccionario de datos (docs/DICCIONARIO_DATOS.md) y diagramas entidad-relación
 * (bloque generado de docs/MODELO_ER.md), leídos de information_schema de la base migrada.
 */
final class DataDictionary
{
    /**
     * Dominio de cada tabla para agrupar los diagramas. Una tabla nueva sin dominio hace fallar
     * la generación: así el diagrama nunca queda incompleto.
     */
    public const DOMINIOS = [
        'Núcleo SaaS: barberías, sucursales, cuentas y roles' => [
            'tenants', 'sucursales', 'horarios_sucursales', 'usuarios', 'roles', 'permisos', 'rol_permisos', 'membresias',
        ],
        'Planes, suscripciones y cobros de la plataforma' => [
            'planes', 'suscripciones', 'suscripcion_eventos', 'pagos_saas', 'ordenes_pago',
        ],
        'Catálogo y agenda de la barbería' => [
            'categorias_servicio', 'tipos_servicio', 'servicios', 'barberos', 'barbero_servicios', 'horarios_barberos',
            'descansos_barberos', 'bloqueos_agenda', 'politicas_reserva', 'tenant_fotos',
        ],
        'Clientes y reservas' => [
            'clientes', 'cliente_tenants', 'favoritos', 'reservas', 'reserva_servicios', 'reserva_eventos', 'reserva_pagos', 'idempotency_keys',
        ],
        'Avisos y notificaciones' => [
            'notificaciones', 'notificacion_envios', 'plantillas_notificacion', 'preferencias_notificacion', 'dispositivos_push',
        ],
        'Seguridad, sesiones y sistema' => [
            'sesiones', 'tokens_refresco', 'tokens_usuario', 'limites_intentos', 'auditoria', 'configuraciones', 'webhook_eventos', 'solicitudes_datos',
        ],
    ];

    /** Tablas tan referenciadas que en los diagramas de otros dominios se omiten (se ven en el núcleo). */
    private const OMITIR_FUERA = ['tenants', 'usuarios'];

    /** @var list<array{t: string, c: string}> */
    private array $tables;

    public function __construct(private PDO $pdo)
    {
        $this->tables = $pdo->query(
            "SELECT table_name AS t, table_comment AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'migraciones' ORDER BY table_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> tablas sin dominio asignado o dominios con tablas que ya no existen */
    public function problems(): array
    {
        $assigned = array_merge(...array_values(self::DOMINIOS));
        $names = array_column($this->tables, 't');
        $out = [];
        foreach (array_diff($names, $assigned) as $t) {
            $out[] = "La tabla {$t} no tiene dominio en DataDictionary::DOMINIOS.";
        }
        foreach (array_diff($assigned, $names) as $t) {
            $out[] = "DataDictionary::DOMINIOS menciona {$t}, que no existe en la base.";
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->tables);
    }

    public function markdown(): string
    {
        $md = "# Diccionario de datos\n\n_Generado con `php bin/console docs:generar` a partir de la base de datos migrada. No editar a mano._\n\n";
        $md .= 'Convenciones: fechas de eventos en UTC; importes en DECIMAL(10,2); `tenant_id` identifica la barbería. '
            . count($this->tables) . " tablas (más la tabla técnica `migraciones`). Diagramas: [MODELO_ER.md](MODELO_ER.md).\n\n";
        $md .= "## Índice\n\n";
        foreach (self::DOMINIOS as $dominio => $tablas) {
            $md .= "- **{$dominio}:** " . implode(', ', array_map(static fn (string $t): string => "[`{$t}`](#{$t})", $tablas)) . "\n";
        }
        $md .= "\n";
        $esc = static fn ($v): string => str_replace(['|', "\n"], ['\\|', ' '], (string) $v);
        foreach ($this->tables as $t) {
            $md .= "## `{$t['t']}`\n\n" . ($t['c'] !== '' ? $esc($t['c']) . "\n\n" : '');
            $md .= "| Columna | Tipo | Nulo | Defecto | Clave | Descripción |\n|---|---|---|---|---|---|\n";
            foreach ($this->columns($t['t']) as $c) {
                $extra = $c['e'] !== '' ? ' ' . $c['e'] : '';
                $md .= sprintf("| `%s` | %s | %s | %s | %s | %s |\n", $c['n'], $esc($c['ty'] . $extra), $c['nu'] === 'YES' ? 'sí' : 'no',
                    $c['d'] === null ? '' : '`' . $esc($c['d']) . '`', $c['k'], $esc($c['c']));
            }
            $fks = $this->foreignKeys($t['t']);
            if ($fks !== []) {
                $md .= "\n**Claves foráneas:** ";
                $md .= implode('; ', array_map(static fn ($f) => "({$f['cols']}) → `{$f['rt']}`({$f['rcols']})", $fks)) . "\n";
            }
            $md .= "\n";
        }
        return $md;
    }

    /** Diagramas Mermaid por dominio (claves primarias, foráneas y únicas, estados y tipos). */
    public function erDiagrams(): string
    {
        $md = '';
        foreach (self::DOMINIOS as $dominio => $tablas) {
            $md .= "### {$dominio}\n\n```mermaid\nerDiagram\n";
            $external = [];
            $rels = [];
            foreach ($tablas as $t) {
                foreach ($this->foreignKeys($t) as $fk) {
                    $parent = (string) $fk['rt'];
                    if (!in_array($parent, $tablas, true)) {
                        if (in_array($parent, self::OMITIR_FUERA, true)) {
                            continue;
                        }
                        $external[$parent] = true;
                    }
                    $cols = explode(',', (string) $fk['cols']);
                    $own = array_values(array_diff($cols, ['tenant_id'])) ?: $cols; // en las FK compuestas, la columna propia
                    $nullable = $this->anyNullable($t, $own);
                    $unique = $this->isUniqueSet($t, $cols);
                    $left = $nullable ? '|o' : '||';
                    $right = $unique ? 'o|' : 'o{';
                    $rels[] = "    {$parent} {$left}--{$right} {$t} : \"" . implode(', ', $own) . '"';
                }
            }
            $md .= implode("\n", array_values(array_unique($rels))) . ($rels !== [] ? "\n" : '');
            foreach ($tablas as $t) {
                $md .= $this->entity($t);
            }
            foreach (array_keys($external) as $t) {
                $md .= "    {$t} {\n        bigint id PK\n    }\n";
            }
            $md .= "```\n\n";
        }
        return $md;
    }

    private function entity(string $table): string
    {
        $fkCols = [];
        foreach ($this->foreignKeys($table) as $fk) {
            foreach (explode(',', (string) $fk['cols']) as $c) {
                $fkCols[$c] = true;
            }
        }
        $out = "    {$table} {\n";
        foreach ($this->columns($table) as $c) {
            $keys = [];
            if ($c['k'] === 'PRI') {
                $keys[] = 'PK';
            }
            if (isset($fkCols[$c['n']])) {
                $keys[] = 'FK';
            }
            if ($c['k'] === 'UNI') {
                $keys[] = 'UK';
            }
            $isState = str_starts_with((string) $c['ty'], 'enum(') && in_array($c['n'], ['estado', 'tipo', 'canal', 'metodo', 'ambito', 'resultado'], true);
            if ($keys === [] && !$isState) {
                continue;
            }
            $type = (string) preg_replace('/[^a-z]/', '', strtolower((string) strtok((string) $c['ty'], '( ')));
            $comment = $isState ? ' "' . str_replace(['enum(', ')', "'", '"'], '', (string) $c['ty']) . '"' : '';
            $out .= "        {$type} {$c['n']}" . ($keys !== [] ? ' ' . implode(', ', $keys) : '') . "{$comment}\n";
        }
        return $out . "    }\n";
    }

    /** @var array<string, list<array<string, mixed>>> */
    private array $colCache = [];

    /** @return list<array<string, mixed>> */
    private function columns(string $table): array
    {
        if (!isset($this->colCache[$table])) {
            $stmt = $this->pdo->prepare(
                "SELECT column_name AS n, column_type AS ty, is_nullable AS nu, column_default AS d, column_key AS k, extra AS e, column_comment AS c
                   FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position"
            );
            $stmt->execute([$table]);
            $this->colCache[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->colCache[$table];
    }

    /** @var array<string, list<array<string, mixed>>> */
    private array $fkCache = [];

    /** @return list<array<string, mixed>> */
    private function foreignKeys(string $table): array
    {
        if (!isset($this->fkCache[$table])) {
            $stmt = $this->pdo->prepare(
                "SELECT constraint_name AS n, GROUP_CONCAT(column_name ORDER BY ordinal_position) AS cols, referenced_table_name AS rt,
                        GROUP_CONCAT(referenced_column_name ORDER BY ordinal_position) AS rcols
                   FROM information_schema.key_column_usage
                  WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_name IS NOT NULL
                  GROUP BY constraint_name, referenced_table_name ORDER BY constraint_name"
            );
            $stmt->execute([$table]);
            $this->fkCache[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->fkCache[$table];
    }

    /** @param list<string> $cols */
    private function anyNullable(string $table, array $cols): bool
    {
        foreach ($this->columns($table) as $c) {
            if (in_array($c['n'], $cols, true) && $c['nu'] === 'YES') {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $cols true si existe un índice único exactamente sobre esas columnas */
    private function isUniqueSet(string $table, array $cols): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 GROUP BY index_name"
        );
        $stmt->execute([$table]);
        $want = $cols;
        sort($want);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $have = explode(',', (string) $idx['cols']);
            sort($have);
            if ($have === $want) {
                return true;
            }
        }
        return false;
    }
}
