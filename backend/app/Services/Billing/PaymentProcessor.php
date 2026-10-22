<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Services\Audit;
use App\Services\Billing\Gateway\GatewayError;
use App\Services\Billing\Gateway\Gateways;
use App\Services\Billing\Gateway\InvalidWebhook;
use App\Services\Billing\Gateway\PaymentGateway;
use App\Services\Platform\SubscriptionService;
use PDOException;

/**
 * Procesa los pagos en línea (Fase 10): webhooks de la pasarela y la consulta al volver de pagar.
 *
 * Reglas:
 *  - Solo se procesan webhooks con firma válida; cada evento se registra una vez en webhook_eventos.
 *  - El estado del pago se consulta SIEMPRE a la pasarela; el cuerpo del webhook no prueba nada.
 *  - La orden se bloquea (FOR UPDATE): webhooks repetidos o simultáneos aplican el pago una sola vez.
 *  - Importe o moneda distintos, una suscripción que cambió o un segundo pago de la misma orden no
 *    se aplican solos: el pago queda «pendiente» en pagos_saas para que el equipo lo revise.
 *  - Un reembolso o contracargo marca el pago como reembolsado (no acorta el periodo, como en la Fase 9).
 */
final class PaymentProcessor extends BaseRepository
{
    private SubscriptionService $subs;

    public function __construct()
    {
        parent::__construct();
        $this->subs = new SubscriptionService();
    }

    /** @return array{status:int, resultado:string} */
    public function handleWebhook(string $provider, Request $request): array
    {
        $gateway = Gateways::make($provider);
        try {
            $evt = $gateway->parseWebhook($request);
        } catch (InvalidWebhook $e) {
            Audit::denied('pagos.webhook_firma_invalida', $request, ['proveedor' => $provider, 'motivo' => $e->getMessage()]);
            return ['status' => 401, 'resultado' => 'firma_invalida'];
        }
        $payload = json_decode($request->rawBody(), true);
        $this->execute(
            'INSERT INTO webhook_eventos (proveedor, evento_id_externo, tipo, payload, firma_valida, estado) VALUES (:p, :e, :t, :pl, 1, \'recibido\')
             ON DUPLICATE KEY UPDATE intentos = intentos + 1',
            ['p' => $gateway->name(), 'e' => mb_substr($evt['evento_id'], 0, 191), 't' => mb_substr($evt['tipo'] !== '' ? $evt['tipo'] : 'desconocido', 0, 100),
                'pl' => json_encode(is_array($payload) ? $payload : ['raw' => mb_substr($request->rawBody(), 0, 2000)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        $row = (array) $this->fetchOne('SELECT id, estado FROM webhook_eventos WHERE proveedor = :p AND evento_id_externo = :e',
            ['p' => $gateway->name(), 'e' => mb_substr($evt['evento_id'], 0, 191)]);
        if (($row['estado'] ?? '') === 'procesado' || ($row['estado'] ?? '') === 'ignorado') {
            return ['status' => 200, 'resultado' => 'duplicado'];
        }
        if ($evt['pago_id'] === null) {
            $this->markEvent((int) $row['id'], 'ignorado', null, null);
            return ['status' => 200, 'resultado' => 'ignorado'];
        }
        try {
            $payment = $gateway->fetchPayment($evt['pago_id']);
        } catch (GatewayError $e) {
            $this->markEvent((int) $row['id'], 'fallido', mb_substr($e->getMessage(), 0, 1000), null);
            // 5xx: la pasarela reintenta más tarde.
            return ['status' => 503, 'resultado' => 'pasarela_no_disponible'];
        }
        [$resultado, $tenantId] = $this->apply($gateway, $payment);
        $this->markEvent((int) $row['id'], $resultado === 'ignorado' ? 'ignorado' : 'procesado', null, $tenantId);
        return ['status' => 200, 'resultado' => $resultado];
    }

    /**
     * Al volver de la pasarela: consulta el pago indicado y lo aplica si pertenece a la orden.
     * Sirve cuando el webhook aún no llegó (o no puede llegar, p. ej. en un XAMPP local).
     */
    public function syncOrder(int $tenantId, string $codigo, ?string $paymentId): string
    {
        $o = $this->fetchOne('SELECT * FROM ordenes_pago WHERE tenant_id = :t AND codigo = :c', ['t' => $tenantId, 'c' => $codigo]);
        if ($o === null || $paymentId === null || $paymentId === '' || $o['estado'] === 'pagada') {
            return 'sin_cambios';
        }
        $gateway = Gateways::make((string) $o['pasarela']);
        try {
            $payment = $gateway->fetchPayment($paymentId);
        } catch (GatewayError $e) {
            return 'pasarela_no_disponible';
        }
        if ($payment['referencia'] !== $codigo) {
            return 'sin_cambios';
        }
        return $this->apply($gateway, $payment)[0];
    }

    /**
     * @param array<string, mixed> $payment PaymentGateway::fetchPayment
     * @return array{0:string, 1:?int} [resultado, tenant_id]
     */
    public function apply(PaymentGateway $gateway, array $payment): array
    {
        if ($payment['estado'] === 'reembolsado') {
            return $this->refund($gateway, $payment);
        }
        if ($payment['referencia'] === null) {
            return ['ignorado', null];
        }
        $after = null;
        $out = Database::transaction(function () use ($gateway, $payment, &$after): array {
            $o = $this->fetchOne('SELECT * FROM ordenes_pago WHERE codigo = :c FOR UPDATE', ['c' => (string) $payment['referencia']]);
            if ($o === null || $o['pasarela'] !== $gateway->name()) {
                return ['ignorado', null];
            }
            $tenantId = (int) $o['tenant_id'];
            if ($payment['estado'] === 'rechazado') {
                if (in_array($o['estado'], ['pendiente', 'expirada'], true)) {
                    $this->execute("UPDATE ordenes_pago SET estado = 'fallida', error = :e WHERE id = :id",
                        ['e' => mb_substr('Pago rechazado por la pasarela (' . $payment['estado_pasarela'] . ')', 0, 500), 'id' => (int) $o['id']]);
                }
                return ['rechazado', $tenantId];
            }
            if ($payment['estado'] !== 'aprobado') {
                return ['pendiente', $tenantId];
            }
            if ($o['estado'] === 'pagada') {
                return $o['pago_externo_id'] === $payment['id'] ? ['duplicado', $tenantId] : [$this->review($o, $payment, 'Segundo pago aprobado para una orden ya pagada'), $tenantId];
            }
            if ($this->paymentExists($gateway->name(), (string) $payment['id'])) {
                return ['duplicado', $tenantId];
            }
            if ($payment['monto'] !== (string) $o['monto'] || $payment['moneda'] !== (string) $o['moneda']) {
                return [$this->review($o, $payment, "El importe pagado ({$payment['moneda']} {$payment['monto']}) no coincide con la orden ({$o['moneda']} {$o['monto']})"), $tenantId];
            }
            $this->exec('SAVEPOINT sp_aplicar_orden');
            try {
                [$subId, $ini, $fin] = $this->subs->applyOrder($o);
            } catch (HttpException $e) {
                $this->exec('ROLLBACK TO SAVEPOINT sp_aplicar_orden');
                return [$this->review($o, $payment, $e->getMessage()), $tenantId];
            }
            $pagoId = $this->insert(
                "INSERT INTO pagos_saas (tenant_id, suscripcion_id, orden_id, monto, moneda, metodo, estado, referencia_externa, periodo_inicio, periodo_fin, pagado_en, notas)
                 VALUES (:t, :s, :o, :m, :mon, 'pasarela', 'confirmado', :ref, :pi, :pf, :pe, :n)",
                ['t' => $tenantId, 's' => $subId, 'o' => (int) $o['id'], 'm' => (string) $o['monto'], 'mon' => (string) $o['moneda'], 'ref' => (string) $payment['id'],
                    'pi' => $ini, 'pf' => $fin, 'pe' => $payment['pagado_en'] ?? Clock::nowSql(), 'n' => mb_substr("Pago en línea ({$gateway->name()}) · orden {$o['codigo']}", 0, 500)]
            );
            $this->subs->event($this->subs->lock($subId), 'pago', ['notas' => "Pago en línea confirmado: {$o['moneda']} {$o['monto']}"], null, $pagoId);
            $this->execute("UPDATE ordenes_pago SET estado = 'pagada', pago_saas_id = :p, pago_externo_id = :x, pagada_en = :pe, error = NULL WHERE id = :id",
                ['p' => $pagoId, 'x' => (string) $payment['id'], 'pe' => Clock::nowSql(), 'id' => (int) $o['id']]);
            $after = ['tenant_id' => $tenantId, 'pago_id' => $pagoId, 'orden' => $o, 'suscripcion_id' => $subId];
            return ['aplicado', $tenantId];
        });
        if ($after !== null) {
            Entitlements::flush($after['tenant_id']);
            Audit::record('suscripcion.pago_en_linea', 'exito', null, null, $after['tenant_id'], 'pagos_saas', $after['pago_id'],
                ['orden' => $after['orden']['codigo'], 'tipo' => $after['orden']['tipo'], 'monto' => (string) $after['orden']['monto'], 'pasarela' => $gateway->name()]);
            BillingNotifier::paymentConfirmed($after['tenant_id'], $after['suscripcion_id'], (string) $after['orden']['monto'], (string) $after['orden']['moneda'], $after['pago_id']);
        }
        return $out;
    }

    /**
     * Pago aprobado que no se puede aplicar solo: queda pendiente para el equipo de BARBERAPP.
     *
     * @param array<string, mixed> $o
     * @param array<string, mixed> $payment
     */
    private function review(array $o, array $payment, string $motivo): string
    {
        $tenantId = (int) $o['tenant_id'];
        $subId = $o['suscripcion_id'] !== null ? (int) $o['suscripcion_id']
            : (int) ($this->fetchOne('SELECT MAX(id) id FROM suscripciones WHERE tenant_id = :t', ['t' => $tenantId])['id'] ?? 0);
        $pagoId = null;
        if ($subId > 0 && !$this->paymentExists((string) $o['pasarela'], (string) $payment['id'])) {
            try {
                $hoy = Clock::nowUtc()->format('Y-m-d');
                $pagoId = $this->insert(
                    "INSERT INTO pagos_saas (tenant_id, suscripcion_id, orden_id, monto, moneda, metodo, estado, referencia_externa, periodo_inicio, periodo_fin, notas)
                     VALUES (:t, :s, :o, :m, :mon, 'pasarela', 'pendiente', :ref, :pi, :pf, :n)",
                    ['t' => $tenantId, 's' => $subId, 'o' => (int) $o['id'], 'm' => $payment['monto'] !== '0.00' ? $payment['monto'] : (string) $o['monto'], 'mon' => (string) $o['moneda'],
                        'ref' => (string) $payment['id'], 'pi' => $hoy, 'pf' => $hoy, 'n' => mb_substr("Pago en línea por revisar: {$motivo}. Orden {$o['codigo']}", 0, 500)]
                );
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        $this->execute('UPDATE ordenes_pago SET error = :e, pago_externo_id = COALESCE(pago_externo_id, :x) WHERE id = :id',
            ['e' => mb_substr("Requiere revisión: {$motivo}", 0, 500), 'x' => (string) $payment['id'], 'id' => (int) $o['id']]);
        Audit::record('suscripcion.pago_por_revisar', 'error', null, null, $tenantId, 'ordenes_pago', (int) $o['id'],
            ['motivo' => $motivo, 'pago_externo' => (string) $payment['id'], 'pago_saas_id' => $pagoId]);
        return 'revision';
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{0:string, 1:?int}
     */
    private function refund(PaymentGateway $gateway, array $payment): array
    {
        return Database::transaction(function () use ($gateway, $payment): array {
            $pg = $this->fetchOne("SELECT pg.* FROM pagos_saas pg JOIN ordenes_pago o ON o.id = pg.orden_id
                                    WHERE pg.metodo = 'pasarela' AND pg.referencia_externa = :r AND o.pasarela = :p FOR UPDATE",
                ['r' => (string) $payment['id'], 'p' => $gateway->name()]);
            if ($pg === null) {
                return ['ignorado', null];
            }
            if ($pg['estado'] !== 'confirmado') {
                return ['duplicado', (int) $pg['tenant_id']];
            }
            $notas = mb_substr(trim(((string) ($pg['notas'] ?? '')) . ' · Reembolsado en la pasarela (' . $payment['estado_pasarela'] . ')', ' ·'), 0, 500);
            $this->execute("UPDATE pagos_saas SET estado = 'reembolsado', notas = :n WHERE id = :id", ['n' => $notas, 'id' => (int) $pg['id']]);
            $this->subs->event($this->subs->lock((int) $pg['suscripcion_id']), 'pago', ['notas' => 'Pago reembolsado en la pasarela'], null, (int) $pg['id']);
            Audit::record('plataforma.pago.reembolsado', 'exito', null, null, (int) $pg['tenant_id'], 'pagos_saas', (int) $pg['id'], ['origen' => 'pasarela', 'estado' => $payment['estado_pasarela']]);
            return ['reembolsado', (int) $pg['tenant_id']];
        });
    }

    private function paymentExists(string $pasarela, string $paymentId): bool
    {
        return $this->fetchOne("SELECT pg.id FROM pagos_saas pg WHERE pg.metodo = 'pasarela' AND pg.referencia_externa = :r", ['r' => $paymentId]) !== null;
    }

    private function markEvent(int $id, string $estado, ?string $error, ?int $tenantId): void
    {
        $this->execute('UPDATE webhook_eventos SET estado = :e, ultimo_error = :err, procesado_en = IF(:e2 IN (\'procesado\',\'ignorado\'), :n, procesado_en), tenant_id = COALESCE(:t, tenant_id) WHERE id = :id',
            ['e' => $estado, 'err' => $error, 'e2' => $estado, 'n' => Clock::nowSql(), 't' => $tenantId, 'id' => $id]);
    }

    private function exec(string $sql): void
    {
        Database::connection()->exec($sql);
    }
}
