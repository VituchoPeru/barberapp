<?php

declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Services\Mail\Mailer;
use Tests\Integration\Platform\PlatformTestCase;

/** Solicitudes de derechos ARCO: la persona las envía y el equipo de BARBERAPP las responde. */
final class DataRequestsTest extends PlatformTestCase
{
    public function testClientSubmitsAndOperatorAnswers(): void
    {
        $h = $this->as(self::CLIENTE);
        $bad = $this->call('POST', '/me/solicitudes-datos', ['tipo' => 'otro', 'detalle' => 'corto'], $h);
        $this->assertSame(422, $bad->status());

        Mailer::reset();
        $res = $this->call('POST', '/me/solicitudes-datos', ['tipo' => 'oposicion', 'detalle' => 'No quiero recibir promociones de ninguna barbería.'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $sol = $this->data($res);
        $this->assertSame('recibida', $sol['estado']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $sol['plazo_vence_en']);
        $this->assertGreaterThan(gmdate('Y-m-d'), $sol['plazo_vence_en']);
        $this->assertCount(1, Mailer::sent(), 'Acuse de recibo');
        $this->assertCount(1, $this->data($this->call('GET', '/me/solicitudes-datos', null, $h)));

        // Un cliente no entra a la bandeja de la plataforma.
        $this->assertSame(403, $this->get('/solicitudes-datos', $h)->status());

        // El operador la ve (con nombre y correo para responder) y la atiende.
        $op = $this->as(self::OPERADOR);
        $inbox = $this->data($this->get('/solicitudes-datos?estado=abiertas', $op));
        $item = array_values(array_filter($inbox['items'], static fn ($i) => $i['id'] === $sol['id']))[0];
        $this->assertSame(self::CLIENTE, $item['persona']['email']);
        $this->assertGreaterThanOrEqual(1, $inbox['resumen']['abiertas']);

        $this->assertSame(422, $this->post("/solicitudes-datos/{$sol['id']}/responder", ['estado' => 'atendida', 'respuesta' => ''], $op)->status());
        Mailer::reset();
        $ok = $this->post("/solicitudes-datos/{$sol['id']}/responder", ['estado' => 'atendida', 'respuesta' => 'Desactivamos el uso de tus datos para promociones en todas las barberías.'], $op);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame('atendida', $this->data($ok)['estado']);
        $this->assertCount(1, Mailer::sent());
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND tipo = 'privacidad_solicitud'", [$this->userId(self::CLIENTE)]));
        $this->assertSame(409, $this->post("/solicitudes-datos/{$sol['id']}/responder", ['estado' => 'rechazada', 'respuesta' => 'Otra respuesta distinta.'], $op)->status());
        $this->assertSame(1, $this->auditCount('privacidad.solicitud_respondida', 'exito'));
    }

    public function testOpenRequestsAreCappedPerPerson(): void
    {
        $h = $this->as(self::CLIENTE);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(201, $this->call('POST', '/me/solicitudes-datos', ['tipo' => 'acceso', 'detalle' => "Solicitud de prueba número {$i}"], $h)->status());
        }
        $this->assertSame(409, $this->call('POST', '/me/solicitudes-datos', ['tipo' => 'acceso', 'detalle' => 'Una más de las permitidas'], $h)->status());
    }

    public function testLegalSettingsAreEditableAndValidated(): void
    {
        $h = $this->as(self::SUPER);
        $bad = $this->put('/configuracion', ['parametros' => ['plataforma.legal_ruc' => '123', 'plataforma.legal_domicilio' => '<script>']], $h);
        $this->assertSame(422, $bad->status());
        $ok = $this->put('/configuracion', ['parametros' => [
            'plataforma.legal_razon_social' => 'Empresa de Prueba S.A.C.', 'plataforma.legal_ruc' => '20123456789',
            'plataforma.legal_domicilio' => 'Av. Ejemplo 123, Lima', 'plataforma.privacidad_email' => 'privacidad@ejemplo.pe',
        ]], $h);
        $this->assertSame(200, $ok->status(), $ok->body());
        $legal = $this->data($this->call('GET', '/legal'));
        $this->assertTrue($legal['configuracion_completa']);
        $this->assertSame('20123456789', $legal['responsable']['ruc']);
    }
}
