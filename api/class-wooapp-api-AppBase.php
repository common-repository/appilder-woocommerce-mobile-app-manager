<?php
/**
 * woocommerce_mobapp API Customers Class
 *
 * Handles requests to the /customers endpoint
 *
 * @author      Coffye
 * @category    API
 * @package     woocommerce_mobapp/API
 * @since       2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class WOOAPP_API_AppBase extends WOOAPP_API_Resource {

    /** @var string $base the route base */
    protected $base = '';
    public $menuTypes = array( "title"=>0,"cat"=>1, "product"=>2, "wlink"=>3, "elink"=>4, "inapp"=>5, "typecount"=>6);
    private $navIds = array();
    private $lastId=0;
    /** @var string $created_at_min for date filtering */
    private $created_at_min = null;

    /** @var string $created_at_max for date filtering */
    private $created_at_max = null;

    /**
     * Setup class, overridden to provide customer data to order response
     *
     * @since 2.1
     * @param WOOAPP_API_Server $server
     * @return \WOOAPP_API_AppBase
     */
    public function __construct( WOOAPP_API_Server $server ) {

        parent::__construct( $server );

    }

    /**
     * Register the routes for this class
     *
     * GET /customers
     * GET /customers/count
     * GET /customers/<id>
     * GET /customers/<id>/orders
     *
     * @since 2.1
     * @param array $routes
     * @return array
     */
    public function register_routes( $routes ) {
        # GET /customers/count
        $routes['/user_login'] = array(
            array( array( $this, 'user_login' ), WOOAPP_API_Server::METHOD_POST )
        );
        # GET /customers/<id>
        $routes['/menu'] = array(
            array(array( $this, 'get_menu' ), WOOAPP_API_Server::METHOD_GET )
        );
        # GET /customers/<id>/orders
        $routes['/user_register' ] = array(
            array(array( $this, 'user_register' ), WOOAPP_API_Server::METHOD_POST )
        );
        $routes['/redirect' ] = array(
            array(array( $this, 'webViewRedirect' ), WOOAPP_API_Server::METHOD_POST)
        );
        $routes['/meta' ] = array(
            array(array( $this, 'meta' ), WOOAPP_API_Server::READABLE )
        );

        $routes['/social-login' ] = array(
            array(array( $this, 'social_login' ), WOOAPP_API_Server::METHOD_POST )
        );
        return $routes;
    }

    public function social_login($type,$accessToken ) {
        $dir  = plugin_dir_path( __FILE__ );
        require_once($dir.'../inc/hybridauth/hybridauth/Hybrid/Auth.php');
        $config = $dir.'../inc/hybridauth/hybridauth/config.php';
        try{
            $hybridauth = new Hybrid_Auth( $config );
            if($type == 'google'){
                $hybridauth->storage()->set( "hauth_session.google.is_logged_in", 1 );
                $hybridauth->storage()->set( "hauth_session.google.token.access_token", $accessToken );
                $provider = $hybridauth->getAdapter("Google" );
            }elseif($type=='facebook'){
                $hybridauth->storage()->set( "hauth_session.facebook.is_logged_in", 1 );
                $hybridauth->storage()->set( "hauth_session.facebook.token.access_token", $accessToken );
                $provider = $hybridauth->getAdapter("Facebook" );
            }else{
                return  new WOOAPP_API_Error( 'invalid_type',__('Invalid type'),401);
            }
            $profile = $provider->getUserProfile();
            if(isset($profile->email) && !empty($profile->email) && is_email($profile->email)){
                $user = get_user_by('email', $profile->email);
                if(empty($user) ||  $user === false){ // Register
                    $parts = explode("@",$profile->email);
                    for($username = $parts[0],$i=1;username_exists($username);$username = $username.'_'.$i,$i++);
                    $user_id = wp_create_user( $username,wp_generate_password(), $profile->email );
                    wp_update_user(
                        array(
                            'ID'  => $user_id,
                            'first_name' => $profile->firstName,
                            'last_name' => $profile->lastName
                        )
                    );
                    add_user_meta( $user_id, '_registered_from_app', 1);
                    wp_set_current_user($user_id);
                }else{
                    wp_set_current_user( $user->ID );
                }
                apply_filters( 'woocommerce_mobapp_api_set_auth_key', wp_get_current_user());
                return $this->user_login();
            }else{
                return  new WOOAPP_API_Error( 'oauth_failed',__('Authentication failed'),401);
            }
        }catch( Exception $e ){
            return  new WOOAPP_API_Error( 'oauth_failed',__('Authentication failed : '.$e->getMessage()),401);
        }
    }

    public function meta(){
        return array(
            'timezone'			 => wc_timezone_string(),
            'currency'       	 => get_woocommerce_currency(),
            'currency_format'    => get_woocommerce_currency_symbol(),
            'tax_included'   	 => ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) ),
            'weight_unit'    	 => get_option( 'woocommerce_weight_unit' ),
            'dimension_unit' 	 => get_option( 'woocommerce_dimension_unit' ),
            'ssl_enabled'    	 => ( 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) ),
            'permalinks_enabled' => ( '' !== get_option( 'permalink_structure' ) ),
        );
    }

    public function user_register(){
        $return = array();
        $params = getapi()->server->params['POST'];
        //print_r($params);
        if(!(empty($params['user_pass']) && empty($params['user_password']))){
            $user_email = !empty($params['user_email'])?$params['user_email']:'';
            $user_name = !empty($params['user_username'])?$params['user_username']:$user_email;
            $password = empty($params['user_password'])?$params['user_pass']:$params['user_password'];
            $user_id = username_exists($user_name);
            $email_exists = false;

            $return['status'] = 1;
            if(apply_filters('applider_woocommerce_email_required',true) == true && (empty($user_email) || !is_email($user_email))){
                $return['status'] = 0;
                $return['errors'][] = array('code' => "INVALID_EMAIL",'message' => __('Invalid Email'));
            }elseif(apply_filters('applider_woocommerce_email_required',true) == true && !(empty($user_email) || !is_email($user_email))){
                $email_exists  = email_exists($user_email);
            }
            if((empty($user_name) || !validate_username($user_name))){
                $return['status'] = 0;
                $return['errors'][] = array('code' => "INVALID_USERNAME",'message' => __('Invalid Username'));
            }
            if($email_exists !== false){
                $return['status'] = 0;
                $return['errors'][] = array('code' => "EMAIL_EXISTS",'message' => __('Email already registered'));
            }
            if($user_id){
                $return['status'] = 0;
                $return['errors'][] = array('code' => "USERNAME_EXISTS",'message' => __('Username already taken'));
            }
            $return = apply_filters('appilder_woocommerce_registration_validate',$return,$params);
            if ($return['status'] ==1) {
                $user_id = wp_create_user( $user_name, $password, $user_email );
                wp_set_current_user( $user_id );
                add_user_meta( $user_id, '_registered_from_app', 1);
                do_action('appilder_woocommerce_user_registered',$user_id,$params);
                apply_filters( 'woocommerce_mobapp_api_set_auth_key', wp_get_current_user() );
                return $this->user_login();
            }
        }else{
            $return['errors'][] = array('code' => "ERROR_INCOMPLETE",'message' => __('All fields are not provided'));
            $return['status'] = 0;
        }
        return $return;
    }

    public function user_login(){
        if (function_exists('w3tc_dbcache_flush')) {
            w3tc_dbcache_flush();
        }
        $current_user = wp_get_current_user();
        if(!$current_user){
            return  new WOOAPP_API_Error( 'woocommerce_mobapp_api_auth','User not found',401);
        }
        $return = array(
            'status' => 1,
            'auth_key' => get_user_meta($current_user->data->ID, 'woocommerce_mobapp_api_user_key', true),
            'auth_secret' => get_user_meta($current_user->data->ID, 'woocommerce_mobapp_api_user_secret', true),
            'id' => $current_user->data->ID,
            'user_nicename' => $current_user->data->user_nicename,
            'user_email' => $current_user->data->user_email,
            'user_status' => $current_user->data->user_status,
            'display_name' => $current_user->data->display_name,
        );
        return apply_filters( 'woocommerce_mobapp_api_index', $return );
    }
    private function getUniqeId($slug){
        if(empty($slug) || $slug == "cat_0"){
            $id=0;
        }elseif(isset($this->navIds[$slug]))
            $id = $this->navIds[$slug];
        else
            $id=$this->navIds[$slug]=++$this->lastId;

        return $id;
    }
    public function get_menu(){
        global $mobappNavigationSettings;
        $order=0;
        $return['menus'] = array();
        if(empty($mobappNavigationSettings['nav_menu'])){
            $dir  = plugin_dir_path( __FILE__ );
            require_once($dir.'../inc/redux-framework/redux-extended/extensions/custom_field/fields/nav-menu-builder/nav_menu_builder.php');
            $mobappNavigationSettings['nav_menu'] = ReduxFramework_nav_menu_builder::get_default();
        }
        if(!empty($mobappNavigationSettings['nav_menu']) && is_array($mobappNavigationSettings['nav_menu'])) {
            foreach ($mobappNavigationSettings['nav_menu'] as $cat) {
                $id = $this->getUniqeId($cat['id']);
                $parent = $this->getUniqeId($cat['parent']);
                $type = $cat['type'];
                $return['menus'][] = array(
                    'remoteId' => (int)$id,
                    'name' => html_entity_decode($cat['label']),
                    'parent' => (int)$parent,
                    'value' => $cat['value'],
                    'taxonomy' => $cat['type'],
                    'order' => $order++,
                    'icon' => (isset($cat['media_url'])) ? $cat['media_url'] : "",
                    'menu_type' => isset($this->menuTypes[$type]) ? $this->menuTypes[$type] : null,
                );
            }
        }

        $timestamp = (isset($mobappNavigationSettings['REDUX_last_saved']) && !empty($mobappNavigationSettings['REDUX_last_saved']))?$mobappNavigationSettings['REDUX_last_saved']:"";
        $return['version'] = "$timestamp";
        return $return;
    }
    public function webViewRedirect(){
        $hash = getapi()->server->get_raw_data();
        $hash =base64_decode($hash);
        if(is_user_logged_in())
        {
            wp_set_auth_cookie(get_current_user_id());
            wp_redirect($hash);
            exit;
        }else{
            wp_redirect($hash);
        }
    }
    /**
     * Check if the current user can read users
     *
     * @since 2.1
     * @see WOOAPP_API_Resource::is_readable()
     * @param int|WP_Post $post unused
     * @return bool true if the current user can read users, false otherwise
     */
    protected function is_readable( $post ) {
        return current_user_can( 'list_users' );
    }

}
