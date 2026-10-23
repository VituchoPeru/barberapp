<?php

declare(strict_types=1);

namespace Tests\Integration\Docs;

use App\Services\Docs\DocsGenerator;
use Tests\Integration\DatabaseTestCase;

/**
 * Fase 16: la documentación no se queda atrás del código.
 *
 *  - Lo generado (OpenAPI, diccionario, diagramas ER, endpoints, errores, matriz de pruebas)
 *    coincide con lo publicado en docs/ y no tiene problemas (ruta sin ficha, prueba citada
 *    que no existe…). Si falla: `php bin/console docs:generar` y revisar el diff.
 *  - Los enlaces relativos de los documentos apuntan a archivos que existen.
 *  - Cada comando de bin/console aparece en algún documento.
 *  - Existen los 15 entregables de la fase y el índice docs/README.md los enlaza.
 */
final class DocumentationTest extends DatabaseTestCase
{
    /** Entregables de la Fase 16 del prompt maestro. */
    public const ENTREGABLES = [
        'Manual de instalación' => 'docs/MANUAL_INSTALACION.md',
        'Manual del cliente' => 'docs/manuales/CLIENTE.md',
        'Manual del propietario' => 'docs/manuales/PROPIETARIO.md',
        'Manual del barbero' => 'docs/manuales/BARBERO.md',
        'Manual del superadmin' => 'docs/manuales/SUPERADMIN.md',
        'OpenAPI' => 'docs/api/openapi.json',
        'Diccionario de datos' => 'docs/DICCIONARIO_DATOS.md',
        'Diagrama ER' => 'docs/MODELO_ER.md',
        'Diagrama de arquitectura' => 'docs/ARQUITECTURA.md',
        'Guía de compilación Android' => 'docs/guias/ANDROID.md',
        'Guía de compilación iOS' => 'docs/guias/IOS.md',
        'Manual de despliegue' => 'docs/DESPLIEGUE.md',
        'Procedimiento de backups' => 'docs/BACKUPS.md',
        'Matriz de pruebas' => 'docs/MATRIZ_PRUEBAS.md',
        'Registro de decisiones técnicas' => 'docs/DECISIONES.md',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    public function testGeneratedDocumentationIsUpToDate(): void
    {
        $gen = new DocsGenerator(self::root(), $this->db);
        $stale = $gen->stale();
        $this->assertSame([], $gen->problems(), 'Problemas al generar la documentación.');
        $this->assertSame([], $stale, 'Documentación desactualizada: ejecuta php bin/console docs:generar.');
    }

    public function testOpenApiCoversEveryRoute(): void
    {
        $router = new \App\Core\Router();
        (require self::root() . '/backend/routes/api.php')($router);
        $spec = json_decode((string) file_get_contents(self::root() . '/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $spec['openapi']);

        $ops = 0;
        foreach ($spec['paths'] as $methods) {
            $ops += count($methods);
        }
        $this->assertSame(count($router->definitions()), $ops);
    }

    public function testRelativeLinksResolve(): void
    {
        $root = self::root();
        $files = array_merge([$root . '/README.md'], $this->markdownFiles($root . '/docs'));
        $broken = [];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $text = (string) preg_replace('/```.*?```/s', '', $text); // los bloques de código no son enlaces
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $m);
            foreach ($m[1] as $link) {
                if (preg_match('#^(https?:|mailto:|\#)#', $link)) {
                    continue;
                }
                $path = explode('#', $link, 2)[0];
                $target = dirname($file) . '/' . rawurldecode($path);
                if (!file_exists($target)) {
                    $broken[] = substr($file, strlen($root) + 1) . ' → ' . $link;
                }
            }
        }
        $this->assertSame([], $broken, 'Enlaces rotos en la documentación.');
    }

    public function testEveryConsoleCommandIsDocumented(): void
    {
        $root = self::root();
        preg_match_all("/^\s+case '([a-z:]+)':/m", (string) file_get_contents($root . '/backend/bin/console'), $m);
        $this->assertNotEmpty($m[1]);
        $docs = (string) file_get_contents($root . '/README.md');
        foreach ($this->markdownFiles($root . '/docs') as $f) {
            $docs .= file_get_contents($f);
        }
        $missing = array_values(array_filter($m[1], static fn (string $cmd): bool => !str_contains($docs, $cmd)));
        $this->assertSame([], $missing, 'Comandos de bin/console que no aparecen en ningún documento.');
    }

    public function testAllDeliverablesExistAndAreIndexed(): void
    {
        $root = self::root();
        $index = (string) @file_get_contents($root . '/docs/README.md');
        foreach (self::ENTREGABLES as $name => $path) {
            $this->assertFileExists($root . '/' . $path, $name);
            $this->assertGreaterThan(1500, filesize($root . '/' . $path), "{$name} está casi vacío.");
            $this->assertStringContainsString('](' . substr($path, 5) . ')', $index, "docs/README.md no enlaza {$name}.");
        }
    }

    /** @return list<string> */
    private function markdownFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.md')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}
