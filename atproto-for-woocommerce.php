<?php
/**
 * Plugin Name:       AT Protocol for WooCommerce
 * Description:       Publish your products to your own AT Protocol account, so open directories such as Atmarket can list them. Buyers still check out in your shop.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Atmarket
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       atproto-for-woocommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const ATPWC_VERSION = '0.1.0';
const ATPWC_FILE = __FILE__;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'AtprotoWC\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

register_activation_hook(__FILE__, [AtprotoWC\Wp\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [AtprotoWC\Wp\Plugin::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (class_exists('WooCommerce')) {
        AtprotoWC\Wp\Plugin::boot();
    }
});
