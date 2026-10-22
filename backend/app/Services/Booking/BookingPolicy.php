<?php

declare(strict_types=1);

namespace App\Services\Booking;

/**
 * Política de reserva vigente para una sucursal (tabla politicas_reserva).
 * Se usa la fila propia de la sucursal; si no existe, la general de la barbería; y si tampoco,
 * los valores por defecto de la tabla.
 */
final class BookingPolicy
{
    public const DEFAULTS = [
        'intervalo_minutos' => 15,
        'margen_entre_citas_minutos' => 0,
        'anticipacion_minima_minutos' => 60,
        'horizonte_maximo_dias' => 30,
        'cancelacion_limite_minutos' => 120,
        'reprogramacion_limite_minutos' => 120,
        'max_reprogramaciones' => 2,
        'max_reservas_activas_cliente' => 3,
        'confirmacion_automatica' => true,
        'permite_cualquier_barbero' => true,
        'texto_politica_cancelacion' => null,
    ];

    public function __construct(
        public int $intervalo,
        public int $margen,
        public int $anticipacion,
        public int $horizonteDias,
        public int $cancelacionLimite,
        public int $reprogramacionLimite,
        public int $maxReprogramaciones,
        public int $maxActivasCliente,
        public bool $confirmacionAutomatica,
        public bool $permiteCualquierBarbero,
        public ?string $texto = null,
        public ?int $sucursalId = null,
    ) {
    }

    /** @param array<string, mixed>|null $row */
    public static function fromRow(?array $row): self
    {
        $r = ($row ?? []) + self::DEFAULTS;
        return new self(
            (int) $r['intervalo_minutos'],
            (int) $r['margen_entre_citas_minutos'],
            (int) $r['anticipacion_minima_minutos'],
            (int) $r['horizonte_maximo_dias'],
            (int) $r['cancelacion_limite_minutos'],
            (int) $r['reprogramacion_limite_minutos'],
            (int) $r['max_reprogramaciones'],
            (int) $r['max_reservas_activas_cliente'],
            (bool) $r['confirmacion_automatica'],
            (bool) $r['permite_cualquier_barbero'],
            $r['texto_politica_cancelacion'] === null ? null : (string) $r['texto_politica_cancelacion'],
            isset($r['sucursal_id']) ? (int) $r['sucursal_id'] : null,
        );
    }

    /**
     * Versión para el personal de la barbería (agenda del panel): sin anticipación mínima ni
     * horizonte corto. La jornada, los bloqueos, la grilla y el margen se respetan igual.
     */
    public function forStaff(): self
    {
        $p = clone $this;
        $p->anticipacion = 0;
        $p->horizonteDias = max($this->horizonteDias, self::STAFF_HORIZON_DAYS);
        return $p;
    }

    public const STAFF_HORIZON_DAYS = 365;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sucursal_id' => $this->sucursalId,
            'intervalo_minutos' => $this->intervalo,
            'margen_entre_citas_minutos' => $this->margen,
            'anticipacion_minima_minutos' => $this->anticipacion,
            'horizonte_maximo_dias' => $this->horizonteDias,
            'cancelacion_limite_minutos' => $this->cancelacionLimite,
            'reprogramacion_limite_minutos' => $this->reprogramacionLimite,
            'max_reprogramaciones' => $this->maxReprogramaciones,
            'max_reservas_activas_cliente' => $this->maxActivasCliente,
            'confirmacion_automatica' => $this->confirmacionAutomatica,
            'permite_cualquier_barbero' => $this->permiteCualquierBarbero,
            'texto_politica_cancelacion' => $this->texto,
        ];
    }
}
