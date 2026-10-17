<?php
declare(strict_types=1);

namespace AtprotoWC\Repo;

use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Http\HttpClient;
use AtprotoWC\Http\Response;
use AtprotoWC\OAuth\Dpop;
use AtprotoWC\OAuth\OAuthClient;
use AtprotoWC\OAuth\Session;
use AtprotoWC\OAuth\Storage;

/**
 * XRPC calls to the account's PDS with DPoP-bound tokens. Handles the PDS's
 * nonce challenges and refreshes the token when it expires, saving the
 * rotated session each time.
 */
final class XrpcRepo implements RepoClient
{
    private const WRITE_TYPES = [
        'create' => 'com.atproto.repo.applyWrites#create',
        'update' => 'com.atproto.repo.applyWrites#update',
        'delete' => 'com.atproto.repo.applyWrites#delete',
    ];

    public function __construct(private HttpClient $http, private OAuthClient $oauth, private Storage $storage)
    {
    }

    private function session(): Session
    {
        $s = $this->storage->getSession();
        if ($s === null) {
            throw new XrpcException('No account is connected.', 0, 'NotConnected');
        }
        if ($s->expiresAt < time() + 60) {
            $s = $this->oauth->refresh($s);
            $this->storage->putSession($s);
        }
        return $s;
    }

    public function did(): string
    {
        return $this->session()->did;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    public function call(string $method, string $nsid, array $query = [], ?string $body = null, string $contentType = 'application/json'): array
    {
        $s = $this->session();
        $url = $s->pds . '/xrpc/' . $nsid . ($query ? '?' . http_build_query($query) : '');
        $refreshed = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers = [
                'Authorization' => 'DPoP ' . $s->accessToken,
                'DPoP' => Dpop::proof(EcKey::fromPem($s->dpopKeyPem), $method, $url, $s->pdsNonce, $s->accessToken),
            ];
            if ($body !== null) {
                $headers['Content-Type'] = $contentType;
            }
            $res = $this->http->request($method, $url, $headers, $body);
            $nonce = $res->header('DPoP-Nonce');
            if ($nonce !== null && $nonce !== $s->pdsNonce) {
                $s->pdsNonce = $nonce;
                $this->storage->putSession($s);
            }
            if ($res->status === 401) {
                $challenge = (string) $res->header('WWW-Authenticate');
                if (str_contains($challenge, 'use_dpop_nonce') && $nonce !== null) {
                    continue;
                }
                if (str_contains($challenge, 'invalid_token') && !$refreshed) {
                    $s = $this->oauth->refresh($s);
                    $this->storage->putSession($s);
                    $refreshed = true;
                    continue;
                }
            }
            if (!$res->ok()) {
                throw XrpcException::from($nsid, $res);
            }
            return $res->json();
        }
        throw XrpcException::from($nsid, $res);
    }

    public function uploadBlob(string $bytes, string $mimeType): array
    {
        return $this->call('POST', 'com.atproto.repo.uploadBlob', [], $bytes, $mimeType)['blob'];
    }

    public function listRecords(string $collection): array
    {
        $out = [];
        $cursor = null;
        do {
            $page = $this->call('GET', 'com.atproto.repo.listRecords', array_filter([
                'repo' => $this->did(),
                'collection' => $collection,
                'limit' => '100',
                'cursor' => $cursor,
            ]));
            $records = $page['records'] ?? [];
            array_push($out, ...$records);
            $cursor = $records ? ($page['cursor'] ?? null) : null;
        } while ($cursor);
        return $out;
    }

    public function applyWrites(array $writes): array
    {
        $res = $this->call('POST', 'com.atproto.repo.applyWrites', [], json_encode([
            'repo' => $this->did(),
            'writes' => array_map(static fn ($w) => array_filter([
                '$type' => self::WRITE_TYPES[$w['action']],
                'collection' => $w['collection'],
                'rkey' => $w['rkey'],
                'value' => $w['value'] ?? null,
            ], static fn ($v) => $v !== null), $writes),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return array_map(static fn ($i) => $res['results'][$i]['cid'] ?? null, array_keys($writes));
    }

    /** @return array<string, mixed> */
    public function describeRepo(): array
    {
        return $this->call('GET', 'com.atproto.repo.describeRepo', ['repo' => $this->did()]);
    }

    public function updateHandle(string $handle): void
    {
        $this->call('POST', 'com.atproto.identity.updateHandle', [], json_encode(['handle' => $handle]));
    }
}
