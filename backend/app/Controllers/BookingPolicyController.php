<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Repositories\BookingRepository;
use App\Services\Audit;
use App\Services\Booking\BookingPolicy;

/**
 * Reglas de reserva de la barbería (Fase 5). La general aplica a todas las sucursales; una
 * sucursal puede tener la suya propia.
 *   GET    /api/v1/panel/politicas-reserva                 General + propias de sucursales
 *   PUT    /api/v1/panel/politicas-reserva                 Guarda (sucursal_id null = general)
 *   DELETE /api/v1/panel/politicas-reserva/{sucursal_id}   La sucursal vuelve a usar la general
 */
final class BookingPolicyController extends BaseController
{
    public function index(Request $request): Response
    {
        $repo = $this->repo($request);
        $rows = $repo->policyRows();
        $general = null;
        $sucursales = [];
        foreach ($rows as $r) {
            $p = BookingPolicy::fromRow($r)->toArray();
            if ($r['sucursal_id'] === null) {
                $general = $p;
            } else {
                $sucursales[] = $p + ['sucursal' => $r['sucursal']];
            }
        }
        return $this->ok(['general' => $general ?? BookingPolicy::fromRow(null)->toArray(), 'sucursales' => $sucursales]);
    }

    public function update(Request $request): Response
    {
        $t = $this->tenant($request);
        $data = Validator::validate($request->json(), [
            'sucursal_id' => 'nullable|integer|min:1',
            'intervalo_minutos' => 'required|integer|min:5|max:120',
            'margen_entre_citas_minutos' => 'required|integer|min:0|max:120',
            'anticipacion_minima_minutos' => 'required|integer|min:0|max:10080',
            'horizonte_maximo_dias' => 'required|integer|min:1|max:365',
            'cancelacion_limite_minutos' => 'required|integer|min:0|max:10080',
            'reprogramacion_limite_minutos' => 'required|integer|min:0|max:10080',
            'max_reprogramaciones' => 'required|integer|min:0|max:10',
            'max_reservas_activas_cliente' => 'required|integer|min:1|max:20',
            'confirmacion_automatica' => 'required|boolean',
            'permite_cualquier_barbero' => 'required|boolean',
            'texto_politica_cancelacion' => 'nullable|string|max:1000',
        ]);
        if ($data['intervalo_minutos'] % 5 !== 0) {
            throw new \App\Core\Exceptions\ValidationException(['intervalo_minutos' => ['Usa un múltiplo de 5 minutos.']]);
        }
        $sucursalId = isset($data['sucursal_id']) ? (int) $data['sucursal_id'] : null;
        $repo = $this->repo($request);
        if ($sucursalId !== null && $repo->branch($sucursalId) === null) {
            throw new NotFoundException('Sucursal no encontrada.');
        }
        unset($data['sucursal_id']);
        $data['texto_politica_cancelacion'] = isset($data['texto_politica_cancelacion']) && trim((string) $data['texto_politica_cancelacion']) !== ''
            ? trim((string) $data['texto_politica_cancelacion']) : null;
        $before = $repo->policyRow($sucursalId);
        $repo->savePolicy($sucursalId, $data);
        Audit::record('politica_reserva.actualizada', 'exito', $request, null, (int) $t['id'], 'politicas_reserva', $sucursalId, $data + ['sucursal_id' => $sucursalId], $before);
        return $this->ok(BookingPolicy::fromRow($repo->policyRow($sucursalId))->toArray());
    }

    public function destroy(Request $request): Response
    {
        $sucursalId = (int) $request->param('sucursal_id');
        if ($this->repo($request)->deletePolicy($sucursalId)) {
            Audit::record('politica_reserva.eliminada', 'exito', $request, null, null, 'politicas_reserva', $sucursalId);
        }
        return $this->noContent();
    }

    private function repo(Request $request): BookingRepository
    {
        return new BookingRepository((int) $this->tenant($request)['id']);
    }
}
