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

class WOOAPP_API_Checkout_fields  {
    public static $function_kecamatan;
    public static $function_kabupaten;
    public static function className(){
        return 'WOOAPP_API_Checkout_fields';
    }

    public static function init(){
        if(function_exists("get_list_of_kecamatan") || function_exists("epeken_get_list_of_kecamatan")) {
            add_filter('appilder_woocommerce_checkout_field', array(self::className(), 'indonesia_field'), 10, 1);
            add_filter('appilder_woocommerce_shipping_countries',
                array(self::className(), 'indonesia_country_override'), 10, 1);

            if(function_exists("epeken_get_list_of_kecamatan")){
                self::$function_kabupaten = "epeken_get_list_of_kota_kabupaten";
                self::$function_kecamatan = "epeken_get_list_of_kecamatan";
            }else{
                self::$function_kabupaten = "get_list_of_kota_kabupaten";
                self::$function_kecamatan = "get_list_of_kecamatan";
            }
        }
    }


    /**
     * @param $args
     * @return Array mixed
     */
    public static function indonesia_field($args){
        if ($args['id'] == 'billing_city' || $args['id'] == 'shipping_city') {
            $args['type'] = 'country';
            $args['default_value'] = '';
        }

        if ($args['id'] == 'billing_address_2' || $args['id'] == 'shipping_address_2') {
            $args['type'] = 'state';
            $args['default_value'] = '';
        }

        if ($args['id'] == 'billing_country' || $args['id'] == 'shipping_country') {
            $args['type'] = 'select';
        }

        if ($args['id'] == 'billing_state' || $args['id'] == 'shipping_state') {
            $args['type'] = 'select';
            if(empty($args['options'])){
                if(function_exists("epeken_get_all_provinces")){
                    $args['options'] =  array(
                        array("id" => ""  ,"name" =>"Pilih sebuah provinsi"),
                        array("id" => "AC","name" =>"Daerah Istimewa Aceh"),
                        array("id" => "SU","name" =>"Sumatera Utara"),
                        array("id" => "SB","name" =>"Sumatera Barat"),
                        array("id" => "RI","name" =>"Riau"),
                        array("id" => "KR","name" =>"Kepulauan Riau"),
                        array("id" => "JA","name" =>"Jambi"),
                        array("id" => "SS","name" =>"Sumatera Selatan"),
                        array("id" => "BB","name" =>"Bangka Belitung"),
                        array("id" => "BE","name" =>"Bengkulu"),
                        array("id" => "LA","name" =>"Lampung"),
                        array("id" => "JK","name" =>"DKI Jakarta"),
                        array("id" => "JB","name" =>"Jawa Barat"),
                        array("id" => "BT","name" =>"Banten"),
                        array("id" => "JT","name" => "Jawa Tengah"),
                        array("id" => "JI","name" =>"Jawa Timur"),
                        array("id" => "YO","name" =>"Daerah Istimewa Yogyakarta"),
                        array("id" => "BA","name" =>"Bali"),
                        array("id" => "NB","name" =>"Nusa Tenggara Barat"),
                        array("id" => "NT","name" =>"Nusa Tenggara Timur"),
                        array("id" => "KB","name" =>"Kalimantan Barat"),
                        array("id" => "KT","name" =>"Kalimantan Tengah"),
                        array("id" => "KI","name" =>"Kalimantan Timur"),
                        array("id" => "KS","name" =>"Kalimantan Selatan"),
                        array("id" => "KU","name" =>"Kalimantan Utara"),
                        array("id" => "SA","name" =>"Sulawesi Utara"),
                        array("id" => "ST","name" =>"Sulawesi Tengah"),
                        array("id" => "SG","name" =>"Sulawesi Tenggara"),
                        array("id" => "SR","name" =>"Sulawesi Barat"),
                        array("id" => "SN","name" =>"Sulawesi Selatan"),
                        array("id" => "GO","name" =>"Gorontalo"),
                        array("id" => "MA","name" =>"Maluku"),
                        array("id" => "MU","name" =>"Maluku Utara"),
                        array("id" => "PA","name" =>"Papua"),
                        array("id" => "PB","name" =>"Papua Barat")
                    );
                    $args['default_value'] = "";
                }else {
                    $args['options'] = array(array("id" => "", "name" => $args["label"]));
                    $args['default_value'] = "";
                }
            }
        }
        return $args;
    }

    public function indonesia_country_override($return){
        $countries = call_user_func(self::$function_kabupaten);
        $i = -1;
        foreach($countries as $key=>$country) {
            $return['countries'][++$i]=array("id"=>$key,"name"=>html_entity_decode($country),"states"=>array());
            $states = call_user_func(self::$function_kecamatan,$key);
            if(is_array($states)){
                foreach($states as $key1=>$state) {
                    $return['countries'][$i]["states"][] = array("id"=>$key1,"name"=>html_entity_decode($state));
                }
            }
        }
        return $return;
    }
}
