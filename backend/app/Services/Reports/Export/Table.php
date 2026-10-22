<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

/**
 * Tabla exportable: título, encabezados y filas. Las celdas son string, int, float o null;
 * los números se escriben como números (Excel puede sumarlos) y el texto como texto.
 */
final class Table
{
    /**
     * @param list<string>                       $headers
     * @param list<list<string|int|float|null>>  $rows
     */
    public function __construct(public string $title, public array $headers, public array $rows = [])
    {
    }
}
