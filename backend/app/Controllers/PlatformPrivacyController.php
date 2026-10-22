<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Privacy\DataRequests;
use App\Services\Privacy\LegalInfo;

/**
 * Solicitudes de derechos sobre datos personales para el equipo de BARBERAPP (Fase 13).
 * GET  /api/v1/plataforma/solicitudes-datos?estado=abiertas|recibida|en_proceso|atendida|rechazada
 * POST /api/v1/plataforma/solicitudes-datos/{id}/responder  {estado, respuesta}
 */
final class PlatformPrivacyController extends BaseController
{
    public function index(Request $request): Response
    {
        $estado = $request->query('estado');
        $data = DataRequests::inbox(is_string($estado) && $estado !== '' ? $estado : null);
        $data['legal'] = LegalInfo::public();
        Audit::record('plataforma.solicitudes_datos_consultadas', 'exito', $request, null, null, null, null, ['estado' => $estado]);
        return $this->ok($data);
    }

    public function respond(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        return $this->ok(DataRequests::respond((int) $request->param('id'), $request->json(), (int) $user['id'], $request));
    }
}
