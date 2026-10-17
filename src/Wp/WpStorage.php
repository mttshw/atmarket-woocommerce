<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\OAuth\Session;
use AtprotoWC\OAuth\Storage;

/** OAuth storage: login state in transients, the session in an option, both encrypted. */
final class WpStorage implements Storage
{
    public const SESSION = 'atpwc_session';

    public function putState(string $state, array $data, int $ttl): void
    {
        set_transient('atpwc_state_' . md5($state), Secrets::encrypt(wp_json_encode($data)), $ttl);
    }

    public function takeState(string $state): ?array
    {
        $key = 'atpwc_state_' . md5($state);
        $value = get_transient($key);
        delete_transient($key);
        $json = Secrets::decrypt(is_string($value) ? $value : null);
        $data = $json === null ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function getSession(): ?Session
    {
        $data = Secrets::getOption(self::SESSION);
        try {
            return $data ? Session::fromArray($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function putSession(?Session $session): void
    {
        Secrets::putOption(self::SESSION, $session?->toArray());
    }
}
