<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, implode("\n", [
            '# comentario',
            'BA_PLAIN=valor',
            'BA_QUOTED="con espacios # no es comentario"',
            "BA_SINGLE='literal\\n'",
            'BA_INLINE=abc # comentario en línea',
            'export BA_EXPORTED=si',
            'BA_TRUE=true',
            'BA_FALSE=false',
            'BA_NULL=null',
            'BA_EMPTY=',
            'BA_REAL=desde-archivo',
        ]));
        putenv('BA_REAL=desde-entorno');
        Env::reset();
        Env::load($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('BA_REAL');
    }

    public function testParsesValues(): void
    {
        $this->assertSame('valor', Env::get('BA_PLAIN'));
        $this->assertSame('con espacios # no es comentario', Env::get('BA_QUOTED'));
        $this->assertSame('literal\\n', Env::get('BA_SINGLE'));
        $this->assertSame('abc', Env::get('BA_INLINE'));
        $this->assertSame('si', Env::get('BA_EXPORTED'));
        $this->assertSame('', Env::get('BA_EMPTY'));
    }

    public function testCastsBooleansAndNull(): void
    {
        $this->assertTrue(Env::get('BA_TRUE'));
        $this->assertFalse(Env::get('BA_FALSE'));
        $this->assertNull(Env::get('BA_NULL'));
        $this->assertSame('def', Env::get('BA_NO_EXISTE', 'def'));
    }

    public function testRealEnvironmentWins(): void
    {
        $this->assertSame('desde-entorno', Env::get('BA_REAL'));
    }

    public function testRequireThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::require('BA_NO_EXISTE');
    }
}
