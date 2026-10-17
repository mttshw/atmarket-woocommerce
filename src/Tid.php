<?php
declare(strict_types=1);

namespace AtprotoWC;

/**
 * atproto record keys: 53-bit microsecond timestamp and 10-bit clock id as
 * 13 characters of sortable base32. https://atproto.com/specs/tid
 */
final class Tid
{
    private const CHARS = '234567abcdefghijklmnopqrstuvwxyz';
    private static int $last = 0;
    private static ?int $clock = null;

    public static function next(?int $nowMicros = null): string
    {
        self::$clock ??= random_int(0, 1023);
        $micros = max($nowMicros ?? (int) (microtime(true) * 1_000_000), self::$last + 1);
        self::$last = $micros;
        $n = ($micros << 10) | self::$clock;
        $out = '';
        for ($i = 0; $i < 13; $i++) {
            $out = self::CHARS[$n & 31] . $out;
            $n >>= 5;
        }
        return $out;
    }

    public static function isValid(string $s): bool
    {
        return (bool) preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $s);
    }
}
