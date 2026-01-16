<?php
/**
 * AJAX handlers class
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_AJAX {

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
        // Register AJAX actions
        add_action('wp_ajax_price_sync_sync_prices', array($this, 'sync_prices'));
        add_action('wp_ajax_price_sync_add_relationship', array($this, 'add_relationship'));
        add_action('wp_ajax_price_sync_delete_relationships', array($this, 'delete_relationships'));
        add_action('wp_ajax_price_sync_toggle_active', array($this, 'toggle_active'));
        add_action('wp_ajax_price_sync_get_available_sources', array($this, 'get_available_sources'));
        add_action('wp_ajax_price_sync_get_products_by_category', array($this, 'get_products_by_category'));
        add_action('wp_ajax_price_sync_save_cron_settings', array($this, 'save_cron_settings'));
    }

    /**
     * Verify nonce and user capability
     */
    private function verify_request() {
        if (!check_ajax_referer('price_sync_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid security token', 'price-sync')));
            return false;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'price-sync')));
            return false;
        }

        return true;
    }

    /**
     * Sync prices - Main sync operation
     */
    public function sync_prices() {
        if (!$this->verify_request()) {
            return;
        }

        try {
            $results = Price_Sync_Sync_Engine::execute_sync();

            if ($results['success']) {
                wp_send_json_success(array(
                    'message' => $results['message'],
                    'products_synced' => $results['products_synced'],
                    'prices_updated' => $results['prices_updated'],
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $results['message'],
                    'errors' => $results['errors'],
                ));
            }
        } catch (Exception $e) {
            Price_Sync_Logger::error('Sync failed: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Sync failed: ', 'price-sync') . $e->getMessage(),
            ));
        }
    }

    /**
     * Add a new relationship
     */
    public function add_relationship() {
        if (!$this->verify_request()) {
            return;
        }

        $slave_product_id = isset($_POST['slave_product_id']) ? intval($_POST['slave_product_id']) : 0;
        $source_product_id = isset($_POST['source_product_id']) ? intval($_POST['source_product_id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;

        if (!$slave_product_id || !$source_product_id) {
            wp_send_json_error(array(
                'message' => __('Both slave and source products are required', 'price-sync'),
            ));
            return;
        }

        $result = Price_Sync_Relationships::add($slave_product_id, $source_product_id, $active);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
            ));
            return;
        }

        // Rebuild prices table after adding relationship
        Price_Sync_Prices::rebuild_from_relationships();

        wp_send_json_success(array(
            'message' => __('Relationship added successfully', 'price-sync'),
            'relationship_id' => $result,
        ));
    }

    /**
     * Delete multiple relationships
     */
    public function delete_relationships() {
        if (!$this->verify_request()) {
            return;
        }

        $relationship_ids = isset($_POST['relationship_ids']) ? $_POST['relationship_ids'] : array();

        if (empty($relationship_ids) || !is_array($relationship_ids)) {
            wp_send_json_error(array(
                'message' => __('No relationships selected', 'price-sync'),
            ));
            return;
        }

        // Sanitize IDs
        $relationship_ids = array_map('intval', $relationship_ids);

        // Delete relationships
        $result = Price_Sync_Relationships::delete_multiple($relationship_ids);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
            ));
            return;
        }

        // Rebuild prices table after deletion
        $rebuild_results = Price_Sync_Prices::rebuild_from_relationships();

        // Update prices
        Price_Sync_Prices::update_prices();

        wp_send_json_success(array(
            'message' => sprintf(__('Deleted %d relationship(s) successfully', 'price-sync'), $result),
            'deleted_count' => $result,
            'prices_updated' => $rebuild_results,
        ));
    }

    /**
     * Toggle active status of a relationship
     */
    public function toggle_active() {
        if (!$this->verify_request()) {
            return;
        }

        $relationship_id = isset($_POST['relationship_id']) ? intval($_POST['relationship_id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;

        if (!$relationship_id) {
            wp_send_json_error(array(
                'message' => __('Invalid relationship ID', 'price-sync'),
            ));
            return;
        }

        $result = Price_Sync_Relationships::update_active($relationship_id, $active);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => __('Active status updated', 'price-sync'),
        ));
    }

    /**
     * Get available source products for a slave
     */
    public function get_available_sources() {
        if (!$this->verify_request()) {
            return;
        }

        $slave_product_id = isset($_POST['slave_product_id']) ? intval($_POST['slave_product_id']) : 0;

        if (!$slave_product_id) {
            wp_send_json_error(array(
                'message' => __('Invalid slave product ID', 'price-sync'),
            ));
            return;
        }

        // Get all products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        );

        $products = get_posts($args);

        // Get slaves (products that cannot be sources)
        $all_slaves = Price_Sync_Relationships::get_all_slave_product_ids();

        // Get already used sources for this slave
        $used_sources = Price_Sync_Relationships::get_used_source_products($slave_product_id);

        // Build available sources list
        $available_sources = array();
        foreach ($products as $product) {
            $product_id = $product->ID;

            // Skip the slave itself
            if ($product_id == $slave_product_id) {
                continue;
            }

            // Skip if already used as source for this slave
            if (in_array($product_id, $used_sources)) {
                continue;
            }

            // Check for circular dependency
            if (Price_Sync_Relationships::would_create_circular_dependency($slave_product_id, $product_id)) {
                continue;
            }

            $product_obj = wc_get_product($product_id);
            if ($product_obj) {
                $available_sources[] = array(
                    'id' => $product_id,
                    'name' => $product_obj->get_name(),
                );
            }
        }

        wp_send_json_success(array(
            'sources' => $available_sources,
        ));
    }

    /**
     * Get products filtered by category
     */
    public function get_products_by_category() {
        if (!$this->verify_request()) {
            return;
        }

        try {
            $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
            $exclude_ids = isset($_POST['exclude_ids']) ? array_map('intval', $_POST['exclude_ids']) : array();
            $slave_product_id = isset($_POST['slave_product_id']) ? intval($_POST['slave_product_id']) : 0;

            // Get all products
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'post_status' => 'publish',
                'fields' => 'ids',
            );

            // Add exclusions if specified
            if (!empty($exclude_ids)) {
                $args['post__not_in'] = $exclude_ids;
            }

            $product_ids = get_posts($args);

            if (empty($product_ids)) {
                wp_send_json_success(array(
                    'products' => array(),
                ));
                return;
            }

            // If category filter is specified, filter products by category
            if ($category_id > 0) {
                $filtered_ids = array();
                foreach ($product_ids as $product_id) {
                    $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
                    if (is_wp_error($product_categories)) {
                        continue;
                    }
                    if (in_array($category_id, $product_categories)) {
                        $filtered_ids[] = $product_id;
                    }
                }
                $product_ids = $filtered_ids;
            }

            // If this is for source products, apply additional filtering
            $used_sources = array();
            if ($slave_product_id > 0) {
                $used_sources = Price_Sync_Relationships::get_used_source_products($slave_product_id);
            }

            // Build products array
            $products_data = array();
            foreach ($product_ids as $product_id) {
                // If filtering for source products, apply business logic
                if ($slave_product_id > 0) {
                    // Skip the slave itself
                    if ($product_id == $slave_product_id) {
                        continue;
                    }

                    // Skip if already used as source for this slave
                    if (in_array($product_id, $used_sources)) {
                        continue;
                    }

                    // Check for circular dependency
                    if (Price_Sync_Relationships::would_create_circular_dependency($slave_product_id, $product_id)) {
                        continue;
                    }
                }

                $product_obj = wc_get_product($product_id);
                if ($product_obj) {
                    $products_data[] = array(
                        'id' => $product_id,
                        'name' => $product_obj->get_name(),
                    );
                }
            }

            wp_send_json_success(array(
                'products' => $products_data,
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error loading products: ' . $e->getMessage(),
            ));
        }
    }

    /**
     * Save cron settings
     */
    public function save_cron_settings() {
        if (!$this->verify_request()) {
            return;
        }

        $schedule = isset($_POST['schedule']) ? sanitize_text_field($_POST['schedule']) : 'disabled';
        $custom_time = isset($_POST['custom_time']) ? sanitize_text_field($_POST['custom_time']) : '02:00';

        // Validate schedule
        $allowed_schedules = array('disabled', 'daily', 'weekly', 'custom');
        if (!in_array($schedule, $allowed_schedules)) {
            wp_send_json_error(array(
                'message' => __('Invalid schedule option', 'price-sync'),
            ));
            return;
        }

        // Save settings
        update_option('price_sync_cron_schedule', $schedule);
        if ($schedule === 'custom') {
            update_option('price_sync_cron_custom_time', $custom_time);
        }

        // Reschedule cron
        Price_Sync_Cron::schedule_event($schedule);

        $message = __('Cron schedule saved successfully', 'price-sync');
        if ($schedule !== 'disabled') {
            $next_run = Price_Sync_Cron::get_next_run();
            if ($next_run) {
                $message .= ' ' . sprintf(
                    __('Next run: %s', 'price-sync'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run)
                );
            }
        }

        wp_send_json_success(array(
            'message' => $message,
        ));
    }
}
