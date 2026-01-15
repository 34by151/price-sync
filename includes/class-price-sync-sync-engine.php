<?php
/**
 * Sync Engine - Handles price synchronization
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Sync_Engine {

    /**
     * Execute full price sync
     *
     * This is the main sync function called by the "Sync Prices" button
     * and the cron job
     */
    public static function execute_sync() {
        Price_Sync_Logger::log('=== Starting price sync ===');

        $results = array(
            'success' => true,
            'message' => '',
            'prices_updated' => 0,
            'products_synced' => 0,
            'errors' => array(),
        );

        try {
            // Step 1: Rebuild prices table from relationships
            Price_Sync_Logger::log('Step 1: Rebuilding prices table');
            $rebuild_results = Price_Sync_Prices::rebuild_from_relationships();
            $results['message'] .= sprintf(
                'Prices table: %d added, %d updated, %d removed. ',
                $rebuild_results['added'],
                $rebuild_results['updated'],
                $rebuild_results['removed']
            );

            // Step 2: Recalculate prices
            Price_Sync_Logger::log('Step 2: Recalculating prices');
            $prices_updated = Price_Sync_Prices::update_prices();
            $results['prices_updated'] = $prices_updated;
            $results['message'] .= sprintf('Recalculated %d prices. ', $prices_updated);

            // Step 3: Sync prices to products (only active relationships)
            Price_Sync_Logger::log('Step 3: Syncing prices to products');
            $sync_results = self::sync_prices_to_products();
            $results['products_synced'] = $sync_results['synced'];
            $results['message'] .= sprintf('Synced %d products.', $sync_results['synced']);

            if (!empty($sync_results['errors'])) {
                $results['errors'] = $sync_results['errors'];
            }

            Price_Sync_Logger::log('=== Price sync completed successfully ===');

        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Sync failed: ' . $e->getMessage();
            $results['errors'][] = $e->getMessage();
            Price_Sync_Logger::log('ERROR: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Sync calculated prices to actual products
     * Only syncs products with active relationships
     */
    private static function sync_prices_to_products() {
        $prices = Price_Sync_Prices::get_all();
        $synced = 0;
        $errors = array();

        foreach ($prices as $price_entry) {
            $slave_product_id = $price_entry->slave_product_id;

            // Check if this product has at least one active relationship
            $has_active = self::has_active_relationship($slave_product_id);

            if (!$has_active) {
                Price_Sync_Logger::log("Skipping product $slave_product_id (no active relationships)");
                continue;
            }

            // Get product using WooCommerce CRUD
            $product = wc_get_product($slave_product_id);

            if (!$product) {
                $error_msg = "Product $slave_product_id not found";
                $errors[] = $error_msg;
                Price_Sync_Logger::log("ERROR: $error_msg");
                continue;
            }

            // Get current regular price
            $current_price = $product->get_regular_price();
            $new_price = number_format($price_entry->calculated_price, 2, '.', '');

            // Only update if price has changed
            if ($current_price != $new_price) {
                try {
                    // Set regular price using WooCommerce CRUD
                    $product->set_regular_price($new_price);
                    $product->save();

                    $synced++;
                    Price_Sync_Logger::log("Updated product $slave_product_id: $current_price -> $new_price");
                } catch (Exception $e) {
                    $error_msg = "Failed to update product $slave_product_id: " . $e->getMessage();
                    $errors[] = $error_msg;
                    Price_Sync_Logger::log("ERROR: $error_msg");
                }
            } else {
                Price_Sync_Logger::log("Product $slave_product_id already at correct price: $new_price");
            }
        }

        return array(
            'synced' => $synced,
            'errors' => $errors,
        );
    }

    /**
     * Check if a slave product has at least one active relationship
     */
    private static function has_active_relationship($slave_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        $active_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE slave_product_id = %d AND active = 1",
            $slave_product_id
        ));

        return $active_count > 0;
    }

    /**
     * Sync a single product
     */
    public static function sync_single_product($slave_product_id) {
        Price_Sync_Logger::log("Syncing single product: $slave_product_id");

        // Get price entry
        $price_entry = Price_Sync_Prices::get_by_slave($slave_product_id);

        if (!$price_entry) {
            return new WP_Error('no_price', 'No price entry found for this product');
        }

        // Check if product has active relationships
        if (!self::has_active_relationship($slave_product_id)) {
            return new WP_Error('no_active', 'Product has no active relationships');
        }

        // Get product
        $product = wc_get_product($slave_product_id);

        if (!$product) {
            return new WP_Error('not_found', 'Product not found');
        }

        // Update price
        $new_price = number_format($price_entry->calculated_price, 2, '.', '');
        $product->set_regular_price($new_price);
        $product->save();

        Price_Sync_Logger::log("Single product sync completed: $slave_product_id -> $new_price");

        return true;
    }
}
