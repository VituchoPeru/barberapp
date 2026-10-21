<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;

/**
 * Resuelve la barbería (tenant) de la petición y la deja en $request->attribute('tenant').
 *
 * Origen del tenant, en orden:
 *   1. Parámetro de ruta {tenant} (slug), p. ej. /api/v1/b/{tenant}/servicios
 *   2. Cabecera X-Tenant (slug) para paneles administrativos.
 *
 * Nunca se acepta un tenant_id numérico del cliente: siempre se busca por slug en la BD y,
 * si $requireMembership es true, se verifica que el usuario autenticado tenga una membresía
 * activa en ese tenant (el usuario lo coloca el middleware de autenticación de la Fase 2
 * en el atributo 'auth_user').
 */
final class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private bool $requireMembership = false,
        private bool $allowInactive = false
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $slug = $request->param('tenant') ?? $request->header('x-tenant');
        if ($slug === null || $slug === '') {
            throw new HttpException(400, 'tenant_required', 'Debe indicar la barbería (tenant).');
        }
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
            throw new NotFoundException('Barbería no encontrada.');
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, slug, nombre_comercial, estado, zona_horaria
               FROM tenants
              WHERE slug = :slug AND deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            throw new NotFoundException('Barbería no encontrada.');
        }
        if (!$this->allowInactive && $tenant['estado'] !== 'activo') {
            throw new HttpException(403, 'tenant_inactive', 'La barbería no está disponible en este momento.');
        }

        $tenant['id'] = (int) $tenant['id'];
        $request->withAttribute('tenant', $tenant);

        if ($this->requireMembership) {
            $user = $request->attribute('auth_user');
            if (!is_array($user) || !isset($user['id'])) {
                throw new HttpException(401, 'unauthenticated', 'Debe iniciar sesión.');
            }
            $request->withAttribute('membership', $this->membership((int) $user['id'], $tenant['id']));
        }

        return $next($request);
    }

    /** @return array{id:int, roles:list<string>} */
    private function membership(int $userId, int $tenantId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT m.id, r.codigo AS rol
               FROM membresias m
               JOIN roles r ON r.id = m.rol_id
              WHERE m.usuario_id = :u AND m.tenant_id = :t AND m.estado = 'activo'"
        );
        $stmt->execute(['u' => $userId, 't' => $tenantId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            // Respuesta 404 en lugar de 403 para no revelar la existencia de datos ajenos.
            throw new NotFoundException('Barbería no encontrada.');
        }
        return [
            'id' => (int) $rows[0]['id'],
            'roles' => array_values(array_unique(array_map(static fn (array $r): string => (string) $r['rol'], $rows))),
        ];
    }
}
