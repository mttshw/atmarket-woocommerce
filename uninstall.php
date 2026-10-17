<?php
// Removes the plugin's tables and settings. Records already published stay on
// the shop's AT Protocol account; delete them there if wanted.
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/Wp/WpSyncState.php';
AtprotoWC\Wp\WpSyncState::dropTables();
foreach (['atpwc_enabled', 'atpwc_exclude_categories', 'atpwc_directory_url', 'atpwc_serve_did', 'atpwc_client_key', 'atpwc_runs', 'atpwc_session'] as $option) {
    delete_option($option);
}
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('atpwc_sync');
}
