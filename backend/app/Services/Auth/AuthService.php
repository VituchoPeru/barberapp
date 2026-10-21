<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Slug;
use App\Helpers\Tokens;
use App\Repositories\MembershipRepository;
use App\Repositories\SessionRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserTokenRepository;
use App\Services\Audit;
use App\Services\Mail\Mailer;

/**
 * Casos de uso de la cuenta: registro, inicio/cierre de sesión, recuperación de contraseña
 * y verificación de correo. Toda decisión de seguridad ocurre aquí, no en el frontend.
 */
final class AuthService
{
    private static ?string $dummyHash = null;

    private UserRepository $users;
    private SessionRepository $sessions;
    private UserTokenRepository $tokens;
    private MembershipRepository $memberships;
    private TenantRepository $tenants;
    private RateLimiter $limiter;
    private SessionService $sessionService;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->sessions = new SessionRepository();
        $this->tokens = new UserTokenRepository();
        $this->memberships = new MembershipRepository();
        $this->tenants = new TenantRepository();
        $this->limiter = new RateLimiter();
        $this->sessionService = new SessionService($this->sessions);
    }

    // ================================================================ registro

    /**
     * Registra un cliente o un propietario (con su barbería) e inicia sesión.
     *
     * @param array<string, mixed> $data Datos ya validados por el controlador
     * @return array{data: array<string, mixed>, cookie: ?string, expires: int}
     */
    public function register(array $data, Request $request): array
    {
        $this->limiter->consume('registro_ip', $request->ip());

        $email = UserRepository::normalizeEmail((string) $data['email']);
        $passwordErrors = PasswordPolicy::errors((string) $data['password'], $email);
        if ($passwordErrors !== []) {
            throw new ValidationException(['password' => $passwordErrors]);
        }
        if ($data['acepta_terminos'] !== true) {
            throw new ValidationException(['acepta_terminos' => ['Debes aceptar los términos de uso y la política de privacidad.']]);
        }
        if ($this->users->emailExists($email)) {
            Audit::denied('auth.registro_duplicado', $request);
            throw new HttpException(409, 'email_taken', 'Ya existe una cuenta con este correo. Inicia sesión o recupera tu contraseña.');
        }

        $tipo = (string) $data['tipo'];
        $barberia = null;
        if ($tipo === 'propietario') {
            $barberia = $this->validateBarberia((array) ($data['barberia'] ?? []));
        }

        $created = Database::transaction(function () use ($data, $email, $tipo, $barberia): array {
            $userId = $this->users->create([
                'email' => $email,
                'password_hash' => PasswordPolicy::hash((string) $data['password']),
                'nombres' => (string) $data['nombres'],
                'apellidos' => $data['apellidos'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'acepta_legal' => true,
            ]);

            $tenant = null;
            if ($tipo === 'cliente') {
                $this->users->createClienteProfile($userId, [
                    'nombres' => (string) $data['nombres'], 'apellidos' => $data['apellidos'] ?? null,
                    'telefono' => $data['telefono'] ?? null, 'email' => $email,
                ]);
            } else {
                $slug = $barberia['slug'] ?? $this->tenants->uniqueSlug($barberia['nombre_comercial']);
                $estado = $this->tenants->requiresApproval() ? 'pendiente' : 'activo';
                $tenantId = $this->tenants->create([
                    'slug' => $slug,
                    'nombre_comercial' => $barberia['nombre_comercial'],
                    'telefono' => $barberia['telefono'] ?? null,
                    'ruc' => $barberia['ruc'] ?? null,
                    'email' => $email,
                    'propietario' => $userId,
                    'estado' => $estado,
                ]);
                $this->memberships->upsert($tenantId, $userId, 'propietario', 'activo', null);
                $tenant = ['id' => $tenantId, 'slug' => $slug, 'nombre_comercial' => $barberia['nombre_comercial'], 'estado' => $estado];
            }
            $verifyToken = $this->createToken($userId, UserTokenRepository::VERIFY_EMAIL, $email, (int) Config::get('auth.verify_email_ttl_hours', 48) * 60, null);
            return ['user_id' => $userId, 'tenant' => $tenant, 'verify_token' => $verifyToken];
        });

        $user = $this->users->findById($created['user_id']);
        Mailer::send(AuthEmails::verifyEmail($email, (string) $data['nombres'], $created['verify_token']));
        Audit::record('auth.registro', 'exito', $request, $created['user_id'], $created['tenant']['id'] ?? null, 'usuarios', $created['user_id'], ['tipo' => $tipo]);

        $issued = $this->sessionService->issue($user, (string) ($data['canal'] ?? 'web'), $request, $data['dispositivo'] ?? null);
        if ($created['tenant'] !== null) {
            $this->sessions->setTenant((int) $issued['data']['sesion']['id'], (int) $created['tenant']['id']);
        }
        $issued['data'] = ['usuario' => UserRepository::publicProfile($user), 'barberia' => $created['tenant']] + $issued['data'];
        return $issued;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateBarberia(array $input): array
    {
        $nombre = trim((string) ($input['nombre_comercial'] ?? ''));
        $errors = [];
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
            $errors['barberia.nombre_comercial'][] = 'Indica el nombre comercial (3 a 120 caracteres).';
        }
        $out = ['nombre_comercial' => $nombre];

        if (isset($input['slug']) && $input['slug'] !== '') {
            $slug = strtolower(trim((string) $input['slug']));
            if (!Slug::isValid($slug)) {
                $errors['barberia.slug'][] = 'Solo minúsculas, números y guiones (3 a 80 caracteres).';
            } elseif (!$this->tenants->slugAvailable($slug)) {
                $errors['barberia.slug'][] = 'Este enlace ya está en uso. Prueba con otro.';
            } else {
                $out['slug'] = $slug;
            }
        }
        if (isset($input['ruc']) && $input['ruc'] !== '') {
            $ruc = trim((string) $input['ruc']);
            if (!preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
                $errors['barberia.ruc'][] = 'RUC inválido (11 dígitos).';
            } elseif ($this->tenants->rucExists($ruc)) {
                $errors['barberia.ruc'][] = 'Ya existe una barbería registrada con este RUC.';
            } else {
                $out['ruc'] = $ruc;
            }
        }
        if (isset($input['telefono']) && $input['telefono'] !== '') {
            $tel = preg_replace('/[\s\-()]/', '', (string) $input['telefono']);
            if (!preg_match('/^\+?\d{6,15}$/', (string) $tel)) {
                $errors['barberia.telefono'][] = 'Teléfono inválido.';
            } else {
                $out['telefono'] = $tel;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    // ================================================================ login / logout

    /** @return array{data: array<string, mixed>, cookie: ?string, expires: int} */
    public function login(string $email, string $password, string $canal, ?string $dispositivo, Request $request): array
    {
        $email = UserRepository::normalizeEmail($email);
        $ip = $request->ip();
        $accountKey = $email . '|' . $ip;

        try {
            $this->limiter->ensureNotLimited('login_ip', $ip);
            $this->limiter->ensureNotLimited('login_cuenta', $accountKey);
        } catch (HttpException $e) {
            Audit::denied('auth.login_limitado', $request, ['email_hash' => hash('sha256', $email)]);
            throw $e;
        }

        $user = $this->users->findByEmail($email);
        $hash = $user['password_hash'] ?? null;
        // Siempre se ejecuta password_verify para no revelar por tiempo si el correo existe.
        $valid = password_verify($password, is_string($hash) && $hash !== '' ? $hash : self::dummyHash()) && $user !== null && $hash !== null;

        if (!$valid) {
            $this->limiter->hit('login_ip', $ip);
            $this->limiter->hit('login_cuenta', $accountKey);
            Audit::denied('auth.login_fallido', $request, ['email_hash' => hash('sha256', $email)], $user !== null ? (int) $user['id'] : null);
            throw new HttpException(401, 'invalid_credentials', 'Correo o contraseña incorrectos.');
        }
        if ($user['estado'] !== 'activo') {
            Audit::denied('auth.login_cuenta_bloqueada', $request, [], (int) $user['id']);
            throw new HttpException(403, 'account_disabled', 'Tu cuenta está bloqueada. Contacta con soporte.');
        }

        $this->limiter->clear('login_cuenta', $accountKey);
        if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], PasswordPolicy::hash($password));
        }
        $this->users->recordLogin((int) $user['id']);

        $issued = $this->sessionService->issue($user, $canal, $request, $dispositivo);
        // Si el usuario trabaja en una sola barbería, queda seleccionada como contexto.
        $memberships = $this->memberships->forUser((int) $user['id'], ['activo']);
        if (count($memberships) === 1) {
            $this->sessions->setTenant((int) $issued['data']['sesion']['id'], (int) $memberships[0]['tenant']['id']);
        }
        Audit::record('auth.login', 'exito', $request, (int) $user['id'], null, 'sesiones', (int) $issued['data']['sesion']['id'], ['canal' => $canal]);

        $issued['data'] = ['usuario' => UserRepository::publicProfile($user)] + $issued['data'];
        return $issued;
    }

    public function logout(Request $request, bool $everywhere): int
    {
        $session = (array) $request->attribute('auth_session');
        $userId = (int) $session['usuario_id'];
        $this->sessions->revoke((int) $session['id'], 'logout');
        $others = $everywhere ? $this->sessions->revokeAllForUser($userId, 'logout_todas') : 0;
        Audit::record($everywhere ? 'auth.logout_todas' : 'auth.logout', 'exito', $request, $userId, null, 'sesiones', (int) $session['id']);
        return $others + 1;
    }

    // ================================================================ recuperación de contraseña

    /** Siempre responde lo mismo exista o no el correo (no se revela qué cuentas existen). */
    public function forgotPassword(string $email, Request $request): void
    {
        $email = UserRepository::normalizeEmail($email);
        $this->limiter->consume('recuperacion_ip', $request->ip());
        $this->limiter->consume('recuperacion_email', $email);

        $user = $this->users->findByEmail($email);
        if ($user === null || $user['estado'] !== 'activo') {
            Audit::record('auth.recuperacion_solicitada', 'denegado', $request, null, null, null, null, ['email_hash' => hash('sha256', $email), 'motivo' => 'cuenta_inexistente_o_inactiva']);
            return;
        }
        $token = Database::transaction(function () use ($user, $request): string {
            $this->tokens->invalidatePending((int) $user['id'], UserTokenRepository::RESET_PASSWORD);
            return $this->createToken((int) $user['id'], UserTokenRepository::RESET_PASSWORD, (string) $user['email'], (int) Config::get('auth.reset_password_ttl_minutes', 60), $request->ip());
        });
        Mailer::send(AuthEmails::resetPassword((string) $user['email'], (string) $user['nombres'], $token));
        Audit::record('auth.recuperacion_solicitada', 'exito', $request, (int) $user['id']);
    }

    /** Cambia la contraseña con un token de correo y cierra todas las sesiones del usuario. */
    public function resetPassword(string $token, string $password, Request $request): void
    {
        $this->limiter->ensureNotLimited('restablecer_ip', $request->ip());
        $invalid = new HttpException(400, 'invalid_token', 'El enlace no es válido o ya venció. Solicita uno nuevo.');
        if (!Tokens::looksValid($token)) {
            $this->limiter->hit('restablecer_ip', $request->ip());
            Audit::denied('auth.restablecer_token_invalido', $request);
            throw $invalid;
        }

        $result = Database::transaction(function () use ($token, $password): array {
            $row = $this->tokens->findValidForUpdate(Tokens::hash($token), UserTokenRepository::RESET_PASSWORD);
            if ($row === null) {
                return ['ok' => false];
            }
            $errors = PasswordPolicy::errors($password, (string) $row['email_actual']);
            if ($errors !== []) {
                throw new ValidationException(['password' => $errors]);
            }
            $userId = (int) $row['usuario_id'];
            $this->users->updatePassword($userId, PasswordPolicy::hash($password));
            $this->tokens->markUsed((int) $row['id']);
            $this->tokens->invalidatePending($userId, UserTokenRepository::RESET_PASSWORD);
            // Quien recibe el enlace en su correo demuestra que el correo es suyo.
            $this->users->markEmailVerified($userId);
            $revoked = $this->sessions->revokeAllForUser($userId, 'password_reset');
            return ['ok' => true, 'user_id' => $userId, 'revoked' => $revoked];
        });

        if (!$result['ok']) {
            $this->limiter->hit('restablecer_ip', $request->ip());
            Audit::denied('auth.restablecer_token_invalido', $request);
            throw $invalid;
        }
        $user = $this->users->findById($result['user_id']);
        if ($user !== null) {
            Mailer::send(AuthEmails::passwordChanged((string) $user['email'], (string) $user['nombres']));
        }
        Audit::record('auth.password_restablecida', 'exito', $request, $result['user_id'], null, 'usuarios', $result['user_id'], ['sesiones_cerradas' => $result['revoked']]);
    }

    // ================================================================ verificación de correo

    public function verifyEmail(string $token, Request $request): array
    {
        $this->limiter->ensureNotLimited('restablecer_ip', $request->ip());
        $userId = null;
        if (Tokens::looksValid($token)) {
            $userId = Database::transaction(function () use ($token): ?int {
                $row = $this->tokens->findValidForUpdate(Tokens::hash($token), UserTokenRepository::VERIFY_EMAIL);
                if ($row === null) {
                    return null;
                }
                $this->tokens->markUsed((int) $row['id']);
                $this->users->markEmailVerified((int) $row['usuario_id']);
                return (int) $row['usuario_id'];
            });
        }
        if ($userId === null) {
            $this->limiter->hit('restablecer_ip', $request->ip());
            Audit::denied('auth.verificacion_token_invalido', $request);
            throw new HttpException(400, 'invalid_token', 'El enlace de verificación no es válido o ya venció. Solicita uno nuevo desde tu cuenta.');
        }
        Audit::record('auth.email_verificado', 'exito', $request, $userId, null, 'usuarios', $userId);
        return UserRepository::publicProfile((array) $this->users->findById($userId));
    }

    /** Reenvía el correo de verificación al usuario autenticado. Devuelve false si ya estaba verificado. */
    public function resendVerification(Request $request): bool
    {
        $userId = (int) $request->attribute('auth_user')['id'];
        $user = $this->users->findById($userId);
        if ($user === null || $user['email_verificado_en'] !== null) {
            return false;
        }
        $this->limiter->consume('verificacion_usuario', (string) $userId);
        $token = Database::transaction(function () use ($user, $request): string {
            $this->tokens->invalidatePending((int) $user['id'], UserTokenRepository::VERIFY_EMAIL);
            return $this->createToken((int) $user['id'], UserTokenRepository::VERIFY_EMAIL, (string) $user['email'], (int) Config::get('auth.verify_email_ttl_hours', 48) * 60, $request->ip());
        });
        Mailer::send(AuthEmails::verifyEmail((string) $user['email'], (string) $user['nombres'], $token));
        Audit::record('auth.verificacion_reenviada', 'exito', $request, $userId);
        return true;
    }

    // ================================================================ helpers

    /** Hash real (mismo costo) para igualar el tiempo de respuesta cuando el correo no existe. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= PasswordPolicy::hash(bin2hex(random_bytes(16)));
    }

    /** Crea un token de un solo uso y devuelve el valor en claro (solo viaja por correo). */
    public function createToken(int $userId, string $tipo, string $email, int $ttlMinutes, ?string $ip): string
    {
        $token = Tokens::random();
        $expires = Clock::nowUtc()->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');
        $this->tokens->create($userId, $tipo, Tokens::hash($token), $email, $expires, $ip);
        return $token;
    }
}
