<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\OAuth\ClientConfig;
use AtprotoWC\OAuth\OAuthException;

/**
 * Public paths atproto needs, matched before WordPress routes the request so
 * they work with any permalink structure the web server passes through:
 *
 *   /atproto/client-metadata.json  OAuth client metadata (https sites)
 *   /atproto/jwks.json             the client's public key
 *   /atproto/oauth/callback        where the account's server sends the owner back
 *   /.well-known/atproto-did       proves the shop's domain is the account's handle
 */
final class Routes
{
    public static function register(): void
    {
        add_filter('do_parse_request', [self::class, 'handle'], 1);
    }

    /** @param bool $parse */
    public static function handle($parse)
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $base = rtrim((string) parse_url(home_url(), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        switch ($path) {
            case '/atproto/client-metadata.json':
                self::json(self::confidentialConfig()?->metadata());
            case '/atproto/jwks.json':
                self::json(self::confidentialConfig()?->jwks());
            case '/atproto/oauth/callback':
                self::callback();
            case '/.well-known/atproto-did':
                // Only meaningful when the site is the domain root.
                $did = get_option(Plugin::OPT_SERVE_DID) === 'yes' && $base === '' ? (new WpStorage())->getSession()?->did : null;
                if ($did === null) {
                    return $parse;
                }
                nocache_headers();
                header('Content-Type: text/plain; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                echo $did;
                exit;
        }
        return $parse;
    }

    private static function confidentialConfig(): ?ClientConfig
    {
        try {
            $config = Plugin::config();
            return $config->confidential() ? $config : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $data */
    private static function json(?array $data): never
    {
        if ($data === null) {
            status_header(404);
            exit;
        }
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function callback(): never
    {
        // Loopback sign-in comes back to 127.0.0.1; return to the address the admin uses.
        $back = static fn (array $args) => wp_safe_redirect(add_query_arg($args, SettingsTab::url())) && exit;
        // The single-use state, created when an admin pressed Connect, is what
        // protects this. On a real site, also require that admin's login. On
        // localhost the browser returns to 127.0.0.1, which has no WordPress
        // cookie, so the state alone has to do.
        if (!ClientConfig::isLoopback(home_url()) && !current_user_can('manage_woocommerce')) {
            wp_safe_redirect(wp_login_url(SettingsTab::url()));
            exit;
        }
        try {
            $query = array_map('strval', wp_unslash($_GET));
            $session = Plugin::oauth()->callback($query);
            $storage = new WpStorage();
            $previous = $storage->getSession();
            if ($previous && $previous->did !== $session->did) {
                Plugin::oauth()->revoke($previous);
            }
            $storage->putSession($session);
            update_option(Plugin::OPT_ENABLED, 'yes');
            Plugin::syncSoon('connected');
            $back(['atpwc' => 'connected']);
        } catch (OAuthException $e) {
            $back(['atpwc_error' => rawurlencode($e->getMessage())]);
        } catch (\Throwable $e) {
            $back(['atpwc_error' => rawurlencode('Sign-in failed: ' . $e->getMessage())]);
        }
        exit;
    }
}
