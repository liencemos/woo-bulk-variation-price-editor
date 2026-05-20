<?php
if (! defined('ABSPATH')) {
    exit;
}

class WBV_Admin
{
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function register_settings()
    {
        register_setting('wbv_pricer', 'wbv_chunk_size', array('sanitize_callback' => 'absint', 'default' => 100));
        register_setting('wbv_pricer', 'wbv_use_action_scheduler', array('sanitize_callback' => 'boolval', 'default' => true));
    }

    public static function register_admin_page()
    {
        // Submenu under Products - Price Editor
        add_submenu_page(
            'edit.php?post_type=product',
            __('Bulk Variation Price Editor', 'woo-bulk-variation-pricer'),
            __('Bulk Variation Price Editor', 'woo-bulk-variation-pricer'),
            'manage_woocommerce',
            'wbv-pricer',
            array(__CLASS__, 'render_admin_page')
        );

        // Submenu under Products - Default Attributes
        add_submenu_page(
            'edit.php?post_type=product',
            __('Bulk Default Attributes', 'woo-bulk-variation-pricer'),
            __('Bulk Default Attributes', 'woo-bulk-variation-pricer'),
            'manage_woocommerce',
            'wbv-defaults',
            array(__CLASS__, 'render_defaults_page')
        );
    }

    public static function render_admin_page()
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html__('Bulk Variation Price Editor', 'woo-bulk-variation-pricer'); ?></h1>
            <p><?php echo esc_html__('Search products by title or SKU and manage variation prices in bulk.', 'woo-bulk-variation-pricer'); ?></p>
            <?php if (get_option('wbv_use_action_scheduler', true) && ! (function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action'))) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('ActionScheduler is not available. Background processing of large jobs will be disabled. Ensure WooCommerce (which includes ActionScheduler) is active or disable background processing in the settings.', 'woo-bulk-variation-pricer'); ?></p>
                </div>
            <?php endif; ?>

            <div id="wbv-app">
                <label style="margin-right:1rem;"><input id="wbv-select-all-visible" type="checkbox" /> <?php echo esc_html__('Select all visible', 'woo-bulk-variation-pricer'); ?></label>
                <input id="wbv-search" type="search" placeholder="<?php echo esc_attr__('Search products or SKU', 'woo-bulk-variation-pricer'); ?>" style="width:24%;" />
                <select id="wbv-attribute" multiple style="margin-left:.5rem; min-width:160px; max-width:220px;" size="3">
                    <option value="" disabled><?php echo esc_html__('All attributes', 'woo-bulk-variation-pricer'); ?></option>
                </select>
                <select id="wbv-attribute-value" multiple style="margin-left:.25rem; min-width:220px; max-width:320px;" disabled size="3">
                    <option value="" disabled><?php echo esc_html__('All values', 'woo-bulk-variation-pricer'); ?></option>
                </select>
                <input id="wbv-attribute-value-text" type="text" placeholder="<?php echo esc_attr__('Or type value text to match term names (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; min-width:220px; max-width:320px;" disabled />
                <div id="wbv-attribute-error" role="status" aria-live="polite" style="display:inline-block; margin-left:.5rem;"></div>
                <select id="wbv-attribute-op" style="margin-left:.25rem;">
                    <option value="and"><?php echo esc_html__('Match all attributes (AND)', 'woo-bulk-variation-pricer'); ?></option>
                    <option value="or"><?php echo esc_html__('Match any attribute (OR)', 'woo-bulk-variation-pricer'); ?></option>
                </select>
                <div id="wbv-active-filters" style="display:inline-block; margin-left:1rem;"></div>
                <select id="wbv-per-page">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="9999"><?php echo esc_html__('All', 'woo-bulk-variation-pricer'); ?></option>
                </select>
                <button id="wbv-search-btn" class="button button-primary"><?php echo esc_html__('Search', 'woo-bulk-variation-pricer'); ?></button>

                <div id="wbv-price-controls" style="display:inline-block; margin-left:1rem;">
                    <label for="wbv-mode"><?php echo esc_html__('Mode', 'woo-bulk-variation-pricer'); ?>:</label>
                    <select id="wbv-mode">
                        <option value="percent"><?php echo esc_html__('Percent (+/-)', 'woo-bulk-variation-pricer'); ?></option>
                        <option value="amount"><?php echo esc_html__('Amount (+/-)', 'woo-bulk-variation-pricer'); ?></option>
                        <option value="fixed"><?php echo esc_html__('Fixed Price', 'woo-bulk-variation-pricer'); ?></option>
                    </select>

                    <label for="wbv-value" style="margin-left:.5rem;"><?php echo esc_html__('Value', 'woo-bulk-variation-pricer'); ?>:</label>
                    <input id="wbv-value" type="number" step="0.01" style="width:8rem;" />

                    <label for="wbv-target" style="margin-left:.5rem;"><?php echo esc_html__('Target', 'woo-bulk-variation-pricer'); ?>:</label>
                    <select id="wbv-target">
                        <option value="regular"><?php echo esc_html__('Regular price', 'woo-bulk-variation-pricer'); ?></option>
                        <option value="sale"><?php echo esc_html__('Sale price', 'woo-bulk-variation-pricer'); ?></option>
                    </select>

                    <input id="wbv-operation-label" type="text" placeholder="<?php echo esc_attr__('Operation label (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.5rem; width:18rem;" />
                    <button id="wbv-preview-btn" class="button"><?php echo esc_html__('Preview', 'woo-bulk-variation-pricer'); ?></button>
                    <button id="wbv-apply-btn" class="button button-primary"><?php echo esc_html__('Apply Changes', 'woo-bulk-variation-pricer'); ?></button>
                    <button id="wbv-quick-create-variations-btn" class="button" style="margin-left:.5rem;"><?php echo esc_html__('Quick Add Variations', 'woo-bulk-variation-pricer'); ?></button>
                    <button id="wbv-export-csv" class="button" style="margin-left:.5rem;"><?php echo esc_html__('Export CSV', 'woo-bulk-variation-pricer'); ?></button>
                </div>
            </div>
            <div id="wbv-quick-create-panel" style="margin-top:.75rem; padding:.75rem; border:1px solid #dcdcde; background:#fff;">
                <strong><?php echo esc_html__('Bulk add specific attribute values', 'woo-bulk-variation-pricer'); ?></strong>
                <input id="wbv-qc-attribute" type="text" placeholder="<?php echo esc_attr__('Attribute taxonomy (e.g. pa_size)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.5rem; min-width:220px;" />
                <input id="wbv-qc-values" type="text" placeholder="<?php echo esc_attr__('Values (comma separated, e.g. M,L,XL,XXL)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; min-width:320px;" />
                <input id="wbv-qc-regular-price" type="number" step="0.01" placeholder="<?php echo esc_attr__('Regular price for new variations (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:280px;" />
                <input id="wbv-qc-stock-qty" type="number" step="1" placeholder="<?php echo esc_attr__('Stock qty for new variations (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:240px;" />
            </div>
            <div id="wbv-bulk-fields-panel" style="margin-top:.75rem; padding:.75rem; border:1px solid #dcdcde; background:#fff;">
                <strong><?php echo esc_html__('Bulk Product Field Editor (selected variations)', 'woo-bulk-variation-pricer'); ?></strong>
                <input id="wbv-bf-sku-prefix" type="text" placeholder="<?php echo esc_attr__('SKU prefix (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.5rem; width:220px;" />
                <input id="wbv-bf-regular-price" type="number" step="0.01" placeholder="<?php echo esc_attr__('Regular price (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:180px;" />
                <input id="wbv-bf-sale-price" type="number" step="0.01" placeholder="<?php echo esc_attr__('Sale price (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:180px;" />
                <input id="wbv-bf-stock-qty" type="number" step="1" placeholder="<?php echo esc_attr__('Stock qty (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:160px;" />
                <select id="wbv-bf-stock-status" style="margin-left:.25rem;">
                    <option value=""><?php echo esc_html__('Stock status (optional)', 'woo-bulk-variation-pricer'); ?></option>
                    <option value="instock"><?php echo esc_html__('In stock', 'woo-bulk-variation-pricer'); ?></option>
                    <option value="outofstock"><?php echo esc_html__('Out of stock', 'woo-bulk-variation-pricer'); ?></option>
                    <option value="onbackorder"><?php echo esc_html__('On backorder', 'woo-bulk-variation-pricer'); ?></option>
                </select>
                <input id="wbv-bf-shipping-class-id" type="number" step="1" placeholder="<?php echo esc_attr__('Shipping class ID (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:190px;" />
                <input id="wbv-bf-weight" type="number" step="0.001" placeholder="<?php echo esc_attr__('Weight (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:150px;" />
                <input id="wbv-bf-length" type="number" step="0.001" placeholder="<?php echo esc_attr__('Length (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:140px;" />
                <input id="wbv-bf-width" type="number" step="0.001" placeholder="<?php echo esc_attr__('Width (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:140px;" />
                <input id="wbv-bf-height" type="number" step="0.001" placeholder="<?php echo esc_attr__('Height (optional)', 'woo-bulk-variation-pricer'); ?>" style="margin-left:.25rem; width:140px;" />
                <button id="wbv-bulk-fields-apply-btn" class="button button-secondary" style="margin-left:.5rem;"><?php echo esc_html__('Apply Fields', 'woo-bulk-variation-pricer'); ?></button>
            </div>

            <div id="wbv-results"></div>
            <div id="wbv-preview" style="margin-top:1rem;"></div>

            <h2 style="margin-top:2rem;"><?php echo esc_html__('Settings', 'woo-bulk-variation-pricer'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('wbv_pricer'); ?>
                <?php do_settings_sections('wbv_pricer'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wbv_chunk_size"><?php echo esc_html__('Chunk size', 'woo-bulk-variation-pricer'); ?></label></th>
                        <td><input name="wbv_chunk_size" id="wbv_chunk_size" type="number" value="<?php echo esc_attr(get_option('wbv_chunk_size', 100)); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Use ActionScheduler', 'woo-bulk-variation-pricer'); ?></th>
                        <td><label><input name="wbv_use_action_scheduler" type="checkbox" value="1" <?php checked(get_option('wbv_use_action_scheduler', true)); ?> /> <?php echo esc_html__('Enable background processing (requires ActionScheduler bundled with WooCommerce)', 'woo-bulk-variation-pricer'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2 style="margin-top:2rem;"><?php echo esc_html__('Recent Operations', 'woo-bulk-variation-pricer'); ?></h2>
            <div id="wbv-operations"></div>
            <div id="wbv-operation-rows" style="margin-top:1rem;"></div>
        </div>
        </div>
    <?php
    }

    public static function render_defaults_page()
    {
    ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Bulk Default Attributes', 'woo-bulk-variation-pricer'); ?></h1>
            <p><?php echo esc_html__('Search products and set their default attribute values in bulk. This controls which variation is pre-selected when customers view the product page.', 'woo-bulk-variation-pricer'); ?></p>

            <div id="wbv-defaults-app">
                <!-- Instructions -->
                <div style="margin-bottom:1rem; padding:15px; background:#e7f3ff; border-left:4px solid #2271b1; border-radius:4px;">
                    <h3 style="margin-top:0; color:#2271b1;">
                        <span class="dashicons dashicons-info" style="font-size:20px; vertical-align:middle;"></span>
                        <?php echo esc_html__('How to Set Default Attributes', 'woo-bulk-variation-pricer'); ?>
                    </h3>
                    <ol style="margin:10px 0; padding-left:20px;">
                        <li><strong><?php echo esc_html__('Search for products', 'woo-bulk-variation-pricer'); ?></strong> - <?php echo esc_html__('Use the search box below to find variable products', 'woo-bulk-variation-pricer'); ?></li>
                        <li><strong><?php echo esc_html__('Select products', 'woo-bulk-variation-pricer'); ?></strong> - <?php echo esc_html__('Check the boxes next to products you want to update', 'woo-bulk-variation-pricer'); ?></li>
                        <li><strong><?php echo esc_html__('Choose defaults', 'woo-bulk-variation-pricer'); ?></strong> - <?php echo esc_html__('Select default attribute values in the panel below', 'woo-bulk-variation-pricer'); ?></li>
                        <li><strong><?php echo esc_html__('Preview & Apply', 'woo-bulk-variation-pricer'); ?></strong> - <?php echo esc_html__('Preview changes, then click "Apply Changes" to update all selected products', 'woo-bulk-variation-pricer'); ?></li>
                    </ol>
                    <p style="margin-bottom:0; color:#135e96;">
                        <span class="dashicons dashicons-lightbulb" style="vertical-align:middle;"></span>
                        <strong><?php echo esc_html__('Tip:', 'woo-bulk-variation-pricer'); ?></strong>
                        <?php echo esc_html__('Default attributes control which variation is pre-selected when customers view the product page.', 'woo-bulk-variation-pricer'); ?>
                    </p>
                </div>

                <!-- Search Toolbar -->
                <div id="wbv-defaults-toolbar" style="margin-bottom: 1rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                    <input id="wbv-defaults-search" type="search" placeholder="<?php echo esc_attr__('Search products or SKU', 'woo-bulk-variation-pricer'); ?>" style="width:300px; min-height:34px;" />
                    <select id="wbv-defaults-per-page" style="min-height:34px;">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="9999"><?php echo esc_html__('All', 'woo-bulk-variation-pricer'); ?></option>
                    </select>
                    <button id="wbv-defaults-search-btn" class="button button-primary"><?php echo esc_html__('Search', 'woo-bulk-variation-pricer'); ?></button>
                    <label style="margin-left:1rem;"><input id="wbv-defaults-select-all" type="checkbox" /> <?php echo esc_html__('Select all visible', 'woo-bulk-variation-pricer'); ?></label>
                </div>

                <!-- Defaults Selector Panel - Always Visible at Top -->
                <div id="wbv-defaults-selector" style="margin-bottom:1.5rem; padding:1.5rem; background:#fff; border:2px solid #2271b1; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0; color:#2271b1; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-admin-settings" style="font-size:24px;"></span>
                        <?php echo esc_html__('Set Default Attributes', 'woo-bulk-variation-pricer'); ?>
                    </h3>
                    <p class="wbv-selection-description" style="font-weight:bold; color:#d63638; margin:10px 0;">
                        ⚠️ <?php echo esc_html__('No products selected. Search and check the boxes below to select products.', 'woo-bulk-variation-pricer'); ?>
                    </p>
                    <div id="wbv-defaults-attributes" style="margin:1rem 0; padding:15px; background:#f9f9f9; border-radius:4px; min-height:60px;">
                        <p style="color:#666; font-style:italic; margin:0;">
                            <span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
                            <?php echo esc_html__('Search for products above to see available attributes here.', 'woo-bulk-variation-pricer'); ?>
                        </p>
                    </div>
                    <div style="margin-top:1rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <input id="wbv-defaults-operation-label" type="text" placeholder="<?php echo esc_attr__('Operation label (optional)', 'woo-bulk-variation-pricer'); ?>" style="width:250px; margin-right:0.5rem;" />
                        <button id="wbv-defaults-preview-btn" class="button"><?php echo esc_html__('Preview', 'woo-bulk-variation-pricer'); ?></button>
                        <button id="wbv-defaults-apply-btn" class="button button-primary"><?php echo esc_html__('Apply Changes', 'woo-bulk-variation-pricer'); ?></button>
                    </div>
                </div>

                <h3 style="margin:1.5rem 0 1rem 0; color:#333;">
                    <span class="dashicons dashicons-list-view" style="vertical-align:middle;"></span>
                    <?php echo esc_html__('Search Results - Select Products', 'woo-bulk-variation-pricer'); ?>
                </h3>
                <div id="wbv-defaults-results" style="background:#f9f9f9; padding:15px; border-radius:4px; min-height:100px;">
                    <p style="color:#666; text-align:center; padding:40px 20px; margin:0;">
                        <span class="dashicons dashicons-arrow-up-alt" style="font-size:48px; opacity:0.3;"></span><br>
                        <strong><?php echo esc_html__('Use the search box above to find variable products', 'woo-bulk-variation-pricer'); ?></strong>
                    </p>
                </div>
                <div id="wbv-defaults-preview" style="margin-top:1rem;"></div>
            </div>
        </div>
<?php
    }

    public static function enqueue_assets($hook)
    {
        // Load assets on price editor page
        if ($hook === 'product_page_wbv-pricer') {
            wp_enqueue_style('wbv-admin', WBVPRICER_PLUGIN_URL . 'assets/css/admin.css', array(), WBVPRICER_VERSION);
            wp_enqueue_script('wbv-admin', WBVPRICER_PLUGIN_URL . 'assets/js/admin.js', array(), WBVPRICER_VERSION, true);

            wp_localize_script('wbv-admin', 'wbvPricer', array(
                'nonce'     => wp_create_nonce('wp_rest'),
                'rest_root' => esc_url_raw(rest_url('wbvpricer/v1')),
                'i18n' => array(
                    'noSelection' => __('No variations selected', 'woo-bulk-variation-pricer'),
                    'searching' => __('Searching…', 'woo-bulk-variation-pricer'),
                    'updateScheduled' => __('Update scheduled', 'woo-bulk-variation-pricer'),
                    'updatedVariations' => __('Updated variations', 'woo-bulk-variation-pricer'),
                    'revertScheduled' => __('Revert scheduled', 'woo-bulk-variation-pricer'),
                    'revertCompleted' => __('Revert completed', 'woo-bulk-variation-pricer'),
                    'noProducts' => __('No products found', 'woo-bulk-variation-pricer'),
                    'noPreview' => __('No preview', 'woo-bulk-variation-pricer'),
                    'previewTitle' => __('Preview', 'woo-bulk-variation-pricer'),
                    'selectAllVisible' => __('Select all visible', 'woo-bulk-variation-pricer'),
                    'attributesFetchFailed' => __('Could not load attribute definitions from the server. The UI will fall back to attributes detected in the current search results.', 'woo-bulk-variation-pricer'),
                    'attributesFetchForbidden' => __('Attributes cannot be loaded due to insufficient permissions (HTTP 403). Ensure your account has the required capability (manage_woocommerce).', 'woo-bulk-variation-pricer'),
                    'attributesFetchServerError' => __('Server error while fetching attributes (HTTP %d). Falling back to product-derived values.', 'woo-bulk-variation-pricer'),
                    'attributesFetchRetry' => __('Retry', 'woo-bulk-variation-pricer'),
                ),
            ));
            return;
        }

        // Load assets on defaults page
        if ($hook === 'product_page_wbv-defaults') {
            wp_enqueue_style('wbv-admin', WBVPRICER_PLUGIN_URL . 'assets/css/admin.css', array(), WBVPRICER_VERSION);
            wp_enqueue_script('wbv-defaults', WBVPRICER_PLUGIN_URL . 'assets/js/defaults.js', array(), WBVPRICER_VERSION, true);

            wp_localize_script('wbv-defaults', 'wbvDefaults', array(
                'nonce'     => wp_create_nonce('wp_rest'),
                'rest_root' => esc_url_raw(rest_url('wbvpricer/v1')),
                'i18n' => array(
                    'noSelection' => __('No products selected', 'woo-bulk-variation-pricer'),
                    'searching' => __('Searching…', 'woo-bulk-variation-pricer'),
                    'noProducts' => __('No products found', 'woo-bulk-variation-pricer'),
                    'noPreview' => __('No preview', 'woo-bulk-variation-pricer'),
                    'previewTitle' => __('Preview: Default Attributes Changes', 'woo-bulk-variation-pricer'),
                ),
            ));
            return;
        }
    }
}
