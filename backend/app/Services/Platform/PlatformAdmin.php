<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Auth\PasswordPolicy;
use RuntimeException;

/**
 * Primer superadministrador de una instalación nueva (Fase 15):
 *   php bin/console plataforma:superadmin correo@dominio [--nombre="…"] [--apellidos="…"]
 *
 * En producción no hay datos de demostración: alguien con acceso al servidor tiene que crear la
 * primera cuenta de la plataforma. Si la cuenta existe se le asigna el rol; si no, se crea con
 * el correo verificado y una contraseña aleatoria que se muestra una sola vez (se cambia en
 * Mi cuenta). Al entrar se le piden los textos legales vigentes, como a cualquiera.
 */
final class PlatformAdmin
{
    /** @return array{usuario_id: int, creado: bool, clave: ?string} */
    public static function ensure(string $email, string $nombres = 'Administrador', ?string $apellidos = null): array
    {
        $email = UserRepository::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Correo inválido.');
        }
        $db = Database::connection();
        $rol = $db->query("SELECT id FROM roles WHERE codigo = 'superadmin'")->fetchColumn();
        if ($rol === false) {
            throw new RuntimeException('No existe el rol superadmin: ejecuta php bin/console db:seed.');
        }
        $users = new UserRepository();
        $user = $users->findByEmail($email);
        if ($user !== null) {
            $db->prepare('UPDATE usuarios SET rol_plataforma_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $rol, (int) $user['id']]);
            Audit::record('plataforma.superadmin_consola', 'exito', null, null, null, 'usuario', (int) $user['id'], ['rol' => 'superadmin', 'origen' => 'consola']);
            return ['usuario_id' => (int) $user['id'], 'creado' => false, 'clave' => null];
        }
        if ($users->emailExists($email)) {
            throw new RuntimeException('Ese correo perteneció a una cuenta eliminada; usa otro.');
        }
        $password = self::randomPassword();
        $id = $users->create([
            'email' => $email, 'password_hash' => PasswordPolicy::hash($password), 'nombres' => mb_substr($nombres, 0, 100),
            'apellidos' => $apellidos !== null ? mb_substr($apellidos, 0, 100) : null, 'telefono' => null, 'acepta_legal' => false,
        ]);
        $users->markEmailVerified($id);
        $db->prepare('UPDATE usuarios SET rol_plataforma_id = ? WHERE id = ?')->execute([(int) $rol, $id]);
        Audit::record('plataforma.superadmin_consola', 'exito', null, null, null, 'usuario', $id, ['rol' => 'superadmin', 'origen' => 'consola', 'creado' => true]);
        return ['usuario_id' => $id, 'creado' => true, 'clave' => $password];
    }

    private static function randomPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 18; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        // Siempre con letras y números (PasswordPolicy).
        return substr($p, 0, 6) . '-' . substr($p, 6, 6) . '-' . substr($p, 12) . random_int(2, 9);
    }
}
