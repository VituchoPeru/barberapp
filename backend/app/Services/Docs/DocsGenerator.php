<?php

declare(strict_types=1);

namespace App\Services\Docs;

use App\Core\Router;
use PDO;
use RuntimeException;

/**
 * Arma todos los documentos que se generan desde el código y la base (Fase 16):
 *
 *   docs/api/openapi.json        rutas reales + fichas de docs/fuente/api
 *   docs/API.md                  bloques "endpoints" y "errores"
 *   docs/DICCIONARIO_DATOS.md    information_schema
 *   docs/MODELO_ER.md            bloque "diagramas" (Mermaid por dominio)
 *   docs/MATRIZ_PRUEBAS.md       docs/fuente/matriz-pruebas.php + archivos de pruebas
 *
 * `php bin/console docs:generar` los escribe; `docs:verificar` (y DocumentationTest) falla si
 * alguno quedó desactualizado o si hay problemas (ruta sin documentar, prueba citada que no existe…).
 */
final class DocsGenerator
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(private string $root, private PDO $pdo)
    {
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, string> ruta relativa al repositorio => contenido esperado */
    public function build(): array
    {
        $backend = $this->root . '/backend';
        $router = new Router();
        (require $backend . '/routes/api.php')($router);

        $api = new OpenApiBuilder($router, $this->root . '/docs/fuente/api', $backend . '/app');
        $spec = $api->build();
        $this->problems = array_merge($this->problems, $api->problems());

        $dict = new DataDictionary($this->pdo);
        $this->problems = array_merge($this->problems, $dict->problems());

        $matrix = new TestMatrix($this->root);
        $matrixMd = $matrix->markdown();
        $this->problems = array_merge($this->problems, $matrix->problems());

        $out = [
            'docs/api/openapi.json' => OpenApiBuilder::json($spec),
            'docs/DICCIONARIO_DATOS.md' => $dict->markdown(),
            'docs/MATRIZ_PRUEBAS.md' => $matrixMd,
        ];
        $out['docs/MODELO_ER.md'] = $this->replaceBlocks('docs/MODELO_ER.md', [
            'resumen' => $dict->count() . ' tablas en ' . count(DataDictionary::DOMINIOS) . ' dominios (más la tabla técnica `migraciones`).',
            'diagramas' => $dict->erDiagrams(),
        ]);
        $out['docs/API.md'] = $this->replaceBlocks('docs/API.md', [
            'endpoints' => self::endpointTable($spec),
            'errores' => ErrorCatalog::markdown($api->errorCatalog()),
        ]);
        return $out;
    }

    /** @return list<string> archivos cuyo contenido difiere del generado */
    public function stale(): array
    {
        $stale = [];
        foreach ($this->build() as $path => $content) {
            if (!is_file($this->root . '/' . $path) || file_get_contents($this->root . '/' . $path) !== $content) {
                $stale[] = $path;
            }
        }
        return $stale;
    }

    /** @return list<string> archivos escritos */
    public function write(): array
    {
        $written = [];
        foreach ($this->build() as $path => $content) {
            $file = $this->root . '/' . $path;
            if (!is_file($file) || file_get_contents($file) !== $content) {
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0775, true);
                }
                file_put_contents($file, $content);
                $written[] = $path;
            }
        }
        return $written;
    }

    /**
     * Reemplaza el contenido entre <!-- generado:nombre --> y <!-- /generado:nombre -->.
     *
     * @param array<string, string> $blocks
     */
    public function replaceBlocks(string $path, array $blocks): string
    {
        $file = $this->root . '/' . $path;
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        foreach ($blocks as $name => $content) {
            $open = "<!-- generado:{$name} -->";
            $close = "<!-- /generado:{$name} -->";
            $a = strpos($text, $open);
            $b = strpos($text, $close);
            if ($a === false || $b === false || $b < $a) {
                throw new RuntimeException("{$path} no tiene el bloque {$open} … {$close}.");
            }
            $text = substr($text, 0, $a + strlen($open)) . "\n" . rtrim($content) . "\n" . substr($text, $b);
        }
        return $text;
    }

    /** Tabla Markdown de endpoints por etiqueta. */
    private static function endpointTable(array $spec): string
    {
        $byTag = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                $byTag[$op['tags'][0]][] = [$method, $path, $op];
            }
        }
        $md = '';
        foreach ($spec['tags'] as $tag) {
            $rows = $byTag[$tag['name']] ?? [];
            $md .= "### {$tag['name']}\n\n{$tag['description']}\n\n| Método | Ruta | Qué hace | Acceso |\n|---|---|---|---|\n";
            foreach ($rows as [$method, $path, $op]) {
                $md .= '| ' . strtoupper($method) . " | `{$path}` | " . str_replace('|', '\\|', $op['summary']) . ' | ' . self::access($op) . " |\n";
            }
            $md .= "\n";
        }
        return $md;
    }

    private static function access(array $op): string
    {
        if (($op['security'] ?? null) === []) {
            return 'pública';
        }
        $parts = [];
        foreach ((array) ($op['security'] ?? []) as $req) {
            if ($req instanceof \stdClass) {
                $parts[] = 'sesión opcional';
                break;
            }
            if (isset($req['tokenMonitoreo'])) {
                return 'MONITOR_TOKEN';
            }
        }
        if (!empty($op['x-plataforma'])) {
            $parts[] = 'plataforma';
        }
        foreach ((array) ($op['x-permisos'] ?? []) as $g) {
            $parts[] = implode($g['modo'] === 'alguno' ? ' o ' : ' y ', array_map(static fn (string $p): string => "`{$p}`", $g['permisos']));
        }
        if (!empty($op['x-requiere-correo-verificado'])) {
            $parts[] = 'correo verificado';
        }
        return $parts === [] ? 'sesión' : implode(' + ', $parts);
    }
}
