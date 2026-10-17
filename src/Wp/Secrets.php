<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

/**
 * Encryption at rest for OAuth material (client key, tokens, DPoP keys), keyed
 * from the site's auth salt. A database copied to another site won't decrypt
 * there; that surfaces as "reconnect needed".
 */
final class Secrets
{
    private static function key(): string
    {
        return sodium_crypto_generichash(wp_salt('auth') . '|atproto-for-woocommerce', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    public static function decrypt(?string $value): ?string
    {
        if (!is_string($value) || !str_starts_with($value, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** @return array<string, mixed>|null */
    public static function getOption(string $name): ?array
    {
        $json = self::decrypt(get_option($name, null));
        $data = $json === null ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed>|null $data */
    public static function putOption(string $name, ?array $data): void
    {
        if ($data === null) {
            delete_option($name);
            return;
        }
        update_option($name, self::encrypt(wp_json_encode($data)), false);
    }
}
