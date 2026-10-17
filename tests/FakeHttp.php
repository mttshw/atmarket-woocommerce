<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Http\HttpClient;
use AtprotoWC\Http\Response;

/** Routes requests to handlers by "METHOD url" prefix and records them. */
final class FakeHttp implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var array<string, callable> */
    private array $routes = [];

    public function on(string $method, string $urlPrefix, callable $handler): self
    {
        $this->routes[$method . ' ' . $urlPrefix] = $handler;
        return $this;
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['content-type' => 'application/json'], json_encode($data));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $req = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $this->requests[] = $req;
        $best = null;
        foreach ($this->routes as $key => $handler) {
            [$m, $prefix] = explode(' ', $key, 2);
            if ($m === $method && str_starts_with($url, $prefix) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $handler];
            }
        }
        if ($best === null) {
            return new Response(404, [], 'no route for ' . $method . ' ' . $url);
        }
        return ($best[1])($req);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function to(string $urlPrefix): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_starts_with($r['url'], $urlPrefix)));
    }
}
