<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** Correo saliente: texto plano obligatorio y HTML opcional. */
final class MailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public ?string $toName = null
    ) {
        foreach ([$to, $subject, (string) $toName] as $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('Cabecera de correo inválida.');
            }
        }
    }

    /** Mensaje MIME completo (cabeceras + cuerpo) listo para SMTP o para guardarse como .eml. */
    public function toMime(string $fromAddress, string $fromName): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr((string) strrchr($fromAddress, '@'), 1) ?: 'barberapp.pe';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeName($fromName) . " <{$fromAddress}>",
            'To: ' . ($this->toName ? self::encodeName($this->toName) . ' ' : '') . "<{$this->to}>",
            'Subject: ' . self::encodeName($this->subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];

        if ($this->html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($this->text), 76, "\r\n");
        }

        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->text), 76, "\r\n")
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->html), 76, "\r\n")
            . "--{$boundary}--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeName(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
