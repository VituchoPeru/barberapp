<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Repositories\SessionRepository;
use App\Services\Audit;
use App\Services\Auth\Gate;

/**
 * Usuarios de plataforma (Fase 9): el equipo de BARBERAPP (superadmin y operador) y la
 * búsqueda de cuentas para soporte y bloqueo por abuso.
 *
 * Privacidad: la búsqueda pide al menos 3 caracteres, devuelve como máximo 20 cuentas y solo
 * datos de la cuenta (nombre, correo, estado, fechas y en qué barberías trabaja). No muestra
 * teléfonos, reservas, historial ni datos de clientes de las barberías. Cada búsqueda y cada
 * ficha consultada queda en la auditoría.
 */
final class PlatformUserService extends BaseRepository
{
    public const ROLES = ['superadmin', 'operador'];

    /** @return list<array<string, mixed>> */
    public function team(): array
    {
        $rows = $this->fetchAll(
            "SELECT u.id, u.email, u.nombres, u.apellidos, u.estado, u.email_verificado_en, u.ultimo_login_en, u.created_at, u.bloqueado_en, u.motivo_bloqueo, r.codigo rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id AND r.ambito = 'plataforma'
              WHERE u.deleted_at IS NULL ORDER BY r.codigo = 'superadmin' DESC, u.nombres, u.id"
        );
        return array_map(fn (array $r): array => $this->present($r), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $q, Request $request): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 3) {
            throw new ValidationException(['q' => ['Escribe al menos 3 caracteres del correo o del nombre.']]);
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 120));
        $rows = $this->fetchAll(
            "SELECT u.id, u.email, u.nombres, u.apellidos, u.estado, u.email_verificado_en, u.ultimo_login_en, u.created_at, u.bloqueado_en, u.motivo_bloqueo, r.codigo rol
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_plataforma_id
              WHERE u.deleted_at IS NULL AND (u.email LIKE :e OR CONCAT(u.nombres, ' ', COALESCE(u.apellidos, '')) LIKE :n)
              ORDER BY u.email = :exact DESC, u.email LIMIT 20",
            ['e' => $like . '%', 'n' => '%' . $like . '%', 'exact' => $q]
        );
        Audit::record('plataforma.usuarios.busqueda', 'exito', $request, null, null, 'usuarios', null, ['q' => mb_substr($q, 0, 120), 'resultados' => count($rows)]);
        return array_map(fn (array $r): array => $this->present($r, true), $rows);
    }

    /** @return array<string, mixed> */
    public function show(int $id, Request $request): array
    {
        $u = $this->find($id);
        $active = (int) ($this->fetchOne('SELECT COUNT(*) n FROM sesiones WHERE usuario_id = :u AND revocada_en IS NULL AND expira_en > :n', ['u' => $id, 'n' => Clock::nowSql()])['n'] ?? 0);
        Audit::record('plataforma.usuarios.consulta', 'exito', $request, null, null, 'usuarios', $id);
        $out = $this->present($u, true);
        $out['sesiones_activas'] = $active;
        $out['historial'] = array_map(static fn (array $h): array => [
            'accion' => (string) $h['accion'], 'fecha' => str_replace(' ', 'T', (string) $h['created_at']) . 'Z', 'por' => $h['email'],
            'datos' => json_decode((string) ($h['datos_nuevos'] ?? 'null'), true),
        ], $this->fetchAll(
            "SELECT a.accion, a.created_at, a.datos_nuevos, x.email FROM auditoria a LEFT JOIN usuarios x ON x.id = a.usuario_id
              WHERE a.entidad = 'usuarios' AND a.entidad_id = :id AND a.accion IN ('plataforma.usuario.bloqueado','plataforma.usuario.desbloqueado','plataforma.usuario.rol')
              ORDER BY a.id DESC LIMIT 20",
            ['id' => $id]
        ));
        return $out;
    }

    /** @return array<string, mixed> */
    public function block(int $id, string $motivo, Request $request): array
    {
        $me = self::actor($request);
        if ($id === $me) {
            throw new HttpException(409, 'cannot_block_self', 'No puedes bloquear tu propia cuenta.');
        }
        $revoked = Database::transaction(function () use ($id, $motivo): int {
            $u = $this->lockUser($id);
            if ($u['estado'] === 'bloqueado') {
                throw new HttpException(409, 'already_blocked', 'La cuenta ya está bloqueada.');
            }
            $this->assertNotLastSuperadmin($u, 'bloquear');
            $this->execute("UPDATE usuarios SET estado = 'bloqueado', bloqueado_en = :n, motivo_bloqueo = :m WHERE id = :id", ['n' => Clock::nowSql(), 'm' => $motivo, 'id' => $id]);
            return (new SessionRepository())->revokeAllForUser($id, 'bloqueo');
        });
        Audit::record('plataforma.usuario.bloqueado', 'exito', $request, null, null, 'usuarios', $id, ['motivo' => $motivo, 'sesiones_cerradas' => $revoked]);
        return $this->present($this->find($id), true) + ['sesiones_cerradas' => $revoked];
    }

    /** @return array<string, mixed> */
    public function unblock(int $id, Request $request): array
    {
        Database::transaction(function () use ($id): void {
            $u = $this->lockUser($id);
            if ($u['estado'] !== 'bloqueado') {
                throw new HttpException(409, 'not_blocked', 'La cuenta no está bloqueada.');
            }
            $this->execute("UPDATE usuarios SET estado = 'activo', bloqueado_en = NULL, motivo_bloqueo = NULL WHERE id = :id", ['id' => $id]);
        });
        Audit::record('plataforma.usuario.desbloqueado', 'exito', $request, null, null, 'usuarios', $id);
        return $this->present($this->find($id), true);
    }

    /**
     * Da o quita un rol de plataforma. Solo a cuentas activas con correo verificado; nadie se
     * cambia su propio rol y siempre queda al menos un superadministrador activo.
     *
     * @return array<string, mixed>
     */
    public function setRole(int $id, ?string $rol, Request $request): array
    {
        if ($id === self::actor($request)) {
            throw new HttpException(409, 'cannot_change_own_role', 'No puedes cambiar tu propio rol de plataforma.');
        }
        if ($rol !== null && !in_array($rol, self::ROLES, true)) {
            throw new ValidationException(['rol' => ['Rol de plataforma desconocido.']]);
        }
        [$antes, $despues] = Database::transaction(function () use ($id, $rol): array {
            $u = $this->lockUser($id);
            $actual = $u['rol'];
            if ($actual === $rol) {
                throw new HttpException(409, 'no_changes', 'La cuenta ya tiene ese rol.');
            }
            if ($rol !== null && ($u['estado'] !== 'activo' || $u['email_verificado_en'] === null)) {
                throw new HttpException(409, 'account_not_eligible', 'Solo una cuenta activa y con correo verificado puede formar parte del equipo de la plataforma.');
            }
            if ($actual === 'superadmin') {
                $this->assertNotLastSuperadmin($u, 'quitar el rol a');
            }
            $rolId = $rol === null ? null : (int) ($this->fetchOne("SELECT id FROM roles WHERE codigo = :c AND ambito = 'plataforma'", ['c' => $rol])['id'] ?? 0);
            if ($rol !== null && !$rolId) {
                throw new HttpException(500, 'role_missing', 'El rol de plataforma no existe. Ejecuta php bin/console db:seed.');
            }
            $this->execute('UPDATE usuarios SET rol_plataforma_id = :r WHERE id = :id', ['r' => $rolId, 'id' => $id]);
            // Sus sesiones abiertas se cierran para que el cambio de permisos rija de inmediato.
            (new SessionRepository())->revokeAllForUser($id, 'cambio_rol_plataforma');
            return [$actual, $rol];
        });
        Gate::flush();
        Audit::record('plataforma.usuario.rol', 'exito', $request, null, null, 'usuarios', $id, ['rol' => $despues], ['rol' => $antes]);
        return $this->present($this->find($id), true);
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $u = $this->fetchOne(
            'SELECT u.id, u.email, u.nombres, u.apellidos, u.estado, u.email_verificado_en, u.ultimo_login_en, u.created_at, u.bloqueado_en, u.motivo_bloqueo, r.codigo rol
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_plataforma_id WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $id]
        );
        if ($u === null) {
            throw new NotFoundException('Cuenta no encontrada.');
        }
        return $u;
    }

    /** @return array<string, mixed> */
    private function lockUser(int $id): array
    {
        $u = $this->fetchOne(
            'SELECT u.id, u.estado, u.email_verificado_en, r.codigo rol FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_plataforma_id
              WHERE u.id = :id AND u.deleted_at IS NULL FOR UPDATE',
            ['id' => $id]
        );
        if ($u === null) {
            throw new NotFoundException('Cuenta no encontrada.');
        }
        return $u;
    }

    /** @param array<string, mixed> $u */
    private function assertNotLastSuperadmin(array $u, string $que): void
    {
        if ($u['rol'] !== 'superadmin') {
            return;
        }
        $others = (int) ($this->fetchOne(
            "SELECT COUNT(*) n FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id
              WHERE r.codigo = 'superadmin' AND u.estado = 'activo' AND u.deleted_at IS NULL AND u.id <> :id FOR UPDATE",
            ['id' => (int) $u['id']]
        )['n'] ?? 0);
        if ($others === 0) {
            throw new HttpException(409, 'last_superadmin', "No puedes {$que} el único superadministrador activo.");
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(array $r, bool $withMemberships = false): array
    {
        $out = [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'nombre' => trim($r['nombres'] . ' ' . ($r['apellidos'] ?? '')),
            'estado' => (string) $r['estado'],
            'email_verificado' => $r['email_verificado_en'] !== null,
            'rol_plataforma' => $r['rol'] ?? null,
            'ultimo_login_en' => $r['ultimo_login_en'] ? str_replace(' ', 'T', (string) $r['ultimo_login_en']) . 'Z' : null,
            'creado_en' => str_replace(' ', 'T', (string) $r['created_at']) . 'Z',
            'bloqueado_en' => $r['bloqueado_en'] ? str_replace(' ', 'T', (string) $r['bloqueado_en']) . 'Z' : null,
            'motivo_bloqueo' => $r['motivo_bloqueo'],
        ];
        if ($withMemberships) {
            $out['barberias'] = array_map(static fn (array $m): array => [
                'slug' => (string) $m['slug'], 'nombre' => (string) $m['nombre_comercial'], 'rol' => (string) $m['rol'], 'estado' => (string) $m['estado'],
            ], $this->fetchAll(
                'SELECT t.slug, t.nombre_comercial, r.codigo rol, m.estado FROM membresias m JOIN tenants t ON t.id = m.tenant_id JOIN roles r ON r.id = m.rol_id
                  WHERE m.usuario_id = :u AND t.deleted_at IS NULL ORDER BY t.nombre_comercial LIMIT 20',
                ['u' => (int) $r['id']]
            ));
        }
        return $out;
    }

    private static function actor(Request $request): int
    {
        return (int) (((array) $request->attribute('auth_user'))['id'] ?? 0);
    }
}
