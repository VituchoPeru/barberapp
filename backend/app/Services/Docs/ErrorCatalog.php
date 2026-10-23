<?php

declare(strict_types=1);

namespace App\Services\Docs;

/**
 * Catálogo de códigos de error de la API, leído del código fuente (HttpException y
 * Response::error con código literal). Lo usan la documentación OpenAPI y docs/API.md.
 */
final class ErrorCatalog
{
    /** Códigos que lanzan las excepciones base del núcleo. */
    private const BASE = [
        'validation_failed' => [422, 'Los datos enviados no son válidos. details.fields trae los mensajes por campo.'],
        'not_found' => [404, 'Recurso no encontrado (o no pertenece a tu cuenta o barbería).'],
        'forbidden' => [403, 'No tienes permiso para esta acción.'],
        'method_not_allowed' => [405, 'La ruta existe con otro método (cabecera Allow).'],
        'internal_error' => [500, 'Error inesperado. El request_id permite ubicarlo en los registros.'],
        'unauthenticated' => [401, 'Falta la sesión, venció o el token no es válido: inicia sesión de nuevo.'],
    ];

    /**
     * @return array<string, array{estados: list<int>, mensaje: string, archivos: list<string>}> código => datos, ordenado
     */
    public static function scan(string $appDir): array
    {
        $out = [];
        foreach (self::BASE as $code => [$status, $msg]) {
            $out[$code] = ['estados' => [$status], 'mensaje' => $msg, 'archivos' => ['app/Core']];
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        $re = "/(?:HttpException|Response::error)\\(\\s*(\\d{3}),\\s*'([a-z_]+)'(?:,\\s*(['\"])((?:(?!\\3).)*)\\3(\\s*\\.)?)?/s";
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
                continue;
            }
            $rel = 'app/' . ltrim(str_replace('\\', '/', substr($file, strlen(rtrim($appDir, '/')))), '/');
            foreach ($m as $hit) {
                $status = (int) $hit[1];
                $code = $hit[2];
                $msg = isset($hit[4]) ? self::cleanMessage($hit[4]) : '';
                if ($msg !== '' && !empty($hit[5])) {
                    $msg .= ' …'; // el mensaje sigue con texto armado en tiempo de ejecución
                }
                if (!isset($out[$code])) {
                    $out[$code] = ['estados' => [], 'mensaje' => $msg, 'archivos' => []];
                }
                if (!in_array($status, $out[$code]['estados'], true)) {
                    $out[$code]['estados'][] = $status;
                    sort($out[$code]['estados']);
                }
                if ($out[$code]['mensaje'] === '' && $msg !== '') {
                    $out[$code]['mensaje'] = $msg;
                }
                if (!in_array($rel, $out[$code]['archivos'], true)) {
                    $out[$code]['archivos'][] = $rel;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** Tabla Markdown para docs/API.md. */
    public static function markdown(array $catalog): string
    {
        $md = "| Código | HTTP | Mensaje de referencia |\n|---|---|---|\n";
        foreach ($catalog as $code => $c) {
            $msg = str_replace('|', '\\|', $c['mensaje'] !== '' ? $c['mensaje'] : '(mensaje armado en tiempo de ejecución)');
            $md .= '| `' . $code . '` | ' . implode(', ', $c['estados']) . ' | ' . $msg . " |\n";
        }
        return $md;
    }

    private static function cleanMessage(string $m): string
    {
        $m = preg_replace('/\{\$[^}]*\}|\$[a-zA-Z_]+(->[a-zA-Z_]+|\[[^\]]*\])*/', '…', $m) ?? $m;
        $m = str_replace(["\\'", '\\"'], ["'", '"'], $m);
        return trim($m);
    }
}
