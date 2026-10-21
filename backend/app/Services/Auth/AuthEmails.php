<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Services\Mail\MailMessage;

/** Correos transaccionales de la cuenta. Textos reales, sin datos de ejemplo. */
final class AuthEmails
{
    public static function link(string $page, string $token, array $extra = []): string
    {
        $base = rtrim((string) Config::get('app.frontend_url', 'http://localhost'), '/');
        return $base . '/pages/auth/' . $page . '?' . http_build_query(['token' => $token] + $extra);
    }

    public static function verifyEmail(string $email, string $nombre, string $token): MailMessage
    {
        $url = self::link('verificar.html', $token);
        $horas = (int) Config::get('auth.verify_email_ttl_hours', 48);
        $text = "Hola {$nombre},\n\nConfirma tu correo para activar todas las funciones de tu cuenta BARBERAPP:\n{$url}\n\n"
            . "El enlace vence en {$horas} horas. Si no creaste esta cuenta, ignora este mensaje.\n\nBARBERAPP";
        return new MailMessage($email, 'Confirma tu correo en BARBERAPP', $text, self::html($nombre, 'Confirma tu correo', 'Confirma tu correo para activar todas las funciones de tu cuenta.', 'Confirmar correo', $url, "El enlace vence en {$horas} horas. Si no creaste esta cuenta, ignora este mensaje."), $nombre);
    }

    public static function resetPassword(string $email, string $nombre, string $token): MailMessage
    {
        $url = self::link('restablecer.html', $token);
        $min = (int) Config::get('auth.reset_password_ttl_minutes', 60);
        $text = "Hola {$nombre},\n\nRecibimos una solicitud para restablecer tu contraseña de BARBERAPP:\n{$url}\n\n"
            . "El enlace vence en {$min} minutos y solo puede usarse una vez. Si no lo solicitaste, ignora este mensaje: tu contraseña no cambiará.\n\nBARBERAPP";
        return new MailMessage($email, 'Restablece tu contraseña de BARBERAPP', $text, self::html($nombre, 'Restablece tu contraseña', 'Recibimos una solicitud para restablecer tu contraseña.', 'Crear nueva contraseña', $url, "El enlace vence en {$min} minutos y solo puede usarse una vez. Si no lo solicitaste, ignora este mensaje."), $nombre);
    }

    public static function passwordChanged(string $email, string $nombre): MailMessage
    {
        $text = "Hola {$nombre},\n\nLa contraseña de tu cuenta BARBERAPP se cambió y cerramos todas tus sesiones abiertas.\n"
            . "Si no fuiste tú, solicita una nueva contraseña de inmediato desde la opción \"¿Olvidaste tu contraseña?\".\n\nBARBERAPP";
        return new MailMessage($email, 'Tu contraseña de BARBERAPP cambió', $text, null, $nombre);
    }

    public static function invitation(string $email, string $nombre, string $barberia, string $rol, ?string $token): MailMessage
    {
        if ($token !== null) {
            $url = self::link('restablecer.html', $token, ['invitacion' => '1']);
            $accion = "Crea tu contraseña para entrar y aceptar la invitación:\n{$url}\n\nEl enlace vence en 72 horas.";
            $boton = 'Crear mi contraseña';
        } else {
            $url = rtrim((string) Config::get('app.frontend_url', 'http://localhost'), '/') . '/pages/auth/cuenta.html';
            $accion = "Inicia sesión y acepta la invitación desde tu cuenta:\n{$url}";
            $boton = 'Ver invitación';
        }
        $text = "Hola {$nombre},\n\n{$barberia} te invitó a unirte a su equipo en BARBERAPP como {$rol}.\n\n{$accion}\n\nSi no esperabas esta invitación, ignora este mensaje.\n\nBARBERAPP";
        return new MailMessage($email, "{$barberia} te invitó a BARBERAPP", $text, self::html($nombre, 'Te invitaron a un equipo', htmlspecialchars("{$barberia} te invitó a unirte como {$rol}.", ENT_QUOTES), $boton, $url, 'Si no esperabas esta invitación, ignora este mensaje.'), $nombre);
    }

    private static function html(string $nombre, string $titulo, string $intro, string $boton, string $url, string $nota): string
    {
        $n = htmlspecialchars($nombre, ENT_QUOTES);
        $u = htmlspecialchars($url, ENT_QUOTES);
        return <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#111111;font-family:Arial,Helvetica,sans-serif;color:#FFFFFF">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#111111;padding:32px 16px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#1C1C1E;border-radius:16px;padding:32px">
<tr><td style="font-size:22px;font-weight:800;letter-spacing:1px">BARBER<span style="color:#C9A45C">APP</span></td></tr>
<tr><td style="padding-top:24px;font-size:20px;font-weight:700">{$titulo}</td></tr>
<tr><td style="padding-top:12px;font-size:15px;line-height:22px;color:#D6D6DA">Hola {$n}, {$intro}</td></tr>
<tr><td style="padding:28px 0"><a href="{$u}" style="display:inline-block;background:#C9A45C;color:#111111;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:10px">{$boton}</a></td></tr>
<tr><td style="font-size:13px;line-height:20px;color:#9A9AA0">{$nota}<br><br>Si el botón no funciona, copia este enlace:<br><span style="color:#E4C88A;word-break:break-all">{$u}</span></td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
