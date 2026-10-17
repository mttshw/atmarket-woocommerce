<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Crypto\Jwt;
use AtprotoWC\Http\Response;
use AtprotoWC\Identity;
use AtprotoWC\OAuth\ClientConfig;
use AtprotoWC\OAuth\OAuthClient;
use AtprotoWC\OAuth\Session;
use AtprotoWC\Repo\XrpcException;
use AtprotoWC\Repo\XrpcRepo;
use PHPUnit\Framework\TestCase;

final class XrpcRepoTest extends TestCase
{
    private const DID = 'did:plc:shopaaaaaaaaaaaaaaaaaaaa';
    private const PDS = 'https://pds.example';

    private FakeHttp $http;
    private MemoryStorage $storage;
    private XrpcRepo $repo;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->storage = new MemoryStorage();
        $this->storage->session = new Session(self::DID, 'shop.example.com', self::PDS, 'https://auth.example', 'https://auth.example/oauth/token', null, 'at1', 'rt1', time() + 3600, 'atproto', EcKey::generate()->pem());
        $oauth = new OAuthClient($this->http, new Identity($this->http), ClientConfig::forSite('http://localhost:8090', 'Test', null), $this->storage);
        $this->repo = new XrpcRepo($this->http, $oauth, $this->storage);
        $this->http->on('POST', 'https://auth.example/oauth/token', fn () => FakeHttp::json(['access_token' => 'at2', 'refresh_token' => 'rt2', 'token_type' => 'DPoP', 'sub' => self::DID, 'expires_in' => 3600, 'scope' => 'atproto']));
    }

    public function testRetriesWithTheNonceThePdsAsksFor(): void
    {
        $calls = 0;
        $this->http->on('GET', self::PDS . '/xrpc/com.atproto.repo.describeRepo', function () use (&$calls) {
            return ++$calls === 1
                ? new Response(401, ['DPoP-Nonce' => 'pds-n1', 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"'], '{"error":"use_dpop_nonce"}')
                : FakeHttp::json(['handle' => 'shop.example.com']);
        });
        $this->assertSame('shop.example.com', $this->repo->describeRepo()['handle']);
        $reqs = $this->http->to(self::PDS);
        $this->assertCount(2, $reqs);
        [, $proof] = Jwt::decode($reqs[1]['headers']['DPoP']);
        $this->assertSame('pds-n1', $proof['nonce']);
        $this->assertSame('DPoP at1', $reqs[1]['headers']['Authorization']);
        $this->assertSame('pds-n1', $this->storage->session->pdsNonce, 'nonce is remembered');
    }

    public function testRefreshesAnExpiringSessionFirstAndSavesTheRotatedTokens(): void
    {
        $this->storage->session->expiresAt = time() + 10;
        $this->http->on('GET', self::PDS . '/xrpc/com.atproto.repo.describeRepo', fn () => FakeHttp::json([]));
        $this->repo->describeRepo();
        $this->assertSame('at2', $this->storage->session->accessToken);
        $this->assertSame('rt2', $this->storage->session->refreshToken);
        $this->assertSame('DPoP at2', $this->http->to(self::PDS)[0]['headers']['Authorization']);
    }

    public function testRefreshesOnceWhenTheTokenIsRejected(): void
    {
        $this->http->on('GET', self::PDS . '/xrpc/com.atproto.repo.describeRepo', fn ($req) => $req['headers']['Authorization'] === 'DPoP at1'
            ? new Response(401, ['WWW-Authenticate' => 'DPoP error="invalid_token"'], '{"error":"InvalidToken"}')
            : FakeHttp::json(['ok' => true]));
        $this->assertTrue($this->repo->describeRepo()['ok']);
        $this->assertSame('at2', $this->storage->session->accessToken);
    }

    public function testErrorsCarryTheXrpcErrorName(): void
    {
        $this->http->on('POST', self::PDS . '/xrpc/com.atproto.repo.applyWrites', fn () => FakeHttp::json(['error' => 'InvalidRequest', 'message' => 'Could not find blob'], 400));
        try {
            $this->repo->applyWrites([['action' => 'delete', 'collection' => 'shop.lgtm.commerce.product', 'rkey' => '3l2']]);
            $this->fail('no exception');
        } catch (XrpcException $e) {
            $this->assertSame('InvalidRequest', $e->error);
            $this->assertStringContainsString('Could not find blob', $e->getMessage());
        }
    }

    public function testApplyWritesSendsTypedWritesAndReturnsCids(): void
    {
        $this->http->on('POST', self::PDS . '/xrpc/com.atproto.repo.applyWrites', fn () => FakeHttp::json(['results' => [['uri' => 'at://x', 'cid' => 'bafy1'], ['$type' => 'com.atproto.repo.applyWrites#deleteResult']]]));
        $cids = $this->repo->applyWrites([
            ['action' => 'create', 'collection' => 'shop.lgtm.commerce.product', 'rkey' => '3l2', 'value' => ['name' => 'x/y']],
            ['action' => 'delete', 'collection' => 'shop.lgtm.commerce.variant', 'rkey' => '3l3'],
        ]);
        $this->assertSame(['bafy1', null], $cids);
        $body = json_decode($this->http->to(self::PDS)[0]['body'], true);
        $this->assertSame(self::DID, $body['repo']);
        $this->assertSame('com.atproto.repo.applyWrites#create', $body['writes'][0]['$type']);
        $this->assertArrayNotHasKey('value', $body['writes'][1]);
        $this->assertStringContainsString('"x/y"', $this->http->to(self::PDS)[0]['body'], 'slashes unescaped');
    }
}
