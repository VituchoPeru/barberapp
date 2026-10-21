<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Auth\ApiTestCase;

/** Utilidades comunes para las pruebas del panel de servicios, barberos y horarios (Fase 4). */
abstract class CatalogTestCase extends ApiTestCase
{
    /** @return array<string, string> cabeceras de sesión web + barbería */
    protected function actingAs(string $email, string $tenant = 'demo-centro'): array
    {
        $this->cookies = [];
        return ['X-CSRF-Token' => $this->loginWeb($email), 'X-Tenant' => $tenant];
    }

    /** @return array<string, string> */
    protected function owner(): array
    {
        return $this->actingAs('propietario.centro@barberapp.test');
    }

    protected function barberId(string $nombre): int
    {
        return (int) $this->scalar('SELECT id FROM barberos WHERE nombre_publico = ?', [$nombre]);
    }

    protected function branchId(string $slug, string $tenant = 'demo-centro'): int
    {
        return (int) $this->scalar('SELECT id FROM sucursales WHERE slug = ? AND tenant_id = ?', [$slug, $this->tenantId($tenant)]);
    }

    protected function serviceId(string $nombre): int
    {
        return (int) $this->scalar('SELECT id FROM servicios WHERE nombre = ?', [$nombre]);
    }

    /** Próxima fecha local (Lima) con ese día ISO (1 = lunes), a partir de mañana + $skipDays. */
    protected function nextLocal(int $isoDay, int $skipDays = 1): string
    {
        $d = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->modify("+{$skipDays} day");
        while ((int) $d->format('N') !== $isoDay) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    protected function localDate(int $plusDays): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->modify(sprintf('%+d day', $plusDays))->format('Y-m-d');
    }

    /** Fecha local (Lima) de la primera reserva activa del barbero. */
    protected function bookingLocalDate(int $barberoId): string
    {
        $utc = (string) $this->scalar("SELECT MIN(inicio_utc) FROM reservas WHERE barbero_id = ? AND estado IN ('pendiente','confirmada')", [$barberoId]);
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Lima'))->format('Y-m-d');
    }
}
