<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use App\Helpers\Clock;

/** Borra los registros diarios app-AAAA-MM-DD.log más antiguos que LOG_RETENTION_DAYS (Fase 15). */
final class LogPurge
{
    /** @return array{borrados: int, dias: int} */
    public static function run(?string $dir = null, ?int $days = null): array
    {
        $days = max(1, $days ?? (int) Config::get('ops.log_retention_days', 30));
        $dir = rtrim($dir ?? (string) Config::get('app.log_path'), '/');
        $limit = Clock::nowUtc()->setTime(0, 0)->getTimestamp() - ($days - 1) * 86400; // hoy y los $days - 1 días anteriores
        $n = 0;
        foreach (glob($dir . '/app-*.log') ?: [] as $f) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $f, $m) && (int) strtotime($m[1] . ' 00:00:00 UTC') < $limit && @unlink($f)) {
                $n++;
            }
        }
        return ['borrados' => $n, 'dias' => $days];
    }
}
