<?php
/*
Plugin Name: WooCommerce Canada Post Webservice Method
Plugin URI: https://truemedia.ca/plugins/cpwebservice/
Description: Extends WooCommerce with Shipping Rates, Labels and Tracking from Canada Post via Webservices
Version: 1.5.12
Author: Jamez Picard
Author URI: https://truemedia.ca/

Copyright (c) 2013-2016 Jamez Picard

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED 
TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL 
THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF 
CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS 
IN THE SOFTWARE.
*/
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Check if WooCommerce is active
if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ||
   (is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins') ))) {

define('CPWEBSERVICE_VERSION', '1.5.12');

// Plugin Path
define('CPWEBSERVICE_PLUGIN_PATH', dirname(__FILE__));

// Shipping Method Init Action
add_action('woocommerce_shipping_init', 'woocommerce_cpwebservice_shipping_init', 0);

//Shipping Method Init Function
function woocommerce_cpwebservice_shipping_init() {
	if (class_exists('WC_Shipping_Method') && !class_exists('woocommerce_cpwebservice')) {
		
		// Main Class Files
	    require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/shippingmethod.php');
		require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice.php');
	
		// Add Class to woocommerce_shipping_methods filter
		function add_cpwebservice_method( $methods ) {
			$methods['woocommerce_cpwebservice'] = 'woocommerce_cpwebservice'; return $methods;
		}
		add_filter('woocommerce_shipping_methods', 'add_cpwebservice_method' );
		
		// Add packing class.
		if (!class_exists('cpwebservice_pack')){
			require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/pack.php');
		}
		// Add location class.
		if (!class_exists('cpwebservice_location')){
		    require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/location.php');
		}
	} 

}

// Checkout order details
add_action( 'woocommerce_shipping_init', 	'cpwebservice_orderdetails_init');
add_action( 'admin_init', 				    'cpwebservice_orderdetails_init');
function cpwebservice_orderdetails_init(){
	// Add orderdetails class.
	if (!class_exists('woocommerce_cpwebservice_orderdetails')){
		require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/orderdetails.php');
		require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_orderdetails.php');
	}
	$cp = new woocommerce_cpwebservice_orderdetails();
    add_action( 'woocommerce_order_add_shipping', array(&$cp, 'order_add_shipping'), 10, 3 );
	//[previous] add_action( 'woocommerce_checkout_order_processed', array(&$cp, 'order_processed') , 10, 2);
}

// Order Shipments
add_action( 'admin_init', 'cpwebservice_shipments_init');
add_action( 'wp_scheduled_delete', 'cpwebservice_shipments_init' ); // for dbcache
function cpwebservice_shipments_init(){
	// Add shipments class.
	if (!class_exists('woocommerce_cpwebservice_shipments')){
		require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/shipments.php');
		require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_shipments.php');
	}
	$cp = new woocommerce_cpwebservice_shipments();
}

// Admin Ajax actions in Shipment Settings.
if (is_admin()){
    // Ajax Validate Action
    add_action('wp_ajax_cpwebservice_validate_api_credentials', 'woocommerce_cpwebservice_validate');
    function woocommerce_cpwebservice_validate() {
    	// Load up woocommerce shipping stack.
    	do_action('woocommerce_shipping_init');
    	$shipping = new woocommerce_cpwebservice();
    	$shipping->validate_api_credentials();
    }
    
    // Ajax Rates Log Display
    add_action('wp_ajax_cpwebservice_rates_log_display', 'cpwebservice_rates_log_display');
    function cpwebservice_rates_log_display() {
    	// Load up woocommerce shipping stack. 
    	do_action('woocommerce_shipping_init');
    	$shipping = new woocommerce_cpwebservice();
    	$shipping->rates_log_display();
    }
    
    // Ajax Shipment Log Display
    add_action('wp_ajax_cpwebservice_shipment_log_display', 'cpwebservice_shipment_log_display');
    function cpwebservice_shipment_log_display() {
        // Load up woocommerce shipping stack.
        do_action('woocommerce_shipping_init');
        $shipments = new woocommerce_cpwebservice_shipments();
        $shipments->shipment_log_display();
    }
}

// Tracking Details, Init, Include Class.
if (!class_exists('woocommerce_cpwebservice_tracking')) {
	// Load Class
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/tracking.php');
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_tracking.php');
}

// Wire up tracking
add_action( 'admin_init', 'cpwebservice_load_tracking'); // Admin: Order Management
add_action( 'woocommerce_order_items_table', 'cpwebservice_load_tracking'); // Customer View Order page.. outside of admin_init.
//add_action( 'woocommerce_email_before_order_table', 'cpwebservice_load_tracking'); // Customer Completion Email.// already wired up with admin_init.
function cpwebservice_load_tracking() {
	$cp = new woocommerce_cpwebservice_tracking();
}


// Wire up plugins settings.
add_action( 'admin_init', 'cpwebservice_load_pluginsettings');
function cpwebservice_load_pluginsettings() {
	$plugin = plugin_basename(__FILE__); 
	add_filter((is_network_admin() ? 'network_admin_' : '')."plugin_action_links_$plugin", 'cpwebservice_settings_link', 10, 4 );
}
// Add settings link on plugin page
function cpwebservice_settings_link($links, $plugin_file, $plugin_data, $context) {
	global $submenu;
	$settings_actions = array();
	if (isset($submenu['woocommerce']) && in_array( 'wc-settings', wp_list_pluck( $submenu['woocommerce'], 2 ) )){ // Woo 2.1
		$settings_actions['settings'] = '<a href="admin.php?page=wc-settings&tab=shipping&section=woocommerce_cpwebservice">'.__('Settings','woocommerce-canadapost-webservice').'</a>';
	} else {
		$settings_actions['settings'] = '<a href="admin.php?page=woocommerce_settings&tab=shipping&section=woocommerce_cpwebservice">'.__('Settings','woocommerce-canadapost-webservice').'</a>';
	}
	$settings_actions['support'] = sprintf( '<a href="%s" target="_blank">%s</a>', 'https://truemedia.ca/plugins/cpwebservice/support/', __( 'Support', 'woocommerce-canadapost-webservice' ) );
	$settings_actions['review'] = sprintf( '<a href="%s" target="_blank">%s</a>', 'https://truemedia.ca/plugins/cpwebservice/reviews/', __( 'Write a Review', 'woocommerce-canadapost-webservice' ) );
	
	return array_merge( $settings_actions, $links );
}

/** Activation hook - wireup schedule to update Tracking. */
register_activation_hook( __FILE__, 'cpwebservice_activation' );
function cpwebservice_activation() {
	wp_clear_scheduled_hook( 'cpwebservice_tracking_schedule_update' );
	wp_schedule_event( time() - 18 * 60 * 60, 'daily', 'cpwebservice_tracking_schedule_update' );
	// Add a shipping class if none exist.
	$shipclasses = get_terms( 'product_shipping_class', array('hide_empty'=>0, 'fields'=>'ids') );
	if (empty($shipclasses) || count($shipclasses) == 0) {
		// Create a default shipping class 'Products'
		$term = wp_insert_term( __('Products', 'woocommerce-canadapost-webservice'), 'product_shipping_class', $args = array('slug' => 'products') );
	}
	// Check for Shipping Zones.
    if (class_exists('WC_Shipping_Zone')){
        // Used on Activation hook to update the main shipping zone to include our shipping method.
        cpwebservice_load_localisation();
        global $wpdb;
        $method_id = 'woocommerce_cpwebservice';
        $zone_id = 0; // Get "Rest of the World" zone.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d AND method_id = %s", $zone_id, $method_id ) );
        if (!$exists) {
            // Add to "Rest of the World" shipping zone.
            $zone = new WC_Shipping_Zone( $zone_id );
            if ($zone){
                $zone->add_shipping_method( $method_id );
            }
        }
    }
	
}
/** On deactivation, remove function from the scheduled action hook. */
register_deactivation_hook( __FILE__, 'cpwebservice_deactivation' );
function cpwebservice_deactivation() {
	wp_clear_scheduled_hook( 'cpwebservice_tracking_schedule_update' );
}

// Action hook, run update on tracked orders.
add_action('cpwebservice_tracking_schedule_update',  'cpwebservice_schedule_update' );
function cpwebservice_schedule_update() {
	$cp = new woocommerce_cpwebservice_tracking();
	if ($cp->options->email_tracking) {
		$cp->scheduled_update_tracked_orders();
	} 
}

// Shipping Calculator: Require postal code option
add_action('woocommerce_before_shipping_calculator', 'cpwebservice_init_require_postal_code');
function cpwebservice_init_require_postal_code() {
	add_filter('woocommerce_shipping_calculator_enable_postcode', 'cpwebservice_require_postal_code');
}
function cpwebservice_require_postal_code($enabled) {
	if ($enabled && get_option('cpwebservice_require_postal', false) == 'yes'){
		wp_enqueue_script('cpwebservice-require-postalcode', plugins_url('lib/require-postalcode.js', __FILE__), array('jquery'), '1.0', true);
		echo '<div id="calc_shipping_postcode_required" class="hidden woocommerce-info" style="display:none">'.__('Zip / Postal Code is required to calculate shipping', 'woocommerce-canadapost-webservice').'</div>';
	}
	return $enabled;
}

// Hooks filter woocommerce_cart_shipping_method_full_label to allow for better formatting.
add_filter('woocommerce_cart_shipping_method_full_label', 'cpwebservice_shipping_method_label' );
function cpwebservice_shipping_method_label($label) {
	if (get_option('woocommerce_shipping_method_format') != 'select' && strpos($label, '<span class="shipping-delivery">')===false) {
		// Update Label to have a <span> around the (Delivered by)
		$label = preg_replace('/(\('.__('Delivered by', 'woocommerce-canadapost-webservice').' [0-9\-]+\))/','<span class="shipping-delivery">$1</span>',$label);
	}
	return $label;
}

// Product shipping options 
if (is_admin() && !class_exists('woocommerce_cpwebservice_products')) {
	// Load Class
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/products.php');
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_products.php');
}

// Wire up product shipping options
add_action( 'admin_init', 'cpwebservice_load_product_options'); // Admin: Product Management
function cpwebservice_load_product_options() {
	$cp = new woocommerce_cpwebservice_products();
	$cp->method_title = __('Canada Post', 'woocommerce-canadapost-webservice');
} 

/**
 * Load Localisation
 */
add_action( 'plugins_loaded', 'cpwebservice_load_localisation');
function cpwebservice_load_localisation() {
	load_plugin_textdomain( 'woocommerce-canadapost-webservice', false, dirname(plugin_basename(__FILE__)). '/languages' );
	// Resources
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/resources.php');
	require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_resources.php');
}

// Check for Plugin updates (only pulls current version info).
if (is_admin() && !class_exists('woocommerce_cpwebservice_update')) {
    require_once(CPWEBSERVICE_PLUGIN_PATH . '/framework/update.php');
    require_once(CPWEBSERVICE_PLUGIN_PATH . '/models/woocommerce_cpwebservice_update.php');
    $update = new woocommerce_cpwebservice_update( 'https://truemedia.ca/plugins/cpwebservice/version/', __FILE__, array('version' => CPWEBSERVICE_VERSION));
}

} // End check if WooCommerce is active

