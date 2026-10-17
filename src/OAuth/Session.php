<?php
declare(strict_types=1);

namespace AtprotoWC\OAuth;

/** A signed-in account: tokens, the DPoP key they are bound to, and where to use them. */
final class Session
{
    public function __construct(
        public string $did,
        public ?string $handle,
        public string $pds,
        public string $issuer,
        public string $tokenEndpoint,
        public ?string $revocationEndpoint,
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresAt,
        public string $scope,
        public string $dpopKeyPem,
        public ?string $authServerNonce = null,
        public ?string $pdsNonce = null,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, explode(' ', $this->scope), true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(...$a);
    }
}
