<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Templates;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Auth\ApiTestCase;

/** Utilidades para las pruebas del panel del superadministrador (Fase 9). */
abstract class PlatformTestCase extends ApiTestCase
{
    protected const SUPER = 'superadmin@barberapp.test';
    protected const OPERADOR = 'operador@barberapp.test';
    protected const PROPIETARIO = 'propietario.centro@barberapp.test';
    protected const CLIENTE = 'cliente1@barberapp.test';

    protected function setUp(): void
    {
        parent::setUp();
        Templates::flush();
        NotificationSettings::flush();
    }

    /** @return array<string, string> cabeceras de una sesión web nueva */
    protected function as(string $email): array
    {
        $this->cookies = [];
        return ['X-CSRF-Token' => $this->loginWeb($email)];
    }

    /** @param array<string, string> $h */
    protected function get(string $path, array $h = []): Response
    {
        return $this->call('GET', '/plataforma' . $path, null, $h);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $h
     */
    protected function post(string $path, array $body, array $h): Response
    {
        return $this->call('POST', '/plataforma' . $path, $body, $h);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $h
     */
    protected function put(string $path, array $body, array $h): Response
    {
        return $this->call('PUT', '/plataforma' . $path, $body, $h);
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

    protected function subId(string $slug): int
    {
        return (int) $this->scalar('SELECT MAX(id) FROM suscripciones WHERE tenant_id = ?', [$this->tenantId($slug)]);
    }

    /** Barbería real (no demo) para cuadrar indicadores sin depender de los datos de demostración. */
    protected function realTenant(string $slug, string $estado = 'activo', string $createdAt = 'now'): int
    {
        $this->exec(
            "INSERT INTO tenants (slug, nombre_comercial, estado, es_demo, created_at, aprobado_en) VALUES (?, ?, ?, 0, ?, IF(? = 'activo', ?, NULL))",
            [$slug, 'Barbería ' . $slug, $estado, $this->utc($createdAt), $estado, $this->utc($createdAt)]
        );
        return (int) $this->db->lastInsertId();
    }

    /** Suscripción insertada directamente (para escenarios históricos). */
    protected function subscription(int $tenantId, string $plan, string $estado, string $precio, string $inicio, string $fin, string $ciclo = 'mensual', ?string $terminada = null): int
    {
        $this->exec(
            'INSERT INTO suscripciones (tenant_id, plan_id, estado, ciclo, precio_pactado, inicio_en, periodo_fin_en, terminada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $this->planId($plan), $estado, $ciclo, $precio, $this->utc($inicio), $this->utc($fin), $terminada === null ? null : $this->utc($terminada)]
        );
        return (int) $this->db->lastInsertId();
    }

    protected function utc(string $relative): string
    {
        return Clock::nowUtc()->modify($relative)->format('Y-m-d H:i:s');
    }

    protected function freeze(string $relative): void
    {
        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($relative));
    }
}
