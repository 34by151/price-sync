<?php
/**
 * Relationships management class
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Relationships {

    /**
     * Get all relationships
     */
    public static function get_all($orderby = 'slave_product_id', $order = 'ASC') {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        $allowed_orderby = array('slave_product_id', 'source_product_id', 'active');
        $allowed_order = array('ASC', 'DESC');

        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'slave_product_id';
        }

        if (!in_array($order, $allowed_order)) {
            $order = 'ASC';
        }

        // Build ORDER BY clause
        if ($orderby === 'active') {
            $order_clause = "ORDER BY active $order, slave_product_id $order, source_product_id $order";
        } elseif ($orderby === 'slave_product_id') {
            $order_clause = "ORDER BY slave_product_id $order, source_product_id $order";
        } else {
            $order_clause = "ORDER BY $orderby $order";
        }

        $query = "SELECT * FROM $table $order_clause";
        return $wpdb->get_results($query);
    }

    /**
     * Get relationships by slave product ID
     */
    public static function get_by_slave($slave_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE slave_product_id = %d ORDER BY source_product_id ASC",
            $slave_product_id
        ));
    }

    /**
     * Get relationships by source product ID
     */
    public static function get_by_source($source_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE source_product_id = %d",
            $source_product_id
        ));
    }

    /**
     * Add a new relationship
     */
    public static function add($slave_product_id, $source_product_id, $active = 0) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        // Validate products exist
        $slave_product = wc_get_product($slave_product_id);
        $source_product = wc_get_product($source_product_id);

        if (!$slave_product || !$source_product) {
            return new WP_Error('invalid_product', 'One or both products do not exist');
        }

        // Check if relationship already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE slave_product_id = %d AND source_product_id = %d",
            $slave_product_id,
            $source_product_id
        ));

        if ($existing) {
            return new WP_Error('duplicate_relationship', 'This relationship already exists');
        }

        // Check for circular dependency
        if (self::would_create_circular_dependency($slave_product_id, $source_product_id)) {
            return new WP_Error('circular_dependency', 'This relationship would create a circular dependency');
        }

        // Insert relationship
        $result = $wpdb->insert(
            $table,
            array(
                'slave_product_id' => $slave_product_id,
                'source_product_id' => $source_product_id,
                'active' => $active ? 1 : 0,
            ),
            array('%d', '%d', '%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to add relationship');
        }

        Price_Sync_Logger::log("Added relationship: Slave $slave_product_id -> Source $source_product_id");

        return $wpdb->insert_id;
    }

    /**
     * Update relationship active status
     */
    public static function update_active($id, $active) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        $result = $wpdb->update(
            $table,
            array('active' => $active ? 1 : 0),
            array('id' => $id),
            array('%d'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update relationship');
        }

        Price_Sync_Logger::log("Updated relationship $id active status to: " . ($active ? 'active' : 'inactive'));

        return true;
    }

    /**
     * Delete relationship
     */
    public static function delete($id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        // Get relationship info before deleting
        $relationship = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));

        if (!$relationship) {
            return new WP_Error('not_found', 'Relationship not found');
        }

        $result = $wpdb->delete(
            $table,
            array('id' => $id),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete relationship');
        }

        Price_Sync_Logger::log("Deleted relationship: Slave {$relationship->slave_product_id} -> Source {$relationship->source_product_id}");

        return true;
    }

    /**
     * Delete multiple relationships
     */
    public static function delete_multiple($ids) {
        if (empty($ids) || !is_array($ids)) {
            return new WP_Error('invalid_input', 'Invalid IDs provided');
        }

        $deleted = 0;
        $errors = array();

        foreach ($ids as $id) {
            $result = self::delete($id);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $deleted++;
            }
        }

        if (!empty($errors)) {
            return new WP_Error('partial_delete', 'Some relationships could not be deleted: ' . implode(', ', $errors));
        }

        return $deleted;
    }

    /**
     * Delete relationships by product ID (when product is deleted)
     */
    public static function delete_by_product($product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE slave_product_id = %d OR source_product_id = %d",
            $product_id,
            $product_id
        ));

        Price_Sync_Logger::log("Deleted all relationships for product $product_id");
    }

    /**
     * Check if adding a relationship would create a circular dependency
     */
    public static function would_create_circular_dependency($slave_product_id, $source_product_id) {
        // If source and slave are the same, it's circular
        if ($slave_product_id === $source_product_id) {
            return true;
        }

        // Check if source_product is a slave that eventually depends on slave_product
        return self::has_dependency_chain($source_product_id, $slave_product_id);
    }

    /**
     * Recursively check if there's a dependency chain
     */
    private static function has_dependency_chain($current_slave, $target_source, $visited = array()) {
        // Prevent infinite loops
        if (in_array($current_slave, $visited)) {
            return false;
        }

        $visited[] = $current_slave;

        // Get all relationships where current_slave is a slave
        $relationships = self::get_by_slave($current_slave);

        foreach ($relationships as $relationship) {
            // If we find the target source, there's a circular dependency
            if ($relationship->source_product_id == $target_source) {
                return true;
            }

            // Recursively check if this source has dependencies
            if (self::has_dependency_chain($relationship->source_product_id, $target_source, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all unique slave product IDs
     */
    public static function get_all_slave_product_ids() {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        return $wpdb->get_col("SELECT DISTINCT slave_product_id FROM $table ORDER BY slave_product_id ASC");
    }

    /**
     * Get source products used for a specific slave
     */
    public static function get_used_source_products($slave_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_relationships_table();

        return $wpdb->get_col($wpdb->prepare(
            "SELECT source_product_id FROM $table WHERE slave_product_id = %d",
            $slave_product_id
        ));
    }
}
