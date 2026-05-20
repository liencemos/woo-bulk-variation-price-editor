<?php
if (! defined('ABSPATH')) {
    exit;
}

class WBV_REST_Controller
{
    public static function register_routes()
    {
        register_rest_route('wbvpricer/v1', '/search', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'handle_search'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/update', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_update'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/operations', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'handle_operations'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/operations/(?P<operation_id>[a-f0-9\-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'handle_operation_rows'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/undo', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_undo'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/attributes', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'handle_attributes'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/set-defaults', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_set_defaults'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/quick-create-variations', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_quick_create_variations'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/quick-create-attribute-variations', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_quick_create_attribute_variations'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('wbvpricer/v1', '/bulk-update-fields', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle_bulk_update_fields'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ));
    }

    public static function handle_quick_create_variations(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('no_woocommerce', 'WooCommerce is not available', array('status' => 500));
        }

        $params = $request->get_json_params();
        $product_ids = isset($params['product_ids']) && is_array($params['product_ids']) ? $params['product_ids'] : array();
        $max_per_product = isset($params['max_per_product']) ? absint($params['max_per_product']) : 50;
        $max_per_product = max(1, min(100, $max_per_product));

        $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        if (empty($product_ids)) {
            return new WP_Error('no_products', 'No product IDs provided', array('status' => 400));
        }

        $created_total = 0;
        $results = array();

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product || $product->get_type() !== 'variable') {
                $results[] = array('product_id' => $product_id, 'created' => 0, 'error' => 'Product not found or not variable');
                continue;
            }

            $before_count = count((array) $product->get_children());
            $created_for_product = 0;

            if (class_exists('WC_Product_Data_Store_CPT')) {
                $data_store = new WC_Product_Data_Store_CPT();
                $created_for_product = (int) $data_store->create_all_product_variations($product, $max_per_product);
            }

            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);

            $product = wc_get_product($product_id);
            $after_count = $product ? count((array) $product->get_children()) : $before_count;
            if ($created_for_product < 1 && $after_count > $before_count) {
                $created_for_product = $after_count - $before_count;
            }

            $created_total += max(0, $created_for_product);
            $results[] = array(
                'product_id' => $product_id,
                'created' => max(0, $created_for_product),
                'before' => $before_count,
                'after' => $after_count,
            );
        }

        return rest_ensure_response(array(
            'created_total' => $created_total,
            'results' => $results,
        ));
    }

    public static function handle_quick_create_attribute_variations(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('no_woocommerce', 'WooCommerce is not available', array('status' => 500));
        }

        $params = $request->get_json_params();
        $product_ids = isset($params['product_ids']) && is_array($params['product_ids']) ? $params['product_ids'] : array();
        $taxonomy = isset($params['attribute_taxonomy']) ? sanitize_text_field($params['attribute_taxonomy']) : '';
        $values = isset($params['values']) && is_array($params['values']) ? $params['values'] : array();
        $regular_price = isset($params['regular_price']) ? wc_format_decimal($params['regular_price']) : '';
        $stock_qty = isset($params['stock_quantity']) ? intval($params['stock_quantity']) : null;

        $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        $values = array_values(array_unique(array_filter(array_map('sanitize_text_field', $values))));

        if (empty($product_ids) || empty($taxonomy) || empty($values)) {
            return new WP_Error('invalid_payload', 'product_ids, attribute_taxonomy and values are required', array('status' => 400));
        }

        $created_total = 0;
        $results = array();

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product || $product->get_type() !== 'variable') {
                $results[] = array('product_id' => $product_id, 'created' => 0, 'skipped' => 0, 'error' => 'Product not found or not variable');
                continue;
            }

            $existing_values = array();
            foreach ((array) $product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if (! $child) {
                    continue;
                }
                $attrs = $child->get_attributes();
                if (isset($attrs[$taxonomy]) && $attrs[$taxonomy] !== '') {
                    $existing_values[] = (string) $attrs[$taxonomy];
                }
            }
            $existing_values = array_unique($existing_values);

            $created_for_product = 0;
            $skipped_for_product = 0;

            foreach ($values as $value_raw) {
                $term = get_term_by('slug', $value_raw, $taxonomy);
                if (! $term) {
                    $term = get_term_by('name', $value_raw, $taxonomy);
                }
                $value = $term ? $term->slug : sanitize_title($value_raw);

                if (in_array($value, $existing_values, true)) {
                    $skipped_for_product++;
                    continue;
                }

                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
                $variation->set_attributes(array($taxonomy => $value));
                if ($regular_price !== '') {
                    $variation->set_regular_price($regular_price);
                }
                if ($stock_qty !== null && $stock_qty >= 0) {
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($stock_qty);
                }
                $variation->set_status('publish');
                $new_id = $variation->save();

                if ($new_id) {
                    $created_for_product++;
                    $existing_values[] = $value;
                }
            }

            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);

            $created_total += $created_for_product;
            $results[] = array(
                'product_id' => $product_id,
                'created' => $created_for_product,
                'skipped' => $skipped_for_product,
            );
        }

        return rest_ensure_response(array(
            'created_total' => $created_total,
            'results' => $results,
        ));
    }

    public static function handle_bulk_update_fields(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('no_woocommerce', 'WooCommerce is not available', array('status' => 500));
        }

        $params = $request->get_json_params();
        $variation_ids = isset($params['variation_ids']) && is_array($params['variation_ids']) ? $params['variation_ids'] : array();
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
        $variation_ids = array_values(array_unique(array_filter(array_map('absint', $variation_ids))));

        if (empty($variation_ids) || empty($fields)) {
            return new WP_Error('invalid_payload', 'variation_ids and fields are required', array('status' => 400));
        }

        $updated = array();
        $errors = array();

        foreach ($variation_ids as $vid) {
            $variation = wc_get_product($vid);
            if (! $variation || ! is_a($variation, 'WC_Product_Variation')) {
                $errors[] = array('variation_id' => $vid, 'error' => 'Variation not found');
                continue;
            }

            if (isset($fields['sku_prefix']) && $fields['sku_prefix'] !== '') {
                $current_sku = (string) $variation->get_sku();
                $variation->set_sku(sanitize_text_field($fields['sku_prefix']) . $vid . ($current_sku !== '' ? '-' . $current_sku : ''));
            }
            if (isset($fields['regular_price']) && $fields['regular_price'] !== '') {
                $variation->set_regular_price(wc_format_decimal($fields['regular_price']));
            }
            if (isset($fields['sale_price']) && $fields['sale_price'] !== '') {
                $variation->set_sale_price(wc_format_decimal($fields['sale_price']));
            }
            if (isset($fields['stock_quantity']) && $fields['stock_quantity'] !== '') {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity(intval($fields['stock_quantity']));
            }
            if (isset($fields['stock_status']) && in_array($fields['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) {
                $variation->set_stock_status($fields['stock_status']);
            }
            if (isset($fields['shipping_class_id']) && $fields['shipping_class_id'] !== '') {
                $variation->set_shipping_class_id(absint($fields['shipping_class_id']));
            }
            if (isset($fields['weight']) && $fields['weight'] !== '') {
                $variation->set_weight(wc_format_decimal($fields['weight']));
            }
            if (isset($fields['length']) && $fields['length'] !== '') {
                $variation->set_length(wc_format_decimal($fields['length']));
            }
            if (isset($fields['width']) && $fields['width'] !== '') {
                $variation->set_width(wc_format_decimal($fields['width']));
            }
            if (isset($fields['height']) && $fields['height'] !== '') {
                $variation->set_height(wc_format_decimal($fields['height']));
            }

            $saved = $variation->save();
            if (! $saved) {
                $errors[] = array('variation_id' => $vid, 'error' => 'Could not save variation');
                continue;
            }

            WC_Product_Variable::sync($variation->get_parent_id());
            wc_delete_product_transients($variation->get_parent_id());
            $updated[] = $vid;
        }

        return rest_ensure_response(array(
            'updated_count' => count($updated),
            'updated' => $updated,
            'errors' => $errors,
        ));
    }

    public static function handle_search(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return rest_ensure_response(array('products' => array(), 'total' => 0, 'page' => 1, 'per_page' => 0));
        }

        $q = sanitize_text_field($request->get_param('q'));
        $requested_per_page = intval($request->get_param('per_page') ? $request->get_param('per_page') : 25);
        // Allow "All" (9999) but cap at a safe maximum to prevent memory issues
        $per_page = ($requested_per_page >= 9999) ? 9999 : max(1, min(500, $requested_per_page));
        $page = max(1, intval($request->get_param('page') ? $request->get_param('page') : 1));
        $category = $request->get_param('category');
        $attribute = $request->get_param('attribute');

        $product_ids_by_query = array();
        $product_ids_by_attribute = array();

        if (! empty($q)) {
            $product_ids = array();
            // Titles
            $title_query = new WP_Query(array(
                'post_type'      => 'product',
                's'              => $q,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => 'publish',
            ));

            $product_ids = array_merge($product_ids, (array) $title_query->posts);

            // Product SKU
            $sku_query = new WP_Query(array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'     => '_sku',
                        'value'   => $q,
                        'compare' => 'LIKE',
                    ),
                ),
            ));

            $product_ids = array_merge($product_ids, (array) $sku_query->posts);

            // Variation SKU -> parent product
            $var_query = new WP_Query(array(
                'post_type'      => 'product_variation',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'     => '_sku',
                        'value'   => $q,
                        'compare' => 'LIKE',
                    ),
                ),
            ));

            if (! empty($var_query->posts)) {
                foreach ($var_query->posts as $var_id) {
                    $var_post = get_post($var_id);
                    if ($var_post && $var_post->post_parent) {
                        $product_ids[] = (int) $var_post->post_parent;
                    }
                }
            }

            $product_ids_by_query = array_values(array_unique($product_ids));
        }

        // Attribute filtering: support multi-select attribute_pairs[] and operator attribute_operator (AND|OR)
        $attribute_pairs = $request->get_param('attribute_pairs'); // array of 'taxonomy|term_slug' or 'taxonomy|search text'
        $attribute_operator = strtolower(sanitize_text_field($request->get_param('attribute_operator') ? $request->get_param('attribute_operator') : 'and'));

        // Backwards compatibility: if single attribute/value were provided, coerce into pairs format
        if (empty($attribute_pairs)) {
            $attribute = $request->get_param('attribute'); // expected taxonomy name, e.g. 'pa_quantity'
            $attribute_value = $request->get_param('attribute_value'); // expected term slug or text
            if (! empty($attribute) && ! empty($attribute_value)) {
                $attribute_pairs = array(sanitize_text_field($attribute) . '|' . sanitize_text_field($attribute_value));
            }
        }

        $product_ids_by_attribute = array();
        if (! empty($attribute_pairs)) {
            $product_ids_by_attribute = self::resolve_product_ids_for_attribute_pairs($attribute_pairs, $attribute_operator);
        }

        // Combine query results: if both query and attribute filter present, intersect (AND). Otherwise use whichever set exists.
        $product_ids = array();
        if (! empty($product_ids_by_query) && ! empty($product_ids_by_attribute)) {
            $product_ids = array_values(array_intersect($product_ids_by_query, $product_ids_by_attribute));
            if (empty($product_ids)) {
                return rest_ensure_response(array('products' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page));
            }
        } elseif (! empty($product_ids_by_query)) {
            $product_ids = $product_ids_by_query;
        } elseif (! empty($product_ids_by_attribute)) {
            $product_ids = $product_ids_by_attribute;
        }

        $query_args = array(
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        );

        if (! empty($product_ids)) {
            $query_args['post__in'] = $product_ids;
        }

        $tax_query = array();
        if (! empty($category)) {
            if (is_numeric($category)) {
                $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => intval($category));
            } else {
                $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => sanitize_text_field($category));
            }
        }

        if (! empty($tax_query)) {
            $query_args['tax_query'] = $tax_query;
        }

        $product_query = new WP_Query($query_args);

        $products = array();
        if (! empty($product_query->posts)) {
            foreach ($product_query->posts as $pid) {
                $product = wc_get_product($pid);
                if (! $product) {
                    continue;
                }

                // Only variable products make sense for variations
                if ($product->get_type() !== 'variable') {
                    continue;
                }

                $variation_items = array();
                $variation_ids = (array) $product->get_children();

                // If attribute filtering is active, only show variations matching the filter
                $filtered_variation_ids = $variation_ids;
                if (! empty($attribute_pairs) && is_array($attribute_pairs)) {
                    $filtered_variation_ids = self::filter_variations_by_attributes($variation_ids, $attribute_pairs);
                }

                foreach ($filtered_variation_ids as $vid) {
                    $variation = wc_get_product($vid);
                    if (! $variation) {
                        continue;
                    }

                    $attrs = array();
                    foreach ($variation->get_attributes() as $a_key => $a_value) {
                        $raw_key = str_replace('attribute_', '', $a_key);
                        $label = function_exists('wc_attribute_label') ? wc_attribute_label($raw_key) : ucfirst(str_replace(array('pa_', '_'), array('', ' '), $raw_key));

                        if (strpos($raw_key, 'pa_') === 0) {
                            $term = get_term_by('slug', $a_value, $raw_key);
                            $display_value = $term ? $term->name : $a_value;
                        } else {
                            $display_value = $a_value;
                        }

                        $attrs[] = array('key' => $raw_key, 'label' => $label, 'value' => $display_value);
                    }

                    $variation_items[] = array(
                        'variation_id'  => $variation->get_id(),
                        'sku'           => sanitize_text_field($variation->get_sku()),
                        'attributes'    => array_map(function ($a) {
                            return array('key' => sanitize_text_field($a['key']), 'label' => sanitize_text_field($a['label']), 'value' => sanitize_text_field($a['value']));
                        }, $attrs),
                        'regular_price' => $variation->get_regular_price(),
                        'sale_price'    => $variation->get_sale_price(),
                    );
                }

                if (empty($variation_items)) {
                    continue;
                }

                // Get default attributes for the product
                $default_attributes = $product->get_default_attributes();
                $default_attributes_display = array();

                if (! empty($default_attributes)) {
                    foreach ($default_attributes as $attr_key => $attr_value) {
                        $label = function_exists('wc_attribute_label') ? wc_attribute_label($attr_key) : ucfirst(str_replace(array('pa_', '_'), array('', ' '), $attr_key));

                        // Get display value for taxonomy-based attributes
                        if (strpos($attr_key, 'pa_') === 0) {
                            $term = get_term_by('slug', $attr_value, $attr_key);
                            $display_value = $term ? $term->name : $attr_value;
                        } else {
                            $display_value = $attr_value;
                        }

                        $default_attributes_display[] = array(
                            'key' => $attr_key,
                            'label' => $label,
                            'value' => $attr_value,
                            'display_value' => $display_value,
                        );
                    }
                }

                $products[] = array(
                    'product_id' => $product->get_id(),
                    'title'      => wp_strip_all_tags($product->get_name()),
                    'sku'        => sanitize_text_field($product->get_sku()),
                    'variations' => $variation_items,
                    'default_attributes' => $default_attributes_display,
                    'default_attributes_raw' => $default_attributes,
                );
            }
        }

        return rest_ensure_response(array('products' => $products, 'total' => (int) $product_query->found_posts, 'page' => $page, 'per_page' => $per_page));
    }

    public static function handle_attributes(WP_REST_Request $request)
    {
        $out = array();
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return rest_ensure_response(array('attributes' => array()));
        }

        $taxes = wc_get_attribute_taxonomies();
        if (! empty($taxes)) {
            foreach ($taxes as $tax) {
                $attr_name = isset($tax->attribute_name) ? $tax->attribute_name : '';
                if (empty($attr_name)) {
                    continue;
                }

                $taxonomy = wc_attribute_taxonomy_name($attr_name);
                if (! taxonomy_exists($taxonomy)) {
                    continue;
                }

                $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
                $term_list = array();
                if (! is_wp_error($terms) && ! empty($terms)) {
                    foreach ($terms as $t) {
                        $term_list[] = array('slug' => $t->slug, 'name' => $t->name);
                    }
                }

                $out[] = array('attribute_name' => $attr_name, 'taxonomy' => $taxonomy, 'label' => $tax->attribute_label, 'terms' => $term_list);
            }
        }

        return rest_ensure_response(array('attributes' => $out));
    }

    /**
     * Resolve an array of attribute_pairs (taxonomy|value) into matching parent product IDs.
     * Supports value being slug or a text search that matches term names. Operator is 'and' or 'or'.
     *
     * @param array $pairs
     * @param string $operator
     * @return array
     */
    /**
     * Filter variation IDs to only include those matching the attribute pairs.
     *
     * @param array $variation_ids Array of variation IDs to filter.
     * @param array $attribute_pairs Array of 'taxonomy|value' pairs.
     * @return array Filtered variation IDs.
     */
    public static function filter_variations_by_attributes($variation_ids, $attribute_pairs)
    {
        if (empty($variation_ids) || empty($attribute_pairs)) {
            return $variation_ids;
        }

        $filtered = array();
        foreach ($variation_ids as $vid) {
            $variation = wc_get_product($vid);
            if (! $variation) {
                continue;
            }

            $matches_all = true;
            foreach ($attribute_pairs as $pair) {
                $parts = explode('|', $pair, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                list($tax, $search_value) = $parts;
                $tax = sanitize_text_field($tax);
                $search_value = sanitize_text_field($search_value);

                // Get the variation's attribute value for this taxonomy
                // Use get_post_meta() directly to retrieve the variation attribute value
                $meta_key = 'attribute_' . $tax;
                $variation_value = get_post_meta($vid, $meta_key, true);

                // Match by slug or by term name
                $matches = false;
                if ($variation_value === $search_value) {
                    $matches = true;
                } else {
                    // Try to resolve search_value to a term and check if variation value matches
                    if (taxonomy_exists($tax)) {
                        $term = get_term_by('slug', $search_value, $tax);
                        if ($term && $variation_value === $term->slug) {
                            $matches = true;
                        }
                        // Also try name-based search
                        if (! $matches) {
                            $terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => false, 'search' => $search_value));
                            if (! is_wp_error($terms) && ! empty($terms)) {
                                foreach ($terms as $t) {
                                    if ($variation_value === $t->slug) {
                                        $matches = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                if (! $matches) {
                    $matches_all = false;
                    break;
                }
            }

            if ($matches_all) {
                $filtered[] = $vid;
            }
        }

        return $filtered;
    }

    public static function resolve_product_ids_for_attribute_pairs($pairs, $operator = 'and')
    {
        $pairs = (array) $pairs;
        $filters = array();
        foreach ($pairs as $pair) {
            $pair = sanitize_text_field($pair);
            $parts = explode('|', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            list($tax, $val) = $parts;
            $tax = sanitize_text_field($tax);
            $val = sanitize_text_field($val);
            if (! isset($filters[$tax])) {
                $filters[$tax] = array();
            }
            $filters[$tax][] = $val;
        }

        $per_tax_product_ids = array();
        foreach ($filters as $tax => $values) {
            // Accept attribute name as well as taxonomy: convert attribute_name to taxonomy
            if (! taxonomy_exists($tax) && function_exists('wc_attribute_taxonomy_name')) {
                $maybe_tax = wc_attribute_taxonomy_name($tax);
                if (taxonomy_exists($maybe_tax)) {
                    $tax = $maybe_tax;
                }
            }

            if (! taxonomy_exists($tax)) {
                // Skip unknown taxonomy
                $per_tax_product_ids[$tax] = array();
                continue;
            }

            // Resolve each provided value into term slugs (try slug first, then name search)
            $resolved_slugs = array();
            foreach ($values as $v) {
                if (empty($v)) {
                    continue;
                }

                $found = get_term_by('slug', $v, $tax);
                if ($found && ! is_wp_error($found)) {
                    $resolved_slugs[] = $found->slug;
                    continue;
                }

                // Try name-based search
                $terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => false, 'search' => $v));
                if (! is_wp_error($terms) && ! empty($terms)) {
                    foreach ($terms as $t) {
                        $resolved_slugs[] = $t->slug;
                    }
                    continue;
                }

                // If nothing matched, still include raw value (best effort)
                $resolved_slugs[] = $v;
            }

            $resolved_slugs = array_values(array_unique(array_filter($resolved_slugs)));

            $attr_parent_ids = array();
            if (! empty($resolved_slugs)) {
                // Find variations with meta key attribute_{tax} in selected slugs
                $meta_key = 'attribute_' . $tax;
                $var_query = new WP_Query(array(
                    'post_type'      => 'product_variation',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'post_status'    => 'publish',
                    'meta_query'     => array(
                        array('key' => $meta_key, 'value' => $resolved_slugs, 'compare' => 'IN'),
                    ),
                ));
                if (! empty($var_query->posts)) {
                    foreach ($var_query->posts as $var_id) {
                        $var_post = get_post($var_id);
                        if ($var_post && $var_post->post_parent) {
                            $attr_parent_ids[] = (int) $var_post->post_parent;
                        }
                    }
                }
            }

            // Find parent products assigned the selected terms (by slug)
            $product_tax_ids = array();
            if (! empty($resolved_slugs)) {
                $tax_query = new WP_Query(array(
                    'post_type'      => 'product',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'post_status'    => 'publish',
                    'tax_query'      => array(
                        array('taxonomy' => $tax, 'field' => 'slug', 'terms' => $resolved_slugs),
                    ),
                ));
                $product_tax_ids = (array) $tax_query->posts;
            }

            $per_tax_product_ids[$tax] = array_values(array_unique(array_merge($attr_parent_ids, $product_tax_ids)));
        }

        // Combine per-taxonomy product id sets according to operator
        $product_ids_by_attribute = array();
        if (! empty($per_tax_product_ids)) {
            if ($operator === 'or') {
                // union
                $merged = array();
                foreach ($per_tax_product_ids as $set) {
                    $merged = array_merge($merged, $set);
                }
                $product_ids_by_attribute = array_values(array_unique($merged));
            } else {
                // default AND => intersection across each set
                $sets = array_values($per_tax_product_ids);
                $intersection = array_shift($sets);
                foreach ($sets as $s) {
                    $intersection = array_intersect($intersection, $s);
                }
                $product_ids_by_attribute = array_values(array_unique((array) $intersection));
            }
        }

        return $product_ids_by_attribute;
    }

    public static function handle_operations(WP_REST_Request $request)
    {
        $limit = max(1, min(100, intval($request->get_param('per_page') ? $request->get_param('per_page') : 20)));
        $ops = WBV_Logger::get_recent_operations($limit);
        return rest_ensure_response(array('operations' => $ops));
    }

    public static function handle_operation_rows(WP_REST_Request $request)
    {
        $operation_id = sanitize_text_field($request->get_param('operation_id'));
        if (empty($operation_id)) {
            return new WP_Error('missing_operation', 'operation_id is required', array('status' => 400));
        }

        $rows = WBV_Logger::get_operation_rows($operation_id);
        return rest_ensure_response(array('rows' => $rows));
    }

    public static function handle_undo(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params) || ! is_array($params)) {
            return new WP_Error('invalid_payload', 'Invalid or missing JSON body', array('status' => 400));
        }

        $operation_id = isset($params['operation_id']) ? sanitize_text_field($params['operation_id']) : '';
        $dry_run = isset($params['dry_run']) ? (bool) $params['dry_run'] : true;
        $items = isset($params['items']) && is_array($params['items']) ? $params['items'] : array();

        if (empty($operation_id)) {
            return new WP_Error('missing_operation', 'operation_id is required', array('status' => 400));
        }

        $orig_rows = WBV_Logger::get_operation_rows($operation_id);
        if (empty($orig_rows)) {
            return new WP_Error('invalid_operation', 'Operation not found', array('status' => 404));
        }

        // Prepare preview data
        $preview = array();
        foreach ($orig_rows as $r) {
            $variation = wc_get_product($r->variation_id);
            $current = $variation ? ($r->target === 'regular' ? $variation->get_regular_price() : $variation->get_sale_price()) : null;
            $preview[] = array('variation_id' => $r->variation_id, 'current_price' => $current, 'will_restore' => $r->old_price);
        }

        if ($dry_run) {
            return rest_ensure_response(array('operation_id' => $operation_id, 'preview' => $preview));
        }

        // Not a dry run: schedule or perform reverts
        $total = count($orig_rows);
        $chunk_size = absint(get_option('wbv_chunk_size', 100));
        $revert_operation_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('wbv_revert_', true);

        $enable_as = (bool) get_option('wbv_use_action_scheduler', true);

        // Enforce safe synchronous revert limit if background processing disabled
        $max_sync = apply_filters('wbv_max_synchronous_updates', 200);
        if (! $enable_as && $total > $max_sync) {
            return new WP_Error('too_many_reverts', sprintf('Revert contains %d variations which exceeds safe synchronous limit (%d). Enable background processing or pass smaller selection.', $total, $max_sync), array('status' => 400));
        }

        $use_async = false;
        if ($enable_as && $total > $chunk_size && (function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action'))) {
            $use_async = true;
        }

        if ($use_async) {
            $variation_ids = array_map(function ($r) {
                return (int) $r->variation_id;
            }, $orig_rows);
            $chunks = array_chunk($variation_ids, $chunk_size);
            foreach ($chunks as $i => $chunk) {
                $args = array(
                    'operation_id' => $operation_id,
                    'revert_operation_id' => $revert_operation_id,
                    'items' => $chunk,
                    'label' => sprintf('Revert of %s', $operation_id),
                    'user_id' => get_current_user_id(),
                );

                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('wbv_run_revert_batch', $args);
                } elseif (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(time() + ($i * 3), 'wbv_run_revert_batch', $args);
                }
            }

            return rest_ensure_response(array('status' => 'scheduled', 'revert_operation_id' => $revert_operation_id, 'chunks' => count($chunks), 'total' => $total));
        }

        // Synchronous revert
        $rows_to_log = array();
        $errors = array();
        foreach ($orig_rows as $r) {
            if (! empty($items) && ! in_array($r->variation_id, $items, true)) {
                continue;
            }

            $vid = (int) $r->variation_id;
            $variation = wc_get_product($vid);
            if (! $variation) {
                $errors[] = array('variation_id' => $vid, 'error' => 'Variation not found');
                continue;
            }

            $current = $r->target === 'regular' ? $variation->get_regular_price() : $variation->get_sale_price();
            $old_price = $r->old_price;

            $res = WBV_Processor::update_variation_price($vid, $r->target, $old_price);
            if (is_wp_error($res)) {
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->error(sprintf('WBV sync revert failed: variation %d, error: %s', $vid, $res->get_error_message()), array('source' => 'wbvpricer'));
                } else {
                    error_log(sprintf('WBV sync revert failed: variation %d, error: %s', $vid, $res->get_error_message()));
                }

                $errors[] = array('variation_id' => $vid, 'error' => $res->get_error_message());
                continue;
            }

            $rows_to_log[] = array('variation_id' => $vid, 'product_id' => $r->product_id, 'old_price' => $current, 'new_price' => $old_price, 'mode' => 'revert', 'value' => 0, 'target' => $r->target);
            if (isset($r->id)) {
                WBV_Logger::mark_row_reverted($r->id);
            }
        }

        if (! empty($rows_to_log)) {
            WBV_Logger::log_changes($revert_operation_id, $rows_to_log, sprintf('Revert of %s', $operation_id));
        }

        return rest_ensure_response(array('status' => 'completed', 'revert_operation_id' => $revert_operation_id, 'errors' => $errors));
    }

    public static function handle_update(WP_REST_Request $request)
    {
        // Parse and validate JSON body
        $params = $request->get_json_params();
        if (empty($params) || ! is_array($params)) {
            return new WP_Error('invalid_payload', 'Invalid or missing JSON body', array('status' => 400));
        }

        $variations = isset($params['variations']) ? $params['variations'] : array();
        $mode = isset($params['mode']) ? $params['mode'] : 'percent';
        $value = isset($params['value']) ? $params['value'] : 0;
        $target = isset($params['target']) ? $params['target'] : 'regular';
        $dry_run = isset($params['dry_run']) ? (bool) $params['dry_run'] : true;
        $operation_label = isset($params['operation_label']) ? sanitize_text_field($params['operation_label']) : '';

        // Normalize variations (allow [1,2,3] or [{variation_id:1}, ...])
        $variation_ids = array();
        if (is_array($variations)) {
            foreach ($variations as $v) {
                if (is_array($v) && isset($v['variation_id'])) {
                    $variation_ids[] = absint($v['variation_id']);
                } elseif (is_numeric($v)) {
                    $variation_ids[] = absint($v);
                }
            }
        }

        $variation_ids = array_values(array_unique(array_filter($variation_ids)));

        if (empty($variation_ids)) {
            return new WP_Error('no_variations', 'No variation IDs provided', array('status' => 400));
        }

        // Validate mode and target
        $mode = in_array($mode, array('amount', 'percent', 'fixed'), true) ? $mode : 'percent';
        $allowed_targets = apply_filters('wbv_allowed_price_targets', array('regular', 'sale'));
        if (! in_array($target, $allowed_targets, true)) {
            return new WP_Error('invalid_target', 'Invalid price target', array('status' => 400));
        }

        // Sanitize numeric value and clamp ranges
        $value = floatval($value);
        if ($mode === 'percent') {
            $value = max(-1000.0, min(1000.0, $value));
        } elseif ($mode === 'fixed') {
            // For fixed price, ensure it's positive
            $value = max(0.0, min(999999999.0, $value));
        } else {
            // For amount mode
            $value = max(-999999.0, min(999999.0, $value));
        }

        $total = count($variation_ids);

        // Operation id for grouping logs
        $operation_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('wbv_', true);

        // If dry_run, compute preview and return
        if ($dry_run) {
            $preview = array();
            foreach ($variation_ids as $vid) {
                $variation = wc_get_product($vid);
                if (! $variation) {
                    $preview[] = array('variation_id' => $vid, 'error' => 'Variation not found');
                    continue;
                }

                $old = $target === 'regular' ? $variation->get_regular_price() : $variation->get_sale_price();
                $new = WBV_Processor::compute_new_price($old, $mode, $value);

                $preview[] = array(
                    'variation_id' => $vid,
                    'old_price'    => $old,
                    'new_price'    => $new,
                );
            }

            return rest_ensure_response(array('operation_id' => $operation_id, 'preview' => $preview, 'total' => $total));
        }

        // Not a dry run: decide between synchronous apply and background scheduling
        $chunk_size = absint(get_option('wbv_chunk_size', 100));
        $enable_as = (bool) get_option('wbv_use_action_scheduler', true);

        // If ActionScheduler is disabled, enforce a safe maximum synchronous batch size
        $max_sync = apply_filters('wbv_max_synchronous_updates', 200);
        if (! $enable_as && $total > $max_sync) {
            return new WP_Error('too_many_variations', sprintf('Batch contains %d variations which exceeds safe synchronous limit (%d). Enable background processing in plugin settings or reduce selection.', $total, $max_sync), array('status' => 400));
        }

        $use_async = false;
        if ($enable_as && $total > $chunk_size && (function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action'))) {
            $use_async = true;
        }

        $applied = array();
        $errors = array();

        if ($use_async) {
            // Schedule chunks
            $chunks = array_chunk($variation_ids, $chunk_size);
            foreach ($chunks as $i => $chunk) {
                $args = array(
                    'operation_id' => $operation_id,
                    'items'        => $chunk,
                    'mode'         => $mode,
                    'value'        => $value,
                    'target'       => $target,
                    'label'        => $operation_label,
                    'user_id'      => get_current_user_id(),
                );

                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('wbv_run_batch_update', $args);
                } elseif (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(time() + ($i * 3), 'wbv_run_batch_update', $args);
                }
            }

            return rest_ensure_response(array('operation_id' => $operation_id, 'status' => 'scheduled', 'chunks' => count($chunks), 'total' => $total));
        }

        // Synchronous apply for small sets
        $rows_to_log = array();
        foreach ($variation_ids as $vid) {
            $variation = wc_get_product($vid);
            if (! $variation) {
                $errors[] = array('variation_id' => $vid, 'error' => 'Variation not found');
                continue;
            }

            $old = $target === 'regular' ? $variation->get_regular_price() : $variation->get_sale_price();
            $new = WBV_Processor::compute_new_price($old, $mode, $value);

            // Allow other plugins to short-circuit or adjust
            $args = apply_filters('wbv_price_change_args', array('variation_id' => $vid, 'old' => $old, 'new' => $new, 'mode' => $mode, 'value' => $value, 'target' => $target));

            do_action('wbv_before_price_change', $vid, $old, $new, array('operation_id' => $operation_id));

            $res = WBV_Processor::update_variation_price($vid, $target, $new);

            if (is_wp_error($res)) {
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->error(sprintf('WBV sync update failed: variation %d, error: %s', $vid, $res->get_error_message()), array('source' => 'wbvpricer'));
                } else {
                    error_log(sprintf('WBV sync update failed: variation %d, error: %s', $vid, $res->get_error_message()));
                }

                $errors[] = array('variation_id' => $vid, 'error' => $res->get_error_message());
                continue;
            }

            do_action('wbv_after_price_change', $vid, $old, $new, array('operation_id' => $operation_id));

            $applied[] = array('variation_id' => $vid, 'old_price' => $old, 'new_price' => $new);
            $rows_to_log[] = array('variation_id' => $vid, 'product_id' => $variation->get_parent_id(), 'old_price' => $old, 'new_price' => $new, 'mode' => $mode, 'value' => $value, 'target' => $target);
        }

        if (! empty($rows_to_log)) {
            WBV_Logger::log_changes($operation_id, $rows_to_log, $operation_label);
        }

        return rest_ensure_response(array('operation_id' => $operation_id, 'applied' => $applied, 'errors' => $errors, 'total' => $total));
    }

    /**
     * Handle bulk setting of default attributes for variable products.
     * Expected request params:
     * - product_ids: array of product IDs
     * - defaults: array keyed by product_id, each containing attribute => value pairs
     * - dry_run: boolean (preview mode)
     * - operation_label: string (optional)
     * 
     * For batches > 300 products, uses ActionScheduler for background processing.
     */
    public static function handle_set_defaults(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('no_woocommerce', 'WooCommerce is not available', array('status' => 500));
        }

        $product_ids = $request->get_param('product_ids');
        $defaults = $request->get_param('defaults'); // array: product_id => array( 'pa_attribute' => 'term-slug' )
        $dry_run = filter_var($request->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $operation_label = sanitize_text_field($request->get_param('operation_label'));

        if (empty($product_ids) || ! is_array($product_ids)) {
            return new WP_Error('missing_products', 'No products specified', array('status' => 400));
        }

        if (empty($defaults) || ! is_array($defaults)) {
            return new WP_Error('missing_defaults', 'No default attributes specified', array('status' => 400));
        }

        $total = count($product_ids);
        $preview = array();
        $applied = array();
        $errors = array();
        $operation_id = wp_generate_uuid4();

        // Background processing threshold optimized for 4GB RAM VPS
        // 300 products allows sufficient memory headroom
        $chunk_size = (int) get_option('wbv_chunk_size', 100);
        $bg_threshold = 300;

        // Preview mode - always synchronous, limit to reasonable size
        if ($dry_run) {
            $preview_limit = 50; // Limit preview to first 50 products to avoid timeout
            $preview_ids = array_slice($product_ids, 0, $preview_limit);

            foreach ($preview_ids as $pid) {
                $pid = absint($pid);
                if (! $pid) {
                    continue;
                }

                $product = wc_get_product($pid);
                if (! $product || $product->get_type() !== 'variable') {
                    continue;
                }

                $old_defaults = $product->get_default_attributes();

                // Apply the same defaults to all products
                $new_defaults = $defaults;

                if (empty($new_defaults)) {
                    continue;
                }

                // Sanitize new defaults
                $sanitized_defaults = array();
                foreach ($new_defaults as $attr_key => $attr_value) {
                    $sanitized_key = sanitize_text_field($attr_key);
                    $sanitized_value = sanitize_text_field($attr_value);
                    if ($sanitized_key && $sanitized_value) {
                        $sanitized_defaults[$sanitized_key] = $sanitized_value;
                    }
                }

                $preview[] = array(
                    'product_id' => $pid,
                    'product_name' => $product->get_name(),
                    'old_defaults' => $old_defaults,
                    'new_defaults' => $sanitized_defaults,
                );
            }

            return rest_ensure_response(array(
                'preview' => $preview,
                'total' => count($preview),
                'total_selected' => $total,
                'preview_limit' => $preview_limit,
            ));
        }

        // Check if ActionScheduler is available and if we should use background processing
        $use_bg = get_option('wbv_use_action_scheduler', true) &&
            (function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action'));

        // Use background processing for large batches
        if ($use_bg && $total > $bg_threshold) {
            // Build products data array for background processing
            // Apply the same defaults to all products
            $products_data = array();
            foreach ($product_ids as $pid) {
                $pid = absint($pid);
                if (! $pid) {
                    continue;
                }

                $sanitized_defaults = array();
                foreach ($defaults as $attr_key => $attr_value) {
                    $sanitized_key = sanitize_text_field($attr_key);
                    $sanitized_value = sanitize_text_field($attr_value);
                    if ($sanitized_key && $sanitized_value) {
                        $sanitized_defaults[$sanitized_key] = $sanitized_value;
                    }
                }

                if (! empty($sanitized_defaults)) {
                    $products_data[$pid] = $sanitized_defaults;
                }
            }

            // Split into chunks optimized for 4GB RAM
            $chunks = array_chunk($products_data, $chunk_size, true);

            // Schedule background jobs
            foreach ($chunks as $i => $chunk) {
                $args = array(
                    'operation_id' => $operation_id,
                    'products' => $chunk,
                    'operation_label' => $operation_label,
                    'user_id' => get_current_user_id(),
                );

                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('wbv_run_defaults_batch', $args, 'wbvpricer');
                } elseif (function_exists('as_schedule_single_action')) {
                    // Stagger jobs by 3 seconds to reduce server load
                    as_schedule_single_action(time() + ($i * 3), 'wbv_run_defaults_batch', $args, 'wbvpricer');
                }
            }

            return rest_ensure_response(array(
                'operation_id' => $operation_id,
                'status' => 'scheduled',
                'chunks' => count($chunks),
                'total' => count($products_data),
                'message' => sprintf('Scheduled %d product(s) for background processing in %d batch(es)', count($products_data), count($chunks)),
            ));
        }

        // Synchronous processing for smaller batches
        foreach ($product_ids as $pid) {
            $pid = absint($pid);
            if (! $pid) {
                continue;
            }

            $product = wc_get_product($pid);
            if (! $product || $product->get_type() !== 'variable') {
                $errors[] = array('product_id' => $pid, 'error' => 'Product not found or not variable');
                continue;
            }

            // Get current default attributes
            $old_defaults = $product->get_default_attributes();

            // Apply the same defaults to all products
            $new_defaults = $defaults;

            if (empty($new_defaults)) {
                continue; // No changes for this product
            }

            // Sanitize new defaults
            $sanitized_defaults = array();
            foreach ($new_defaults as $attr_key => $attr_value) {
                $sanitized_key = sanitize_text_field($attr_key);
                $sanitized_value = sanitize_text_field($attr_value);
                if ($sanitized_key && $sanitized_value) {
                    $sanitized_defaults[$sanitized_key] = $sanitized_value;
                }
            }

            // Apply changes
            do_action('wbv_before_default_change', $pid, $old_defaults, $sanitized_defaults, array('operation_id' => $operation_id));

            $result = WBV_Processor::set_default_attributes($pid, $sanitized_defaults);

            if (is_wp_error($result)) {
                $errors[] = array('product_id' => $pid, 'error' => $result->get_error_message());
                continue;
            }

            do_action('wbv_after_default_change', $pid, $old_defaults, $sanitized_defaults, array('operation_id' => $operation_id));

            $applied[] = array(
                'product_id' => $pid,
                'product_name' => $product->get_name(),
                'old_defaults' => $old_defaults,
                'new_defaults' => $sanitized_defaults,
            );

            // Log for undo capability
            WBV_Logger::log_default_change($operation_id, $pid, $old_defaults, $sanitized_defaults, $operation_label);
        }

        return rest_ensure_response(array(
            'operation_id' => $operation_id,
            'applied' => $applied,
            'errors' => $errors,
            'total' => count($applied),
        ));
    }
}
