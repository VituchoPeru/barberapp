<?php

declare(strict_types=1);

namespace App\Services\Backup;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Cliente mínimo de almacenamiento compatible con S3 (Fase 15), sin SDK: firma AWS Signature
 * Version 4 y las cinco operaciones que necesitan las copias fuera del servidor (subir, bajar,
 * consultar, listar y borrar). Funciona con Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean
 * Spaces, Wasabi y MinIO.
 *
 * Cada subida envía el SHA-256 real del archivo en x-amz-content-sha256: el proveedor rechaza el
 * objeto si llega distinto, así que una copia corrupta en el camino nunca queda guardada.
 *
 * Con la extensión curl los archivos se suben y bajan por partes (no se cargan en memoria); sin
 * ella se usa el cliente HTTP de PHP, que lee el archivo completo.
 */
final class S3Client
{
    public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private bool $pathStyle;
    private int $timeout;

    /** @var callable */
    private $http;

    /**
     * @param array<string, mixed> $cfg  endpoint, region, bucket, access_key, secret_key, path_style, timeout
     * @param callable|null        $http solo pruebas: fn(string $method, string $url, array $headers, ?string $inFile, ?string $outFile, int $timeout): array{status:int, headers:array<string,string>, body:string}
     */
    public function __construct(array $cfg, ?callable $http = null)
    {
        $this->endpoint = rtrim((string) ($cfg['endpoint'] ?? ''), '/');
        $this->bucket = (string) ($cfg['bucket'] ?? '');
        $this->region = (string) (($cfg['region'] ?? '') ?: 'us-east-1');
        $this->accessKey = (string) ($cfg['access_key'] ?? '');
        $this->secretKey = (string) ($cfg['secret_key'] ?? '');
        $this->pathStyle = (bool) ($cfg['path_style'] ?? false);
        $this->timeout = max(10, (int) ($cfg['timeout'] ?? 120));
        if ($this->endpoint === '') {
            $this->endpoint = 'https://s3.' . $this->region . '.amazonaws.com';
        }
        if (!preg_match('#^https?://[^/\s]+$#', $this->endpoint)) {
            throw new RuntimeException('BACKUP_S3_ENDPOINT debe ser una URL como https://s3.us-east-1.amazonaws.com (sin ruta).');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket)) {
            throw new RuntimeException('BACKUP_S3_BUCKET no es un nombre de bucket válido.');
        }
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException('Faltan BACKUP_S3_KEY o BACKUP_S3_SECRET.');
        }
        $this->http = $http ?? (extension_loaded('curl') ? [self::class, 'curlRequest'] : [self::class, 'streamRequest']);
    }

    // ================================================================== operaciones

    /** Sube un archivo. $meta se guarda como x-amz-meta-{clave}. */
    public function put(string $key, string $file, string $contentType = 'application/octet-stream', array $meta = []): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("No se puede leer {$file}.");
        }
        $headers = ['content-type' => $contentType, 'content-length' => (string) filesize($file)];
        foreach ($meta as $k => $v) {
            $headers['x-amz-meta-' . strtolower((string) $k)] = (string) $v;
        }
        $res = $this->request('PUT', $key, [], $headers, (string) hash_file('sha256', $file), $file);
        $this->expect($res, [200], "subir {$key}");
    }

    /** Descarga un objeto a $dest. */
    public function get(string $key, string $dest): void
    {
        $res = $this->request('GET', $key, [], [], self::EMPTY_SHA256, null, $dest);
        if ($res['status'] !== 200) {
            @unlink($dest);
        }
        $this->expect($res, [200], "descargar {$key}");
    }

    /** Datos de un objeto, o null si no existe. @return array{bytes: int, etag: string, meta: array<string, string>}|null */
    public function head(string $key): ?array
    {
        $res = $this->request('HEAD', $key, [], [], self::EMPTY_SHA256);
        if ($res['status'] === 404) {
            return null;
        }
        $this->expect($res, [200], "consultar {$key}");
        $meta = [];
        foreach ($res['headers'] as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $meta[substr($name, 11)] = $value;
            }
        }
        return ['bytes' => (int) ($res['headers']['content-length'] ?? 0), 'etag' => trim((string) ($res['headers']['etag'] ?? ''), '"'), 'meta' => $meta];
    }

    /**
     * Lista los objetos bajo un prefijo (ListObjectsV2, todas las páginas).
     *
     * @return list<array{clave: string, bytes: int, fecha: string}>
     */
    public function list(string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $res = $this->request('GET', '', $query, [], self::EMPTY_SHA256);
            $this->expect($res, [200], 'listar el bucket');
            $xml = @simplexml_load_string($res['body']);
            if ($xml === false) {
                throw new RuntimeException('El almacenamiento devolvió una lista ilegible.');
            }
            foreach ($xml->Contents as $c) {
                $out[] = ['clave' => (string) $c->Key, 'bytes' => (int) $c->Size, 'fecha' => (string) $c->LastModified];
            }
            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
        return $out;
    }

    public function delete(string $key): void
    {
        $res = $this->request('DELETE', $key, [], [], self::EMPTY_SHA256);
        $this->expect($res, [200, 204], "borrar {$key}");
    }

    // ================================================================== firma

    /**
     * Cabecera Authorization de AWS Signature Version 4.
     *
     * @param array<string, string> $headers cabeceras que se firman (incluida host); nombres en cualquier caja
     * @param array<string, string> $query
     */
    public static function authorization(
        string $method,
        string $canonicalUri,
        array $query,
        array $headers,
        string $payloadHash,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service,
        DateTimeImmutable $at
    ): string {
        $amzDate = $at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $day = substr($amzDate, 0, 8);

        $canonHeaders = [];
        foreach ($headers as $name => $value) {
            $canonHeaders[strtolower(trim((string) $name))] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        }
        ksort($canonHeaders, SORT_STRING);
        $signed = implode(';', array_keys($canonHeaders));
        $headerBlock = '';
        foreach ($canonHeaders as $name => $value) {
            $headerBlock .= $name . ':' . $value . "\n";
        }

        $canonical = implode("\n", [strtoupper($method), $canonicalUri, self::canonicalQuery($query), $headerBlock, $signed, $payloadHash]);
        $scope = "{$day}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);

        $k = hash_hmac('sha256', $day, 'AWS4' . $secretKey, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $stringToSign, $k);

        return "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signed}, Signature={$signature}";
    }

    /** @param array<string, string> $query */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($pairs, SORT_STRING);
        $parts = [];
        foreach ($pairs as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
    }

    /** Codifica cada segmento de la clave una sola vez (regla de S3) y conserva las barras. */
    public static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    // ================================================================== HTTP

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $key, array $query, array $headers, string $payloadHash, ?string $inFile = null, ?string $outFile = null): array
    {
        $parts = parse_url($this->endpoint);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $encodedKey = self::encodeKey($key);
        if ($this->pathStyle) {
            $hostHeader = $host . $port;
            $uri = '/' . $this->bucket . ($key !== '' ? '/' . $encodedKey : '/');
        } else {
            $hostHeader = $this->bucket . '.' . $host . $port;
            $uri = '/' . $encodedKey;
        }
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $signedHeaders = ['host' => $hostHeader, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $at->format('Ymd\THis\Z')];
        foreach ($headers as $name => $value) {
            if ($name !== 'content-length') {
                $signedHeaders[$name] = $value;
            }
        }
        $auth = self::authorization($method, $uri, $query, $signedHeaders, $payloadHash, $this->accessKey, $this->secretKey, $this->region, 's3', $at);

        $lines = ['Authorization: ' . $auth];
        foreach ($signedHeaders + $headers as $name => $value) {
            if ($name !== 'host') {
                $lines[] = $name . ': ' . $value;
            }
        }
        $url = $scheme . '://' . $hostHeader . $uri . ($query !== [] ? '?' . self::canonicalQuery($query) : '');
        return ($this->http)($method, $url, $lines, $inFile, $outFile, $this->timeout);
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $res @param list<int> $ok */
    private function expect(array $res, array $ok, string $what): void
    {
        if (in_array($res['status'], $ok, true)) {
            return;
        }
        $code = '';
        if (preg_match('#<Code>([^<]+)</Code>#', $res['body'], $m)) {
            $code = ' ' . $m[1];
        }
        throw new S3Error("No se pudo {$what}: HTTP {$res['status']}{$code}.", $res['status']);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function curlRequest(string $method, string $url, array $headers, ?string $inFile, ?string $outFile, int $timeout): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        $in = null;
        $out = null;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($n))] = trim($v);
                } elseif (preg_match('#^HTTP/#', $line)) {
                    $respHeaders = [];
                }
                return strlen($line);
            },
        ]);
        if ($inFile !== null) {
            $in = fopen($inFile, 'rb');
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => (int) filesize($inFile)]);
        }
        $body = '';
        if ($outFile !== null) {
            // Se escribe al archivo solo si la respuesta es 200; un error se guarda en memoria.
            $out = fopen($outFile, 'wb');
            $status = 0;
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($c, string $chunk) use (&$body, &$status, $out): int {
                $status = $status ?: (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
                if ($status === 200) {
                    return (int) fwrite($out, $chunk);
                }
                $body .= $chunk;
                return strlen($chunk);
            });
            curl_exec($ch);
        } else {
            $body = (string) curl_exec($ch);
        }
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if ($status === 0) {
            throw new S3Error('Sin conexión con el almacenamiento: ' . ($error ?: 'error desconocido') . '.', 0);
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function streamRequest(string $method, string $url, array $headers, ?string $inFile, ?string $outFile, int $timeout): array
    {
        $content = $inFile !== null ? (string) file_get_contents($inFile) : '';
        if ($inFile !== null) {
            $headers[] = 'Content-Length: ' . strlen($content);
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
            'timeout' => $timeout, 'ignore_errors' => true, 'protocol_version' => 1.1,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        /** @var array<int, string> $http_response_header */
        $raw = $http_response_header ?? [];
        if ($raw === [] && $resp === false) {
            throw new S3Error('Sin conexión con el almacenamiento: ' . (error_get_last()['message'] ?? 'error desconocido') . '.', 0);
        }
        $status = 0;
        $respHeaders = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $respHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($n))] = trim($v);
            }
        }
        $body = (string) $resp;
        if ($outFile !== null && $status === 200) {
            file_put_contents($outFile, $body);
            $body = '';
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }
}
