<?php

declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Core\Config;
use App\Services\Privacy\Retention;
use Tests\Integration\DatabaseTestCase;

/** Política de retención (privacidad:retencion). */
final class RetentionTest extends DatabaseTestCase
{
    public function testOldRecordsAndUnusedUnverifiedAccountsArePurged(): void
    {
        Config::set('privacy.retencion', ['auditoria_dias' => 365, 'webhooks_dias' => 180, 'cuentas_sin_verificar_dias' => 90, 'solicitudes_dias' => 1825]);
        $this->exec("INSERT INTO auditoria (accion, resultado, created_at) VALUES ('prueba.vieja', 'exito', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 400 DAY)), ('prueba.nueva', 'exito', UTC_TIMESTAMP())");
        $this->exec("INSERT INTO webhook_eventos (proveedor, evento_id_externo, tipo, payload, firma_valida, estado, recibido_en)
                     VALUES ('sandbox', 'ret-1', 'payment', '{}', 1, 'procesado', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 DAY)),
                            ('sandbox', 'ret-2', 'payment', '{}', 1, 'recibido', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 DAY))");
        $this->exec("INSERT INTO usuarios (email, password_hash, nombres, created_at) VALUES ('viejo@ejemplo.pe', 'x', 'Viejo', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 DAY)),
                     ('reciente@ejemplo.pe', 'x', 'Reciente', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))");
        $this->exec("INSERT INTO usuarios (email, password_hash, nombres, email_verificado_en, created_at) VALUES ('verificado@ejemplo.pe', 'x', 'Verificado', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 DAY))");

        $r = (new Retention())->run();

        $this->assertGreaterThanOrEqual(1, $r['auditoria']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM auditoria WHERE accion = 'prueba.vieja'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM auditoria WHERE accion = 'prueba.nueva'"));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM webhook_eventos WHERE evento_id_externo = 'ret-1'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM webhook_eventos WHERE evento_id_externo = 'ret-2'"), 'Un webhook sin procesar no se borra');
        $this->assertSame(1, $r['cuentas_sin_verificar']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM usuarios WHERE email = 'viejo@ejemplo.pe'"));
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM usuarios WHERE email IN ('reciente@ejemplo.pe', 'verificado@ejemplo.pe') AND estado = 'activo'"));
    }
}
