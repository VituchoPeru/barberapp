<?php

declare(strict_types=1);

namespace Tests\Integration\Ops;

use App\Services\Backup\S3Client;

/**
 * Almacenamiento S3 en memoria para las pruebas (se inyecta como transporte HTTP de S3Client).
 * Como S3 real: exige firma, rechaza un cuerpo cuyo SHA-256 no coincide con
 * x-amz-content-sha256, pagina las listas (de a 3 para probar la paginación) y puede negar el
 * borrado (clave sin ese permiso).
 */
final class FakeS3
{
    /** @var array<string, array{body: string, meta: array<string, string>, fecha: string}> */
    public array $objects = [];
    public bool $denyDelete = false;
    /** @var list<string> */
    public array $log = [];
    /** Si se define, corrompe el siguiente GET de esta clave (simula una copia dañada en el bucket). */
    public ?string $corruptNextGet = null;

    /** @param list<string> $headers @return array{status: int, headers: array<string, string>, body: string} */
    public function __invoke(string $method, string $url, array $headers, ?string $inFile, ?string $outFile, int $timeout): array
    {
        $h = [];
        foreach ($headers as $line) {
            [$n, $v] = explode(':', $line, 2);
            $h[strtolower(trim($n))] = trim($v);
        }
        if (!str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 Credential=')) {
            return $this->xml(403, 'AccessDenied');
        }
        $p = parse_url($url);
        $path = rawurldecode((string) ($p['path'] ?? '/'));
        parse_str((string) ($p['query'] ?? ''), $query);
        // Estilo de ruta: /{bucket}/{clave}
        $parts = explode('/', ltrim($path, '/'), 2);
        $key = $parts[1] ?? '';
        $this->log[] = $method . ' ' . $key;

        if ($method === 'PUT') {
            $body = (string) file_get_contents((string) $inFile);
            if (hash('sha256', $body) !== ($h['x-amz-content-sha256'] ?? '')) {
                return $this->xml(400, 'XAmzContentSHA256Mismatch');
            }
            $meta = [];
            foreach ($h as $n => $v) {
                if (str_starts_with($n, 'x-amz-meta-')) {
                    $meta[$n] = $v;
                }
            }
            $this->objects[$key] = ['body' => $body, 'meta' => $meta, 'fecha' => gmdate('Y-m-d\TH:i:s.000\Z')];
            return ['status' => 200, 'headers' => ['etag' => '"' . md5($body) . '"'], 'body' => ''];
        }
        if ($method === 'GET' && ($query['list-type'] ?? '') === '2') {
            $keys = array_values(array_filter(array_keys($this->objects), static fn (string $k): bool => str_starts_with($k, (string) ($query['prefix'] ?? ''))));
            sort($keys);
            $start = isset($query['continuation-token']) ? (int) $query['continuation-token'] : 0;
            $page = array_slice($keys, $start, 3);
            $more = $start + 3 < count($keys);
            $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><IsTruncated>' . ($more ? 'true' : 'false') . '</IsTruncated>';
            foreach ($page as $k) {
                $xml .= '<Contents><Key>' . htmlspecialchars($k, ENT_XML1) . '</Key><Size>' . strlen($this->objects[$k]['body']) . '</Size><LastModified>' . $this->objects[$k]['fecha'] . '</LastModified></Contents>';
            }
            $xml .= $more ? '<NextContinuationToken>' . ($start + 3) . '</NextContinuationToken>' : '';
            return ['status' => 200, 'headers' => [], 'body' => $xml . '</ListBucketResult>'];
        }
        if (!isset($this->objects[$key])) {
            return $method === 'DELETE' ? ['status' => 204, 'headers' => [], 'body' => ''] : $this->xml(404, 'NoSuchKey');
        }
        if ($method === 'HEAD') {
            return ['status' => 200, 'headers' => ['content-length' => (string) strlen($this->objects[$key]['body'])] + $this->objects[$key]['meta'], 'body' => ''];
        }
        if ($method === 'GET') {
            $body = $this->objects[$key]['body'];
            if ($this->corruptNextGet === $key) {
                $body[10] = $body[10] === 'x' ? 'y' : 'x';
                $this->corruptNextGet = null;
            }
            file_put_contents((string) $outFile, $body);
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }
        if ($method === 'DELETE') {
            if ($this->denyDelete) {
                return $this->xml(403, 'AccessDenied');
            }
            unset($this->objects[$key]);
            return ['status' => 204, 'headers' => [], 'body' => ''];
        }
        return $this->xml(405, 'MethodNotAllowed');
    }

    public function client(): S3Client
    {
        return new S3Client(['endpoint' => 'https://s3.prueba.local', 'bucket' => 'copias-barberapp', 'access_key' => 'AKIAPRUEBA',
            'secret_key' => 'secreto', 'path_style' => true, 'region' => 'us-east-1'], $this);
    }

    /** @return list<string> */
    public function keys(string $prefix = ''): array
    {
        $k = array_values(array_filter(array_keys($this->objects), static fn (string $x): bool => str_starts_with($x, $prefix)));
        sort($k);
        return $k;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function xml(int $status, string $code): array
    {
        return ['status' => $status, 'headers' => [], 'body' => "<?xml version=\"1.0\"?><Error><Code>{$code}</Code></Error>"];
    }
}
