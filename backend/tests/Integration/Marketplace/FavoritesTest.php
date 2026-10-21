<?php

declare(strict_types=1);

namespace Tests\Integration\Marketplace;

use Tests\Integration\Auth\ApiTestCase;

final class FavoritesTest extends ApiTestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->call('GET', '/me/favoritos')->status());
        $this->assertSame(401, $this->call('PUT', '/me/favoritos/demo-centro', [])->status());
    }

    public function testAddListRemoveIsIdempotent(): void
    {
        $csrf = $this->loginWeb('cliente1@barberapp.test');
        $h = ['X-CSRF-Token' => $csrf];

        $this->assertSame(['demo-centro'], array_column($this->data($this->call('GET', '/me/favoritos')), 'slug'));

        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-norte', [], $h)->status());
        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-norte', [], $h)->status(), 'Repetir no duplica');
        $list = $this->data($this->call('GET', '/me/favoritos'));
        $this->assertSame(['demo-norte', 'demo-centro'], array_column($list, 'slug'));
        $this->assertSame([true, true], array_column($list, 'favorito'));

        $this->assertSame(204, $this->call('DELETE', '/me/favoritos/demo-norte', null, $h)->status());
        $this->assertSame(204, $this->call('DELETE', '/me/favoritos/demo-norte', null, $h)->status());
        $this->assertSame(['demo-centro'], array_column($this->data($this->call('GET', '/me/favoritos')), 'slug'));
    }

    public function testCsrfIsRequiredForWebSessions(): void
    {
        $this->loginWeb('cliente1@barberapp.test');
        $res = $this->call('PUT', '/me/favoritos/demo-norte', []);
        $this->assertSame(403, $res->status());
        $this->assertSame('csrf_mismatch', $this->errorCode($res));
    }

    public function testMobileBearerWorks(): void
    {
        $tokens = $this->loginMobile('cliente2@barberapp.test');
        $h = ['Authorization' => 'Bearer ' . $tokens['access_token']];
        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-sur', [], $h)->status());
        $this->assertSame(['demo-sur'], array_column($this->data($this->call('GET', '/me/favoritos', null, $h)), 'slug'));
    }

    public function testUnavailableTenantsCannotBeAddedAndAreHidden(): void
    {
        $csrf = $this->loginWeb('cliente1@barberapp.test');
        $h = ['X-CSRF-Token' => $csrf];
        $this->assertSame(404, $this->call('PUT', '/me/favoritos/demo-suspendida', [], $h)->status());
        $this->assertSame(404, $this->call('PUT', '/me/favoritos/no-existe', [], $h)->status());
        // Una barbería no listada sí puede guardarse (el cliente llegó por su enlace o QR).
        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-privada', [], $h)->status());

        // Si la barbería se suspende, desaparece de la lista (el registro se conserva).
        $this->exec("UPDATE tenants SET estado = 'suspendido' WHERE slug = 'demo-centro'");
        $this->assertSame(['demo-privada'], array_column($this->data($this->call('GET', '/me/favoritos')), 'slug'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM favoritos WHERE tenant_id = ?', [$this->tenantId('demo-centro')]));
    }

    public function testStaffWithoutClientProfileGetsOneCreated(): void
    {
        $email = 'propietario.norte@barberapp.test';
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id = c.usuario_id WHERE u.email = ?', [$email]));
        $csrf = $this->loginWeb($email);
        $this->assertSame([], $this->data($this->call('GET', '/me/favoritos')));
        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-centro', [], ['X-CSRF-Token' => $csrf])->status());
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id = c.usuario_id WHERE u.email = ?', [$email]));
    }

    public function testFavoritesAreIsolatedPerUser(): void
    {
        $this->loginWeb('cliente2@barberapp.test');
        $this->assertSame([], $this->data($this->call('GET', '/me/favoritos')), 'cliente2 no ve los favoritos de cliente1');
    }
}
