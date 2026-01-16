<?php
/**
 * Prices management class
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Prices {

    /**
     * Get all prices
     */
    public static function get_all($orderby = 'slave_product_id', $order = 'ASC') {
        global $wpdb;
        $table = Price_Sync_Database::get_prices_table();

        $allowed_orderby = array('slave_product_id', 'relationship_type', 'calculated_price');
        $allowed_order = array('ASC', 'DESC');

        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'slave_product_id';
        }

        if (!in_array($order, $allowed_order)) {
            $order = 'ASC';
        }

        $query = "SELECT * FROM $table ORDER BY $orderby $order";
        return $wpdb->get_results($query);
    }

    /**
     * Get price by slave product ID
     */
    public static function get_by_slave($slave_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_prices_table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slave_product_id = %d",
            $slave_product_id
        ));
    }

    /**
     * Update or insert a price entry
     */
    public static function update_or_insert($slave_product_id, $relationship_type, $calculated_price) {
        global $wpdb;
        $table = Price_Sync_Database::get_prices_table();

        // Check if entry exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE slave_product_id = %d",
            $slave_product_id
        ));

        if ($existing) {
            // Update existing entry
            $result = $wpdb->update(
                $table,
                array(
                    'relationship_type' => $relationship_type,
                    'calculated_price' => $calculated_price,
                ),
                array('slave_product_id' => $slave_product_id),
                array('%s', '%f'),
                array('%d')
            );

            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update price');
            }

            return $existing;
        } else {
            // Insert new entry
            $result = $wpdb->insert(
                $table,
                array(
                    'slave_product_id' => $slave_product_id,
                    'relationship_type' => $relationship_type,
                    'calculated_price' => $calculated_price,
                ),
                array('%d', '%s', '%f')
            );

            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert price');
            }

            return $wpdb->insert_id;
        }
    }

    /**
     * Delete price entry by slave product ID
     */
    public static function delete_by_slave($slave_product_id) {
        global $wpdb;
        $table = Price_Sync_Database::get_prices_table();

        return $wpdb->delete(
            $table,
            array('slave_product_id' => $slave_product_id),
            array('%d')
        );
    }

    /**
     * Rebuild prices table from relationships
     */
    public static function rebuild_from_relationships() {
        global $wpdb;
        $prices_table = Price_Sync_Database::get_prices_table();
        $relationships_table = Price_Sync_Database::get_relationships_table();

        // Get all unique slave products from relationships
        $slave_product_ids = Price_Sync_Relationships::get_all_slave_product_ids();

        // Get all existing prices
        $existing_prices = $wpdb->get_col("SELECT slave_product_id FROM $prices_table");

        $updated_count = 0;
        $added_count = 0;
        $removed_count = 0;

        // Remove prices that no longer have relationships
        foreach ($existing_prices as $existing_slave_id) {
            if (!in_array($existing_slave_id, $slave_product_ids)) {
                self::delete_by_slave($existing_slave_id);
                $removed_count++;
                Price_Sync_Logger::log("Removed price entry for product $existing_slave_id (no relationship)");
            }
        }

        // Add or update prices for each slave product
        foreach ($slave_product_ids as $slave_product_id) {
            $relationships = Price_Sync_Relationships::get_by_slave($slave_product_id);
            $relationship_count = count($relationships);

            if ($relationship_count === 0) {
                continue;
            }

            // Determine relationship type
            $relationship_type = $relationship_count === 1 ? 'one_to_one' : 'many_to_one';

            // Calculate price
            $calculated_price = 0;
            foreach ($relationships as $relationship) {
                $source_product = wc_get_product($relationship->source_product_id);
                if ($source_product) {
                    $regular_price = $source_product->get_regular_price();
                    if ($regular_price) {
                        $calculated_price += floatval($regular_price);
                    }
                }
            }

            // Check if this is an update or insert
            $was_existing = in_array($slave_product_id, $existing_prices);

            // Update or insert price
            $result = self::update_or_insert($slave_product_id, $relationship_type, $calculated_price);

            if (!is_wp_error($result)) {
                if ($was_existing) {
                    $updated_count++;
                } else {
                    $added_count++;
                }
            }
        }

        Price_Sync_Logger::log("Prices table rebuilt: $added_count added, $updated_count updated, $removed_count removed");

        return array(
            'added' => $added_count,
            'updated' => $updated_count,
            'removed' => $removed_count,
        );
    }

    /**
     * Update prices (recalculate from source products)
     */
    public static function update_prices() {
        $prices = self::get_all();
        $updated_count = 0;

        foreach ($prices as $price_entry) {
            $slave_product_id = $price_entry->slave_product_id;
            $relationships = Price_Sync_Relationships::get_by_slave($slave_product_id);

            if (empty($relationships)) {
                continue;
            }

            // Recalculate price
            $calculated_price = 0;
            foreach ($relationships as $relationship) {
                $source_product = wc_get_product($relationship->source_product_id);
                if ($source_product) {
                    $regular_price = $source_product->get_regular_price();
                    if ($regular_price) {
                        $calculated_price += floatval($regular_price);
                    }
                }
            }

            // Update if changed
            if (abs($calculated_price - $price_entry->calculated_price) > 0.001) {
                $relationship_type = count($relationships) === 1 ? 'one_to_one' : 'many_to_one';
                self::update_or_insert($slave_product_id, $relationship_type, $calculated_price);
                $updated_count++;
            }
        }

        Price_Sync_Logger::log("Updated $updated_count prices");

        return $updated_count;
    }
}
