<?php

declare(strict_types=1);

namespace App\Helpers;

/** Generación de slugs para enlaces públicos (/b/{slug}). */
final class Slug
{
    private const MAP = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c', '&' => ' y ', "'" => '',
    ];

    public static function from(string $text, int $max = 60): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if (strlen($text) > $max) {
            $text = rtrim(substr($text, 0, $max), '-');
        }
        return $text;
    }

    public static function isValid(string $slug): bool
    {
        return strlen($slug) >= 3 && strlen($slug) <= 80 && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}
