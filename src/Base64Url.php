<?php
declare(strict_types=1);

namespace AtprotoWC;

final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url');
        }
        return $decoded;
    }
}
