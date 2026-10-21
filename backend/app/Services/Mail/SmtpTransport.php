<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Cliente SMTP mínimo (RFC 5321) sin dependencias: EHLO, STARTTLS o SSL implícito,
 * AUTH LOGIN, MAIL FROM, RCPT TO, DATA. Verifica el certificado TLS del servidor.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    /** @param array<string, mixed> $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function send(MailMessage $message, string $from, string $fromName): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 587);
        $encryption = strtolower((string) ($this->cfg['encryption'] ?? 'tls'));
        $timeout = (int) ($this->cfg['timeout'] ?? 10);
        if ($host === '') {
            throw new RuntimeException('MAIL_HOST no configurado.');
        }

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar a SMTP {$host}:{$port} ({$errstr})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect(220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->command($ehlo, 250);
            if ($encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
                }
                $this->command($ehlo, 250);
            }
            $user = (string) ($this->cfg['username'] ?? '');
            if ($user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($user), 334);
                $this->command(base64_encode((string) ($this->cfg['password'] ?? '')), 235, true);
            }
            $this->command("MAIL FROM:<{$from}>", 250);
            $this->command("RCPT TO:<{$message->to}>", [250, 251]);
            $this->command('DATA', 354);
            // Dot-stuffing: las líneas que empiezan con "." se duplican.
            $data = preg_replace('/^\./m', '..', $message->toMime($from, $fromName));
            $this->command($data . "\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    /** @param int|list<int> $expected */
    private function command(string $line, $expected, bool $secret = false): string
    {
        fwrite($this->socket, $line . "\r\n");
        return $this->expect($expected, $secret ? '[credencial]' : strtok($line, "\r\n"));
    }

    /** @param int|list<int> $expected */
    private function expect($expected, string $context = 'saludo'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            throw new RuntimeException(sprintf('SMTP respondió %d a "%s": %s', $code, substr((string) $context, 0, 40), trim($response)));
        }
        return $response;
    }
}
