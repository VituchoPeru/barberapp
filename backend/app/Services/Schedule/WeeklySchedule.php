<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Valida la jornada semanal de un barbero antes de guardarla.
 *
 * Reglas:
 *  - Cada tramo: día 1..7, hora de fin mayor que la de inicio, sucursal activa de la barbería.
 *  - El tramo debe caber dentro del horario comercial de su sucursal ese día
 *    (el barbero no puede ofrecer citas con el local cerrado).
 *  - Dos tramos del mismo día no pueden solaparse, AUNQUE sean de sucursales distintas:
 *    así un barbero nunca está disponible en dos sedes a la vez.
 *    (Si ambos tienen vigencias que no se cruzan, sí pueden coexistir.)
 *  - Cada descanso debe estar dentro de un tramo del mismo día y no solaparse con otro descanso.
 */
final class WeeklySchedule
{
    public const MAX_TRAMOS = 42;
    public const MAX_DESCANSOS = 21;
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d(:00)?$/';

    /**
     * @param array<string, mixed> $input  {tramos: [...], descansos: [...]}
     * @param array<int, array{nombre:string, activo:bool, horario:list<array{0:int, 1:string, 2:string}>}> $branches
     * @return array{tramos: list<array{dia_semana:int, hora_inicio:string, hora_fin:string, sucursal_id:int, vigente_desde:?string, vigente_hasta:?string}>,
     *               descansos: list<array{dia_semana:int, hora_inicio:string, hora_fin:string, motivo:?string}>}
     */
    public static function normalize(array $input, array $branches): array
    {
        $errors = [];
        $rawTramos = $input['tramos'] ?? null;
        $rawDescansos = $input['descansos'] ?? [];
        if (!is_array($rawTramos) || !self::isList($rawTramos)) {
            throw new ValidationException(['tramos' => ['Envía la lista de tramos de la jornada (puede estar vacía).']]);
        }
        if (!is_array($rawDescansos) || !self::isList($rawDescansos)) {
            throw new ValidationException(['descansos' => ['Debe ser una lista.']]);
        }
        if (count($rawTramos) > self::MAX_TRAMOS) {
            throw new ValidationException(['tramos' => ['Máximo ' . self::MAX_TRAMOS . ' tramos por semana.']]);
        }
        if (count($rawDescansos) > self::MAX_DESCANSOS) {
            throw new ValidationException(['descansos' => ['Máximo ' . self::MAX_DESCANSOS . ' descansos por semana.']]);
        }

        $tramos = [];
        foreach ($rawTramos as $i => $t) {
            $key = "tramos.{$i}";
            if (!is_array($t)) {
                $errors[$key][] = 'Tramo inválido.';
                continue;
            }
            $dia = self::day($t['dia_semana'] ?? null);
            [$ini, $fin] = [self::time($t['hora_inicio'] ?? null), self::time($t['hora_fin'] ?? null)];
            $suc = filter_var($t['sucursal_id'] ?? null, FILTER_VALIDATE_INT);
            $desde = self::date($t['vigente_desde'] ?? null);
            $hasta = self::date($t['vigente_hasta'] ?? null);
            if ($dia === null) {
                $errors[$key][] = 'Día inválido (1 = lunes … 7 = domingo).';
            } elseif ($ini === null || $fin === null) {
                $errors[$key][] = 'Indica la hora de inicio y fin (HH:MM).';
            } elseif (Intervals::minutes($fin) <= Intervals::minutes($ini)) {
                $errors[$key][] = 'La hora de fin debe ser posterior a la de inicio.';
            } elseif ($suc === false || !isset($branches[$suc])) {
                $errors[$key][] = 'Sucursal no encontrada.';
            } elseif (!$branches[$suc]['activo']) {
                $errors[$key][] = "La sucursal {$branches[$suc]['nombre']} está desactivada.";
            } elseif ($desde === false || $hasta === false) {
                $errors[$key][] = 'Vigencia inválida (AAAA-MM-DD).';
            } elseif ($desde !== null && $hasta !== null && $hasta < $desde) {
                $errors[$key][] = 'La vigencia termina antes de empezar.';
            } else {
                $abierto = [];
                foreach ($branches[$suc]['horario'] as [$d, $a, $c]) {
                    if ($d === $dia) {
                        $abierto[] = [Intervals::minutes($a), Intervals::minutes($c)];
                    }
                }
                if (!Intervals::covers($abierto, Intervals::minutes($ini), Intervals::minutes($fin))) {
                    $errors[$key][] = $abierto === []
                        ? "La sucursal {$branches[$suc]['nombre']} no abre ese día."
                        : "Fuera del horario de atención de {$branches[$suc]['nombre']} (" . self::describe($abierto) . ').';
                }
                $tramos[$i] = [
                    'dia_semana' => $dia, 'hora_inicio' => $ini, 'hora_fin' => $fin, 'sucursal_id' => (int) $suc,
                    'vigente_desde' => $desde, 'vigente_hasta' => $hasta,
                ];
            }
        }

        // Solapamientos entre tramos (misma u otra sucursal).
        $keys = array_keys($tramos);
        foreach ($keys as $x => $i) {
            foreach (array_slice($keys, $x + 1) as $j) {
                $a = $tramos[$i];
                $b = $tramos[$j];
                if ($a['dia_semana'] !== $b['dia_semana'] || !self::validityOverlaps($a, $b)) {
                    continue;
                }
                if (Intervals::overlaps(Intervals::minutes($a['hora_inicio']), Intervals::minutes($a['hora_fin']), Intervals::minutes($b['hora_inicio']), Intervals::minutes($b['hora_fin']))) {
                    $errors["tramos.{$j}"][] = $a['sucursal_id'] === $b['sucursal_id']
                        ? "Se cruza con el tramo {$a['hora_inicio']}–{$a['hora_fin']} del mismo día."
                        : "Se cruza con {$a['hora_inicio']}–{$a['hora_fin']} en {$branches[$a['sucursal_id']]['nombre']}: un barbero no puede atender en dos sucursales a la vez.";
                }
            }
        }

        $descansos = [];
        foreach ($rawDescansos as $i => $d) {
            $key = "descansos.{$i}";
            if (!is_array($d)) {
                $errors[$key][] = 'Descanso inválido.';
                continue;
            }
            $dia = self::day($d['dia_semana'] ?? null);
            [$ini, $fin] = [self::time($d['hora_inicio'] ?? null), self::time($d['hora_fin'] ?? null)];
            $motivo = isset($d['motivo']) && is_string($d['motivo']) && trim($d['motivo']) !== '' ? trim($d['motivo']) : null;
            if ($dia === null) {
                $errors[$key][] = 'Día inválido (1 = lunes … 7 = domingo).';
                continue;
            }
            if ($ini === null || $fin === null || Intervals::minutes($fin) <= Intervals::minutes($ini)) {
                $errors[$key][] = 'Indica un rango de horas válido (HH:MM).';
                continue;
            }
            if ($motivo !== null && mb_strlen($motivo) > 80) {
                $errors[$key][] = 'El motivo admite hasta 80 caracteres.';
                continue;
            }
            [$s, $e] = [Intervals::minutes($ini), Intervals::minutes($fin)];
            $dentro = false;
            foreach ($tramos as $t) {
                if ($t['dia_semana'] === $dia && Intervals::minutes($t['hora_inicio']) <= $s && $e <= Intervals::minutes($t['hora_fin'])) {
                    $dentro = true;
                    break;
                }
            }
            if (!$dentro) {
                $errors[$key][] = 'El descanso debe estar dentro de un tramo de la jornada de ese día.';
                continue;
            }
            foreach ($descansos as $prev) {
                if ($prev['dia_semana'] === $dia && Intervals::overlaps(Intervals::minutes($prev['hora_inicio']), Intervals::minutes($prev['hora_fin']), $s, $e)) {
                    $errors[$key][] = "Se cruza con el descanso {$prev['hora_inicio']}–{$prev['hora_fin']}.";
                    continue 2;
                }
            }
            $descansos[] = ['dia_semana' => $dia, 'hora_inicio' => $ini, 'hora_fin' => $fin, 'motivo' => $motivo];
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Revisa la jornada: hay tramos o descansos inválidos.');
        }
        $tramos = array_values($tramos);
        usort($tramos, static fn (array $a, array $b): int => [$a['dia_semana'], $a['hora_inicio']] <=> [$b['dia_semana'], $b['hora_inicio']]);
        usort($descansos, static fn (array $a, array $b): int => [$a['dia_semana'], $a['hora_inicio']] <=> [$b['dia_semana'], $b['hora_inicio']]);
        return ['tramos' => $tramos, 'descansos' => $descansos];
    }

    /** @param list<array{0:int, 1:int}> $intervals */
    private static function describe(array $intervals): string
    {
        return implode(' y ', array_map(static fn (array $i): string => Intervals::hhmm($i[0]) . '–' . Intervals::hhmm($i[1]), Intervals::union($intervals)));
    }

    /** @param array{vigente_desde:?string, vigente_hasta:?string} $a @param array{vigente_desde:?string, vigente_hasta:?string} $b */
    private static function validityOverlaps(array $a, array $b): bool
    {
        $aFrom = $a['vigente_desde'] ?? '0000-01-01';
        $aTo = $a['vigente_hasta'] ?? '9999-12-31';
        $bFrom = $b['vigente_desde'] ?? '0000-01-01';
        $bTo = $b['vigente_hasta'] ?? '9999-12-31';
        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    /** array_is_list() no existe en PHP 8.0. @param array<mixed> $a */
    public static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /** @param mixed $v */
    private static function day($v): ?int
    {
        $d = filter_var($v, FILTER_VALIDATE_INT);
        return $d !== false && $d >= 1 && $d <= 7 && !is_bool($v) ? (int) $d : null;
    }

    /** @param mixed $v */
    private static function time($v): ?string
    {
        return is_string($v) && preg_match(self::TIME, $v) ? substr($v, 0, 5) : null;
    }

    /**
     * @param mixed $v
     * @return string|null|false null = sin valor; false = inválida
     */
    private static function date($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : false;
    }
}
