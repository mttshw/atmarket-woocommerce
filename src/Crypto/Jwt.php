<?php
declare(strict_types=1);

namespace AtprotoWC\Crypto;

use AtprotoWC\Base64Url;

/** Compact JWS (ES256) for DPoP proofs and client assertions. */
final class Jwt
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    public static function sign(array $header, array $payload, EcKey $key): string
    {
        $input = self::part($header + ['alg' => 'ES256']) . '.' . self::part($payload);
        return $input . '.' . Base64Url::encode($key->sign($input));
    }

    /** @param array<string, mixed> $data */
    private static function part(array $data): string
    {
        return Base64Url::encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Splits a token into its decoded header and payload. For tests.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    public static function decode(string $jwt): array
    {
        [$h, $p, $s] = explode('.', $jwt);
        return [
            json_decode(Base64Url::decode($h), true, 512, JSON_THROW_ON_ERROR),
            json_decode(Base64Url::decode($p), true, 512, JSON_THROW_ON_ERROR),
            Base64Url::decode($s),
        ];
    }
}
