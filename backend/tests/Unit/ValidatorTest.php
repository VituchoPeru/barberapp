<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Helpers\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const RULES = [
        'nombre' => 'required|string|max:120',
        'duracion_minutos' => 'required|integer|min:1|max:600',
        'precio' => 'required|decimal|min:0',
        'email' => 'nullable|email',
        'activo' => 'boolean',
    ];

    public function testValidDataIsCastAndWhitelisted(): void
    {
        $out = Validator::validate([
            'nombre' => '  Corte clásico ',
            'duracion_minutos' => '45',
            'precio' => 25,
            'email' => null,
            'activo' => 'true',
            'tenant_id' => 999, // no declarado: debe descartarse
        ], self::RULES);

        $this->assertSame(['nombre' => 'Corte clásico', 'duracion_minutos' => 45, 'precio' => '25.00', 'email' => null, 'activo' => true], $out);
    }

    public function testRejectsZeroDurationAndNegativePrice(): void
    {
        try {
            Validator::validate(['nombre' => 'X', 'duracion_minutos' => 0, 'precio' => '-1.00'], self::RULES);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            $fields = $e->details()['fields'];
            $this->assertArrayHasKey('duracion_minutos', $fields);
            $this->assertArrayHasKey('precio', $fields);
            $this->assertSame(422, $e->status());
        }
    }

    public function testRequiredAndFormatErrors(): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate(['duracion_minutos' => 'abc', 'precio' => '1.999', 'email' => 'no-es-correo'], self::RULES);
    }
}
