<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Core\Config;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Copias fuera del servidor (Fase 15).
 *
 *   push()  sube al bucket las copias cifradas que aún no están (con su .sha256), comprueba el
 *           tamaño de cada una tras subirla, aplica BACKUP_REMOTE_KEEP y sincroniza las fotos
 *           de las barberías (MEDIA_PATH/tenants): sube las nuevas o cambiadas y borra las que
 *           ya no existen en el servidor (si la clave tiene permiso de borrado).
 *   remote() lista las copias del bucket.
 *   pull()  baja una copia, verifica su SHA-256 y la deja junto a las locales, lista para
 *           backup:verificar o backup:restaurar (p. ej. en un servidor nuevo tras perder el anterior).
 *
 * Solo se suben copias cifradas: el bucket nunca ve datos personales legibles. La clave de
 * cifrado (BACKUP_KEY) no se sube: debe guardarse aparte, fuera del servidor y del bucket.
 *
 * Estructura en el bucket:  {prefijo}/db/barberapp-AAAAMMDD-HHMMSS.sql.gz.enc[.sha256]
 *                           {prefijo}/media/tenants/{id}/…
 */
final class OffsiteBackup
{
    private S3Client $s3;
    private BackupService $local;
    private string $prefix;

    public function __construct(?S3Client $client = null, ?BackupService $local = null, ?string $prefix = null)
    {
        $cfg = (array) Config::get('backup.remote', []);
        if ($client === null && !self::configured()) {
            throw new RuntimeException('Las copias fuera del servidor no están configuradas (BACKUP_S3_BUCKET, BACKUP_S3_KEY, BACKUP_S3_SECRET; ver docs/DESPLIEGUE.md).');
        }
        $this->s3 = $client ?? new S3Client($cfg);
        $this->local = $local ?? new BackupService();
        $this->prefix = trim($prefix ?? (string) ($cfg['prefix'] ?? 'barberapp'), '/');
    }

    public static function configured(): bool
    {
        $cfg = (array) Config::get('backup.remote', []);
        return ($cfg['bucket'] ?? '') !== '' && ($cfg['access_key'] ?? '') !== '' && ($cfg['secret_key'] ?? '') !== '';
    }

    /**
     * @return array{subidas: list<string>, ya_estaban: int, omitidas_sin_cifrar: list<string>, borradas: list<string>,
     *               media: array{subidas: int, ya_estaban: int, borradas: int, no_borradas: int, bytes: int}}
     */
    public function push(?bool $withMedia = null): array
    {
        $remote = [];
        foreach ($this->s3->list($this->prefix . '/db/') as $o) {
            $remote[$o['clave']] = $o['bytes'];
        }

        // Con BACKUP_REMOTE_KEEP solo se suben las copias que quedarían entre las más nuevas: una
        // copia local más antigua que todas las del bucket se borraría en seguida.
        $keep = (int) Config::get('backup.remote.keep', 0);
        $names = array_map('basename', array_keys($remote));
        foreach ($this->local->list() as $file) {
            $names[] = basename($file);
        }
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => str_ends_with($n, '.enc'))));
        rsort($names, SORT_STRING);
        $wanted = $keep > 0 ? array_flip(array_slice($names, 0, $keep)) : null;

        $uploaded = [];
        $skipped = [];
        $already = 0;
        foreach (array_reverse($this->local->list()) as $file) {
            $name = basename($file);
            if (!str_ends_with($name, '.enc')) {
                $skipped[] = $name;
                continue;
            }
            if ($wanted !== null && !isset($wanted[$name])) {
                continue;
            }
            $key = $this->prefix . '/db/' . $name;
            $size = (int) filesize($file);
            if (($remote[$key] ?? null) === $size && isset($remote[$key . '.sha256'])) {
                $already++;
                continue;
            }
            $sha = $this->localSha($file);
            $this->s3->put($key, $file, 'application/octet-stream', ['sha256' => $sha]);
            $head = $this->s3->head($key);
            if ($head === null || $head['bytes'] !== $size) {
                throw new RuntimeException("La copia {$name} quedó incompleta en el almacenamiento (esperado {$size} bytes).");
            }
            $shaFile = $this->tempFile($sha . '  ' . $name . "\n");
            try {
                $this->s3->put($key . '.sha256', $shaFile, 'text/plain');
            } finally {
                @unlink($shaFile);
            }
            $uploaded[] = $name;
        }

        $removed = $this->rotate();
        $media = ($withMedia ?? (bool) Config::get('backup.remote.media', true))
            ? $this->syncMedia()
            : ['subidas' => 0, 'ya_estaban' => 0, 'borradas' => 0, 'no_borradas' => 0, 'bytes' => 0];

        return ['subidas' => $uploaded, 'ya_estaban' => $already, 'omitidas_sin_cifrar' => $skipped, 'borradas' => $removed, 'media' => $media];
    }

    /** @return list<array{nombre: string, bytes: int, fecha: string}> de la más nueva a la más antigua */
    public function remote(): array
    {
        $out = [];
        foreach ($this->s3->list($this->prefix . '/db/') as $o) {
            $name = basename($o['clave']);
            if (preg_match('/^barberapp-\d{8}-\d{6}\.sql\.gz\.enc$/', $name)) {
                $out[] = ['nombre' => $name, 'bytes' => $o['bytes'], 'fecha' => $o['fecha']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['nombre'], $a['nombre']));
        return $out;
    }

    /** Baja una copia (la más reciente si $name es null) y verifica su SHA-256. Devuelve la ruta local. */
    public function pull(?string $name = null): string
    {
        if ($name === null || $name === '') {
            $list = $this->remote();
            if ($list === []) {
                throw new RuntimeException('No hay copias en el almacenamiento externo.');
            }
            $name = $list[0]['nombre'];
        }
        $name = basename($name);
        if (!preg_match('/^barberapp-\d{8}-\d{6}\.sql\.gz\.enc$/', $name)) {
            throw new RuntimeException('Nombre de copia inválido (esperado barberapp-AAAAMMDD-HHMMSS.sql.gz.enc).');
        }
        $dir = $this->local->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}.");
        }
        $dest = $dir . '/' . $name;
        if (file_exists($dest)) {
            throw new RuntimeException("Ya existe {$dest}: es la misma copia; úsala directamente o bórrala antes.");
        }
        $tmp = $dir . '/.descarga-' . bin2hex(random_bytes(6));
        $tmpSha = $tmp . '.sha256';
        try {
            $key = $this->prefix . '/db/' . $name;
            $this->s3->get($key . '.sha256', $tmpSha);
            $expected = strtolower((string) strtok((string) file_get_contents($tmpSha), " \n"));
            $this->s3->get($key, $tmp);
            $actual = (string) hash_file('sha256', $tmp);
            if (!hash_equals($expected, $actual)) {
                throw new RuntimeException("La copia descargada no coincide con su SHA-256 ({$actual} ≠ {$expected}). No se usará.");
            }
            rename($tmp, $dest);
            @chmod($dest, 0600);
            file_put_contents($dest . '.sha256', $actual . '  ' . $name . "\n");
            @chmod($dest . '.sha256', 0600);
        } finally {
            @unlink($tmp);
            @unlink($tmpSha);
        }
        return $dest;
    }

    /** @return list<string> copias borradas del bucket por exceder BACKUP_REMOTE_KEEP */
    private function rotate(): array
    {
        $keep = (int) Config::get('backup.remote.keep', 0);
        if ($keep <= 0) {
            return [];
        }
        $removed = [];
        foreach (array_slice($this->remote(), $keep) as $old) {
            $key = $this->prefix . '/db/' . $old['nombre'];
            $this->s3->delete($key);
            try {
                $this->s3->delete($key . '.sha256');
            } catch (S3Error $e) {
                // Si el .sha256 ya no estaba, no importa.
            }
            $removed[] = $old['nombre'];
        }
        return $removed;
    }

    /** @return array{subidas: int, ya_estaban: int, borradas: int, no_borradas: int, bytes: int} */
    private function syncMedia(): array
    {
        $stats = ['subidas' => 0, 'ya_estaban' => 0, 'borradas' => 0, 'no_borradas' => 0, 'bytes' => 0];
        $root = rtrim((string) Config::get('media.path'), '/\\');
        $base = $root . '/tenants';
        $prefix = $this->prefix . '/media/tenants/';

        $remote = [];
        foreach ($this->s3->list($prefix) as $o) {
            $remote[substr($o['clave'], strlen($prefix))] = $o['bytes'];
        }
        $local = [];
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                $local[$rel] = true;
                if (($remote[$rel] ?? null) === $f->getSize()) {
                    $stats['ya_estaban']++;
                    continue;
                }
                $this->s3->put($prefix . $rel, $f->getPathname(), self::mime($f->getFilename()));
                $stats['subidas']++;
                $stats['bytes'] += (int) $f->getSize();
            }
        }
        // Las fotos borradas en el servidor (p. ej. de un barbero que ya no está) también se
        // borran del bucket: son datos personales y no deben quedar fuera de la vista de la barbería.
        foreach (array_keys(array_diff_key($remote, $local)) as $rel) {
            try {
                $this->s3->delete($prefix . $rel);
                $stats['borradas']++;
            } catch (S3Error $e) {
                if ($e->getCode() !== 403) {
                    throw $e;
                }
                $stats['no_borradas']++;
            }
        }
        return $stats;
    }

    private function localSha(string $file): string
    {
        $shaFile = $file . '.sha256';
        if (is_file($shaFile)) {
            $saved = strtolower((string) strtok((string) file_get_contents($shaFile), " \n"));
            $actual = (string) hash_file('sha256', $file);
            if (!hash_equals($saved, $actual)) {
                throw new RuntimeException(basename($file) . ' no coincide con su .sha256: la copia local está dañada y no se sube.');
            }
            return $actual;
        }
        return (string) hash_file('sha256', $file);
    }

    private function tempFile(string $content): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'bbsha');
        file_put_contents($f, $content);
        return $f;
    }

    private static function mime(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
    }
}
