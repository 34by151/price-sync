<?php
/**
 * Admin interface class
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Admin {

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
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add admin menu under WooCommerce Products
     */
    public function add_admin_menu() {
        // Check user capability (administrators only)
        if (!current_user_can('manage_options')) {
            return;
        }

        add_submenu_page(
            'edit.php?post_type=product',
            __('Price Sync', 'price-sync'),
            __('Price Sync', 'price-sync'),
            'manage_options',
            'price-sync',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin page
        if ($hook !== 'product_page_price-sync') {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'price-sync-admin',
            PRICE_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PRICE_SYNC_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'price-sync-admin',
            PRICE_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            PRICE_SYNC_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('price-sync-admin', 'priceSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('price_sync_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete the selected relationships?', 'price-sync'),
                'confirmSync' => __('Are you sure you want to sync prices? This will update product prices.', 'price-sync'),
                'selectAtLeastOne' => __('Please select at least one relationship to delete.', 'price-sync'),
                'syncInProgress' => __('Syncing prices...', 'price-sync'),
                'syncComplete' => __('Sync completed successfully!', 'price-sync'),
                'syncError' => __('Sync failed. Please check the logs.', 'price-sync'),
                'saving' => __('Saving...', 'price-sync'),
                'addingRelationship' => __('Adding relationship...', 'price-sync'),
                'selectSlave' => __('Please select a slave product first.', 'price-sync'),
                'selectSource' => __('Please select a source product.', 'price-sync'),
            ),
        ));
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'price-sync'));
        }

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'prices';

        // Get sorting parameters
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';

        ?>
        <div class="wrap price-sync-admin">
            <h1><?php _e('Price Sync', 'price-sync'); ?></h1>

            <!-- Sync Prices Button -->
            <div class="price-sync-header">
                <button type="button" class="button button-primary button-large" id="sync-prices-btn">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Sync Prices', 'price-sync'); ?>
                </button>
                <div id="sync-status" class="price-sync-status"></div>
            </div>

            <!-- Settings Section -->
            <div class="price-sync-settings">
                <h2><?php _e('Cron Schedule', 'price-sync'); ?></h2>
                <?php $this->render_cron_settings(); ?>
            </div>

            <!-- Product Sync Section -->
            <div class="price-sync-section">
                <h2><?php _e('Product Sync', 'price-sync'); ?></h2>

                <!-- Tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="?post_type=product&page=price-sync&tab=prices"
                       class="nav-tab <?php echo $current_tab === 'prices' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Prices', 'price-sync'); ?>
                    </a>
                    <a href="?post_type=product&page=price-sync&tab=relationships"
                       class="nav-tab <?php echo $current_tab === 'relationships' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Relationships', 'price-sync'); ?>
                    </a>
                </h2>

                <!-- Tab Content -->
                <div class="tab-content">
                    <?php
                    if ($current_tab === 'prices') {
                        $this->render_prices_tab($orderby, $order);
                    } else {
                        $this->render_relationships_tab($orderby, $order);
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Prices tab
     */
    private function render_prices_tab($orderby, $order) {
        // Get prices
        $default_orderby = !empty($orderby) ? $orderby : 'slave_product_id';
        $prices = Price_Sync_Prices::get_all($default_orderby, $order);

        ?>
        <div class="price-sync-table-container">
            <table class="wp-list-table widefat fixed striped" id="prices-table">
                <thead>
                    <tr>
                        <th class="sortable <?php echo $orderby === 'slave_product_id' ? 'sorted ' . strtolower($order) : ''; ?>">
                            <a href="<?php echo $this->get_sort_url('prices', 'slave_product_id', $orderby, $order); ?>">
                                <span><?php _e('Slave', 'price-sync'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'relationship_type' ? 'sorted ' . strtolower($order) : ''; ?>">
                            <a href="<?php echo $this->get_sort_url('prices', 'relationship_type', $orderby, $order); ?>">
                                <span><?php _e('Relationship', 'price-sync'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th><?php _e('Price', 'price-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($prices)) : ?>
                        <tr>
                            <td colspan="3" class="no-items">
                                <?php _e('No price entries found. Add relationships to see calculated prices.', 'price-sync'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($prices as $price) : ?>
                            <?php
                            $product = wc_get_product($price->slave_product_id);
                            $product_name = $product ? $product->get_name() : __('Unknown Product', 'price-sync');
                            $relationship_display = $price->relationship_type === 'one_to_one' ? 'One:1' : 'Many:1';
                            ?>
                            <tr>
                                <td><?php echo esc_html($product_name); ?></td>
                                <td><?php echo esc_html($relationship_display); ?></td>
                                <td><?php echo wc_price($price->calculated_price); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render Relationships tab
     */
    private function render_relationships_tab($orderby, $order) {
        // Get relationships
        $default_orderby = !empty($orderby) ? $orderby : 'slave_product_id';
        $relationships = Price_Sync_Relationships::get_all($default_orderby, $order);

        ?>
        <div class="relationships-actions">
            <button type="button" class="button button-secondary" id="delete-relationships-btn">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Delete Selected', 'price-sync'); ?>
            </button>
            <button type="button" class="button button-secondary" id="add-relationship-btn">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Add New Relationship', 'price-sync'); ?>
            </button>
        </div>

        <!-- Add New Relationship Form (hidden by default) -->
        <div id="add-relationship-form" class="add-relationship-form" style="display: none;">
            <h3><?php _e('Add New Relationship', 'price-sync'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label for="slave-category-filter"><?php _e('Slave Category Filter', 'price-sync'); ?></label></th>
                    <td>
                        <select id="slave-category-filter" class="regular-text" style="width: 300px;">
                            <option value=""><?php _e('All Categories', 'price-sync'); ?></option>
                            <?php $this->render_category_options(); ?>
                        </select>
                        <p class="description"><?php _e('Filter slave products by category', 'price-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="new-slave-product"><?php _e('Slave Product', 'price-sync'); ?></label></th>
                    <td>
                        <select id="new-slave-product" class="regular-text" style="width: 300px;">
                            <option value=""><?php _e('Loading...', 'price-sync'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="source-category-filter"><?php _e('Source Category Filter', 'price-sync'); ?></label></th>
                    <td>
                        <select id="source-category-filter" class="regular-text" style="width: 300px;">
                            <option value=""><?php _e('All Categories', 'price-sync'); ?></option>
                            <?php $this->render_category_options(); ?>
                        </select>
                        <p class="description"><?php _e('Filter source products by category', 'price-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="new-source-product"><?php _e('Source Product', 'price-sync'); ?></label></th>
                    <td>
                        <select id="new-source-product" class="regular-text" style="width: 300px;" disabled>
                            <option value=""><?php _e('Select slave product first...', 'price-sync'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="new-active"><?php _e('Active', 'price-sync'); ?></label></th>
                    <td>
                        <input type="checkbox" id="new-active" value="1">
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="save-relationship-btn">
                    <?php _e('Save Relationship', 'price-sync'); ?>
                </button>
                <button type="button" class="button button-secondary" id="cancel-relationship-btn">
                    <?php _e('Cancel', 'price-sync'); ?>
                </button>
            </p>
        </div>

        <div class="price-sync-table-container">
            <table class="wp-list-table widefat fixed striped" id="relationships-table">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="select-all-relationships">
                        </th>
                        <th class="sortable <?php echo $orderby === 'slave_product_id' ? 'sorted ' . strtolower($order) : ''; ?>">
                            <a href="<?php echo $this->get_sort_url('relationships', 'slave_product_id', $orderby, $order); ?>">
                                <span><?php _e('Slave', 'price-sync'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'source_product_id' ? 'sorted ' . strtolower($order) : ''; ?>">
                            <a href="<?php echo $this->get_sort_url('relationships', 'source_product_id', $orderby, $order); ?>">
                                <span><?php _e('Source', 'price-sync'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'active' ? 'sorted ' . strtolower($order) : ''; ?>">
                            <a href="<?php echo $this->get_sort_url('relationships', 'active', $orderby, $order); ?>">
                                <span><?php _e('Active', 'price-sync'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($relationships)) : ?>
                        <tr>
                            <td colspan="4" class="no-items">
                                <?php _e('No relationships found. Click "Add New Relationship" to get started.', 'price-sync'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($relationships as $relationship) : ?>
                            <?php
                            $slave_product = wc_get_product($relationship->slave_product_id);
                            $source_product = wc_get_product($relationship->source_product_id);
                            $slave_name = $slave_product ? $slave_product->get_name() : __('Unknown', 'price-sync');
                            $source_name = $source_product ? $source_product->get_name() : __('Unknown', 'price-sync');
                            ?>
                            <tr data-relationship-id="<?php echo esc_attr($relationship->id); ?>">
                                <td class="check-column">
                                    <input type="checkbox" class="relationship-select" value="<?php echo esc_attr($relationship->id); ?>">
                                </td>
                                <td><?php echo esc_html($slave_name); ?></td>
                                <td><?php echo esc_html($source_name); ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="active-toggle"
                                           data-relationship-id="<?php echo esc_attr($relationship->id); ?>"
                                           <?php checked($relationship->active, 1); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render cron settings
     */
    private function render_cron_settings() {
        $current_schedule = Price_Sync_Cron::get_current_schedule();
        $next_run = Price_Sync_Cron::get_next_run();
        ?>
        <form method="post" action="" id="cron-settings-form">
            <table class="form-table">
                <tr>
                    <th><label for="cron-schedule"><?php _e('Schedule', 'price-sync'); ?></label></th>
                    <td>
                        <select id="cron-schedule" name="cron_schedule">
                            <option value="disabled" <?php selected($current_schedule, 'disabled'); ?>>
                                <?php _e('Disabled', 'price-sync'); ?>
                            </option>
                            <option value="daily" <?php selected($current_schedule, 'daily'); ?>>
                                <?php _e('Daily', 'price-sync'); ?>
                            </option>
                            <option value="weekly" <?php selected($current_schedule, 'weekly'); ?>>
                                <?php _e('Weekly', 'price-sync'); ?>
                            </option>
                            <option value="custom" <?php selected($current_schedule, 'custom'); ?>>
                                <?php _e('Custom (Daily at specific time)', 'price-sync'); ?>
                            </option>
                        </select>
                        <?php if ($next_run) : ?>
                            <p class="description">
                                <?php
                                printf(
                                    __('Next scheduled run: %s', 'price-sync'),
                                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run)
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="custom-time-row" style="<?php echo $current_schedule !== 'custom' ? 'display: none;' : ''; ?>">
                    <th><label for="cron-custom-time"><?php _e('Custom Time', 'price-sync'); ?></label></th>
                    <td>
                        <input type="time"
                               id="cron-custom-time"
                               name="cron_custom_time"
                               value="<?php echo esc_attr(get_option('price_sync_cron_custom_time', '02:00')); ?>">
                        <p class="description"><?php _e('Time to run the sync (24-hour format)', 'price-sync'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" class="button button-primary" id="save-cron-settings-btn">
                    <?php _e('Save Schedule', 'price-sync'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Render product options for dropdowns
     */
    private function render_product_options($exclude_ids = array()) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        );

        if (!empty($exclude_ids)) {
            $args['post__not_in'] = $exclude_ids;
        }

        $products = get_posts($args);

        foreach ($products as $product) {
            $product_obj = wc_get_product($product->ID);
            if ($product_obj) {
                echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product_obj->get_name()) . '</option>';
            }
        }
    }

    /**
     * Render category options for dropdowns
     */
    private function render_category_options() {
        $categories = $this->get_product_categories_with_paths();

        foreach ($categories as $category) {
            echo '<option value="' . esc_attr($category['id']) . '">' . esc_html($category['path']) . '</option>';
        }
    }

    /**
     * Get product categories with full paths
     */
    private function get_product_categories_with_paths() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));

        if (is_wp_error($categories) || empty($categories)) {
            return array();
        }

        $category_data = array();

        foreach ($categories as $category) {
            $path = $this->get_category_path($category->term_id);
            $category_data[] = array(
                'id' => $category->term_id,
                'path' => $path,
            );
        }

        // Sort alphanumerically by path
        usort($category_data, function($a, $b) {
            return strcasecmp($a['path'], $b['path']);
        });

        return $category_data;
    }

    /**
     * Get full category path (parent/child/grandchild)
     */
    private function get_category_path($term_id) {
        $path_parts = array();
        $current_term = get_term($term_id, 'product_cat');

        if (is_wp_error($current_term) || !$current_term) {
            return '';
        }

        // Build path from current term up to root
        while ($current_term && !is_wp_error($current_term)) {
            array_unshift($path_parts, $current_term->name);

            if ($current_term->parent == 0) {
                break;
            }

            $current_term = get_term($current_term->parent, 'product_cat');
        }

        return implode('/', $path_parts);
    }

    /**
     * Get sort URL
     */
    private function get_sort_url($tab, $column, $current_orderby, $current_order) {
        $new_order = 'ASC';
        if ($current_orderby === $column && $current_order === 'ASC') {
            $new_order = 'DESC';
        }

        return add_query_arg(array(
            'post_type' => 'product',
            'page' => 'price-sync',
            'tab' => $tab,
            'orderby' => $column,
            'order' => $new_order,
        ), admin_url('edit.php'));
    }
}
