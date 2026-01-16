<?php
/**
 * Product hooks - Handle product deletion and other events
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Product_Hooks {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook into product deletion
        add_action('before_delete_post', array($this, 'handle_product_deletion'), 10, 1);
        add_action('woocommerce_before_delete_product', array($this, 'handle_product_deletion'), 10, 1);

        // HPOS compatibility - handle order deletion
        add_action('woocommerce_delete_product', array($this, 'handle_product_deletion'), 10, 1);
    }

    /**
     * Handle product deletion
     */
    public function handle_product_deletion($product_id) {
        // Check if this is a product
        $post_type = get_post_type($product_id);
        if ($post_type !== 'product') {
            return;
        }

        Price_Sync_Logger::log("Product $product_id is being deleted, cleaning up relationships");

        // Delete all relationships involving this product
        Price_Sync_Relationships::delete_by_product($product_id);

        // Delete price entry if this product is a slave
        Price_Sync_Prices::delete_by_slave($product_id);

        // Rebuild prices table to update any affected slaves
        Price_Sync_Prices::rebuild_from_relationships();
    }
}
