<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Clock;
use App\Services\Audit;
use App\Services\Booking\BookingService;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailMessage;
use PDO;
use Throwable;

/**
 * Eliminar la cuenta (derecho de cancelación, Ley 29733; requisito de App Store y Google Play).
 *
 * La eliminación es inmediata y no se puede deshacer:
 *   1. Se cancelan las citas futuras de la persona (la barbería recibe el aviso y el horario se libera).
 *   2. Se borran sesiones, dispositivos, favoritos, avisos, preferencias, tokens y claves de idempotencia.
 *   3. La fila de usuarios y su perfil de cliente se anonimizan: sin nombre, correo, teléfono,
 *      contraseña ni notas. Se conservan las filas (con id) para que el historial de la barbería
 *      (citas pasadas y cobros, obligación contable) siga cuadrando sin datos personales.
 *   4. Se revocan sus membresías y se desvincula su perfil de barbero (la barbería conserva el
 *      nombre público con el que trabajó, que es información del negocio).
 *
 * No se elimina si la persona es la única propietaria activa de una barbería vigente (primero
 * debe cerrarla o invitar a otro propietario) ni si es el único superadministrador.
 */
final class AccountDeletion
{
    public const EMAIL_DOMAIN = 'eliminado.invalid';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Motivos que impiden eliminar la cuenta ahora (vacío = se puede).
     *
     * @return list<array{codigo: string, mensaje: string, barberias?: list<string>}>
     */
    public function blockers(int $userId): array
    {
        $out = [];
        $stmt = $this->db->prepare(
            "SELECT t.nombre_comercial
               FROM membresias m
               JOIN roles r ON r.id = m.rol_id AND r.codigo = 'propietario'
               JOIN tenants t ON t.id = m.tenant_id AND t.estado <> 'cancelado' AND t.deleted_at IS NULL
              WHERE m.usuario_id = :u AND m.estado = 'activo'
                AND NOT EXISTS (
                    SELECT 1 FROM membresias m2
                      JOIN roles r2 ON r2.id = m2.rol_id AND r2.codigo = 'propietario'
                      JOIN usuarios u2 ON u2.id = m2.usuario_id AND u2.estado = 'activo' AND u2.deleted_at IS NULL
                     WHERE m2.tenant_id = m.tenant_id AND m2.estado = 'activo' AND m2.usuario_id <> m.usuario_id)
              ORDER BY t.nombre_comercial"
        );
        $stmt->execute(['u' => $userId]);
        $tenants = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($tenants !== []) {
            $out[] = [
                'codigo' => 'unico_propietario',
                'mensaje' => 'Eres el único propietario de ' . implode(', ', $tenants) . '. Invita a otro propietario desde el panel de la barbería '
                    . 'o escribe a soporte para darla de baja; después podrás eliminar tu cuenta.',
                'barberias' => $tenants,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT r.codigo FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === 'superadmin') {
            $others = (int) $this->db->query(
                "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id
                  WHERE r.codigo = 'superadmin' AND u.estado = 'activo' AND u.deleted_at IS NULL AND u.id <> " . (int) $userId
            )->fetchColumn();
            if ($others === 0) {
                $out[] = ['codigo' => 'ultimo_superadmin', 'mensaje' => 'Eres el único superadministrador de BARBERAPP. Asigna ese rol a otra persona antes de eliminar tu cuenta.'];
            }
        }
        return $out;
    }

    /**
     * Resumen de lo que pasará (para la pantalla de confirmación).
     *
     * @return array<string, mixed>
     */
    public function preview(int $userId): array
    {
        return [
            'bloqueos' => $this->blockers($userId),
            'citas_futuras' => count($this->futureBookings($userId)),
            'barberias_equipo' => (int) $this->scalar("SELECT COUNT(*) FROM membresias WHERE usuario_id = ? AND estado IN ('activo','invitado')", [$userId]),
        ];
    }

    /**
     * Elimina la cuenta. $user es la fila completa del usuario autenticado (ya reautenticado).
     *
     * @param array<string, mixed> $user
     * @return array{citas_canceladas: int, solicitud: string}
     */
    public function delete(array $user, ?Request $request, string $origen = 'usuario'): array
    {
        $userId = (int) $user['id'];
        $blockers = $this->blockers($userId);
        if ($blockers !== []) {
            throw new HttpException(409, 'account_deletion_blocked', $blockers[0]['mensaje'], ['bloqueos' => $blockers]);
        }

        // 1. Citas futuras: se cancelan con el motor de reservas (libera el horario y avisa a la barbería).
        $canceladas = 0;
        foreach ($this->futureBookings($userId) as $b) {
            try {
                (new BookingService((int) $b['tenant_id']))->cancel((int) $b['id'], $user, ['motivo' => 'La persona eliminó su cuenta de BARBERAPP.'], 'sistema', $request);
                $canceladas++;
            } catch (Throwable $e) {
                // Una cita que cambió en ese instante no impide eliminar la cuenta: queda anonimizada.
                Logger::warning('No se pudo cancelar una cita al eliminar la cuenta', ['reserva_id' => (int) $b['id'], 'exception' => $e]);
            }
        }

        $email = (string) $user['email'];
        $nombre = (string) $user['nombres'];

        // 2 a 4, en una sola transacción.
        $solicitud = Database::transaction(function () use ($userId, $email): string {
            $locked = $this->db->prepare('SELECT id FROM usuarios WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $locked->execute([$userId]);
            if ($locked->fetchColumn() === false) {
                throw new HttpException(409, 'account_already_deleted', 'La cuenta ya fue eliminada.');
            }
            $this->erase($userId);
            // Constancia del derecho ejercido (solo el hash del correo).
            return (string) DataRequests::create($userId, $email, 'cancelacion', 'autoservicio', null)['codigo'];
        });

        Audit::record('privacidad.cuenta_eliminada', 'exito', $request, $userId, null, 'usuarios', $userId, [
            'origen' => $origen, 'citas_canceladas' => $canceladas, 'solicitud' => $solicitud,
        ]);
        if ($origen === 'usuario') {
            Mailer::send(new MailMessage($email, 'Tu cuenta de BARBERAPP fue eliminada',
                "Hola {$nombre},\n\nEliminamos tu cuenta de BARBERAPP y tus datos personales, como pediste (constancia {$solicitud}).\n"
                . ($canceladas > 0 ? "Cancelamos {$canceladas} cita(s) futura(s) y avisamos a la barbería.\n" : '')
                . "Las barberías conservan el registro anónimo de las citas pasadas y sus cobros por obligaciones contables.\n"
                . "Las copias de seguridad cifradas se renuevan y tus datos desaparecen de ellas en un máximo de 14 días.\n\n"
                . "Si no fuiste tú, responde a este correo de inmediato.\n\nBARBERAPP", null, $nombre));
        }
        return ['citas_canceladas' => $canceladas, 'solicitud' => $solicitud];
    }

    /** @return list<array{id: int, tenant_id: int}> */
    private function futureBookings(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id, r.tenant_id FROM reservas r JOIN clientes c ON c.id = r.cliente_id
              WHERE c.usuario_id = ? AND r.estado IN ('pendiente','confirmada') AND r.inicio_utc > ?
              ORDER BY r.inicio_utc"
        );
        $stmt->execute([$userId, Clock::nowSql()]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'tenant_id' => (int) $r['tenant_id']], $stmt->fetchAll());
    }

    /** Borra y anonimiza los datos personales de la cuenta (dentro de la transacción). */
    private function erase(int $userId): void
    {
        $now = Clock::nowSql();
        $run = function (string $sql, array $params = []): void {
            $this->db->prepare($sql)->execute($params);
        };

        // Perfil de cliente: anónimo y desvinculado. Sus citas pasadas quedan sin notas del cliente.
        $clientes = $this->db->prepare('SELECT id FROM clientes WHERE usuario_id = ?');
        $clientes->execute([$userId]);
        foreach ($clientes->fetchAll(PDO::FETCH_COLUMN) as $clienteId) {
            $run('DELETE FROM favoritos WHERE cliente_id = ?', [$clienteId]);
            $run('UPDATE cliente_tenants SET notas_internas = NULL, acepta_marketing = 0 WHERE cliente_id = ?', [$clienteId]);
            $run('UPDATE reservas SET notas_cliente = NULL WHERE cliente_id = ?', [$clienteId]);
            $run("UPDATE clientes SET usuario_id = NULL, nombres = 'Cliente eliminado', apellidos = NULL, telefono = NULL, email = NULL,
                         fecha_nacimiento = NULL, deleted_at = COALESCE(deleted_at, ?) WHERE id = ?", [$now, $clienteId]);
        }

        // Rastros técnicos con datos de la persona.
        $run('UPDATE reserva_eventos SET ip = NULL WHERE actor_usuario_id = ?', [$userId]);
        $run('DELETE FROM sesiones WHERE usuario_id = ?', [$userId]);           // tokens_refresco en cascada
        $run('DELETE FROM tokens_usuario WHERE usuario_id = ?', [$userId]);
        $run('DELETE FROM dispositivos_push WHERE usuario_id = ?', [$userId]);
        $run('DELETE FROM preferencias_notificacion WHERE usuario_id = ?', [$userId]);
        $run('DELETE FROM notificaciones WHERE usuario_id = ?', [$userId]);     // notificacion_envios en cascada
        $run('DELETE FROM idempotency_keys WHERE usuario_id = ?', [$userId]);

        // Equipo de barberías y plataforma.
        $run("UPDATE membresias SET estado = 'revocado' WHERE usuario_id = ? AND estado <> 'revocado'", [$userId]);
        $run('UPDATE barberos SET usuario_id = NULL WHERE usuario_id = ?', [$userId]);

        // La cuenta: sin datos personales. El correo queda libre para registrarse de nuevo.
        $run("UPDATE usuarios SET email = ?, password_hash = NULL, nombres = 'Cuenta eliminada', apellidos = NULL, telefono = NULL,
                     avatar_url = NULL, rol_plataforma_id = NULL, estado = 'eliminado', email_verificado_en = NULL, ultimo_login_en = NULL, motivo_bloqueo = NULL,
                     deleted_at = ? WHERE id = ?", ['eliminado-' . $userId . '@' . self::EMAIL_DOMAIN, $now, $userId]);
    }

    /** @param list<mixed> $params @return mixed */
    private function scalar(string $sql, array $params)
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
