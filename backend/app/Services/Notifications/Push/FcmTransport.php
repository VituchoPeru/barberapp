<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

use App\Helpers\Tokens;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging, API HTTP v1 (la API "legacy" con server key fue retirada por Google).
 *
 * Autenticación con una cuenta de servicio de Firebase (JSON descargado de la consola):
 *  1. JWT RS256 firmado con la clave privada de la cuenta (openssl, sin dependencias de Composer);
 *  2. se canjea en oauth2.googleapis.com por un access token de 1 hora (scope firebase.messaging);
 *  3. el access token se guarda en caché (memoria + archivo con permisos 0600) hasta 60 s antes de vencer.
 * Envío: POST {endpoint}/v1/projects/{project_id}/messages:send con un mensaje por token.
 *
 * Clasificación de errores (https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode):
 *   UNREGISTERED / 404, SENDER_ID_MISMATCH, INVALID_ARGUMENT sobre el token → token inválido
 *   401 (token OAuth vencido), 403, 429, 5xx, errores de red                → reintentar
 *   otro INVALID_ARGUMENT                                                  → fallo permanente
 */
final class FcmTransport implements PushTransport
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var callable(string, string, array<int, string>, string, int): array{status:int, body:string} */
    private $http;
    /** @var array{client_email:string, private_key:string, project_id:string, token_uri:string}|null */
    private ?array $credentials = null;
    private ?string $accessToken = null;
    private int $accessTokenExp = 0;

    /**
     * @param array<string, mixed> $cfg credentials (ruta del JSON), project_id, endpoint, timeout, cache_path
     * @param callable|null        $http solo pruebas: reemplaza la llamada HTTP real
     */
    public function __construct(private array $cfg, ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'request'];
    }

    public function name(): string
    {
        return 'fcm';
    }

    public function send(string $token, string $plataforma, array $message): PushResult
    {
        try {
            $creds = $this->credentials();
            $access = $this->accessToken();
        } catch (Throwable $e) {
            return new PushResult(PushResult::RETRY, 'fcm_config', mb_substr($e->getMessage(), 0, 500));
        }
        $project = (string) ($this->cfg['project_id'] ?? '') ?: $creds['project_id'];
        $url = rtrim((string) ($this->cfg['endpoint'] ?? 'https://fcm.googleapis.com'), '/') . '/v1/projects/' . rawurlencode($project) . '/messages:send';
        $body = (string) json_encode(['message' => self::message($token, $plataforma, $message)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $res = ($this->http)('POST', $url, ['Authorization: Bearer ' . $access, 'Content-Type: application/json; charset=UTF-8'], $body, (int) ($this->cfg['timeout'] ?? 10));
        } catch (Throwable $e) {
            return new PushResult(PushResult::RETRY, 'network_error', mb_substr($e->getMessage(), 0, 500));
        }
        if ($res['status'] === 401) {
            $this->forgetAccessToken();
        }
        return self::classify($res['status'], $res['body']);
    }

    /**
     * Mensaje FCM v1. Los valores de "data" deben ser strings.
     *
     * @param array{title:string, body:string, data:array<string,string>} $m
     * @return array<string, mixed>
     */
    public static function message(string $token, string $plataforma, array $m): array
    {
        $data = array_map('strval', $m['data']);
        $msg = [
            'token' => $token,
            'notification' => ['title' => $m['title'], 'body' => $m['body']],
            'data' => (object) $data,
            'android' => [
                'priority' => 'high',
                'notification' => ['channel_id' => 'citas', 'tag' => $data['tag'] ?? null],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => array_filter([
                    'sound' => 'default',
                    // Categoría con botón "Confirmar asistencia" (la registra la app nativa en la Fase 12).
                    'category' => ($data['accion'] ?? '') === 'confirmar_asistencia' ? 'CITA_CONFIRMAR' : null,
                    'thread-id' => $data['tag'] ?? null,
                ])],
            ],
        ];
        if ($msg['android']['notification']['tag'] === null) {
            unset($msg['android']['notification']['tag']);
        }
        if ($plataforma === 'web' && isset($data['url'])) {
            $msg['webpush'] = ['fcm_options' => ['link' => $data['url']]];
        }
        return $msg;
    }

    public static function classify(int $status, string $body): PushResult
    {
        if ($status >= 200 && $status < 300) {
            $d = json_decode($body, true);
            return PushResult::ok(is_array($d) && isset($d['name']) ? 'ok' : 'http_' . $status);
        }
        $d = json_decode($body, true);
        $err = is_array($d) ? (array) ($d['error'] ?? []) : [];
        $message = mb_substr((string) ($err['message'] ?? $body), 0, 500);
        $fcmCode = null;
        foreach ((array) ($err['details'] ?? []) as $det) {
            if (is_array($det) && isset($det['errorCode'])) {
                $fcmCode = (string) $det['errorCode'];
            }
        }
        $code = $fcmCode ?? (string) ($err['status'] ?? 'http_' . $status);

        if ($fcmCode === 'UNREGISTERED' || $fcmCode === 'SENDER_ID_MISMATCH' || $status === 404) {
            return new PushResult(PushResult::INVALID_TOKEN, $code, $message);
        }
        if ($status === 400) {
            // INVALID_ARGUMENT también cubre un payload mal formado; solo se culpa al token si lo dice.
            return stripos($message, 'token') !== false
                ? new PushResult(PushResult::INVALID_TOKEN, $code, $message)
                : new PushResult(PushResult::FAILED, $code, $message);
        }
        if (in_array($status, [401, 403, 408, 429], true) || $status >= 500 || $status === 0) {
            return new PushResult(PushResult::RETRY, $code, $message);
        }
        return new PushResult(PushResult::FAILED, $code, $message);
    }

    /** JWT de la cuenta de servicio (RS256) para el canje OAuth 2.0. */
    public static function assertion(string $clientEmail, string $privateKey, string $tokenUri, int $now): string
    {
        $h = Tokens::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $p = Tokens::base64url((string) json_encode([
            'iss' => $clientEmail, 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || !openssl_sign($h . '.' . $p, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('La clave privada de la cuenta de servicio de Firebase no es válida.');
        }
        return $h . '.' . $p . '.' . Tokens::base64url($sig);
    }

    /** @return array{client_email:string, private_key:string, project_id:string, token_uri:string} */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        if (!function_exists('openssl_sign')) {
            throw new RuntimeException('La extensión openssl de PHP es necesaria para enviar push con FCM.');
        }
        $path = (string) ($this->cfg['credentials'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('FCM_CREDENTIALS no apunta a un archivo legible con la cuenta de servicio de Firebase.');
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || ($json['type'] ?? '') !== 'service_account' || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('El archivo de FCM_CREDENTIALS no es una cuenta de servicio de Firebase (type=service_account).');
        }
        $project = (string) ($this->cfg['project_id'] ?? '') ?: (string) ($json['project_id'] ?? '');
        if ($project === '') {
            throw new RuntimeException('Falta el project_id de Firebase (FCM_PROJECT_ID o project_id en el JSON).');
        }
        return $this->credentials = [
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'project_id' => $project,
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->accessToken !== null && $this->accessTokenExp - 60 > $now) {
            return $this->accessToken;
        }
        $cache = (string) ($this->cfg['cache_path'] ?? '');
        if ($cache !== '' && is_file($cache)) {
            $c = json_decode((string) @file_get_contents($cache), true);
            if (is_array($c) && isset($c['token'], $c['exp']) && (int) $c['exp'] - 60 > $now && ($c['iss'] ?? '') === $this->credentials()['client_email']) {
                $this->accessToken = (string) $c['token'];
                $this->accessTokenExp = (int) $c['exp'];
                return $this->accessToken;
            }
        }
        $creds = $this->credentials();
        $form = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => self::assertion($creds['client_email'], $creds['private_key'], $creds['token_uri'], $now),
        ]);
        $res = ($this->http)('POST', $creds['token_uri'], ['Content-Type: application/x-www-form-urlencoded'], $form, (int) ($this->cfg['timeout'] ?? 10));
        $d = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($d) || empty($d['access_token'])) {
            $why = is_array($d) ? (string) ($d['error_description'] ?? $d['error'] ?? '') : '';
            throw new RuntimeException("Google OAuth rechazó la cuenta de servicio (HTTP {$res['status']}" . ($why !== '' ? ": {$why}" : '') . ').');
        }
        $this->accessToken = (string) $d['access_token'];
        $this->accessTokenExp = $now + (int) ($d['expires_in'] ?? 3600);
        if ($cache !== '') {
            $dir = dirname($cache);
            if (is_dir($dir) || @mkdir($dir, 0700, true)) {
                @file_put_contents($cache, (string) json_encode(['token' => $this->accessToken, 'exp' => $this->accessTokenExp, 'iss' => $creds['client_email']]), LOCK_EX);
                @chmod($cache, 0600);
            }
        }
        return $this->accessToken;
    }

    private function forgetAccessToken(): void
    {
        $this->accessToken = null;
        $this->accessTokenExp = 0;
        $cache = (string) ($this->cfg['cache_path'] ?? '');
        if ($cache !== '' && is_file($cache)) {
            @unlink($cache);
        }
    }

    /**
     * Petición HTTP con streams de PHP (sin ext-curl). ignore_errors permite leer el cuerpo de un 4xx/5xx.
     *
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    public static function request(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $ctx = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        /** @var array<int, string> $http_response_header */
        $statusLine = isset($http_response_header) ? (string) ($http_response_header[0] ?? '') : '';
        if ($resp === false && $statusLine === '') {
            $err = error_get_last();
            throw new RuntimeException('Sin respuesta de ' . (string) parse_url($url, PHP_URL_HOST) . ($err ? ': ' . $err['message'] : ''));
        }
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) ? (int) $m[1] : 0;
        return ['status' => $status, 'body' => (string) $resp];
    }
}
