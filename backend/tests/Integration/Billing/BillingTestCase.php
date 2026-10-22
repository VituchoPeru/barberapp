<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Core\Config;
use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Billing\Entitlements;
use App\Services\Notifications\Templates;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Auth\ApiTestCase;

/** Utilidades para las pruebas de planes y monetización (Fase 10). */
abstract class BillingTestCase extends ApiTestCase
{
    protected const OWNER = 'propietario.centro@barberapp.test';
    protected const OWNER_NORTE = 'propietario.norte@barberapp.test';
    protected const SUPER = 'superadmin@barberapp.test';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.driver', 'sandbox');
        Templates::flush();
        Entitlements::flush();
    }

    /** @return array<string, string> */
    protected function as(string $email, string $tenant = 'demo-centro'): array
    {
        $this->cookies = [];
        return ['X-CSRF-Token' => $this->loginWeb($email), 'X-Tenant' => $tenant];
    }

    /** @param array<string, string> $h */
    protected function panel(string $method, string $path, array $h, ?array $body = null): Response
    {
        return $this->call($method, '/panel' . $path, $body, $h);
    }

    /** @return array<string, mixed> */
    protected function ok(Response $res, int $status = 200): array
    {
        $this->assertSame($status, $res->status(), $res->body());
        return $this->data($res);
    }

    protected function planId(string $codigo): int
    {
        return (int) $this->scalar('SELECT id FROM planes WHERE codigo = ?', [$codigo]);
    }

    /** @return array<string, mixed> suscripción más reciente de la barbería */
    protected function sub(string $slug): array
    {
        $st = $this->db->prepare('SELECT s.*, p.codigo plan_codigo FROM suscripciones s JOIN planes p ON p.id = s.plan_id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1');
        $st->execute([$this->tenantId($slug)]);
        return $st->fetch() ?: [];
    }

    /** Crea la orden y la paga (o rechaza) en el simulador. @return array<string, mixed> respuesta de simular */
    protected function payWithSandbox(array $h, int $planId, string $ciclo = 'mensual', int $periodos = 1, bool $aprobar = true): array
    {
        $co = $this->ok($this->panel('POST', '/suscripcion/pagar', $h, ['plan_id' => $planId, 'ciclo' => $ciclo, 'periodos' => $periodos]), 201);
        $this->assertFalse($co['aplicado']);
        $this->assertStringContainsString('pages/panel/pago-simulado.html', (string) $co['url']);
        return $this->ok($this->panel('POST', '/suscripcion/ordenes/' . $co['orden']['codigo'] . '/simular', $h, ['aprobar' => $aprobar]));
    }

    protected function setting(string $clave, $valor): void
    {
        $this->exec('INSERT INTO configuraciones (tenant_id, clave, valor, es_publica) VALUES (NULL, ?, ?, 0) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, json_encode($valor)]);
        Entitlements::flush();
    }

    protected function utc(string $relative): string
    {
        return Clock::nowUtc()->modify($relative)->format('Y-m-d H:i:s');
    }

    protected function freeze(string $relative): void
    {
        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($relative));
        Entitlements::flush();
    }

    protected function notices(string $tipo, ?string $email = null): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM notificaciones n JOIN usuarios u ON u.id = n.usuario_id WHERE n.tipo = ?' . ($email !== null ? ' AND u.email = ?' : ''),
            $email !== null ? [$tipo, $email] : [$tipo]
        );
    }
}
