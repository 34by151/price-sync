# Price Sync for WooCommerce

A WordPress plugin that synchronizes WooCommerce product prices with one-to-one and many-to-one relationships. Fully compatible with WooCommerce High-Performance Order Storage (HPOS).

## Features

- **One-to-One Relationships**: Sync the regular price from one product to another
- **Many-to-One Relationships**: Calculate prices as the sum of multiple source products
- **Active/Inactive Control**: Enable or disable price syncing for specific relationships
- **Manual Sync**: On-demand price synchronization via admin button
- **Scheduled Sync**: Automated price syncing via WordPress cron (daily, weekly, or custom schedule)
- **Circular Dependency Prevention**: Automatically prevents creation of circular relationships
- **Product Deletion Handling**: Automatically cleans up relationships when products are deleted
- **HPOS Compatible**: Uses WooCommerce CRUD methods for full HPOS compatibility
- **Error Logging**: Comprehensive logging using WooCommerce logger
- **Sortable Tables**: Sort prices and relationships by any column
- **Admin Only Access**: Restricted to administrators for security

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- WooCommerce 6.0 or higher

## Installation

1. Download or clone this repository
2. Upload the `price-sync` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Products > Price Sync** to configure

## Usage

### Accessing the Plugin

Navigate to **Products > Price Sync** in your WordPress admin menu.

### Understanding the Interface

The Price Sync page has three main sections:

1. **Sync Prices Button**: Manually trigger a complete price synchronization
2. **Cron Schedule Settings**: Configure automated price syncing
3. **Product Sync Tabs**: Manage relationships and view calculated prices

### Managing Relationships

#### Adding a New Relationship

1. Go to the **Relationships** tab
2. Click **Add New Relationship**
3. Select a **Slave Product** (the product whose price will be updated)
4. Select a **Source Product** (the product whose price will be used)
5. Check **Active** if you want this relationship to be active immediately
6. Click **Save Relationship**

**Notes:**
- A product cannot be its own source
- You cannot add the same source product twice for the same slave
- Circular dependencies are automatically prevented
- Slave products can also be used as source products for other slaves

#### Relationship Types

- **One-to-One**: When a slave product has only one source product, its price equals the source's regular price
- **Many-to-One**: When a slave product has multiple source products, its price is the sum of all source regular prices

#### Activating/Deactivating Relationships

- Check or uncheck the **Active** checkbox in the Relationships table
- Changes are saved immediately
- Only active relationships affect price synchronization

#### Deleting Relationships

1. Select one or more relationships using the checkboxes
2. Click **Delete Selected**
3. Confirm the deletion
4. The Prices table will automatically update

### Viewing Calculated Prices

The **Prices** tab shows:
- **Slave**: The product whose price will be synchronized
- **Relationship**: The type (One:1 or Many:1)
- **Price**: The calculated price based on source products

This table automatically updates when relationships are added or removed.

### Syncing Prices

#### Manual Sync

1. Click the **Sync Prices** button at the top of the page
2. Confirm the action
3. The process will:
   - Update the Prices table from Relationships
   - Recalculate all prices
   - Update product regular prices for slaves with active relationships

#### Scheduled Sync (Cron)

1. Choose a schedule from the **Cron Schedule** dropdown:
   - **Disabled**: No automatic syncing
   - **Daily**: Runs once per day at 2:00 AM
   - **Weekly**: Runs once per week
   - **Custom**: Runs daily at a specific time you choose

2. Click **Save Schedule**

The next scheduled run time will be displayed below the schedule selector.

## How Price Synchronization Works

### Step 1: Rebuild Prices Table
- Removes prices for products with no relationships
- Adds prices for new relationships
- Commits changes to database

### Step 2: Recalculate Prices
- For each slave product, sums the regular prices of all source products
- Updates the calculated price in the Prices table
- Commits changes to database

### Step 3: Sync to Products
- Only processes slaves with at least one **active** relationship
- Updates the regular price of each slave product using WooCommerce CRUD
- Leaves sale prices untouched
- Logs all changes

## Technical Details

### Database Tables

The plugin creates two custom tables:

#### wp_price_sync_relationships
- `id`: Primary key
- `slave_product_id`: Product whose price will be updated
- `source_product_id`: Product whose price will be used
- `active`: Whether this relationship is active (0 or 1)
- `created_at`: Timestamp
- `updated_at`: Timestamp

#### wp_price_sync_prices
- `id`: Primary key
- `slave_product_id`: Product (unique)
- `relationship_type`: 'one_to_one' or 'many_to_one'
- `calculated_price`: Sum of source prices
- `updated_at`: Timestamp

### Product Deletion

When a product is deleted:
1. All relationships involving that product are removed
2. If the product was a slave, its price entry is removed
3. If the product was a source, affected slaves have their prices recalculated

### Circular Dependency Prevention

The plugin prevents circular dependencies by checking if adding a relationship would create a circular chain:
- Product A cannot depend on Product B if Product B already depends on Product A
- Multi-level circular dependencies are also prevented (A→B→C→A)

### HPOS Compatibility

The plugin uses WooCommerce's CRUD methods exclusively:
- `wc_get_product()` for retrieving products
- `$product->get_regular_price()` for reading prices
- `$product->set_regular_price()` for updating prices
- `$product->save()` for persisting changes

### Logging

All operations are logged using WooCommerce's logger:
- View logs at **WooCommerce > Status > Logs**
- Look for logs with the source `price-sync`

## Hooks and Filters

The plugin hooks into:
- `before_delete_post`: Clean up relationships on product deletion
- `woocommerce_before_delete_product`: HPOS-compatible product deletion
- `woocommerce_delete_product`: Additional HPOS hook
- `price_sync_cron_event`: Custom cron event for scheduled syncs

## Uninstallation

When you delete the plugin:
1. All database tables are dropped
2. All plugin options are removed
3. Scheduled cron jobs are cancelled
4. Product prices remain at their last synced values

## Support

For issues, questions, or contributions, please open an issue on the GitHub repository.

## Changelog

### Version 1.0.0
- Initial release
- One-to-one and many-to-one relationship support
- Manual and scheduled price synchronization
- HPOS compatibility
- Circular dependency prevention
- Product deletion handling
- Comprehensive error logging

## License

GPL v2 or later

## Credits

Developed with ❤️ for WooCommerce