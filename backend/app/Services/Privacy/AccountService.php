<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Tokens;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Auth\AuthEmails;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\RateLimiter;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailMessage;

/**
 * Datos de la propia cuenta (Fase 13): rectificar el perfil, cambiar contraseña y correo
 * con la sesión abierta, y la reautenticación que piden las acciones sensibles.
 */
final class AccountService
{
    public const CAMBIAR_EMAIL = 'cambiar_email';

    private UserRepository $users;
    private RateLimiter $limiter;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->limiter = new RateLimiter();
    }

    /**
     * Comprueba la contraseña actual antes de una acción sensible (eliminar cuenta, cambiar
     * correo o contraseña). Los fallos cuentan para un límite por usuario.
     *
     * @return array<string, mixed> fila del usuario con password_hash
     */
    public function reauthenticate(int $userId, string $password, Request $request, string $accion): array
    {
        $key = (string) $userId;
        $this->limiter->ensureNotLimited('reautenticacion_usuario', $key);
        $user = $this->users->findById($userId);
        $hash = $user['password_hash'] ?? null;
        if ($user === null || !is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            $this->limiter->hit('reautenticacion_usuario', $key);
            Audit::denied('privacidad.reautenticacion_fallida', $request, ['accion' => $accion], $userId);
            throw new ValidationException(['password' => ['La contraseña no es correcta.']]);
        }
        $this->limiter->clear('reautenticacion_usuario', $key);
        return $user;
    }

    /** @param array<string, mixed> $input */
    public function updateProfile(array $user, array $input, Request $request): array
    {
        $nombres = trim((string) ($input['nombres'] ?? ''));
        $apellidos = trim((string) ($input['apellidos'] ?? ''));
        $telefono = preg_replace('/[\s\-()]/', '', (string) ($input['telefono'] ?? '')) ?? '';
        $errors = [];
        if (mb_strlen($nombres) < 2 || mb_strlen($nombres) > 100 || preg_match('/[<>]/', $nombres)) {
            $errors['nombres'][] = 'Escribe tu nombre (2 a 100 caracteres).';
        }
        if (mb_strlen($apellidos) > 100 || preg_match('/[<>]/', $apellidos)) {
            $errors['apellidos'][] = 'Máximo 100 caracteres.';
        }
        if ($telefono !== '' && !preg_match('/^\+?[0-9]{6,15}$/', $telefono)) {
            $errors['telefono'][] = 'Teléfono inválido (6 a 15 dígitos, puede empezar con +).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $antes = ['nombres' => $user['nombres'], 'apellidos' => $user['apellidos'], 'telefono' => $user['telefono']];
        $despues = ['nombres' => $nombres, 'apellidos' => $apellidos !== '' ? $apellidos : null, 'telefono' => $telefono !== '' ? $telefono : null];
        Database::transaction(fn () => $this->users->updateProfile((int) $user['id'], $despues['nombres'], $despues['apellidos'], $despues['telefono']));
        $cambios = array_keys(array_diff_assoc(array_map('strval', $despues), array_map('strval', $antes)));
        Audit::record('cuenta.perfil_actualizado', 'exito', $request, (int) $user['id'], null, 'usuarios', (int) $user['id'], ['campos' => $cambios]);
        return UserRepository::publicProfile((array) $this->users->findById((int) $user['id']));
    }

    /** Cambia la contraseña con la actual y cierra las demás sesiones. */
    public function changePassword(int $userId, string $actual, string $nueva, int $currentSessionId, Request $request): int
    {
        $user = $this->reauthenticate($userId, $actual, $request, 'cambiar_password');
        $errors = PasswordPolicy::errors($nueva, (string) $user['email']);
        if ($nueva !== '' && hash_equals($actual, $nueva)) {
            $errors[] = 'La nueva contraseña debe ser distinta de la actual.';
        }
        if ($errors !== []) {
            throw new ValidationException(['password_nueva' => $errors]);
        }
        $cerradas = Database::transaction(function () use ($userId, $nueva, $currentSessionId): int {
            $this->users->updatePassword($userId, PasswordPolicy::hash($nueva));
            return (new SessionRepository())->revokeAllForUser($userId, 'password_cambiada', $currentSessionId);
        });
        Mailer::send(new MailMessage((string) $user['email'], 'Tu contraseña de BARBERAPP cambió',
            "Hola {$user['nombres']},\n\nCambiaste la contraseña de tu cuenta BARBERAPP y cerramos tus otras sesiones abiertas.\n"
            . "Si no fuiste tú, solicita una nueva contraseña de inmediato desde la opción \"¿Olvidaste tu contraseña?\".\n\nBARBERAPP", null, (string) $user['nombres']));
        Audit::record('cuenta.password_cambiada', 'exito', $request, $userId, null, 'usuarios', $userId, ['sesiones_cerradas' => $cerradas]);
        return $cerradas;
    }

    /**
     * Pide cambiar el correo: se envía un enlace al correo nuevo. Hasta que se confirma, la
     * cuenta sigue con el correo anterior (que recibe un aviso).
     */
    public function requestEmailChange(int $userId, string $password, string $nuevo, Request $request): string
    {
        $user = $this->reauthenticate($userId, $password, $request, 'cambiar_email');
        $nuevo = UserRepository::normalizeEmail($nuevo);
        if (!filter_var($nuevo, FILTER_VALIDATE_EMAIL) || strlen($nuevo) > 190 || str_ends_with($nuevo, '@' . AccountDeletion::EMAIL_DOMAIN)) {
            throw new ValidationException(['email' => ['Correo electrónico inválido.']]);
        }
        if ($nuevo === UserRepository::normalizeEmail((string) $user['email'])) {
            throw new ValidationException(['email' => ['Ese ya es tu correo.']]);
        }
        if ($this->users->emailExists($nuevo)) {
            // Mismo mensaje genérico que en el registro: no se confirma a quién pertenece.
            throw new ValidationException(['email' => ['No se puede usar este correo. Si es tuyo y tienes otra cuenta, elimina esa cuenta primero.']]);
        }
        $this->limiter->consume('cambio_email_usuario', (string) $userId);

        $token = Tokens::random();
        $min = 60 * (int) Config::get('auth.verify_email_ttl_hours', 48);
        Database::transaction(function () use ($userId, $nuevo, $token, $min, $request): void {
            $db = Database::connection();
            $db->prepare("UPDATE tokens_usuario SET usado_en = ? WHERE usuario_id = ? AND tipo = 'cambiar_email' AND usado_en IS NULL")
                ->execute([Clock::nowSql(), $userId]);
            $db->prepare("INSERT INTO tokens_usuario (usuario_id, tipo, token_hash, email, expira_en, ip) VALUES (?, 'cambiar_email', ?, ?, ?, ?)")
                ->execute([$userId, Tokens::hash($token), $nuevo, Clock::nowUtc()->modify("+{$min} minutes")->format('Y-m-d H:i:s'), \App\Helpers\Ip::toBinary($request->ip())]);
        });

        $url = AuthEmails::link('verificar.html', $token, ['cambio' => '1']);
        Mailer::send(new MailMessage($nuevo, 'Confirma tu nuevo correo en BARBERAPP',
            "Hola {$user['nombres']},\n\nPara usar este correo en tu cuenta BARBERAPP, confírmalo con este enlace:\n{$url}\n\n"
            . "El enlace vence en " . intdiv($min, 60) . " horas. Si no lo pediste, ignora este mensaje.\n\nBARBERAPP", null, (string) $user['nombres']));
        Mailer::send(new MailMessage((string) $user['email'], 'Pidieron cambiar el correo de tu cuenta BARBERAPP',
            "Hola {$user['nombres']},\n\nSe pidió cambiar el correo de tu cuenta a " . self::mask($nuevo) . ". El cambio se hará cuando se confirme desde ese correo.\n"
            . "Si no fuiste tú, cambia tu contraseña de inmediato.\n\nBARBERAPP", null, (string) $user['nombres']));
        Audit::record('cuenta.cambio_email_solicitado', 'exito', $request, $userId, null, 'usuarios', $userId, ['nuevo_hash' => hash('sha256', $nuevo)]);
        return self::mask($nuevo);
    }

    /** Confirma el cambio con el enlace del correo nuevo (no requiere sesión). */
    public function confirmEmailChange(string $token, Request $request): array
    {
        $this->limiter->ensureNotLimited('restablecer_ip', $request->ip());
        $invalid = new HttpException(400, 'invalid_token', 'El enlace no es válido o ya venció. Pide el cambio de nuevo desde tu cuenta.');
        if (!Tokens::looksValid($token)) {
            $this->limiter->hit('restablecer_ip', $request->ip());
            throw $invalid;
        }
        $result = Database::transaction(function () use ($token): ?array {
            $db = Database::connection();
            $stmt = $db->prepare(
                "SELECT t.id, t.usuario_id, t.email AS nuevo, u.email AS anterior, u.nombres
                   FROM tokens_usuario t JOIN usuarios u ON u.id = t.usuario_id AND u.deleted_at IS NULL AND u.estado = 'activo'
                  WHERE t.token_hash = ? AND t.tipo = 'cambiar_email' AND t.usado_en IS NULL AND t.expira_en > ? FOR UPDATE"
            );
            $stmt->execute([Tokens::hash($token), Clock::nowSql()]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $db->prepare('UPDATE tokens_usuario SET usado_en = ? WHERE id = ?')->execute([Clock::nowSql(), (int) $row['id']]);
            if ($this->users->emailExists((string) $row['nuevo'])) {
                return ['taken' => true];
            }
            $this->users->updateEmail((int) $row['usuario_id'], (string) $row['nuevo']);
            // Los enlaces pendientes (verificación, recuperación) iban al correo anterior.
            $db->prepare("UPDATE tokens_usuario SET usado_en = ? WHERE usuario_id = ? AND usado_en IS NULL")->execute([Clock::nowSql(), (int) $row['usuario_id']]);
            return $row;
        });
        if ($result === null) {
            $this->limiter->hit('restablecer_ip', $request->ip());
            Audit::denied('cuenta.cambio_email_token_invalido', $request);
            throw $invalid;
        }
        if (isset($result['taken'])) {
            throw new HttpException(409, 'email_taken', 'Ese correo ya se usa en otra cuenta. No se cambió tu correo.');
        }
        Mailer::send(new MailMessage((string) $result['anterior'], 'El correo de tu cuenta BARBERAPP cambió',
            "Hola {$result['nombres']},\n\nEl correo de tu cuenta BARBERAPP ahora es " . self::mask((string) $result['nuevo']) . ". Este correo ya no recibirá avisos.\n"
            . "Si no fuiste tú, responde a este mensaje de inmediato.\n\nBARBERAPP", null, (string) $result['nombres']));
        Audit::record('cuenta.email_cambiado', 'exito', $request, (int) $result['usuario_id'], null, 'usuarios', (int) $result['usuario_id']);
        return UserRepository::publicProfile((array) $this->users->findById((int) $result['usuario_id']));
    }

    /** a***@dominio.com */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }
}
