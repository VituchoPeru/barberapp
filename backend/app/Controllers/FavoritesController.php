<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FavoriteRepository;
use App\Repositories\MarketplaceRepository;
use App\Repositories\TenantRepository;
use App\Services\Marketplace\MarketplacePresenter;

/**
 * Barberías favoritas del usuario autenticado.
 *   GET    /api/v1/me/favoritos           Lista (tarjetas del marketplace)
 *   PUT    /api/v1/me/favoritos/{slug}    Guarda (idempotente)
 *   DELETE /api/v1/me/favoritos/{slug}    Quita (idempotente)
 */
final class FavoritesController extends BaseController
{
    public const MAX_FAVORITOS = 200;

    public function index(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $favs = new FavoriteRepository();
        $clienteId = $favs->clienteIdForUser((int) $user['id']);
        if ($clienteId === null) {
            return $this->ok([]);
        }
        $rows = $favs->listFor($clienteId);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        return $this->ok((new MarketplacePresenter(new MarketplaceRepository()))->cards($rows, $ids));
    }

    public function add(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $tenant = $this->tenantBySlug($request);
        $favs = new FavoriteRepository();
        $clienteId = $favs->ensureCliente($user);
        if (!$favs->isFavorite((int) $user['id'], (int) $tenant['id']) && $favs->count($clienteId) >= self::MAX_FAVORITOS) {
            throw new HttpException(422, 'favorites_limit', 'Alcanzaste el máximo de ' . self::MAX_FAVORITOS . ' barberías favoritas.');
        }
        $favs->add($clienteId, (int) $tenant['id']);
        return $this->ok(['slug' => $tenant['slug'], 'favorito' => true]);
    }

    public function remove(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $slug = strtolower((string) $request->param('slug'));
        $favs = new FavoriteRepository();
        $clienteId = $favs->clienteIdForUser((int) $user['id']);
        // Se permite quitar aunque la barbería ya no esté activa.
        $row = (new TenantRepository())->findBySlug($slug);
        if ($clienteId !== null && $row !== null) {
            $favs->remove($clienteId, (int) $row['id']);
        }
        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function tenantBySlug(Request $request): array
    {
        $slug = strtolower((string) $request->param('slug'));
        $tenant = (new MarketplaceRepository())->findActiveBySlug($slug);
        if ($tenant === null) {
            throw new NotFoundException('Esta barbería no existe o no está disponible en este momento.');
        }
        return $tenant;
    }
}
