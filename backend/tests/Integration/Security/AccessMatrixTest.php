<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Core\Response;
use Tests\Integration\Auth\ApiTestCase;

/**
 * Fase 14 — seguridad: escalada de privilegios, acceso sin sesión y CSRF en TODAS las rutas.
 *
 * La matriz esperada (rol × ruta) está en tests/Fixtures/matriz-acceso.json y se publica en
 * docs/MATRIZ_ACCESO.md. Si una ruta nueva o un cambio de permisos altera lo que puede hacer un
 * rol, esta prueba falla hasta que la matriz se revise y se actualice a propósito:
 *
 *   ACTUALIZAR_MATRIZ=1 vendor/bin/phpunit --filter AccessMatrixTest
 *
 * Cada petición se hace con un id inexistente y cuerpo vacío dentro de un SAVEPOINT que se revierte,
 * de modo que solo se evalúa si el rol pasa los controles de acceso de la ruta.
 *
 * @group seguridad
 */
final class AccessMatrixTest extends ApiTestCase
{
    private const FIXTURE = BARBERAPP_BASE . '/tests/Fixtures/matriz-acceso.json';
    private const DOC = BARBERAPP_BASE . '/../docs/MATRIZ_ACCESO.md';

    /** Una cuenta demo por rol. El contexto de barbería es siempre demo-centro. */
    private const ROLES = [
        'anonimo' => null,
        'cliente' => 'cliente1@barberapp.test',
        'barbero' => 'barbero1.centro@barberapp.test',
        'administrador' => 'admin.centro@barberapp.test',
        'propietario' => 'propietario.centro@barberapp.test',
        'operador' => 'operador@barberapp.test',
        'superadmin' => 'superadmin@barberapp.test',
    ];

    /**
     * Leyenda de la matriz:
     *   S = permitido (pasa los controles de acceso)
     *   L = sin sesión (401)
     *   P = sin permiso (403)
     *   B = barbería ajena: responde 404 sin revelar que existe
     *   - = no aplica a ese rol (p. ej. panel del barbero para quien no tiene perfil de barbero)
     */
    public function testEveryRouteGrantsExactlyTheExpectedAccessPerRole(): void
    {
        $actual = [];
        $n = 0;
        foreach (self::ROLES as $rol => $email) {
            $this->cookies = [];
            $headers = ['X-Tenant' => 'demo-centro'];
            if ($email !== null) {
                $headers['Authorization'] = 'Bearer ' . $this->loginMobile($email)['access_token'];
            }
            foreach (RouteProbe::routes() as $r) {
                $this->ip = '198.51.100.' . (++$n % 250 + 1); // sin chocar con el límite general de escrituras
                $this->db->exec('SAVEPOINT matriz');
                $res = $this->call($r['method'], $r['path'], RouteProbe::isWrite($r['method']) ? [] : null, $headers);
                $this->db->exec('ROLLBACK TO SAVEPOINT matriz');
                $this->assertLessThan(500, $res->status(), "{$rol} {$r['method']} {$r['path']} → {$res->body()}");
                $actual[$r['name']][$rol] = $this->classify($r['path'], $res);
            }
        }

        if (getenv('ACTUALIZAR_MATRIZ') === '1') {
            file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents(self::DOC, $this->markdown($actual));
        }
        $expected = json_decode((string) file_get_contents(self::FIXTURE), true);
        $this->assertIsArray($expected, 'Falta tests/Fixtures/matriz-acceso.json');

        $diff = [];
        foreach ($actual + $expected as $name => $_) {
            foreach (array_keys(self::ROLES) as $rol) {
                $a = $actual[$name][$rol] ?? '(ruta eliminada)';
                $e = $expected[$name][$rol] ?? '(ruta nueva)';
                if ($a !== $e) {
                    $diff[] = "{$name} / {$rol}: esperado {$e}, obtenido {$a}";
                }
            }
        }
        $this->assertSame([], $diff, "La matriz de acceso cambió. Revísala y, si es correcto, regenérala con ACTUALIZAR_MATRIZ=1.\n" . implode("\n", $diff));
        $this->assertFileExists(self::DOC);
        $this->assertStringContainsString('| `panel.staff.invite` |', (string) file_get_contents(self::DOC));
    }

    /** Las reglas que la matriz debe cumplir siempre, aunque alguien la regenere sin mirar. */
    public function testMatrixInvariants(): void
    {
        $m = json_decode((string) file_get_contents(self::FIXTURE), true);
        foreach ($m as $name => $roles) {
            if ($name === 'webhooks.payments') {
                // Sin sesión, pero autenticado por la firma de la pasarela: sin firma válida, 401 para todos.
                $this->assertSame(['L'], array_values(array_unique($roles)), $name);
                continue;
            }
            $publica = in_array($name, RouteProbe::PUBLICAS, true);
            $this->assertSame($publica ? 'S' : 'L', $roles['anonimo'], "{$name}: sin sesión");
            if (str_starts_with($name, 'panel.') || str_starts_with($name, 'barber.')) {
                foreach (['cliente', 'operador', 'superadmin'] as $rol) {
                    // El equipo de la plataforma no entra al panel de una barbería (Fase 9).
                    $this->assertSame('B', $roles[$rol], "{$name}: {$rol} no es miembro de demo-centro");
                }
            }
            if (str_starts_with($name, 'platform.')) {
                foreach (['cliente', 'barbero', 'administrador', 'propietario'] as $rol) {
                    $this->assertSame('P', $roles[$rol], "{$name}: {$rol} no es del equipo de la plataforma");
                }
                $this->assertSame('S', $roles['superadmin'], "{$name}: superadmin");
            }
        }
        // Lo que el barbero nunca puede hacer en el panel: dinero, personal, configuración y clientes.
        foreach (['panel.staff', 'panel.staff.invite', 'panel.dashboard', 'panel.reports', 'panel.reports.export', 'panel.bookings.pay',
            'panel.clients', 'panel.subscription', 'panel.business.update', 'panel.booking_policy.update', 'panel.services.store', 'panel.barbers.store'] as $name) {
            $this->assertSame('P', $m[$name]['barbero'], "barbero → {$name}");
        }
        // Lo que solo hace el propietario (ni el administrador): personal, suscripción y datos del negocio.
        foreach (['panel.staff', 'panel.staff.invite', 'panel.staff.revoke', 'panel.subscription', 'panel.subscription.pay',
            'panel.business.update', 'panel.public_profile.update', 'panel.barbers.store', 'panel.branches.store'] as $name) {
            $this->assertSame('P', $m[$name]['administrador'], "administrador → {$name}");
            $this->assertSame('S', $m[$name]['propietario'], "propietario → {$name}");
        }
        // El operador de la plataforma no administra planes, usuarios, ni configuración.
        foreach (['platform.plans.store', 'platform.users', 'platform.users.role', 'platform.settings.update', 'platform.templates.save'] as $name) {
            $this->assertSame('P', $m[$name]['operador'], "operador → {$name}");
        }
    }

    /**
     * Con sesión web (cookie), toda escritura sin X-CSRF-Token se rechaza con 403 antes de hacer nada,
     * y con el token correcto pasa ese control.
     */
    public function testEveryWriteWithCookieSessionRequiresCsrf(): void
    {
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $n = 0;
        $checked = 0;
        foreach (RouteProbe::routes() as $r) {
            if (!RouteProbe::isWrite($r['method']) || in_array($r['name'], RouteProbe::PUBLICAS, true)) {
                continue;
            }
            $this->ip = '198.51.100.' . (++$n % 250 + 1);
            foreach (['sin' => [], 'falso' => ['X-CSRF-Token' => str_repeat('a', strlen($csrf))]] as $caso => $extra) {
                $before = $this->auditCount('auth.csrf_invalido', 'denegado');
                $res = $this->call($r['method'], $r['path'], [], ['X-Tenant' => 'demo-centro'] + $extra);
                $this->assertSame(403, $res->status(), "{$r['name']} ({$caso} token) → {$res->body()}");
                $this->assertSame('csrf_mismatch', $this->errorCode($res), $r['name']);
                $this->assertSame($before + 1, $this->auditCount('auth.csrf_invalido', 'denegado'), "{$r['name']}: el intento queda en auditoría");
            }
            $this->db->exec('SAVEPOINT csrf');
            $cookies = $this->cookies; // logout, cambio de contraseña, etc. cambian la cookie: se restaura
            $ok = $this->call($r['method'], $r['path'], [], ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf]);
            $this->db->exec('ROLLBACK TO SAVEPOINT csrf');
            $this->cookies = $cookies;
            $this->assertNotSame('csrf_mismatch', $this->errorCode($ok), $r['name'] . ' con token válido');
            $checked++;
        }
        $this->assertGreaterThan(100, $checked, 'Se esperaban más de 100 rutas de escritura');
    }

    /** El token CSRF de una sesión no sirve en otra (no es un valor fijo ni adivinable). */
    public function testCsrfTokenFromAnotherSessionIsRejected(): void
    {
        $ajeno = $this->loginWeb('cliente2@barberapp.test');
        $this->cookies = [];
        $this->loginWeb('cliente1@barberapp.test');
        $res = $this->call('PUT', '/me/favoritos/demo-centro', null, ['X-CSRF-Token' => $ajeno]);
        $this->assertSame(403, $res->status());
        $this->assertSame('csrf_mismatch', $this->errorCode($res));
    }

    private function classify(string $path, Response $res): string
    {
        $status = $res->status();
        $code = (string) $this->errorCode($res);
        if ($status === 401) {
            return 'L';
        }
        if ($status === 403 && in_array($code, ['no_barber_profile'], true)) {
            return '-';
        }
        if ($status === 403 && in_array($code, ['forbidden', 'schedule_locked'], true)) {
            return $code === 'schedule_locked' ? 'S' : 'P';
        }
        $tenantRoute = str_starts_with($path, '/panel') || str_starts_with($path, '/barbero');
        if ($tenantRoute && $status === 404 && ($res->decoded()['error']['message'] ?? '') === 'Barbería no encontrada.') {
            return 'B';
        }
        return 'S';
    }

    /** @param array<string, array<string, string>> $m */
    private function markdown(array $m): string
    {
        $roles = array_keys(self::ROLES);
        $out = "# Matriz de acceso por rol\n\n"
            . "Generada por `backend/tests/Integration/Security/AccessMatrixTest.php` a partir de las rutas reales; no la edites a mano.\n"
            . "La prueba falla si el acceso de algún rol cambia sin actualizar esta matriz.\n\n"
            . "Cuentas demo usadas: una por rol, con el contexto de la barbería `demo-centro`.\n\n"
            . "| Símbolo | Significado |\n|---|---|\n"
            . "| S | Permitido: pasa los controles de acceso de la ruta (luego aplican las reglas del recurso, p. ej. el barbero solo ve sus citas) |\n"
            . "| L | Requiere iniciar sesión (401) |\n| P | Sin permiso (403) |\n"
            . "| B | No es miembro de la barbería: 404 sin revelar que existe |\n| - | No aplica: la cuenta no tiene perfil de barbero |\n\n"
            . '| Ruta | ' . implode(' | ', $roles) . " |\n|---|" . str_repeat('---|', count($roles)) . "\n";
        foreach ($m as $name => $row) {
            $out .= "| `{$name}` | " . implode(' | ', array_map(static fn (string $r): string => $row[$r], $roles)) . " |\n";
        }
        return $out;
    }
}
