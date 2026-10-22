<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Core\Config;
use App\Services\Mail\Mailer;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Push\ArrayPushTransport;
use App\Services\Notifications\Push\Push;
use App\Services\Notifications\QueueWorker;
use App\Services\Notifications\Templates;
use Tests\Integration\Panel\PanelTestCase;

/** Utilidades de las pruebas de avisos y recordatorios (Fase 8). */
abstract class NotificationsTestCase extends PanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('notifications.push.driver', 'array');
        ArrayPushTransport::reset();
        Push::use(null);
        NotificationSettings::flush();
        Templates::flush();
    }

    protected function tearDown(): void
    {
        Push::use(null);
        ArrayPushTransport::reset();
        NotificationSettings::flush();
        parent::tearDown();
    }

    /** @return array<string, int> */
    protected function work(): array
    {
        return (new QueueWorker('test-worker'))->run(500, 60);
    }

    /** Reserva de Cliente Uno con Barbero Uno; devuelve la reserva presentada. @return array<string, mixed> */
    protected function bookAs(string $email, string $fecha, string $hora): array
    {
        $res = $this->book($this->body($fecha, $hora), $this->client($email));
        $this->assertSame(201, $res->status(), $res->body());
        return $this->data($res);
    }

    /** @return list<array<string, mixed>> */
    protected function rows(int $reservaId, ?string $tipo = null): array
    {
        $sql = 'SELECT * FROM notificaciones WHERE reserva_id = ?' . ($tipo !== null ? ' AND tipo = ?' : '') . ' ORDER BY id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($tipo !== null ? [$reservaId, $tipo] : [$reservaId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    protected function reminder(int $reservaId, int $horas, string $canal): array
    {
        $stmt = $this->db->prepare("SELECT * FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND canal = ? AND CAST(JSON_EXTRACT(datos, '$.horas') AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$reservaId, $canal, $horas]);
        $row = $stmt->fetch();
        $this->assertIsArray($row, "No hay recordatorio de {$horas} h por {$canal}.");
        return $row;
    }

    protected function inicio(int $reservaId): string
    {
        return (string) $this->scalar('SELECT inicio_utc FROM reservas WHERE id = ?', [$reservaId]);
    }

    protected function registerDevice(string $email, string $token, string $plataforma = 'android'): void
    {
        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb($email)];
        $res = $this->call('POST', '/me/dispositivos', ['token' => $token, 'plataforma' => $plataforma, 'modelo' => 'Pixel de prueba', 'app_version' => '0.8.0'], $h);
        $this->assertSame(201, $res->status(), $res->body());
    }

    protected function mailsTo(string $email): int
    {
        return count(array_filter(Mailer::sent(), static fn ($m) => $m->to === $email));
    }
}
