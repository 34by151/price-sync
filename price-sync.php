<?php
/**
 * Plugin Name: Price Sync for WooCommerce
 * Plugin URI: https://github.com/yourusername/price-sync
 * Description: Synchronize WooCommerce product prices with one-to-one and many-to-one relationships. HPOS compatible.
 * Version: 1.0.0
 * Author: Art In Metal
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: price-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 10.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('PRICE_SYNC_VERSION', '1.0.0');
define('PRICE_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRICE_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRICE_SYNC_PLUGIN_FILE', __FILE__);

/**
 * Main Price Sync Plugin Class
 */
class Price_Sync_Plugin {

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
        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Check if WooCommerce is active
        add_action('plugins_loaded', array($this, 'init'));

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load dependencies
        $this->load_dependencies();

        // Initialize components
        if (is_admin()) {
            Price_Sync_Admin::get_instance();
        }

        Price_Sync_AJAX::get_instance();
        Price_Sync_Cron::get_instance();
        Price_Sync_Product_Hooks::get_instance();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-database.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-relationships.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-prices.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-sync-engine.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-logger.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-product-hooks.php';
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-cron.php';

        if (is_admin()) {
            require_once PRICE_SYNC_PLUGIN_DIR . 'admin/class-price-sync-admin.php';
            require_once PRICE_SYNC_PLUGIN_DIR . 'admin/class-price-sync-ajax.php';
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-database.php';
        Price_Sync_Database::create_tables();

        // Set default options
        if (!get_option('price_sync_cron_schedule')) {
            update_option('price_sync_cron_schedule', 'disabled');
        }

        // Log activation
        require_once PRICE_SYNC_PLUGIN_DIR . 'includes/class-price-sync-logger.php';
        Price_Sync_Logger::log('Plugin activated');
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        $timestamp = wp_next_scheduled('price_sync_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'price_sync_cron_event');
        }

        // Log deactivation
        if (class_exists('Price_Sync_Logger')) {
            Price_Sync_Logger::log('Plugin deactivated');
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php esc_html_e('Price Sync requires WooCommerce to be installed and active.', 'price-sync'); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
Price_Sync_Plugin::get_instance();
