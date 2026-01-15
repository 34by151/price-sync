<?php
/**
 * Database management class
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Database {

    /**
     * Create plugin database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Relationships table
        $relationships_table = $wpdb->prefix . 'price_sync_relationships';
        $relationships_sql = "CREATE TABLE IF NOT EXISTS $relationships_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slave_product_id bigint(20) UNSIGNED NOT NULL,
            source_product_id bigint(20) UNSIGNED NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_relationship (slave_product_id, source_product_id),
            KEY slave_product_id (slave_product_id),
            KEY source_product_id (source_product_id),
            KEY active (active)
        ) $charset_collate;";

        // Prices table
        $prices_table = $wpdb->prefix . 'price_sync_prices';
        $prices_sql = "CREATE TABLE IF NOT EXISTS $prices_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slave_product_id bigint(20) UNSIGNED NOT NULL,
            relationship_type varchar(20) NOT NULL,
            calculated_price decimal(10,2) NOT NULL DEFAULT 0.00,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slave_product_id (slave_product_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($relationships_sql);
        dbDelta($prices_sql);

        // Store database version
        update_option('price_sync_db_version', PRICE_SYNC_VERSION);
    }

    /**
     * Drop plugin tables (used for uninstall)
     */
    public static function drop_tables() {
        global $wpdb;

        $relationships_table = $wpdb->prefix . 'price_sync_relationships';
        $prices_table = $wpdb->prefix . 'price_sync_prices';

        $wpdb->query("DROP TABLE IF EXISTS $relationships_table");
        $wpdb->query("DROP TABLE IF EXISTS $prices_table");

        delete_option('price_sync_db_version');
    }

    /**
     * Get relationships table name
     */
    public static function get_relationships_table() {
        global $wpdb;
        return $wpdb->prefix . 'price_sync_relationships';
    }

    /**
     * Get prices table name
     */
    public static function get_prices_table() {
        global $wpdb;
        return $wpdb->prefix . 'price_sync_prices';
    }
}
