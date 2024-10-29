<?php
/**
 * woocommerce_mobapp API Products Class
 *
 * Handles requests to the /products endpoint
 *
 * @author      WooThemes
 * @category    API
 * @package     woocommerce_mobapp/API
 * @since       2.1
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class WOOAPP_API_Products extends WOOAPP_API_Resource
{

    /** @var string $base the route base */
    protected $base = '/products';
    protected $product_images = array();

    /**
     * Register the routes for this class
     *
     * GET /products
     * GET /products/count
     * GET /products/<id>
     * GET /products/<id>/reviews
     *
     * @since 2.1
     * @param array $routes
     * @return array
     */
    public function register_routes($routes)
    {

        # GET /products
        $routes[$this->base] = array(
            array(array($this, 'get_products'), WOOAPP_API_Server::READABLE),
        );

        # GET /products/count
        $routes[$this->base . '/count'] = array(
            array(array($this, 'get_products_count'), WOOAPP_API_Server::READABLE),
        );

        # GET /products/<id>
        $routes[$this->base . '/product'] = array(
            array(array($this, 'get_product'), WOOAPP_API_Server::READABLE),
        );

        # GET /products/<id>/reviews
        $routes[$this->base . '/reviews'] = array(
            array(array($this, 'get_product_reviews'), WOOAPP_API_Server::READABLE),
        );

        $routes[$this->base . '/add_review'] = array(
            array(array($this, 'add_product_review'), WOOAPP_API_Server::EDITABLE),
        );

        $routes[$this->base . '/filters'] = array(
            array(array($this, 'get_filters'), WOOAPP_API_Server::METHOD_GET),
        );

        return $routes;
    }

    public function get_filters()
    {
        global $mobappSettings, $wpdb;
        $return["enabled"] = isset($mobappSettings['product_filter_enable']) ? ($mobappSettings['product_filter_enable'] ? true : false) : true;
        $filters = (isset($mobappSettings["product_filter_attr"]) && is_array($mobappSettings["product_filter_attr"])) ? $mobappSettings["product_filter_attr"] : array();

        if (sizeof(WC()->query->layered_nav_product_ids) === 0) {
            $min = floor($wpdb->get_var(
                $wpdb->prepare('
					SELECT min(meta_value + 0)
					FROM %1$s
					LEFT JOIN %2$s ON %1$s.ID = %2$s.post_id
					WHERE ( meta_key = \'%3$s\' OR meta_key = \'%4$s\' )
					AND meta_value != ""
				', $wpdb->posts, $wpdb->postmeta, '_price', '_min_variation_price')
            ));
            $max = ceil($wpdb->get_var(
                $wpdb->prepare('
					SELECT max(meta_value + 0)
					FROM %1$s
					LEFT JOIN %2$s ON %1$s.ID = %2$s.post_id
					WHERE meta_key = \'%3$s\'
				', $wpdb->posts, $wpdb->postmeta, '_price')
            ));
        } else {
            $min = floor($wpdb->get_var(
                $wpdb->prepare('
					SELECT min(meta_value + 0)
					FROM %1$s
					LEFT JOIN %2$s ON %1$s.ID = %2$s.post_id
					WHERE ( meta_key =\'%3$s\' OR meta_key =\'%4$s\' )
					AND meta_value != ""
					AND (
						%1$s.ID IN (' . implode(',', array_map('absint', WC()->query->layered_nav_product_ids)) . ')
						OR (
							%1$s.post_parent IN (' . implode(',', array_map('absint', WC()->query->layered_nav_product_ids)) . ')
							AND %1$s.post_parent != 0
						)
					)
				', $wpdb->posts, $wpdb->postmeta, '_price', '_min_variation_price'
                )));
            $max = ceil($wpdb->get_var(
                $wpdb->prepare('
					SELECT max(meta_value + 0)
					FROM %1$s
					LEFT JOIN %2$s ON %1$s.ID = %2$s.post_id
					WHERE meta_key =\'%3$s\'
					AND (
						%1$s.ID IN (' . implode(',', array_map('absint', WC()->query->layered_nav_product_ids)) . ')
						OR (
							%1$s.post_parent IN (' . implode(',', array_map('absint', WC()->query->layered_nav_product_ids)) . ')
							AND %1$s.post_parent != 0
						)
					)
				', $wpdb->posts, $wpdb->postmeta, '_price'
                )));
        }


        $return['price_filter'] = array(
            "enabled" => isset($mobappSettings['price_filter_enable']) ? ($mobappSettings['price_filter_enable'] ? true : false) : true,
            "min_price" => $min,
            "max_price" => $max
        );
        if ($min == $max || $min > $max) {
            $return['price_filter']["enabled"] = false;
        }
        $return["attributes"] = array();
        $i = -1;
        foreach ($filters as $filter) {
            $terms = get_terms($filter);
            if (!empty($terms)) {
                $return["attributes"][++$i] = array(
                    "attributes" => $filter,
                    "label" => wc_attribute_label($filter),
                );
                foreach ($terms as $term) {
                    $return["attributes"][$i]["values"][] = array("term_id" => $term->term_id, "name" => $term->name, "slug" => $term->slug);
                }
            }
        }
        $timestamp = (isset($mobappSettings['REDUX_last_saved']) && !empty($mobappSettings['REDUX_last_saved'])) ? $mobappSettings['REDUX_last_saved'] : "";
        $return['version'] = "$timestamp";
        if (empty($return["attributes"]) && !$return["price_filter"]["enabled"]) $return["enabled"] = false;
        return $return;
    }

    /**
     * Get all products
     *
     * @since 2.1
     * @param string $fields
     * @param string $type
     * @param array $filter
     * @param int $page
     * @return array
     */
    public function get_products($fields = null, $type = null, $filter = array(), $page = 1)
    {
        if (!empty($type))
            $filter['type'] = $type;
        $filter['page'] = $page;
        $query = $this->query_products($filter);
        $products = array();

        foreach ($query->posts as $product_id) {
            if (!$this->is_readable($product_id))
                continue;
            $products[] = current($this->get_product($product_id, $fields, true));
        }
        $this->server->add_pagination_headers($query);
        return array('products' => $products);
    }

    /**
     * Helper method to get product post objects
     *
     * @since 2.1
     * @param array $args request arguments for filtering query
     * @return WP_Query
     */
    private function query_products($args)
    {

        // set base query arguments
        $query_args = array(
            'fields' => 'ids',
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => array(array(
                'key' => '_visibility',
                'value' => array('catalog', 'visible'),
                'compare' => 'IN'
            )),
        );

        if (!empty($args['type'])) {

            $types = explode(',', $args['type']);

            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $types,
                ),
            );

            unset($args['type']);
        }

        // relevanssi plugin fix
        remove_filter('the_posts', 'relevanssi_query');
        remove_filter('posts_request', 'relevanssi_prevent_default_request', 10);
        remove_filter('query_vars', 'relevanssi_query_vars');
        $query_args = $this->merge_query_args($query_args, $args);
        $query_args = apply_filters('appilder_woocommerce_product_query_args', $query_args);

        $return_products = new  WP_Query($query_args);
        add_filter('appilder_woocommerce_product_ids', array($this, 'woofix_attribute_searcher'),1);
        $return_products = apply_filters('appilder_woocommerce_product_ids', $return_products);
        return ($return_products);
    }

    /**
     * @param int|WP_Post $post
     * @return bool
     */
    public function is_readable($post)
    {
        return true;
    }

    /**
     * Get the product for the given ID
     *
     * @since 2.1
     * @param int $id the product ID
     * @param string $fields
     * @param bool $forList
     * @return array
     */
    public function get_product($id, $fields = null, $forList = false)
    {

        $id = $this->validate_request($id, 'product', 'read');

        if (is_wooapp_api_error($id))
            return $id;

        $product = wc_get_product($id);

        // add data that applies to every product type
        $product_data = $this->get_product_data($product, $forList);

        // add variations to variable products
        if ($product->is_type('variable') && $product->has_child()) {

            $product_data['variations'] = $this->get_variation_data($product);
            $this->product_images = array_values(array_unique($this->product_images));
            $product_data['images'] = $this->product_images;

        }

        // add the parent product data to an individual variation
        if ($product->is_type('variation')) {

            $product_data['parent'] = $this->get_product_data($product->parent);
        }

        return array('product' => apply_filters('woocommerce_mobapp_api_product_response', $product_data, $product, $fields, $this->server));
    }

    /**
     * Get standard product data that applies to every product type
     *
     * @since 2.1
     * @param WC_Product $product
     * @param bool $forlist
     * @return array
     */
    public function get_product_data($product, $forlist = false)
    {
        $short_desc = apply_filters('woocommerce_mobapp_short_description', $product->get_post_data()->post_excerpt);
        $desc = metadata_exists('post', $product->id, 'wooapp_product_desc') ? get_post_meta($product->id, 'wooapp_product_desc', true) : false;
        if (empty($desc) || $desc == false) {
            $desc = apply_filters('appilder_woocommerce_plugin_description', $product->get_post_data()->post_content);
        }
        $desc = empty($desc) ? $short_desc : $desc;
        $desc = do_shortcode($desc);
        $short_desc = do_shortcode($short_desc);
        $image = wp_get_attachment_image_src(get_post_thumbnail_id($product->is_type('variation') ? $product->variation_id : $product->id),'medium');
        $image = $image[0];
        if ($forlist) {
            $return = array(
                'title' => $product->get_title(),
                'id' => (int)$product->is_type('variation') ? $product->get_variation_id() : $product->id,
                'type' => $product->product_type,
                'status' => $product->get_post_data()->post_status,
                'permalink' => $product->get_permalink(),
                'downloadable' => $product->is_downloadable(),
                'virtual' => $product->is_virtual(),
                'price' => self::display_price($product->get_price_html()), //$product->get_price()),
                'regular_price' => (is_a($product,'WC_Product_Variable'))?(self::price($product->get_variation_regular_price())):(self::price($product->get_regular_price())),
                'sale_price' =>!$product->is_on_sale()?null:(is_a($product,'WC_Product_Variable'))?($product->get_variation_sale_price() ? self::price($product->get_variation_sale_price()) : null):($product->get_sale_price() ? self::price($product->get_sale_price()) : null),
                'price_html' => $product->get_price_html(),
                // 'managing_stock'     => $product->managing_stock(),
                // 'stock_quantity'     => (int) $product->get_stock_quantity(),
                'in_stock' => $product->is_in_stock(),
                'purchaseable' => $product->is_purchasable(),
                'featured' => $product->is_featured(),
                'visible' => $product->is_visible(),
                'catalog_visibility' => $product->visibility,
                'on_sale' => $product->is_on_sale(),
                'shipping_required' => $product->needs_shipping(),
                'short_description' => $short_desc,
                'average_rating' => wc_format_decimal($product->get_average_rating(), 2),
                'rating_count' => (int)$product->get_rating_count(),
                // 'categories'         => wp_get_post_terms( $product->id, 'product_cat', array( 'fields' => 'names' ) ),
                // 'tags'               => wp_get_post_terms( $product->id, 'product_tag', array( 'fields' => 'names' ) ),
                'featured_src' => $image,
                //@todo Make this selectable from dashboard
                'attributes_array' => $this->get_attributes($product),
            );
        } else {
            $return = array(
                'title' => $product->get_title(),
                'id' => (int)$product->is_type('variation') ? $product->get_variation_id() : $product->id,
                // 'created_at'         => $this->server->format_datetime( $product->get_post_data()->post_date_gmt ),
                // 'updated_at'         => $this->server->format_datetime( $product->get_post_data()->post_modified_gmt ),
                'type' => $product->product_type,
                'status' => $product->get_post_data()->post_status,
                'downloadable' => $product->is_downloadable(),
                'virtual' => $product->is_virtual(),
                'permalink' => $product->get_permalink(),
                'sku' => $product->get_sku(),
                'price' => self::display_price($product->get_price_html()), //$product->get_price()),
                'regular_price' => (is_a($product,'WC_Product_Variable'))?(self::price($product->get_variation_regular_price())):(self::price($product->get_regular_price())),
                'sale_price' =>!$product->is_on_sale()?null:(is_a($product,'WC_Product_Variable'))?($product->get_variation_sale_price() ? self::price($product->get_variation_sale_price()) : null):($product->get_sale_price() ? self::price($product->get_sale_price()) : null),
                'price_html' => $product->get_price_html(),
                // 'taxable'            => $product->is_taxable(),
                // 'tax_status'         => $product->get_tax_status(),
                // 'tax_class'          => $product->get_tax_class(),
                // 'managing_stock'     => $product->managing_stock(),
                // 'stock_quantity'     => (int) $product->get_stock_quantity(),
                'in_stock' => $product->is_in_stock(),
                // 'backorders_allowed' => $product->backorders_allowed(),
                // 'backordered'        => $product->is_on_backorder(),
                // 'sold_individually'  => $product->is_sold_individually(),
                'purchaseable' => $product->is_purchasable(),
                'featured' => $product->is_featured(),
                'visible' => $product->is_visible(),
                'catalog_visibility' => $product->visibility,
                'on_sale' => $product->is_on_sale(),
                'weight' => $product->get_weight() ? WC_format_decimal($product->get_weight(), 2) : null,
                'dimensions' => array(
                    'length' => $product->length,
                    'width' => $product->width,
                    'height' => $product->height,
                    'unit' => get_option('woocommerce_mobapp_dimension_unit'),
                ),
                'shipping_required' => $product->needs_shipping(),
                'shipping_taxable' => $product->is_shipping_taxable(),
                // 'shipping_class'     => $product->get_shipping_class(),
                // 'shipping_class_id'  => ( 0 !== $product->get_shipping_class_id() ) ? $product->get_shipping_class_id() : null,
                'description' => $desc,
                'short_description' => $short_desc,
                'reviews_allowed' => ('open' === $product->get_post_data()->comment_status),
                'average_rating' => WC_format_decimal($product->get_average_rating(), 2),
                'rating_count' => (int)$product->get_rating_count(),
                // 'upsell_ids'         => array_map( 'absint', $product->get_upsells() ),
                // 'cross_sell_ids'     => array_map( 'absint', $product->get_cross_sells() ),
                // 'categories'         => wp_get_post_terms( $product->id, 'product_cat', array( 'fields' => 'names' ) ),
                // 'tags'               => wp_get_post_terms( $product->id, 'product_tag', array( 'fields' => 'names' ) ),
                'images' => $this->get_images($product),
                'featured_src' => $image,
                'attributes' => $this->get_attributes($product, true),
                'attributes_array' => $this->get_attributes($product),
                // 'downloads'          => $this->get_downloads( $product ),
                // 'download_limit'     => (int) $product->download_limit,
                // 'download_expiry'    => (int) $product->download_expiry,
                // 'download_type'      => $product->download_type,
                // 'purchase_note'      => $product->purchase_note,
                // 'total_sales'        => metadata_exists( 'post', $product->id, 'total_sales' ) ? (int) get_post_meta( $product->id, 'total_sales', true ) : 0,
                'variations' => array(),
                'parent' => array(),
                'related_products' => $this->get_related_desc($product),
                'seller_name'=>$this->get_seller_name($product)
            );
        }
        if ($product->is_type('external'))
            $return["external_product"] = array("product_url" => $product->get_product_url(), "button_text" => $product->get_button_text());
        $return = apply_filters('appilder_woocommerce_product_data',$return,$forlist);
        return $return;
    }

    /**
     * @param WC_Product $product
     * @return mixed
     */
    public function get_seller_name($product){
        $seller_name = "";
        if(class_exists("WCV_Vendors")){
            try {
                $seller_name = WCV_Vendors::get_vendor_from_product($product->id);
                if (empty($seller_name) || $seller_name < 0)
                    $seller_name = "";
                else
                    $seller_name = WCV_Vendors::get_vendor_sold_by($seller_name);
            }catch (Exception $e){
                $seller_name = "";
            }
        }
        return apply_filters("appilder_woocommerce_seller_name",$seller_name);
    }
    public static function display_price($price_html)
    {
        $price_html = preg_replace("/<del>(.*?)<\/del>/",'',$price_html);
        $price_html = preg_replace("/<span class=\"special-price\">(.*?)<\/span>/",'',$price_html);
        $price_html = strip_tags($price_html);
        $price_html = preg_replace("/&nbsp;/",' ',$price_html);
        $price_html = html_entity_decode($price_html);
        $price_html = trim($price_html);
        return  apply_filters('appilder_woocommerce_display_price',$price_html);
    }
    public static function price($price, $args = array())
    {
        $price = wc_price( $price );
        $price = strip_tags($price);
        $price = preg_replace("/&nbsp;/",' ',$price);
        $price = html_entity_decode($price);
        return  apply_filters('appilder_woocommerce_price',$price);
    }

    /**
     * Return the decimal separator for prices
     * @since  2.3
     * @return string
     */
    public static function wc_get_price_decimal_separator()
    {
        $separator = stripslashes(get_option('woocommerce_price_decimal_sep'));
        return $separator ? $separator : '.';
    }

    /**
     * Return the thousand separator for prices
     * @since  2.3
     * @return string
     */
    public static function wc_get_price_thousand_separator()
    {
        $separator = stripslashes(get_option('woocommerce_price_thousand_sep'));
        return $separator;
    }

    /**
     * Return the number of decimals after the decimal point.
     * @since  2.3
     * @return int
     */
    public static function wc_get_price_decimals()
    {
        return absint(get_option('woocommerce_price_num_decimals', 2));
    }

    /**
     * Get the attributes for a product or product variation
     *
     * @since 2.1
     * @param WC_Product|WC_Product_Variation $product
     * @param bool $old_type
     * @return array
     */
    private function get_attributes($product, $old_type = false)
    {

        $attributes = array();

        if ($product->is_type('variation')) {


            // variation attributes
            foreach ($product->get_variation_attributes() as $attribute_name => $attribute) {
                // taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
                $attributes[] = array(
                    'id' => str_replace('attribute_', '', $attribute_name),
                    'name' => html_entity_decode(wc_attribute_label(str_replace('attribute_', '', $attribute_name)),ENT_QUOTES, 'UTF-8'),
                    // ucwords( str_replace( 'attribute_', '', str_replace( 'pa_', '', $attribute_name ) ) ),
                    'option' => $attribute,
                );
            }
        } else {
            if ($product->is_type('variable'))
                $variation_attrs = $product->get_variation_attributes();
            else
                $variation_attrs = array();

            foreach ($product->get_attributes() as $attribute) {
                // taxonomy-based attributes are comma-separated, others are pipe (|) separated
                if ($attribute['is_taxonomy']) {
                    $options = wc_get_product_terms( $product->id, $attribute['name'], array( 'fields' => 'all') );
                } else
                    $options = explode('|', $product->get_attribute($attribute['name']));

                if ($old_type) {
                    foreach ($options as $key => $option) {
                        if(is_a($option,'stdClass') || is_a($option,'WP_Term')) {
                            $options[$key] = trim(esc_html(apply_filters('woocommerce_variation_option_name', $option->name)));
                        }else{
                            $options[$key] = trim(esc_html(apply_filters('woocommerce_variation_option_name', $option)));
                        }
                        $options[$key] = html_entity_decode($options[$key],ENT_QUOTES, 'UTF-8');
                    }
                } else {

                    if ($product->is_type('variable')) {
                        $variations = isset($variation_attrs[$attribute['name']]) ? $variation_attrs[$attribute['name']] : array();
                        $variations = array_map('sanitize_title', $variations);
                    } else
                        $variations = array();

                    foreach ($options as $key => $option) {
                        if(is_a($option,'stdClass') || is_a($option,'WP_Term'))
                        {
                            $slug = $option->slug;
                            $option = $option->name;
                        }else {
                            $slug = sanitize_title($option);
                        }
                        if (empty($variations))
                            $is_variation = false;
                        else
                            $is_variation = preg_grep("/" . $slug . "/i", $variations) ? true : false;
                        $options[$key] = array(
                            'id' => $slug,
                            'is_variation' => $is_variation,
                            'value' => html_entity_decode(trim(esc_html(apply_filters('woocommerce_variation_option_name', $option))),ENT_QUOTES, 'UTF-8')
                        );
                    }
                }
                $attributes[] = array(
                    'id' => sanitize_title($attribute['name']),
                    'name' => html_entity_decode(wc_attribute_label($attribute['name']), ENT_QUOTES, 'UTF-8'),
                    'position' => $attribute['position'],
                    'visible' => (bool)$attribute['is_visible'],
                    'variation' => (bool)$attribute['is_variation'],
                    'options' => $options,
                );
            }
        }
        $attributes = apply_filters('appilder_woocommerce_attributes', $attributes);
        return $attributes;
    }

    /**
     * Get the images for a product or product variation
     *
     * @since 2.1
     * @param WC_Product|WC_Product_Variation $product
     * @return array
     */
    private function get_images($product)
    {

        $images = $attachment_ids = array();

        if ($product->is_type('variation')) {
            if (has_post_thumbnail($product->get_variation_id())) {

                // add variation image if set
                $attachment_ids[] = get_post_thumbnail_id($product->get_variation_id());

            } elseif (has_post_thumbnail($product->id)) {

                // otherwise use the parent product featured image if set
                $attachment_ids[] = get_post_thumbnail_id($product->id);
            }

        } else {

            // add featured image
            if (has_post_thumbnail($product->id)) {
                $attachment_ids[] = get_post_thumbnail_id($product->id);
            }

            // add gallery images
            $attachment_ids = array_merge($attachment_ids, $product->get_gallery_attachment_ids());
        }

        // build image data
        foreach ($attachment_ids as $position => $attachment_id) {

            $attachment_post = get_post($attachment_id);

            if (is_null($attachment_post))
                continue;

            $size = apply_filters('appmaker_woocommerce_image_size','full');
            $attachment = wp_get_attachment_image_src($attachment_id, $size);

            if (!is_array($attachment))
                continue;
            $images[] = current($attachment);
            //	$images[] = array(
            //		'id'         => (int) $attachment_id,
            //		'created_at' => $this->server->format_datetime( $attachment_post->post_date_gmt ),
            //		'updated_at' => $this->server->format_datetime( $attachment_post->post_modified_gmt ),
            //		'src'        => current( $attachment ),
            //		'title'      => get_the_title( $attachment_id ),
            //		'alt'        => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            //		'position'   => $position,
            //	);
        }

        // set a placeholder image if the product has no images set
        if (empty($images)) {
            $images[] = WC_placeholder_img_src();
        }
        $images =  apply_filters('appilder_woocommerce_product_images', $images, $product);
        $this->product_images =  array_merge($this->product_images,$images);
        return $images;
    }

    public function ids_to_short_desc($ids)
    {
        foreach ($ids as $key => $id) {
            $id = wc_get_product($id);
            $ids[$key] = $this->get_product_data($id, true);
        }
        return $ids;
    }

    /**
     * @param WC_Product $product
     * @return array
     */
    public function get_related_desc($product)
    {
        $ids = array();
        $related = $product->get_related( );
        if ( sizeof( $related ) == 0 ) return $ids;

        $args = apply_filters( 'appmaker_woocommerce_related_products_args', array(
            'fields' => 'ids',
            'post_type'            => 'product',
            'ignore_sticky_posts'  => 1,
            'no_found_rows'        => 1,
            'post__in'             => $related,
            'post__not_in'         => array( $product->id )
        ) );

        $products = new WP_Query( $args );
        foreach ($products->posts as $id) {
            $id = wc_get_product($id);
            $ids[] = $this->get_product_data($id, true);
        }
        return $ids;
    }


    /**
     * Get an individual variation's data
     *
     * @since 2.1
     * @param WC_Product $product
     * @internal param \WC_Product_variation $variation
     * @return array
     */
    private function get_variation_data($product)
    {

        $variations = array();

        foreach ($product->get_children() as $child_id) {

            $variation = $product->get_child($child_id);

            if (!$variation->exists())
                continue;

            $variation = array(
                'id' => $variation->get_variation_id(),
                'created_at' => $this->server->format_datetime($variation->get_post_data()->post_date_gmt),
                'updated_at' => $this->server->format_datetime($variation->get_post_data()->post_modified_gmt),
                'downloadable' => $variation->is_downloadable(),
                'virtual' => $variation->is_virtual(),
                'permalink' => $variation->get_permalink(),
                'sku' => $variation->get_sku(),
                'price' => self::price($variation->get_price()),
                'regular_price' => self::price($variation->get_regular_price()),
                'sale_price' => !$variation->is_on_sale()?null:$variation->get_sale_price() ? self::price($variation->get_sale_price()) : null,
                'taxable' => $variation->is_taxable(),
                'tax_status' => $variation->get_tax_status(),
                'tax_class' => $variation->get_tax_class(),
                'stock_quantity' => (int)$variation->get_stock_quantity(),
                'in_stock' => $variation->is_in_stock(),
                'backordered' => $variation->is_on_backorder(),
                'purchaseable' => $variation->is_purchasable(),
                'visible' => $variation->variation_is_visible(),
                'on_sale' => $variation->is_on_sale(),
                'weight' => $variation->get_weight() ? WC_format_decimal($variation->get_weight(), 2) : null,
                'dimensions' => array(
                    'length' => $variation->length,
                    'width' => $variation->width,
                    'height' => $variation->height,
                    'unit' => get_option('woocommerce_mobapp_dimension_unit'),
                ),
                'shipping_class' => $variation->get_shipping_class(),
                'shipping_class_id' => (0 !== $variation->get_shipping_class_id()) ? $variation->get_shipping_class_id() : null,
                'image' => $this->get_images($variation),
                'attributes' => $this->get_attributes($variation),
                'downloads' => $this->get_downloads($variation),
                'download_limit' => (int)$product->download_limit,
                'download_expiry' => (int)$product->download_expiry,
            );
            $variations[] = apply_filters('appilder_woocommerce_variation_data',$variation);
        }

        return $variations;
    }

    /**
     * Get the downloads for a product or product variation
     *
     * @since 2.1
     * @param WC_Product|WC_Product_Variation $product
     * @return array
     */
    private function get_downloads($product)
    {

        $downloads = array();

        if ($product->is_downloadable()) {

            foreach ($product->get_files() as $file_id => $file) {

                $downloads[] = array(
                    'id' => $file_id, // do not cast as int as this is a hash
                    'name' => $file['name'],
                    'file' => $file['file'],
                );
            }
        }

        return $downloads;
    }

    /**
     * Get the total number of orders
     *
     * @since 2.1
     * @param string $type
     * @param array $filter
     * @return array
     */
    public function get_products_count($type = null, $filter = array())
    {

        if (!empty($type))
            $filter['type'] = $type;

        if (!current_user_can('read_private_products'))
            return new WOOAPP_API_Error('woocommerce_mobapp_api_user_cannot_read_products_count', __('You do not have permission to read the products count', 'woocommerce_mobapp'), array('status' => 401));

        $query = $this->query_products($filter);

        return array('count' => (int)$query->found_posts);
    }

    /**
     * Edit a product
     *
     * @TODO implement in 2.2
     * @param int $id the product ID
     * @param array $data
     * @return array
     */
    public function edit_product($id, $data)
    {

        $id = $this->validate_request($id, 'product', 'edit');

        if (is_wooapp_api_error($id))
            return $id;

        return $this->get_product($id);
    }

    /**
     * Delete a product
     *
     * @TODO enable along with PUT/POST in 2.2
     * @param int $id the product ID
     * @param bool $force true to permanently delete order, false to move to trash
     * @return array
     */
    public function delete_product($id, $force = false)
    {

        $id = $this->validate_request($id, 'product', 'delete');

        if (is_wooapp_api_error($id))
            return $id;

        return $this->delete($id, 'product', ('true' === $force));
    }

    /**
     * Get the reviews for a product
     *
     * @since 2.1
     * @param int $product_id the product ID to get reviews for
     * @param $comment
     * @param $rating
     * @internal param string $fields fields to include in response
     * @return array
     * @todo Addvalidation
     */

    public function add_product_review($product_id, $comment, $rating)
    {
        $product_id = $this->validate_request($product_id, 'product', 'read');
        $time = current_time('mysql');
        $user = wp_get_current_user();
        if (!is_wooapp_api_error($product_id) && $user !== false && is_numeric($rating)) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $data = array(
                'comment_post_ID' => $product_id,
                'comment_author' => $user->display_name,
                'comment_author_email' => $user->user_email,
                'comment_content' => $comment,
                'user_id' => $user->ID,
                'comment_author_IP' => '127.0.0.1',
                'comment_agent' => $user_agent,
                'comment_date' => $time,
            );
            $comment = wp_insert_comment($data);
            if ($comment !== false) {
                add_comment_meta($comment, 'rating', $rating);
                return $this->get_product_reviews($product_id);
            } else {
                return new WOOAPP_API_Error("comment_add_error", "Cannot add comment");
            }
        }
    }

    /**
     * Get the reviews for a product
     *
     * @since 2.1
     * @param int $id the product ID to get reviews for
     * @param string $fields fields to include in response
     * @return array
     */
    public function get_product_reviews($id, $fields = null)
    {

        $id = $this->validate_request($id, 'product', 'read');

        if (is_wooapp_api_error($id))
            return $id;

        $args = array(
            'post_id' => $id,
            'status' => 'approve',
        );

        $comments = get_comments($args);

        $reviews = array();

        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
            // $rating = empty($rating)?"0":$rating;
            $reviews[] = array(
                'id' => $comment->comment_ID,
                'created_at' => $this->server->format_datetime($comment->comment_date_gmt),
                'review' => $comment->comment_content,
                'rating' => $rating,
                'reviewer_name' => $comment->comment_author,
                'reviewer_email' => $comment->comment_author_email,
                'verified' => (bool)WC_customer_bought_product($comment->comment_author_email, $comment->user_id, $id),
            );
        }

        return array('product_reviews' => apply_filters('woocommerce_mobapp_api_product_reviews_response', $reviews, $id, $fields, $comments, $this->server));
    }

    /**
     * @param WP_Query $posts
     * @return array
     */
    public function woofix_attribute_searcher($posts)
    {
        if ($posts->is_search()) {
            $noIds = array(0);
            foreach ($posts->posts as $post) {
                $noIds[] = $post;
            }
            $mPosts = $this->get_post_by_custom_field($posts,$posts->query_vars['s'], $noIds);
            if ($mPosts) {
                foreach ($mPosts as $product_id) {
                    $posts->posts[] = $product_id->post_id;
                }
            }
        }
        return $posts;
    }
    /**
     * @param WP_Query $posts
     * @return array
     */
    function get_post_by_custom_field($posts,$qrval, $noIds)
    {
        global $wpdb;

        $pstArr = array();

        $noIdsQrl = implode(",", $noIds);
        $vrvspsts = $wpdb->get_results(
            "
          SELECT p.post_parent as post_id FROM $wpdb->posts as p
          join $wpdb->postmeta pm
          on p.ID = pm.post_id
          and pm.meta_value LIKE '%$qrval%'
          join $wpdb->postmeta visibility
          on p.post_parent = visibility.post_id
          and visibility.meta_key = '_visibility'
          and visibility.meta_value <> 'hidden'
          where 1
          AND p.post_parent <> 0
          and p.ID not in ($noIdsQrl)
          and p.post_status = 'publish'
          group by p.post_parent
          "
        );

        foreach ($vrvspsts as $post) {
            $noIds[] = $post->post_id;
        }

        $noIdsQrl = implode(",", $noIds);

        $rglprds = $wpdb->get_results(
            "SELECT p.ID as post_id FROM $wpdb->posts as p
        join $wpdb->postmeta pm
        on p.ID = pm.post_id
        AND pm.meta_value LIKE '%$qrval%'
        join $wpdb->postmeta visibility
        on p.ID = visibility.post_id
        and visibility.meta_key = '_visibility'
        and visibility.meta_value <> 'hidden'
        where 1
        and (p.post_parent = 0 or p.post_parent is null)
        and p.ID not in ($noIdsQrl)
        and p.post_status = 'publish'
        group by p.ID"
        );

        $pstArr = array_merge($vrvspsts, $rglprds);
        $posts->found_posts += sizeof($pstArr);
        return $pstArr;
    }
}
