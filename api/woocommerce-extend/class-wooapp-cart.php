<?php
/**
 * User: Mohammed Anees
 * Date: 5/10/14
 * Time: 8:31 PM
 */

class WOOAPP_Cart extends WC_Cart {
    function __construct(){
        parent::__construct();
        parent::init();
        define('WOOCOMMERCE_CART',true);
        $cart = get_user_meta(get_current_user_id(), '_woocommerce_persistent_cart',true);
        $current_cart =  $this->get_cart();
        if(!empty($cart['cart'])){
            foreach($cart['cart'] as $key=>$cart){
                if(!isset($current_cart[$key]))
                    $this->add_to_cart($cart['product_id'],$cart['quantity'],$cart['variation_id'],$cart['variation']);
            }
        }
        $this->check_cart_items();
        $this->persistent_cart_update();
    }

    /**
     * Returns the contents of the cart in an array.
     *
     * @return array contents of the cart
     */
    public function get_cart_api() {
        $cart = array_filter( (array) $this->cart_contents );
        $return =array();
        foreach($cart as $key=>$item){
            $item["key"] = $key;
            $variation = array();
            if(isset($item["variation"]) && is_array($item["variation"])){
                foreach($item["variation"] as $id=>$variation_value){
                    // If this is a term slug, get the term's nice name
                    if ( taxonomy_exists( esc_attr( str_replace( 'attribute_', '', $id ) ) ) ) {
                        $term = get_term_by( 'slug', $variation_value, esc_attr( str_replace( 'attribute_', '', $id ) ) );
                        if ( ! is_wp_error( $term ) && ! empty( $term->name ) ) {
                            $value = $term->name;
                        }else{
                            $value = ucwords( str_replace( '-', ' ', $variation_value) );
                        }
                    } else {
                        $value = ucwords( str_replace( '-', ' ', $variation_value) );
                    }
                    $variation[] = array(
                        "id" => str_replace('attribute_', '', $id),
                        "name"   =>  wc_attribute_label(str_replace('attribute_', '', $id)),
                        "value_id"  => $variation_value,
                        'value' => html_entity_decode(trim(esc_html(apply_filters('woocommerce_variation_option_name', $value))),ENT_QUOTES, 'UTF-8'),
                    );
                }
            }
            $item["variation"] = $variation;
            $item = array_merge($item,get_product_short_details($item["data"]));
            unset($item["data"]);
            $return[] = $item;
        }
        return $return;
    }
} 