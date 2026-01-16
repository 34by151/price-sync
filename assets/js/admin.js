/**
 * Price Sync Admin JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        PriceSyncAdmin.init();
    });

    /**
     * Main Admin Object
     */
    var PriceSyncAdmin = {

        /**
         * Initialize
         */
        init: function() {
            console.log('PriceSyncAdmin.init() called');
            console.log('priceSync object:', priceSync);
            this.bindEvents();
            this.initSelectAll();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            console.log('bindEvents() called');
            console.log('Add relationship button exists:', $('#add-relationship-btn').length);

            // Sync Prices button
            $('#sync-prices-btn').on('click', this.syncPrices.bind(this));

            // Add Relationship button
            $('#add-relationship-btn').on('click', this.showAddRelationshipForm.bind(this));
            console.log('Bound click handler to add-relationship-btn');
            $('#cancel-relationship-btn').on('click', this.hideAddRelationshipForm.bind(this));
            $('#save-relationship-btn').on('click', this.saveRelationship.bind(this));

            // Delete Relationships button
            $('#delete-relationships-btn').on('click', this.deleteRelationships.bind(this));

            // Active toggle checkboxes
            $('.active-toggle').on('change', this.toggleActive.bind(this));

            // Slave product selection change
            $('#new-slave-product').on('change', this.loadAvailableSources.bind(this));

            // Category filter changes
            $('#slave-category-filter').on('change', this.filterSlaveProducts.bind(this));
            $('#source-category-filter').on('change', this.filterSourceProducts.bind(this));

            // Save Cron Settings button
            $('#save-cron-settings-btn').on('click', this.saveCronSettings.bind(this));

            // Cron schedule change
            $('#cron-schedule').on('change', this.toggleCustomTimeRow.bind(this));
        },

        /**
         * Initialize select all functionality
         */
        initSelectAll: function() {
            $('#select-all-relationships').on('change', function() {
                $('.relationship-select').prop('checked', $(this).prop('checked'));
            });

            $('.relationship-select').on('change', function() {
                var allChecked = $('.relationship-select:checked').length === $('.relationship-select').length;
                $('#select-all-relationships').prop('checked', allChecked);
            });
        },

        /**
         * Sync Prices
         */
        syncPrices: function() {
            if (!confirm(priceSync.strings.confirmSync)) {
                return;
            }

            var $btn = $('#sync-prices-btn');
            var $status = $('#sync-status');

            // Disable button and show loading
            $btn.prop('disabled', true).addClass('syncing');
            $status.html(priceSync.strings.syncInProgress).removeClass('success error').addClass('info');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_sync_prices',
                    nonce: priceSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html(response.data.message).removeClass('info error').addClass('success');
                        // Reload page after 2 seconds to show updated prices
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.html(response.data.message).removeClass('info success').addClass('error');
                    }
                },
                error: function() {
                    $status.html(priceSync.strings.syncError).removeClass('info success').addClass('error');
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('syncing');
                }
            });
        },

        /**
         * Show Add Relationship Form
         */
        showAddRelationshipForm: function() {
            console.log('showAddRelationshipForm() called');
            $('#add-relationship-form').slideDown();
            // Ensure source category filter is always enabled
            $('#source-category-filter').prop('disabled', false);
            // Load all products when form opens (simulate "All Categories" selection)
            console.log('About to call filterSlaveProducts()');
            this.filterSlaveProducts();
            console.log('After calling filterSlaveProducts()');
            $('#new-slave-product').focus();
        },

        /**
         * Hide Add Relationship Form
         */
        hideAddRelationshipForm: function() {
            $('#add-relationship-form').slideUp();
            this.resetAddRelationshipForm();
        },

        /**
         * Reset Add Relationship Form
         */
        resetAddRelationshipForm: function() {
            $('#slave-category-filter').val('');
            $('#source-category-filter').val('').prop('disabled', false);
            $('#new-slave-product').val('');
            $('#new-source-product').val('').prop('disabled', true).html('<option value="">' + priceSync.strings.selectSlaveFirst + '</option>');
            $('#new-active').prop('checked', false);
        },

        /**
         * Load Available Sources for selected Slave
         */
        loadAvailableSources: function() {
            var slaveProductId = $('#new-slave-product').val();
            var $sourceSelect = $('#new-source-product');
            var $sourceCategoryFilter = $('#source-category-filter');

            if (!slaveProductId) {
                $sourceSelect.prop('disabled', true).html('<option value="">' + priceSync.strings.selectSlaveFirst + '</option>');
                return;
            }

            // Check if source category filter is set
            var sourceCategoryId = $sourceCategoryFilter.val();

            if (sourceCategoryId) {
                // Use category-filtered approach
                this.filterSourceProducts();
                return;
            }

            // Show loading
            $sourceSelect.prop('disabled', true).html('<option value="">Loading...</option>');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_get_available_sources',
                    nonce: priceSync.nonce,
                    slave_product_id: slaveProductId
                },
                success: function(response) {
                    if (response.success) {
                        var sources = response.data.sources;
                        var options = '<option value="">' + priceSync.strings.selectSource + '</option>';

                        if (sources.length === 0) {
                            options = '<option value="">No available source products</option>';
                        } else {
                            $.each(sources, function(index, source) {
                                options += '<option value="' + source.id + '">' + source.name + '</option>';
                            });
                        }

                        $sourceSelect.html(options).prop('disabled', sources.length === 0);
                    } else {
                        $sourceSelect.html('<option value="">Error loading sources</option>');
                    }
                },
                error: function() {
                    $sourceSelect.html('<option value="">Error loading sources</option>');
                }
            });
        },

        /**
         * Filter Slave Products by Category
         */
        filterSlaveProducts: function() {
            var categoryId = $('#slave-category-filter').val();
            var $slaveSelect = $('#new-slave-product');

            // Convert to integer, 0 if empty (for "All Categories")
            categoryId = categoryId ? parseInt(categoryId, 10) : 0;

            console.log('filterSlaveProducts called with categoryId:', categoryId);
            console.log('AJAX URL:', priceSync.ajaxUrl);
            console.log('Nonce:', priceSync.nonce);

            // Show loading
            $slaveSelect.html('<option value="">Loading...</option>');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_get_products_by_category',
                    nonce: priceSync.nonce,
                    category_id: categoryId
                },
                success: function(response) {
                    console.log('AJAX success response:', response);
                    if (response.success) {
                        var products = response.data.products;
                        var options = '<option value="">' + priceSync.strings.selectSlaveProduct + '</option>';

                        if (products.length === 0) {
                            options = '<option value="">No products in this category</option>';
                        } else {
                            $.each(products, function(index, product) {
                                options += '<option value="' + product.id + '">' + product.name + '</option>';
                            });
                        }

                        $slaveSelect.html(options);
                    } else {
                        console.error('AJAX returned error:', response.data);
                        $slaveSelect.html('<option value="">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</option>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    console.error('XHR:', xhr);
                    $slaveSelect.html('<option value="">Error loading products</option>');
                }
            });

            // Reset source product
            $('#new-source-product').val('').prop('disabled', true).html('<option value="">' + priceSync.strings.selectSlaveFirst + '</option>');
        },

        /**
         * Filter Source Products by Category
         */
        filterSourceProducts: function() {
            var categoryId = $('#source-category-filter').val();
            var slaveProductId = $('#new-slave-product').val();
            var $sourceSelect = $('#new-source-product');

            if (!slaveProductId) {
                alert(priceSync.strings.selectSlave);
                return;
            }

            // Convert to integer, 0 if empty (for "All Categories")
            categoryId = categoryId ? parseInt(categoryId, 10) : 0;
            slaveProductId = parseInt(slaveProductId, 10);

            // Show loading
            $sourceSelect.prop('disabled', true).html('<option value="">Loading...</option>');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_get_products_by_category',
                    nonce: priceSync.nonce,
                    category_id: categoryId,
                    slave_product_id: slaveProductId
                },
                success: function(response) {
                    if (response.success) {
                        var products = response.data.products;
                        var options = '<option value="">' + priceSync.strings.selectSourceProduct + '</option>';

                        if (products.length === 0) {
                            options = '<option value="">No products in this category</option>';
                        } else {
                            $.each(products, function(index, product) {
                                options += '<option value="' + product.id + '">' + product.name + '</option>';
                            });
                        }

                        $sourceSelect.html(options).prop('disabled', products.length === 0);
                    } else {
                        $sourceSelect.html('<option value="">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</option>');
                    }
                },
                error: function() {
                    $sourceSelect.html('<option value="">Error loading products</option>');
                }
            });
        },

        /**
         * Save Relationship
         */
        saveRelationship: function() {
            var slaveProductId = $('#new-slave-product').val();
            var sourceProductId = $('#new-source-product').val();
            var active = $('#new-active').prop('checked') ? 1 : 0;

            if (!slaveProductId) {
                alert(priceSync.strings.selectSlave);
                return;
            }

            if (!sourceProductId) {
                alert(priceSync.strings.selectSource);
                return;
            }

            var $btn = $('#save-relationship-btn');
            $btn.prop('disabled', true).text(priceSync.strings.saving);

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_add_relationship',
                    nonce: priceSync.nonce,
                    slave_product_id: slaveProductId,
                    source_product_id: sourceProductId,
                    active: active
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show new relationship
                        location.reload();
                    } else {
                        alert(response.data.message);
                        $btn.prop('disabled', false).text('Save Relationship');
                    }
                },
                error: function() {
                    alert('Error adding relationship');
                    $btn.prop('disabled', false).text('Save Relationship');
                }
            });
        },

        /**
         * Delete Relationships
         */
        deleteRelationships: function() {
            var selectedIds = [];
            $('.relationship-select:checked').each(function() {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length === 0) {
                alert(priceSync.strings.selectAtLeastOne);
                return;
            }

            if (!confirm(priceSync.strings.confirmDelete)) {
                return;
            }

            var $btn = $('#delete-relationships-btn');
            $btn.prop('disabled', true).addClass('loading');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_delete_relationships',
                    nonce: priceSync.nonce,
                    relationship_ids: selectedIds
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show updated tables
                        location.reload();
                    } else {
                        alert(response.data.message);
                        $btn.prop('disabled', false).removeClass('loading');
                    }
                },
                error: function() {
                    alert('Error deleting relationships');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            });
        },

        /**
         * Toggle Active Status
         */
        toggleActive: function(e) {
            var $checkbox = $(e.currentTarget);
            var relationshipId = $checkbox.data('relationship-id');
            var active = $checkbox.prop('checked') ? 1 : 0;

            // Disable checkbox during update
            $checkbox.prop('disabled', true);

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_toggle_active',
                    nonce: priceSync.nonce,
                    relationship_id: relationshipId,
                    active: active
                },
                success: function(response) {
                    if (!response.success) {
                        // Revert checkbox on error
                        $checkbox.prop('checked', !active);
                        alert(response.data.message);
                    }
                },
                error: function() {
                    // Revert checkbox on error
                    $checkbox.prop('checked', !active);
                    alert('Error updating active status');
                },
                complete: function() {
                    $checkbox.prop('disabled', false);
                }
            });
        },

        /**
         * Save Cron Settings
         */
        saveCronSettings: function() {
            var schedule = $('#cron-schedule').val();
            var customTime = $('#cron-custom-time').val();

            var $btn = $('#save-cron-settings-btn');
            $btn.prop('disabled', true).addClass('loading');

            $.ajax({
                url: priceSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'price_sync_save_cron_settings',
                    nonce: priceSync.nonce,
                    schedule: schedule,
                    custom_time: customTime
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        var $notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                        $('.price-sync-settings').prepend($notice);

                        // Auto-dismiss after 3 seconds
                        setTimeout(function() {
                            $notice.fadeOut(function() {
                                $(this).remove();
                            });
                        }, 3000);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('Error saving cron settings');
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('loading');
                }
            });
        },

        /**
         * Toggle Custom Time Row
         */
        toggleCustomTimeRow: function() {
            var schedule = $('#cron-schedule').val();
            if (schedule === 'custom') {
                $('#custom-time-row').slideDown();
            } else {
                $('#custom-time-row').slideUp();
            }
        }
    };

})(jQuery);
