<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Templates;

/** Plantillas globales, parámetros de la plataforma y auditoría (Fase 9). */
final class PlatformContentTest extends PlatformTestCase
{
    public function testTemplatesListValidateSaveAndRestore(): void
    {
        $h = $this->as(self::SUPER);
        $list = $this->ok($this->get('/plantillas', $h));
        $keys = array_map(static fn (array $t): string => $t['codigo'] . '|' . $t['canal'], $list);
        foreach (array_keys(Templates::DEFAULTS) as $codigo) {
            $this->assertContains("{$codigo}|push", $keys);
            $this->assertContains("{$codigo}|email", $keys);
        }

        $bad = $this->put('/plantillas/recordatorio/push', ['asunto' => 'Tu cita', 'cuerpo' => 'Hola {{nombre_cliente}} {{barberia}}'], $h);
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('{{nombre_cliente}}', $bad->decoded()['error']['details']['fields']['cuerpo'][0]);
        $this->assertSame(422, $this->put('/plantillas/recordatorio/push', ['asunto' => 'Tu cita', 'cuerpo' => '<b>Hola</b>'], $h)->status());
        $this->assertSame(422, $this->put('/plantillas/barberia_aprobada/push', ['asunto' => 'Lista', 'cuerpo' => '{{barberia}} el {{fecha}}'], $h)->status(), 'Los avisos de cuenta no tienen fecha');
        $this->assertSame(404, $this->put('/plantillas/no_existe/push', ['asunto' => 'a', 'cuerpo' => 'b'], $h)->status());

        $t = $this->ok($this->put('/plantillas/barberia_aprobada/push', ['asunto' => '¡{{barberia}} ya está activa!', 'cuerpo' => 'Bienvenida a BARBERAPP, {{barberia}}.'], $h));
        $this->assertTrue($t['personalizada']);
        $this->assertSame(2, $t['version']);
        $this->assertSame(['¡Barbería X ya está activa!', 'Bienvenida a BARBERAPP, Barbería X.'], Templates::render('barberia_aprobada', 'push', null, ['barberia' => 'Barbería X']));

        // La siguiente aprobación usa el texto nuevo.
        $this->ok($this->post('/barberias/' . $this->tenantId('demo-pendiente') . '/aprobar', [], $h));
        $this->assertSame('¡Barbería Demo Pendiente ya está activa!', $this->scalar("SELECT titulo FROM notificaciones WHERE tipo = 'barberia_aprobada' AND canal = 'interna'"));

        $pv = $this->ok($this->post('/plantillas/recordatorio/push/vista-previa', ['asunto' => 'Tu cita es {{cuando}}', 'cuerpo' => '{{barberia}} con {{barbero}}'], $h));
        $this->assertTrue($pv['ejemplo']);
        $this->assertSame('Barbería Ejemplo con Barbero Ejemplo', $pv['cuerpo']);

        $r = $this->ok($this->call('DELETE', '/plataforma/plantillas/barberia_aprobada/push', null, $h));
        $this->assertFalse($r['personalizada']);
        $this->assertSame(Templates::DEFAULTS['barberia_aprobada']['push'][1], $r['cuerpo']);
        $this->assertSame(1, $this->auditCount('plataforma.plantilla.actualizada', 'exito'));
        $this->assertSame(1, $this->auditCount('plataforma.plantilla.restaurada', 'exito'));
    }

    public function testSettingsValidateAndApply(): void
    {
        $h = $this->as(self::SUPER);
        $bad = $this->put('/configuracion', ['parametros' => ['plataforma.zona_horaria' => 'Lima/Peru', 'plataforma.otra' => 1], 'avisos' => ['recordatorios_horas' => [1, 2, 3, 4]]], $h);
        $this->assertSame(422, $bad->status());
        $fields = $bad->decoded()['error']['details']['fields'];
        $this->assertArrayHasKey('plataforma.zona_horaria', $fields);
        $this->assertArrayHasKey('plataforma.otra', $fields);
        $this->assertArrayHasKey('avisos.recordatorios_horas', $fields);

        // Una barbería con regla propia no cambia; las demás heredan el nuevo valor global.
        $this->exec("INSERT INTO configuraciones (tenant_id, clave, valor) VALUES (?, 'notificaciones.recordatorios_horas', '[6]')", [$this->tenantId('demo-norte')]);
        $res = $this->put('/configuracion', [
            'parametros' => ['plataforma.soporte_email' => 'ayuda@barberapp.test', 'plataforma.aprobacion_manual_tenants' => false],
            'avisos' => ['recordatorios_horas' => [48, 3], 'canal_email' => false],
        ], $h);
        $d = $this->ok($res);
        $this->assertEqualsCanonicalizing(['plataforma.soporte_email', 'plataforma.aprobacion_manual_tenants', 'notificaciones.recordatorios_horas', 'notificaciones.canal_email'], $res->decoded()['meta']['cambios']);
        $this->assertSame(1, $d['barberias_con_reglas_propias']);
        NotificationSettings::flush();
        $this->assertSame([48, 3], (new NotificationSettings($this->tenantId('demo-centro')))->hours());
        $this->assertFalse((new NotificationSettings($this->tenantId('demo-centro')))->channelEnabled('email'));
        $this->assertSame([6], (new NotificationSettings($this->tenantId('demo-norte')))->hours());
        $this->assertSame(1, $this->auditCount('plataforma.configuracion.actualizada', 'exito'));

        // Sin aprobación manual, una barbería nueva nace activa.
        $this->cookies = [];
        $reg = $this->call('POST', '/auth/register', [
            'tipo' => 'propietario', 'email' => 'sin.aprobacion@barberapp.test', 'password' => 'Navaja2026x', 'nombres' => 'Rápida',
            'acepta_terminos' => true, 'barberia' => ['nombre_comercial' => 'Barbería Sin Espera'],
        ]);
        $this->assertSame('activo', $this->data($reg)['barberia']['estado']);

        // El panel de una barbería suspendida muestra el correo de soporte configurado.
        $this->exec("UPDATE tenants SET estado = 'suspendido', motivo_suspension = 'Prueba' WHERE slug = 'demo-centro'");
        $ctx = $this->data($this->call('GET', '/panel/contexto', null, $this->as(self::PROPIETARIO) + ['X-Tenant' => 'demo-centro']));
        $this->assertSame('ayuda@barberapp.test', $ctx['aviso_estado']['soporte_email']);
    }

    public function testAuditBrowserFiltersAndPaginates(): void
    {
        $h = $this->as(self::SUPER);
        // Intentos denegados de un cliente al panel SaaS.
        $c = $this->as(self::CLIENTE);
        for ($i = 0; $i < 3; $i++) {
            $this->get('/dashboard', $c);
        }
        $h = $this->as(self::SUPER);
        $den = $this->ok($this->get('/auditoria?accion=authz.plataforma&resultado=denegado', $h));
        $this->assertCount(3, $den);
        $this->assertSame(self::CLIENTE, $den[0]['usuario']['email']);
        $this->assertSame('GET /api/v1/plataforma/dashboard', $den[0]['datos_nuevos']['ruta']);
        $this->assertSame('203.0.113.10', $den[0]['ip']);

        $page1 = $this->get('/auditoria?accion=authz.plataforma&per_page=10', $h);
        $this->assertNull($page1->decoded()['meta']['siguiente']);
        $this->ok($this->post('/barberias/' . $this->tenantId('demo-centro') . '/suspender', ['motivo' => 'Motivo de prueba'], $h));
        $porBarberia = $this->ok($this->get('/auditoria?tenant=demo-centro&accion=plataforma.barberia', $h));
        $this->assertSame('plataforma.barberia.suspendida', $porBarberia[0]['accion']);
        $this->assertSame(['estado' => 'activo'], $porBarberia[0]['datos_anteriores']);

        // Paginación por cursor.
        $all = $this->ok($this->get('/auditoria?per_page=10', $h));
        $res = $this->get('/auditoria?per_page=10', $h);
        $next = $res->decoded()['meta']['siguiente'];
        if ($next !== null) {
            $more = $this->ok($this->get("/auditoria?per_page=10&antes_de={$next}", $h));
            $this->assertLessThan(end($all)['id'], $more[0]['id']);
        }
        $acciones = array_column($this->ok($this->get('/auditoria/acciones', $h)), 'accion');
        $this->assertContains('authz.plataforma_denegado', $acciones);
    }
}
