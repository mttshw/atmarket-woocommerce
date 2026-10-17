<?php
declare(strict_types=1);

namespace AtprotoWC\OAuth;

use AtprotoWC\Base64Url;
use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Crypto\Jwt;
use AtprotoWC\Http\HttpClient;
use AtprotoWC\Http\Response;
use AtprotoWC\Identity;

/**
 * atproto OAuth (https://atproto.com/specs/oauth): PAR, PKCE, DPoP-bound
 * tokens, and private_key_jwt client auth when confidential.
 */
final class OAuthClient
{
    private const STATE_TTL = 600;

    public function __construct(
        private HttpClient $http,
        private Identity $identity,
        private ClientConfig $config,
        private Storage $storage,
    ) {
    }

    /** Starts sign-in for a handle or DID. Returns the URL to send the browser to. */
    public function authorize(string $handleOrDid): string
    {
        $account = $this->identity->resolve($handleOrDid);
        $as = $this->authServerFor($account['pds']);

        $verifier = Base64Url::encode(random_bytes(32));
        $state = Base64Url::encode(random_bytes(24));
        $dpopKey = EcKey::generate();
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => $this->config->scope,
            'state' => $state,
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'login_hint' => $account['handle'] ?? $account['did'],
        ];
        [$res, $nonce] = $this->postToAuthServer($as['pushed_authorization_request_endpoint'], $params, $as['issuer'], $dpopKey, null);
        $requestUri = $res->json()['request_uri'] ?? null;
        if (!$res->ok() || !is_string($requestUri)) {
            throw new OAuthException('The sign-in request was refused: ' . self::describe($res));
        }

        $this->storage->putState($state, [
            'verifier' => $verifier,
            'did' => $account['did'],
            'handle' => $account['handle'],
            'pds' => $account['pds'],
            'issuer' => $as['issuer'],
            'token_endpoint' => $as['token_endpoint'],
            'revocation_endpoint' => $as['revocation_endpoint'] ?? null,
            'dpop_key' => $dpopKey->pem(),
            'nonce' => $nonce,
        ], self::STATE_TTL);

        return $as['authorization_endpoint'] . '?' . http_build_query(['client_id' => $this->config->clientId, 'request_uri' => $requestUri]);
    }

    /**
     * Finishes sign-in from the callback's query parameters.
     * @param array<string, string> $query
     */
    public function callback(array $query): Session
    {
        if (isset($query['error'])) {
            throw new OAuthException($query['error_description'] ?? $query['error']);
        }
        $saved = isset($query['state']) ? $this->storage->takeState((string) $query['state']) : null;
        if ($saved === null) {
            throw new OAuthException('This sign-in link has expired or was already used. Please try again.');
        }
        // Mix-up defence: the response must come from the server we sent the user to.
        if (($query['iss'] ?? null) !== $saved['issuer']) {
            throw new OAuthException('The sign-in response came from an unexpected server.');
        }
        $dpopKey = EcKey::fromPem($saved['dpop_key']);
        [$res, $nonce] = $this->postToAuthServer($saved['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => (string) ($query['code'] ?? ''),
            'redirect_uri' => $this->config->redirectUri,
            'code_verifier' => $saved['verifier'],
            'client_id' => $this->config->clientId,
        ], $saved['issuer'], $dpopKey, $saved['nonce']);
        $token = $res->json();
        if (!$res->ok() || !isset($token['access_token'], $token['sub'])) {
            throw new OAuthException('Sign-in could not be completed: ' . self::describe($res));
        }
        if (($token['token_type'] ?? '') !== 'DPoP') {
            throw new OAuthException('The server issued a token this plugin cannot use.');
        }
        // The token is for the account we resolved, whose PDS trusts this issuer.
        if ($token['sub'] !== $saved['did']) {
            throw new OAuthException('Signed in as a different account than requested.');
        }
        $scope = (string) ($token['scope'] ?? '');
        if (!in_array('atproto', explode(' ', $scope), true)) {
            throw new OAuthException('The granted permissions are missing "atproto".');
        }
        return new Session(
            did: $saved['did'],
            handle: $saved['handle'],
            pds: $saved['pds'],
            issuer: $saved['issuer'],
            tokenEndpoint: $saved['token_endpoint'],
            revocationEndpoint: $saved['revocation_endpoint'],
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            expiresAt: time() + (int) ($token['expires_in'] ?? 300),
            scope: $scope,
            dpopKeyPem: $saved['dpop_key'],
            authServerNonce: $nonce,
        );
    }

    /** Swaps the refresh token for new tokens. Refresh tokens rotate, so the session must be saved. */
    public function refresh(Session $s): Session
    {
        if ($s->refreshToken === null) {
            throw new OAuthException('The session expired. Please reconnect.');
        }
        [$res, $nonce] = $this->postToAuthServer($s->tokenEndpoint, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $s->refreshToken,
            'client_id' => $this->config->clientId,
        ], $s->issuer, EcKey::fromPem($s->dpopKeyPem), $s->authServerNonce);
        $token = $res->json();
        if (!$res->ok() || !isset($token['access_token']) || ($token['sub'] ?? null) !== $s->did) {
            throw new OAuthException('The session could not be renewed: ' . self::describe($res));
        }
        $s->accessToken = $token['access_token'];
        $s->refreshToken = $token['refresh_token'] ?? $s->refreshToken;
        $s->expiresAt = time() + (int) ($token['expires_in'] ?? 300);
        $s->scope = (string) ($token['scope'] ?? $s->scope);
        $s->authServerNonce = $nonce;
        return $s;
    }

    public function revoke(Session $s): void
    {
        if (!$s->revocationEndpoint) {
            return;
        }
        try {
            foreach (array_filter([$s->refreshToken, $s->accessToken]) as $token) {
                $this->postToAuthServer($s->revocationEndpoint, ['token' => $token, 'client_id' => $this->config->clientId], $s->issuer, EcKey::fromPem($s->dpopKeyPem), $s->authServerNonce);
            }
        } catch (\Throwable) {
            // Best effort: the local session is forgotten either way.
        }
    }

    /**
     * The PDS names its authorization server; the server describes itself.
     * @return array<string, mixed>
     */
    public function authServerFor(string $pds): array
    {
        $resource = $this->http->request('GET', $pds . '/.well-known/oauth-protected-resource')->json();
        $issuer = $resource['authorization_servers'][0] ?? null;
        if (!is_string($issuer) || !str_starts_with($issuer, 'https://')) {
            throw new OAuthException("This account's server doesn't support AT Protocol sign-in.");
        }
        $meta = $this->http->request('GET', $issuer . '/.well-known/oauth-authorization-server')->json();
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'pushed_authorization_request_endpoint'] as $field) {
            if (!is_string($meta[$field] ?? null)) {
                throw new OAuthException("The account's sign-in server is missing {$field}.");
            }
        }
        if ($meta['issuer'] !== $issuer) {
            throw new OAuthException("The account's sign-in server describes itself inconsistently.");
        }
        if (!in_array('ES256', $meta['dpop_signing_alg_values_supported'] ?? [], true)) {
            throw new OAuthException("The account's sign-in server doesn't support ES256 DPoP.");
        }
        return $meta;
    }

    /**
     * POSTs a form to the authorization server with a DPoP proof and client
     * authentication, retrying once if the server asks for a fresh nonce.
     * @param array<string, string> $params
     * @return array{0: Response, 1: ?string} the response and the latest nonce
     */
    private function postToAuthServer(string $url, array $params, string $issuer, EcKey $dpopKey, ?string $nonce): array
    {
        for ($attempt = 0; ; $attempt++) {
            // Client assertions are single-use, so each attempt gets a fresh one.
            $body = $params + $this->clientAuth($issuer);
            $res = $this->http->request('POST', $url, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'DPoP' => Dpop::proof($dpopKey, 'POST', $url, $nonce),
            ], http_build_query($body));
            $nonce = $res->header('DPoP-Nonce') ?? $nonce;
            $wantsNonce = $res->status === 400 && ($res->json()['error'] ?? null) === 'use_dpop_nonce';
            if (!$wantsNonce || $attempt > 0) {
                return [$res, $nonce];
            }
        }
    }

    /** @return array<string, string> */
    private function clientAuth(string $issuer): array
    {
        if (!$this->config->confidential()) {
            return [];
        }
        $now = time();
        return [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => Jwt::sign(['kid' => ClientConfig::KEY_ID], [
                'iss' => $this->config->clientId,
                'sub' => $this->config->clientId,
                'aud' => $issuer,
                'jti' => Base64Url::encode(random_bytes(16)),
                'iat' => $now,
                'exp' => $now + 60,
            ], $this->config->key),
        ];
    }

    private static function describe(Response $res): string
    {
        $body = $res->json();
        return (string) ($body['error_description'] ?? $body['error'] ?? ('HTTP ' . $res->status));
    }
}
