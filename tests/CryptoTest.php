<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Base64Url;
use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Crypto\Jwt;
use AtprotoWC\OAuth\Dpop;
use AtprotoWC\Tid;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testSignaturesAreRaw64BytesAndVerify(): void
    {
        $key = EcKey::generate();
        // Enough runs to hit r or s values with leading zero bytes and high bits.
        for ($i = 0; $i < 200; $i++) {
            $sig = $key->sign("message $i");
            $this->assertSame(64, strlen($sig));
            $this->assertTrue($key->verify("message $i", $sig));
        }
        $this->assertFalse($key->verify('other message', $key->sign('message')));
    }

    public function testKeysRoundTripThroughPem(): void
    {
        $key = EcKey::generate();
        $again = EcKey::fromPem($key->pem());
        $this->assertSame($key->publicJwk(), $again->publicJwk());
        $jwk = $key->publicJwk();
        $this->assertSame(['kty', 'crv', 'x', 'y'], array_keys($jwk));
        $this->assertSame(32, strlen(Base64Url::decode($jwk['x'])));
    }

    public function testJwtHeaderPayloadAndSignature(): void
    {
        $key = EcKey::generate();
        $jwt = Jwt::sign(['typ' => 'test'], ['sub' => 'x', 'url' => 'https://a/b'], $key);
        [$h, $p, $sig] = Jwt::decode($jwt);
        $this->assertSame(['typ' => 'test', 'alg' => 'ES256'], $h);
        $this->assertSame('https://a/b', $p['url']);
        $this->assertStringNotContainsString('\/', explode('.', $jwt)[1]);
        $this->assertTrue($key->verify(substr($jwt, 0, strrpos($jwt, '.')), $sig));
    }

    public function testDpopProofShape(): void
    {
        $key = EcKey::generate();
        [$h, $p] = Jwt::decode(Dpop::proof($key, 'POST', 'https://pds.example/xrpc/x?repo=a#frag', 'nonce-1', 'token-abc'));
        $this->assertSame('dpop+jwt', $h['typ']);
        $this->assertSame($key->publicJwk(), $h['jwk']);
        $this->assertSame('https://pds.example/xrpc/x', $p['htu'], 'no query or fragment');
        $this->assertSame('POST', $p['htm']);
        $this->assertSame('nonce-1', $p['nonce']);
        $this->assertSame(Base64Url::encode(hash('sha256', 'token-abc', true)), $p['ath']);
        [, $p2] = Jwt::decode(Dpop::proof($key, 'GET', 'https://pds.example/'));
        $this->assertArrayNotHasKey('nonce', $p2);
        $this->assertArrayNotHasKey('ath', $p2);
        $this->assertNotSame($p['jti'], $p2['jti']);
    }

    public function testTidsAreValidAndIncreasing(): void
    {
        $a = Tid::next();
        $b = Tid::next();
        $this->assertTrue(Tid::isValid($a));
        $this->assertTrue(Tid::isValid($b));
        $this->assertGreaterThan($a, $b);
        $this->assertSame(13, strlen($a));
    }
}
