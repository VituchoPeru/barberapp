<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Core\Logger;
use App\Services\Platform\PlatformMetrics;

/** Indicadores globales: se calculan con datos reales y la demostración se separa (Fase 9). */
final class PlatformMetricsTest extends PlatformTestCase
{
    public function testDashboardMatchesDatabaseAndFlagsDemoData(): void
    {
        $h = $this->as(self::SUPER);
        $d = $this->ok($this->get('/dashboard', $h));
        $this->assertSame((int) $this->scalar('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL'), $d['barberias']['registradas']);
        $this->assertSame((int) $this->scalar("SELECT COUNT(*) FROM tenants WHERE estado = 'activo'"), $d['barberias']['activas']);
        $this->assertSame(1, $d['barberias']['suspendidas']);
        $this->assertSame(1, $d['barberias']['pendientes']);
        $this->assertSame(6, $d['demo']['barberias'], 'Todas las barberías sembradas son de demostración');
        $this->assertSame(1, $d['suscripciones']['activas']);
        $this->assertSame(1, $d['suscripciones']['en_prueba']);
        $this->assertSame([['moneda' => 'PEN', 'total' => '79.00']], $d['mrr'], 'Solo la suscripción activa (la prueba no suma)');
        $this->assertSame(
            (int) $this->scalar('SELECT COUNT(*) FROM reservas WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY'),
            $d['reservas_30_dias']['total']
        );

        // Sin la demostración no queda nada: ningún indicador se rellena.
        $real = $this->ok($this->get('/dashboard?excluir_demo=1', $h));
        $this->assertTrue($real['excluye_demo']);
        $this->assertSame(0, $real['barberias']['registradas']);
        $this->assertSame([], $real['mrr']);
        $this->assertSame(0, $real['reservas_30_dias']['total']);
        $this->assertNull($real['churn_30_dias']['porcentaje'], 'Sin base no hay porcentaje inventado');
        $this->assertSame(6, $real['demo']['barberias']);
    }

    public function testMrrChurnAndNewSignupsWithRealTenants(): void
    {
        $a = $this->realTenant('real-anual', 'activo', '-50 days');
        $this->subscription($a, 'premium', 'activa', '1200.00', '-45 days', '+320 days', 'anual');
        $b = $this->realTenant('real-mensual', 'activo', '-50 days');
        $this->subscription($b, 'profesional', 'activa', '79.00', '-40 days', '+20 days');
        $c = $this->realTenant('real-baja', 'activo', '-50 days');
        $this->subscription($c, 'basico', 'cancelada', '39.00', '-40 days', '+20 days', 'mensual', '-10 days');
        $p = $this->realTenant('real-prueba', 'activo', '-5 days');
        $this->subscription($p, 'basico', 'prueba', '39.00', '-5 days', '+9 days');
        $this->realTenant('real-nueva', 'pendiente', '-2 days');

        $d = $this->ok($this->get('/dashboard?excluir_demo=1', $this->as(self::SUPER)));
        $this->assertSame([['moneda' => 'PEN', 'total' => '179.00']], $d['mrr'], '1200 ÷ 12 + 79');
        $this->assertSame(2, $d['suscripciones']['activas']);
        $this->assertSame(1, $d['suscripciones']['en_prueba']);
        $this->assertSame(1, $d['suscripciones']['canceladas']);
        $this->assertSame(['base' => 3, 'perdidas' => 1, 'porcentaje' => 33.3], $d['churn_30_dias']);
        $this->assertSame(2, $d['barberias']['altas_30_dias']);
        $this->assertSame(3, $d['barberias']['altas_30_dias_anteriores']);
        $this->assertSame(1, $d['barberias']['pendientes']);
        $this->assertSame(1, $d['suscripciones']['sin_suscripcion']);

        $plans = [];
        foreach ($d['consumo_por_plan'] as $row) {
            $plans[$row['plan']['codigo']] = $row;
        }
        $this->assertSame(1, $plans['premium']['barberias']);
        $this->assertSame('100.00', $plans['premium']['mrr']);
        $this->assertSame(1, $plans['basico']['en_prueba']);
        $this->assertSame('0.00', $plans['basico']['mrr']);
    }

    public function testUsageByPlanReportsTenantsOverTheirLimits(): void
    {
        $t = $this->realTenant('real-grande');
        $this->subscription($t, 'basico', 'activa', '39.00', '-5 days', '+25 days');
        foreach (['uno', 'dos'] as $i => $s) {
            $this->exec("INSERT INTO sucursales (tenant_id, nombre, slug, direccion, distrito, es_principal) VALUES (?, ?, ?, 'Av. Real 1', 'Lince', ?)", [$t, "Sede {$s}", "sede-{$s}", $i === 0 ? 1 : 0]);
        }
        $d = $this->ok($this->get('/dashboard?excluir_demo=1', $this->as(self::SUPER)));
        $basico = array_values(array_filter($d['consumo_por_plan'], static fn (array $r): bool => $r['plan']['codigo'] === 'basico'))[0];
        $this->assertSame(2, $basico['sucursales']);
        $this->assertSame(1, $basico['plan']['max_sucursales']);
        $this->assertSame(1, $basico['exceden_sucursales']);
        $this->assertSame(0, $basico['exceden_barberos']);
    }

    public function testMonthlyReportAndCsvExport(): void
    {
        $t = $this->realTenant('real-pagos');
        $sid = $this->subscription($t, 'basico', 'activa', '39.00', '-1 minute', '+30 days');
        $this->exec(
            "INSERT INTO pagos_saas (tenant_id, suscripcion_id, monto, metodo, estado, periodo_inicio, periodo_fin, pagado_en) VALUES (?, ?, '39.00', 'yape', 'confirmado', CURDATE(), CURDATE() + INTERVAL 30 DAY, UTC_TIMESTAMP())",
            [$t, $sid]
        );
        $this->exec(
            "INSERT INTO pagos_saas (tenant_id, suscripcion_id, monto, metodo, estado, periodo_inicio, periodo_fin, pagado_en) VALUES (?, ?, '500.00', 'yape', 'pendiente', CURDATE(), CURDATE(), NULL)",
            [$t, $sid]
        );
        $h = $this->as(self::SUPER);
        $r = $this->ok($this->get('/reportes?meses=6&excluir_demo=1', $h));
        $this->assertCount(6, $r['meses']);
        $this->assertTrue($r['excluye_demo']);
        $this->assertSame((int) $this->scalar('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND es_demo = 1'), $r['demo']['barberias'], 'El reporte dice cuántas barberías demo hay');
        $actual = end($r['meses']);
        $this->assertSame('39.00', $actual['ingresos'], 'Solo pagos confirmados; los pendientes no son ingresos');
        $this->assertSame(1, $actual['altas_barberias']);
        $this->assertSame(1, $actual['suscripciones_nuevas']);
        $this->assertSame(1, $actual['barberias_con_suscripcion_vigente']);
        $this->assertSame(39.0, array_sum(array_map('floatval', array_column($r['meses'], 'ingresos'))), 'Los meses anteriores no tienen ingresos reales');

        $dash = $this->ok($this->get('/dashboard?excluir_demo=1', $h));
        $this->assertSame('39.00', $dash['ingresos_mes'][0]['total']);
        $this->assertSame('500.00', $dash['ingresos_mes'][0]['por_confirmar']);

        $csv = $this->get('/reportes?meses=3&excluir_demo=1&formato=csv', $h);
        $this->assertSame(200, $csv->status());
        $this->assertStringStartsWith("\xEF\xBB\xBFmes,altas_barberias", $csv->body());
        $this->assertCount(4, array_filter(explode("\n", trim($csv->body()))));
        $this->assertSame(1, $this->auditCount('plataforma.reportes.exportado', 'exito'));
        $this->assertSame(24, count($this->data($this->get('/reportes?meses=99', $h))['meses']), 'Máximo 24 meses');
    }

    public function testSystemStatusAlertsAboutStuckQueueAndLogErrors(): void
    {
        $u = $this->userId(self::CLIENTE);
        $this->exec(
            "INSERT INTO notificaciones (usuario_id, canal, tipo, titulo, cuerpo, estado, programada_para_utc) VALUES (?, 'email', 'prueba', 't', 'c', 'programada', UTC_TIMESTAMP() - INTERVAL 1 HOUR)",
            [$u]
        );
        Logger::error('Error de prueba del panel SaaS');
        $s = $this->ok($this->get('/sistema', $this->as(self::SUPER)));
        $this->assertGreaterThanOrEqual(1, $s['cola']['atrasadas']);
        $this->assertContains('cola_atrasada', array_column($s['alertas'], 'codigo'));
        $this->assertGreaterThanOrEqual(1, $s['log']['total']);
        $this->assertContains('Error de prueba del panel SaaS', array_column($s['log']['ultimos'], 'mensaje'));
        $this->assertSame(PHP_VERSION, $s['app']['php']);
        $this->assertStringNotContainsString(self::CLIENTE, json_encode($s));
    }

    public function testMonthlyHelperAnnualIsTwelfth(): void
    {
        $this->assertSame(100.0, PlatformMetrics::monthly(['precio_pactado' => '1200.00', 'ciclo' => 'anual']));
        $this->assertSame(79.0, PlatformMetrics::monthly(['precio_pactado' => '79.00', 'ciclo' => 'mensual']));
    }
}
