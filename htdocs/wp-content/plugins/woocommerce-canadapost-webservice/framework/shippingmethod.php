<?php
/*
 Main Shipping Method Webservice Class
 woocommerce_cpwebservice.php

Copyright (c) 2013-2016 Jamez Picard

*/
abstract class cpwebservice_shippingmethod extends WC_Shipping_Method
{	

    /**
     * __construct function.
     *
     * @access public
     * @return woocommerce_cpwebservice
     */
    function __construct($instance_id = 0) {
        
        $this->init($instance_id);
    }
    
    /* Instance id */
    public $instance_id = 0;
    /** logging */
    public $log;
    
    /** options */
    public $options;
    
    /** services array */
    public $services;
    
    public $available_services;
    
    // Service array data
    protected $service_groups;
    protected $service_boxes;
    protected $service_descriptions;
    protected $service_labels;
    
    
    public $packagetypes;
    
    /**
     * init function.
     *
     * @access public
     * @return void
     */
    function init($instance_id = 0) {
        $this->id			      = 'woocommerce_cpwebservice';
        $this->instance_id        = absint( $instance_id );
        $this->method_title 	  = $this->get_resource('method_title');
        $this->method_description = $this->get_resource('method_description');
        $this->supports           = array('shipping-zones', 'settings', 'instance-settings'); // 'instance-settings-modal' 
         
        $default_options = (object) array('enabled'=>'no', 'title'=>$this->method_title, 'api_user'=>'', 'api_key'=>'','account'=>'','contractid'=>'','source_postalcode'=>'','mode'=>'live', 'prefer_service'=>false, 'geolocate_origin'=>true, 'geolocate_limit'=>false, 'packagetype'=>'',
            'delivery'=>'', 'delivery_guarantee'=>false, 'delivery_label'=>'', 'margin'=>'', 'margin_value'=>'', 'packageweight'=>floatval('0.02'), 'boxes_enable'=> false, 'boxes_switch'=>true, 'lettermail_enable'=> false, 'rules_enable'=>false, 'volumetric_weight'=>true, 'product_shipping_options' => true, 'weight_only_enabled'=> true,
            'shipping_tracking'=> true, 'email_tracking'=> true, 'log_enable'=>false,'lettermail_limits'=>false,'lettermail_maxlength'=>'','lettermail_maxwidth'=>'','lettermail_maxheight'=>'','lettermail_override_weight'=>false,'lettermail_packageweight'=>'', 'lettermail_exclude_tax'=> false, 'tracking_icons'=> true, 'display_required_notice'=>true,
            'shipments_enabled'=> true, 'shipment_mode' => 'dev', 'shipment_log'=>false, 'api_dev_user'=>'', 'api_dev_key'=>'', 'display_units'=>'cm', 'display_weights'=>'kg', 'delivery_format'=> '', 'availability'=>'', 'availability_countries'=>'', 'template_package'=>true,'template_customs'=>true,'shipment_hscodes'=>false
        );
        $default_options->volumetric_weight = $this->get_resource('volumetric_weight_default');
        $this->options		  = get_option('woocommerce_cpwebservice', $default_options);
        $this->options		  =	(object) array_merge((array) $default_options, (array) $this->options); // ensure all keys exist, as defined in default_options.
        $this->enabled		  = $this->options->enabled;
        $this->title 		  = $this->options->title;
        $this->availability   = $this->options->availability; // used by parent class WC_Shipping_Method.is_available( array $package )
        $this->countries      = !empty($this->options->availability_countries) ? explode(',', $this->options->availability_countries) : array();
        $this->boxes		  = get_option('woocommerce_cpwebservice_boxes');
        $this->services		  = get_option('woocommerce_cpwebservice_services', array());
        $this->lettermail	  = get_option('woocommerce_cpwebservice_lettermail', array());
        $this->shipment_address=get_option('woocommerce_cpwebservice_shipment_address', array());
        $this->rules		  = get_option('woocommerce_cpwebservice_rules', array());
        $this->service_labels = get_option('woocommerce_cpwebservice_service_labels', array());
        $this->packagetypes   = array();
        $this->log 			  = (object) array('cart'=>array(),'params'=>array(),'request'=>array('http'=>'','service'=>''),'rates'=>array());
        // Display Units (only in/lb and cm/kg supported).
        $dimension_unit                = get_option( 'woocommerce_dimension_unit' );
        $this->options->display_units  = $dimension_unit == 'in' ? 'in' : 'cm';
        $weight_unit                   = get_option( 'woocommerce_weight_unit' );
        $this->options->display_weights= $weight_unit == 'lbs' ? 'lbs' : 'kg';
    
        // Defined Services
        $this->init_available_services();
    
        // Actions
        add_action('woocommerce_update_options_shipping_' . $this->id, array(&$this, 'process_admin_options'));
        // Admin only-scripts
        if (is_admin()){
            add_action( 'admin_enqueue_scripts', array(&$this, 'enqueue_scripts') );
            wp_enqueue_style( 'cpwebservice_woocommerce_admin' , plugins_url( 'framework/lib/admin.css' , dirname(__FILE__) ) );
        }
    }
    
    function enqueue_scripts() {
        wp_enqueue_script( 'cpwebservice_admin_settings' ,plugins_url( 'framework/lib/admin-settings.js' , dirname(__FILE__) ) , array( 'jquery' ), '1.3');
        wp_localize_script( 'cpwebservice_admin_settings', 'cpwebservice_admin_settings', array( 'confirm'=>__('Are you sure you wish to delete?', 'woocommerce-canadapost-webservice') ) );
    }
    
    /*
     * Return resources
     */
    abstract function get_resource($id);
    
    /*
     * Defined Services
     * Populate $this->available_services array.
    */
    abstract public function init_available_services();
    
    /*
     * Return destination Label (ie. Canada, USA, International) from Service code.
    */
    abstract public function get_destination_from_service($service_code);
    
    /*
     * Return 2-char Country Code (CA, US, ZZ) ZZ is international from Service code.
     */
    abstract public function get_destination_country_code_from_service($service_code);
    
    function admin_options() {
        global $woocommerce;
        ?>
    		<?php // security nonce
    		  wp_nonce_field(plugin_basename(__FILE__), 'cpwebservice_options_noncename'); 
    		?>
    		<h3><?php echo $this->get_resource('method_title'); ?></h3>
    		<div><img src="<?php echo plugins_url( $this->get_resource('method_logo_url') , dirname(__FILE__) ); ?>" /></div>
    	    <h2 id="cpwebservice_tabs" class="nav-tab-wrapper woo-nav-tab-wrapper">
			<a href="#cpwebservice_settings" class="nav-tab nav-tab-active" id="cpwebservice_settings_tab"><?php _e('Settings', 'woocommerce-canadapost-webservice') ?></a>
			<a href="#cpwebservice_services" class="nav-tab" id="cpwebservice_services_tab"><?php _e('Shipping Rates / Boxes', 'woocommerce-canadapost-webservice') ?></a>
			<a href="#cpwebservice_flatrates" class="nav-tab" id="cpwebservice_flatrates_tab"><?php _e('Lettermail/Flat Rates', 'woocommerce-canadapost-webservice') ?></a>
			<a href="#cpwebservice_shipments" class="nav-tab <?php echo $this->get_resource('display_shipmentstab');?>" id="cpwebservice_shipments_tab"><?php _e('Shipment Labels', 'woocommerce-canadapost-webservice') ?></a>
			<a href="#cpwebservice_tracking" class="nav-tab" id="cpwebservice_tracking_tab"><?php _e('Tracking', 'woocommerce-canadapost-webservice') ?></a>
			</h2>
			<div class="cpwebservice_panel" id="cpwebservice_settings">
			<h3><?php echo $this->get_resource('method_title').' '; _e('Settings', 'woocommerce-canadapost-webservice') ?></h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Enable/Disable', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
						<fieldset><legend class="screen-reader-text"><span><?php _e('Enable/Disable', 'woocommerce-canadapost-webservice') ?></span></legend>
								<label for="woocommerce_cpwebservice_enabled">
								<input name="woocommerce_cpwebservice_enabled" id="woocommerce_cpwebservice_enabled" type="checkbox" value="1" <?php checked($this->options->enabled=='yes'); ?> /> <?php printf(__('Enable %s Webservice', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title')) ?></label><br />
							</fieldset>
						<fieldset class="<?php echo $this->get_resource('display_shipmentstab')?>"><legend class="screen-reader-text"><span><?php _e('Enable/Disable', 'woocommerce-canadapost-webservice') ?></span></legend>
								<label for="woocommerce_cpwebservice_shipments_enabled">
								<input name="woocommerce_cpwebservice_shipments_enabled" id="woocommerce_cpwebservice_shipments_enabled" type="checkbox" value="1" <?php checked($this->options->shipments_enabled==true); ?> /> <?php _e('Enable Creation of Shipment Labels', 'woocommerce-canadapost-webservice') ?></label><br />
							</fieldset>
						<fieldset>
						<label for="woocommerce_cpwebservice_shipping_tracking"><input name="woocommerce_cpwebservice_shipping_tracking" id="woocommerce_cpwebservice_shipping_tracking" type="checkbox" value="1" <?php checked($this->options->shipping_tracking==true); ?>  /> <?php printf(__('Enable %s Tracking number feature on Orders', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title')) ?></label><br />
						</fieldset>
					</td>
				    </tr>
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Method Title', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
						<input type="text" name="woocommerce_cpwebservice_title" id="woocommerce_cpwebservice_title" style="min-width:50px;" value="<?php echo esc_attr($this->options->title); ?>" /> <span class="description"><?php _e('This controls the title which the user sees during checkout.', 'woocommerce-canadapost-webservice') ?></span>
					</td>
				    </tr>
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Webservice Account Settings', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					    <div class="<?php echo $this->get_resource('display_customeraccount')?>">
					    <p><strong><?php _e('Customer Account Number', 'woocommerce-canadapost-webservice') ?>:</strong></p>
						<input type="text" name="woocommerce_cpwebservice_account" id="woocommerce_cpwebservice_account" style="min-width:50px;" value="<?php echo esc_attr($this->options->account); ?>" /> 
                        </div>
						<p><input type="radio" class="woocommerce_cpwebservice_contractid_button" name="woocommerce_cpwebservice_accounttype" id="woocommerce_cpwebservice_accounttype_0" value="0" <?php checked(empty($this->options->contractid)); ?> />
						<label for="woocommerce_cpwebservice_accounttype_0"> <?php _e('Personal/Small Business Customer','woocommerce-canadapost-webservice')?></label> &nbsp; 
						<input type="radio" class="woocommerce_cpwebservice_contractid_button" name="woocommerce_cpwebservice_accounttype" id="woocommerce_cpwebservice_accounttype_1" value="1" <?php checked(!empty($this->options->contractid)); ?> />
						<label for="woocommerce_cpwebservice_accounttype_1"> <?php _e('Commercial/Contract Customer','woocommerce-canadapost-webservice')?></label></p>  
						<?php //_e('Add Contract ID (Optional, Only if a Contract Customer)', 'woocommerce-canadapost-webservice')?>
						<div id="woocommerce_cpwebservice_contractid_display" style="<?php echo (!empty($this->options->contractid) ? "":"display:none"); ?>">
						<input type="text" name="woocommerce_cpwebservice_contractid" id="woocommerce_cpwebservice_contractid" style="min-width:50px;" value="<?php echo esc_attr($this->options->contractid); ?>" /> <span class="description"><?php _e('Contract ID (Optional, Only if a Contract Customer)', 'woocommerce-canadapost-webservice') ?></span>
						<br /></div>
						<p><strong><?php _e('Production Credentials', 'woocommerce-canadapost-webservice')?></strong></p>
						<input type="text" name="woocommerce_cpwebservice_api_user" id="woocommerce_cpwebservice_api_user" style="min-width:50px;" value="<?php echo esc_attr($this->options->api_user); ?>" /> <span class="description"><?php _e('API Username', 'woocommerce-canadapost-webservice') ?></span>
						<br />
						<div class="<?php echo $this->get_resource('display_apikey')?>">
						<input type="password" name="woocommerce_cpwebservice_api_key" id="woocommerce_cpwebservice_api_key" style="min-width:50px;" value="<?php echo esc_attr($this->options->api_key); ?>" /> <span class="description"><?php _e('API Password/Key', 'woocommerce-canadapost-webservice') ?></span>
						<br /></div>
						<div><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_validate_api_credentials&mode=live' ), 'cpwebservice_validate_api_credentials' ); ?>" id="woocommerce_cpwebservice_validate_btn" class="button canadapost-validate"><?php _e('Validate Credentials', 'woocommerce-canadapost-webservice') ?></a> <div class="cpwebservice_ajaxupdate canadapost-spinner" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div><br /></div>							
						<div id="woocommerce_cpwebservice_validate" class="widefat" style="display:none;"><a href="#" class="button button-secondary canadapost-validate-close"><span class="dashicons dashicons-no"></span></a><p></p></div>
					</td>
				    </tr>
				    <tr valign="top" class="woocommerce-canadapost-development-api <?php if($this->options->mode!='dev' && $this->options->shipment_mode!='dev'){ echo 'hidden'; }  ?>">
				    <th><?php _e('Development', 'woocommerce-canadapost-webservice') ?></th>
				    <td>
				    <p><strong><?php _e('Development Credentials', 'woocommerce-canadapost-webservice') ?></strong></p>
				    <input type="text" name="woocommerce_cpwebservice_api_dev_user" id="woocommerce_cpwebservice_api_dev_user" style="min-width:50px;" value="<?php echo esc_attr($this->options->api_dev_user); ?>" /> <span class="description"><?php _e('Development', 'woocommerce-canadapost-webservice') ?> <?php _e('API Username', 'woocommerce-canadapost-webservice') ?></span>
						<br />
						<div class="<?php echo $this->get_resource('display_apikey')?>">
						<input type="password" name="woocommerce_cpwebservice_api_dev_key" id="woocommerce_cpwebservice_api_dev_key" style="min-width:50px;" value="<?php echo esc_attr($this->options->api_dev_key); ?>" /> <span class="description"><?php _e('Development', 'woocommerce-canadapost-webservice') ?> <?php _e('API Password/Key', 'woocommerce-canadapost-webservice') ?></span>
						<br /></div>
						<div><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_validate_api_credentials&mode=dev' ), 'cpwebservice_validate_api_credentials' ); ?>" id="woocommerce_cpwebservice_validate_dev_btn" class="button canadapost-validate-dev"><?php _e('Validate Credentials', 'woocommerce-canadapost-webservice') ?></a> <div class="cpwebservice_ajaxupdate_dev canadapost-spinner" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div><br /></div>							
						<div id="woocommerce_cpwebservice_validate_dev" class="widefat" style="display:none;"><a href="#" class="button button-secondary canadapost-validate-close"><span class="dashicons dashicons-no"></span></a><p></p></div>
				       </td>
				    </tr>
				    <tr valign="top">
				    <th scope="row" class="titledesc"><?php _e('Webservice API Mode', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
						<fieldset><legend class="screen-reader-text"><span><?php _e('Webservice API Mode', 'woocommerce-canadapost-webservice') ?></span></legend>
					  <?php _e('Rates Lookup / Tracking', 'woocommerce-canadapost-webservice') ?>: &nbsp;
								<select name="woocommerce_cpwebservice_mode" class="canadapost-mode canadapost-mode-rates">
									<option value="dev"<?php if ($this->options->mode=='dev') echo 'selected="selected"'; ?>><?php _e('Development', 'woocommerce-canadapost-webservice') ?></option>
									<option value="live" <?php if ($this->options->mode=='live') echo 'selected="selected"'; ?>><?php _e('Production/Live', 'woocommerce-canadapost-webservice') ?></option>
								</select>
								<p class="canadapost-mode-rates-dev-msg <?php if ($this->options->mode!='dev'){ echo ' hidden'; } ?>">
								   <strong><span class="dashicons dashicons-info"></span> <?php _e('Test Mode', 'woocommerce-canadapost-webservice') ?>:</strong> <?php _e('Rates will not reflect actual account prices. Tracking disabled.', 'woocommerce-canadapost-webservice')?>
								</p>
								<p class="canadapost-mode-rates-live-msg <?php if ($this->options->mode!='live'){ echo ' hidden'; } ?>">
								   <strong><span class="dashicons dashicons-flag"></span>  <?php _e('Live Mode', 'woocommerce-canadapost-webservice') ?>:</strong> <?php _e('Rates reflect your account prices. Package Tracking is available.','woocommerce-canadapost-webservice')?>
								</p>
								</fieldset>
							<br />
								<fieldset class="<?php echo $this->get_resource('display_shipmentstab')?>"><legend class="screen-reader-text"><span><?php _e('Development Mode', 'woocommerce-canadapost-webservice') ?></span></legend>
								<?php _e('Shipment Labels', 'woocommerce-canadapost-webservice') ?>: 
								<select name="woocommerce_cpwebservice_shipment_mode" class="canadapost-mode canadapost-mode-shipment">
									<option value="dev"<?php if ($this->options->shipment_mode=='dev') echo 'selected="selected"'; ?>><?php _e('Development', 'woocommerce-canadapost-webservice') ?></option>
									<option value="live" <?php if ($this->options->shipment_mode=='live') echo 'selected="selected"'; ?>><?php _e('Production/Live', 'woocommerce-canadapost-webservice') ?></option>
								</select>
								<p class="canadapost-mode-shipment-dev-msg <?php if ($this->options->shipment_mode!='dev'){ echo ' hidden'; } ?>">
								     <strong><span class="dashicons dashicons-info"></span> <?php _e('Test Mode', 'woocommerce-canadapost-webservice') ?>:</strong> <?php _e('Only test labels will be created', 'woocommerce-canadapost-webservice')?>.
								 </p>
								<p class="canadapost-mode-shipment-live-msg <?php if ($this->options->shipment_mode!='live'){ echo ' hidden'; } ?>">
								     <strong><span class="dashicons dashicons-flag"></span> <?php _e('Live Mode', 'woocommerce-canadapost-webservice')?>:</strong> <?php _e('Paid shipping labels can be created and will be billed to your account', 'woocommerce-canadapost-webservice')?>.
								</p>
								</fieldset>
					</td>
				    </tr>
				    <tr valign="top" class="woocommerce-canadapost-shipment-address">
					<th scope="row" class="titledesc"><?php _e('Sender Address', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
						<div id="cpwebservice_address">
						<?php 
						$postal_array = array();
						if (empty($this->shipment_address) || !is_array($this->shipment_address) || count($this->shipment_address) == 0){
						    // Init shipment_address array. (min 1 element)
						    $this->shipment_address = array(array('default'=>true,'postalcode'=>$this->options->source_postalcode,'contact'=>'','phone'=>'', 'address'=>'','address2'=>'','city'=>'','prov'=>'','country'=>'', 'origin'=>true, 'postalcode_lat'=>0, 'postalcode_lng'=>0));
						}
						$address_defaults = array('default'=>false,'contact'=>'','phone'=>'','postalcode'=>'','address'=>'','address2'=>'','city'=>'','prov'=>'','country'=>'', 'origin'=>false, 'postalcode_lat'=>0, 'postalcode_lng'=>0);
						?>
						<?php for($i=0;$i<count($this->shipment_address); $i++): ?>
						<?php $address = (is_array($this->shipment_address[$i]) ? array_merge($address_defaults, $this->shipment_address[$i]) : array()); ?>
						 <div class="cpwebservice_address_item">
						 <h4 class="titledescr"> <?php _e('Sender Address', 'woocommerce-canadapost-webservice') ?></h4>
						 <span style="float:right;" class="canadapost-remove-btn<?php if ($i==0):?> hidden<?php endif;?>"><a href="javascript:;" title="Remove" class="canadapost-address-remove button"><?php _e('Remove','woocommerce-canadapost-webservice'); ?></a></span>
						 <span class="description"><?php _e('Source address to be used for Rates lookup and Sender Address to be printed on Shipment label', 'woocommerce-canadapost-webservice') ?></span>
						 <br />
						 <br />
						 <p><strong><?php _e('Sender Zip/Postal Code', 'woocommerce-canadapost-webservice') ?></strong> <span class="description">(*<?php _e('Required', 'woocommerce-canadapost-webservice') ?>)</span></p>
						 <input type="text" name="woocommerce_cpwebservice_shipment_postalcode[]" id="woocommerce_cpwebservice_shipment_postalcode<?php echo $i;?>" class="canadapost-shipment-postal" data-postaltype="<?php echo $this->get_resource('origin_postal_format'); ?>" style="min-width:50px;" placeholder="<?php echo $this->get_resource('origin_postal_placeholder'); ?>" value="<?php echo esc_attr($address['postalcode']); ?>" /> 
						 <?php if (!empty($address['postalcode_lat']) && !empty($address['postalcode_lng'])) : ?><span class="description canadapost-hide-new"><?php _e('Approximate Latitude/Longitude','woocommerce-canadapost-webservice') ?>: (<?php echo esc_html($address['postalcode_lat']) ?>,<?php echo esc_html($address['postalcode_lng']) ?>)</span><?php endif; ?>
						 <div id="woocommerce_cpwebservice_shipment_postalcode<?php echo $i;?>_error" style="display:none;background-color: #fffbcc;padding:5px;border-color: #e6db55;"><p><?php echo $this->get_resource('postalcode_warning'); ?></p></div>
						 <div class="canadapost-postal-requires-one" style="display:none;background-color: #fffbcc;padding:5px;border-color: #e6db55;"><p><?php _e('Rates lookup require an Origin Postal Code', 'woocommerce-canadapost-webservice') ?>.</p></div>
						 <?php if (count($this->shipment_address) == 1) { $address['origin'] = true; } ?>
						 <br /><label class="canadapost-postalcode-origin-label"><input name="woocommerce_cpwebservice_shipment_postalcode_origin[]" type="checkbox" value="<?php echo $i;?>" <?php checked($address['origin']==true); ?> class="canadapost-postalcode-origin"  /> <strong><?php _e('Use as Origin Postal Code for Rates Lookup', 'woocommerce-canadapost-webservice') ?></strong></label>
						 <br />
						 <br />
						<?php _e('Sender Contact Name/Company', 'woocommerce-canadapost-webservice') ?><br />
						<input type="text" name="woocommerce_cpwebservice_shipment_contact[]" id="woocommerce_cpwebservice_shipment_contact<?php echo $i;?>" style="min-width:50px;" value="<?php echo esc_attr($address['contact']); ?>" /> 
						<br />
						<?php _e('Contact Phone', 'woocommerce-canadapost-webservice') ?><br />
						<input type="text" name="woocommerce_cpwebservice_shipment_phone[]" id="woocommerce_cpwebservice_shipment_phone<?php echo $i;?>" style="min-width:50px;" value="<?php echo esc_attr($address['phone']); ?>" />
						<br />
						 <?php _e('Address', 'woocommerce-canadapost-webservice') ?><br />
						 <input type="text" name="woocommerce_cpwebservice_shipment_address[]" id="woocommerce_cpwebservice_shipment_address<?php echo $i;?>" style="min-width:50px;" value="<?php echo esc_attr($address['address']); ?>" />
						 <br />
						 <?php _e('Address2', 'woocommerce-canadapost-webservice') ?><br />
						 <input type="text" name="woocommerce_cpwebservice_shipment_address2[]" id="woocommerce_cpwebservice_shipment_address2<?php echo $i;?>" style="min-width:50px;" value="<?php echo esc_attr($address['address2']); ?>" />
						 <br /> 
						 <?php _e('City', 'woocommerce-canadapost-webservice') ?><br />
						 <input type="text" name="woocommerce_cpwebservice_shipment_city[]" id="woocommerce_cpwebservice_shipment_city<?php echo $i;?>" style="min-width:50px;" value="<?php echo esc_attr($address['city']); ?>" />
						 <br /> 
						 <?php _e('State/Province', 'woocommerce-canadapost-webservice') ?><br />
						 <?php 
						 $address['country'] = !empty($address['country']) ? $address['country'] : $this->get_resource('shipment_country');
						 $shipment_states =  WC()->countries->get_states( $address['country'] );
						 ?>
						 <select name="woocommerce_cpwebservice_shipment_prov[]" id="woocommerce_cpwebservice_shipment_prov<?php echo $i;?>" <?php if (empty($shipment_states)) { echo 'style="display:none"'; } ?> class="canadapost-shipment-prov">
						    <option value="" <?php selected( '', esc_attr( $address['prov'] ) ); ?>></option>
						    <?php
    						  foreach ( (array) $shipment_states as $option_key => $option_value ) : ?>
    							<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $address['prov'] ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
    						<?php endforeach; ?>
    					</select>
    					<br />
						 <?php _e('Country', 'woocommerce-canadapost-webservice') ?><br />
						  <select name="woocommerce_cpwebservice_shipment_country[]" id="woocommerce_cpwebservice_shipment_country<?php echo $i;?>" class="canadapost-shipment-country">
						      <?php foreach ($this->get_resource('sender_shipment_countries') as $sender_country=>$sender_country_label) : ?>
						      <option value="<?php echo esc_attr($sender_country) ?>" <?php selected( $sender_country, esc_attr( $address['country'] ) ); ?>><?php echo esc_html($sender_country_label) ?></option>
						      <?php endforeach; ?>
						  </select>
						 <br />
						 <br />
						 <label><input type="radio" name="woocommerce_cpwebservice_shipment_default" value="<?php echo $i; ?>" <?php checked(true,$address['default'])?> /><?php _e('Default Sending Address', 'woocommerce-canadapost-webservice'); ?></label>
						 <br />
						 <br />
						  </div> <?php if ($address['origin']){ $postal_array[] = $address['postalcode']; } ?>
						  <?php endfor; ?>
						  </div>
						  <a href="javascript:;" id="btn_cpwebservice_address" class="button-secondary"><?php _e('Add More','woocommerce-canadapost-webservice'); ?> <span class="dashicons dashicons-plus-alt" style="margin-top:5px;"></span></a>
					</td>
				    </tr>
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Origin Postal Code', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					   <div class="canadapost-display-geolocate <?php if (count($this->shipment_address) == 1){ echo 'hidden'; } ?>">
					   <label><input name="woocommerce_cpwebservice_geolocate_origin" type="checkbox" value="1" <?php checked(true,$this->options->geolocate_origin); ?>  /><?php _e('Use multiple Origin Postal Codes for Rates Lookup', 'woocommerce-canadapost-webservice') ?></label>
					   <br />
					   <p class="description"><?php echo esc_html(__('This will use the "closest" origin postal code to the entered postal code when looking up rates. If the approximate lat/long for the postal code cannot be found, it will use the "Default Sending Address" Postal code.')) ?></p>
					   <label style="display:none"><input name="woocommerce_cpwebservice_geolocate_limit" type="checkbox" value="1" <?php checked(true,$this->options->geolocate_limit); ?>  /><?php _e('Limit Sending Address/Warehouses on Products (Enables selection on Product Edit page)', 'woocommerce-canadapost-webservice') ?></label>
					   </div>
					   <br />
					   <p><?php _e('Currently using the following Postal Code(s) as Origins when looking up rates')?>: <strong><span class="canadapost-postal-array"><?php echo esc_html(implode(',', $postal_array));?></span></strong></p>
					<div class="hidden">
						<input type="text" name="woocommerce_cpwebservice_source_postalcode" id="woocommerce_cpwebservice_source_postalcode" style="min-width:50px;" class="canadapost-postal" value="<?php echo esc_attr($this->options->source_postalcode); ?>" /> <span class="description"><?php _e('The Postal Code that items will be shipped from.', 'woocommerce-canadapost-webservice') ?></span>
						<div class="canadapost-postal-error" style="display:none;background-color: #fffbcc;padding:5px;border-color: #e6db55;"><p><?php echo $this->get_resource('postalcode_warning'); ?></p></div>
				    </div>
					</td>
				    </tr>
				  </table>
				  <table class="form-table">
				
				</table>
		</div> <!-- /#cpwebservice_settings -->
		
		<div class="cpwebservice_panel cpwebservice_hidden" id="cpwebservice_services">
		<table class="form-table"> 
		 <tr><td colspan="2" style="padding-left:0;border-bottom: 1px solid #999;">
		                 <h3><?php _e('Rates Lookup', 'woocommerce-canadapost-webservice') ?></h3>
				    </td></tr>
				     <tr valign="top">
				    <th scope="row" class="titledesc">
				    	<?php _e('Logging', 'woocommerce-canadapost-webservice')?>
				    </th>
					<td class="forminp">
					<label for="woocommerce_cpwebservice_log_enable">
								<input name="woocommerce_cpwebservice_log_enable" id="woocommerce_cpwebservice_log_enable" type="checkbox" value="1" <?php checked($this->options->log_enable=='1'); ?> /> <?php _e('Enable Rates Lookup Logging', 'woocommerce-canadapost-webservice') ?>
								<br /><small><?php _e('Captures most recent shipping rate lookup.  Recommended to be disabled when website development is complete. This option does not display any messages on frontend.', 'woocommerce-canadapost-webservice') ?></small></label>
					<?php if ($this->options->log_enable): ?>
					<div><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_rates_log_display' ), 'cpwebservice_rates_log_display' ); ?>" title="Display Log" class="button canadapost-log-display"><?php _e('Display most recent request','woocommerce-canadapost-webservice'); ?></a> 
					<div class="canadapost-spinner canadapost-log-display-loading" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div>
					<a href="#" class="button button-secondary canadapost-log-close" style="display:none"><span class="dashicons dashicons-no"></span></a>
					</div>
					<div id="cpwebservice_log_display" style="display:none;">
					<p></p>
					</div>
					<?php endif; ?> 
					</td>
					</tr>
				    <tr>
					<th scope="row" class="titledesc"><?php _e('Add Margin', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					   <p class="description"><?php echo esc_html(__('Margin can be used for Currency Conversion (ex. CAD to USD)','woocommerce-canadapost-webservice'))?>. 
					       <br /><?php echo $this->get_resource('margin_currency') ?></p>
							&nbsp; <input type="text" name="woocommerce_cpwebservice_margin" id="woocommerce_cpwebservice_margin" style="max-width:50px;" value="<?php echo esc_attr($this->options->margin); ?>" />% <span class="description"><?php _e('Add Margin Percentage (ex. 5% or -2%) to Shipping Cost', 'woocommerce-canadapost-webservice') ?></span><br />
							$<input type="text" name="woocommerce_cpwebservice_margin_value" id="woocommerce_cpwebservice_margin_value" style="max-width:50px;" value="<?php echo esc_attr($this->options->margin_value); ?>" /> &nbsp; <span class="description"><?php _e('Add Margin Amount (ex. $4 or -$1) to Shipping Cost', 'woocommerce-canadapost-webservice') ?></span>
					</td>
				    </tr>
				    <tr>
					<th scope="row" class="titledesc"><?php _e('Delivery Dates', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
								<p><label for="woocommerce_cpwebservice_delivery_hide">
								<input name="woocommerce_cpwebservice_delivery_hide" id="woocommerce_cpwebservice_delivery_hide" onclick="jQuery('#woocommerce_cpwebservice_delivery').val(this.checked?'1':'');" type="checkbox" value="1" <?php checked(!empty($this->options->delivery)); ?> /> <?php _e('Enable Estimated Delivery Dates', 'woocommerce-canadapost-webservice') ?></label>
								</p>
								<p><label for="woocommerce_cpwebservice_delivery_guarantee"><input name="woocommerce_cpwebservice_delivery_guarantee" id="woocommerce_cpwebservice_delivery_guarantee" type="checkbox" value="1" <?php checked(!empty($this->options->delivery_guarantee)); ?> /> <?php _e('Show Estimated Delivery only on Guaranteed Services', 'woocommerce-canadapost-webservice') ?></label> <span class="description"><?php echo esc_html($this->get_resource('guaranteed_services')) ?></span></p>
								<p><input type="text" name="woocommerce_cpwebservice_delivery" id="woocommerce_cpwebservice_delivery" style="max-width:50px;" value="<?php echo esc_attr($this->options->delivery); ?>" /> <span class="description"><?php _e('Number of Days to Ship after order placed.  Used to calculate date mailed.', 'woocommerce-canadapost-webservice') ?></span></p>
								<p><select name="woocommerce_cpwebservice_delivery_format">
								    <option value="" <?php selected('', $this->options->delivery_format); ?>><?php _e('Default (ex: 2020-02-29)','woocommerce-canadapost-webservice')?></option>
								    <option value="D M j, Y" <?php selected('D M j, Y', $this->options->delivery_format); ?>><?php _e('Full Date (ex: Mon Feb 29, 2020)','woocommerce-canadapost-webservice')?></option>
								    <option value="F j, Y" <?php selected('F j, Y', $this->options->delivery_format); ?>><?php _e('Date (ex: February 29, 2020)','woocommerce-canadapost-webservice')?></option>
								    <option value="M j, Y" <?php selected('M j, Y', $this->options->delivery_format); ?>><?php _e('Date (ex: Feb 29, 2020)','woocommerce-canadapost-webservice')?></option>
								</select> <span class="description"><?php _e('Date format for Delivery Estimate', 'woocommerce-canadapost-webservice') ?></span></p>
								<p><input type="text" name="woocommerce_cpwebservice_delivery_label" id="woocommerce_cpwebservice_delivery_label" placeholder="<?php _e('Delivered by', 'woocommerce-canadapost-webservice') ?>" value="<?php echo esc_attr($this->options->delivery_label); ?>" /> <span class="description"><?php _e('Label for Delivered by', 'woocommerce-canadapost-webservice') ?></span></p>
					</td>
				    </tr>
				    <tr>
				    <th scope="row" class="titledesc"><?php _e('Validation', 'woocommerce-canadapost-webservice')?> </th>
					<td class="forminp">
							<label for="woocommerce_cpwebservice_display_required_notice">
								<input name="woocommerce_cpwebservice_display_required_notice" id="woocommerce_cpwebservice_display_required_notice" type="checkbox" value="1" <?php checked(!empty($this->options->display_required_notice)); ?> /> <?php _e('Validate Zip / Postal code as required in Calculate Shipping form', 'woocommerce-canadapost-webservice') ?></label>
					</td>
				    </tr>	
				    <tr><td colspan="2" style="padding-left:0;border-bottom: 1px solid #999;">
		                 <h3><?php _e('Parcel Services', 'woocommerce-canadapost-webservice') ?></h3>
				    </td></tr>
		             <tr>
		            <tr>
				    <th scope="row" class="titledesc"><?php _e('Method Availability', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
							<select name="woocommerce_cpwebservice_availability">
							     <option value=""><?php _e('Any Country', 'woocommerce-canadapost-webservice')?></option>
								<option value="including" <?php selected('including' == $this->availability); ?>><?php _e('Only Selected countries', 'woocommerce-canadapost-webservice') ?></option>
								<option value="excluding" <?php selected('excluding' == $this->availability); ?>><?php _e('Excluding selected countries', 'woocommerce-canadapost-webservice') ?></option>
						    </select>
					</td>
				    </tr>
				    <tr>
				    <th scope="row" class="titledesc"></th>
					<td class="forminp">
							<select name="woocommerce_cpwebservice_availability_countries[]" class="widefat chosen_select" placeholder="<?php _e('Choose countries..', 'woocommerce-canadapost-webservice') ?>" multiple>
								<option value=""></option>
								<?php $r_countries = WC()->countries->get_shipping_countries(); ?>
								<?php foreach($r_countries as $country_code=>$name): ?>
								<option value="<?php echo esc_attr($country_code); ?>" <?php selected(is_array($this->countries) && in_array($country_code, $this->countries)); ?>><?php echo esc_html($name); ?></option>
								<?php endforeach;?>
						    </select>
					</td>
				    </tr>
					<th scope="row" class="titledesc"><?php _e('Enable Parcel Services', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
							<fieldset><legend class="screen-reader-text"><span><?php echo $this->get_resource('method_title') . ' '; _e('Shipping Services', 'woocommerce-canadapost-webservice') ?></span></legend>
							<?php if (empty($this->services)){ $this->services = array_keys($this->available_services);  } // set all checked as default.
								  $s=0; // service count
								  $cur_country = ' '; ?>
						    <ul>
							<?php foreach($this->available_services as $service_code=>$service_label): ?>
							<?php $s++;
							      $service_country = $this->get_destination_from_service($service_code);
								  if ($cur_country!=$service_country){ $cur_country=$service_country; echo '<li><h4>'.esc_html($service_country).'</h4></li>'; }?>
								<li><label for="woocommerce_cpwebservice_service-<?php echo $s ?>">
								<input name="woocommerce_cpwebservice_services[]" id="woocommerce_cpwebservice_service-<?php echo $s ?>" type="checkbox" value="<?php echo esc_attr($service_code) ?>" <?php checked(in_array($service_code,$this->services)); ?> /> <?php echo esc_html($service_label); ?></label>
								<?php $custom_service_label = (!empty($this->service_labels) && !empty($this->service_labels[$service_code]) && $this->service_labels[$service_code] != $service_label) ? $this->service_labels[$service_code] : ''; ?>
								<a class="button canadapost-btn-icon canadapost-service-label-edit" href="#" style="<?php echo !empty($custom_service_label) ? 'display:none' : ''; ?>" title="<?php _e('Custom Label', 'woocommerce-canadapost-webservice'); ?>"><span class="dashicons dashicons-tag"></span></a>
								<span class="canadapost-service-label-wrapper" style="<?php echo empty($custom_service_label) ? 'display:none' : ''; ?>">
								  <input name="woocommerce_cpwebservice_service_label_<?php echo $this->get_service_code_field($service_code) ?>" class="canadapost-input-lg" type="text" value="<?php echo esc_attr($custom_service_label); ?>" placeholder="<?php echo esc_attr($service_label); ?>" />
								    <a class="button canadapost-btn-icon canadapost-service-label-remove" href="#" title="<?php _e('Remove', 'woocommerce-canadapost-webservice'); ?>"><span class="dashicons dashicons-no-alt"></span></a>
								</span>
								<?php if (!empty($this->service_descriptions) && is_array($this->service_descriptions) && !empty($this->service_descriptions[$service_code])): ?>
								<small class="description canadapost-service-description"><?php echo esc_html($this->service_descriptions[$service_code]); ?></small>
								<?php endif; ?>
								</li>
							<?php endforeach; ?>
							</ul>
							</fieldset>
					</td>
				    </tr>
				    <?php if (!empty($this->packagetypes)): ?>
				    <tr>
					    <th scope="row" class="titledesc"><?php _e('Package Type', 'woocommerce-canadapost-webservice') ?></th>
						<td class="forminp">
								<select name="woocommerce_cpwebservice_packagetype" class="canadapost-packagetype">
								<?php foreach($this->packagetypes as $key=>$type): ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($key == $this->options->packagetype); ?>><?php echo esc_html($type); ?></option>
								<?php endforeach;?>
								</select> 
								<p class="description"><?php _e('Packaging used with Parcel Services', 'woocommerce-canadapost-webservice') ?></p>
						</td>
					</tr>
					<?php endif; ?>
				    <tr>
				    <th scope="row" class="titledesc"><?php _e('Parcel Services', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
							<p><label for="woocommerce_cpwebservice_prefer_service">
							<input name="woocommerce_cpwebservice_prefer_service" id="woocommerce_cpwebservice_prefer_service" type="checkbox" value="1" <?php checked(!empty($this->options->prefer_service)); ?> /> <?php _e('If services are the same cost, only display the better service.', 'woocommerce-canadapost-webservice') ?></label>
							<br />
							<span class="description"><?php echo $this->get_resource('parcel_services'); ?></span>
							</p>
					</td>
				    </tr>
				    <tr>
				    <th scope="row" class="titledesc"><?php _e('Shipping Class Rules', 'woocommerce-canadapost-webservice')?> </th>
					<td class="forminp">
					
							<p><label for="woocommerce_cpwebservice_enable_rules">
								<input name="woocommerce_cpwebservice_enable_rules" id="woocommerce_cpwebservice_enable_rules" type="checkbox" value="1" <?php checked(!empty($this->options->rules_enable)); ?> /> <?php _e('Enable Shipping Class rules for Parcel Services', 'woocommerce-canadapost-webservice') ?></label> &nbsp; <span class="description"><?php _e('Assign products to these shipping classes to apply these rules.', 'woocommerce-canadapost-webservice')?></span></p>
					<div id="cpwebservice_rules">									
							<?php $this->shipping_class_rule(); ?>
					</div>
					</td>
				    </tr>
				    <tr><td colspan="2" style="padding-left:0;border-bottom: 1px solid #999;">
		                 <h3><?php _e('Boxes / Packing', 'woocommerce-canadapost-webservice') ?></h3>
				    </td></tr>
				    <tr>
					<th scope="row" class="titledesc"><?php _e('Box/Envelope Weight', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
							<input type="text" name="woocommerce_cpwebservice_packageweight" id="woocommerce_cpwebservice_packageweight" style="max-width:50px;" value="<?php echo esc_attr($this->display_weight($this->options->packageweight)); ?>" /> <?php echo esc_html($this->options->display_weights)?> <span class="description"><?php echo sprintf(__('Envelope/Box weight with bill/notes/advertising inserts (ex. 0.02%s)', 'woocommerce-canadapost-webservice'), $this->options->display_weights) ?></span>
					</td>
				    </tr>
				    <tr>
				    <th scope="row" class="titledesc"><?php _e('Box Packing', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
							
							<p><label for="woocommerce_cpwebservice_box_packing">
							<?php _e('Advanced Box-Packing Algorithm used to pack products.', 'woocommerce-canadapost-webservice') ?></label>
							</p>
							<p>								
							<label for="woocommerce_cpwebservice_volumetric_weight">
							<input name="woocommerce_cpwebservice_volumetric_weight" id="woocommerce_cpwebservice_volumetric_weight" type="checkbox" value="1" <?php checked(!empty($this->options->volumetric_weight)); ?> /> <?php _e('After packing, use Volumetric weight (if it is more than the actual weight) when requesting package rates', 'woocommerce-canadapost-webservice') ?> <?php echo esc_html($this->get_resource('volumetric_weight_recommend')) ?></label>
							<img class="help_tip" data-tip="<?php echo esc_attr(sprintf(__( 'Most shipping couriers, including %s charge by volumetric weight (the size of the package) when it is greater than the actual weight.', 'woocommerce-canadapost-webservice' ), $this->get_resource('method_title'))) ?>" src="<?php echo esc_url( WC()->plugin_url() ); ?>/assets/images/help.png" height="16" width="16" /></p>
							
							<p><label for="woocommerce_cpwebservice_weight_only_enabled">
							<input name="woocommerce_cpwebservice_weight_only_enabled" id="woocommerce_cpwebservice_weight_only_enabled" type="checkbox" value="1" <?php checked(!empty($this->options->weight_only_enabled)); ?> /> <?php _e('Allow products with weight-only (no dimensions) to still be calculated.', 'woocommerce-canadapost-webservice') ?></label>
							</p>
							<p>
							<label for="woocommerce_cpwebservice_product_shipping_options">
							<input name="woocommerce_cpwebservice_product_shipping_options" id="woocommerce_cpwebservice_product_shipping_options" type="checkbox" value="1" <?php checked(!empty($this->options->product_shipping_options)); ?> /> <?php _e('Enable the option to mark products as Package Separately (Pre-packaged)', 'woocommerce-canadapost-webservice') ?></label>
							<br />
							<span class="description"><?php _e('This option will be displayed on the Product Edit page in Woocommerce.', 'woocommerce-canadapost-webservice') ?></span>
							</p>
					</td>
				    </tr>
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Shipping Package/Box sizes', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<?php if (!isset($this->boxes) || !is_array($this->boxes)){
							$this->boxes = array(array('length'=>'10','width'=>'10', 'height'=>'6','name'=>'Standard Box'));
							$this->options->boxes_enable='';
						}
						$box_defaults = array('length'=>0,'width'=>0, 'height'=>0,'name'=>''); ?>
					<label for="woocommerce_cpwebservice_boxes_enable">
								<input name="woocommerce_cpwebservice_boxes_enable" id="woocommerce_cpwebservice_boxes_enable" type="checkbox" value="1" <?php checked($this->options->boxes_enable=='1'); ?> /> <?php _e('Enable Shipping Box Sizes', 'woocommerce-canadapost-webservice') ?></label><br />
						<span class="description"><?php _e('Please define a number of envelope/package/box sizes that you use to ship. These will be used to ship but if a large enough box is not found for a product, the system will use a calculate one.', 'woocommerce-canadapost-webservice') ?></span>
						<div id="cpwebservice_boxes">							
							<?php for($i=0;$i<count($this->boxes); $i++): ?>
							<?php $box = (is_array($this->boxes[$i]) ? array_merge($box_defaults, $this->boxes[$i]) : array()); ?>
							<p class="cpwebservice_boxes_item form-field">
							<label for="woocommerce_cpwebservice_box_length[]"><?php _e('Box Dimensions', 'woocommerce-canadapost-webservice'); ?> (<?php echo esc_html($this->options->display_units)?>)</label><span class="wrap">
									<input name="woocommerce_cpwebservice_box_length[]" id="woocommerce_cpwebservice_box_length<?php echo $i;?>" style="max-width:60px" placeholder="Length" class="input-text" size="6" type="text" value="<?php echo esc_attr($this->display_unit($box['length'])); ?>" />
									<input name="woocommerce_cpwebservice_box_width[]" id="woocommerce_cpwebservice_box_width<?php echo $i;?>" style="max-width:60px" placeholder="Width" class="input-text" size="6" type="text" value="<?php echo esc_attr($this->display_unit($box['width'])); ?>">
									<input name="woocommerce_cpwebservice_box_height[]" id="woocommerce_cpwebservice_box_width<?php echo $i;?>" style="max-width:60px" placeholder="Height" class="input-text last" size="6" type="text" value="<?php echo esc_attr($this->display_unit($box['height'])); ?>" />
									<span class="description"><?php echo esc_html(sprintf(__('LxWxH %s decimal form','woocommerce-canadapost-webservice'), $this->options->display_units)); ?></span>
									<span class="description" style="margin-left:10px;"><?php _e('Box Name (internal)', 'woocommerce-canadapost-webservice'); ?>:</span>
									<input name="woocommerce_cpwebservice_box_name[]" id="woocommerce_cpwebservice_box_name<?php echo $i;?>" style="max-width:120px;" placeholder="Box Name" class="input-text last" size="6" type="text" value="<?php echo esc_attr($box['name']); ?>"></span>
									<span class="description"> <?php _e('Box Weight','woocommerce-canadapost-webservice')?>:</span><input name="woocommerce_cpwebservice_box_weight[]" id="woocommerce_cpwebservice_box_weight<?php echo $i;?>" style="max-width:50px" class="input-text last" size="6" type="text" value="<?php echo esc_attr(isset($box['weight'])? $this->display_weight($box['weight']) : ''); ?>" /><span class="description"><?php echo esc_html($this->options->display_weights)?></span>
									<span class="description">&nbsp; <?php _e('Add Cost/Margin $','woocommerce-canadapost-webservice')?></span><input name="woocommerce_cpwebservice_box_margin[]" id="woocommerce_cpwebservice_box_margin<?php echo $i;?>" style="max-width:50px" class="input-text last" size="6" type="text" value="<?php echo esc_attr(isset($box['margin'])? $box['margin'] : ''); ?>" />
									<span style="margin-left:5px;"><a href="javascript:;" title="Remove" onclick="jQuery(this).parent().parent('p').remove(); return false;" class="button"><?php _e('Remove','woocommerce-canadapost-webservice'); ?></a></span>
							</p>
							<?php endfor; ?>
						</div>
						<a href="javascript:;" id="btn_cpwebservice_boxes" class="button-secondary"><?php _e('Add More','woocommerce-canadapost-webservice'); ?> <span class="dashicons dashicons-plus-alt" style="margin-top:5px;"></span></a>
					</td>
				    </tr>
				    </table>
				  </div><!-- /#cpwebservice_services -->
				  					  
		<div class="cpwebservice_panel cpwebservice_hidden" id="cpwebservice_tracking">
		<h3><?php _e('Tracking', 'woocommerce-canadapost-webservice') ?></h3>
		         <table class="form-table">
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Order Shipping Tracking', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
						<p><label for="woocommerce_cpwebservice_email_tracking"><input name="woocommerce_cpwebservice_email_tracking" id="woocommerce_cpwebservice_email_tracking" type="checkbox" value="1" <?php checked($this->options->email_tracking==true); ?>  /> <?php _e('Enable Email notification when Parcel Tracking updates', 'woocommerce-canadapost-webservice') ?></label></p> 
						<p class="description"><?php _e('Automatic email notifications to customers when "Mailed on" or "Delivered" date is updated', 'woocommerce-canadapost-webservice')?></p>
						<p><label for="woocommerce_cpwebservice_tracking_icons"><input name="woocommerce_cpwebservice_tracking_icons" id="woocommerce_cpwebservice_tracking_icons" type="checkbox" value="1" <?php checked($this->options->tracking_icons==true); ?>  /> <?php _e('Display icons with Tracking information', 'woocommerce-canadapost-webservice') ?></label>
					</td>
				    </tr>
				  </table>
				  </div><!-- /#cpwebservice_tracking -->
		
				 <div class="cpwebservice_panel cpwebservice_hidden" id="cpwebservice_flatrates">
				  <table class="form-table">
				  <tr><th style="padding-left:0;border-bottom: 1px solid #999;">
				    <h3><?php _e('Lettermail / Flat Rates', 'woocommerce-canadapost-webservice') ?></h3>
				  </th>
				  </tr>
				     <tr valign="top">
					<td class="forminp">
					<?php if (empty($this->lettermail) || !is_array($this->lettermail) || count($this->lettermail) == 0){
							// Set default CP Lettermail Rates.
							/* Letter-post USA rates:(for now)
							0-100g = $2.20
							100g-200g = $3.80
							200g-500g = $7.60*/
							$this->lettermail = array(array('country'=>'CA','label'=>$this->get_resource('lettermail_default'), 'cost'=>'2.20','weight_from'=>'0','weight_to'=>'0.1', 'max_qty'=>0, 'min_total'=>'', 'max_total'=>''),
												    array('country'=>'CA','label'=>$this->get_resource('lettermail_default'), 'cost'=>'3.80','weight_from'=>'0.1','weight_to'=>'0.2', 'max_qty'=>0, 'min_total'=>'', 'max_total'=>''),
													array('country'=>'US','label'=>$this->get_resource('lettermail_default'), 'cost'=>'2.20','weight_from'=>'0','weight_to'=>'0.1', 'max_qty'=>0, 'min_total'=>'', 'max_total'=>''),
													array('country'=>'US','label'=>$this->get_resource('lettermail_default'), 'cost'=>'3.80','weight_from'=>'0.1','weight_to'=>'0.2', 'max_qty'=>0, 'min_total'=>'', 'max_total'=>''),
													array('country'=>'US','label'=>$this->get_resource('lettermail_default'), 'cost'=>'7.60','weight_from'=>'0.2','weight_to'=>'0.5', 'max_qty'=>0, 'min_total'=>'', 'max_total'=>''));
							$this->options->lettermail_enable='';
						} 
						$lettermail_defaults = array('country'=>'', 'prov'=>'', 'label'=>'', 'cost'=>0,'weight_from'=>'','weight_to'=>'','max_qty'=>0, 'min_total'=>'', 'max_total'=>''); ?>
					<label for="woocommerce_cpwebservice_lettermail_enable">
								<input name="woocommerce_cpwebservice_lettermail_enable" id="woocommerce_cpwebservice_lettermail_enable" type="checkbox" value="1" <?php checked($this->options->lettermail_enable=='1'); ?> /> <?php _e('Enable Lettermail / Flat Rates', 'woocommerce-canadapost-webservice') ?></label>
						<p class="description"><?php echo sprintf(__('Define Lettermail rates based on Weight Range (%s)', 'woocommerce-canadapost-webservice'), $this->options->display_weights) ?>.</p>
						<p class="description"> <?php echo sprintf(__('Example: 0.1%s to 0.2%s: $3.80 Lettermail', 'woocommerce-canadapost-webservice'), $this->options->display_weights,  $this->options->display_weights) ?></p>
						<?php
						// States/Prov. 
						$arr_prov =  WC()->countries->get_states( 'CA' );
                        $arr_states =  WC()->countries->get_states( 'US' );
            			 ?>
						<span id="cpwebservice_lettermail_statearray" data-states="<?php echo esc_attr(json_encode((array)$arr_states));  ?>" class="hidden"></span>
						<span id="cpwebservice_lettermail_provarray" data-provs="<?php echo esc_attr(json_encode((array)$arr_prov));  ?>" class="hidden"></span>
						<div id="cpwebservice_lettermail">							
							<?php for($i=0;$i<count($this->lettermail); $i++): ?>
							<?php $lettermail = (is_array($this->lettermail[$i]) ? array_merge($lettermail_defaults, $this->lettermail[$i]) : array()); ?>
							<p class="cpwebservice_lettermail_item form-field">
							<span class="wrap">
							    <select name="woocommerce_cpwebservice_lettermail_country[]" class="cpwebservice_lettermail_country">
									<option value="CA"<?php if ($lettermail['country']=='CA') echo 'selected="selected"'; ?>>Canada</option>
									<option value="US" <?php if ($lettermail['country']=='US') echo 'selected="selected"'; ?>>USA</option>
									<option value="INT" <?php if ($lettermail['country']=='INT') echo 'selected="selected"'; ?>><?php _e('International', 'woocommerce-canadapost-webservice') ?></option>
								</select>
        						 <select name="woocommerce_cpwebservice_lettermail_prov[]" class="cpwebservice_lettermail_prov">
        						    <option value="" <?php selected( '', esc_attr( $lettermail['country'] ) ); ?>></option>
            						<?php
            						if ($lettermail['country']!='INT'):      
            						  $lettermail_states = ($lettermail['country'] == 'CA') ? $arr_prov : $arr_states;
            						  foreach ( (array) $lettermail_states as $option_key => $option_value ) : ?>
            							<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $lettermail['prov'] ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
            						<?php endforeach; ?>
            						<?php endif; ?>
            					</select>
								<label for="woocommerce_cpwebservice_lettermail_label<?php echo $i;?>"> <?php _e('Label', 'woocommerce-canadapost-webservice'); ?>:</label>
									<input name="woocommerce_cpwebservice_lettermail_label[]" id="woocommerce_cpwebservice_lettermail_label<?php echo $i;?>" style="max-width:150px" placeholder="<?php _e('Lettermail','woocommerce-canadapost-webservice'); ?>" class="input-text" size="16" type="text" value="<?php echo esc_attr($lettermail['label']); ?>" />
									<span class="description"> <?php _e('Cost','woocommerce-canadapost-webservice'); ?>: $</span><input name="woocommerce_cpwebservice_lettermail_cost[]" id="woocommerce_cpwebservice_lettermail_cost<?php echo $i;?>" style="max-width:50px" placeholder="<?php _e('Cost','woocommerce-canadapost-webservice'); ?>" class="input-text" size="16" type="text" value="<?php echo esc_attr($lettermail['cost']); ?>">
									<span class="description"> <?php _e('Weight Range','woocommerce-canadapost-webservice'); ?>(<?php echo esc_html($this->options->display_weights)?>): </span><input name="woocommerce_cpwebservice_lettermail_weight_from[]" id="woocommerce_cpwebservice_lettermail_weight_from<?php echo $i;?>" style="max-width:40px" placeholder="" class="input-text" size="6" type="text" value="<?php echo esc_attr($this->display_weight($lettermail['weight_from'])); ?>" /><?php echo esc_html($this->options->display_weights)?>
									 <?php _e('to','woocommerce-canadapost-webservice'); ?> &lt;
									<input name="woocommerce_cpwebservice_lettermail_weight_to[]" id="woocommerce_cpwebservice_lettermail_weight_to<?php echo $i;?>" style="max-width:40px" placeholder="" class="input-text last" size="6" type="text" value="<?php echo esc_attr($this->display_weight($lettermail['weight_to'])); ?>" /><?php echo esc_html($this->options->display_weights)?></span>
									<span class="description"> <?php _e('Max items (0 for no limit)','woocommerce-canadapost-webservice'); ?>: </span> <input name="woocommerce_cpwebservice_lettermail_max_qty[]" id="woocommerce_cpwebservice_lettermail_max_qty<?php echo $i;?>" style="max-width:40px" placeholder="" class="input-text" size="6" type="text" value="<?php echo esc_attr($lettermail['max_qty']); ?>" />
									<span class="description"> <?php _e('Cart subtotal','woocommerce-canadapost-webservice'); ?>: $</span><input name="woocommerce_cpwebservice_lettermail_min_total[]" id="woocommerce_cpwebservice_lettermail_min_total<?php echo $i;?>" style="max-width:50px" placeholder="" class="input-text" size="6" type="text" value="<?php echo esc_attr($lettermail['min_total']); ?>" />
									 <?php _e('to','woocommerce-canadapost-webservice'); ?> &lt;=
									$<input name="woocommerce_cpwebservice_lettermail_max_total[]" id="woocommerce_cpwebservice_lettermail_max_total<?php echo $i;?>" style="max-width:50px" placeholder="" class="input-text last" size="6" type="text" value="<?php echo esc_attr($lettermail['max_total']); ?>" />
									<span style="margin-left:5px;"><a href="javascript:;" title="Remove" onclick="jQuery(this).parent().parent('p').remove(); return false;" class="button"><?php _e('Remove','woocommerce-canadapost-webservice'); ?></a></span>
							</p>
							<?php endfor; ?>
						</div>
						<a href="javascript:;" id="btn_cpwebservice_lettermail" class="button-secondary"><?php _e('Add More','woocommerce-canadapost-webservice'); ?> <span class="dashicons dashicons-plus-alt" style="margin-top:5px;"></span></a>
						<br />
						<br />
						<label for="woocommerce_cpwebservice_lettermail_limits">
								<input name="woocommerce_cpwebservice_lettermail_limits" id="woocommerce_cpwebservice_lettermail_limits" type="checkbox" value="1" <?php checked($this->options->lettermail_limits=='1'); ?> /> <?php _e('Maximum dimensions for Lettermail/Flat Rates (Also maximum volumetric weight)', 'woocommerce-canadapost-webservice') ?></label>
						<p class="form-field">
							<input name="woocommerce_cpwebservice_lettermail_maxlength" id="woocommerce_cpwebservice_lettermail_maxlength" style="max-width:50px" placeholder="Length" class="input-text" size="6" type="text" value="<?php echo esc_attr(!empty($this->options->lettermail_maxlength) ? $this->display_unit($this->options->lettermail_maxlength) : '' ); ?>" />
									<input name="woocommerce_cpwebservice_lettermail_maxwidth" id="woocommerce_cpwebservice_lettermail_maxwidth" style="max-width:50px" placeholder="Width" class="input-text" size="6" type="text" value="<?php echo esc_attr(!empty($this->options->lettermail_maxwidth) ? $this->display_unit($this->options->lettermail_maxwidth) : ''); ?>">
									<input name="woocommerce_cpwebservice_lettermail_maxheight" id="woocommerce_cpwebservice_lettermail_maxheight" style="max-width:50px" placeholder="Height" class="input-text last" size="6" type="text" value="<?php echo esc_attr(!empty($this->options->lettermail_maxheight) ? $this->display_unit($this->options->lettermail_maxheight) : ''); ?>" />
									<span class="description"><?php echo esc_html(sprintf(__('(%s) LxWxH decimal form','woocommerce-canadapost-webservice'), $this->options->display_units)); ?> </span>
						</p>
						<br />
						<label for="woocommerce_cpwebservice_lettermail_override_weight">
								<input name="woocommerce_cpwebservice_lettermail_override_weight" id="woocommerce_cpwebservice_lettermail_override_weight" type="checkbox" value="1" <?php checked($this->options->lettermail_override_weight=='1'); ?> /> <?php _e('Override Box/Envelope Weights for Lettermail/Flat Rates', 'woocommerce-canadapost-webservice') ?></label>
						<p class="form-field">
							<input name="woocommerce_cpwebservice_lettermail_packageweight" id="woocommerce_cpwebservice_lettermail_packageweight" style="max-width:50px" class="input-text" size="6" type="text" value="<?php echo esc_attr(!empty($this->options->lettermail_packageweight) ? $this->save_weight($this->options->lettermail_packageweight) : ''); ?>" /><?php echo esc_html($this->options->display_weights)?> <span class="description"><?php _e('Envelope/Box weight. This is used instead of above Box/Envelope Weight, but only for calculating Lettermail/Flat Rates.', 'woocommerce-canadapost-webservice') ?></span></p>
					</td>
				    </tr>
				    
			</table>
			</div><!-- /#cpwebservice_flatrates -->
			<?php if ($this->get_resource('shipments_implemented')===true): ?>
			<div class="cpwebservice_panel cpwebservice_hidden" id="cpwebservice_shipments">
		     <h3><?php _e('Shipments', 'woocommerce-canadapost-webservice') ?></h3>
		     
		       <table class="form-table">
		        <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Creating Shipments', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<p><?php _e('The ability to Create Shipments (Paid shipping labels) is provided on the &quot;Edit Order&quot; page in Woocommerce. You will see a &quot;Create Shipment&quot; button.', 'woocommerce-canadapost-webservice')?>
					<br /><a href="<?php echo admin_url( 'edit.php?post_type=shop_order' ); ?>" target="_blank"><?php _e('View Orders', 'woocommerce-canadapost-webservice')?></a>
					</td>
					</tr>
				    <tr valign="top">
					<th scope="row" class="titledesc"><?php _e('Shipment Settings', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<p><span class="dashicons dashicons-cart"></span> <?php _e('View my orders on', 'woocommerce-canadapost-webservice'); ?> <?php echo esc_html($this->get_resource('method_title'))?>
                    <br /><a href="<?php echo esc_attr($this->get_resource('method_website_orders_url')); ?>" target="_blank"><?php echo esc_html($this->get_resource('method_website_orders_url')); ?></a></p>
                    
					<p><?php printf(__('In order to create paid shipping labels through this plugin, you will need to add a method of payment (Credit Card) on your account with %s.', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title') ) ?></p>

<p><?php printf(__('Log-in and add a default payment credit card to your %s online profile.', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title') ) ?></p>
<?php if (!empty($this->options->contractid)):?>
<p><?php _e('To create a commercial shipping label you must be a commercial customer with a parcel agreement and have an account in good standing.', 'woocommerce-canadapost-webservice')?></p>
<p><?php _e('You may use “account” as an alternate method of payment. Please ensure that your account is in good standing.', 'woocommerce-canadapost-webservice') ?></p>
<?php endif; ?>


<p><?php _e('Add a method of Payment: (Visa/MasterCard/AmericanExpress)', 'woocommerce-canadapost-webservice') ?>
 <br /><a href="<?php echo $this->get_resource('method_website_account_url')?>"><?php echo $this->get_resource('method_website_account_url'); ?></a></p>
					</td>
				    </tr>
				    <tr valign="top">
				    <th scope="row" class="titledesc"><?php _e('Logging', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<label for="woocommerce_cpwebservice_shipment_log_enable">
								<input name="woocommerce_cpwebservice_shipment_log_enable" id="woocommerce_cpwebservice_shipment_log_enable" type="checkbox" value="1" <?php checked($this->options->shipment_log=='1'); ?> /> <?php _e('Enable Shipment Logging', 'woocommerce-canadapost-webservice') ?>
								<br /><small><?php _e('Captures recent create shipment / label actions.  If there are any errors in creating shipments, they will be captured in this log.', 'woocommerce-canadapost-webservice') ?></small></label>
					<?php if ($this->options->shipment_log): ?>
					<div><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_shipment_log_display' ), 'cpwebservice_shipment_log_display' ); ?>" title="Display Log" class="button canadapost-shipment-log-display"><?php _e('Display log for recent shipments/labels','woocommerce-canadapost-webservice'); ?></a> <div class="canadapost-shipment-log-display-loading canadapost-spinner" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div>
					<a href="#" class="button button-secondary canadapost-shipment-log-close" style="display:none"><span class="dashicons dashicons-no"></span></a>
					</div>
					<div id="cpwebservice_shipment_log_display" style="display:none;">
					<p></p>
					</div>
					<?php endif; ?> 
					</td>
				    </tr>
				    <tr valign="top">
				    <th scope="row" class="titledesc"><?php _e('Template', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<p><?php _e('Common settings can be saved into a Template when creating a shipment label.', 'woocommerce-canadapost-webservice') ?><br />
					<?php _e('Please note that templates do not save the Customer Email, Phone and Order Reference Numbers of the shipment label.', 'woocommerce-canadapost-webservice') ?>
					</p>
					<label for="woocommerce_cpwebservice_shipment_template_package">
					<input name="woocommerce_cpwebservice_shipment_template_package" id="woocommerce_cpwebservice_shipment_template_package" type="checkbox" value="1" <?php checked($this->options->template_package); ?> /> <?php _e('Include package weight and dimensions in Shipment Tempmlates', 'woocommerce-canadapost-webservice') ?>
					<br />
					<label for="woocommerce_cpwebservice_shipment_template_customs">
					<input name="woocommerce_cpwebservice_shipment_template_customs" id="woocommerce_cpwebservice_shipment_template_customs" type="checkbox" value="1" <?php checked($this->options->template_customs); ?> /> <?php _e('Include Customs Information (but not Customs Products) in Shipment Templates', 'woocommerce-canadapost-webservice') ?>
					<br />
					</td>
					</tr>
					<tr valign="top">
				    <th scope="row" class="titledesc"><?php _e('Customs', 'woocommerce-canadapost-webservice') ?></th>
					<td class="forminp">
					<p><?php _e('Customs product data can be saved with the product.', 'woocommerce-canadapost-webservice') ?>
					</p>
					<label for="woocommerce_cpwebservice_shipment_hscodes">
					<input name="woocommerce_cpwebservice_shipment_hscodes" id="woocommerce_cpwebservice_shipment_hscodes" type="checkbox" value="1" <?php checked($this->options->shipment_hscodes); ?> /> <?php _e('Enable saving Customs HS Codes and Country of Origin on Products', 'woocommerce-canadapost-webservice') ?>
					<br />
					</td>
					</tr>
				  </table>
			</div><!-- /#cpwebservice_shipments -->
			<?php endif; ?>
			<script type="text/javascript">
				jQuery(document).ready(function($) {

					jQuery('.btn_cpwebservice_rules').on('click',function() {
						var fields = jQuery(this).parent().parent().find('select').each(function() { jQuery(this).find('option:selected').removeAttr('selected'); });
						<?php if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.3', '>=' ) ) { ?>
						fields.trigger('change');
						<?php } else { // chosen ?>
						fields.trigger('chosen:updated');
						<?php } // endif;?>
						return false;
					});
				});

				</script>					
		<?php 
		}

		function process_admin_options() {

			
			// check for security
			if (!isset($_POST['cpwebservice_options_noncename']) || !wp_verify_nonce($_POST['cpwebservice_options_noncename'], plugin_basename(__FILE__))) 
				return;


			if(isset($_POST['woocommerce_cpwebservice_enabled'])) $this->options->enabled = 'yes'; else $this->options->enabled ='no';
			if(isset($_POST['woocommerce_cpwebservice_title'])) $this->options->title = woocommerce_clean($_POST['woocommerce_cpwebservice_title']);
			if(isset($_POST['woocommerce_cpwebservice_account'])) $this->options->account = woocommerce_clean($_POST['woocommerce_cpwebservice_account']);
			if(isset($_POST['woocommerce_cpwebservice_contractid'])) $this->options->contractid = woocommerce_clean($_POST['woocommerce_cpwebservice_contractid']);
			if(isset($_POST['woocommerce_cpwebservice_api_user'])) $this->options->api_user = woocommerce_clean($_POST['woocommerce_cpwebservice_api_user']);
			if(isset($_POST['woocommerce_cpwebservice_api_key'])) $this->options->api_key = woocommerce_clean($_POST['woocommerce_cpwebservice_api_key']);
			if ($this->get_resource('account_onlyuserid')===true && !empty($this->options->api_user) && empty($this->options->api_key) && empty($this->options->account)){ // means password is generated;
			    $this->options->api_key = hash('sha256', $this->options->api_user);  $this->options->account = $this->options->api_user;
			}
			if(isset($_POST['woocommerce_cpwebservice_mode'])) $this->options->mode = woocommerce_clean($_POST['woocommerce_cpwebservice_mode']);
			if(isset($_POST['woocommerce_cpwebservice_delivery'])) $this->options->delivery = intval(woocommerce_clean($_POST['woocommerce_cpwebservice_delivery'])); 
			if ($this->options->delivery==0) { $this->options->delivery = ''; }
			if(isset($_POST['woocommerce_cpwebservice_delivery_guarantee'])) $this->options->delivery_guarantee = true; else $this->options->delivery_guarantee = false;
			if(isset($_POST['woocommerce_cpwebservice_delivery_format'])) $this->options->delivery_format = woocommerce_clean($_POST['woocommerce_cpwebservice_delivery_format']);
			if(isset($_POST['woocommerce_cpwebservice_delivery_label'])) $this->options->delivery_label = woocommerce_clean($_POST['woocommerce_cpwebservice_delivery_label']);
			if(isset($_POST['woocommerce_cpwebservice_margin_value']))  $this->options->margin_value = floatval(woocommerce_clean($_POST['woocommerce_cpwebservice_margin_value']));
			if(isset($_POST['woocommerce_cpwebservice_margin'])) $this->options->margin = floatval(woocommerce_clean($_POST['woocommerce_cpwebservice_margin']));
			if (!empty($this->options->margin) && $this->options->margin == 0) { $this->options->margin = ''; } // percentage only != 0
			if(isset($_POST['woocommerce_cpwebservice_packageweight'])) $this->options->packageweight = $this->save_weight(floatval($_POST['woocommerce_cpwebservice_packageweight']));
			if(isset($_POST['woocommerce_cpwebservice_log_enable'])) $this->options->log_enable = true; else $this->options->log_enable = false;
			if(isset($_POST['woocommerce_cpwebservice_boxes_enable'])) $this->options->boxes_enable = true; else $this->options->boxes_enable = false;
			if(isset($_POST['woocommerce_cpwebservice_lettermail_enable'])) $this->options->lettermail_enable = true; else $this->options->lettermail_enable = false;
			if(isset($_POST['woocommerce_cpwebservice_shipping_tracking'])) $this->options->shipping_tracking = true; else $this->options->shipping_tracking = false;
			if(isset($_POST['woocommerce_cpwebservice_email_tracking'])) $this->options->email_tracking = true; else $this->options->email_tracking = false;
			if(isset($_POST['woocommerce_cpwebservice_tracking_icons'])) $this->options->tracking_icons = true; else $this->options->tracking_icons = false;
			if(isset($_POST['woocommerce_cpwebservice_display_required_notice'])) $this->options->display_required_notice = true; else $this->options->display_required_notice = false;
			update_option('cpwebservice_require_postal', $this->options->display_required_notice  ? 'yes' : 'no');
			if(isset($_POST['woocommerce_cpwebservice_enable_rules'])) $this->options->rules_enable = true; else $this->options->rules_enable = false;
			if(isset($_POST['woocommerce_cpwebservice_product_shipping_options'])) $this->options->product_shipping_options = true; else $this->options->product_shipping_options = false;
			if(isset($_POST['woocommerce_cpwebservice_volumetric_weight'])) $this->options->volumetric_weight = true; else $this->options->volumetric_weight = false;
			if(isset($_POST['woocommerce_cpwebservice_weight_only_enabled'])) $this->options->weight_only_enabled = true; else $this->options->weight_only_enabled = false;
			if(isset($_POST['woocommerce_cpwebservice_prefer_service'])) $this->options->prefer_service = true; else $this->options->prefer_service = false;
			if(isset($_POST['woocommerce_cpwebservice_packagetype'])) $this->options->packagetype = woocommerce_clean($_POST['woocommerce_cpwebservice_packagetype']);
			if(isset($_POST['woocommerce_cpwebservice_geolocate_origin'])) $this->options->geolocate_origin = true; else $this->options->geolocate_origin = false;
			if(isset($_POST['woocommerce_cpwebservice_geolocate_limit'])) $this->options->geolocate_limit = true; else $this->options->geolocate_limit = false;
			if(isset($_POST['woocommerce_cpwebservice_availability'])) $this->options->availability = woocommerce_clean($_POST['woocommerce_cpwebservice_availability']);
			if(isset($_POST['woocommerce_cpwebservice_availability_countries']) && is_array($_POST['woocommerce_cpwebservice_availability_countries'])){
			    $this->options->availability_countries = woocommerce_clean(implode(',', $_POST['woocommerce_cpwebservice_availability_countries']));
			} else { $this->options->availability_countries = ''; };
			
			// Source postal code (rates)
			if(isset($_POST['woocommerce_cpwebservice_source_postalcode'])) $this->options->source_postalcode = woocommerce_clean($_POST['woocommerce_cpwebservice_source_postalcode']);
			$this->options->source_postalcode = str_replace(' ','',strtoupper($this->options->source_postalcode)); // N0N0N0 format only
			
			// services
			if(isset($_POST['woocommerce_cpwebservice_services']) && is_array($_POST['woocommerce_cpwebservice_services'])) {
				// save valid options. ( returns an array containing all the values of array1 that are present in array2 - in this case, an array of valid service codes)
				$this->services = array_intersect($_POST['woocommerce_cpwebservice_services'], array_keys($this->available_services));
				update_option('woocommerce_cpwebservice_services', $this->services);
			}
			// service labels
			$this->service_labels = array();
			foreach($this->available_services as $service_code=>$service_label) {
			    $service_code_field = $this->get_service_code_field($service_code);
			    if(!empty($_POST['woocommerce_cpwebservice_service_label_'.$service_code_field]) && $_POST['woocommerce_cpwebservice_service_label_'.$service_code_field] != $service_label) {
			        // save valid labels
			        $this->service_labels[$service_code] = woocommerce_clean($_POST['woocommerce_cpwebservice_service_label_'.$service_code_field]);
			    }
			}
			update_option('woocommerce_cpwebservice_service_labels', $this->service_labels);
			
			
			// boxes
			if( isset($_POST) && isset($_POST['woocommerce_cpwebservice_box_length']) && is_array($_POST['woocommerce_cpwebservice_box_length']) ) {
				$boxes = array();
			
				for ($i=0; $i < count($_POST['woocommerce_cpwebservice_box_length']); $i++){
					$box = array();
					$box['length'] = isset($_POST['woocommerce_cpwebservice_box_length'][$i]) ? $this->save_unit(floatval($_POST['woocommerce_cpwebservice_box_length'][$i])) : '';
					$box['width'] = isset($_POST['woocommerce_cpwebservice_box_width'][$i]) ? $this->save_unit(floatval($_POST['woocommerce_cpwebservice_box_width'][$i])) : '';
					$box['height'] = isset($_POST['woocommerce_cpwebservice_box_height'][$i]) ? $this->save_unit(floatval($_POST['woocommerce_cpwebservice_box_height'][$i])) : '';
					$box['name'] = isset($_POST['woocommerce_cpwebservice_box_name'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_box_name'][$i]) : '';
					$box['weight'] = isset($_POST['woocommerce_cpwebservice_box_weight'][$i]) ? $this->save_weight(floatval($_POST['woocommerce_cpwebservice_box_weight'][$i])) : '';
					$box['margin'] = isset($_POST['woocommerce_cpwebservice_box_margin'][$i]) ? number_format(floatval($_POST['woocommerce_cpwebservice_box_margin'][$i]),1,'.','') : '';
					if (empty($box['weight'])) { $box['weight'] = ''; }
					if (empty($box['margin'])) { $box['margin'] = ''; }
					// Cubed/volumetric
					$box['cubed'] = $box['length'] * $box['width'] * $box['height'];
					
					$boxes[] = $box;
				}
			
				$this->boxes = $boxes;
				update_option('woocommerce_cpwebservice_boxes', $this->boxes);
			}
			
			// rules
			if( isset($_POST) && isset($_POST['cpwebservice_rule_classes']) && is_array($_POST['cpwebservice_rule_classes']) ) {
				$rules = array();
				for ($i=0; $i < count($_POST['cpwebservice_rule_classes']); $i++){
					$rule = array();
					if (isset($_POST['cpwebservice_rule_classes'][$i]) && isset($_POST['cpwebservice_rule_services'][$i])){
						$rule['shipping_class'] =  woocommerce_clean($_POST['cpwebservice_rule_classes'][$i]);
						$rule['services'] = array();
						foreach($_POST['cpwebservice_rule_services'][$i] as $svc){
							$rule['services'][] = woocommerce_clean($svc);
						}
					}
					$rules[] = $rule;
				}
			
				$this->rules = $rules;
				update_option('woocommerce_cpwebservice_rules', $this->rules);
			}
			
			// lettermail
			if( isset($_POST) && isset($_POST['woocommerce_cpwebservice_lettermail_country']) && is_array($_POST['woocommerce_cpwebservice_lettermail_country']) ) {
				$lettermail = array();
			
				for ($i=0; $i < count($_POST['woocommerce_cpwebservice_lettermail_country']); $i++){
					$row = array();
					$row['country'] = isset($_POST['woocommerce_cpwebservice_lettermail_country'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_lettermail_country'][$i]) : '';
					$row['prov'] = isset($_POST['woocommerce_cpwebservice_lettermail_prov'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_lettermail_prov'][$i]) : '';
					$row['label'] = isset($_POST['woocommerce_cpwebservice_lettermail_label'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_lettermail_label'][$i]) : '';
					$row['cost'] = isset($_POST['woocommerce_cpwebservice_lettermail_cost'][$i]) ? number_format(floatval($_POST['woocommerce_cpwebservice_lettermail_cost'][$i]),2,'.','') : '';
					$row['weight_from'] = isset($_POST['woocommerce_cpwebservice_lettermail_weight_from'][$i]) ? $this->save_weight(floatval($_POST['woocommerce_cpwebservice_lettermail_weight_from'][$i])) : '';
					$row['weight_to'] = isset($_POST['woocommerce_cpwebservice_lettermail_weight_to'][$i]) ? $this->save_weight(floatval($_POST['woocommerce_cpwebservice_lettermail_weight_to'][$i])) : '';
					if ($row['weight_from'] > $row['weight_to']) { $row['weight_from'] = $row['weight_to']; } // Weight From must be a lesser value.
					$row['max_qty'] = isset($_POST['woocommerce_cpwebservice_lettermail_max_qty'][$i]) ? intval($_POST['woocommerce_cpwebservice_lettermail_max_qty'][$i]) : 0;
					$row['min_total'] = !empty($_POST['woocommerce_cpwebservice_lettermail_min_total'][$i]) ? number_format(floatval($_POST['woocommerce_cpwebservice_lettermail_min_total'][$i]),2,'.','') : '';
					$row['max_total'] = !empty($_POST['woocommerce_cpwebservice_lettermail_max_total'][$i]) ? number_format(floatval($_POST['woocommerce_cpwebservice_lettermail_max_total'][$i]),2,'.','') : '';
					
					$lettermail[] = $row;
				}
			
				$this->lettermail = $lettermail;
				update_option('woocommerce_cpwebservice_lettermail', $this->lettermail);
			}
			if(isset($_POST['woocommerce_cpwebservice_lettermail_limits'])) $this->options->lettermail_limits = true; else $this->options->lettermail_limits = false;
			if(isset($_POST['woocommerce_cpwebservice_lettermail_maxlength'])) $this->options->lettermail_maxlength = $this->save_unit(floatval($_POST['woocommerce_cpwebservice_lettermail_maxlength']));
			if(isset($_POST['woocommerce_cpwebservice_lettermail_maxwidth'])) $this->options->lettermail_maxwidth = $this->save_unit(floatval($_POST['woocommerce_cpwebservice_lettermail_maxwidth']));
			if(isset($_POST['woocommerce_cpwebservice_lettermail_maxheight'])) $this->options->lettermail_maxheight = $this->save_unit(floatval($_POST['woocommerce_cpwebservice_lettermail_maxheight']));
			if (empty($this->options->lettermail_maxlength)) $this->options->lettermail_maxlength = '';
			if (empty($this->options->lettermail_maxwidth)) $this->options->lettermail_maxwidth = '';
			if (empty($this->options->lettermail_maxheight)) $this->options->lettermail_maxheight = '';
			if(isset($_POST['woocommerce_cpwebservice_lettermail_packageweight'])) $this->options->lettermail_packageweight = $this->save_weight(floatval($_POST['woocommerce_cpwebservice_lettermail_packageweight']));
			if(isset($_POST['woocommerce_cpwebservice_lettermail_override_weight'])) $this->options->lettermail_override_weight = true; else $this->options->lettermail_override_weight = false;
			//Shipments
			//
			if( isset($_POST['woocommerce_cpwebservice_shipment_postalcode']) && is_array($_POST['woocommerce_cpwebservice_shipment_postalcode']) ) {
			    $address = array();
			    $default_postalcode = '';
			    $geo = new cpwebservice_location();
			    	
			    for ($i=0; $i < count($_POST['woocommerce_cpwebservice_shipment_postalcode']); $i++){
			        $row = array('default'=>false,'contact'=>'','phone'=>'','postalcode'=>'','address'=>'','address2'=>'','city'=>'','prov'=>'','country'=>'','origin'=>true, 'postalcode_lat'=>0,'postalcode_lng'=>0);
			        $row['contact'] = isset($_POST['woocommerce_cpwebservice_shipment_contact'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_contact'][$i]) : '';
			        $row['phone'] = isset($_POST['woocommerce_cpwebservice_shipment_phone'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_phone'][$i]) : '';
			        $row['address'] = isset($_POST['woocommerce_cpwebservice_shipment_address'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_address'][$i]) : '';
			        $row['address2'] = isset($_POST['woocommerce_cpwebservice_shipment_address2'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_address2'][$i]) : '';
			        $row['city'] = isset($_POST['woocommerce_cpwebservice_shipment_city'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_city'][$i]) : '';
			        $row['prov'] = isset($_POST['woocommerce_cpwebservice_shipment_prov'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_prov'][$i]) : '';
			        $row['country'] = isset($_POST['woocommerce_cpwebservice_shipment_country'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_country'][$i]) : '';
			        $row['postalcode'] = isset($_POST['woocommerce_cpwebservice_shipment_postalcode'][$i]) ? woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_postalcode'][$i]) : '';
			        $row['postalcode'] = str_replace(' ','',strtoupper($row['postalcode'])); // N0N0N0 format only
			        $row['origin'] = (isset($_POST['woocommerce_cpwebservice_shipment_postalcode_origin']) && is_array($_POST['woocommerce_cpwebservice_shipment_postalcode_origin']) && in_array($i, $_POST['woocommerce_cpwebservice_shipment_postalcode_origin'])) ? true : false;
			        // Default Address radio group
			        $row['default'] = (isset($_POST['woocommerce_cpwebservice_shipment_default']) && intval($_POST['woocommerce_cpwebservice_shipment_default'])==$i) ? true : false;
			        if ($row['default']){
			            $default_postalcode = $row['postalcode'];
			        }
			        // Find Geo lat/long if postal code is valid.
			        if (!empty($row['postalcode'])){
			            $prefix = $geo->postal_prefix($row['postalcode']);
			            $latlng = $geo->lookup_postal_location($prefix);
			            if (!empty($latlng)){
    			            $row['postalcode_lat'] = $latlng[0];
    			            $row['postalcode_lng'] = $latlng[1];
			            }
			        }
			        	
			        $address[] = $row;
			    }
			    $this->shipment_address = $address;
			    update_option('woocommerce_cpwebservice_shipment_address', $this->shipment_address);
			    
			    // Set default source_postalcode. (set if empty)
			    if (empty($default_postalcode) && !empty($address)){
			        $default_postalcode = $address[0]['postalcode'];
			    }
			    // Set source postalcode from the default address's postalcode.
			    $this->options->source_postalcode = $default_postalcode;
			}
			
			if(isset($_POST['woocommerce_cpwebservice_shipment_mode'])) $this->options->shipment_mode = woocommerce_clean($_POST['woocommerce_cpwebservice_shipment_mode']);
			if(isset($_POST['woocommerce_cpwebservice_api_dev_user'])) $this->options->api_dev_user = woocommerce_clean($_POST['woocommerce_cpwebservice_api_dev_user']);
			if(isset($_POST['woocommerce_cpwebservice_api_dev_key'])) $this->options->api_dev_key = woocommerce_clean($_POST['woocommerce_cpwebservice_api_dev_key']);
			if(isset($_POST['woocommerce_cpwebservice_shipments_enabled'])) $this->options->shipments_enabled = true; else $this->options->shipments_enabled = false;
			if(isset($_POST['woocommerce_cpwebservice_shipment_log_enable'])) $this->options->shipment_log = true; else $this->options->shipment_log = false;
			if(isset($_POST['woocommerce_cpwebservice_shipment_template_package'])) $this->options->template_package = true; else $this->options->template_package = false;
			if(isset($_POST['woocommerce_cpwebservice_shipment_template_customs'])) $this->options->template_customs = true; else $this->options->template_customs = false;
			if(isset($_POST['woocommerce_cpwebservice_shipment_hscodes'])) $this->options->shipment_hscodes = true; else $this->options->shipment_hscodes = false;
			
			// shipment implementation
			if ($this->get_resource('shipments_implemented')===false) { $this->options->shipments_enabled = false; }
			
			// update options.
			update_option('woocommerce_cpwebservice', $this->options);
		}
		
		
		/**
		 * Return admin options as a html string.
		 * @return string
		 */
		public function get_admin_options_html() {
		    if ( $this->instance_id ) {
		        $settings_html= 'Configure options.' .  $this->instance_id;  
		    } else {
		        $settings_html= 'Global options.';   
		    }
		    return '<table class="form-table">' . $settings_html . '</table>';
		}
		
		/**
		 * Ajax function to Display Rates Lookup Log.
		 */
		public function rates_log_display() {
		
			// Let the backend only access the page
			if( !is_admin() ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
				
			// Check the user privileges
			if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
				
			// Nonce.
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_rates_log_display' ) )
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			
			
			if (false !== ( $log = get_transient('cpwebservice_log') ) && !empty($log)){
			$log = (object) array_merge(array('cart'=>array(),'params'=>array(),'request'=>array(),'rates'=>array(), 'datestamp'=>''), (array) $log);
				?>
									<h4><?php _e('Cart Shipping Rates Request', 'woocommerce-canadapost-webservice')?> - <?php echo date("F j, Y, g:i a",$log->datestamp); ?></h4>
									<table class="table widefat">
									<tr><th><?php _e('Item', 'woocommerce-canadapost-webservice')?></th><th style="width:10%"><?php _e('Qty', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Weight', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Dimensions', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Cubic', 'woocommerce-canadapost-webservice')?></th></tr>
									<?php foreach($log->cart as $cart):?>
									<tr>
									<td><?php echo edit_post_link(esc_html($cart['item']),'','',$cart['id'])?></td><td><?php echo esc_html($cart['quantity'])?></td><td><?php echo esc_html($this->display_weight($cart['weight']))?><?php echo esc_html($this->options->display_weights)?></td><td><?php echo esc_html($cart['quantity'])?>* (<?php echo esc_html($this->display_unit($cart['length']))?><?php echo esc_html($this->options->display_units) ?> x <?php echo esc_html($this->display_unit($cart['width']))?><?php echo esc_html($this->options->display_units) ?> x <?php echo esc_html($this->display_unit($cart['height']))?><?php echo esc_html($this->options->display_units) ?>)</td><td><?php echo esc_html($this->display_unit_cubed($cart['cubic']))?><?php echo esc_html($this->options->display_units) ?><sup>3</sup></td>
									</tr>
									<?php endforeach; ?>
									</table>
									
									<h4><?php _e('Request / API Response', 'woocommerce-canadapost-webservice')?></h4>
									<p class="description"><?php _e('After box packing/Volumetric weight calculation and Box/Envelope Weight', 'woocommerce-canadapost-webservice')?></p>
									<table class="table widefat">
									<tr><th><?php _e('Origin Postal', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Packages', 'woocommerce-canadapost-webservice')?> (<?php echo count($log->params)?>)</th><th><?php _e('Country', 'woocommerce-canadapost-webservice')?>, <?php _e('State', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Destination', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Shipping Weight', 'woocommerce-canadapost-webservice')?></th><th><?php _e('Dimensions', 'woocommerce-canadapost-webservice')?></th></tr>
									<?php foreach($log->params as $i=>$params) { ?>
									<tr>
									<td><?php echo esc_html($params['source_postalcode'])?></td><td><?php echo (($i+1) . (isset($params['box_name']) ? esc_html(': '.$params['box_name']) : '')) ?></td><td><?php echo esc_html($params['country'])?>, <?php echo esc_html($params['state'])?></td><td><?php echo esc_html($params['postal'])?></td><td><?php echo esc_html($this->display_weight($params['shipping_weight']))?><?php echo esc_html($this->options->display_weights)?> (<?php _e('Actual', 'woocommerce-canadapost-webservice')?> <?php echo esc_html($this->display_weight($params['total_weight']))?><?php echo esc_html($this->options->display_weights)?>)
									</td>
									<td><?php echo esc_html($this->display_unit($params['length']))?><?php echo esc_html($this->options->display_units) ?> x <?php echo esc_html($this->display_unit($params['width']))?><?php echo esc_html($this->options->display_units) ?> x <?php echo esc_html($this->display_unit($params['height']))?><?php echo esc_html($this->options->display_units) ?></td>
									<?php //array('country'=>$country, 'state'=>$state, 'postal'=>$postal, 'shipping_weight'=>$shipping_weight, 'length'=>$length, 'width'=>$width, 'height'=>$height); ?>
									</tr>
									<?php } //endforeach ?>
									</table>
									<br />
									<table class="table widefat">
									<tr><td>
									<?php foreach($log->request as $request):?><?php echo str_replace("\n\n","</td><td>",str_replace("\n","<br />",esc_html($request))) ?><?php endforeach; ?>
									</td>
									</tr></table>
									
									<h4><?php _e('Rates displayed in Cart', 'woocommerce-canadapost-webservice')?></h4>
									<?php if(!empty($log->rates)): ?>
									<table class="table widefat">
									<?php foreach($log->rates as $rates):?>
									<tr>
									<th><?php echo $rates->label ?></th>
									<td><?php echo number_format((float)$rates->cost, 2) ?>
									</td>
									</tr>
									<?php endforeach; ?>
									</table>
									<?php else: ?>
									<p><?php _e('No rates displayed', 'woocommerce-canadapost-webservice') ?></p>
									<?php endif; ?>
									<?php } else { ?>
					<?php _e('No log information.. yet.  Go to your shopping cart page and click on "Calculate Shipping".', 'woocommerce-canadapost-webservice') ?>
					<?php  } // endif
			
		exit;
		}
		
		public function shipping_class_rule(){
			if (empty($this->rules) || !is_array($this->rules)){
				$this->rules = array();
			}
			// ensure there are 3 rules available (can change to any number as it's unlimited)
			$display_rules_num = 3;
			if (count($this->rules) < $display_rules_num){
				for($i=count($this->rules);$i<$display_rules_num;$i++){
					$this->rules[] = array('shipping_class'=>'', 'services'=>array(''));
				}
			}
			$shipping_class = get_terms(array('product_shipping_class'), array('hide_empty' => 0));
			?><table class="form-table" style="width:auto">
			<?php foreach($this->rules as $i => $rule){ ?>
			<tr class="cpwebservice_rules">
			<td>
			<select name="cpwebservice_rule_classes[<?php echo $i ?>]" data-placeholder="<?php _e('Choose a Shipping Class...' , 'woocommerce-canadapost-webservice' )?>" class="chosen_select">
        	<option value=""></option>
        <?php foreach($shipping_class as $ship) { ?>
				<option value="<?php echo esc_attr($ship->term_id)?>" <?php selected(isset($rule['shipping_class']) && $rule['shipping_class']==$ship->term_id)?>><?php echo esc_html($ship->name)?></option>
		<?php 	}//end foreach ?>
        </select>
        </td><td>
        	<?php $current_group = ''; ?>
			<?php _e('Can only use', 'woocommerce-canadapost-webservice')?>:
			 <select name="cpwebservice_rule_services[<?php echo $i ?>][]" data-placeholder="<?php printf(__('Choose %s Services...' , 'woocommerce-canadapost-webservice' ), $this->get_resource('method_title') )?>" class="widefat chosen_select" multiple>
	            <option value=""></option>
	            <?php foreach($this->available_services as $service_code => $label) { ?>
	            <?php 
	            	$group = $this->get_destination_from_service($service_code);
	            	if ($current_group != $group) { ?>
	            	<?php if ($current_group != '') { echo '</optgroup>'; } // endif; ?>
	            	<?php $current_group = $group; ?>
	            	<optgroup label="<?php echo esc_attr($group)?>">
	            	<?php } // endif ?>
	              		<option value="<?php echo esc_attr($service_code)?>" <?php selected(isset($rule['services']) && is_array($rule['services']) && in_array($service_code,$rule['services']))?>><?php echo esc_html($label)?></option>
	            <?php } // endforeach ?>
	            <?php if ($current_group != '') { ?>
	            	</optgroup>
	            	<?php } // endif; ?>
	            	<option value="CP.NONE" <?php selected(isset($rule['services']) && is_array($rule['services']) && in_array('CP.NONE',$rule['services']))?>><?php printf(__('No %s Shipping', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title') ) ?></option>
	          </select>
	          </td><td>
	          <a href="javascript:;" class="button-secondary btn_cpwebservice_rules"><?php _e('Clear','woocommerce-canadapost-webservice'); ?></a>
	          </td> 
	          </tr>
			<?php
			}// end foreach  ?>
			</table>
			<?php 
		}
    			
    			
		/*
		 * Function that does the lookup with the api.
		 */
		abstract public function call_validate_api_credentials($customerid,$contractid,$api_user,$api_key,$source_postalcode,$mode);
		
		
		/**
		 * Load and generate the template output with ajax
		 */
		public function validate_api_credentials() {
			// Let the backend only access the page
			if( !is_admin() ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
				
			// Check the user privileges
			if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
				
			// Check the nonce
			if( empty( $_GET['action'] ) || !check_admin_referer( $_GET['action'] ) ) {
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
			}
				
			if(empty( $_POST['api_user'] ) &&  $this->get_resource('account_onlyuserid')===true) {
			    wp_die( __( 'API username, API password, Customer ID and Sender Address (Origin Postal Code) are required.' , 'woocommerce-canadapost-webservice' ) );
			}
			
			if( $this->get_resource('account_onlyuserid')!==true && (empty( $_POST['api_user'] )  || empty( $_POST['api_key'] ) || empty( $_POST['customerid'] ) || empty($_POST['source_postalcode']) )) {
    			wp_die( __( 'API username, API password, Customer ID and Sender Address (Origin Postal Code) are required.' , 'woocommerce-canadapost-webservice' ) );
    		}
			
		
			// Nonce.
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_validate_api_credentials' ) )
				wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
				
			// Get api_user, api_key, customerid
			$api_user = sanitize_text_field( $_POST['api_user'] );
			$api_key = sanitize_text_field( $_POST['api_key'] );
			$customerid = sanitize_text_field( $_POST['customerid'] );
			$contractid = sanitize_text_field( $_POST['contractid'] );
			$source_postalcode = sanitize_text_field( $_POST['source_postalcode'] );
			$source_postalcode = str_replace(' ','',strtoupper($source_postalcode)); //N0N0N0 (no spaces, uppercase)
			$mode = isset($_GET['mode']) && ($_GET['mode']=='live' || $_GET['mode'] == 'dev') ? sanitize_text_field( $_GET['mode'] ) : 'dev';
			
			// Do lookup with service. This method outputs the info.
			$this->call_validate_api_credentials($customerid,$contractid,$api_user,$api_key,$source_postalcode, $mode);
			
			
			
			exit;
    }
    			
    /*
     * Required function: GetRates.
     */
    abstract public function get_rates($dest_country, $dest_state, $dest_city, $dest_postal_code, $weight_kg, $length, $width, $height, $services = array(), $add_options = null,  $price_details = null);
	
	/*
	 * Main Lookup Rates function
	 */
	function calculate_shipping( $package = array() ) {
	    	
	    global $woocommerce;
	
	    // Need to calculate total package weight.
	
	    // Get total volumetric weight.
	    $total_quantity = 0;
	    $total_weight = 0;
	    $max = array('length'=>0, 'width'=>0, 'height'=>0);
	    $dimension_unit = get_option( 'woocommerce_dimension_unit' );
	    $weight_unit = get_option( 'woocommerce_weight_unit' );
	    $item_weight = 0;
	    $shipping_weight = 0;
	    $length = $width = $height = 0;
	    $cubic = 0;
	    $products = array();
	
	    foreach ( $package['contents'] as $item_id => $values ) {
	        if ( $values['quantity'] > 0 && $values['data']->needs_shipping() && $values['data']->has_weight() ) {
	            $total_quantity += $values['quantity'];
	            $item_weight = $this->convertWeight($values['data']->get_weight(), $weight_unit);
	            $total_weight +=  $item_weight * $values['quantity'];
	            $length = 0; $width = 0; $height = 0;
	            if ( $values['data']->has_dimensions() ) {
	                $dimensions = explode(' x ',str_replace($dimension_unit,'',$values['data']->get_dimensions()));
	                if (count($dimensions) >= 3) {
	                    // Get cubic size.
	                    $length = $this->convertSize($dimensions[0], $dimension_unit);
	                    $width = $this->convertSize( $dimensions[1], $dimension_unit);
	                    $height = $this->convertSize( $dimensions[2], $dimension_unit);
	                    //  Rotate so that height the smallest dimension. Makes for consistent and efficient packing.
	                    $this->rotate_package($length, $width, $height);
	                }
	
	            } // Allow products with weight-only (no dimensions) to still be calculated.
	            if ($length == 0 && $width==0 && $height == 0 && $item_weight > 0 && $this->options->weight_only_enabled) {
	                // Weight-only so use reverse-volumetric weight.
	                // volumetric weight = cubic / 6000 : 0; //Canada Post: (L cm x W cm x H cm)/6000
	                $volumetric_cubic = $item_weight * 6000;
	                // cube Root volume instead of Boxes. (item is assumed already packaged to ship)
	                $dimension = (float)pow($volumetric_cubic, 1.0/3.0);
	                // A cube is the best estimate we can make.
	                $length = $width = $height = cpwebservice_resources::round_decimal($dimension,3);
	            }
	
	            // Max dimensions
	            if ($length > $max['length']) {  $max['length'] = $length; }
	            if ($width > $max['width']) {  $max['width'] = $width; }
	            if ($height > $max['height']) {  $max['height'] = $height; }
	
	            // Add to Products Array
	            if ($length > 0 && $width > 0 && $height > 0){
	                $product_id = ($values['data']->is_type('variation') ? $values['data']->get_variation_id(): $values['data']->id);
	                // Lookup custom product options
	                $product_shipping_prepacked = get_post_meta( $product_id, '_cpwebservice_product_shipping', true );
	                for($j=0;$j<intval($values['quantity']);$j++){
	                    $products[] = array('length'=>$length, 'width'=>$width, 'height'=>$height, 'item_id'=> $product_id,  'weight'=> $item_weight, 'cubic'=>($length * $width * $height), 'prepacked'=>$product_shipping_prepacked);
	                }
	            }
	            	
	            // Cubic size
	            $cubic +=  $length * $width * $height * $values['quantity'];
	
	            // Cart Logging
	            if ($this->options->log_enable){
	                $this->log->cart[] = array('id'=>$values['data']->id, 'item'=>$values['data']->get_title(),'quantity'=>$values['quantity'], 'weight'=>$item_weight * $values['quantity'],
	                    'length'=>$length, 'width'=>$width, 'height'=>$height, 'cubic'=>($length * $width * $height * $values['quantity']));
	            }
	        }
	    }
	
	    // Box packing!
	    $pack = new cpwebservice_pack();
	
	    // Max box size (container in cm and kg) as defined on Shipping Method documentation. Ex:
	    // http://www.canadapost.ca/cpo/mc/assets/pdf/business/parcelserviceguide_en.pdf (page 43)
	    // Girth: length + (height x 2) + (width x 2)
	    // No one dimension may exceed 2 m (200 cm)
	    // Max. length + girth = 3 m (300 cm)
	    $max_cp_box = $this->get_resource('max_cp_box');  // ex: array('length'=>200 , 'width'=> 200, 'height'=>200, 'girth'=> 300, 'weight'=> 30);
	
	    // Max number rate lookups (To avoid webservice usage issues)
	    $max_cp_lookups = 10;
	
	    // Packages
	    $containers = array();
	
	    // Envelope weight with bill/notes/advertising inserts: ex. 20g
	    $max_cp_box['weight'] -= (!empty($this->options->packageweight) ? floatval($this->options->packageweight) : 0);
	
	    // Add Pre-packed products
	    $products_tmp = array();
	    foreach($products as $i => $p ){
	        if (isset($p['prepacked']) && $p['prepacked']=='yes') {
	            $containers[] = $this->to_container($p);
	        } else {
	            $products_tmp[] = $p;
	        }
	        if ($p['weight'] > $max_cp_box['weight']){
	            // Cart has a product that is too heavy to pack or even mail on its own. Cancel lookups.
	            $products = array();
	        }
	    }
	
	    // Exclude products that are pre-packed
	    if (count($containers) > 0){
	        $products = $products_tmp;
	    }
	
	    // Sort products for consistant packing.
	    usort($products, array(&$this, 'sort_products'));
	
	    // Sort boxes if defined
	    $max_defined_box = null;
	    $pack_with_boxes = ($this->options->boxes_enable && is_array($this->boxes));
	    $box_switch = false; // allows the situation when products don't fit in a defined box to switch to optimistic packing.
	    if($this->options->boxes_enable && is_array($this->boxes)){
	        // rotate boxes so smallest dimension is height.
	        $this->rotate_boxes($this->boxes);
	        // usort(boxes)
	        usort($this->boxes, array(&$this, 'sort_boxes'));
	        // used for packing
	        $max_defined_box =  array('length'=>$max_cp_box['length'] , 'width'=> $max_cp_box['width'], 'height'=>$max_cp_box['height'], 'girth'=> $max_cp_box['girth'], 'weight'=> $max_cp_box['weight']);
	    }
	
	    // Loop over products to pack.
	    while (!empty($products) && is_array($products)){
	
	        // Optimistic pack - no box containers defined.
	        if (!$pack_with_boxes || $box_switch){
	            $products_topack = count($products);
	            // $box_switch = false; // switch back to box mode if that's why it's here.
	            // Pack and Check Container
	            $pack->pack($products, null, $max_cp_box);
	            $packed_boxes = $pack->get_packed_boxes();
	            if (is_array($packed_boxes) && count($packed_boxes) > 0){
	                $container = $pack->get_container_dimensions(); // return array('length' => 0,'width' => 0,'height' => 0);
	                $container['cubic'] = $pack->get_container_volume(); // number
	                $container['weight'] = $pack->get_container_weight();
	                	
	                $container['products'] = $packed_boxes;
	                	
	                // Add to containers array.
	                $containers[] = $container;
	                	
	                // Loop to pack remaining products
	                $products = $pack->get_remaining_boxes();
	            }// endif
	
	            if ($products_topack == count($products) && $products_topack > 0){
	                // No products were packed this loop. (probably too big or too heavy, hitting the max)  Have to finish this while loop.
	                // Break from loop and... ship products individually. Add remaining products as containers themselves
	                $p_containers = array_map(array(&$this,'to_container'), $products);
	                // Add remaining products as containers themselves.
	                foreach($p_containers as $c){
	                    $containers[] = $c;
	                }
	                break;
	            }
	
	        } elseif($pack_with_boxes) {
	            $products_topack = count($products);
	            // Loop [get remaining products] and start with smallest box and go up to biggest box
	            // Boxes have been sorted from smallest to biggest already.
	            $last_box = count($this->boxes);
	            $i = 0;
	            foreach ($this->boxes as $box){
	                $i++;
	                if (!empty($box) && is_array($box)){
	                    if ($i==$last_box){ $max_defined_box['height'] = $box['height'];  } // last/largest box constrain height.
	                    // Pack and Check Container
	                    $pack->pack($products, $box, $max_defined_box);
	                    $packed_boxes = $pack->get_packed_boxes();
	                    if (is_array($packed_boxes) && count($packed_boxes) > 0){
	                        // Great! Products were packed in this box.
	                        $container = $pack->get_container_dimensions(); // return array('length' => 0,'width' => 0,'height' => 0);
	                        if (floatval($container['height']) > floatval($box['height'])) {
	                            // Because 'height' is dynamically calculated so we have made sure it actually fits inside this box)
	                            // If the dynamic height is greater than the box, we need to skip this box.
	                            continue;
	                        }
	                        $container['cubic'] = $pack->get_container_volume(); // number
	                        $container['weight'] = $pack->get_container_weight();
	                        $container['box_weight'] = $box['weight'];
	                        $container['box_margin'] = $box['margin'];
	                        $container['box_name'] 	 = $box['name'];
	                        	
	                        $container['products'] = $packed_boxes;
	                        	
	                        // If the box has weight, add it to the shipping weight.
	                        if (floatval($container['box_weight']) > 0){
	                            $container['weight'] += floatval($container['box_weight']);
	                        }
	                        	
	                        // Container height is dynamic in the box-packing algorithm.  Set to the defined box height, because that's the true package size.
	                        $container['height'] = $box['height'];
	                        // Recalc cubic because of height change.
	                        $container['cubic'] = $container['length'] * $container['width'] * $container['height'];
	                        	
	                        // Add to containers array.
	                        $containers[] = $container;
	                        	
	                        // Loop to pack remaining products
	                        $products = $pack->get_remaining_boxes();
	                    } // end if
	                    // If none remaining, break from foreach.
	                    if (!is_array($products) || count($products) == 0){
	                        break;
	                    }
	                } // endif
	                // else loop and try next smallest box.
	                	
	            } // end foreach
	
	            if ($products_topack == count($products) && $products_topack > 0){
	                // No products were packed this loop. (probably too big or too heavy, hitting the max)  Have to finish this while loop.
	                // Switch to non-box method? (TODO Configurable in options)
	                if ($this->options->boxes_switch){
	                    $box_switch = true;
	                    continue;
	                } else {
	                    // Alternatively, break from loop and... ship products individually. Add remaining products as containers themselves
	                    array_push($containers, array_map(array(&$this,'to_container'), $products));
	                    break;
	                }
	            } // endif
	        } // endif
	
	    } // end while
	
	    // Done Box Packing!
	    $legacy_volumetric_packing = false;
	    if ($legacy_volumetric_packing){
	
	        // Find which box the items will fit in (by cubic + packaging factor).
	        $box_fits = null; // to fit.
	
	        if ($this->options->boxes_enable && is_array($this->boxes)){
	            foreach ($this->boxes as $box){
	                $box_cubed = $box['cubed'];
	                if ($cubic < $box_cubed && $this->boxFits($box,$max)){ // volume fitting and check for max dimension fitting.
	                    // It Fits!
	                    if (empty($box_fits)) {
	                        $box_fits = $box;
	                        // Use if smaller than previously iterated box that fits.
	                    } elseif (is_array($box_fits) && $box_cubed < $box_fits['cubed']) {
	                        $box_fits = $box;
	                    }
	                }
	            }
	        }
	        	
	        if (empty($box_fits)) { // If box was not found or boxes are not enabled
	            if (count($products)>1){
	                // Method: Cube Root volume instead of Boxes. (item is assumed already packaged to ship)
	                $dimension = (float)pow($cubic, 1.0/3.0);
	                // use max dimensions to ensure an item like 1x1x20 is estimated with enough length.
	                $box_fits = array('cubed'=>$cubic, 'length'=>($dimension < $max['length'] ? $max['length'] : $dimension), 'width'=>($dimension < $max['width'] ? $max['width'] : $dimension), 'height'=>($dimension < $max['height'] ? $max['height'] : $dimension));
	            } elseif(count($products)==1) {
	                // only one product
	                $box_fits = array('cubed'=>$cubic, 'length'=> $products[0]['length'], 'width'=> $products[0]['width'], 'height'=> $products[0]['height']);
	            }
	        }
	        	
	        // Calculate product weight.
	        $packed_boxes_weight = floatval(0);
	        foreach($products as $p ){
	            $packed_boxes_weight += isset($p['weight']) ? floatval($p['weight']) : 0;
	        }
	        $box_fits['weight'] = $packed_boxes_weight + (isset($box_fits['weight']) ? floatval($box_fits['weight']) : 0); // Also add box weight if it exists.
	        	
	        $container = array($box_fits);
	
	    } // end if
	
	    // Destination information (Need this to calculate rate)
	    $country = $package['destination']['country']; // 2 char country code
	    $state = $package['destination']['state']; // 2 char prov/state code
	    $postal = $package['destination']['postcode']; // postalcode/zip code as entered by user.
	    $city = !empty($package['destination']['city']) ? $package['destination']['city'] : null; // city as entered in checkout. (used in international rate calculation).
	
	    // Get a rate to ship the package.
	    if ($country != '' && is_array($containers) && !empty($postal)) {
	        
	        // Determine origin postal code (if needed)
	        if ($this->options->geolocate_origin && ($country=='CA' || $country == 'US')){
	           // Get array of origin postal codes.
	           $origin = array();
	           foreach($this->shipment_address as $a){
	               if (isset($a['origin']) && $a['origin'] && !empty($a['postalcode']) && isset($a['postalcode_lat']) && isset($a['postalcode_lng'])){
	                   $origin[$a['postalcode']] = array($a['postalcode_lat'], $a['postalcode_lng']);
	               }
	           }
	           // Require more than 1 to make it worth it. (Otherwise, it'll just be the default, which is saved to options->source_postalcode
	           if (count($origin) > 1){
	               if($this->options->geolocate_limit){
    	               // Determine if Products are restricted to certain warehouses.
    	               $limit_warehouse = $this->get_product_warehouses($containers);
    	               // Modify $origin array if needed; Split shipment if $origin would be empty from a conflict.
    	               if (!empty($limit_warehouse)){
    	                   $limited_origin = $this->limit_product_warehouse_origin($origin, $limit_warehouse, $this->shipment_address);
    	               }
	               }
	               // Look up Lat/Lng for destination postal.
	               $distance = 0;
	               $min_distance = 999999;
	               $source_postalcode = $this->options->source_postalcode;
	               $geo = new cpwebservice_location();
	               foreach($origin as $origin_postal=>$loc){
	                   // if it happens..
	                   if ($geo->postal_prefix($origin_postal) == $geo->postal_prefix($postal)){
	                       $source_postalcode = $origin_postal;
	                       break;
	                   }
	                   // Calculate distance 
	                   $distance = $geo->distance($loc[0], $loc[1], $country, $state, $postal);
	                   // Distance null means the calculation failed.
	                   // Finding the origin with the least amount of distance to the destination.
	                   if ($distance != null && $distance < $min_distance){
	                       $min_distance = $distance;
	                       $source_postalcode = $origin_postal;
	                   }
	               }
	               // Min-distance $source_postalcode
	               $this->options->source_postalcode = $source_postalcode;
	           }
	        }
	        
	        // Loop $containers to get_rates.
	        $rates = array();
	        $rates_combined = array();
	        $cp_lookups = 0;
	        // Ensure service codes are in _all_ rate objects
	        $distinct_service_codes = array();
	        foreach($containers as $i=>$shipping_package) {
	            $total_weight = ($shipping_package['weight'] > 0) ? $shipping_package['weight'] : 0;
	            if ($this->options->volumetric_weight){
	                $volumetric_weight = $shipping_package['cubic'] > 0 ? $shipping_package['cubic'] / 6000 : 0; //Canada Post: (L cm x W cm x H cm)/6000
	                // Use the largest value of total weight or volumetric/dimensional weight
	                $shipping_weight = ($total_weight <= $volumetric_weight && $volumetric_weight <= $max_cp_box['weight']) ? $volumetric_weight : $total_weight;
	            } else {
	                $shipping_weight = $total_weight;
	            }
	            // Envelope weight with bill/notes/advertising inserts: ex. 20g
	            $shipping_weight += (!empty($this->options->packageweight) ? floatval($this->options->packageweight) : 0);
	            // update weight
	            $containers[$i]['actual_weight'] = $shipping_package['weight'];
	            $containers[$i]['weight'] = $shipping_weight;
	            $limit_services = null;
	            if (isset($this->options->rules_enable) && $this->options->rules_enable){
	                $limit_services = $this->get_limited_services($shipping_package['products']);
	                if (in_array('CP.NONE', $limit_services)) {
	                    // Oops, not supposed to ship with this method.
	                    $rates = null;
	                    break;
	                }
	            }
	
	            $shipping_weight = round($shipping_weight,2); // 2 decimal places.
	            $length = round($shipping_package['length'], 2);
	            $width = round($shipping_package['width'], 2);
	            $height = round($shipping_package['height'], 2);
	
	            // Debug
	            if ($this->options->log_enable){
	                $this->log->params[] = array('country'=>$country, 'state'=>$state, 'postal'=>$postal, 'shipping_weight'=>$shipping_weight, 'total_weight'=>$total_weight, 'length'=>$length, 'width'=>$width, 'height'=>$height, 'box_name'=>(isset($shipping_package['box_name']) ? $shipping_package['box_name'] : ''), 'source_postalcode'=> $this->options->source_postalcode);
	            }
	
	            // In subsequent iterations, use Rates from a previous package/container that is identical to current container. Else do a rates lookup.
	            $result_previous = $this->get_rates_previous($i, $containers);
	
	            if ($result_previous != null) {
	                $rates[] = $result_previous;
	            } else {
	                $cp_lookups++;
	                if ($cp_lookups > $max_cp_lookups){
	                    // Error. Protect from too many lookups.
	                    $rates = null;
	                    break;
	                }
	
	                // WebServices Lookup
	                $result = $this->get_rates($country, $state, $city, $postal, $shipping_weight, $length, $width, $height, $limit_services);
	                $rates[] = $result;
	                if (empty($result)){
	                    // Rate was not found: Usually because package is too big or api error. Can't display because it would cause an incorrect cost.
	                    $rates = null;
	                    break;
	                }
	            }
	            // Save to container
	            $containers[$i]['rate'] = $result;
	            // Save distinct rate service_codes.
	            $distinct_service_codes = $this->get_distinct_service_codes($distinct_service_codes, $result);
	        }
	        	
	        if (!empty($rates)){
	            // Combine rates result sets into $rates_combined
	
	            if (count($rates) > 1 && count($rates[0]) > 0){
	                // Loop through rates by type, combine to get total cost/type array().
	                	
	                // Add first rate to $rates_combined.
	                for($j=0;$j<count($rates[0]);$j++){
	                    $rates_combined[] = clone($rates[0][$j]);
	                }
	                	
	                // Now loop through the remainder of the rates array to create aggragate sum of prices.
	                for($i=1;$i<count($rates);$i++){
	                    // Loop over objects.
	                    for($j=0;$j<count($rates[$i]);$j++){
	                        // Match using Service Code
	                        for($r=0; $r<count($rates_combined); $r++){
	                            if ($rates_combined[$r]->service_code == $rates[$i][$j]->service_code){
	                                // Sum Rate price
	                                $rates_combined[$r]->price = floatval($rates_combined[$r]->price) + floatval($rates[$i][$j]->price);
	                            }
	                        } // end foreach
	                    }// end for
	                }// end for
	                	
	                // Now loop through the $rates_combined array and only keep the rates that are in distinct_service_codes.
	                if (!empty($distinct_service_codes)){
	                    for ($i=0;$i<count($rates_combined);$i++){
	                        // Check for valid service codes.
	                        if (!in_array($rates_combined[$i]->service_code, $distinct_service_codes)) {
	                            // oops, this service_code is not one of the distinct_service_codes. It has not been an aggragate sum so it needs to be removed.
	                            unset($rates_combined[$i]);
	                        }
	                    }
	                }
	                	
	            } else {
	                // Only 1 rates result set
	                $rates_combined = $rates[0];
	            }
	
	            // If services are the same cost, only keep the better service. (ie. Regular Parcel vs Epedited Parcel same cost)
	            if ($this->options->prefer_service===true && count($rates_combined) > 1){
	                $better_service = array_keys($this->available_services); // These are ordered by lowest to best already.
	                $prev_cost = 0;
	                $prev_service_code = '';
	                // sort rates by lowest cost.
	                usort($rates_combined, array(&$this, 'sort_rate_services'));
	                // Loop from lowest to highest cost.
	                for($i=0;$i<count($rates_combined);$i++){
	                    if ($rates_combined[$i]->price == $prev_cost){
	                        // Check their service_code position in the $better_service array.
	                        if (array_search($rates_combined[$i]->service_code, $better_service) > array_search($prev_service_code, $better_service)){
	                            // Remove the 'lower' service because it has the same cost.
	                            unset($rates_combined[$prev_index]);
	                        } else {
	                            unset($rates_combined[$i]);
	                            continue;
	                        }
	                    }
	                    $prev_cost = $rates_combined[$i]->price;
	                    $prev_service_code = $rates_combined[$i]->service_code;
	                    $prev_index = $i;
	                }
	                	
	            }
	
	            // Do final foreach($rates_combined)
	
	            foreach($rates_combined as $rate){
	                if (!empty($this->options->margin) && $this->options->margin != 0) {
	                    $rate->price = $rate->price * (1 + $this->options->margin/100); //Add margin
	                }
	                if (!empty($this->options->margin_value) && $this->options->margin_value != 0) {
	                    $rate->price = $rate->price + $this->options->margin_value; //Add margin_value
	                    if ($rate->price < 0){ $rate->price = 0; }
	                }
	                	
	                $box_margin = $this->get_box_margin_sum($containers);
	                if ($box_margin != 0){
	                    $rate->price = $rate->price + $box_margin; //Add box margin if any value exists.
	                    if ($rate->price < 0){ $rate->price = 0; }
	                }
	                	
	                $delivery_label = '';
	                if (!empty($this->options->delivery) && $rate->expected_delivery != '') { 
	                    $delivery_label =  ' (' . (!empty($this->options->delivery_label) ? woocommerce_clean($this->options->delivery_label) :  __('Delivered by', 'woocommerce-canadapost-webservice')) . ' ' . $rate->expected_delivery . ')';
	                    if (isset($rate->guaranteed) && ($rate->guaranteed == false) && isset($this->options->delivery_guarantee) && $this->options->delivery_guarantee) {
	                        $delivery_label = ''; // only display Delivery label on Guaranteed services (when $this->options->delivery_guarantee is enabled).
	                    }
	                }
	                	
	                $rateitem = array(
	                    'id' 		=> $this->rate_id($rate->service_code), // $this->id .':'.($this->instance_id > 0 ? $this->instance_id.':':''). $rate->service_code,
	                    'label' 	=> $rate->service . $delivery_label,
	                    'cost' 		=> $rate->price,
	                    'package'   => $package
	                );
	                // Register the rate
	                $this->add_rate( $rateitem );
	                	
	            }
	        } // endif
	
	        // Lettermail Limits.
	        if ($this->options->lettermail_limits=='1' && !empty($this->options->lettermail_maxlength) && !empty($this->options->lettermail_maxwidth) && !empty($this->options->lettermail_maxheight)) {
	            // Check to see if within lettermail limits.
	            $lettermail_cubic =  $this->options->lettermail_maxlength * $this->options->lettermail_maxwidth * $this->options->lettermail_maxheight;
	            if ($lettermail_cubic > 0) {
	                if ($max['length'] <= $this->options->lettermail_maxlength && $max['width'] <= $this->options->lettermail_maxwidth && $max['height'] <= $this->options->lettermail_maxheight
	                    && $cubic <= $lettermail_cubic) {
	                        // valid, within limit.
	                    } else {
	                        // over limit. Disable lettermail rates from being applied.
	                        $this->options->lettermail_enable = 0;
	                    }
	            }
	        }
	
	        if ($this->options->lettermail_enable=='1'){
	            /*
	             Letter-post / Flat Rates
	             */
	            // If override packing weight, remove package weight and add custom package weight.
	            if (!empty($this->options->lettermail_override_weight) && $this->options->lettermail_override_weight){
	                $shipping_weight -= (!empty($this->options->packageweight) ? floatval($this->options->packageweight) : 0);
	                //$shipping_weight -= (!empty($box_fits['weight']) ? floatval($box_fits['weight']) : 0);
	                $shipping_weight += (!empty($this->options->lettermail_packageweight) ? floatval($this->options->lettermail_packageweight) : 0);
	                $shipping_weight = round($shipping_weight,2); // 2 decimal places.
	            }
	            // Subtotal for lettermail min/max subtotal calculation.
	            $cart_subtotal = $woocommerce->cart->subtotal;
	            if ($this->options->lettermail_exclude_tax){
	                $tax = $woocommerce->cart->get_taxes_total(false, false);
	                // Remove tax from subtotal.
	                if (is_numeric($tax)){ $cart_subtotal -= floatval($tax); }
	            }
	
	            foreach($this->lettermail as $lettermail) {
	                if ($shipping_weight >= $lettermail['weight_from'] && $shipping_weight < $lettermail['weight_to']
	                    && ($country == $lettermail['country'] || ($lettermail['country']=='INT' && $country!='CA' && $country !='US'))
	                    && (empty($lettermail['prov']) || $state ==  $lettermail['prov'])
	                    && (empty($lettermail['max_qty']) || $total_quantity <=  $lettermail['max_qty'])
	                    && (empty($lettermail['min_total']) ||  $cart_subtotal >= $lettermail['min_total'])
	                    && (empty($lettermail['max_total']) ||  $cart_subtotal <= $lettermail['max_total'])
	                ){
	                    	
	                    $rateitem = array(
	                        'id' 		=>  $this->rate_id('Lettermail '.$lettermail['label']), // $this->id .':'.($this->instance_id>0 ? $this->instance_id.':':'').'Lettermail '.$lettermail['label'],
	                        'label' 	=> $lettermail['label'],
	                        'cost' 		=> $lettermail['cost'],
	                        'package'   => $package
	                    );
	                    $this->add_rate( $rateitem );
	                }
	            }
	
	        }
	        	
	        // Save shipping info to save with order to session.
	        $shipping_info = array('rates'=>$rates_combined, 'packages'=>$containers, 'origin'=>$this->options->source_postalcode);
	        do_action('cpwebservice_order_shipping_info', $shipping_info);
	
	        // Sort rates (by lowest cost)
	        if(!empty($this->rates)){
	            // Sort associated array.
	            uasort($this->rates, array(&$this, 'sort_rates'));
	        }
	    }
	    // Logging
	    if ( $this->options->log_enable ){
	        $this->log->rates = $this->rates;
	        $this->log->datestamp = current_time('timestamp');
	        // Save to transient for 20 minutes.
	        set_transient( 'cpwebservice_log', $this->log, 20 * MINUTE_IN_SECONDS );
	    }
	
	}
	
	// Sort Rates function
	function sort_rates($a, $b){
	    if ($a->cost == $b->cost) {
	        return 0;
	    }
	    return ($a->cost < $b->cost) ? -1 : 1;
	}
	
	// Sort rate services function
	function sort_rate_services($a, $b){
	    if ($a->price == $b->price) {
	        return 0;
	    }
	    return ($a->price < $b->price) ? -1 : 1;
	}
	
	// Sort Boxes function. Ascending
	function sort_boxes($a, $b){
	    $a_max = max($a['length'], $a['width'], $a['height']);
	    $b_max = max($b['length'], $b['width'], $b['height']);
	    if ($a['cubed'] == $b['cubed'] && $a_max == $b_max) {
	        return 0;
	    }
	    if ($a['cubed'] == $b['cubed'] && $a_max < $b_max) {
	        return -1;
	    }
	    return ($a['cubed'] < $b['cubed']) ? -1 : 1;
	}
	
	// Sort Products function.  Descending (biggest box first)
	function sort_products($a, $b){
	    $a_max = max($a['length'], $a['width'], $a['height']);
	    $b_max = max($b['length'], $b['width'], $b['height']);
	    if ($a['cubic'] == $b['cubic'] && $a_max == $b_max) {
	        return 0;
	    }
	    if ($a['cubic'] == $b['cubic'] && $a_max < $b_max) {
	        return 1;
	    }
	    return ($a['cubic'] < $b['cubic']) ? 1 : -1;
	}
	
	// Rotates so that height is the smallest dimension.
	// Also converts dimensions to float vals instead of strings.
	function rotate_package(&$length, &$width, &$height){
	    $length = floatval($length);
	    $width = floatval($width);
	    $num = $height = floatval($height);
	    if ($height > $length){
	        $height = $length;
	        $length = $num;
	    }
	    if ($height > $width){
	        $height = $width;
	        $width = $num;
	    }
	}
	// Rotates so that for each box, height is the smallest dimension
	function rotate_boxes(&$boxes){
	    for ($i=0;$i<count($boxes);$i++){
	        $this->rotate_package($boxes[$i]['length'], $boxes[$i]['width'], $boxes[$i]['height']);
	    }
	}
	
	// Gets distinct service codes.
	// @unique array
	function get_distinct_service_codes($unique, $results){
	    $service_codes = array();
	    if (is_array($results)){
	        for($i=0;$i<count($results);$i++){
	            if (!in_array($results[$i]->service_code, $service_codes)){
	                $service_codes[] = $results[$i]->service_code;
	            }
	        }
	    }
	    if (empty($unique)){ // if empty, this is the first $results set.
	        return $service_codes;
	    } else {
	        // Go through $unique array and remove any service_code not found.
	        for ($i=0;$i<count($unique);$i++){
	            if (!in_array($unique[$i], $service_codes)){
	                unset($unique[$i]);
	            }
	        }
	        	
	        return $unique;
	    }
	}
	
	function get_rates_previous($index, $containers){
	    $rate = null;
	    // after the 1st item.
	    if ($index > 0){
	        // Get Package/Container unique features.
	        $weight_kg = $containers[$index]['weight'];
	        $length = $containers[$index]['length'];
	        $width = $containers[$index]['width'];
	        $height = $containers[$index]['height'];
	        $cubic = $containers[$index]['cubic'];
	
	        // Look for previous container that is identical (and has rates -- after all, that's what we're looking for).
	        for($j=0;$j<$index;$j++){
	            if ($containers[$j]['weight'] == $weight_kg && $containers[$j]['length'] == $length && $containers[$j]['width'] == $width
	                &&  $containers[$j]['height'] == $height && $containers[$j]['cubic'] == $cubic && $containers[$j]['rate']!=null){
	                // Huston, we have a match.
	                return $containers[$j]['rate'];
	            }
	        }
	        	
	    }
	    return $rate;
	}
	
	function get_box_margin_sum($containers){
	    $margin = 0;
	    foreach($containers as $container){
	        if (isset($container['box_margin']) && floatval($container['box_margin']) != 0){
	            $margin += floatval($container['box_margin']);
	        }
	    }
	    return $margin;
	}
	
	// Format Delivery date from API.
	public function format_expected_delivery($expected_delivery){
	    if (!empty($expected_delivery) && !empty($this->options->delivery_format) && 
	        ($this->options->delivery_format == 'D M j, Y' || $this->options->delivery_format == 'F j, Y' || $this->options->delivery_format == 'M j, Y')){
	        // Try to parse time.
	       if (($expected_delivery_time = DateTime::createFromFormat('Y-m-d', $expected_delivery )) !== false ){
	            // Wordpress Date-formatted return.
	            if ($this->options->delivery_format == 'D M j, Y' || $this->options->delivery_format == 'F j, Y' || $this->options->delivery_format == 'M j, Y'){
	               return date_i18n( $this->options->delivery_format , $expected_delivery_time->getTimestamp() );
	            } else { 
	                return date( 'Y-m-d', $expected_delivery_time->getTimestamp() );
	            }
	        }
	    }
	    return $expected_delivery;
	}

	
	public function get_limited_services($products){
	    $services = array();
	    $services_rules = array();
	    if (isset($this->rules) && is_array($this->rules) && count($this->rules) > 0){
	        $ids = $this->get_product_ids($products);
	        if (!empty($ids)){
	            $term_list = wp_get_object_terms($ids, 'product_shipping_class', array('fields' => 'ids'));
	            // Check to see if these products are in a Shipping Class.
	            if (!empty($term_list) && is_array($term_list)){
	                // In Shipping Class. Check rules table.
	                foreach($this->rules as $rule){
	                    if (isset($rule['shipping_class']) && isset($rule['services']) && is_array($rule['services'])){
	                        if (in_array($rule['shipping_class'], $term_list)){
	                            // Shipping Class matches a rule.
	                            $services_rules[] = $rule['services'];
	                        }
	                    }
	                }
	            }
	        }
	    }
	    if (count($services_rules) > 1){
	        //Only use values that are present in _all_ rules.  array_intersect all rule arrays.
	        $services=call_user_func_array('array_intersect', $services_rules);
	    } elseif (count($services_rules) == 1){
	        $services= $services_rules[0];
	    }
	    return $services;
	}
		
	// Get item_ids into an easy-to-use array.
	private function get_product_ids($products){
	    $ids = array();
	    foreach($products as $level){
	        foreach($level as $p){
	            if (isset($p['item_id'])){
	                $ids[] = $p['item_id'];
	            }
	        }
	    }
	    $ids = array_unique($ids);
	    return $ids;
	}
		
	// Converts a product array to a container array.
	private function to_container($product){
	    // put product into its own ['products']
	    $container = $product;
	    // products are inside a level array.
	    $container['products'] = array(array($product));
	    return $container;
	}
	
	// Param package arrays and returns an array of any products set to specific warehouses.
	private function get_product_warehouses($products){
	    $product_ids = $this->get_product_ids($products);
	    $warehouses = array();
	    foreach($product_ids as $id){
	        $product_warehouse = get_post_meta( $id, '_cpwebservice_product_warehouse', true );
	        if (!empty($product_warehouse) && is_array($product_warehouse)){
	            
	            $warehouses[$id] = $product_warehouse;
	            if (empty($warehouses['summary'])){ $warehouses['summary'] = $product_warehouse; }
	            else {
	                // Only keep unique address_index_ids
	               $warehouses['summary'] = array_unique( $warehouses['summary'] + $product_warehouse );
	            }
	        }
	    }
	    return $warehouses;
	}
	
	// Provides a valid list of origins for given limit_warehouse array.
	private function limit_product_warehouse_origin($origin, $limit_warehouse, $address) 
	{
	    // Only limit if there are warehouses defined for a given product.
	    if (!empty($limit_warehouse) && !empty($limit_warehouse['summary'])){
    	    foreach($address as $id => $a){
    	        
    	        if (isset($a['origin']) && $a['origin'] && !empty($a['postalcode']) && isset($a['postalcode_lat']) && isset($a['postalcode_lng'])){
    	            if (!in_array($id, $limit_warehouse['summary']) ){
    	                 // Origin is invalid for a certain product.
    	                 unset($origin[$a['postalcode']] );
    	            }
    	        }
    	    }
	    }
	}

	// Box fitting function
	function boxFits($box, $max){
	    if (is_array($box) && is_array($max)){
	        $fitbox = array($box['width'], $box['height'], $box['length']);
	        $fitmax = array($max['width'], $max['height'], $max['length']);
	        	
	        $it_fits = true;
	        	
	        while (count($fitbox) > 0){
	            // Compare the maximum dimensions with each other.
	            // If the MaxDim is bigger than the Box Dim, then it doesn't fit.
	            $b = max($fitbox);
	            $m = max($fitmax);
	            if ($m > $b){
	                $it_fits = false;
	                break;
	            } else {
	                // Remove value from each array.
	                // Remove one max value from Box
	                $b_index = array_search($b, $fitbox);
	                if (false !== $b_index) {
	                    unset($fitbox[$b_index]);
	                } else { $it_fits = false; break; } // safety so loop doesn't continue.
	                // Remove one max value from MaxDimension
	                $m_index = array_search($m, $fitmax);
	                if (false !== $m_index) {
	                    unset($fitmax[$m_index]);
	                } else { $it_fits = false; break; } // safety
	            }
	        }
	        	
	        return $it_fits;
	    }
	    return false;
	}
	
	// Converts array of 3 dimensions to $box[] associated array.
	public function to_box($dimensions, $unit='cm'){
	    if (!empty($dimensions) && is_array($dimensions) && count($dimensions)==3){
	        if ($unit!='cm') { 
	             $dimensions[0] = $this->convertSize($dimensions[0], $unit); 
    	         $dimensions[1] = $this->convertSize($dimensions[1], $unit);
    	         $dimensions[2] = $this->convertSize($dimensions[2], $unit);
	        }
	        $box = array('length' => $dimensions[0], 'width'=> $dimensions[1], 'height'=>$dimensions[2], 'cubed'=>($dimensions[0]*$dimensions[1]*$dimensions[2]));
	        return $box;
	    }
	    return array();
	}
	
	
	/**
	 * Calculates girth from rectangular package dimensions.
	 * @param number $length
	 * @param number $width
	 * @param number $height
	 * @return number girth
	 */
	function calc_girth($length,$width, $height){
	
	    $longest = max($length,$width, $height);
	
	    // Longest side is not included in girth, as it is perpendicular.
	    if ($longest == $length){
	        return $width * 2 + $height * 2;
	    }else if ($longest == $width){
	        return $length * 2 + $height * 2;
	    } else { //$longest == $height
	        return $width * 2 + $length * 2;
	    }
	}
	
	/**
	 * Returns a rate ID based on this methods ID and instance, with an optional
	 * suffix if distinguishing between multiple rates.
	 */
	public function rate_id( $suffix = '' ) {
	    if (method_exists($this, 'get_rate_id')){
	        return $this->get_rate_id($suffix);
	    }
	    // get_rate_id
	    $rate_id = array( $this->id );
	    if ( $this->instance_id ) {
	        $rate_id[] = $this->instance_id;
	    }
	    if ( $suffix ) {
	        $rate_id[] = $suffix;
	    }
	    return implode( ':', $rate_id );
	}
		
	// Get service code part of field name.
	public function get_service_code_field($service_code){
	    return strtolower(preg_replace("/[^A-Za-z0-9]/", '', $service_code));
	}
	
	// Gets service name
	public function service_name_label($service_code, $service_label){
	    if(!empty($this->service_labels) && !empty($this->service_labels[$service_code])){
	        return woocommerce_clean($this->service_labels[$service_code]);
	    }
	    // Return supplied label.
	    return $service_label;
	}
	
	/*
	 * Convert size to cm
	 */
	public function convertSize($size,$unit_from) {
	    $size = floatval($size);
	    $finalSize = $size;
	    switch ($unit_from) {
	        // we need the units in cm
	        case 'cm':
	            // change nothing
	            $finalSize = $size;
	            break;
	        case 'in':
	            // convert from in to cm
	            $finalSize = $size * 2.54;
	            break;
	        case 'yd':
	            // convert from yd to cm
	            $finalSize = $size * 3 * 2.54;
	            break;
	        case 'm':
	            // convert from m to cm
	            $finalSize = $size * 100;
	            break;
	        case 'mm':
	            // convert from mm to cm
	            $finalSize = $size * 0.1;
	            break;
	    }
	    return $finalSize;
	}
	
	/*
	 * Convert Weight to Kg
	 */
	public function convertWeight($weight,$unit_from) {
	    $finalWeight = $weight;
	    switch ($unit_from) {
	        // we need the units in kg
	        case 'kg':
	            // change nothing
	            $finalWeight = $weight;
	            break;
	        case 'g':
	            // convert from g to kg
	            $finalWeight = $weight * 0.001;
	            break;
	        case 'lbs':
	            // convert from lbs to kg
	            $finalWeight = $weight * 0.4535;
	            break;
	        case 'oz':
	            // convert from oz to kg
	            $finalWeight = $weight * 0.0283;
	            break;
	    }
	    return $finalWeight;
	}
	
	// Section: Size Display Options
	
	// Display Size (as option determines)
	public function display_unit($cm){
	    return $this->options->display_units == 'in' ?  cpwebservice_resources::round_decimal(cpwebservice_resources::cm_to_in($cm),3) :  cpwebservice_resources::round_decimal($cm,3);   
	}
	
	public function display_unit_cubed($cm3){
	    return $this->options->display_units == 'in' ? cpwebservice_resources::round_decimal(cpwebservice_resources::cm3_to_in3($cm3),3) :  cpwebservice_resources::round_decimal($cm3,3);
	}
	
	
	// Display Weight
	public function display_weight($kg){
	    return $this->options->display_weights == 'lbs' ? cpwebservice_resources::round_decimal(cpwebservice_resources::kg_to_lb($kg),3) : cpwebservice_resources::round_decimal($kg,3);
	}
	
	// Save Size (to cm)
	// Returns cm
	public function save_unit($size){
	    return $this->options->display_units == 'in' ?  floatval(number_format(cpwebservice_resources::in_to_cm($size),4,'.','')) :  floatval(number_format($size,4,'.',''));
	}
	
	// Save weight (to kg)
	// Returns kg.
	public function save_weight($weight){
	    return $this->options->display_weights == 'lbs' ? floatval(number_format(cpwebservice_resources::lb_to_kg($weight),4,'.','')) : floatval(number_format($weight,4,'.',''));
	}
	
	// END Section Size/Weight Display Options.
	
}