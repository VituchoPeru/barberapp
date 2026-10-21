<?php

declare(strict_types=1);

namespace Tests\Integration\Marketplace;

use App\Core\Config;
use Tests\Integration\Auth\ApiTestCase;

/** Panel: perfil público, publicación en el marketplace y fotos. */
final class PublicProfileTest extends ApiTestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaDir = sys_get_temp_dir() . '/barberapp-media-' . bin2hex(random_bytes(4));
        Config::set('media.path', $this->mediaDir);
        Config::set('media.url', 'https://cdn.test/media');
        Config::set('media.max_upload_bytes', 5 * 1024 * 1024);
        Config::set('media.max_galeria', 12);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->mediaDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->mediaDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->mediaDir);
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function owner(string $email = 'propietario.centro@barberapp.test', string $tenant = 'demo-centro'): array
    {
        return ['X-CSRF-Token' => $this->loginWeb($email), 'X-Tenant' => $tenant];
    }

    private function jpeg(int $w = 800, int $h = 600): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('La extensión GD no está instalada (necesaria para subir fotos).');
        }
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 201, 164, 92));
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public function testOwnerReadsAndUpdatesProfile(): void
    {
        $h = $this->owner();
        $res = $this->call('GET', '/panel/perfil-publico', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertTrue($d['visible_en_marketplace']);
        $this->assertSame(['sucursal' => true, 'horario' => true, 'servicio' => true, 'barbero' => true], $d['requisitos']);

        $res = $this->call('PUT', '/panel/perfil-publico', [
            'descripcion' => 'Nueva descripción',
            'whatsapp' => '987 654 321',
            'instagram' => 'https://www.instagram.com/barberia.demo/',
            'tiktok' => '@demo_tiktok',
            'sitio_web' => 'https://ejemplo.test',
        ], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertSame('+51987654321', $d['whatsapp']);
        $this->assertSame('barberia.demo', $d['instagram']);
        $this->assertSame('demo_tiktok', $d['tiktok']);
        $this->assertSame(1, $this->auditCount('marketplace.perfil_actualizado', 'exito'));

        $pub = $this->data($this->call('GET', '/marketplace/barberias/demo-centro'));
        $this->assertSame('Nueva descripción', $pub['descripcion']);
        $this->assertSame('https://www.instagram.com/barberia.demo/', $pub['contacto']['instagram_url']);
    }

    public function testRejectsInvalidContactData(): void
    {
        $h = $this->owner();
        foreach ([['sitio_web' => 'javascript:alert(1)'], ['whatsapp' => 'abc'], ['instagram' => 'a b<script>'], ['descripcion' => str_repeat('x', 2001)]] as $body) {
            $this->assertSame(422, $this->call('PUT', '/panel/perfil-publico', $body, $h)->status(), json_encode($body));
        }
    }

    public function testUnpublishAndRepublishControlsListing(): void
    {
        $h = $this->owner();
        $this->call('PUT', '/panel/perfil-publico', ['publicado_marketplace' => false], $h);
        $slugs = array_column($this->data($this->call('GET', '/marketplace/barberias')), 'slug');
        $this->assertNotContains('demo-centro', $slugs);
        // El enlace directo sigue funcionando.
        $this->assertSame(200, $this->call('GET', '/marketplace/barberias/demo-centro')->status());

        $this->assertSame(200, $this->call('PUT', '/panel/perfil-publico', ['publicado_marketplace' => true], $h)->status());
        $this->assertContains('demo-centro', array_column($this->data($this->call('GET', '/marketplace/barberias')), 'slug'));
    }

    public function testCannotPublishIncompleteProfile(): void
    {
        $h = $this->owner();
        $t = $this->tenantId('demo-centro');
        $this->call('PUT', '/panel/perfil-publico', ['publicado_marketplace' => false], $h);
        $this->exec('UPDATE servicios SET activo = 0 WHERE tenant_id = ?', [$t]);
        $res = $this->call('PUT', '/panel/perfil-publico', ['publicado_marketplace' => true], $h);
        $this->assertSame(422, $res->status());
        $this->assertSame('profile_incomplete', $this->errorCode($res));
        $this->assertContains('servicio', $res->decoded()['error']['details']['faltantes']);
    }

    public function testRequiresPermissionAndMembership(): void
    {
        // Administrador no tiene negocio.perfil.gestionar.
        $this->loginWeb('admin.centro@barberapp.test');
        $this->assertSame(403, $this->call('GET', '/panel/perfil-publico', null, ['X-Tenant' => 'demo-centro'])->status());

        // Propietario de otra barbería: 404 (no revela la existencia de datos ajenos).
        $h = $this->owner('propietario.norte@barberapp.test', 'demo-centro');
        $this->assertSame(404, $this->call('PUT', '/panel/perfil-publico', ['descripcion' => 'hack'], $h)->status());
        $this->assertNotSame('hack', $this->scalar("SELECT descripcion FROM tenants WHERE slug = 'demo-centro'"));
    }

    public function testUploadGalleryPhotoIsReencodedAndShown(): void
    {
        $h = $this->owner();
        $res = $this->send('POST', '/panel/fotos?tipo=galeria&alt=Fachada', $this->jpeg(2400, 1600), $h + ['Content-Type' => 'image/jpeg']);
        $this->assertSame(201, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertSame(1600, $d['ancho'], 'Se redimensiona a 1600 px');
        $this->assertMatchesRegularExpression('#^https://cdn\.test/media/tenants/\d+/[a-f0-9]{32}\.(webp|png)$#', $d['url']);

        $ruta = (string) $this->scalar('SELECT ruta FROM tenant_fotos WHERE id = ?', [$d['id']]);
        $file = $this->mediaDir . '/' . $ruta;
        $this->assertFileExists($file);
        $info = getimagesize($file);
        $this->assertSame([1600, 1067], [$info[0], $info[1]]);

        $pub = $this->data($this->call('GET', '/marketplace/barberias/demo-centro'));
        $this->assertSame('Fachada', $pub['fotos'][0]['alt']);
        $this->assertSame($d['url'], $pub['fotos'][0]['url']);

        $this->assertSame(204, $this->call('DELETE', '/panel/fotos/' . $d['id'], null, $h)->status());
        $this->assertFileDoesNotExist($file);
        $this->assertSame([], $this->data($this->call('GET', '/marketplace/barberias/demo-centro'))['fotos']);
    }

    public function testLogoReplacesPreviousOne(): void
    {
        $h = $this->owner();
        $first = $this->data($this->send('POST', '/panel/fotos?tipo=logo', $this->jpeg(600, 600), $h + ['Content-Type' => 'image/jpeg']));
        $second = $this->data($this->send('POST', '/panel/fotos?tipo=logo', $this->jpeg(400, 400), $h + ['Content-Type' => 'image/jpeg']));
        $this->assertSame(512, $first['ancho']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM tenant_fotos WHERE tenant_id = ? AND tipo = 'logo'", [$this->tenantId('demo-centro')]));
        $card = $this->data($this->call('GET', '/marketplace/barberias?q=centro'))[0];
        $this->assertSame($second['url'], $card['logo_url']);
    }

    public function testRejectsFakeOrDangerousImages(): void
    {
        $h = $this->owner();
        $cases = [
            ['<?php system($_GET["c"]); ?>', 'image/jpeg', 422],          // no es imagen
            ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'image/png', 422],    // GIF disfrazado
            [$this->jpeg(100, 100), 'image/jpeg', 422],                  // muy pequeña para galería
            [$this->jpeg(), 'application/x-php', 415],                   // tipo no permitido
        ];
        foreach ($cases as [$body, $type, $status]) {
            $res = $this->send('POST', '/panel/fotos?tipo=galeria', $body, $h + ['Content-Type' => $type]);
            $this->assertSame($status, $res->status(), $type . ' → ' . $res->body());
        }
        $this->assertSame(422, $this->send('POST', '/panel/fotos?tipo=avatar', $this->jpeg(), $h + ['Content-Type' => 'image/jpeg'])->status());

        Config::set('media.max_upload_bytes', 1000);
        $this->assertSame(413, $this->send('POST', '/panel/fotos?tipo=galeria', $this->jpeg(), $h + ['Content-Type' => 'image/jpeg'])->status());
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenant_fotos'));
    }

    public function testUploadRequiresCsrfAndCannotDeleteOtherTenantsPhotos(): void
    {
        $h = $this->owner();
        $noCsrf = $this->send('POST', '/panel/fotos?tipo=galeria', $this->jpeg(), ['X-Tenant' => 'demo-centro', 'Content-Type' => 'image/jpeg']);
        $this->assertSame(403, $noCsrf->status());

        $id = $this->data($this->send('POST', '/panel/fotos?tipo=galeria', $this->jpeg(), $h + ['Content-Type' => 'image/jpeg']))['id'];

        $this->cookies = [];
        $other = $this->owner('propietario.norte@barberapp.test', 'demo-norte');
        $this->assertSame(404, $this->call('DELETE', '/panel/fotos/' . $id, null, $other)->status());
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tenant_fotos WHERE id = ?', [$id]));
    }

    public function testGalleryLimit(): void
    {
        Config::set('media.max_galeria', 1);
        $h = $this->owner();
        $this->assertSame(201, $this->send('POST', '/panel/fotos?tipo=galeria', $this->jpeg(), $h + ['Content-Type' => 'image/jpeg'])->status());
        $res = $this->send('POST', '/panel/fotos?tipo=galeria', $this->jpeg(), $h + ['Content-Type' => 'image/jpeg']);
        $this->assertSame(422, $res->status());
        $this->assertSame('gallery_full', $this->errorCode($res));
    }
}
