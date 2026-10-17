<?php
declare(strict_types=1);

namespace AtprotoWC\OAuth;

/** Where OAuth state lives: short-lived login state, and the one session. */
interface Storage
{
    /** @param array<string, mixed> $data */
    public function putState(string $state, array $data, int $ttl): void;

    /** Returns and deletes the state, so it can only be used once. @return array<string, mixed>|null */
    public function takeState(string $state): ?array;

    public function getSession(): ?Session;

    public function putSession(?Session $session): void;
}
