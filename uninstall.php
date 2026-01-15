<?php
/**
 * Uninstall script
 *
 * This file is executed when the plugin is uninstalled (deleted) from WordPress
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load plugin file to get database class
require_once plugin_dir_path(__FILE__) . 'includes/class-price-sync-database.php';

// Drop database tables
Price_Sync_Database::drop_tables();

// Delete plugin options
delete_option('price_sync_db_version');
delete_option('price_sync_cron_schedule');
delete_option('price_sync_cron_custom_time');

// Clear scheduled events
$timestamp = wp_next_scheduled('price_sync_cron_event');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'price_sync_cron_event');
}
