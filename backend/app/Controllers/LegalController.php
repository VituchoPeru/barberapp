<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Privacy\LegalInfo;

/** GET /api/v1/legal — versión de los textos legales y datos del responsable (público). */
final class LegalController extends BaseController
{
    public function show(Request $request): Response
    {
        $res = $this->ok(LegalInfo::public());
        $res->setHeader('Cache-Control', 'public, max-age=300');
        return $res;
    }
}
