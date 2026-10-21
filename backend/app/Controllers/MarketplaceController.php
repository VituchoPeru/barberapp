<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Repositories\FavoriteRepository;
use App\Repositories\MarketplaceRepository;
use App\Services\Marketplace\MarketplacePresenter;
use App\Services\Qr\QrCode;
use App\Services\Qr\QrRenderer;

/**
 * Marketplace público (no requiere sesión; si la hay, marca favoritos).
 *   GET /api/v1/marketplace/filtros                    Distritos, tipos de servicio y rango de precios reales
 *   GET /api/v1/marketplace/barberias                  Búsqueda con filtros, orden y paginación
 *   GET /api/v1/marketplace/barberias/{slug}           Perfil público (servicios, equipo, sedes, fotos, contacto)
 *   GET /api/v1/marketplace/barberias/{slug}/qr        Código QR del enlace directo (?formato=svg|png&tam=&descargar=1)
 */
final class MarketplaceController extends BaseController
{
    private MarketplaceRepository $repo;

    public function __construct()
    {
        $this->repo = new MarketplaceRepository();
    }

    public function filters(Request $request): Response
    {
        return $this->ok($this->repo->filters());
    }

    public function index(Request $request): Response
    {
        $f = Validator::validate($request->allQuery(), [
            'q' => 'nullable|string|max:80',
            'distrito' => 'nullable|string|max:80',
            'tipo' => 'nullable|regex:/^[a-z0-9_]{1,40}$/',
            'precio_min' => 'nullable|decimal|min:0|max:100000',
            'precio_max' => 'nullable|decimal|min:0|max:100000',
            'lat' => 'nullable|regex:/^-?\d{1,2}(\.\d{1,8})?$/',
            'lng' => 'nullable|regex:/^-?\d{1,3}(\.\d{1,8})?$/',
            'radio_km' => 'nullable|decimal|min:0.5|max:50',
            'orden' => 'nullable|in:' . implode(',', MarketplaceRepository::ORDENES),
        ]);
        // Distancias solo con coordenadas válidas que el usuario autorizó compartir (ambas presentes).
        $lat = isset($f['lat']) ? (float) $f['lat'] : null;
        $lng = isset($f['lng']) ? (float) $f['lng'] : null;
        if (($lat === null) !== ($lng === null) || ($lat !== null && ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180))) {
            throw new ValidationException(['lat' => ['Envía latitud y longitud válidas.']], 'Coordenadas inválidas.');
        }
        if (isset($f['precio_min'], $f['precio_max']) && (float) $f['precio_min'] > (float) $f['precio_max']) {
            throw new ValidationException(['precio_min' => ['Debe ser menor o igual al precio máximo.']], 'El precio mínimo no puede superar al máximo.');
        }
        if (($f['orden'] ?? null) === 'distancia' && $lat === null) {
            throw new ValidationException(['orden' => ['Requiere compartir tu ubicación.']], 'Para ordenar por distancia comparte tu ubicación.');
        }
        $f['lat'] = $lat;
        $f['lng'] = $lng;
        $f['radio_km'] = isset($f['radio_km']) ? (float) $f['radio_km'] : null;

        $p = $this->pagination($request, 12, 48);
        $result = $this->repo->search($f, $p['per_page'], $p['offset']);
        $cards = (new MarketplacePresenter($this->repo))->cards($result['items'], $this->favoriteIds($request));

        return $this->ok($cards, [
            'page' => $p['page'],
            'per_page' => $p['per_page'],
            'total' => $result['total'],
            'pages' => (int) ceil($result['total'] / $p['per_page']),
            'con_ubicacion' => $lat !== null,
        ]);
    }

    public function show(Request $request): Response
    {
        $tenant = $this->findTenant($request);
        $user = $request->attribute('auth_user');
        $favorito = is_array($user) ? (new FavoriteRepository())->isFavorite((int) $user['id'], (int) $tenant['id']) : null;
        return $this->ok((new MarketplacePresenter($this->repo))->profile($tenant, $favorito));
    }

    public function qr(Request $request): Response
    {
        $tenant = $this->findTenant($request);
        $q = Validator::validate($request->allQuery(), [
            'formato' => 'nullable|in:svg,png',
            'tam' => 'nullable|integer|min:4|max:20',
            'descargar' => 'nullable|boolean',
        ]);
        $formato = $q['formato'] ?? 'svg';
        // ?o=qr permite distinguir en las reservas (Fase 5) a quienes llegaron escaneando el código.
        $qr = QrCode::encode(MarketplacePresenter::bookingUrl((string) $tenant['slug']) . '?o=qr', 'M');

        if ($formato === 'png') {
            $response = new Response(QrRenderer::png($qr, (int) ($q['tam'] ?? 12)), 200, ['Content-Type' => 'image/png']);
        } else {
            $response = new Response(QrRenderer::svg($qr), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']);
        }
        $response->setHeader('Cache-Control', 'public, max-age=86400');
        if (!empty($q['descargar'])) {
            $response->setHeader('Content-Disposition', 'attachment; filename="qr-' . $tenant['slug'] . '.' . $formato . '"');
        }
        return $response;
    }

    /** @return array<string, mixed> */
    private function findTenant(Request $request): array
    {
        $slug = strtolower((string) $request->param('slug'));
        $tenant = strlen($slug) <= 80 ? $this->repo->findActiveBySlug($slug) : null;
        if ($tenant === null) {
            throw new NotFoundException('Esta barbería no existe o no está disponible en este momento.');
        }
        return $tenant;
    }

    /** @return list<int>|null */
    private function favoriteIds(Request $request): ?array
    {
        $user = $request->attribute('auth_user');
        return is_array($user) ? (new FavoriteRepository())->tenantIdsForUser((int) $user['id']) : null;
    }
}
