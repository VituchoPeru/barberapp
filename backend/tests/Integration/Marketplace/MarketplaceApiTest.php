<?php

declare(strict_types=1);

namespace Tests\Integration\Marketplace;

use App\Helpers\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Auth\ApiTestCase;

/** Marketplace público: búsqueda, filtros, visibilidad, perfil, distancias y QR. */
final class MarketplaceApiTest extends ApiTestCase
{
    /** @return list<string> slugs de la respuesta */
    private function slugs(string $query = ''): array
    {
        $res = $this->call('GET', '/marketplace/barberias' . ($query !== '' ? '?' . $query : ''));
        $this->assertSame(200, $res->status(), $res->body());
        return array_column($this->data($res), 'slug');
    }

    public function testListsOnlyActivePublishedTenantsWithBranches(): void
    {
        $slugs = $this->slugs();
        sort($slugs);
        // demo-privada (no publicada) y demo-suspendida no aparecen.
        $this->assertSame(['demo-centro', 'demo-norte', 'demo-sur'], $slugs);

        // Sin sucursal activa deja de listarse aunque esté publicada.
        $this->exec('UPDATE sucursales SET activo = 0 WHERE tenant_id = ?', [$this->tenantId('demo-sur')]);
        $this->assertNotContains('demo-sur', $this->slugs());

        // Suspender una barbería la retira del listado.
        $this->exec("UPDATE tenants SET estado = 'suspendido' WHERE slug = 'demo-norte'");
        $this->assertSame(['demo-centro'], $this->slugs());
    }

    public function testSearchByNameDistrictAndServiceIsAccentInsensitive(): void
    {
        $this->assertSame(['demo-norte'], $this->slugs('q=' . rawurlencode('barberia demo norte')));
        $this->assertSame(['demo-centro'], $this->slugs('q=miraflores'));
        $this->assertSame(['demo-sur'], $this->slugs('q=navaja'));
        // Los comodines de LIKE se tratan como texto.
        $this->assertSame([], $this->slugs('q=' . rawurlencode('%')));
        $this->assertSame([], $this->slugs('q=' . rawurlencode('_')));
        // No encuentra barberías no publicadas por su nombre.
        $this->assertSame([], $this->slugs('q=privada'));
    }

    public function testFiltersByDistrictServiceTypeAndPrice(): void
    {
        $this->assertSame(['demo-norte'], $this->slugs('distrito=' . rawurlencode('Los Olivos')));
        $this->assertSame(['demo-sur'], $this->slugs('tipo=afeitado'));

        // Tipo y precio deben cumplirse en el mismo servicio: barba ≤ S/ 16 solo en demo-centro (S/ 15).
        $res = $this->call('GET', '/marketplace/barberias?tipo=barba&precio_max=16');
        $data = $this->data($res);
        $this->assertSame(['demo-centro'], array_column($data, 'slug'));
        $this->assertSame('15.00', $data[0]['precio_desde']);

        $this->assertSame(['demo-sur'], $this->slugs('precio_min=50'));
        // Los servicios inactivos no cuentan (demo-centro tiene un "color" inactivo).
        $this->assertSame(['demo-sur'], $this->slugs('tipo=color'));
    }

    public function testInvalidFiltersAreRejected(): void
    {
        foreach (['lat=-12.1', 'lat=200&lng=1', 'precio_min=50&precio_max=10', 'orden=distancia', 'orden=rating', 'precio_min=-5', 'tipo=DROP%20TABLE'] as $q) {
            $res = $this->call('GET', '/marketplace/barberias?' . $q);
            $this->assertSame(422, $res->status(), $q . ' → ' . $res->body());
        }
    }

    public function testDistancesOnlyWithValidCoordinates(): void
    {
        $sin = $this->data($this->call('GET', '/marketplace/barberias'));
        foreach ($sin as $card) {
            $this->assertNull($card['distancia_km'], 'Sin ubicación del usuario no se calcula distancia');
        }

        // Ubicación en Miraflores: demo-centro (sede Miraflores) es la más cercana; demo-sur no tiene coordenadas.
        $res = $this->call('GET', '/marketplace/barberias?lat=-12.1211&lng=-77.0297');
        $data = $this->data($res);
        $this->assertTrue($res->decoded()['meta']['con_ubicacion']);
        $this->assertSame(['demo-centro', 'demo-norte', 'demo-sur'], array_column($data, 'slug'));
        $this->assertLessThan(0.5, $data[0]['distancia_km']);
        $this->assertGreaterThan(10, $data[1]['distancia_km']);
        $this->assertNull($data[2]['distancia_km']);

        // Radio de 5 km.
        $this->assertSame(['demo-centro'], $this->slugs('lat=-12.1211&lng=-77.0297&radio_km=5'));
    }

    public function testOrderingAndPagination(): void
    {
        $this->assertSame(['demo-centro', 'demo-norte', 'demo-sur'], $this->slugs('orden=precio'));
        $res = $this->call('GET', '/marketplace/barberias?per_page=2&page=2');
        $meta = $res->decoded()['meta'];
        $this->assertSame(3, $meta['total']);
        $this->assertSame(2, $meta['pages']);
        $this->assertCount(1, $this->data($res));
    }

    public function testCardsCarryRealDataOnly(): void
    {
        $card = $this->data($this->call('GET', '/marketplace/barberias?q=centro'))[0];
        $this->assertSame('15.00', $card['precio_desde']);
        $this->assertSame(2, $card['sucursales']);
        $this->assertSame('Cercado de Lima', $card['ubicacion']['distrito']);
        $this->assertNull($card['favorito'], 'Anónimo: no se marca favorito');
        $this->assertStringEndsWith('/b/demo-centro', $card['enlace']);
        $this->assertArrayNotHasKey('rating', $card);
        $this->assertArrayNotHasKey('calificacion', $card);
    }

    public function testOpenNowUsesBranchTimezone(): void
    {
        // Martes 10:00 en Lima (UTC-5) = 15:00 UTC → abierta (09:00–20:00).
        Clock::freeze(new DateTimeImmutable('2026-10-20 15:00:00', new DateTimeZone('UTC')));
        $card = $this->data($this->call('GET', '/marketplace/barberias?q=norte'))[0];
        $this->assertTrue($card['abierto_ahora']);

        // Martes 21:30 en Lima → cerrada; próxima apertura miércoles 09:00.
        Clock::freeze(new DateTimeImmutable('2026-10-21 02:30:00', new DateTimeZone('UTC')));
        $this->assertFalse($this->data($this->call('GET', '/marketplace/barberias?q=norte'))[0]['abierto_ahora']);
        $perfil = $this->data($this->call('GET', '/marketplace/barberias/demo-norte'));
        $estado = $perfil['sucursales'][0]['estado'];
        $this->assertFalse($estado['abierto']);
        $this->assertSame('miércoles', $estado['proxima_apertura']['dia_nombre']);
        $this->assertSame('09:00', $estado['proxima_apertura']['hora']);
    }

    public function testFiltersEndpointReflectsVisibleTenants(): void
    {
        $data = $this->data($this->call('GET', '/marketplace/filtros'));
        $distritos = array_column($data['distritos'], 'distrito');
        $this->assertContains('Miraflores', $distritos);
        $this->assertNotContains('Santiago de Surco', $distritos, 'demo-privada no está publicada');
        $tipos = array_column($data['tipos_servicio'], 'codigo');
        $this->assertContains('afeitado', $tipos);
        $this->assertSame('15.00', $data['precios']['minimo']);
        $this->assertSame('60.00', $data['precios']['maximo']);
    }

    public function testPublicProfileExposesOnlyPublicActiveData(): void
    {
        $res = $this->call('GET', '/marketplace/barberias/demo-centro');
        $this->assertSame(200, $res->status());
        $p = $this->data($res);

        $nombres = array_column($p['servicios'], 'nombre');
        $this->assertContains('Corte y barba', $nombres);
        $this->assertNotContains('Servicio inactivo de prueba', $nombres);
        $this->assertNotContains('Barbero Inactivo Demo', array_column($p['barberos'], 'nombre'));
        $this->assertCount(2, $p['sucursales']);
        $this->assertCount(7, $p['sucursales'][0]['horarios']);
        $this->assertSame([], $p['sucursales'][0]['horarios'][6]['tramos'], 'Domingo cerrado');
        $this->assertSame('https://wa.me/51900000010', $p['contacto']['whatsapp_url']);

        // Servicio → barberos que lo realizan; barbero → servicios con precio efectivo.
        $corteBarba = $p['servicios'][array_search('Corte y barba', $nombres, true)];
        $this->assertCount(1, $corteBarba['barberos']);

        // Nunca datos privados: correos de usuarios, clientes, notas, reservas, RUC.
        $body = $res->body();
        $codigos = $this->db->query('SELECT codigo FROM reservas')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_merge(['@barberapp.test', 'notas_internas', 'Cliente Uno', 'inicio_utc', '"ruc"', 'password', 'razon_social'], $codigos) as $private) {
            $this->assertStringNotContainsString($private, $body, "El perfil público expone: {$private}");
        }
    }

    public function testDirectLinkWorksForUnlistedButNotForSuspended(): void
    {
        $res = $this->call('GET', '/marketplace/barberias/demo-privada');
        $this->assertSame(200, $res->status());
        $this->assertFalse($this->data($res)['listada_en_marketplace']);

        foreach (['demo-suspendida', 'no-existe', 'DEMO-CENTRO%27'] as $slug) {
            $this->assertSame(404, $this->call('GET', '/marketplace/barberias/' . $slug)->status(), $slug);
        }
        $this->exec("UPDATE tenants SET estado = 'pendiente' WHERE slug = 'demo-privada'");
        $this->assertSame(404, $this->call('GET', '/marketplace/barberias/demo-privada')->status());
    }

    public function testProfileMarksFavoriteForLoggedClientAndToleratesExpiredCookie(): void
    {
        $this->loginWeb('cliente1@barberapp.test');
        $this->assertTrue($this->data($this->call('GET', '/marketplace/barberias/demo-centro'))['favorito']);
        $this->assertFalse($this->data($this->call('GET', '/marketplace/barberias/demo-norte'))['favorito']);
        $cards = $this->data($this->call('GET', '/marketplace/barberias'));
        $this->assertSame([true, false, false], array_column($cards, 'favorito'));

        // Cookie vencida o falsa: el marketplace sigue funcionando como anónimo (y borra la cookie).
        $this->cookies = ['barberapp_session' => str_repeat('x', 43)];
        $res = $this->call('GET', '/marketplace/barberias/demo-centro');
        $this->assertSame(200, $res->status());
        $this->assertNull($this->data($res)['favorito']);
        $this->assertSame([], $this->cookies);
    }

    public function testQrEncodesDirectLinkInSvgAndPng(): void
    {
        $svg = $this->call('GET', '/marketplace/barberias/demo-centro/qr');
        $this->assertSame(200, $svg->status());
        $this->assertStringStartsWith('image/svg+xml', (string) $svg->header('Content-Type'));
        $this->assertStringContainsString('<svg', $svg->body());
        $this->assertStringContainsString('public', (string) $svg->header('Cache-Control'));

        $png = $this->call('GET', '/marketplace/barberias/demo-centro/qr?formato=png&tam=8&descargar=1');
        $this->assertSame('image/png', $png->header('Content-Type'));
        $this->assertSame("\x89PNG", substr($png->body(), 0, 4));
        $this->assertStringContainsString('qr-demo-centro.png', (string) $png->header('Content-Disposition'));
        $size = getimagesizefromstring($png->body());
        $this->assertNotFalse($size);

        $decoded = $this->decodeQr($png->body());
        if ($decoded !== null) {
            $this->assertStringEndsWith('/b/demo-centro?o=qr', $decoded);
        }

        $this->assertSame(404, $this->call('GET', '/marketplace/barberias/demo-suspendida/qr')->status());
        $this->assertSame(422, $this->call('GET', '/marketplace/barberias/demo-centro/qr?tam=500')->status());
    }

    /** Decodifica con zbarimg si está instalado (verificación real del QR). */
    private function decodeQr(string $png): ?string
    {
        $bin = trim((string) @shell_exec('command -v zbarimg 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'qr') . '.png';
        file_put_contents($file, $png);
        $out = shell_exec(escapeshellarg($bin) . ' -q --raw ' . escapeshellarg($file) . ' 2>/dev/null');
        @unlink($file);
        return $out === null ? null : trim($out);
    }
}
