<?php
declare(strict_types=1);

namespace AtprotoWC\OAuth;

use AtprotoWC\Crypto\EcKey;

/**
 * Who this OAuth client is. On a public https site it is a confidential client
 * (private_key_jwt with its own key), which gets long-lived sessions so the
 * hourly sync keeps working. On localhost it is an atproto loopback client.
 */
final class ClientConfig
{
    public const SCOPE = 'atproto repo:shop.lgtm.commerce.storefront repo:shop.lgtm.commerce.product repo:shop.lgtm.commerce.variant blob:image/* identity:handle';
    public const KEY_ID = 'atpwc-1';

    private function __construct(
        public string $clientId,
        public string $redirectUri,
        public string $scope,
        public ?EcKey $key,
        public ?string $siteUrl,
        public string $clientName,
    ) {
    }

    public static function forSite(string $siteUrl, string $clientName, ?EcKey $key): self
    {
        $site = rtrim($siteUrl, '/');
        if (self::isLoopback($site)) {
            // Loopback redirect URIs must use an IP literal, not "localhost".
            $redirect = preg_replace('#^http://localhost#', 'http://127.0.0.1', $site) . '/atproto/oauth/callback';
            $clientId = 'http://localhost?' . http_build_query(['redirect_uri' => $redirect, 'scope' => self::SCOPE], '', '&', PHP_QUERY_RFC3986);
            return new self($clientId, $redirect, self::SCOPE, null, null, $clientName);
        }
        if (!str_starts_with($site, 'https://')) {
            throw new \RuntimeException('Your site address must use https for AT Protocol sign-in.');
        }
        if ($key === null) {
            throw new \LogicException('A confidential client needs a key');
        }
        return new self($site . '/atproto/client-metadata.json', $site . '/atproto/oauth/callback', self::SCOPE, $key, $site, $clientName);
    }

    public static function isLoopback(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function confidential(): bool
    {
        return $this->key !== null;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_name' => $this->clientName,
            'client_uri' => $this->siteUrl,
            'redirect_uris' => [$this->redirectUri],
            'scope' => $this->scope,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'application_type' => 'web',
            'token_endpoint_auth_method' => $this->confidential() ? 'private_key_jwt' : 'none',
            ...($this->confidential() ? ['token_endpoint_auth_signing_alg' => 'ES256', 'jwks_uri' => $this->siteUrl . '/atproto/jwks.json'] : []),
            'dpop_bound_access_tokens' => true,
        ];
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        if (!$this->key) {
            return ['keys' => []];
        }
        return ['keys' => [$this->key->publicJwk() + ['kid' => self::KEY_ID, 'use' => 'sig', 'alg' => 'ES256']]];
    }
}
