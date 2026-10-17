<?php
declare(strict_types=1);

namespace AtprotoWC\Http;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response;
}
