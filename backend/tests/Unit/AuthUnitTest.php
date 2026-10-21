<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Slug;
use App\Helpers\Tokens;
use App\Helpers\Validator;
use App\Core\Exceptions\ValidationException;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\TokenSigner;
use App\Services\Mail\MailMessage;
use PHPUnit\Framework\TestCase;

final class AuthUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    public function testSignedTokenRoundTripAndExpiry(): void
    {
        $token = TokenSigner::sign(['sub' => 5, 'sid' => 9, 'typ' => 'access', 'exp' => time() + 60]);
        $claims = TokenSigner::verify($token);
        $this->assertSame(5, $claims['sub']);
        $this->assertSame(9, $claims['sid']);

        Clock::freeze((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 minutes'));
        $this->assertNull(TokenSigner::verify($token), 'Token vencido');
    }

    public function testSignedTokenRejectsTamperingAndWrongType(): void
    {
        $token = TokenSigner::sign(['sub' => 5, 'sid' => 9, 'typ' => 'access', 'exp' => time() + 60]);
        $this->assertNull(TokenSigner::verify($token . 'x'));
        $this->assertNull(TokenSigner::verify(str_replace('.', '', $token)));
        $this->assertNull(TokenSigner::verify(TokenSigner::sign(['sub' => 5, 'sid' => 9, 'typ' => 'refresh', 'exp' => time() + 60])));
        $this->assertNull(TokenSigner::verify(TokenSigner::sign(['sub' => 5, 'typ' => 'access', 'exp' => time() + 60])), 'Sin sid');
    }

    public function testCsrfTokenIsStablePerSessionAndDifferentBetweenSessions(): void
    {
        $this->assertSame(TokenSigner::csrfFor(1, 'abc'), TokenSigner::csrfFor(1, 'abc'));
        $this->assertNotSame(TokenSigner::csrfFor(1, 'abc'), TokenSigner::csrfFor(2, 'abc'));
        $this->assertNotSame(TokenSigner::csrfFor(1, 'abc'), TokenSigner::csrfFor(1, 'abd'));
    }

    public function testPasswordPolicy(): void
    {
        $this->assertSame([], PasswordPolicy::errors('Corte2026x', 'ana@barberapp.test'));
        $this->assertNotSame([], PasswordPolicy::errors('corto1'));
        $this->assertNotSame([], PasswordPolicy::errors('solamenteletras'));
        $this->assertNotSame([], PasswordPolicy::errors('1234567890'));
        $this->assertNotSame([], PasswordPolicy::errors('Password1'));
        $this->assertNotSame([], PasswordPolicy::errors('mariaperez2026', 'mariaperez@barberapp.test'));
        $this->assertNotSame([], PasswordPolicy::errors(str_repeat('a1', 40)), 'Más de 72 bytes');
        $this->assertSame([], PasswordPolicy::errors('Ñandú2026'), 'Admite letras con tilde');
    }

    public function testRequestCookiesAndBearer(): void
    {
        $req = new Request('GET', '/', [], ['Cookie' => 'a=1; barberapp_session=abc%2Bdef; b=2', 'Authorization' => 'Bearer x.y.z']);
        $this->assertSame('abc+def', $req->cookie('barberapp_session'));
        $this->assertNull($req->cookie('nada'));
        $this->assertSame('x.y.z', $req->bearerToken());
        $this->assertNull((new Request('GET', '/', [], ['Authorization' => 'Basic dXNlcg==']))->bearerToken());
    }

    public function testResponseCookieFlags(): void
    {
        $res = (new Response())->withCookie('s', 'v', ['expires' => time() + 60, 'secure' => true, 'samesite' => 'strict']);
        $line = $res->cookies()['s'];
        foreach (['s=v', 'Path=/', 'Secure', 'HttpOnly', 'SameSite=Strict', 'Max-Age='] as $part) {
            $this->assertStringContainsString($part, $line);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->withCookie("bad\r\nname", 'v');
    }

    public function testSlugs(): void
    {
        $this->assertSame('barberia-el-nino-y-cia', Slug::from('  Barbería El Niño & Cía. '));
        $this->assertTrue(Slug::isValid('demo-centro'));
        $this->assertFalse(Slug::isValid('Demo'));
        $this->assertFalse(Slug::isValid('a'));
        $this->assertFalse(Slug::isValid('a--b'));
    }

    public function testTokensHelper(): void
    {
        $t = Tokens::random();
        $this->assertSame(43, strlen($t));
        $this->assertTrue(Tokens::looksValid($t));
        $this->assertFalse(Tokens::looksValid("' OR 1=1"));
        $this->assertSame(64, strlen(Tokens::hash($t)));
        $this->assertSame('hola', Tokens::base64urlDecode(Tokens::base64url('hola')));
    }

    public function testMailHeaderInjectionIsBlocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailMessage("a@b.test\r\nBcc: x@y.test", 'Asunto', 'Texto');
    }

    public function testMailMimeIsUtf8(): void
    {
        $mime = (new MailMessage('a@b.test', 'Contraseña', 'Hola', '<p>Hola</p>', 'Ñusta'))->toMime('no-reply@barberapp.pe', 'BARBERAPP');
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
        $this->assertStringContainsString('multipart/alternative', $mime);
    }

    public function testStringRuleMeasuresLengthOfNumericText(): void
    {
        $this->assertSame(['p' => '12345678'], Validator::validate(['p' => '12345678'], ['p' => 'required|string|max:200']));
        $this->expectException(ValidationException::class);
        Validator::validate(['p' => '123'], ['p' => 'required|string|min:5']);
    }
}
