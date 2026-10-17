<?php
declare(strict_types=1);

namespace AtprotoWC\Crypto;

use AtprotoWC\Base64Url;

/**
 * A P-256 key for ES256 signatures: DPoP proofs, and the client assertion
 * when the plugin is a confidential OAuth client. Built on ext-openssl.
 */
final class EcKey
{
    private function __construct(private \OpenSSLAsymmetricKey $key, private string $pem)
    {
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Could not generate a P-256 key: ' . (openssl_error_string() ?: 'unknown error'));
        }
        return new self($key, $pem);
    }

    public static function fromPem(string $pem): self
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new \RuntimeException('Key is not P-256');
        }
        return new self($key, $pem);
    }

    public function pem(): string
    {
        return $this->pem;
    }

    /** @return array{kty: string, crv: string, x: string, y: string} */
    public function publicJwk(): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => Base64Url::encode(self::pad($ec['x'])),
            'y' => Base64Url::encode(self::pad($ec['y'])),
        ];
    }

    /** ES256 signature in JWS form: 64 raw bytes (r || s), not DER. */
    public function sign(string $input): string
    {
        if (!openssl_sign($input, $der, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed: ' . (openssl_error_string() ?: 'unknown error'));
        }
        return self::derToRaw($der);
    }

    /** Verifies a raw (r || s) signature. Used by tests and nothing else. */
    public function verify(string $input, string $raw): bool
    {
        $public = openssl_pkey_get_public(openssl_pkey_get_details($this->key)['key']);
        return openssl_verify($input, self::rawToDer($raw), $public, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function pad(string $bytes): string
    {
        return str_pad($bytes, 32, "\0", STR_PAD_LEFT);
    }

    /** DER SEQUENCE { INTEGER r, INTEGER s } to 32-byte r || 32-byte s. */
    public static function derToRaw(string $der): string
    {
        $pos = 0;
        if (ord($der[$pos++] ?? "\0") !== 0x30) {
            throw new \RuntimeException('Bad DER signature');
        }
        $len = ord($der[$pos++]);
        if ($len & 0x80) {
            $pos += $len & 0x7f; // long-form length; never needed for P-256, but skip it cleanly
        }
        $ints = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++] ?? "\0") !== 0x02) {
                throw new \RuntimeException('Bad DER signature');
            }
            $l = ord($der[$pos++]);
            $ints[] = ltrim(substr($der, $pos, $l), "\0");
            $pos += $l;
        }
        return self::pad($ints[0]) . self::pad($ints[1]);
    }

    public static function rawToDer(string $raw): string
    {
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || (ord($v[0]) & 0x80)) {
                $v = "\0" . $v;
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($body)) . $body;
    }
}
