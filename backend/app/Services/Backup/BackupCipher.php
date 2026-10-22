<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Cifrado de copias de seguridad con AES-256-GCM por bloques de 1 MiB (sin cargar el archivo
 * completo en memoria). Formato:
 *
 *   "BBK1" | por bloque: [1 byte: 1 si es el último] [4 bytes: largo] [12 bytes: IV] [16 bytes: tag] [datos]
 *
 * El número de bloque y la marca de último bloque van en los datos autenticados: no se pueden
 * reordenar, quitar ni truncar bloques sin que el descifrado falle.
 */
final class BackupCipher
{
    public const MAGIC = 'BBK1';
    private const CHUNK = 1048576;

    public static function parseKey(string $raw): string
    {
        $raw = trim($raw);
        $key = str_starts_with($raw, 'base64:') ? (string) base64_decode(substr($raw, 7), true) : '';
        if (strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_KEY inválida: debe ser base64:… de 32 bytes (php bin/console backup:clave).');
        }
        return $key;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function isEncrypted(string $file): bool
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = fread($h, 4);
        fclose($h);
        return $magic === self::MAGIC;
    }

    public static function encryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'xb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('No se pudo abrir el archivo para cifrar.');
        }
        fwrite($dst, self::MAGIC);
        $index = 0;
        $chunk = (string) fread($src, self::CHUNK);
        while (true) {
            $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
            $last = $next === '' && feof($src);
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($index, $last), 16);
            if ($ct === false) {
                throw new RuntimeException('Falló el cifrado de la copia.');
            }
            fwrite($dst, ($last ? "\x01" : "\x00") . pack('N', strlen($ct)) . $iv . $tag . $ct);
            if ($last) {
                break;
            }
            $chunk = $next;
            $index++;
        }
        fclose($src);
        fclose($dst);
    }

    public static function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        if ($src === false || fread($src, 4) !== self::MAGIC) {
            throw new RuntimeException('El archivo no es una copia cifrada de BARBERAPP.');
        }
        $dst = fopen($out, 'xb');
        if ($dst === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }
        $index = 0;
        $done = false;
        try {
            while (!$done) {
                $head = fread($src, 33);
                if ($head === false || strlen($head) !== 33) {
                    throw new RuntimeException('La copia está incompleta o dañada.');
                }
                $last = $head[0] === "\x01";
                $len = unpack('N', substr($head, 1, 4))[1];
                if ($len > self::CHUNK + 16) {
                    throw new RuntimeException('La copia está dañada.');
                }
                $ct = $len > 0 ? (string) fread($src, $len) : '';
                if (strlen($ct) !== $len) {
                    throw new RuntimeException('La copia está incompleta.');
                }
                $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($head, 5, 12), substr($head, 17, 16), self::aad($index, $last));
                if ($plain === false) {
                    throw new RuntimeException('No se pudo descifrar la copia: la clave no corresponde o el archivo fue alterado.');
                }
                fwrite($dst, $plain);
                $done = $last;
                $index++;
            }
            if (fread($src, 1) !== '') {
                throw new RuntimeException('La copia tiene datos de más después del último bloque.');
            }
        } catch (\Throwable $e) {
            fclose($dst);
            @unlink($out);
            throw $e;
        } finally {
            fclose($src);
        }
        fclose($dst);
    }

    private static function aad(int $index, bool $last): string
    {
        return self::MAGIC . pack('N', $index) . ($last ? "\x01" : "\x00");
    }
}
