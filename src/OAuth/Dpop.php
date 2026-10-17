<?php
declare(strict_types=1);

namespace AtprotoWC\OAuth;

use AtprotoWC\Base64Url;
use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Crypto\Jwt;

/** DPoP proofs (RFC 9449): each request is signed by the key the tokens are bound to. */
final class Dpop
{
    public static function proof(EcKey $key, string $method, string $url, ?string $nonce = null, ?string $accessToken = null): string
    {
        $parts = parse_url($url);
        $htu = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        return Jwt::sign(['typ' => 'dpop+jwt', 'jwk' => $key->publicJwk()], array_filter([
            'jti' => Base64Url::encode(random_bytes(16)),
            'htm' => $method,
            'htu' => $htu,
            'iat' => time(),
            'nonce' => $nonce,
            'ath' => $accessToken !== null ? Base64Url::encode(hash('sha256', $accessToken, true)) : null,
        ], static fn ($v) => $v !== null), $key);
    }
}
