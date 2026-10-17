<?php
declare(strict_types=1);

namespace AtprotoWC\Repo;

use AtprotoWC\Http\Response;

final class XrpcException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 0, public ?string $error = null)
    {
        parent::__construct($message);
    }

    public static function from(string $nsid, Response $res): self
    {
        $body = $res->json();
        $error = is_string($body['error'] ?? null) ? $body['error'] : null;
        $message = is_string($body['message'] ?? null) ? $body['message'] : substr($res->body, 0, 200);
        return new self("{$nsid}: {$res->status}" . ($error ? " {$error}" : '') . ($message ? ": {$message}" : ''), $res->status, $error);
    }
}
