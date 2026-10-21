<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Media;
use App\Helpers\Validator;
use App\Middleware\JsonBodyMiddleware;
use App\Repositories\PublicProfileRepository;
use App\Services\Audit;
use App\Services\Marketplace\MarketplacePresenter;
use App\Services\Media\ImageStore;

/**
 * Perfil público de la barbería en el marketplace (panel, permiso negocio.perfil.gestionar).
 *   GET    /api/v1/panel/perfil-publico     Datos, fotos, enlace directo y requisitos para publicarse
 *   PUT    /api/v1/panel/perfil-publico     Descripción, contacto, redes y publicación en el marketplace
 *   POST   /api/v1/panel/fotos?tipo=        Sube logo, portada o foto de galería (cuerpo binario image/*)
 *   DELETE /api/v1/panel/fotos/{id}         Elimina una foto
 */
final class PublicProfileController extends BaseController
{
    public function show(Request $request): Response
    {
        return $this->ok($this->payload(new PublicProfileRepository($this->tenant($request)['id'])));
    }

    public function update(Request $request): Response
    {
        $input = $request->json();
        foreach (['telefono', 'whatsapp'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = preg_replace('/[\s\-().]/', '', $input[$k]);
            }
        }
        foreach (['instagram', 'tiktok', 'facebook'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                // Acepta "@usuario" o la URL completa del perfil y guarda solo el usuario.
                $v = trim($input[$k]);
                $v = (string) preg_replace('#^(https?://)?(www\.)?(instagram\.com|tiktok\.com|facebook\.com|fb\.com)/@?#i', '', $v);
                $input[$k] = trim(ltrim($v, '@'), '/');
            }
        }
        $data = Validator::validate($input, [
            'descripcion' => 'nullable|string|max:2000',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'whatsapp' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'instagram' => 'nullable|regex:/^[A-Za-z0-9._]{1,30}$/',
            'facebook' => 'nullable|regex:/^[A-Za-z0-9.\-]{1,100}$/',
            'tiktok' => 'nullable|regex:/^[A-Za-z0-9._]{1,30}$/',
            'sitio_web' => 'nullable|string|max:255|regex:#^https?://[^\s"\'<>]+\.[^\s"\'<>]+$#i',
            'publicado_marketplace' => 'nullable|boolean',
        ]);
        if (array_key_exists('publicado_marketplace', $data) && $data['publicado_marketplace'] === null) {
            unset($data['publicado_marketplace']);
        }
        if (isset($data['whatsapp']) && preg_match('/^9\d{8}$/', (string) $data['whatsapp'])) {
            $data['whatsapp'] = '+51' . $data['whatsapp']; // celular peruano sin código de país
        }

        $tenant = $this->tenant($request);
        $repo = new PublicProfileRepository($tenant['id']);
        $before = $repo->profile();

        if (!empty($data['publicado_marketplace']) && !(bool) $before['publicado_marketplace']) {
            $faltantes = array_keys(array_filter($repo->readiness(), static fn (bool $ok): bool => !$ok));
            if ($faltantes !== []) {
                throw new HttpException(422, 'profile_incomplete', 'Completa tu barbería antes de publicarla en el marketplace.', ['faltantes' => $faltantes]);
            }
        }

        $repo->update($data);
        $changed = array_intersect_key($before, $data);
        Audit::record('marketplace.perfil_actualizado', 'exito', $request, null, $tenant['id'], 'tenants', $tenant['id'], $data, $changed);
        return $this->ok($this->payload($repo));
    }

    public function uploadPhoto(Request $request): Response
    {
        $q = Validator::validate($request->allQuery(), [
            'tipo' => 'required|in:' . implode(',', ImageStore::TIPOS),
            'alt' => 'nullable|string|max:160',
        ]);
        $type = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));
        if (!in_array($type, JsonBodyMiddleware::IMAGE_TYPES, true)) {
            throw new HttpException(415, 'unsupported_media_type', 'Envía la imagen como JPG, PNG o WebP.');
        }

        $tenant = $this->tenant($request);
        $repo = new PublicProfileRepository($tenant['id']);
        $tipo = (string) $q['tipo'];
        $max = (int) Config::get('media.max_galeria', 12);
        if ($tipo === 'galeria' && $repo->countGallery() >= $max) {
            throw new HttpException(422, 'gallery_full', "La galería admite hasta {$max} fotos. Elimina alguna para subir otra.");
        }

        $store = new ImageStore();
        $img = $store->store($tenant['id'], $request->rawBody(), $tipo);
        $user = (array) $request->attribute('auth_user');
        try {
            [$id, $replaced] = Database::transaction(static function () use ($repo, $tipo, $img, $q, $user): array {
                $id = $repo->addPhoto($tipo, $img, $q['alt'] ?? null, (int) $user['id']);
                $replaced = [];
                if ($tipo !== 'galeria') {
                    // Logo y portada son únicos: la nueva imagen reemplaza a la anterior.
                    $replaced = $repo->othersOfType($tipo, $id);
                    foreach ($replaced as $old) {
                        $repo->deletePhoto((int) $old['id']);
                    }
                    $repo->setBrandImage($tipo, $img['ruta']);
                }
                return [$id, $replaced];
            });
        } catch (\Throwable $e) {
            $store->delete($img['ruta']);
            throw $e;
        }
        foreach ($replaced as $old) {
            $store->delete((string) $old['ruta']);
        }
        Audit::record('marketplace.foto_subida', 'exito', $request, null, $tenant['id'], 'tenant_fotos', $id, ['tipo' => $tipo, 'bytes' => $img['bytes']]);
        return $this->created(['id' => $id, 'tipo' => $tipo, 'url' => Media::url($img['ruta']), 'ancho' => $img['ancho'], 'alto' => $img['alto']]);
    }

    public function deletePhoto(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $repo = new PublicProfileRepository($tenant['id']);
        $photo = $repo->findPhoto((int) $request->param('id'));
        if ($photo === null) {
            throw new NotFoundException('Foto no encontrada.');
        }
        Database::transaction(static function () use ($repo, $photo): void {
            $repo->deletePhoto((int) $photo['id']);
            if ($photo['tipo'] !== 'galeria') {
                $repo->setBrandImage((string) $photo['tipo'], null);
            }
        });
        (new ImageStore())->delete((string) $photo['ruta']);
        Audit::record('marketplace.foto_eliminada', 'exito', $request, null, $tenant['id'], 'tenant_fotos', (int) $photo['id'], null, ['tipo' => $photo['tipo']]);
        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function payload(PublicProfileRepository $repo): array
    {
        $p = $repo->profile();
        $readiness = $repo->readiness();
        $listo = !in_array(false, $readiness, true);
        return [
            'slug' => $p['slug'],
            'nombre_comercial' => $p['nombre_comercial'],
            'estado' => $p['estado'],
            'descripcion' => $p['descripcion'],
            'telefono' => $p['telefono'],
            'whatsapp' => $p['whatsapp'],
            'instagram' => $p['instagram'],
            'facebook' => $p['facebook'],
            'tiktok' => $p['tiktok'],
            'sitio_web' => $p['sitio_web'],
            'publicado_marketplace' => (bool) $p['publicado_marketplace'],
            // Mismas condiciones que el buscador: publicada, activa (aprobada), con sucursal y servicio activos.
            'visible_en_marketplace' => (bool) $p['publicado_marketplace'] && $p['estado'] === 'activo' && $readiness['sucursal'] && $readiness['servicio'],
            'lista_para_publicar' => $listo,
            'requisitos' => $readiness,
            'enlace' => MarketplacePresenter::bookingUrl((string) $p['slug']),
            'enlace_activo' => $p['estado'] === 'activo',
            'logo_url' => Media::url($p['logo_url']),
            'portada_url' => Media::url($p['portada_url']),
            'fotos' => array_map(static fn (array $f): array => [
                'id' => (int) $f['id'],
                'tipo' => $f['tipo'],
                'url' => Media::url($f['ruta']),
                'ancho' => (int) $f['ancho'],
                'alto' => (int) $f['alto'],
                'bytes' => (int) $f['bytes'],
                'alt' => $f['texto_alternativo'],
            ], $repo->photos()),
            'max_galeria' => (int) Config::get('media.max_galeria', 12),
            'max_upload_bytes' => (int) Config::get('media.max_upload_bytes', 5242880),
        ];
    }
}
