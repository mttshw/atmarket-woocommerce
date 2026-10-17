<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Crypto\Jwt;
use AtprotoWC\Http\Response;
use AtprotoWC\Identity;
use AtprotoWC\OAuth\ClientConfig;
use AtprotoWC\OAuth\OAuthClient;
use AtprotoWC\OAuth\OAuthException;
use AtprotoWC\OAuth\Session;
use AtprotoWC\OAuth\Storage;
use PHPUnit\Framework\TestCase;

final class MemoryStorage implements Storage
{
    public array $states = [];
    public ?Session $session = null;

    public function putState(string $state, array $data, int $ttl): void
    {
        $this->states[$state] = $data;
    }

    public function takeState(string $state): ?array
    {
        $data = $this->states[$state] ?? null;
        unset($this->states[$state]);
        return $data;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function putSession(?Session $session): void
    {
        $this->session = $session;
    }
}

final class OAuthTest extends TestCase
{
    private const DID = 'did:plc:shopaaaaaaaaaaaaaaaaaaaa';
    private const PDS = 'https://pds.example';
    private const AS = 'https://auth.example';

    private FakeHttp $http;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->storage = new MemoryStorage();
        $this->http
            ->on('GET', 'https://plc.directory/' . self::DID, fn () => FakeHttp::json([
                'id' => self::DID,
                'alsoKnownAs' => ['at://shop.example.com'],
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => self::PDS]],
            ]))
            ->on('GET', self::PDS . '/.well-known/oauth-protected-resource', fn () => FakeHttp::json(['authorization_servers' => [self::AS]]))
            ->on('GET', self::AS . '/.well-known/oauth-authorization-server', fn () => FakeHttp::json([
                'issuer' => self::AS,
                'authorization_endpoint' => self::AS . '/oauth/authorize',
                'token_endpoint' => self::AS . '/oauth/token',
                'pushed_authorization_request_endpoint' => self::AS . '/oauth/par',
                'revocation_endpoint' => self::AS . '/oauth/revoke',
                'dpop_signing_alg_values_supported' => ['ES256'],
            ]));
        // The server insists on a nonce the first time, like real atproto servers.
        $seen = false;
        $this->http->on('POST', self::AS . '/oauth/par', function () use (&$seen) {
            if (!$seen) {
                $seen = true;
                return FakeHttp::json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'n1']);
            }
            return FakeHttp::json(['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60], 201, ['DPoP-Nonce' => 'n2']);
        });
    }

    private function client(?EcKey $key = null, string $site = 'https://shop.example.com'): OAuthClient
    {
        $identity = new Identity($this->http, 'https://plc.directory', fn (string $name) => $name === '_atproto.shop.example.com' ? [['txt' => 'did=' . self::DID]] : []);
        return new OAuthClient($this->http, $identity, ClientConfig::forSite($site, 'Test Shop', $key ?? EcKey::generate()), $this->storage);
    }

    /** @return array<string, string> */
    private static function form(?string $body): array
    {
        parse_str((string) $body, $out);
        return $out;
    }

    private function tokenEndpoint(array $token): void
    {
        $this->http->on('POST', self::AS . '/oauth/token', fn () => FakeHttp::json($token + [
            'token_type' => 'DPoP', 'scope' => ClientConfig::SCOPE, 'sub' => self::DID, 'expires_in' => 3600,
        ], 200, ['DPoP-Nonce' => 'n3']));
    }

    public function testAuthorizeSendsAPushedRequestWithPkceDpopAndClientAuth(): void
    {
        $url = $this->client()->authorize('@shop.example.com');
        $this->assertSame(self::AS . '/oauth/authorize?client_id=' . urlencode('https://shop.example.com/atproto/client-metadata.json') . '&request_uri=' . urlencode('urn:ietf:params:oauth:request_uri:abc'), $url);

        $par = $this->http->to(self::AS . '/oauth/par');
        $this->assertCount(2, $par, 'retried once with the nonce');
        [, $first] = Jwt::decode($par[0]['headers']['DPoP']);
        [, $second] = Jwt::decode($par[1]['headers']['DPoP']);
        $this->assertArrayNotHasKey('nonce', $first);
        $this->assertSame('n1', $second['nonce']);

        $form = self::form($par[1]['body']);
        $this->assertSame('S256', $form['code_challenge_method']);
        $this->assertSame(ClientConfig::SCOPE, $form['scope']);
        $this->assertSame('shop.example.com', $form['login_hint']);
        $this->assertSame('https://shop.example.com/atproto/oauth/callback', $form['redirect_uri']);
        [$ah, $ap] = Jwt::decode($form['client_assertion']);
        $this->assertSame('atpwc-1', $ah['kid']);
        $this->assertSame(self::AS, $ap['aud']);
        $this->assertNotSame(self::form($par[0]['body'])['client_assertion'], $form['client_assertion'], 'fresh assertion per attempt');

        $saved = $this->storage->states[$form['state']];
        $this->assertSame('n2', $saved['nonce']);
        $this->assertSame(self::DID, $saved['did']);
    }

    public function testCallbackExchangesTheCodeAndChecksTheAccount(): void
    {
        $client = $this->client();
        $client->authorize('shop.example.com');
        $state = array_key_first($this->storage->states);
        $verifier = $this->storage->states[$state]['verifier'];
        $this->tokenEndpoint(['access_token' => 'at1', 'refresh_token' => 'rt1']);

        $session = $client->callback(['code' => 'c1', 'state' => $state, 'iss' => self::AS]);
        $this->assertSame(self::DID, $session->did);
        $this->assertSame('shop.example.com', $session->handle);
        $this->assertSame(self::PDS, $session->pds);
        $this->assertSame('at1', $session->accessToken);
        $this->assertSame('n3', $session->authServerNonce);
        $this->assertTrue($session->hasScope('identity:handle'));
        $form = self::form($this->http->to(self::AS . '/oauth/token')[0]['body']);
        $this->assertSame($verifier, $form['code_verifier']);
        $this->assertSame('authorization_code', $form['grant_type']);

        // State is single-use.
        $this->expectException(OAuthException::class);
        $client->callback(['code' => 'c1', 'state' => $state, 'iss' => self::AS]);
    }

    public function testCallbackRejectsAnotherIssuerOrAccount(): void
    {
        $client = $this->client();
        $client->authorize('shop.example.com');
        $state = array_key_first($this->storage->states);
        try {
            $client->callback(['code' => 'c', 'state' => $state, 'iss' => 'https://evil.example']);
            $this->fail('accepted a foreign issuer');
        } catch (OAuthException $e) {
            $this->assertStringContainsString('unexpected server', $e->getMessage());
        }

        $client->authorize('shop.example.com');
        $state = array_key_first($this->storage->states);
        $this->http->on('POST', self::AS . '/oauth/token', fn () => FakeHttp::json(['access_token' => 'x', 'token_type' => 'DPoP', 'sub' => 'did:plc:someoneelseaaaaaaaaaaaaa', 'scope' => 'atproto']));
        $this->expectExceptionMessage('different account');
        $client->callback(['code' => 'c', 'state' => $state, 'iss' => self::AS]);
    }

    public function testRefreshRotatesTokens(): void
    {
        $client = $this->client();
        $session = new Session(self::DID, null, self::PDS, self::AS, self::AS . '/oauth/token', null, 'old', 'rt-old', time() - 1, 'atproto', EcKey::generate()->pem());
        $this->tokenEndpoint(['access_token' => 'new', 'refresh_token' => 'rt-new']);
        $session = $client->refresh($session);
        $this->assertSame('new', $session->accessToken);
        $this->assertSame('rt-new', $session->refreshToken);
        $this->assertGreaterThan(time(), $session->expiresAt);
        $this->assertSame('rt-old', self::form($this->http->to(self::AS . '/oauth/token')[0]['body'])['refresh_token']);
    }

    public function testLoopbackClientForLocalhost(): void
    {
        $config = ClientConfig::forSite('http://localhost:8090', 'Test', null);
        $this->assertFalse($config->confidential());
        $this->assertSame('http://127.0.0.1:8090/atproto/oauth/callback', $config->redirectUri);
        $this->assertStringStartsWith('http://localhost?redirect_uri=http%3A%2F%2F127.0.0.1%3A8090', $config->clientId);
        $this->assertSame('none', $config->metadata()['token_endpoint_auth_method']);

        $this->expectExceptionMessage('https');
        ClientConfig::forSite('http://shop.example.com', 'Test', EcKey::generate());
    }

    public function testConfidentialMetadataPublishesOnlyThePublicKey(): void
    {
        $config = ClientConfig::forSite('https://shop.example.com/', 'Test Shop', EcKey::generate());
        $meta = $config->metadata();
        $this->assertSame('https://shop.example.com/atproto/client-metadata.json', $meta['client_id']);
        $this->assertSame('private_key_jwt', $meta['token_endpoint_auth_method']);
        $this->assertSame('https://shop.example.com/atproto/jwks.json', $meta['jwks_uri']);
        $jwk = $config->jwks()['keys'][0];
        $this->assertArrayNotHasKey('d', $jwk);
        $this->assertSame('atpwc-1', $jwk['kid']);
    }

    public function testUnresolvableHandle(): void
    {
        $this->expectExceptionMessage("Couldn't find an account");
        $this->client()->authorize('nobody.example.com');
    }
}
