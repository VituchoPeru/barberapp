<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\MembershipRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserTokenRepository;
use App\Services\Auth\AuthEmails;
use App\Services\Auth\AuthService;
use App\Services\Mail\Mailer;

/** Asociación de usuarios a una barbería: invitar, revocar. Siempre dentro del tenant del contexto. */
final class StaffService
{
    public const ROLES_INVITABLES = ['administrador', 'barbero'];

    private MembershipRepository $memberships;
    private UserRepository $users;

    public function __construct(private int $tenantId)
    {
        $this->memberships = new MembershipRepository();
        $this->users = new UserRepository();
    }

    /**
     * Invita por correo. Si la persona no tiene cuenta, se crea sin contraseña y recibe un
     * enlace para crearla. La membresía queda 'invitado' hasta que la acepte.
     *
     * @param array{email:string, rol:string, nombres:?string} $data
     * @return array<string, mixed>
     */
    public function invite(array $data, Request $request): array
    {
        $tenant = (array) $request->attribute('tenant');
        $inviter = (int) ((array) $request->attribute('auth_user'))['id'];
        $email = UserRepository::normalizeEmail($data['email']);

        $result = Database::transaction(function () use ($data, $email, $inviter): array {
            $user = $this->users->findByEmail($email);
            $token = null;
            $nuevo = false;
            if ($user === null) {
                if ($this->users->emailExists($email)) {
                    throw new HttpException(409, 'email_unavailable', 'Este correo no puede usarse.');
                }
                if (empty($data['nombres'])) {
                    throw new \App\Core\Exceptions\ValidationException(['nombres' => ['Indica el nombre de la persona que invitas.']]);
                }
                $userId = $this->users->create([
                    'email' => $email, 'password_hash' => null, 'nombres' => (string) $data['nombres'],
                    'apellidos' => null, 'telefono' => null, 'acepta_legal' => false,
                ]);
                $token = (new AuthService())->createToken($userId, UserTokenRepository::RESET_PASSWORD, $email, 72 * 60, null);
                $nombre = (string) $data['nombres'];
                $nuevo = true;
            } else {
                $userId = (int) $user['id'];
                $nombre = (string) $user['nombres'];
                $active = $this->memberships->activeRoles($userId, $this->tenantId);
                if (in_array($data['rol'], $active, true)) {
                    throw new HttpException(409, 'already_member', 'Esta persona ya tiene ese rol en tu barbería.');
                }
            }
            $membershipId = $this->memberships->upsert($this->tenantId, $userId, $data['rol'], 'invitado', $inviter);
            return ['membresia_id' => $membershipId, 'usuario_id' => $userId, 'nombre' => $nombre, 'token' => $token, 'cuenta_nueva' => $nuevo];
        });

        Mailer::send(AuthEmails::invitation($email, $result['nombre'], (string) $tenant['nombre_comercial'], $data['rol'], $result['token']));
        Audit::record('personal.invitado', 'exito', $request, $inviter, $this->tenantId, 'membresias', $result['membresia_id'], ['rol' => $data['rol'], 'cuenta_nueva' => $result['cuenta_nueva']]);
        return ['membresia_id' => $result['membresia_id'], 'usuario_id' => $result['usuario_id'], 'email' => $email, 'rol' => $data['rol'], 'estado' => 'invitado', 'cuenta_nueva' => $result['cuenta_nueva']];
    }

    public function revoke(int $membershipId, Request $request): void
    {
        $actor = (int) ((array) $request->attribute('auth_user'))['id'];
        $row = $this->memberships->findInTenant($this->tenantId, $membershipId);
        if ($row === null || $row['estado'] === 'revocado') {
            throw new NotFoundException('Integrante no encontrado.');
        }
        if ($row['rol'] === 'propietario' && $row['estado'] === 'activo' && $this->memberships->countActiveOwners($this->tenantId) <= 1) {
            throw new HttpException(422, 'last_owner', 'La barbería debe conservar al menos un propietario.');
        }
        if ((int) $row['usuario_id'] === $actor && $row['rol'] === 'propietario') {
            throw new HttpException(422, 'cannot_revoke_self', 'No puedes quitarte tu propio rol de propietario.');
        }
        $this->memberships->setStatus($membershipId, 'revocado');
        Audit::record('personal.revocado', 'exito', $request, $actor, $this->tenantId, 'membresias', $membershipId, ['rol' => $row['rol']], ['estado' => $row['estado']]);
    }
}
