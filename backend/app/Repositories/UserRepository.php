<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Clock;
use App\Services\Privacy\LegalInfo;

/** Cuentas de usuario (tabla usuarios). */
final class UserRepository extends BaseRepository
{
    private const COLUMNS = 'u.id, u.email, u.password_hash, u.nombres, u.apellidos, u.telefono, u.avatar_url, u.estado,
        u.email_verificado_en, u.ultimo_login_en, u.rol_plataforma_id, u.created_at, u.deleted_at,
        u.acepto_terminos_en, u.terminos_version, u.acepto_privacidad_en, u.privacidad_version';

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM usuarios u WHERE u.email = :email AND u.deleted_at IS NULL LIMIT 1',
            ['email' => self::normalizeEmail($email)]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM usuarios u WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public function emailExists(string $email): bool
    {
        // Incluye cuentas eliminadas: el índice único de email no permite reutilizarlo.
        return $this->fetchOne('SELECT id FROM usuarios WHERE email = :email LIMIT 1', ['email' => self::normalizeEmail($email)]) !== null;
    }

    /** @param array{email:string, password_hash:?string, nombres:string, apellidos:?string, telefono:?string, acepta_legal:bool} $data */
    public function create(array $data): int
    {
        $now = Clock::nowSql();
        return $this->insert(
            'INSERT INTO usuarios (email, password_hash, nombres, apellidos, telefono, acepto_terminos_en, terminos_version,
                acepto_privacidad_en, privacidad_version, created_at, updated_at)
             VALUES (:email, :hash, :nombres, :apellidos, :telefono, :terminos, :tv, :privacidad, :pv, :c, :u)',
            [
                'email' => self::normalizeEmail($data['email']),
                'hash' => $data['password_hash'],
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'],
                'telefono' => $data['telefono'],
                'terminos' => $data['acepta_legal'] ? $now : null,
                'privacidad' => $data['acepta_legal'] ? $now : null,
                'tv' => $data['acepta_legal'] ? LegalInfo::terminosVersion() : null,
                'pv' => $data['acepta_legal'] ? LegalInfo::privacidadVersion() : null,
                'c' => $now,
                'u' => $now,
            ]
        );
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->execute('UPDATE usuarios SET password_hash = :h WHERE id = :id', ['h' => $hash, 'id' => $id]);
    }

    public function markEmailVerified(int $id): void
    {
        $this->execute(
            'UPDATE usuarios SET email_verificado_en = COALESCE(email_verificado_en, :now) WHERE id = :id',
            ['now' => Clock::nowSql(), 'id' => $id]
        );
    }

    public function recordLogin(int $id): void
    {
        $this->execute('UPDATE usuarios SET ultimo_login_en = :now WHERE id = :id', ['now' => Clock::nowSql(), 'id' => $id]);
    }

    /** Registra la aceptación de las versiones vigentes de los términos y la política de privacidad. */
    public function acceptLegal(int $id): void
    {
        $now = Clock::nowSql();
        $this->execute(
            'UPDATE usuarios SET acepto_terminos_en = :a, terminos_version = :tv, acepto_privacidad_en = :b, privacidad_version = :pv WHERE id = :id',
            ['a' => $now, 'tv' => LegalInfo::terminosVersion(), 'b' => $now, 'pv' => LegalInfo::privacidadVersion(), 'id' => $id]
        );
    }

    /** Rectificación de datos (Fase 13): nombre, apellidos y teléfono. */
    public function updateProfile(int $id, string $nombres, ?string $apellidos, ?string $telefono): void
    {
        $this->execute(
            'UPDATE usuarios SET nombres = :n, apellidos = :a, telefono = :t WHERE id = :id',
            ['n' => $nombres, 'a' => $apellidos, 't' => $telefono, 'id' => $id]
        );
        $this->execute(
            'UPDATE clientes SET nombres = :n, apellidos = :a, telefono = :t WHERE usuario_id = :id AND deleted_at IS NULL',
            ['n' => $nombres, 'a' => $apellidos, 't' => $telefono, 'id' => $id]
        );
    }

    /** Cambia el correo (ya confirmado por enlace) en la cuenta y en su perfil de cliente. */
    public function updateEmail(int $id, string $email): void
    {
        $email = self::normalizeEmail($email);
        $this->execute(
            'UPDATE usuarios SET email = :e, email_verificado_en = :now WHERE id = :id',
            ['e' => $email, 'now' => Clock::nowSql(), 'id' => $id]
        );
        $this->execute('UPDATE clientes SET email = :e WHERE usuario_id = :id AND deleted_at IS NULL', ['e' => $email, 'id' => $id]);
    }

    public function isCliente(int $id): bool
    {
        return $this->fetchOne('SELECT id FROM clientes WHERE usuario_id = :id AND deleted_at IS NULL', ['id' => $id]) !== null;
    }

    /** @param array{nombres:string, apellidos:?string, telefono:?string, email:string} $data */
    public function createClienteProfile(int $userId, array $data): int
    {
        return $this->insert(
            'INSERT INTO clientes (usuario_id, nombres, apellidos, telefono, email) VALUES (:u, :n, :a, :t, :e)',
            ['u' => $userId, 'n' => $data['nombres'], 'a' => $data['apellidos'], 't' => $data['telefono'], 'e' => self::normalizeEmail($data['email'])]
        );
    }

    /**
     * Datos del usuario que pueden enviarse al frontend (nunca password_hash).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicProfile(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'nombres' => (string) $row['nombres'],
            'apellidos' => $row['apellidos'] !== null ? (string) $row['apellidos'] : null,
            'telefono' => $row['telefono'] ?? null,
            'avatar_url' => $row['avatar_url'] ?? null,
            'email_verificado' => !empty($row['email_verificado_en']),
            'estado' => (string) $row['estado'],
            'legal_pendiente' => array_key_exists('terminos_version', $row) ? LegalInfo::pending($row) : [],
        ];
    }
}
