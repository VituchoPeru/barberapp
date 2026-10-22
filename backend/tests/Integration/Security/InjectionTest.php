<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use Tests\Integration\Auth\ApiTestCase;

/**
 * Fase 14 — seguridad: inyección SQL y XSS almacenado en la API.
 *
 * Recorre todas las rutas con cargas de inyección en la query, en el cuerpo JSON y en la cabecera
 * X-Tenant, con las cuentas que llegan a cada grupo de rutas. Ninguna puede producir un error 500,
 * filtrar un mensaje de MySQL, alterar tablas, demorar la respuesta (SLEEP) ni devolver hashes.
 *
 * @group seguridad
 */
final class InjectionTest extends ApiTestCase
{
    /** Cargas típicas: comillas, comentarios, UNION, apilado, tiempo, LIKE y escapes. */
    private const CARGAS = [
        "' OR '1'='1",
        "1' OR 1=1 -- -",
        '" OR ""="',
        "1; DROP TABLE usuarios; --",
        "1' AND SLEEP(2) AND '1'='1",
        "0 UNION SELECT email, password_hash FROM usuarios -- -",
        "%' OR 1=1 #",
        "\\' OR 1=1 /*",
        "demo-centro' -- ",
    ];

    /** Nombres de campo que usan los controladores y validadores (query y cuerpo). */
    private const CAMPOS = [
        'q', 'estado', 'desde', 'hasta', 'fecha', 'hora', 'page', 'per_page', 'orden', 'tipo', 'distrito', 'servicio', 'vista',
        'formato', 'sucursal_id', 'barbero_id', 'cliente_id', 'servicio_ids', 'categoria_id', 'plan_id', 'usuario_id', 'tenant',
        'tenant_id', 'accion', 'email', 'password', 'nombre', 'nombres', 'apellidos', 'telefono', 'motivo', 'notas', 'codigo',
        'slug', 'precio_min', 'precio_max', 'lat', 'lng', 'radio_km', 'token', 'refresh_token', 'canal', 'rol', 'metodo', 'monto',
        'referencia', 'descripcion', 'nombre_comercial', 'respuesta', 'solo_no_leidas', 'meses', 'ciclo', 'collection_id', 'data_id',
    ];

    /** Grupo de rutas → cuenta que lo alcanza (los demás reciben 401/403 antes de tocar la base). */
    private const CUENTAS = [
        'anonimo' => null,
        'cliente' => 'cliente1@barberapp.test',
        'propietario' => 'propietario.centro@barberapp.test',
        'barbero' => 'barbero1.centro@barberapp.test',
        'superadmin' => 'superadmin@barberapp.test',
    ];

    public function testNoRouteIsInjectableThroughQueryBodyOrTenantHeader(): void
    {
        $before = $this->fingerprint();
        $routes = RouteProbe::routes();
        $n = 0;
        $calls = 0;
        foreach (self::CUENTAS as $cuenta => $email) {
            $this->cookies = [];
            $auth = $email !== null ? ['Authorization' => 'Bearer ' . $this->loginMobile($email)['access_token']] : [];
            foreach ($routes as $r) {
                if ($cuenta === 'anonimo' xor in_array($r['name'], RouteProbe::PUBLICAS, true)) {
                    continue; // cada cuenta prueba lo que realmente alcanza
                }
                foreach (self::CARGAS as $carga) {
                    $this->ip = '198.51.100.' . (++$n % 250 + 1);
                    $fields = array_fill_keys(self::CAMPOS, $carga);
                    $headers = $auth + ['X-Tenant' => 'demo-centro', 'Idempotency-Key' => 'prueba-inyeccion-' . $n];
                    $path = $r['path'] . '?' . http_build_query($fields);
                    $this->db->exec('SAVEPOINT inj');
                    $t = microtime(true);
                    $res = $this->call($r['method'], $path, RouteProbe::isWrite($r['method']) ? $fields : null, $headers);
                    $elapsed = microtime(true) - $t;
                    $this->db->exec('ROLLBACK TO SAVEPOINT inj');
                    $calls++;
                    $where = "{$cuenta} {$r['method']} {$r['path']} con «{$carga}»";
                    $this->assertLessThan(500, $res->status(), "{$where} → {$res->body()}");
                    $this->assertDoesNotMatchRegularExpression('/SQLSTATE|You have an error in your SQL|syntax to use near|PDOException/i', $res->body(), $where);
                    $this->assertDoesNotMatchRegularExpression('/\$2y\$|\$argon2/', $res->body(), "{$where}: devolvió un hash");
                    $this->assertLessThan(1.5, $elapsed, "{$where}: tardó {$elapsed}s (¿SLEEP ejecutado?)");
                }
            }
        }
        $this->assertGreaterThan(1500, $calls);
        $this->assertSame($before, $this->fingerprint(), 'Las tablas cambiaron tras las cargas de inyección');
    }

    public function testTenantHeaderInjectionNeverSelectsAnotherBarbershop(): void
    {
        $tok = $this->loginMobile('propietario.norte@barberapp.test')['access_token'];
        foreach (["demo-centro' OR '1'='1", "demo-norte' OR slug='demo-centro", '1 OR 1=1', "' UNION SELECT 1 -- -"] as $carga) {
            $res = $this->call('GET', '/panel/contexto', null, ['Authorization' => 'Bearer ' . $tok, 'X-Tenant' => $carga]);
            $this->assertContains($res->status(), [400, 404], $carga . ' → ' . $res->body());
            $this->assertStringNotContainsString('Demo Centro', $res->body());
        }
    }

    public function testSearchAndLoginTreatPayloadsAsPlainText(): void
    {
        // El buscador no se "abre": una comilla no devuelve todas las barberías.
        foreach (["' OR '1'='1", '%', '_', "%' OR 1=1 #"] as $q) {
            $res = $this->call('GET', '/marketplace/barberias?q=' . rawurlencode($q));
            $this->assertSame(200, $res->status(), $q);
            $this->assertSame([], $this->data($res)['items'] ?? $this->data($res), "La búsqueda «{$q}» no debe coincidir con nada");
        }
        // El login con una carga en el correo no entra y responde como un correo inválido.
        foreach (["' OR 1=1 -- -", "superadmin@barberapp.test' -- "] as $email) {
            $res = $this->call('POST', '/auth/login', ['email' => $email, 'password' => "' OR '1'='1"]);
            $this->assertContains($res->status(), [401, 422], $email);
            $this->assertArrayNotHasKey('csrf_token', $this->data($res));
        }
    }

    /**
     * XSS almacenado: la API guarda el texto tal cual (sin "limpiarlo" a medias) y lo entrega como
     * JSON con nosniff; el escape ocurre al pintarlo (frontend, correos y PDF). El navegador lo prueba
     * en frontend/e2e/xss.spec.mjs con estos mismos datos.
     */
    public function testStoredMarkupIsReturnedAsJsonDataNotHtml(): void
    {
        $xss = '"\'><img src=x onerror=alert(1)><script>alert(2)</script>';

        // Los nombres de personas no admiten < ni >.
        $csrf = $this->loginWeb('cliente1@barberapp.test');
        $res = $this->call('PUT', '/me/perfil', ['nombres' => 'Ana <b>', 'apellidos' => 'Prueba'], ['X-CSRF-Token' => $csrf]);
        $this->assertSame(422, $res->status(), $res->body());

        // Un texto libre (nota interna de una cita) se guarda tal cual y vuelve como dato JSON.
        $this->cookies = [];
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $id = (int) $this->scalar("SELECT id FROM reservas WHERE tenant_id = ? AND estado IN ('pendiente','confirmada') ORDER BY id LIMIT 1", [$this->tenantId('demo-centro')]);
        $h = ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf];
        $res = $this->call('PUT', "/panel/reservas/{$id}/notas", ['notas_internas' => $xss], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame($xss, $this->scalar('SELECT notas_internas FROM reservas WHERE id = ?', [$id]));

        $show = $this->call('GET', "/panel/reservas/{$id}", null, $h);
        $this->assertStringStartsWith('application/json', (string) $show->header('Content-Type'));
        $this->assertSame('nosniff', $show->header('X-Content-Type-Options'));
        $this->assertStringContainsString($xss, json_encode($show->decoded(), JSON_UNESCAPED_SLASHES));
        // JSON_HEX_TAG: ni abriendo la respuesta como HTML aparecen etiquetas.
        $this->assertStringNotContainsString('<img', $show->body());
        $this->assertStringNotContainsString('<script', $show->body());
        $this->assertStringContainsString('\u003Cscript\u003E', $show->body());
    }

    /** Huella de la base: tablas y filas de las tablas sensibles. */
    private function fingerprint(): array
    {
        $tables = $this->db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(\PDO::FETCH_COLUMN);
        $rows = [];
        foreach (['usuarios', 'tenants', 'reservas', 'membresias', 'pagos_saas', 'auditoria'] as $t) {
            if (in_array($t, $tables, true)) {
                $rows[$t] = (int) $this->scalar("SELECT COUNT(*) FROM `{$t}`");
            }
        }
        unset($rows['auditoria']); // los intentos denegados se registran (es lo esperado)
        return ['tablas' => $tables, 'filas' => $rows];
    }
}
