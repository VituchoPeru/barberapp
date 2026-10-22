<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Dispositivos que reciben push (tabla dispositivos_push). El token del proveedor se guarda
 * tal cual (el worker lo necesita para enviar) y su SHA-256 asegura que un mismo teléfono
 * no quede registrado dos veces. Nunca se devuelve el token completo en la API.
 */
final class DeviceRepository extends BaseRepository
{
    public const MAX_ACTIVOS = 10;

    /**
     * Registra o reactiva el dispositivo para el usuario. Si el token estaba con otra cuenta
     * (se cambió de sesión en el mismo teléfono), pasa a esta: los avisos siguen a quien usa la app.
     */
    public function register(int $userId, string $token, string $plataforma, ?string $appVersion, ?string $modelo, string $now): int
    {
        $hash = hash('sha256', $token);
        $this->execute(
            "INSERT INTO dispositivos_push (usuario_id, plataforma, proveedor, token, token_hash, app_version, modelo, activo, ultimo_uso_en)
             VALUES (:u, :p, 'fcm', :t, :h, :v, :m, 1, :n)
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), plataforma = VALUES(plataforma), app_version = VALUES(app_version),
                                     modelo = VALUES(modelo), activo = 1, desactivado_en = NULL, motivo_desactivado = NULL, ultimo_uso_en = VALUES(ultimo_uso_en)",
            ['u' => $userId, 'p' => $plataforma, 't' => $token, 'h' => $hash, 'v' => $appVersion, 'm' => $modelo, 'n' => $now]
        );
        $id = (int) ($this->fetchOne('SELECT id FROM dispositivos_push WHERE token_hash = :h', ['h' => $hash])['id'] ?? 0);
        // Tope de dispositivos activos por cuenta: se desactivan los que llevan más tiempo sin usarse.
        $this->execute(
            "UPDATE dispositivos_push d
               JOIN (SELECT id FROM dispositivos_push WHERE usuario_id = :u AND activo = 1
                      ORDER BY ultimo_uso_en DESC, id DESC LIMIT 1000 OFFSET " . self::MAX_ACTIVOS . ") viejos ON viejos.id = d.id
                SET d.activo = 0, d.desactivado_en = :n, d.motivo_desactivado = 'Reemplazado por un dispositivo más reciente'",
            ['u' => $userId, 'n' => $now]
        );
        return $id;
    }

    public function unregister(int $userId, string $token, string $now): bool
    {
        return $this->execute(
            "UPDATE dispositivos_push SET activo = 0, desactivado_en = :n, motivo_desactivado = 'Desactivado desde la app'
              WHERE usuario_id = :u AND token_hash = :h AND activo = 1",
            ['n' => $now, 'u' => $userId, 'h' => hash('sha256', $token)]
        ) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'plataforma' => $d['plataforma'],
            'modelo' => $d['modelo'],
            'app_version' => $d['app_version'],
            'activo' => (bool) $d['activo'],
            'ultimo_uso_en' => $d['ultimo_uso_en'] !== null ? str_replace(' ', 'T', (string) $d['ultimo_uso_en']) . 'Z' : null,
            'motivo_desactivado' => $d['motivo_desactivado'],
        ], $this->fetchAll(
            'SELECT id, plataforma, modelo, app_version, activo, ultimo_uso_en, motivo_desactivado FROM dispositivos_push
              WHERE usuario_id = :u ORDER BY activo DESC, ultimo_uso_en DESC, id DESC LIMIT 20',
            ['u' => $userId]
        ));
    }

    public function deactivate(int $userId, int $id, string $now): bool
    {
        return $this->execute(
            "UPDATE dispositivos_push SET activo = 0, desactivado_en = :n, motivo_desactivado = 'Desactivado desde Mi cuenta'
              WHERE id = :id AND usuario_id = :u AND activo = 1",
            ['n' => $now, 'id' => $id, 'u' => $userId]
        ) > 0;
    }
}
