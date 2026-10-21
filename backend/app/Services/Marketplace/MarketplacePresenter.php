<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Config;
use App\Helpers\Clock;
use App\Helpers\Media;
use App\Repositories\MarketplaceRepository;

/** Da forma pública (JSON) a tarjetas y perfiles de barberías. */
final class MarketplacePresenter
{
    public function __construct(private MarketplaceRepository $repo)
    {
    }

    public static function bookingUrl(string $slug): string
    {
        return rtrim((string) Config::get('app.public_booking_url'), '/') . '/b/' . $slug;
    }

    /**
     * @param list<array<string, mixed>> $rows     filas de tenants (search / favoritos)
     * @param list<int>|null             $favoritos ids favoritos del usuario; null = anónimo
     * @return list<array<string, mixed>>
     */
    public function cards(array $rows, ?array $favoritos): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $branches = $this->repo->branchesFor($ids);
        $types = $this->repo->serviceTypesFor($ids);
        $now = Clock::nowUtc();

        $cards = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $sucursales = $branches[$id] ?? [];
            $principal = $sucursales[0] ?? null;
            $abierto = false;
            foreach ($sucursales as $s) {
                if (OpeningHours::status($s['horarios'], (string) $s['zona_horaria'], $now)['abierto']) {
                    $abierto = true;
                    break;
                }
            }
            $cards[] = [
                'slug' => $r['slug'],
                'nombre' => $r['nombre_comercial'],
                'descripcion' => self::excerpt($r['descripcion'] ?? null, 160),
                'logo_url' => Media::url($r['logo_url'] ?? null),
                'portada_url' => Media::url($r['portada_url'] ?? null),
                'color' => $r['color_primario'] ?? '#C9A45C',
                'precio_desde' => $r['precio_desde'] ?? null,
                'moneda' => $r['moneda'] ?? 'PEN',
                'distancia_km' => isset($r['distancia_m']) && $r['distancia_m'] !== null ? round((float) $r['distancia_m'] / 1000, 1) : null,
                'ubicacion' => $principal === null ? null : ['distrito' => $principal['distrito'], 'provincia' => $principal['provincia']],
                'sucursales' => count($sucursales),
                'abierto_ahora' => $abierto,
                'tipos_servicio' => array_slice($types[$id] ?? [], 0, 4),
                'favorito' => $favoritos === null ? null : in_array($id, $favoritos, true),
                'enlace' => self::bookingUrl((string) $r['slug']),
            ];
        }
        return $cards;
    }

    /**
     * @param array<string, mixed> $t fila de MarketplaceRepository::findActiveBySlug
     * @return array<string, mixed>
     */
    public function profile(array $t, ?bool $favorito): array
    {
        $id = (int) $t['id'];
        $now = Clock::nowUtc();

        $sucursales = [];
        foreach ($this->repo->branchesFor([$id], true)[$id] ?? [] as $s) {
            $porDia = [];
            foreach ($s['horarios'] as $h) {
                $porDia[$h['dia_semana']][] = ['apertura' => $h['hora_apertura'], 'cierre' => $h['hora_cierre']];
            }
            $horarios = [];
            foreach (OpeningHours::DIAS as $dia => $nombre) {
                $horarios[] = ['dia' => $dia, 'dia_nombre' => $nombre, 'tramos' => $porDia[$dia] ?? []];
            }
            $sucursales[] = [
                'id' => (int) $s['id'],
                'nombre' => $s['nombre'],
                'slug' => $s['slug'],
                'direccion' => $s['direccion'],
                'referencia' => $s['referencia'],
                'distrito' => $s['distrito'],
                'provincia' => $s['provincia'],
                'departamento' => $s['departamento'],
                'coordenadas' => $s['latitud'] !== null ? ['lat' => (float) $s['latitud'], 'lng' => (float) $s['longitud']] : null,
                'telefono' => $s['telefono'],
                'email' => $s['email'],
                'es_principal' => (bool) $s['es_principal'],
                'zona_horaria' => $s['zona_horaria'],
                'horarios' => $horarios,
                'estado' => OpeningHours::status($s['horarios'], (string) $s['zona_horaria'], $now),
            ];
        }
        $sucursalIds = array_column($sucursales, 'id');

        $barberos = [];
        foreach ($this->repo->barbers($id) as $b) {
            $barberos[(int) $b['id']] = [
                'id' => (int) $b['id'],
                'nombre' => $b['nombre_publico'],
                'especialidad' => $b['especialidad'],
                'biografia' => $b['biografia'],
                'foto_url' => Media::url($b['foto_url']),
                'sucursal_id' => (int) $b['sucursal_id'],
                'reservas_online' => (bool) $b['acepta_reservas_online'],
                'servicios' => [],
            ];
        }
        $porServicio = [];
        foreach ($this->repo->barberServices($id) as $bs) {
            $bid = (int) $bs['barbero_id'];
            if (!isset($barberos[$bid])) {
                continue; // barbero de una sucursal inactiva
            }
            $barberos[$bid]['servicios'][] = [
                'id' => (int) $bs['servicio_id'],
                'duracion_minutos' => (int) $bs['duracion_minutos'],
                'precio' => $bs['precio'],
            ];
            $porServicio[(int) $bs['servicio_id']][] = $bid;
        }

        $servicios = array_map(static fn (array $s): array => [
            'id' => (int) $s['id'],
            'nombre' => $s['nombre'],
            'descripcion' => $s['descripcion'],
            'duracion_minutos' => (int) $s['duracion_minutos'],
            'precio' => $s['precio'],
            'moneda' => $s['moneda'],
            'imagen_url' => Media::url($s['imagen_url']),
            'categoria' => $s['categoria'],
            'tipo' => $s['tipo'] === null ? null : ['codigo' => $s['tipo'], 'nombre' => $s['tipo_nombre']],
            'barberos' => $porServicio[(int) $s['id']] ?? [],
        ], $this->repo->services($id));

        $fotos = [];
        foreach ($this->repo->gallery($id) as $f) {
            if ($f['sucursal_id'] !== null && !in_array((int) $f['sucursal_id'], $sucursalIds, true)) {
                continue;
            }
            $fotos[] = [
                'id' => (int) $f['id'],
                'url' => Media::url($f['ruta']),
                'ancho' => (int) $f['ancho'],
                'alto' => (int) $f['alto'],
                'alt' => $f['texto_alternativo'] ?: 'Foto de ' . $t['nombre_comercial'],
                'sucursal_id' => $f['sucursal_id'] !== null ? (int) $f['sucursal_id'] : null,
            ];
        }

        $politica = $this->repo->policy($id);

        return [
            'slug' => $t['slug'],
            'nombre' => $t['nombre_comercial'],
            'descripcion' => $t['descripcion'],
            'logo_url' => Media::url($t['logo_url']),
            'portada_url' => Media::url($t['portada_url']),
            'colores' => ['primario' => $t['color_primario'], 'secundario' => $t['color_secundario']],
            'listada_en_marketplace' => (bool) $t['publicado_marketplace'],
            'enlace' => self::bookingUrl((string) $t['slug']),
            'contacto' => self::contact($t),
            'sucursales' => $sucursales,
            'servicios' => $servicios,
            'barberos' => array_values($barberos),
            'fotos' => $fotos,
            'politica' => $politica === null ? null : [
                'cancelacion_limite_minutos' => (int) $politica['cancelacion_limite_minutos'],
                'reprogramacion_limite_minutos' => (int) $politica['reprogramacion_limite_minutos'],
                'anticipacion_minima_minutos' => (int) $politica['anticipacion_minima_minutos'],
                'horizonte_maximo_dias' => (int) $politica['horizonte_maximo_dias'],
                'permite_cualquier_barbero' => (bool) $politica['permite_cualquier_barbero'],
                'texto' => $politica['texto_politica_cancelacion'],
            ],
            'favorito' => $favorito,
        ];
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, ?string>
     */
    public static function contact(array $t): array
    {
        $wa = $t['whatsapp'] ?? null;
        return [
            'telefono' => $t['telefono'] ?? null,
            'whatsapp' => $wa,
            'whatsapp_url' => $wa ? 'https://wa.me/' . ltrim((string) $wa, '+') : null,
            'instagram_url' => !empty($t['instagram']) ? 'https://www.instagram.com/' . rawurlencode((string) $t['instagram']) . '/' : null,
            'facebook_url' => !empty($t['facebook']) ? 'https://www.facebook.com/' . rawurlencode((string) $t['facebook']) : null,
            'tiktok_url' => !empty($t['tiktok']) ? 'https://www.tiktok.com/@' . rawurlencode((string) $t['tiktok']) : null,
            'sitio_web' => !empty($t['sitio_web']) && preg_match('#^https?://[^\s"\'<>]+$#i', (string) $t['sitio_web']) ? $t['sitio_web'] : null,
        ];
    }

    private static function excerpt(?string $text, int $max): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
