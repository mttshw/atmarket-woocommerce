<?php
declare(strict_types=1);

namespace AtprotoWC\Http;

final class Response
{
    /** @var array<string, string> lower-cased header names */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public int $status, array $headers, public string $body)
    {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
