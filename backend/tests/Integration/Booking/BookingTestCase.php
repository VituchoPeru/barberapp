<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Core\Response;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Catalog\CatalogTestCase;

/** Utilidades para las pruebas del motor de reservas (Fase 5). */
abstract class BookingTestCase extends CatalogTestCase
{
    protected const CLIENTE1 = 'cliente1@barberapp.test';
    protected const CLIENTE2 = 'cliente2@barberapp.test';

    private int $keySeq = 0;

    /** @return array<string, string> sesión web de un cliente (sin X-Tenant: el cliente no es personal) */
    protected function client(string $email = self::CLIENTE1): array
    {
        $this->cookies = [];
        return ['X-CSRF-Token' => $this->loginWeb($email)];
    }

    /**
     * POST /reservas con una Idempotency-Key nueva (o la indicada).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $h
     */
    protected function book(array $body, array $h, ?string $key = null): Response
    {
        $key ??= 'test-' . (++$this->keySeq) . '-' . bin2hex(random_bytes(4));
        return $this->call('POST', '/reservas', $body + ['slug' => 'demo-centro', 'canal' => 'marketplace'], $h + ['Idempotency-Key' => $key]);
    }

    /** @return array<string, mixed> cuerpo base de una reserva en demo-centro, sede Cercado */
    protected function body(string $fecha, string $hora, array $servicios = ['Corte clásico'], ?string $barbero = 'Barbero Uno Demo'): array
    {
        return [
            'sucursal_id' => $this->branchId('sede-cercado'),
            'servicio_ids' => array_map(fn (string $s): int => $this->serviceId($s), $servicios),
            'barbero_id' => $barbero === null ? null : $this->barberId($barbero),
            'fecha' => $fecha,
            'hora' => $hora,
        ];
    }

    /** @return list<string> horas ofrecidas ese día */
    protected function hours(string $fecha, array $servicios = ['Corte clásico'], ?string $barbero = 'Barbero Uno Demo', array $h = []): array
    {
        $qs = http_build_query(array_filter([
            'slug' => 'demo-centro',
            'sucursal_id' => $this->branchId('sede-cercado'),
            'servicio_ids' => implode(',', array_map(fn (string $s): int => $this->serviceId($s), $servicios)),
            'barbero_id' => $barbero === null ? null : $this->barberId($barbero),
            'fecha' => $fecha,
        ], static fn ($v) => $v !== null));
        $res = $this->call('GET', '/disponibilidad?' . $qs, null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        return array_column($this->data($res)['horarios'], 'hora');
    }

    /** Fecha local (Lima) del próximo día ISO dado, al menos $skip días después de hoy. */
    protected function day(int $iso, int $skip = 2): string
    {
        return $this->nextLocal($iso, $skip);
    }

    protected function localNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    }
}
