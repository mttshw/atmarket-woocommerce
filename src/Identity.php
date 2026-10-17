<?php
declare(strict_types=1);

namespace AtprotoWC;

use AtprotoWC\Http\HttpClient;

/** Handle and DID resolution: https://atproto.com/specs/handle, https://atproto.com/specs/did */
final class Identity
{
    private const HANDLE_RE = '/^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';
    private const DID_RE = '/^did:(plc:[a-z2-7]{24}|web:[a-zA-Z0-9.%-]+)$/';

    /** @var callable(string): array<int, array<string, mixed>> */
    private $dnsTxt;

    /** @param (callable(string): array<int, array<string, mixed>>)|null $dnsTxt for tests */
    public function __construct(private HttpClient $http, private string $plc = 'https://plc.directory', ?callable $dnsTxt = null)
    {
        $this->dnsTxt = $dnsTxt ?? static fn (string $name) => @dns_get_record($name, DNS_TXT) ?: [];
    }

    public static function isHandle(string $s): bool
    {
        return strlen($s) <= 253 && (bool) preg_match(self::HANDLE_RE, $s);
    }

    public static function isDid(string $s): bool
    {
        return (bool) preg_match(self::DID_RE, $s);
    }

    /** DNS TXT _atproto.<handle>, then https://<handle>/.well-known/atproto-did. */
    public function resolveHandle(string $handle): ?string
    {
        $handle = strtolower($handle);
        if (!self::isHandle($handle)) {
            return null;
        }
        $dids = [];
        foreach (($this->dnsTxt)('_atproto.' . $handle) as $record) {
            $txt = $record['txt'] ?? implode('', $record['entries'] ?? []);
            if (str_starts_with($txt, 'did=')) {
                $dids[] = substr($txt, 4);
            }
        }
        if (count($dids) === 1 && self::isDid($dids[0])) {
            return $dids[0];
        }
        try {
            $res = $this->http->request('GET', 'https://' . $handle . '/.well-known/atproto-did');
            $did = trim($res->body);
            return $res->ok() && self::isDid($did) ? $did : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function didDocument(string $did): array
    {
        if (!self::isDid($did)) {
            throw new \InvalidArgumentException('Not a supported DID: ' . $did);
        }
        $url = str_starts_with($did, 'did:plc:')
            ? $this->plc . '/' . $did
            : 'https://' . rawurldecode(substr($did, 8)) . '/.well-known/did.json';
        $res = $this->http->request('GET', $url);
        $doc = $res->json();
        if (!$res->ok() || ($doc['id'] ?? null) !== $did) {
            throw new \RuntimeException('Could not resolve ' . $did);
        }
        return $doc;
    }

    /** @param array<string, mixed> $doc */
    public static function pds(array $doc): ?string
    {
        foreach ($doc['service'] ?? [] as $s) {
            if (str_ends_with((string) ($s['id'] ?? ''), '#atproto_pds') && is_string($s['serviceEndpoint'] ?? null)) {
                return rtrim($s['serviceEndpoint'], '/');
            }
        }
        return null;
    }

    /** @param array<string, mixed> $doc */
    public static function handle(array $doc): ?string
    {
        foreach ($doc['alsoKnownAs'] ?? [] as $aka) {
            if (is_string($aka) && str_starts_with($aka, 'at://')) {
                return strtolower(substr($aka, 5));
            }
        }
        return null;
    }

    /**
     * Resolves a handle or DID to the account's DID, PDS and (verified) handle.
     * @return array{did: string, pds: string, handle: ?string}
     */
    public function resolve(string $input): array
    {
        $input = ltrim(trim($input), '@');
        $did = self::isDid($input) ? $input : $this->resolveHandle($input);
        if ($did === null) {
            throw new \RuntimeException("Couldn't find an account for “{$input}”.");
        }
        $doc = $this->didDocument($did);
        $pds = self::pds($doc);
        if ($pds === null) {
            throw new \RuntimeException("{$did} has no PDS.");
        }
        $handle = self::handle($doc);
        // A handle only counts if it resolves back to the same DID.
        if ($handle !== null && $handle !== strtolower($input) && $this->resolveHandle($handle) !== $did) {
            $handle = null;
        }
        return ['did' => $did, 'pds' => $pds, 'handle' => $handle];
    }
}
