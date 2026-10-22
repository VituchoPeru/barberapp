<?php

declare(strict_types=1);

namespace Tests\Integration\Panel;

use App\Core\Response;
use App\Helpers\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Booking\BookingTestCase;

/** Utilidades para las pruebas del panel administrativo (Fase 6). */
abstract class PanelTestCase extends BookingTestCase
{
    protected const ADMIN = 'admin.centro@barberapp.test';
    protected const BARBERO1 = 'barbero1.centro@barberapp.test';

    private int $panelKeySeq = 0;

    /** Reserva demo por estado y hora local de mañana (ver DemoSeeder). @return array<string, mixed> */
    protected function demoBooking(string $estado, string $tenant = 'demo-centro'): array
    {
        $stmt = $this->db->prepare('SELECT * FROM reservas WHERE tenant_id = ? AND estado = ? ORDER BY inicio_utc LIMIT 1');
        $stmt->execute([$this->tenantId($tenant), $estado]);
        return (array) $stmt->fetch();
    }

    /** Congela el reloj de la aplicación en un instante UTC (+ minutos). */
    protected function freezeAt(string $utc, int $plusMinutes = 0): void
    {
        Clock::freeze((new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify(sprintf('%+d minutes', $plusMinutes)));
    }

    protected function localDateOf(string $utc): string
    {
        return Clock::utcToLocal($utc, 'America/Lima')->format('Y-m-d');
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $h
     */
    protected function panelBook(array $body, array $h, ?string $key = null): Response
    {
        $key ??= 'panel-' . (++$this->panelKeySeq) . '-' . bin2hex(random_bytes(4));
        return $this->call('POST', '/panel/reservas', $body, $h + ['Idempotency-Key' => $key]);
    }

    /** @return array<string, mixed> cuerpo de alta manual en la sede Cercado */
    protected function panelBody(string $fecha, string $hora, array $cliente, array $servicios = ['Corte clásico'], ?string $barbero = 'Barbero Uno Demo'): array
    {
        return [
            'sucursal_id' => $this->branchId('sede-cercado'),
            'servicio_ids' => array_map(fn (string $s): int => $this->serviceId($s), $servicios),
            'barbero_id' => $barbero === null ? null : $this->barberId($barbero),
            'fecha' => $fecha,
            'hora' => $hora,
        ] + $cliente;
    }
}
