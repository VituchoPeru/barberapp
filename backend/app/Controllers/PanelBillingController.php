<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\Gate;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateway\Gateways;
use App\Services\Billing\Gateway\SandboxGateway;
use App\Services\Billing\PaymentProcessor;

/**
 * Suscripción de la barbería en su panel (Fase 10).
 *   GET    /api/v1/panel/suscripcion                       Plan, uso, planes, pagos y órdenes   (negocio.suscripcion.ver)
 *   POST   /api/v1/panel/suscripcion/cotizar               {plan_id, ciclo, periodos} → importe (negocio.suscripcion.gestionar)
 *   POST   /api/v1/panel/suscripcion/pagar                 Crea la orden y devuelve la URL de pago
 *   POST   /api/v1/panel/suscripcion/cambiar-plan          Prueba: inmediato; con periodo pagado: programado
 *   DELETE /api/v1/panel/suscripcion/cambio-programado     Anula el cambio programado
 *   GET    /api/v1/panel/suscripcion/ordenes/{codigo}?payment_id=   Estado de una orden (al volver de pagar)
 *   POST   /api/v1/panel/suscripcion/ordenes/{codigo}/simular       Simulador local {aprobar: bool} (solo PAYMENT_DRIVER=sandbox)
 */
final class PanelBillingController extends BaseController
{
    public function show(Request $request): Response
    {
        return $this->ok($this->svc($request)->overview($this->canManage($request)));
    }

    public function quote(Request $request): Response
    {
        $b = $request->json();
        return $this->ok($this->svc($request)->quote((int) ($b['plan_id'] ?? 0), (string) ($b['ciclo'] ?? 'mensual'), (int) ($b['periodos'] ?? 1)));
    }

    public function pay(Request $request): Response
    {
        return $this->created($this->svc($request)->checkout($request->json(), $request));
    }

    public function changePlan(Request $request): Response
    {
        return $this->ok($this->svc($request)->changePlan($request->json(), $request));
    }

    public function cancelScheduled(Request $request): Response
    {
        return $this->ok($this->svc($request)->cancelScheduled($request));
    }

    public function order(Request $request): Response
    {
        $codigo = (string) $request->param('codigo');
        $svc = $this->svc($request);
        $svc->order($codigo); // 404 si no es de esta barbería
        $pid = $request->query('payment_id') ?? $request->query('collection_id');
        $sync = (new PaymentProcessor())->syncOrder((int) $this->tenant($request)['id'], $codigo, is_string($pid) ? $pid : null);
        return $this->ok(['orden' => $svc->order($codigo), 'sincronizacion' => $sync]);
    }

    /** Página de pago simulada: genera el webhook firmado y lo procesa por el camino normal. */
    public function simulate(Request $request): Response
    {
        if (Gateways::driver() !== 'sandbox') {
            throw new HttpException(404, 'not_found', 'Recurso no encontrado.');
        }
        $codigo = (string) $request->param('codigo');
        $svc = $this->svc($request);
        $orden = $svc->order($codigo);
        if ($orden['estado'] !== 'pendiente') {
            throw new HttpException(409, 'order_closed', 'Esta orden ya no admite pagos.');
        }
        $row = ['codigo' => $codigo, 'monto' => $orden['monto'], 'moneda' => $orden['moneda']];
        $wh = SandboxGateway::webhook(SandboxGateway::paymentId($row, (bool) ($request->json()['aprobar'] ?? true)));
        $hook = new Request('POST', '/api/v1/webhooks/pagos/sandbox', $wh['query'], $wh['headers'] + ['content-type' => 'application/json'], (string) json_encode($wh['body']), ['REMOTE_ADDR' => $request->ip()]);
        $res = (new PaymentProcessor())->handleWebhook('sandbox', $hook);
        return $this->ok(['orden' => $svc->order($codigo), 'resultado' => $res['resultado']]);
    }

    private function svc(Request $request): BillingService
    {
        return new BillingService($this->tenant($request));
    }

    private function canManage(Request $request): bool
    {
        $roles = ((array) $request->attribute('membership'))['roles'] ?? [];
        return in_array('negocio.suscripcion.gestionar', Gate::rolePermissions($roles), true);
    }
}
