<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * HTML de los correos de avisos con la identidad de BARBERAPP (negro, gris oscuro y dorado).
 * Todo el texto llega plano y se escapa aquí: una variable de plantilla (nombre del cliente,
 * motivo de cancelación…) nunca puede inyectar HTML.
 */
final class EmailLayout
{
    public static function render(string $titulo, string $cuerpo, ?string $boton, ?string $url, string $nota): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = '';
        foreach (preg_split('/\n/', $cuerpo) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $lines .= '<tr><td style="height:10px"></td></tr>';
            } elseif (str_starts_with($line, '- ')) {
                $lines .= '<tr><td style="font-size:15px;line-height:22px;color:#D6D6DA;padding-left:8px">• ' . $e(substr($line, 2)) . '</td></tr>';
            } else {
                $lines .= '<tr><td style="font-size:15px;line-height:22px;color:#D6D6DA">' . $e($line) . '</td></tr>';
            }
        }
        $button = '';
        $copy = '';
        if ($boton !== null && $url !== null) {
            $u = $e($url);
            $button = '<tr><td style="padding:28px 0 8px"><a href="' . $u . '" style="display:inline-block;background:#C9A45C;color:#111111;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:10px">' . $e($boton) . '</a></td></tr>';
            $copy = '<br><br>Si el botón no funciona, copia este enlace:<br><span style="color:#E4C88A;word-break:break-all">' . $u . '</span>';
        }
        $t = $e($titulo);
        $n = $e($nota);
        return <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#111111;font-family:Arial,Helvetica,sans-serif;color:#FFFFFF">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#111111;padding:32px 16px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#1C1C1E;border-radius:16px;padding:32px">
<tr><td style="font-size:22px;font-weight:800;letter-spacing:1px">BARBER<span style="color:#C9A45C">APP</span></td></tr>
<tr><td style="padding:24px 0 12px;font-size:20px;font-weight:700">{$t}</td></tr>
{$lines}
{$button}
<tr><td style="padding-top:20px;font-size:13px;line-height:20px;color:#9A9AA0">{$n}{$copy}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
