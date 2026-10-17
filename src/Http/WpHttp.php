<?php
declare(strict_types=1);

namespace AtprotoWC\Http;

/** HttpClient on the WordPress HTTP API, so hosts' proxy and TLS settings apply. */
final class WpHttp implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $res = wp_safe_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 20,
            'redirection' => 0,
            'user-agent' => 'atproto-for-woocommerce/' . ATPWC_VERSION . '; ' . home_url('/'),
        ]);
        if (is_wp_error($res)) {
            throw new HttpException($method . ' ' . $url . ': ' . $res->get_error_message());
        }
        $headers = [];
        foreach (wp_remote_retrieve_headers($res) as $name => $value) {
            $headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return new Response((int) wp_remote_retrieve_response_code($res), $headers, (string) wp_remote_retrieve_body($res));
    }
}
