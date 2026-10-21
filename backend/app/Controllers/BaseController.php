<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;

/** Utilidades comunes de controladores. */
abstract class BaseController
{
    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    protected function ok($data = null, array $meta = []): Response
    {
        return Response::success($data, 200, $meta);
    }

    /** @param mixed $data */
    protected function created($data = null): Response
    {
        return Response::success($data, 201);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules): array
    {
        return Validator::validate($request->json(), $rules);
    }

    /**
     * Contexto de tenant resuelto por TenantContextMiddleware. Nunca se toma del cuerpo de la petición.
     *
     * @return array{id:int, slug:string, nombre_comercial:string, estado:string, zona_horaria:string}
     */
    protected function tenant(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        if (!is_array($tenant)) {
            throw new HttpException(500, 'tenant_context_missing', 'La ruta no tiene contexto de barbería.');
        }
        return $tenant;
    }

    /** @return array{page:int, per_page:int, offset:int} */
    protected function pagination(Request $request, int $defaultPerPage = 20, int $maxPerPage = 100): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min($maxPerPage, max(1, (int) $request->query('per_page', $defaultPerPage)));
        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }
}
