<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Codes;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailMessage;

/**
 * Solicitudes de derechos sobre datos personales (ARCO, Ley 29733).
 *
 *  - Autoservicio: descargar mis datos (acceso) y eliminar mi cuenta (cancelación) se resuelven
 *    al instante y quedan registradas como atendidas.
 *  - Formulario: lo que la app no resuelve sola (p. ej. oponerse a un tratamiento, pedir datos
 *    que guarda una barbería) lo responde el equipo de BARBERAPP dentro del plazo legal.
 */
final class DataRequests
{
    public const TIPOS = ['acceso', 'rectificacion', 'cancelacion', 'oposicion'];
    public const ESTADOS = ['recibida', 'en_proceso', 'atendida', 'rechazada'];
    public const NOMBRES = [
        'acceso' => 'Acceso a mis datos', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación (supresión)', 'oposicion' => 'Oposición',
    ];

    /** Registra una solicitud y devuelve su fila presentada. */
    public static function create(int $userId, string $email, string $tipo, string $origen, ?string $detalle): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new ValidationException(['tipo' => ['Tipo de solicitud inválido.']]);
        }
        $now = Clock::nowUtc();
        $dias = (int) (((array) Config::get('privacy.plazos_dias_habiles', []))[$tipo] ?? 10);
        $auto = $origen === 'autoservicio';
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO solicitudes_datos (codigo, usuario_id, email_hash, tipo, origen, estado, detalle, respuesta, plazo_vence_en, atendida_en, created_at, updated_at)
             VALUES (:codigo, :u, :h, :tipo, :origen, :estado, :detalle, :respuesta, :plazo, :atendida, :c, :c2)'
        );
        for ($i = 0; ; $i++) {
            $codigo = 'DP' . Codes::random(10);
            try {
                $stmt->execute([
                    'codigo' => $codigo, 'u' => $userId, 'h' => self::emailHash($email), 'tipo' => $tipo, 'origen' => $origen,
                    'estado' => $auto ? 'atendida' : 'recibida', 'detalle' => $detalle,
                    'respuesta' => $auto ? self::autoAnswer($tipo) : null,
                    'plazo' => BusinessDays::add($now, $dias)->format('Y-m-d'),
                    'atendida' => $auto ? $now->format('Y-m-d H:i:s') : null,
                    'c' => $now->format('Y-m-d H:i:s'), 'c2' => $now->format('Y-m-d H:i:s'),
                ]);
                break;
            } catch (\PDOException $e) {
                if ($i >= 3 || ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        return self::find((int) $db->lastInsertId());
    }

    public static function emailHash(string $email): string
    {
        return hash('sha256', UserRepository::normalizeEmail($email));
    }

    private static function autoAnswer(string $tipo): string
    {
        return $tipo === 'acceso'
            ? 'Atendida al instante: descargaste una copia de tus datos desde la app.'
            : 'Atendida al instante: eliminaste tu cuenta desde la app.';
    }

    /** Solicitud escrita por la persona desde la app. */
    public static function submit(array $user, array $input, Request $request): array
    {
        $tipo = (string) ($input['tipo'] ?? '');
        $detalle = trim((string) ($input['detalle'] ?? ''));
        $errors = [];
        if (!in_array($tipo, self::TIPOS, true)) {
            $errors['tipo'][] = 'Elige el derecho que quieres ejercer.';
        }
        if (mb_strlen($detalle) < 10 || mb_strlen($detalle) > 2000) {
            $errors['detalle'][] = 'Cuéntanos qué necesitas (10 a 2000 caracteres).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $abiertas = (int) self::scalar("SELECT COUNT(*) FROM solicitudes_datos WHERE usuario_id = ? AND estado IN ('recibida','en_proceso')", [(int) $user['id']]);
        if ($abiertas >= 5) {
            throw new HttpException(409, 'too_many_open_requests', 'Ya tienes 5 solicitudes en curso. Espera la respuesta antes de enviar otra.');
        }
        (new \App\Services\Auth\RateLimiter())->consume('solicitud_usuario', (string) $user['id']);
        $row = self::create((int) $user['id'], (string) $user['email'], $tipo, 'formulario', $detalle);
        Audit::record('privacidad.solicitud_creada', 'exito', $request, (int) $user['id'], null, 'solicitudes_datos', (int) $row['id'], ['tipo' => $tipo]);
        Mailer::send(new MailMessage((string) $user['email'], "Recibimos tu solicitud {$row['codigo']}",
            "Hola {$user['nombres']},\n\nRecibimos tu solicitud de " . mb_strtolower(self::NOMBRES[$tipo]) . " sobre tus datos personales (número {$row['codigo']}).\n"
            . "Te responderemos a más tardar el {$row['plazo_vence_en']}. Puedes ver su estado en Mi cuenta → Privacidad.\n\nBARBERAPP", null, (string) $user['nombres']));
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM solicitudes_datos WHERE usuario_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
        $stmt->execute([$userId]);
        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /**
     * Bandeja del equipo de BARBERAPP. No muestra el correo ni datos de la cuenta salvo nombre y
     * correo actuales, necesarios para responder.
     *
     * @return array{items: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public static function inbox(?string $estado): array
    {
        $where = '';
        $params = [];
        if ($estado === 'abiertas') {
            $where = "WHERE s.estado IN ('recibida','en_proceso')";
        } elseif ($estado !== null && in_array($estado, self::ESTADOS, true)) {
            $where = 'WHERE s.estado = ?';
            $params[] = $estado;
        }
        $stmt = Database::connection()->prepare(
            "SELECT s.*, u.nombres AS usuario_nombres, u.apellidos AS usuario_apellidos, u.email AS usuario_email, u.estado AS usuario_estado
               FROM solicitudes_datos s LEFT JOIN usuarios u ON u.id = s.usuario_id
               {$where} ORDER BY s.estado IN ('recibida','en_proceso') DESC, s.plazo_vence_en, s.id DESC LIMIT 200"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $item = self::present($r);
            $item['persona'] = $r['usuario_estado'] === null || $r['usuario_estado'] === 'eliminado'
                ? null
                : ['nombre' => trim($r['usuario_nombres'] . ' ' . ($r['usuario_apellidos'] ?? '')), 'email' => (string) $r['usuario_email']];
            $items[] = $item;
        }
        $hoy = Clock::nowUtc()->format('Y-m-d');
        $resumen = Database::connection()->query(
            "SELECT SUM(estado IN ('recibida','en_proceso')) AS abiertas,
                    SUM(estado IN ('recibida','en_proceso') AND plazo_vence_en < '{$hoy}') AS vencidas,
                    SUM(estado = 'atendida') AS atendidas, SUM(estado = 'rechazada') AS rechazadas
               FROM solicitudes_datos"
        )->fetch();
        return ['items' => $items, 'resumen' => array_map('intval', (array) $resumen)];
    }

    /** El equipo cambia el estado y responde; la persona recibe la respuesta por correo y en la bandeja. */
    public static function respond(int $id, array $input, int $staffId, Request $request): array
    {
        $estado = (string) ($input['estado'] ?? '');
        $respuesta = trim((string) ($input['respuesta'] ?? ''));
        $errors = [];
        if (!in_array($estado, ['en_proceso', 'atendida', 'rechazada'], true)) {
            $errors['estado'][] = 'Elige: en proceso, atendida o rechazada.';
        }
        if (in_array($estado, ['atendida', 'rechazada'], true) && (mb_strlen($respuesta) < 10 || mb_strlen($respuesta) > 2000)) {
            $errors['respuesta'][] = 'Escribe la respuesta para la persona (10 a 2000 caracteres).';
        }
        if (mb_strlen($respuesta) > 2000) {
            $errors['respuesta'][] = 'Máximo 2000 caracteres.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $before = Database::transaction(static function () use ($id, $estado, $respuesta, $staffId): array {
            $stmt = Database::connection()->prepare('SELECT * FROM solicitudes_datos WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new NotFoundException('Solicitud no encontrada.');
            }
            if (in_array($row['estado'], ['atendida', 'rechazada'], true)) {
                throw new HttpException(409, 'request_closed', 'Esta solicitud ya fue respondida.');
            }
            $final = in_array($estado, ['atendida', 'rechazada'], true);
            Database::connection()->prepare(
                'UPDATE solicitudes_datos SET estado = ?, respuesta = ?, atendida_en = ?, atendida_por = ? WHERE id = ?'
            )->execute([$estado, $respuesta !== '' ? $respuesta : $row['respuesta'], $final ? Clock::nowSql() : null, $final ? $staffId : null, $id]);
            return $row;
        });

        $row = self::find($id);
        Audit::record('privacidad.solicitud_respondida', 'exito', $request, $staffId, null, 'solicitudes_datos', $id, ['estado' => $estado], ['estado' => $before['estado']]);
        if ($before['usuario_id'] !== null && $estado !== 'en_proceso') {
            $user = (new UserRepository())->findById((int) $before['usuario_id']);
            if ($user !== null && $user['estado'] !== 'eliminado') {
                $titulo = $estado === 'atendida' ? 'Atendimos tu solicitud' : 'Respuesta a tu solicitud';
                Mailer::send(new MailMessage((string) $user['email'], "{$titulo} {$row['codigo']}",
                    "Hola {$user['nombres']},\n\nRespuesta a tu solicitud {$row['codigo']} (" . mb_strtolower(self::NOMBRES[$row['tipo']]) . "):\n\n{$respuesta}\n\n"
                    . "Si no estás de acuerdo, puedes presentar una reclamación ante la Autoridad Nacional de Protección de Datos Personales (Ministerio de Justicia y Derechos Humanos).\n\nBARBERAPP",
                    null, (string) $user['nombres']));
                $now = Clock::nowSql();
                Database::connection()->prepare(
                    "INSERT INTO notificaciones (tenant_id, usuario_id, canal, tipo, titulo, cuerpo, datos, estado, programada_para_utc, enviada_en, dedupe_key)
                     VALUES (NULL, ?, 'interna', 'privacidad_solicitud', ?, ?, ?, 'enviada', ?, ?, ?) ON DUPLICATE KEY UPDATE id = id"
                )->execute([(int) $user['id'], $titulo, "Tu solicitud {$row['codigo']} fue " . ($estado === 'atendida' ? 'atendida' : 'respondida') . '. Revisa el detalle en Mi cuenta.',
                    json_encode(['url' => 'pages/auth/cuenta.html#privacidad'], JSON_UNESCAPED_SLASHES), $now, $now, "privacidad:{$id}:{$estado}"]);
            }
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public static function find(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM solicitudes_datos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException('Solicitud no encontrada.');
        }
        return self::present($row);
    }

    /** @param array<string, mixed> $r */
    private static function present(array $r): array
    {
        $iso = static fn ($v) => $v === null ? null : str_replace(' ', 'T', (string) $v) . 'Z';
        $abierta = in_array($r['estado'], ['recibida', 'en_proceso'], true);
        return [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'tipo' => (string) $r['tipo'],
            'tipo_nombre' => self::NOMBRES[$r['tipo']] ?? (string) $r['tipo'],
            'origen' => (string) $r['origen'],
            'estado' => (string) $r['estado'],
            'detalle' => $r['detalle'],
            'respuesta' => $r['respuesta'],
            'plazo_vence_en' => (string) $r['plazo_vence_en'],
            'vencida' => $abierta && (string) $r['plazo_vence_en'] < Clock::nowUtc()->format('Y-m-d'),
            'creada_en' => $iso($r['created_at']),
            'atendida_en' => $iso($r['atendida_en']),
        ];
    }

    /** @param list<mixed> $params @return mixed */
    private static function scalar(string $sql, array $params)
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
