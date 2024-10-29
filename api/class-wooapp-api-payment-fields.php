<?php
/**
 * woocommerce_mobapp API Orders Class
 *
 * Handles requests to the /orders endpoint
 *
 * @author      WooThemes
 * @category    API
 * @package     woocommerce_mobapp/API
 * @since       2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class WOOAPP_API_Payment_fields  {
    public static function className(){
        return 'WOOAPP_API_Payment_fields';
    }
    public static function init(){
        add_filter('appilder_woocommerce_payment_gateway_PayPal-Pro',array(self::className(),'PayPal_Pro'),10,2);
    }


    /**
     * @param Array $return
     * @param WC_Payment_Gateway $gateway
     * @return Array mixed
     */
    public static function PayPal_Pro($return,$gateway){
        $return['fields'] = self::parseFieldsArray(array(
            array(
                'id' => 'billing_credircard',
                'maxlength'=>19,
                'label' =>'Card Number',
            ),
            array(
                'type' => 'select',
                'id' => 'billing_cardtype',
                'label' =>'Card Type',
                'options' => array(array('id'=>'Visa','name'=>'Visa'),array('id'=>'MasterCard','name'=>'MasterCard'),array('id'=>'Discover','name'=>'Discover'),array('id'=>'Amex','name'=>'American Express'))
            ),array(
                'type' => 'select',
                'id' => 'billing_expdatemonth',
                'label' =>'Expiration Month',
                'options' => array(array('id'=>'1','name'=>'01'),array('id'=>'2','name'=>'02'),array('id'=>'3','name'=>'03'),array('id'=>'4','name'=>'04'),array('id'=>'5','name'=>'05'),array('id'=>'6','name'=>'06'),array('id'=>'7','name'=>'07'),array('id'=>'8','name'=>'08'),array('id'=>'9','name'=>'09'),array('id'=>'10','name'=>'10'),array('id'=>'11','name'=>'11'),array('id'=>'12','name'=>'12'))
            ),array(
                'type' => 'select',
                'id' => 'billing_expdateyear',
                'label' =>'Expiration Year',
                'options' => array(array('id'=>'2016','name'=>'2016'),array('id'=>'2017','name'=>'2017'),array('id'=>'2018','name'=> '2018'),array('id'=>'2019','name'=>'2019'),array('id'=>'2020','name'=>'2020'),array('id'=>'2021','name'=>'2021'),array('id'=>'2022','name'=>'2022'),array('id'=>'2023','name'=>'2023'))
            ),array(
                'type' => 'number',
                'id' => 'billing_ccvnumber',
                'label' =>'Card Verification Number (CVV)',
            )));
        return $return;
    }

    public static function parseFieldsArray($args_array){
        $return = array();
        foreach($args_array as $args){
            $return[] = self::parseFields($args);
        }
        return $return;
    }
    public static function parseFields($args){
        $defaults = array(
            'type'              => 'text',
            'id'                => '',
            'label'             => '',
            'description'       => '',
            'placeholder'       => '',
            'maxlength'         => 0,
            'required'          => false,
            'options'           => array(),
            'validate'          => array(),
            'default_value' => '',
        );
        $args = wp_parse_args( $args, $defaults );
        return $args;
    }
}
