<?php

declare(strict_types=1);

namespace App\Services\Docs;

/**
 * docs/MATRIZ_PRUEBAS.md: requisitos del prompt maestro → pruebas que los verifican
 * (docs/fuente/matriz-pruebas.php) más el inventario de todas las pruebas, leído de los archivos.
 */
final class TestMatrix
{
    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, array{archivo: string, suite: string, grupos: list<string>, metodos: list<string>}> */
    private array $classes = [];

    public function __construct(private string $root)
    {
        foreach (['Unit', 'Integration'] as $suite) {
            $dir = $root . '/backend/tests/' . $suite;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            $files = [];
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
                    $files[] = $f->getPathname();
                }
            }
            sort($files);
            foreach ($files as $file) {
                $src = (string) file_get_contents($file);
                $rel = substr($file, strlen($dir) + 1, -4); // Booking/BookingApiTest
                preg_match_all('/public function (test[A-Za-z0-9_]+)\s*\(/', $src, $m);
                preg_match_all('/@group\s+([a-z]+)/', $src, $g);
                $this->classes[$rel] = [
                    'archivo' => 'backend/tests/' . $suite . '/' . $rel . '.php',
                    'suite' => $suite === 'Unit' ? 'Unitarias' : 'Integración',
                    'grupos' => array_values(array_unique($g[1])),
                    'metodos' => $m[1],
                ];
            }
        }
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (array $c): int => count($c['metodos']), $this->classes));
    }

    public function markdown(): string
    {
        $matrix = require $this->root . '/docs/fuente/matriz-pruebas.php';
        $md = "# Matriz de pruebas\n\n";
        $md .= "_Generado con `php bin/console docs:generar` desde `docs/fuente/matriz-pruebas.php` y los archivos de pruebas. No editar a mano._\n\n";
        $md .= "Cada requisito del prompt maestro con las pruebas automáticas que lo verifican. Cómo correrlas, la integración continua y lo que no cubren: [PRUEBAS.md](PRUEBAS.md). "
            . 'Si una prueba citada se renombra o se borra, `docs:generar` falla y `DocumentationTest` queda en rojo.' . "\n\n";
        $md .= '**Inventario:** ' . $this->total() . ' pruebas PHPUnit en ' . count($this->classes) . ' clases, más los E2E de Playwright (cada pantalla con cada rol y el flujo de reserva, en 9 dispositivos y navegadores).' . "\n\n";

        foreach ($matrix as $section) {
            $md .= "## {$section['titulo']}\n\n| Requisito | Pruebas |\n|---|---|\n";
            foreach ($section['filas'] as $req => $refs) {
                $md .= '| ' . $req . ' | ' . implode('<br>', array_map(fn (string $r): string => $this->ref($r, $req), $refs)) . " |\n";
            }
            $md .= "\n";
        }

        $md .= "## Inventario de pruebas PHPUnit\n\n";
        $md .= "Grupos: `seguridad` y `concurrencia` (`vendor/bin/phpunit --group seguridad`).\n\n";
        foreach (['Unitarias', 'Integración'] as $suite) {
            $rows = array_filter($this->classes, static fn (array $c): bool => $c['suite'] === $suite);
            $n = array_sum(array_map(static fn (array $c): int => count($c['metodos']), $rows));
            $md .= "### {$suite} ({$n})\n\n| Clase | Pruebas | Métodos |\n|---|---|---|\n";
            foreach ($rows as $name => $c) {
                $grupos = $c['grupos'] !== [] ? ' _(' . implode(', ', $c['grupos']) . ')_' : '';
                $md .= "| [`{$name}`](../{$c['archivo']}){$grupos} | " . count($c['metodos']) . ' | '
                    . implode(', ', array_map(static fn (string $t): string => '`' . substr($t, 4) . '`', $c['metodos'])) . " |\n";
            }
            $md .= "\n";
        }
        return $md;
    }

    private function ref(string $ref, string $req): string
    {
        if (str_starts_with($ref, 'ci:')) {
            $job = substr($ref, 3);
            $yml = (string) @file_get_contents($this->root . '/.github/workflows/calidad.yml');
            if (!preg_match('/^  ' . preg_quote($job, '/') . ':$/m', $yml)) {
                $this->problems[] = "Matriz «{$req}»: el trabajo de CI {$job} no existe en calidad.yml.";
            }
            return "CI: trabajo `{$job}` de [calidad.yml](../.github/workflows/calidad.yml)";
        }
        if (str_starts_with($ref, 'e2e:')) {
            [$file, $title] = array_pad(explode(':', substr($ref, 4), 2), 2, null);
            $path = $this->root . '/frontend/e2e/' . $file;
            $src = is_file($path) ? (string) file_get_contents($path) : null;
            if ($src === null) {
                $this->problems[] = "Matriz «{$req}»: no existe frontend/e2e/{$file}.";
            } elseif ($title !== null && !str_contains($src, "test('{$title}'")) {
                $this->problems[] = "Matriz «{$req}»: {$file} no tiene la prueba «{$title}».";
            }
            return "E2E [`{$file}`](../frontend/e2e/{$file})" . ($title !== null ? ": {$title}" : ' (todas)');
        }
        [$class, $method] = array_pad(explode('::', $ref, 2), 2, null);
        $c = $this->classes[$class] ?? null;
        if ($c === null) {
            $this->problems[] = "Matriz «{$req}»: no existe la clase de prueba {$class}.";
            return "`{$ref}`";
        }
        if ($method !== null && !in_array($method, $c['metodos'], true)) {
            $this->problems[] = "Matriz «{$req}»: {$class} no tiene {$method}.";
        }
        return $method === null
            ? "[`{$class}`](../{$c['archivo']}) (" . count($c['metodos']) . ' pruebas)'
            : "[`{$class}`](../{$c['archivo']})::`" . substr($method, 4) . '`';
    }
}
