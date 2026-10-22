<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Services\Billing\OrderBrowser;
use App\Services\Platform\PlanService;
use App\Services\Platform\SubscriptionService;

/**
 * Planes, suscripciones y pagos del SaaS (Fase 9).
 *
 *   GET|POST /api/v1/plataforma/planes · PUT /planes/{id}                       (plataforma.planes.gestionar)
 *   GET  /api/v1/plataforma/suscripciones?estado=&plan=&q=&vence_en_dias=&tenant_id=&historial=1
 *   POST /api/v1/plataforma/suscripciones                                      (plataforma.suscripciones.gestionar)
 *   GET  /api/v1/plataforma/suscripciones/{id}
 *   POST /api/v1/plataforma/suscripciones/{id}/cambiar-plan | renovar | suspender | reactivar | cancelar
 *   GET  /api/v1/plataforma/pagos?estado=&metodo=&tenant_id=&desde=&hasta=&q=       (plataforma.pagos.gestionar)
 *   POST /api/v1/plataforma/suscripciones/{id}/pagos
 *   POST /api/v1/plataforma/pagos/{id}/confirmar | rechazar | reembolsar
 *   GET  /api/v1/plataforma/ordenes?estado=&tenant_id=&por_revisar=1              Pagos en línea (Fase 10)
 */
final class PlatformBillingController extends BaseController
{
    // ---------------- Planes

    public function plans(Request $request): Response
    {
        return $this->ok((new PlanService())->list());
    }

    public function storePlan(Request $request): Response
    {
        return $this->created((new PlanService())->create($request->json(), $request));
    }

    public function updatePlan(Request $request): Response
    {
        return $this->ok((new PlanService())->update((int) $request->param('id'), $request->json(), $request));
    }

    // ---------------- Suscripciones

    public function subscriptions(Request $request): Response
    {
        $pg = $this->pagination($request, 25, 100);
        $r = (new SubscriptionService())->list($request->allQuery(), $pg['per_page'], $pg['offset']);
        return $this->ok($r['items'], ['total' => $r['total'], 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    public function showSubscription(Request $request): Response
    {
        return $this->ok((new SubscriptionService())->show((int) $request->param('id')));
    }

    public function storeSubscription(Request $request): Response
    {
        $d = $this->validate($request, [
            'tenant_id' => 'required|integer|min:1',
            'plan_id' => 'required|integer|min:1',
            'estado' => 'required|in:prueba,activa',
            'ciclo' => 'nullable|in:mensual,anual',
            'precio_pactado' => 'nullable|decimal|min:0|max:999999.99',
            'dias_prueba' => 'nullable|integer|min:1|max:90',
            'periodos' => 'nullable|integer|min:1|max:24',
            'notas' => 'nullable|string|max:500',
        ]);
        return $this->created((new SubscriptionService())->create(array_filter($d, static fn ($v) => $v !== null), $request));
    }

    public function changePlan(Request $request): Response
    {
        $d = $this->validate($request, [
            'plan_id' => 'required|integer|min:1',
            'ciclo' => 'nullable|in:mensual,anual',
            'precio_pactado' => 'nullable|decimal|min:0|max:999999.99',
            'notas' => 'nullable|string|max:500',
        ]);
        return $this->ok((new SubscriptionService())->changePlan((int) $request->param('id'), array_filter($d, static fn ($v) => $v !== null), $request));
    }

    public function renew(Request $request): Response
    {
        $d = $this->validate($request, ['periodos' => 'required|integer|min:1|max:24', 'notas' => 'nullable|string|max:500']);
        return $this->ok((new SubscriptionService())->renew((int) $request->param('id'), (int) $d['periodos'], $d['notas'] ?? null, $request));
    }

    public function suspend(Request $request): Response
    {
        return $this->ok((new SubscriptionService())->suspend((int) $request->param('id'), $this->motivo($request), $request));
    }

    public function cancel(Request $request): Response
    {
        return $this->ok((new SubscriptionService())->cancel((int) $request->param('id'), $this->motivo($request), $request));
    }

    public function reactivate(Request $request): Response
    {
        $d = $this->validate($request, ['notas' => 'nullable|string|max:500']);
        return $this->ok((new SubscriptionService())->reactivate((int) $request->param('id'), $d['notas'] ?? null, $request));
    }

    // ---------------- Pagos

    public function payments(Request $request): Response
    {
        $pg = $this->pagination($request, 25, 100);
        $r = (new SubscriptionService())->payments($request->allQuery(), $pg['per_page'], $pg['offset']);
        return $this->ok($r['items'], ['total' => $r['total'], 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'totales' => $r['totales']]);
    }

    public function storePayment(Request $request): Response
    {
        $d = $this->validate($request, [
            'monto' => 'required|decimal|min:0.01|max:999999.99',
            'metodo' => 'required|in:' . implode(',', SubscriptionService::METODOS),
            'referencia' => 'nullable|string|max:120',
            'estado' => 'nullable|in:confirmado,pendiente',
            'pagado_en' => 'nullable|date',
            'periodos' => 'nullable|integer|min:0|max:24',
            'notas' => 'nullable|string|max:500',
        ]);
        return $this->created((new SubscriptionService())->registerPayment((int) $request->param('id'), $d, $request));
    }

    public function confirmPayment(Request $request): Response
    {
        $d = $this->validate($request, ['periodos' => 'nullable|integer|min:0|max:24', 'pagado_en' => 'nullable|date']);
        return $this->ok((new SubscriptionService())->confirmPayment((int) $request->param('id'), (int) ($d['periodos'] ?? 1), $d['pagado_en'] ?? null, $request));
    }

    public function rejectPayment(Request $request): Response
    {
        return $this->ok((new SubscriptionService())->rejectPayment((int) $request->param('id'), $this->motivo($request), $request));
    }

    public function refundPayment(Request $request): Response
    {
        return $this->ok((new SubscriptionService())->refundPayment((int) $request->param('id'), $this->motivo($request), $request));
    }

    private function motivo(Request $request): string
    {
        return (string) Validator::validate($request->json(), ['motivo' => 'required|string|min:5|max:255'])['motivo'];
    }

    // ---------------- Órdenes de pago en línea (Fase 10)

    public function orders(Request $request): Response
    {
        $pg = $this->pagination($request, 25, 100);
        $r = (new OrderBrowser())->list($request->allQuery(), $pg['per_page'], $pg['offset']);
        return $this->ok($r['items'], ['total' => $r['total'], 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'pasarela' => $r['pasarela']]);
    }
}
