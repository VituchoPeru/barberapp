<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Helpers\Media;
use RuntimeException;

/**
 * Guarda imágenes subidas por las barberías de forma segura:
 *
 *  1. Límite de tamaño (MEDIA_MAX_UPLOAD_BYTES) y tipo real por contenido (JPEG, PNG o WebP),
 *     nunca por la extensión ni por la cabecera Content-Type.
 *  2. Límite de dimensiones antes de decodificar (evita bombas de descompresión).
 *  3. Recodificación completa con GD: se descartan EXIF (ubicación GPS del teléfono),
 *     perfiles y cualquier contenido incrustado; se corrige la orientación de fotos de celular.
 *  4. Nombre aleatorio de 128 bits dentro de tenants/{tenant_id}/; el usuario no elige rutas.
 */
final class ImageStore
{
    /** Fotos del perfil público de la barbería (tenant_fotos). */
    public const TIPOS = ['logo', 'portada', 'galeria'];
    /** Todos los tipos aceptados: 'barbero' es el retrato cuadrado de cada barbero (Fase 4). */
    public const TODOS = ['logo', 'portada', 'galeria', 'barbero'];

    /** @return array{ruta:string, ancho:int, alto:int, bytes:int} */
    public function store(int $tenantId, string $binary, string $tipo): array
    {
        if (!in_array($tipo, self::TODOS, true)) {
            throw new ValidationException(['tipo' => ['Tipo de imagen no permitido.']]);
        }
        if (!extension_loaded('gd')) {
            throw new HttpException(503, 'images_unavailable', 'El servidor no tiene habilitada la extensión GD para procesar imágenes.');
        }
        $max = (int) Config::get('media.max_upload_bytes', 5242880);
        $size = strlen($binary);
        if ($size === 0) {
            throw new ValidationException(['archivo' => ['Selecciona una imagen.']]);
        }
        if ($size > $max) {
            throw new HttpException(413, 'image_too_large', 'La imagen supera el tamaño máximo de ' . round($max / 1048576, 1) . ' MB.');
        }

        $info = @getimagesizefromstring($binary);
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($info === false || !in_array($info[2], $allowed, true)) {
            throw new ValidationException(['archivo' => ['El archivo debe ser una imagen JPG, PNG o WebP.']]);
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $maxSide = (int) Config::get('media.max_input_side', 8000);
        if ($w < 1 || $h < 1 || $w > $maxSide || $h > $maxSide || $w * $h > (int) Config::get('media.max_input_pixels', 40000000)) {
            throw new ValidationException(['archivo' => ["La imagen es demasiado grande (máximo {$maxSide} px por lado)."]]);
        }
        $minSide = ['logo' => 64, 'barbero' => 200][$tipo] ?? 320;
        if (min($w, $h) < $minSide) {
            throw new ValidationException(['archivo' => ["La imagen es muy pequeña: mínimo {$minSide} px por lado para que se vea nítida."]]);
        }

        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            throw new ValidationException(['archivo' => ['No se pudo leer la imagen. Prueba con otro archivo.']]);
        }
        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $src = $this->applyExifOrientation($src, $binary);
            }
            if ($tipo === 'barbero') {
                $src = $this->cropSquare($src);
            }
            $out = $this->resize($src, (int) (Config::get('media.max_side')[$tipo] ?? 1600));
            [$bytes, $ext] = $this->encode($out);
            $ancho = imagesx($out);
            $alto = imagesy($out);
            if ($out !== $src) {
                imagedestroy($out);
            }
        } finally {
            imagedestroy($src);
        }

        $ruta = 'tenants/' . $tenantId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $this->write($ruta, $bytes);
        return ['ruta' => $ruta, 'ancho' => $ancho, 'alto' => $alto, 'bytes' => strlen($bytes)];
    }

    public function delete(string $ruta): void
    {
        if (!preg_match(Media::RUTA_REGEX, $ruta)) {
            return;
        }
        $file = $this->base() . '/' . $ruta;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function base(): string
    {
        return rtrim((string) Config::get('media.path'), '/');
    }

    private function write(string $ruta, string $bytes): void
    {
        $file = $this->base() . '/' . $ruta;
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
        @chmod($file, 0644);
    }

    /** Recorte cuadrado centrado (retratos de barberos). @param \GdImage|resource $img @return \GdImage|resource */
    private function cropSquare($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w === $h) {
            return $img;
        }
        $side = min($w, $h);
        // En retratos verticales se conserva el tercio superior (la cara suele estar arriba).
        $cropped = imagecrop($img, ['x' => intdiv($w - $side, 2), 'y' => $h > $w ? intdiv($h - $side, 4) : 0, 'width' => $side, 'height' => $side]);
        if ($cropped === false) {
            return $img;
        }
        imagedestroy($img);
        return $cropped;
    }

    /** @param \GdImage|resource $img @return \GdImage|resource */
    private function resize($img, int $maxSide)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        // Siempre se copia a un lienzo nuevo: así se descarta cualquier dato no visual.
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    /** @param \GdImage|resource $img @return array{0:string, 1:string} */
    private function encode($img): array
    {
        ob_start();
        if (function_exists('imagewebp')) {
            $ok = imagewebp($img, null, 82);
            $ext = 'webp';
        } else {
            $ok = imagepng($img, null, 7);
            $ext = 'png';
        }
        $bytes = (string) ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new RuntimeException('No se pudo procesar la imagen.');
        }
        return [$bytes, $ext];
    }

    /** @param \GdImage|resource $img @return \GdImage|resource */
    private function applyExifOrientation($img, string $binary)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($binary));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);
        return $rotated;
    }
}
