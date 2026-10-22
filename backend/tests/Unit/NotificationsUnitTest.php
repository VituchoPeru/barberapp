<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Helpers\Tokens;
use App\Services\Notifications\AttendanceToken;
use App\Services\Notifications\EmailLayout;
use App\Services\Notifications\Push\FcmTransport;
use App\Services\Notifications\Push\PushResult;
use App\Services\Notifications\Templates;
use PHPUnit\Framework\TestCase;

/** Piezas de la Fase 8 que no necesitan base de datos: FCM HTTP v1, enlaces firmados y plantillas. */
final class NotificationsUnitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/barberapp-fcm-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    /** @return array{0:string, 1:\OpenSSLAsymmetricKey|resource} ruta del JSON de cuenta de servicio y clave pública */
    private function serviceAccount(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $path = $this->dir . '/sa.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account', 'project_id' => 'barberapp-test', 'private_key' => $pem,
            'client_email' => 'push@barberapp-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        return [$path, openssl_pkey_get_public(openssl_pkey_get_details($key)['key'])];
    }

    public function testFcmSendsWithAServiceAccountAndCachesTheAccessToken(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('Sin openssl.');
        }
        [$path, $pub] = $this->serviceAccount();
        $calls = [];
        $http = function (string $method, string $url, array $headers, string $body) use (&$calls, $pub): array {
            $calls[] = [$method, $url, $headers, $body];
            if (str_contains($url, 'oauth2')) {
                parse_str($body, $form);
                $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
                [$h, $p, $s] = explode('.', (string) $form['assertion']);
                $this->assertSame(1, openssl_verify($h . '.' . $p, (string) Tokens::base64urlDecode($s), $pub, OPENSSL_ALGO_SHA256), 'JWT RS256 firmado con la clave de la cuenta');
                $claims = json_decode((string) Tokens::base64urlDecode($p), true);
                $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
                $this->assertSame(3600, $claims['exp'] - $claims['iat']);
                return ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.prueba', 'expires_in' => 3599])];
            }
            return ['status' => 200, 'body' => json_encode(['name' => 'projects/barberapp-test/messages/1'])];
        };
        $fcm = new FcmTransport(['credentials' => $path, 'endpoint' => 'https://fcm.test', 'cache_path' => $this->dir . '/token.json'], $http);
        $msg = ['title' => 'Tu cita es mañana', 'body' => 'Barbería Demo', 'data' => ['reserva_id' => '5', 'accion' => 'confirmar_asistencia', 'tag' => 'reserva-5']];
        $this->assertSame(PushResult::OK, $fcm->send('tok-1', 'android', $msg)->status);
        $this->assertSame(PushResult::OK, $fcm->send('tok-2', 'ios', $msg)->status);

        $this->assertCount(3, $calls, 'Un solo canje OAuth para dos envíos');
        [$m, $url, $headers, $body] = $calls[1];
        $this->assertSame(['POST', 'https://fcm.test/v1/projects/barberapp-test/messages:send'], [$m, $url]);
        $this->assertContains('Authorization: Bearer ya29.prueba', $headers);
        $sent = json_decode($body, true)['message'];
        $this->assertSame('tok-1', $sent['token']);
        $this->assertSame(['title' => 'Tu cita es mañana', 'body' => 'Barbería Demo'], $sent['notification']);
        $this->assertSame('confirmar_asistencia', $sent['data']['accion']);
        $this->assertSame('high', $sent['android']['priority']);
        $this->assertSame('CITA_CONFIRMAR', json_decode($calls[2][3], true)['message']['apns']['payload']['aps']['category']);

        // Otro proceso reutiliza el access token de la caché (archivo 0600).
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/token.json')), -4));
        $calls = [];
        $fcm2 = new FcmTransport(['credentials' => $path, 'endpoint' => 'https://fcm.test', 'cache_path' => $this->dir . '/token.json'], $http);
        $fcm2->send('tok-3', 'android', $msg);
        $this->assertCount(1, $calls);
    }

    public function testFcmErrorsAreClassified(): void
    {
        $err = static fn (int $status, string $code, string $fcm = '', string $msg = 'x'): PushResult => FcmTransport::classify($status, (string) json_encode([
            'error' => ['code' => $status, 'status' => $code, 'message' => $msg, 'details' => $fcm !== '' ? [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $fcm]] : []],
        ]));
        $this->assertSame([PushResult::INVALID_TOKEN, 'UNREGISTERED'], [($r = $err(404, 'NOT_FOUND', 'UNREGISTERED'))->status, $r->code]);
        $this->assertSame(PushResult::INVALID_TOKEN, $err(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH')->status);
        $this->assertSame(PushResult::INVALID_TOKEN, $err(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token')->status);
        $this->assertSame(PushResult::FAILED, $err(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'Invalid JSON payload received.')->status);
        $this->assertSame(PushResult::RETRY, $err(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED')->status);
        $this->assertSame(PushResult::RETRY, $err(503, 'UNAVAILABLE', 'UNAVAILABLE')->status);
        $this->assertSame(PushResult::RETRY, $err(401, 'UNAUTHENTICATED', 'THIRD_PARTY_AUTH_ERROR')->status);
        $this->assertSame(PushResult::RETRY, FcmTransport::classify(502, '<html>Bad gateway</html>')->status);
    }

    public function testFcmWithoutCredentialsRetriesInsteadOfLosingTheNotification(): void
    {
        $fcm = new FcmTransport(['credentials' => $this->dir . '/no-existe.json'], static fn () => ['status' => 200, 'body' => '{}']);
        $r = $fcm->send('tok', 'android', ['title' => 't', 'body' => 'b', 'data' => []]);
        $this->assertSame([PushResult::RETRY, 'fcm_config'], [$r->status, $r->code]);
        $this->assertStringContainsString('FCM_CREDENTIALS', (string) $r->detail);
    }

    public function testExpiredOauthTokenIsDiscardedOn401(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('Sin openssl.');
        }
        [$path] = $this->serviceAccount();
        $oauth = 0;
        $http = static function (string $method, string $url) use (&$oauth): array {
            if (str_contains($url, 'oauth2')) {
                $oauth++;
                return ['status' => 200, 'body' => json_encode(['access_token' => "t{$oauth}", 'expires_in' => 3600])];
            }
            return $oauth === 1 ? ['status' => 401, 'body' => '{"error":{"status":"UNAUTHENTICATED","message":"expired"}}'] : ['status' => 200, 'body' => '{"name":"m"}'];
        };
        $fcm = new FcmTransport(['credentials' => $path, 'cache_path' => $this->dir . '/token.json'], $http);
        $msg = ['title' => 't', 'body' => 'b', 'data' => []];
        $this->assertSame(PushResult::RETRY, $fcm->send('tok', 'android', $msg)->status);
        $this->assertSame(PushResult::OK, $fcm->send('tok', 'android', $msg)->status);
        $this->assertSame(2, $oauth, 'Tras un 401 se pide un access token nuevo');
    }

    public function testAttendanceTokensAreSignedAndBoundToTheBookingTime(): void
    {
        Config::set('auth.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $t = AttendanceToken::issue(3, 42, '2026-11-02 15:00:00');
        $this->assertSame(['tenant_id' => 3, 'reserva_id' => 42, 'inicio_ts' => strtotime('2026-11-02 15:00:00 UTC')], AttendanceToken::parse($t));
        [$p, $s] = explode('.', $t);
        $otro = Tokens::base64url((string) json_encode(['t' => 3, 'r' => 43, 'i' => strtotime('2026-11-02 15:00:00 UTC')]));
        $this->assertNull(AttendanceToken::parse($otro . '.' . $s), 'Cambiar la reserva invalida la firma');
        $this->assertNull(AttendanceToken::parse($p . '.' . substr($s, 1)));
        $this->assertNull(AttendanceToken::parse('a.b.c'));
        $this->assertNull(AttendanceToken::parse(str_repeat('a', 400)));
        Config::set('auth.key', 'base64:' . base64_encode(str_repeat('z', 32)));
        $this->assertNull(AttendanceToken::parse($t), 'Otra APP_KEY, otra firma');
        Config::set('auth.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    public function testTemplatesFillVariablesAndEmailEscapesHtml(): void
    {
        $this->assertSame('Hola Ana, tu cita es mañana.', Templates::fill('Hola {{cliente}}, tu cita es {{ cuando }}.{{desconocida}}', ['cliente' => 'Ana', 'cuando' => 'mañana']));
        $this->assertSame("Línea 1\n\nLínea 2", Templates::fill("Línea 1\n\n\n\n{{motivo}}\nLínea 2", ['motivo' => '']));
        $html = EmailLayout::render('Cita <b>', "Hola <script>alert(1)</script>\n- Barbero: \"Ana\"", 'Ver', 'https://app.test/?a=1&b=2', 'Nota');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('https://app.test/?a=1&amp;b=2', $html);
        $this->assertStringContainsString('• Barbero: &quot;Ana&quot;', $html);
    }
}
