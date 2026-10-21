<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/** Fuente única de tiempo. Todas las fechas de eventos se manejan en UTC. */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function nowUtc(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Formato DATETIME de MySQL en UTC. */
    public static function nowSql(): string
    {
        return self::nowUtc()->format('Y-m-d H:i:s');
    }

    /** Convierte una fecha/hora local de una sucursal a UTC. */
    public static function localToUtc(string $localDateTime, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($localDateTime, new DateTimeZone($timezone)))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function utcToLocal(string $utcDateTime, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
    }

    /** Solo para pruebas. */
    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }
}
