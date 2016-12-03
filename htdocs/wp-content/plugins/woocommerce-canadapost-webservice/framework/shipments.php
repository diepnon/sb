<?php
/*
 Shipments (Order Details page)
woocommerce_cpwebservice_shipments.php

Copyright (c) 2013-2015 Jamez Picard

*/
abstract class cpwebservice_shipments
{
	/**
	 * __construct function.
	 *
	 * @access public
	 * @return woocommerce_cpwebservice_shipments
	 */
	function __construct() {
	
		$this->init();
	}
	
	private $default_shipment = array('order_id'=>0,'sender_address_index' => 0, 'shipment_type'=>'Package', 'shipment_dimensions'=>'cm', 'package'=>array(),'reference'=>'', 'reference_cost'=>'', 'reference_additional'=>'', 'shipping_point_id'=>'', 'pickup_indicator'=>'dropoff', 'payment_method'=>'CreditCard',
	                                  'custom_products' => array(), 'customs_currency'=>'CAD', 'customs_export'=>'', 'customs_export_other'=> '', 'customs_invoice'=> '', 'customs_certificateid'=> '', 'customs_licenseid'=>'', 'customs_nondelivery'=> '', 'email_on_shipment'=>'', 'email_on_exception'=>'', 'email_on_delivery'=>'',
                                      'opt_signature'=>false, 'opt_promocode'=>'', 'opt_packinginstructions'=>true, 'opt_postrate'=>false, 'opt_insuredvalue'=>false,  'opt_delivery_confirmation'=>false, 'opt_outputformat'=>'', 'opt_required'=>'','opt_delivery_door'=>'', 'insurance'=> '', 'label'=> null
	);
	private $default_customs = array(array('sku'=>'', 'quantity'=>'', 'description'=>'', 'unitcost'=>'','unitweight'=>'', 'hs_code'=>'', 'origin_country'=>'', 'origin_prov'=>''));
	public $cachemode = 'dbcache';
	
	/*
	 * Init
	*/
	function init() {
	    $default_options        = array('enabled'=>'no', 'api_user'=>'', 'api_key'=>'','account'=>'','contractid'=>'','source_postalcode'=>'','mode'=>'live','shipments_enabled'=> true, 'shipment_mode' => 'dev', 'shipment_log'=>false, 'api_dev_user'=>'', 'api_dev_key'=>'', 'display_units'=>'cm', 'display_weights'=>'kg','template_package'=>true,'template_customs'=>true,'shipment_hscodes'=>false); 
		$this->options          = get_option('woocommerce_cpwebservice', $default_options);
		$this->options          = (object) array_merge((array) $default_options, (array) $this->options); // ensure all keys exist, as defined in default_options.
		$this->log 	            = (object) array('order'=>array(),'params'=>array(),'request'=>array('http'=>'','service'=>''),'shipment'=>array());
		$this->shipment_address = (array)get_option('woocommerce_cpwebservice_shipment_address', array());
		$this->current_shipment = array('orderid'=>0,'sender_address_index' => 0);
		$this->current_rate     = null;
		$this->shippingmethod   = null; // init when needed.
		$this->orderdetails     = null;
		$this->templates        = null; // init when needed.
		if ($this->options->enabled){
			// Wire up actions
			if (is_admin()){
				add_action( 'wp_ajax_cpwebservice_create_shipment' , array(&$this, 'create_shipment_form')  );
				add_action( 'wp_ajax_cpwebservice_create_shipment_summary' , array(&$this, 'create_shipment_summary')  );
				add_action( 'wp_ajax_cpwebservice_save_draft_shipment' , array(&$this, 'save_draft_shipment')  );
				add_action( 'wp_ajax_cpwebservice_save_shipment' , array(&$this, 'save_shipment')  );
				add_action( 'wp_ajax_cpwebservice_shipment_label_pdf' , array(&$this, 'shipment_label_pdf')  );
				add_action( 'wp_ajax_cpwebservice_shipment_refund' , array(&$this, 'shipment_refund')  );
				add_action( 'wp_ajax_cpwebservice_shipment_remove' , array(&$this, 'shipment_remove')  );
				add_action( 'wp_ajax_cpwebservice_save_shipment_template' , array(&$this, 'shipment_template')  );
				add_action( 'wp_ajax_cpwebservice_shipment_template_list' , array(&$this, 'shipment_template_list')  );
				add_action( 'wp_ajax_cpwebservice_shipment_template_remove' , array(&$this, 'shipment_template_remove')  );
				if (did_action('wp_scheduled_delete') > 0 && $this->cachemode=='dbcache'){ $this->delete_expired_transients(); }	
				// Display Units (only in/lb and cm/kg supported).
				$dimension_unit                = get_option( 'woocommerce_dimension_unit' );
				$this->options->display_units  = $dimension_unit == 'in' ? 'in' : 'cm';
				$weight_unit                   = get_option( 'woocommerce_weight_unit' );
				$this->options->display_weights= $weight_unit == 'lbs' ? 'lbs' : 'kg';
			}
		}
		// order_id
	}
	
	/*
	 * Return resources
	 */
	abstract function get_resource($id);
	
	
	
	/**
	 * AJAX method that adds the required styles and scripts, then includes the modal content.
	 */
	public function create_shipment_form() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_create_shipment' ) )
			return;
		
		if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		
		wp_enqueue_script( 'cpwebservice_modal_content' ,plugins_url( 'framework/lib/modal-inline.js' , dirname(__FILE__) ) , array( 'jquery', 'jquery-form'), '1.2' );
		wp_enqueue_script( 'cpwebservice_jquery_validate' ,plugins_url( 'framework/lib/jquery.validate.min.js' , dirname(__FILE__) ) , array( 'jquery' ), '1.11.1' );
		if ((defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE=='fr') || get_locale() == 'fr_FR' || get_locale() == 'fr_CA'){
		  wp_enqueue_script( 'cpwebservice_jquery_validate_fr' ,plugins_url( 'framework/lib/jquery.validate.fr.js' , dirname(__FILE__) ) , array( 'cpwebservice_jquery_validate' ) );
		}
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_create_shipment', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_create_shipment' ), 'confirm' => __('Are you sure you wish to apply this?', 'woocommerce-canadapost-webservice')) );
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_create_shipment_summary', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_create_shipment_summary' )) );
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_save_draft_shipment', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_save_draft_shipment' ), 'success'=> __('Saved Shipment as Draft', 'woocommerce-canadapost-webservice')) );
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_save_shipment', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_save_shipment' ), 'success'=> __('Submitted Shipment', 'woocommerce-canadapost-webservice'), 'validation'=> __('Please check required fields.', 'woocommerce-canadapost-webservice')) );
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_shipment_refund', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_shipment_refund' ), 'confirm' => __('Are you sure you wish to request a refund of this Shipment/Label?', 'woocommerce-canadapost-webservice'), 'success'=> __('Submitted Refund', 'woocommerce-canadapost-webservice')) );
		wp_localize_script( 'cpwebservice_modal_content', 'cpwebservice_save_shipment_template', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_save_shipment_template' ), 'prompt'=> __('Please enter a name for the template', 'woocommerce-canadapost-webservice'), 'defaultname'=> __('Template', 'woocommerce-canadapost-webservice'), 'success' => __('Saved Template', 'woocommerce-canadapost-webservice'), 'confirm' => __('Are you sure you wish to apply this Template?', 'woocommerce-canadapost-webservice'), 'remove' => __('Are you sure you wish to remove this?', 'woocommerce-canadapost-webservice'), 'template_package'=>$this->options->template_package, 'template_customs'=>$this->options->template_customs) );
		wp_enqueue_style( 'cpwebservice_modal_grid' , plugins_url( 'framework/lib/modal-grid.min.css' , dirname(__FILE__) ) );
		wp_enqueue_style( 'cpwebservice_modal_wpadmin' , plugins_url( 'framework/lib/wp-admin.min.css' , dirname(__FILE__) ));
		wp_enqueue_style( 'cpwebservice_modal_content' , plugins_url( 'framework/lib/modal-style.css' , dirname(__FILE__) ), null, '1.2' );
		wp_enqueue_style( 'cpwebservice_modal_admin' , plugins_url( 'framework/lib/admin.css' , dirname(__FILE__) ), null, '1.2' );
		
		// Params 
		$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
		$package_index = isset($_GET['package_index']) ? intval($_GET['package_index']) : 0;
		$display_refund_form = isset($_GET['refund_form']) ? ($_GET['refund_form']=='true') : false;
		
		// Init
		if ($this->shippingmethod == null){ $this->init_shippingmethod(); }
		if ($this->orderdetails == null){ $this->init_orderdetails(); }
		
		// Load order shipment data.
		// Get Information from meta data.
		$order = $this->get_order_details($order_id);
		if (!empty($order) && !empty($order['order'])){
		    // Get shipment data from order but from session if it is saved (as draft).
		    $this->current_shipment = $this->get_shipment_data($order, $package_index);
		}
		?>
		<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title><?php _e( 'Shipment' , 'woocommerce-canadapost-webservice' ); ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_print_styles(array( 'cpwebservice_modal_grid',  'dashicons', 'open-sans', 'cpwebservice_modal_wpadmin', 'cpwebservice_modal_content','cpwebservice_modal_admin')); ?>
</head>
<body>
<div class="navigation-bar">
	<nav>
		<ul>
		<li class="nav-center">
		  <img src="<?php echo plugins_url( $this->get_resource('method_logo_icon') , dirname(__FILE__) ); ?>" />
		  <br />
		  <br />
		  <?php echo $this->get_resource('method_title'); ?>
		</li>
		<li class="separator">&nbsp;</li>
			<?php if (!empty($this->current_shipment) && !empty($this->current_shipment['label']) && empty($this->current_shipment['refund'])) : ?>
			<li<?php echo (!$display_refund_form ? ' class="nav-active"' : '')?>><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $order_id . '&package_index=' . $package_index ), 'cpwebservice_create_shipment' ); ?>"><?php _e( 'Print Shipment Label' , 'woocommerce-canadapost-webservice' ); ?></a></li>
			<?php endif; ?>
			<?php if (!empty($this->current_shipment) && !empty($this->current_shipment['label']) && empty($this->options->contractid)) : ?>
			<li<?php echo (($display_refund_form || isset($this->current_shipment['refund'])) ? ' class="nav-active"' : '')?>><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $order_id . '&package_index=' . $package_index . '&refund_form=true' ), 'cpwebservice_create_shipment' ); ?>"><?php _e( 'Refund Shipment Label' , 'woocommerce-canadapost-webservice' ); ?></a></li>
			<?php endif; ?>
		</ul>
		<ul>
		<li class="nav-loadimg"><div class="canadapost-nav-loading canadapost-spinner" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div></li>
		<li>
		<div id="display_message">
		 
	    </div>
	    <div id="success_icon" data-img="<?php echo plugins_url( 'img/success.png' , dirname(__FILE__) ); ?>" style="display:none"></div>
	    </li>
	    </ul>
	</nav>
</div>
<section class="main" id="create_shipment">
	<?php
	if (!empty($this->current_shipment) && !empty($this->current_shipment['refund'])) {
	    // Status: Label is refunded.
	    $this->display_refund_complete($order_id, $package_index, $order, $this->current_shipment);
	} elseif (!empty($this->current_shipment) && !empty($this->current_shipment['label']) && $display_refund_form && empty($this->options->contractid)) {
	    // Status: Label has been created: but show Refund form.
	    $this->display_refund_form($order_id, $package_index, $order, $this->current_shipment);
	}elseif (!empty($this->current_shipment) && !empty($this->current_shipment['label'])) {
	    // Status: Label has been created.
	    $this->display_view_shipment($order_id, $package_index, $order, $this->current_shipment);
	} else {
	    // Status: Ready to create shipment label.
	    $this->display_create_shipment($order_id, $package_index, $order, $this->current_shipment);
	}
	?>
</section>
<?php wp_print_scripts(array('jquery', 'cpwebservice_jquery_validate', 'cpwebservice_jquery_validate_fr', 'cpwebservice_modal_content')); ?>
</body>
</html>
		<?php 
		exit; 
	}
	
	
	// Create Shipment Inner page.
	private function display_create_shipment($order_id, $package_index, $order, $shipment) {
	    
	    if (!empty($order) && !empty($order['order'])){
	    // Render form
	    ?>
	     <header id="shipment_data" data-orderid="<?php echo esc_attr($order_id)?>" data-packageindex="<?php echo esc_attr($package_index)?>">
		  <h1><?php _e( 'Create Shipment' , 'woocommerce-canadapost-webservice' ); ?> <?php echo $this->get_resource('method_title'); ?></h1>
	     </header>
	   <article>
	   <div class="container-fluid">
	  <div class="row">
          <div class="col-md-8">
          <p><?php _e( 'Please ensure you have set up your account details to create paid shipping labels.' , 'woocommerce-canadapost-webservice' ); ?></p>
		  <?php if ($this->options->shipment_mode=='live') { ?>
    		 <p><strong><span class="dashicons dashicons-flag"></span> <?php _e( 'Production/Live Mode' , 'woocommerce-canadapost-webservice' ); ?>:</strong> 
    		  <?php _e( 'This function will create a paid shipping label that will be billed to your account.' , 'woocommerce-canadapost-webservice' ); ?></p>
		  <?php } else { ?>
		      <p><strong><span class="dashicons dashicons-info"></span> <?php _e( 'Development Mode' , 'woocommerce-canadapost-webservice' ); ?>:</strong>
		      <?php _e( 'Only test labels will be created.' , 'woocommerce-canadapost-webservice' ); ?></p>
		  <?php } // endif ?>
		  
		<?php if(!empty($this->options->contractid)): ?>
    		<p><span class="dashicons dashicons-flag"></span> <?php _e( 'As a Contract customer, shipments will be transmitted immediately and will not need a Manifest document. If you use traditional contract shipping and manifest processes (e.g. you ship more than 50 parcels per day from a central warehouse), this process will not work. Manifest support will be added to this plugin soon.','woocommerce-canadapost-webservice' ); ?></p>
		<?php endif; ?>
		</div>
		 <div class="col-md-4" style="margin-top: 20px;">
		      <div class="form-group">
		        <div class="btn-group" id="templates">
		        <?php $this->load_shipment_template_list(); ?>
                  <button class="button button-secondary dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <?php _e('Shipment template','woocommerce-canadapost-webservice') ?> <span class="dashicons dashicons-arrow-down-alt2"></span>
                  </button>
                   <ul class="dropdown-menu">
                   <li><a href="#" class="auto-template-add" data-index="<?php echo !empty($this->templates) ? count($this->templates) : 0 ?>"><span class="dashicons dashicons-welcome-add-page"></span> <?php _e('Add current form as Template','woocommerce-canadapost-webservice')?></a></li>
                   <?php if(!empty($this->templates) && is_array($this->templates)): ?>
                   <?php
                   $i=0;
                   foreach($this->templates as $template): ?>
                    <li><a href="#" class="auto-template" data-template="<?php echo esc_attr(json_encode($template)) ?>"> 
                    <span class="dashicons dashicons-welcome-write-blog"></span> <?php echo esc_html($template['name']); ?>
                    </a> <a href="#" class="auto-template-remove" data-index="<?php echo $i ?>"><span class="dashicons dashicons-no-alt"></span></a></li>
                    <?php $i++; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                  </ul>
               </div>
		      </div>
		 </div>
		
      </div><!-- ./row -->
		<div class="row">
          <div class="col-md-12">
          <hr />
		<form name="form_shipment" id="form_shipment" class="form-horizontal">
		<div class="form-group">
    		<label for="sender_address" class="col-sm-2 control-label"><?php _e('Sender Address', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
                 <select name="sender_address" id="sender_address" class="form-control">
				    <?php foreach ( $this->shipment_address as $id=>$address ) : ?>
    							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, esc_attr( $shipment['sender_address_index'] ) ); ?>><?php echo esc_attr( $address['contact'] . ' ' . $address['postalcode'] ); ?></option>
    				<?php endforeach; ?>
				    </select>
             </div>
		</div>
		<div class="form-group">
		  <div class="col-sm-offset-2 col-sm-10">
		      <?php $index = intval($shipment['sender_address_index']); ?>
			  <?php for($i=0; $i<count($this->shipment_address); $i++):?>
				 <div id="sender_address_display_<?php echo $i; ?>" style="<?php if($i != $index){ ?>display:none<?php } ?>" class="sender_address_display">
				    <?php echo esc_html($this->shipment_address[$i]['contact'])?><br />
				    <?php echo esc_html($this->shipment_address[$i]['phone'])?><br />
					<?php echo esc_html($this->shipment_address[$i]['address'])?><?php if (!empty($this->shipment_address[$i]['address2'])) { ?><br /><?php } ?>
					<?php echo esc_html($this->shipment_address[$i]['address2'])?><br />
					<?php echo esc_html($this->shipment_address[$i]['city'])?>, <?php echo esc_html($this->shipment_address[$i]['prov'])?> <?php echo esc_html($this->shipment_address[$i]['postalcode'])?><br />
					<?php echo esc_html($this->shipment_address[$i]['country'])?>
				 </div>
			   <?php endfor; ?>
			   <?php if (empty($this->shipment_address) || empty($this->shipment_address[$index])|| empty($this->shipment_address[$index]['address'])|| empty($this->shipment_address[$index]['postalcode'])): ?>
			   <span class="help-text"><?php _e('Please enter your Sender Address information in the plugin settings.', 'woocommerce-canadapost-webservice')?></span>
			   <?php endif; ?>
			</div>	  
		</div><!-- ./form-group -->
		<?php if(!empty($this->options->contractid)): ?>
		<div class="form-group">
    		<label class="col-sm-2 control-label" style="padding-top:0"><?php _e('Shipping Point', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-10">
				<label><input type="radio" name="pickup_indicator" class="pickup-indicator" value="dropoff" <?php checked($shipment['pickup_indicator'], 'dropoff'); ?> /> <?php printf(__('Drop off at %s Location', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title')) ?></label>
				&nbsp;
				<label><input type="radio" name="pickup_indicator" class="pickup-indicator" value="pickup" <?php checked($shipment['pickup_indicator'], 'pickup'); ?> /> <?php printf(__('%s to Pickup', 'woocommerce-canadapost-webservice'), $this->get_resource('method_title')) ?></label>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group shipping-point-display"<?php if (!empty($shipment['pickup_indicator']) && $shipment['pickup_indicator']=='pickup') { echo ' style="display:none"'; } ?>>
    		<div class="col-sm-offset-2 col-sm-4">
    		<label class="dropoff-label" for="shipping_point_id"><?php _e('Dropoff Location (Optional)', 'woocommerce-canadapost-webservice') ?>:</label>
    		 <input type="text" name="shipping_point_id" id="shipping_point_id" class="form-control" value="<?php echo esc_attr($shipment['shipping_point_id']); ?> " />
    		 <p class="description">(<?php _e('4-digit Site #','woocommerce-canadapost-webservice')?>)</p>
    		 </div>
    		 <div class="col-sm-4 top30">
    		 <a href="<?php echo esc_attr($this->get_resource('dropoff_search_url'))?>" target="_blank" id="dropoff_location" class="button button-secondary"><?php _e('Lookup Dropoff Location',  'woocommerce-canadapost-webservice')?> <span class="dashicons dashicons-search"></span></a>
    		</div>
		</div><!-- ./form-group -->
		<?php endif; ?>
		<div class="row">
    		<div class="col-sm-12">
    		  <hr />
    		</div>
		</div> <!-- ./row -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" style="padding-top:0"><?php _e('Recipient Name', 'woocommerce-canadapost-webservice') ?>:<br /><?php _e('Ship-to Address', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-10">
				<?php echo $order['shipping_address'] ?>
				 <p class="description"><?php _e('If you need to change this Destination address, please update the Shipping address on the Order', 'woocommerce-canadapost-webservice') ?></p>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="contact_phone"><?php _e('Recipient Phone', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
    		  <input type="text" name="contact_phone" id="contact_phone" class="form-control" value="<?php echo esc_attr($shipment['contact_phone']); ?> " />
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="destination_email"><?php _e('Recipient Email', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
    		  <input type="text" name="destination_email" id="destination_email" class="form-control" value="<?php echo esc_attr($shipment['destination_email']); ?> " />
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php echo $this->get_resource('shipment_emailupdates') ?>:</label>
    		<div class="col-sm-10">
    		  <label><input type="checkbox" name="email_on_shipment" id="email_on_shipment" value="1" <?php checked( $shipment['email_on_shipment'] ); ?> />  <?php _e('Email on Shipment', 'woocommerce-canadapost-webservice') ?> </label><br />
 			  <label><input type="checkbox" name="email_on_exception" id="email_on_exception" value="1" <?php checked( $shipment['email_on_exception'] ); ?> />  <?php _e('Email if Exception', 'woocommerce-canadapost-webservice') ?> </label><br />
			  <label><input type="checkbox" name="email_on_delivery" id="email_on_delivery" value="1" <?php checked( $shipment['email_on_delivery'] ); ?> />  <?php _e('Email on Delivery', 'woocommerce-canadapost-webservice') ?> </label>
			  <p class="description"><?php _e('Email will be delivered to email address above.', 'woocommerce-canadapost-webservice')?></p>
    		</div>
		</div><!-- ./form-group -->	
		<div class="row">	
    		<div class="col-sm-12">
    		  <hr />
    		</div>
    		<div class="col-sm-12">
    		  <h3><?php _e( 'Shipment' , 'woocommerce-canadapost-webservice' ); ?></h3>  
    		</div>
		</div> <!-- ./row -->
		<?php if(!empty($this->options->contractid)): ?>
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="transmit_method"><?php _e('Transmit Method', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
    		  <select name="transmit_method" class="form-control">
    		  <option value="transmit"><?php _e('Transmit immediately', 'woocommerce-canadapost-webservice')?></option>
    		  <option value="manifest" disabled="disabled"><?php _e('Use Manifest - (Feature coming soon.)', 'woocommerce-canadapost-webservice')?></option>
    		  </select>
    		</div>
		</div><!-- ./form-group -->
		<?php endif; ?>
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="selected_service"><?php echo $this->get_resource('method_title'); ?> <?php _e('Service', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-8">
    		  <select name="selected_service" id="selected_service" class="form-control">
				    <?php foreach ( $this->shippingmethod->available_services as $service_code=>$service ) : ?>
				    <?php $method_country = $this->shippingmethod->get_destination_country_code_from_service($service_code); ?>
				    <?php if (($method_country == 'CA' && $order['order']->shipping_country == 'CA') || 
				              ($method_country == 'US' && $order['order']->shipping_country == 'US') || 
				              ($method_country == 'ZZ' && $order['order']->shipping_country != 'CA' && $order['order']->shipping_country != 'US')): ?>
    							<option value="<?php echo esc_attr( $service_code ); ?>" <?php selected( $service_code , esc_attr( $shipment['method_id'] ) ); ?>><?php echo esc_attr( $service ); ?></option>
    				<?php endif; ?>
    				<?php endforeach; ?>
    				</select>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php _e('Package', 'woocommerce-canadapost-webservice') ?> <?php _e( 'Type' , 'woocommerce-canadapost-webservice' ); ?>:</label>
    		<div class="col-sm-10">
    		  <label><input type="radio" name="shipment_type" value="Package" <?php checked('Package', $shipment['shipment_type']) ?> />Package</label> &nbsp; 
			  <label><input type="radio" name="shipment_type" value="Document" <?php checked('Document', $shipment['shipment_type']) ?> />Document</label> &nbsp;
			  <label><input type="radio" name="shipment_type" value="Mailing Tube" <?php checked('Mailing Tube', $shipment['shipment_type']) ?> />Mailing Tube</label>
			  <label><input type="radio" name="shipment_type" value="Unpackaged" <?php checked('Unpackaged', $shipment['shipment_type']) ?> />Unpackaged</label>
    		</div>
		</div><!-- ./form-group -->	
		<div class="row">
		  <label class="col-sm-2 control-label"><?php _e('Package', 'woocommerce-canadapost-webservice') ?> <?php _e('Weight', 'woocommerce-canadapost-webservice') ?>:</label>
		  <div class="col-sm-3">
		   <div class="form-group">
		   <div class="input-group input-group-left-align">
			<input type="text" name="weight" id="weight" class="package_details form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.001" value="<?php echo isset($shipment['package']['weight']) ? esc_attr( cpwebservice_resources::display_weight($shipment['package']['weight'], $this->options->display_weights) ) : ''; ?>" />
			<span class="input-group-addon heavy"><?php echo esc_html($this->options->display_weights) ?></span>
			</div>
			</div>
		  </div>
		  <div class="col-sm-3">
		      <?php if (isset( $this->shippingmethod->boxes) && is_array($this->shippingmethod->boxes)): ?>
		      <div class="btn-group">
                  <button class="button button-secondary dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <?php _e('Use box dimensions','woocommerce-canadapost-webservice') ?> <span class="dashicons dashicons-arrow-down-alt2"></span>
                  </button>
                   <ul class="dropdown-menu">
                   <?php foreach($this->shippingmethod->boxes as $box): 
                    $boxdata = array(
                        'length'=>cpwebservice_resources::display_unit($box['length'], $this->options->display_units),
                        'width'=>cpwebservice_resources::display_unit($box['width'], $this->options->display_units),
                        'height'=>cpwebservice_resources::display_unit($box['height'], $this->options->display_units));
                    ?>
                    <li><a href="#" class="auto-box" data-box="<?php echo esc_attr(json_encode($boxdata)) ?>"> 
                    <span class="dashicons dashicons-image-rotate-right"></span> <?php _e('Box','woocommerce-canadapost-webservice') ?>: <?php echo esc_html($box['name']); ?> 
                     (<?php echo esc_html($boxdata['length']) ?>x<?php echo esc_html($boxdata['width']) ?>x<?php echo esc_html($boxdata['height']) ?>
                      <?php echo esc_html($this->options->display_units) ?>)</a></li>
                    <?php endforeach; ?>
                  </ul>
               </div>
               <?php endif; ?>
               
		  </div>
		</div><!-- ./row -->	
		<div class="row">
		  <label class="col-sm-2 control-label">
		     <br />
		     <strong><?php _e('Package', 'woocommerce-canadapost-webservice') ?> <?php _e( 'Dimensions' , 'woocommerce-canadapost-webservice' ); ?>:</strong>
		  </label>
		  <div class="col-sm-10">
        	    <div class="form-group">
                		
            		   <div class="col-sm-3">
            					<label class="control-label" for="length"><?php _e('Length', 'woocommerce-canadapost-webservice') ?>:</label>
            					<div class="input-group"> 
                					<input type="text" name="length" id="length" class="package_details form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.1" value="<?php echo isset($shipment['package']['length']) ? esc_attr(cpwebservice_resources::display_unit($shipment['package']['length'], $this->options->display_units)) : ''; ?>" />
                					<span class="input-group-addon heavy"><?php echo esc_html($this->options->display_units) ?></span>
            					</div>
            			</div>
            			<div class="col-sm-3">
            					<label class="control-label" for="width"><?php _e('Width', 'woocommerce-canadapost-webservice') ?>:</label>
            					<div class="input-group"> 
                					<input type="text" name="width" id="width" class="package_details form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.1" value="<?php echo isset($shipment['package']['width']) ? esc_attr(cpwebservice_resources::display_unit($shipment['package']['width'], $this->options->display_units)) : ''; ?>" />
                					<span class="input-group-addon heavy"><?php echo esc_html($this->options->display_units) ?></span>
            					</div>
            		     </div>
            		     <div class="col-sm-3">
            					<label class="control-label" for="height"><?php _e('Height', 'woocommerce-canadapost-webservice') ?>:</label>
            					<div class="input-group"> 
            					   <input type="text" name="height" id="height" class="package_details form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.1" value="<?php echo isset($shipment['package']['height']) ? esc_attr(cpwebservice_resources::display_unit($shipment['package']['height'], $this->options->display_units)) : ''; ?>" />
            					   <span class="input-group-addon heavy"><?php echo esc_html($this->options->display_units) ?></span>
            					</div>
            			</div>
        		</div><!-- ./form-group -->
		  </div>
		</div>
			
		<div class="row">
    		<div class="col-sm-12">
    		  <hr />
    		</div>
    		<div class="col-sm-12">
    		  <h3><?php _e( 'Tracking Information' , 'woocommerce-canadapost-webservice' ); ?></h3>
    		</div>
		</div> <!-- ./row -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php _e('Reference number', 'woocommerce-canadapost-webservice') ?>:<br />
    		<p class="description">(<?php _e('up to 35 characters', 'woocommerce-canadapost-webservice') ?>)</p></label>
    		<div class="col-sm-3">
    		  <input type="text" name="reference" id="reference" class="form-control" value="<?php echo esc_attr($shipment['reference'])?>" />
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php _e('Cost centre reference', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-3">
    		  <input type="text" name="reference_cost" id="reference_cost" class="form-control" value="<?php echo esc_attr($shipment['reference_cost'])?>" />
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php _e('Additional reference number', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-3">
    		  <input type="text" name="reference_additional" id="reference_additional" class="form-control" value="<?php echo esc_attr($shipment['reference_additional'])?>" />
    		</div>
		</div><!-- ./form-group -->
		<div class="row">
		<div class="col-sm-12">
		  <hr />
		</div>
		</div>
		<div class="row">
		  <label class="col-sm-2"><h3><?php _e('Options', 'woocommerce-canadapost-webservice') ?></h3></label>
		  <div class="col-sm-10">
		      <div>
		      <label><input type="checkbox" name="opt_signature" id="opt_signature" value="1" <?php checked( $shipment['opt_signature'] ); ?> />  <?php _e('Signature', 'woocommerce-canadapost-webservice') ?> </label><br />
			  <label><input type="checkbox" name="opt_delivery_confirmation" id="opt_delivery_confirmation" value="1" <?php checked( $shipment['opt_delivery_confirmation'] ); ?> />  <?php _e('Delivery Confirmation', 'woocommerce-canadapost-webservice') ?> </label><br />
		      <label><input type="checkbox" name="opt_packinginstructions" id="opt_packinginstructions" value="1" <?php checked( $shipment['opt_packinginstructions'] ); ?> />  <?php _e('Show Packing instructions on Mailing Label', 'woocommerce-canadapost-webservice') ?> </label><br />
		      <label><input type="checkbox" name="opt_postrate" id="opt_postrate" value="1" <?php checked( $shipment['opt_postrate'] ); ?> />  <?php _e('Show Postage Rate on Mailing Label', 'woocommerce-canadapost-webservice') ?> </label><br />
			  <label><input type="checkbox" name="opt_insuredvalue" id="opt_insuredvalue" value="1" <?php checked( $shipment['opt_insuredvalue'] ); ?> />  <?php _e('Display Insured value on Mailing Label', 'woocommerce-canadapost-webservice') ?> </label>
			  </div>
		  </div>
		</div><!-- ./row -->
		<div class="form-group">
		  <label class="col-sm-2 control-label"><?php _e('Requirement Option', 'woocommerce-canadapost-webservice') ?>:</label>
		  <div class="col-sm-3">
			     <select name="opt_required" id="opt_required" class="form-control">
			     <option value=""></option>
			     <option value="PA18" <?php selected( 'PA18',  $shipment['opt_required'] ); ?>>Proof of Age Required - 18</option>
			     <option value="PA19" <?php selected( 'PA19',  $shipment['opt_required'] ); ?>>Proof of Age Required - 19</option>
			     </select>
			</div>
	    </div><!-- ./form-group -->
	    <div class="form-group">
	        <label class="col-sm-2 control-label"><?php _e('Delivery Option', 'woocommerce-canadapost-webservice') ?>:</label>
	        <div class="col-sm-3">
			     <select name="opt_delivery_door" id="opt_delivery_door" class="form-control">
			     <option value=""></option>
			     <option value="HFP" <?php selected( 'HFP',  $shipment['opt_delivery_door'] ); ?>>Card for pickup</option>
			     <option value="DNS" <?php selected( 'DNS',  $shipment['opt_delivery_door'] ); ?>>Do not safe drop</option>
			     <option value="LAD" <?php selected( 'LAD',  $shipment['opt_delivery_door'] ); ?>>Leave at door - do not card</option>
			     </select>
			  </div>
	    </div><!-- ./form-group -->
        <div class="form-group top10">
	         <label class="col-sm-2 control-label"><?php _e('Insurance Coverage', 'woocommerce-canadapost-webservice') ?>:</label>
    	     <div class="col-sm-3 input-group input-group-left-align">
    		     <span class="input-group-addon heavy">$</span>
    		     <input type="text" name="insurance" id="insurance" class="form-control" value="<?php echo esc_attr($shipment['insurance'])?>" />
    	     </div>
	    </div><!-- ./form-group -->
	    <?php if(!empty($this->options->contractid)): ?>
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="opt_outputformat"><?php _e('Printing Format', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-3">
    		  <select name="opt_outputformat" class="form-control" id="opt_outputformat">
    		  <option value="8.5x10" <?php selected($shipment['opt_outputformat'],'8.5x10')?>><?php _e('8.5x10 PDF', 'woocommerce-canadapost-webservice')?></option>
    		  <option value="4x6" <?php selected($shipment['opt_outputformat'],'4x6')?>><?php _e('4x6 PDF', 'woocommerce-canadapost-webservice')?></option>
    		  </select>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="payment_method"><?php _e('Payment Method', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-3">
    		  <select name="payment_method" id="payment_method" class="form-control">
    		  <option value="CreditCard" <?php selected($shipment['payment_method'],'CreditCard')?>><?php _e('Credit Card', 'woocommerce-canadapost-webservice')?></option>
    		  <option value="Account" <?php selected($shipment['payment_method'],'Account')?>><?php _e('Account', 'woocommerce-canadapost-webservice')?></option>
    		  </select>
    		</div>
		</div><!-- ./form-group -->
		<?php endif; ?>
	    <div class="form-group">
    	     <label class="col-sm-2 control-label"><?php _e('Promotional discount code', 'woocommerce-canadapost-webservice') ?>:</label>
    	     <div class="col-sm-3">
    	       <input type="text" name="opt_promocode" id="opt_promocode" class="form-control" value="<?php echo esc_attr($shipment['opt_promocode'])?>" />
    	     </div>
	    </div><!-- ./form-group -->
		 <?php if ($order['order']->shipping_country != 'CA'): // If USA or International ?>
		<div class="row">
    		<div class="col-sm-12">
    		  <hr />
    		</div>
    		<div class="col-sm-12">
    		  <h3><?php _e('Border Customs', 'woocommerce-canadapost-webservice') ?></h3>
    		</div>
		</div> <!-- ./row -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_currency"><?php _e('Currency', 'woocommerce-canadapost-webservice') ?>*:</label>
    		<div class="col-sm-4">
    		    <select name="customs_currency" id="customs_currency" class="form-control" data-rule-required="true">
					   <option value="CAD" <?php selected( 'CAD' , esc_attr( $shipment['customs_currency'] ) ); ?>><?php _e('CAD - Canadian Dollars', 'woocommerce-canadapost-webservice') ?></option>
					   <option value="USD" <?php selected( 'USD' , esc_attr( $shipment['customs_currency'] ) ); ?>><?php _e('USD - US Dollars', 'woocommerce-canadapost-webservice') ?></option>
			   </select>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_export"><?php _e('Reason for Export', 'woocommerce-canadapost-webservice') ?>*:</label>
    		<div class="col-sm-4">
    		   <select name="customs_export" id="customs_export" class="form-control" data-rule-required="true">
					   <option value="" <?php selected( '' , esc_attr( $shipment['customs_export'] ) ); ?>></option>
					   <!-- <option value="GIF" <?php selected( 'GIF' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Gift','woocommerce-canadapost-webservice') ?></option>  --><!-- Gift is no longer a valid option. -->
					   <option value="DOC" <?php selected( 'DOC' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Document','woocommerce-canadapost-webservice') ?></option>
					   <option value="SAM" <?php selected( 'SAM' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Commercial sample','woocommerce-canadapost-webservice') ?></option>
					   <option value="REP" <?php selected( 'REP' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Repair or warranty','woocommerce-canadapost-webservice') ?></option>
					   <option value="SOG" <?php selected( 'SOG' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Sale of goods','woocommerce-canadapost-webservice') ?></option>
					   <option value="OTH" <?php selected( 'OTH' , esc_attr( $shipment['customs_export'] ) ); ?>><?php _e('Other (Please specify)','woocommerce-canadapost-webservice') ?></option> 
					  </select>
					<div id="customs_export_other_display">
    					<label class="control-label"><?php _e('Other', 'woocommerce-canadapost-webservice') ?>: </label>
    					<input type="text" name="customs_export_other" id="customs_export_other" class="form-control" value="<?php echo esc_attr($shipment['customs_export_other'])?>" />
					</div>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_nondelivery"><?php _e('Non-Delivery', 'woocommerce-canadapost-webservice') ?>*:</label>
    		<div class="col-sm-4">
    		   <select name="customs_nondelivery" id="customs_nondelivery" class="form-control" data-rule-required="true">
					   <option value="" <?php selected( '' , esc_attr( $shipment['customs_nondelivery'] ) ); ?>></option>
					   <option value="RASE" <?php selected( 'RASE' , esc_attr( $shipment['customs_nondelivery'] ) ); ?>><?php _e('Return at Sender’s Expense', 'woocommerce-canadapost-webservice') ?></option>
					   <option value="RTS" <?php selected( 'RTS' , esc_attr( $shipment['customs_nondelivery'] ) ); ?>><?php _e('Return to Sender', 'woocommerce-canadapost-webservice') ?></option>
					   <option value="ABAN" <?php selected( 'ABAN' , esc_attr( $shipment['customs_nondelivery'] ) ); ?>><?php _e('Abandon', 'woocommerce-canadapost-webservice') ?></option>
			  </select>
    		</div>  
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_invoice"><?php _e('Invoice No.', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
    		    <input type="text" name="customs_invoice" id="customs_invoice" class="form-control" value="<?php echo esc_attr($shipment['customs_invoice'])?>" />
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_certificateid"><?php _e('Number of the government or agency certificate or permit', 'woocommerce-canadapost-webservice') ?>:</label>
    		<div class="col-sm-4">
    		     <input type="text" name="customs_certificateid" id="customs_certificateid" class="form-control" value="<?php echo esc_attr($shipment['customs_certificateid'])?>" />
    		     <p class="description">(<?php _e('If required by customs at the destination', 'woocommerce-canadapost-webservice') ?>)</p>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label" for="customs_licenseid"><?php _e('The number of the government or agency import or export licence', 'woocommerce-canadapost-webservice') ?>: </label>
    		<div class="col-sm-4">
    		     <input type="text" name="customs_licenseid" id="customs_licenseid" class="form-control" value="<?php echo esc_attr($shipment['customs_licenseid'])?>" />
    		     <p class="description">(<?php _e('If required by customs at the destination', 'woocommerce-canadapost-webservice') ?>)</p>
    		</div>
		</div><!-- ./form-group -->
		<div class="form-group">
    		<label class="col-sm-2 control-label"><?php _e('Contents', 'woocommerce-canadapost-webservice') ?></label>
    		<div class="col-sm-9">
    		     <div class="row">
            	   <div id="customs_items"><?php $origin_country = $this->get_resource('shipment_country'); $origin_states =  WC()->countries->get_states( $origin_country ); $origin_countries = WC()->countries->countries;   ?>
            	   <?php if (empty($shipment['custom_products']) || !is_array($shipment['custom_products'])) { $shipment['custom_products'] = $this->default_customs; } ?>
					<?php if(is_array($shipment['custom_products'])): ?>
					   <?php foreach($shipment['custom_products'] as $n=>$p): ?>
					   <div class="row customs_item">
					    <div class="pull-right">
					       <a class="button buttonicon btn_custom_remove <?php if ($n==0){ echo 'hidden'; } ?>" href="#"><span class="dashicons dashicons-no"></span></a>
					    </div>
					   <div class="col-sm-5 col-md-2">
					      <label class="control-label" title="<?php _e('Optional', 'woocommerce-canadapost-webservice') ?>"><?php _e('Sku', 'woocommerce-canadapost-webservice') ?>:</label>
					      <input type="text" name="custom_product_sku[]" value="<?php echo esc_attr($p['sku'])?>" class="form-control" />       
					    </div>
					    <div class="col-sm-3 col-md-2">
					       <label class="control-label"><?php _e('Quantity', 'woocommerce-canadapost-webservice') ?>:</label>
					       <input type="text" name="custom_product_quantity[]" value="<?php echo esc_attr($p['quantity'])?>" class="form-control" data-rule-required="true" data-rule-number="true" data-rule-min="1" /> 
					    </div>
					    <div class="col-sm-6 col-md-4">
					       <label class="control-label"><?php _e('Description', 'woocommerce-canadapost-webservice') ?>:</label>
					        <input type="text" name="custom_product_description[]" value="<?php echo esc_attr($p['description'])?>" class="form-control" data-rule-required="true" />
					    </div>
					    <div class="col-sm-5 col-md-3">
					       <label class="control-label"><?php _e('Unit Value', 'woocommerce-canadapost-webservice') ?>: </label>
					       <div class="input-group"> 
					                <span class="input-group-addon heavy">$</span>
                					<input type="text" name="custom_product_unitcost[]" value="<?php echo esc_attr($p['unitcost'])?>" class="form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.01" />
            			   </div>
					    </div>
					    <div class="col-sm-6 col-md-4">
					        <label class="control-label"><?php _e('Unit Weight', 'woocommerce-canadapost-webservice') ?>:</label>
					        <div class="input-group"> 
                					<input type="text" name="custom_product_unitweight[]" value="<?php echo esc_attr(!empty($p['unitweight']) ? cpwebservice_resources::display_weight($p['unitweight'], $this->options->display_weights):'')?>" class="form-control" data-rule-required="true" data-rule-number="true" data-rule-min="0.001" />
                					<span class="input-group-addon heavy"><?php echo esc_html($this->options->display_weights) ?></span>
            			   </div>
					    </div>
					    <div class="col-sm-6 col-md-4">
					        <label class="control-label"><?php _e('HS Code', 'woocommerce-canadapost-webservice') ?>: (<?php _e('Optional', 'woocommerce-canadapost-webservice') ?>)</label>
					        <input type="text" name="custom_product_hs_code[]" value="<?php echo esc_attr($p['hs_code'])?>" data-mask="\d{4}(\.\d{2}(\.\d{2}(\.\d{2})?)?)?" placeholder="9999.99.99.99" class="form-control" /> 
					    </div>
					    <div class="col-sm-6 col-md-4">
					        <label class="control-label"><?php _e('Country of Origin', 'woocommerce-canadapost-webservice') ?>: (<?php _e('Optional', 'woocommerce-canadapost-webservice') ?>)</label>
					          <select name="custom_product_origin_country[]" class="form-control origin-country" data-origincountry="<?php echo esc_attr($origin_country); ?>">
    						    <option value="" <?php selected( '', esc_attr( $p['origin_country'] ) ); ?>></option>
    						     <?php if ($origin_countries): ?>
        						<?php foreach ( $origin_countries as $option_key => $option_value ) : ?>
        						 <option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $p['origin_country'] ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
        						<?php endforeach; ?>
        						<?php endif; ?>
            					</select>
					    </div>
					    <div class="origin-prov col-md-offset-8 col-sm-6 col-md-4"<?php if($p['origin_country']!=$origin_country){ ?> style="display:none"<?php }?>>
					         <label class="control-label"><?php _e('Province of Origin', 'woocommerce-canadapost-webservice') ?>:  </label>
					         <select name="custom_product_origin_prov[]" class="form-control origin-prov-control">
    						    <option value="" <?php selected( '', esc_attr( $p['origin_prov'] ) ); ?>></option>
        						<?php foreach ( (array) $origin_states as $option_key => $option_value ) : ?>
        						 <option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $p['origin_prov'] ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
        						<?php endforeach; ?>
            				 </select>
					    </div>
					    <div class="col-sm-12">
					       <hr />
					    </div>
					   </div>
					   <?php endforeach; ?>
					   <?php endif;?>
					</div> 
    		     </div>
    		    <div class="row">
    		          <div class="col-sm-12">
    		              <a href="javascript:;" id="btn_customs_items" class="button button-secondary"><?php _e('Add More', 'woocommerce-canadapost-webservice') ?> <span class="dashicons dashicons-plus-alt"></span></a>  
    		              &nbsp;
    		              <a href="<?php echo esc_attr($this->get_resource('hscode_search_url'))?>" target="_blank" id="code_search" class="button button-secondary"><?php _e('HS Code Search',  'woocommerce-canadapost-webservice')?> <span class="dashicons dashicons-search"></span></a>
    		          </div>
    		     </div>
    		</div>
		</div><!-- ./form-group -->
		<?php endif; ?>
		  <div class="row">
    		  <div class="col-sm-12">
    		  <hr />
    		</div>
    		<div class="col-sm-12">
    		  <h3><?php _e('Summary', 'woocommerce-canadapost-webservice') ?></h3>
    		</div>
		 </div> <!-- ./row -->
		  <div class="row">
    		  <div class="col-sm-2">
    		      <a href="javascript:;" id="shipment_summary_refresh" class="button button-secondary"><span class="dashicons dashicons-update"></span><?php _e( 'Refresh' , 'woocommerce-canadapost-webservice' ); ?></a>
    		  </div>
    		  <div id="shipment_summary" class="col-sm-10">
				    <?php $this->create_shipment_summary($order, $shipment, false); ?>
			  </div>
    		</div><!-- ./row -->
    		<div class="row form-control-actionbar">
    		<div class="col-md-offset-2 col-md-10">
    			<button id="btn-cancel" class="button button-secondary"><?php _e( 'Cancel' , 'woocommerce-canadapost-webservice' ); ?></button>
    			<button id="btn-draft" class="button button-secondary"><?php _e( 'Save as Draft' , 'woocommerce-canadapost-webservice' ); ?></button>
    			<?php if (empty($this->shipment_address) || empty($this->shipment_address[$index])): ?>
			     <span class="help-text">*<?php _e('Please enter your Sender Address information in the plugin settings.', 'woocommerce-canadapost-webservice')?></span>
			    <?php else: ?>
    			<button id="btn-ok" class="button button-primary"><?php _e( 'Create Shipment' , 'woocommerce-canadapost-webservice' ); ?></button>
    			<?php endif; ?>
    			<span class="loading-action" style="display:none"><img src="<?php echo plugins_url( 'img/loading-action.gif' , dirname(__FILE__) ); ?>" alt="" border="0" width="36" height="28" /></span>
    		</div>
    		</div><!-- ./row -->
    		<div class="row">
    		  <div class="col-sm-12">
    		  &nbsp;
    		  <br />
    		  </div>
    		</div><!-- ./row -->
		</form>
		</div><!-- ./col-md-12 -->
        </div><!-- ./row -->
	</div><!-- ./container -->
	</article>
    <?php     
	    } else {?>
	    <article>
	        <strong><?php _e('Order not found.  Please check your link or reload the page.', 'woocommerce-canadapost-webservice' ); ?></strong>
	    </article>
	 <footer>
		<div class="inner text-right">
			<button id="btn-cancel" class="button"><?php _e( 'Cancel' , 'woocommerce-canadapost-webservice' ); ?></button>
		</div>
	</footer>
	    <?php } // endif
	    
	}
	
	/*
	 * Displays shipment summary.  Parameters will be null if this is called through ajax
	 * Parameter variables are for performance: so the order and shipment data is not looked up twice.
	 */
	public function create_shipment_summary($order=null, $shipment=null, $return_ajax=true) {
	    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_create_shipment' ) )
	        return;
	    
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    
	    // Params
	    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
	    $package_index = isset($_GET['package_index']) ? intval($_GET['package_index']) : 0;
	    
	    // Init
	    if ($this->shippingmethod == null){ $this->init_shippingmethod(); }
	    if ($this->orderdetails == null){ $this->init_orderdetails(); }
	    
	    // Get Information from meta data.
	    if ($order==null) { $order = $this->get_order_details($order_id); }
	    if (!empty($order) && !empty($order['order'])){
	      // Get shipment data from order but from session if it is saved (as draft).
	      if ($shipment==null) { $shipment = $this->get_shipment_data($order, $package_index); }
	      $this->current_shipment = $shipment;
   	      // Calculate rates for $shipment.  It's really nice to know how much you'll be charged before submitting.
	      $index = intval($shipment['sender_address_index']);
	      if (!empty($this->shipment_address[$index]['postalcode'])) { 
	          $this->shippingmethod->options->source_postalcode = $this->shipment_address[$index]['postalcode'];
	          $this->shippingmethod->options->geolocate_origin = false;
	      }
	      // If options (signature / insurance)
	      if (!empty($shipment['opt_signature']) || !empty($shipment['opt_signature'])){
    	      $add_options = new stdClass();
    	      if (!empty($shipment['opt_signature'])) { $add_options->signature = $shipment['opt_signature']; }
    	      if (!empty($shipment['insurance'])) { $add_options->insurance = $shipment['insurance']; }
	      } else {
	          $add_options = null;
	      }
   	      $rates = (!empty($shipment) && !empty($shipment['package']) && !empty($shipment['method_id'])) ? $this->shippingmethod->get_rates($order['order']->shipping_country, $order['order']->shipping_state, $order['order']->shipping_city, $order['order']->shipping_postcode, $shipment['package']['weight'], $shipment['package']['length'], $shipment['package']['width'], $shipment['package']['height'], $services = array($shipment['method_id']), !empty($add_options) ? $add_options : null, $price_details = true) : null;
   	      
   	      ?>
   	      <div class="summary-title">
   	      <?php echo $this->get_resource('method_title'); ?> <?php _e( 'Service' , 'woocommerce-canadapost-webservice' ); ?>: 
   	      <strong><?php if(isset($this->shippingmethod->available_services[$shipment['method_id']])){ echo esc_html($this->shippingmethod->available_services[$shipment['method_id']]); } ?></strong>
   	      </div>
   	      <?php if (!empty($rates) && !empty($rates[0])) : ?>
   	      <?php $rate = $rates[0]; ?>
   	      
			<?php if ($rate->guaranteed): ?>
    			<div class="summary-title">
    			<?php _e( 'Delivery Guarantee' , 'woocommerce-canadapost-webservice' ) ?>: 
    			<?php echo $rate->expected_delivery ?>
    			</div> 
			<?php endif; ?>
					<table class="table table-hover">
    					<tr>
    					   <td><?php _e( 'Base' , 'woocommerce-canadapost-webservice' ) ?>:</td><td>$<?php echo $rate->price_details['base'] ?></td>
    					</tr>
    					<?php if (!empty($rate->price_details['taxes_gst']) && floatval($rate->price_details['taxes_gst']) > 0): ?>
    					<tr>
    					   <td><?php _e( 'Tax (GST)' , 'woocommerce-canadapost-webservice' ) ?>:</td><td>$<?php echo $rate->price_details['taxes_gst'] ?></td>
    					</tr>
    					<?php endif; ?>
    					<?php if (!empty($rate->price_details['taxes_hst']) && floatval($rate->price_details['taxes_hst']) > 0): ?>
    					<tr>
    					   <td><?php _e( 'Tax (HST)' , 'woocommerce-canadapost-webservice' ) ?>:</td><td>$<?php echo $rate->price_details['taxes_hst'] ?></td>
    					</tr>
    					<?php endif; ?>
    					<?php if (!empty($rate->price_details['taxes_pst']) && floatval($rate->price_details['taxes_pst']) > 0): ?>
    					<tr>
    					   <td><?php _e( 'Tax (PST)' , 'woocommerce-canadapost-webservice' ) ?>:</td><td>$<?php echo $rate->price_details['taxes_pst'] ?></td>
    					</tr>
    					<?php endif; ?>
    					<?php if (!empty($rate->price_details['options']) && is_array($rate->price_details['options'])): ?>
        					<?php foreach( $rate->price_details['options'] as $option): ?>
        					<?php if (floatval($option['price']) > 0):?>
        					<tr>
        					   <td><?php _e( 'Fee' , 'woocommerce-canadapost-webservice' ) ?>: <?php echo $option['name'] ?></td><td>$<?php echo $option['price'] ?></td>
        					</tr>
        					<?php endif; ?>
        					<?php endforeach; ?>
    					<?php endif; ?>
    					<?php if (!empty($rate->price_details['adjustments']) && is_array($rate->price_details['adjustments'])): ?>
        					<?php foreach( $rate->price_details['adjustments'] as $option): ?>
        					<tr>
        					   <td><?php _e( 'Fee' , 'woocommerce-canadapost-webservice' ) ?>: <?php echo $option['name'] ?></td><td>$<?php echo $option['price'] ?></td>
        					</tr>
        					<?php endforeach; ?>
    					<?php endif; ?>
    					<tr>
    					<td><strong><?php _e( 'Grand Total (CAD)' , 'woocommerce-canadapost-webservice' ) ?>:</strong></td><td><strong>$<?php echo $rate->price ?></strong></td>
    					</tr>
    					<tr>
    					<td><?php _e( 'Payment Method' , 'woocommerce-canadapost-webservice' ) ?>:</td>
    					<td><?php if (!empty($shipment['payment_method']) && $shipment['payment_method'] == 'Account')
        					{ echo $this->get_resource('shipment_payment_onaccount');
        					} else {
    					    echo $this->get_resource('shipment_payment_onfile');
    					   }?></td>
    					</tr>
					</table>
					<input type="hidden" name="rate" value="<?php echo esc_attr(json_encode($rate)) ?>" />
   	      <?php else : ?>
   	        <?php if (!empty($shipment['package']) && !empty($shipment['method_id'])) : ?>
   	            <?php if (!empty($shipment['package']['weight']) &&  !empty($shipment['package']['length']) && !empty($shipment['package']['width']) && !empty($shipment['package']['height'])) : ?>
   	            <?php _e( 'The selected service did not have rates for the package entered' , 'woocommerce-canadapost-webservice' ) ?>: 
   	               <?php echo esc_html($shipment['package']['length']) . 'x' . esc_html($shipment['package']['width']). 'x' . esc_html($shipment['package']['height']). 'cm / ' . esc_html($shipment['package']['weight']).'kg';  ?>
   	               <?php  _e('Try selecting a different service')?>.
   	            <?php else: ?>
   	            <?php _e( 'Summary Pending..' , 'woocommerce-canadapost-webservice' ) ?>
   	            <?php endif; ?>
   	            <?php else: ?>
   	            <?php _e('Please choose a Shipping Service / Method','woocommerce-canadapost-webservice' )?>
   	         <?php endif; ?>
   	      <?php endif; ?>
   	      <?php 
	    }
	    
	    if ($return_ajax){
	        // ajax response
	        exit;
	    }
	}
	
	
	/*
	 * Displays Shipment Label
	 */
	private function display_view_shipment($order_id, $package_index, $order, $shipment) 
	{
	    // Add Receipt details to shipment['label'] (If not populated yet).
	    if (empty($shipment['label']->rated_weight)){
	       if (!empty($this->options->contractid)){
	            // Contract Customer Get Shipment details
	            $label = $this->ct_shipment_getdetails($shipment);
	       } else {
	           // Non-Contract Customer Get Shipment details
	           $label = $this->nc_shipment_getdetails($shipment);
	       }
	       if (!empty($label->links) && !empty($label->rated_weight)){
	           $shipment['label'] = $label;
	           // Save Label Data.
	           $this->save_shipment_data($shipment, $order_id, $package_index);
	       }
	    }	    	      
	    // Begin template
	    ?>
	     <header id="shipment_data" data-orderid="<?php echo esc_attr($order_id)?>" data-packageindex="<?php echo esc_attr($package_index)?>">
		  <h1><?php _e( 'Shipment' , 'woocommerce-canadapost-webservice' ); ?> <?php echo $this->get_resource('method_title'); ?></h1>
	     </header>
	   <article>
	   <div class="row">
	   <div class="col-sm-4">
	      <h3><?php _e('Tracking number', 'woocommerce-canadapost-webservice')?>: <strong><?php echo $shipment['label']->pin ?></strong></h3>
	       <?php
	       // Tracking Data - Check if it should be auto-added.
	       $pin_exists = $this->get_tracking_exists($order_id, $shipment['label']->pin);
	       ?>
	       <div id="cpwebservice_tracking_autoupdate" data-trackingpin="<?php echo $shipment['label']->pin ?>" data-orderid="<?php echo $order_id ?>" data-autosync="<?php echo (!$pin_exists) ? "true" : "false" ?>"></div>
	       <p><?php _e('Created on', 'woocommerce-canadapost-webservice') ?>: <?php echo $shipment['label']->date_created; ?></p>
	   </div>
	   <div class="col-sm-4 top10">
	   <?php if (!empty($shipment['label']->sender_postal)) : ?>
		<?php _e( 'Shipment from' , 'woocommerce-canadapost-webservice' ); ?> 
		<strong><?php echo !empty($shipment['label']->sender_contact) ? esc_html($shipment['label']->sender_contact) : '' ?> 
		<?php echo esc_html($shipment['label']->sender_postal) ?></strong>
		<?php endif; ?>
		<?php if (!empty($shipment['label']->destination_postal)) : ?>
    	<br /><?php _e('Destination', 'woocommerce-canadapost-webservice' )?>: <strong><?php echo !empty($shipment['label']->destination_name) ? esc_html($shipment['label']->destination_name) : '' ?></strong> <br />
    		<?php echo !empty($shipment['label']->destination_city) ? esc_html($shipment['label']->destination_city) : '' ?>
    		<?php echo !empty($shipment['label']->destination_state) ? esc_html($shipment['label']->destination_state) : '' ?>
    		<?php echo !empty($shipment['label']->destination_country) ? esc_html($shipment['label']->destination_country) : '' ?>
    		 <?php echo esc_html($shipment['label']->destination_postal) ?>
        <?php endif; ?>
		</div>
		<?php if (!empty($shipment['label']->cost)):?>
		<div class="col-sm-4 top10">
    		<?php echo __('Cost', 'woocommerce-canadapost-webservice') . ': $' . $shipment['label']->cost ?> <?php echo isset($shipment['label']->cost_currency) ? $shipment['label']->cost_currency : '' ?></span>
    	   <?php if (!empty($shipment['label']->card_name)):?><br /><?php _e('Billed to', 'woocommerce-canadapost-webservice')?>:	<?php echo $shipment['label']->card_name ?> (<?php echo $this->cp_cardtype($shipment['label']->card_type) ?>)<?php endif; ?>
		</div>
		<?php endif;?>
		</div>
		<div class="row">
    		<div class="col-sm-4">
    		  <p><a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_shipment_label_pdf&order_id=' . $order_id . '&package_index=' . $package_index ), 'cpwebservice_shipment_label_pdf' ); ?>" class="button button-primary" target="_blank"><?php _e('Download Shipping Label PDF', 'woocommerce-canadapost-webservice')?></a></p>
    		</div>
		</div>
		<div id="shipment_label_pdf">
		<object data="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_shipment_label_pdf&embed=true&order_id=' . $order_id . '&package_index=' . $package_index ), 'cpwebservice_shipment_label_pdf' ); ?>" type="application/pdf" width="100%" height="100%">
        </object>
		</div>
		</article>
	    <?php 
	}
	
	
	/*
	 * Displays Refund request form
	 */
	private function display_refund_form($order_id, $package_index, $order, $shipment)
	{
	    ?>
		   <header id="shipment_data" data-orderid="<?php echo esc_attr($order_id)?>" data-packageindex="<?php echo esc_attr($package_index)?>">
			  <h1><?php _e( 'Shipment' , 'woocommerce-canadapost-webservice' ); ?> <?php echo $this->get_resource('method_title'); ?></h1>
		     </header>
		   <article>
			<form id="form_refund" class="form">
			<div class="row top20">
			 <div class="col-sm-4">
			     <strong><?php _e('Tracking number', 'woocommerce-canadapost-webservice')?>: <?php echo $shipment['label']->pin ?></strong>
			 </div>
			 <div class="col-sm-4">
			 <?php if (!empty($shipment['label']->sender_postal)) : ?>
        		<?php _e( 'Shipment from' , 'woocommerce-canadapost-webservice' ); ?> <strong><?php echo !empty($shipment['label']->sender_contact) ? esc_html($shipment['label']->sender_contact) : '' ?> <?php echo esc_html($shipment['label']->sender_postal) ?></strong>
        		<?php endif; ?>
        		<?php if (!empty($shipment['label']->destination_postal)) : ?><br /><?php _e('Destination', 'woocommerce-canadapost-webservice' )?>: <strong><?php echo !empty($shipment['label']->destination_name) ? esc_html($shipment['label']->destination_name) : '' ?></strong> <br />
            		 <?php echo !empty($shipment['label']->destination_city) ? esc_html($shipment['label']->destination_city) : '' ?> <?php echo !empty($shipment['label']->destination_state) ? esc_html($shipment['label']->destination_state) : '' ?> <?php echo !empty($shipment['label']->destination_country) ? esc_html($shipment['label']->destination_country) : '' ?> <?php echo esc_html($shipment['label']->destination_postal) ?>
                     <?php endif; ?>
                <br />
			 </div>
			</div>
			<hr />
			<h3><?php _e('Request Refund for Shipment', 'woocommerce-canadapost-webservice' )?></h3>
			<div class="row">
			 <div class="col-sm-8">
			   <?php _e('Use this form to request a refund for a shipment/label that you created in error. You can only request a refund for a shipment that has not been sent and has no scan events associated with the label.', 'woocommerce-canadapost-webservice') ?>  
			 </div>
			</div>
			<div class="row top20">
			 <div class="col-sm-12">
			    <label class="control-label"><?php _e( 'Email for notification of successful refund' , 'woocommerce-canadapost-webservice' ); ?>:</label>  
			 </div>
			 <div class="col-sm-5">
			     <input type="text" name="shipment_email" class="form-control" value="<?php echo esc_attr(get_option('admin_email')) ?>" />
			 </div>
			</div>
			<div class="row top10">
			  <div class="col-sm-12">
			     <input type="button" class="button button-primary" id="btn-refund" value="<?php _e('Request Refund', 'woocommerce-canadapost-webservice') ?>" />
    			<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $order_id . '&package_index=' . $package_index ), 'cpwebservice_create_shipment' ); ?>" class="button-secondary"><?php _e('Cancel', 'woocommerce-canadapost-webservice')?></a>
			  </div>
			</div>   
			</form>
			</article>
		    <?php 
		}
	
		
		/*
		 * Displays Refund request form
		 */
		private function display_refund_complete($order_id, $package_index, $order, $shipment)
		{
		    ?>
				   <header id="shipment_data" data-orderid="<?php echo esc_attr($order_id)?>" data-packageindex="<?php echo esc_attr($package_index)?>">
					  <h1><?php _e( 'Shipment' , 'woocommerce-canadapost-webservice' ); ?> <?php echo $this->get_resource('method_title'); ?></h1>
				     </header>
				   <article>
					<div class="row top20">
        			 <div class="col-sm-4">
            			     <strong><?php _e('Tracking number', 'woocommerce-canadapost-webservice')?>: <?php echo $shipment['label']->pin ?></strong>
            			 </div>
            			 <div class="col-sm-4">
            			 <?php if (!empty($shipment['label']->sender_postal)) : ?>
                    		<?php _e( 'Shipment from' , 'woocommerce-canadapost-webservice' ); ?> <strong><?php echo !empty($shipment['label']->sender_contact) ? esc_html($shipment['label']->sender_contact) : '' ?> <?php echo esc_html($shipment['label']->sender_postal) ?></strong>
                    		<?php endif; ?>
                    		<?php if (!empty($shipment['label']->destination_postal)) : ?><br /><?php _e('Destination', 'woocommerce-canadapost-webservice' )?>: <strong><?php echo !empty($shipment['label']->destination_name) ? esc_html($shipment['label']->destination_name) : '' ?></strong> <br />
                        		 <?php echo !empty($shipment['label']->destination_city) ? esc_html($shipment['label']->destination_city) : '' ?> <?php echo !empty($shipment['label']->destination_state) ? esc_html($shipment['label']->destination_state) : '' ?> <?php echo !empty($shipment['label']->destination_country) ? esc_html($shipment['label']->destination_country) : '' ?> <?php echo esc_html($shipment['label']->destination_postal) ?>
                                 <?php endif; ?>
                            <br />
            			 </div>
            			</div>
            			<hr />
            		<h3><?php _e('Request Refund Complete', 'woocommerce-canadapost-webservice' )?></h3>
					<h4><?php _e('Request has been sent.', 'woocommerce-canadapost-webservice') ?></h4>
					<p><strong><?php _e('Service Ticket', 'woocommerce-canadapost-webservice'); ?>:</strong> <?php echo isset($shipment['refund']->ticket_id) ? esc_html($shipment['refund']->ticket_id) : ''; ?>
    	            <p><strong><?php _e('Date', 'woocommerce-canadapost-webservice'); ?>:</strong> <?php echo isset($shipment['refund']->date) ? esc_html($shipment['refund']->date) : ''; ?></p> 
    	            <p><strong><?php _e('Email to be Notified', 'woocommerce-canadapost-webservice'); ?>:</strong> <?php echo isset($shipment['refund']->email) ? esc_html($shipment['refund']->email) : '';; ?></p>
    	            </article>
				    <?php 
		}
			
		
	/*
	 * Saves the shipment data
	 */
	public function save_draft_shipment() {
	    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cpwebservice_save_draft_shipment' ) )
	         return;
	    
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    if (!isset($_POST['order_id']) || !isset($_POST['package_index'])) {
	        wp_die( __( 'Invalid request.' ) );
	    }
	    // Init
	    if ($this->shippingmethod == null){ $this->init_shippingmethod(); }
	    if ($this->orderdetails == null){ $this->init_orderdetails(); }
	    
	    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
	    $package_index = isset($_POST['package_index']) ? intval($_POST['package_index']) : -1;
	     
	    // Load order details
	    $order = $this->get_order_details($order_id);
	    
	    // Do draft save.
	    $result = $this->save_shipment_post($order, $package_index);
	    
	    if ($result){
	        echo 'true';
	    } else {
	        echo 'false';
	    }
    	// ajax response
	    exit;
	}
	
	/*
	 * Submits shipment data to API.
	 */
	public function save_shipment() {
	    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cpwebservice_save_shipment' ) )
	        return;
	     
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    if (!isset($_POST['shipment_type'])) {
	        wp_die( __( 'Invalid request.' ) );
	    }
	    
	    // Init
	    if ($this->shippingmethod == null){ $this->init_shippingmethod(); }
	    if ($this->orderdetails == null){ $this->init_orderdetails(); }
	    
	    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
	    $package_index = isset($_POST['package_index']) ? intval($_POST['package_index']) : -1;
	    
	    // Load order details
	    $order = $this->get_order_details($order_id);
	     
	    // Do draft save.
	    $result = $this->save_shipment_post($order, $package_index);
	    
	    if ($result){
	        
	        // Submit shipment.
	        $shipment = null;
	        // If Contract Customer
	        if (!empty($this->options->contractid)){
	            $mode = 'transmit'; // TODO Manifest process.
	            $shipment = $this->create_ct_shipment($this->current_shipment, $order, $package_index, $this->shipment_address, $mode);
	        } else {
	            // Non-Contract Customer
	           $shipment = $this->create_nc_shipment($this->current_shipment, $order, $package_index, $this->shipment_address);
	        }
	        
	        if (isset($shipment['label']) && !empty($shipment['label']->id)) {
	            
	           
    	        // Save shipment label
    	        $this->save_shipment_data($shipment, $order['order']->id, $package_index, $this->current_rate);
    	        
    	        // Modal will refresh with a value of true.
    	        echo 'true';
	        } else {
	            // Error ocurred. Display message.
	            _e('Error occured. Error details has been logged. ', 'woocommerce-canadapost-webservice');
	            // Echo 'timeout' error (can't reach Canada Post).
	            if (isset($this->log) && isset($this->log->request) && !empty($this->log->request['http']) && strpos($this->log->request['http'], 'Failed. Error: http_request')===0){
	                _e('HTTP Connection or timeout error occured.', 'woocommerce-canadapost-webservice');
	            }
	            // Echo any api error. 
	            if (isset($this->log) && isset($this->log->request) && !empty($this->log->request['apierror'])){
	                // remove url in brackets { }
	                $apierror = preg_replace("/(.*){(.+)}(.*)/i", "$1$3", $this->log->request['apierror']);
	                echo esc_html($apierror);
	            }
	        }
	    } else {
	       _e('Error occured. Error details has been logged.', 'woocommerce-canadapost-webservice');
	    }
	    exit;
	}
	
	/*
	 * Draft saves shipment data.
	 */
	public function save_shipment_post($order, $package_index) {
	    	
	    if (!empty($order) && !empty($order['order']) && $package_index >= 0){
	        // Init shipment data from session / db (then replace with posted data).
	        $this->current_shipment = $this->get_shipment_data($order, $package_index);
	    
	        // if already submitted as a label, don't change values.
	        if (!empty($this->current_shipment['label'])) {
	            return false;
	        }
	        
	        // Parse posted shipment values.
	        $this->parse_shipment_values();
	        	
	        if (!empty($this->current_shipment)){
	            $this->save_shipment_data($this->current_shipment, $order['order']->id, $package_index, $this->current_rate);
	            // Saved successfully.
	            return true;
	        }
	    }
	    // Didn't save.
	    return false;
	}
	
	/*
	 * Converts posted values to current_shipment array values.
	 */
	function parse_shipment_values() {
	    // Parse posted shipment values from $_POST 
	    if (!empty($this->current_shipment)){ $this->current_shipment = array(); $this->current_shipment['package'] = array(); }
	    // Clean and assign values 
	    $this->current_shipment['method_id']     = woocommerce_clean($_POST['selected_service']);
	    $this->current_shipment['shipment_type']     = woocommerce_clean($_POST['shipment_type']);
	    $this->current_shipment['sender_address_index'] = intval($_POST['sender_address']);
	    $this->current_shipment['contact_phone'] = woocommerce_clean($_POST['contact_phone']);
	    $this->current_shipment['destination_email'] =  woocommerce_clean($_POST['destination_email']);
	    $this->current_shipment['shipping_point_id'] = !empty($_POST['shipping_point_id']) ? woocommerce_clean($_POST['shipping_point_id']) : null;
	    $this->current_shipment['pickup_indicator'] = !empty($_POST['pickup_indicator']) ? woocommerce_clean($_POST['pickup_indicator']) : null;
	    // package
	    $this->current_shipment['package']['weight'] = cpwebservice_resources::save_weight(floatval(woocommerce_clean($_POST['weight'])),  $this->options->display_weights);
	    $this->current_shipment['package']['length'] = cpwebservice_resources::save_unit(floatval(woocommerce_clean($_POST['length'])), $this->options->display_units);
	    $this->current_shipment['package']['width']  = cpwebservice_resources::save_unit(floatval(woocommerce_clean($_POST['width'])), $this->options->display_units);
	    $this->current_shipment['package']['height'] = cpwebservice_resources::save_unit(floatval(woocommerce_clean($_POST['height'])), $this->options->display_units);
	    //$this->current_shipment['package']['length'] = number_format(floatval(woocommerce_clean($_POST['length'])),1,'.','');
	    //$this->current_shipment['package']['width'] = number_format(floatval(woocommerce_clean($_POST['width'])),1,'.','');
	    //$this->current_shipment['package']['height'] = number_format(floatval(woocommerce_clean($_POST['height'])),1,'.','');
	    // reference
	    $this->current_shipment['reference'] = $this->truncate(woocommerce_clean($_POST['reference']), 35);
	    $this->current_shipment['reference_cost'] = $this->truncate(woocommerce_clean($_POST['reference_cost']), 30);
	    $this->current_shipment['reference_additional'] = $this->truncate(woocommerce_clean($_POST['reference_additional']), 35);
	    // options
	    $this->current_shipment['opt_signature'] = isset($_POST['opt_signature']) ? true : false; 
	    $this->current_shipment['opt_delivery_confirmation'] = isset($_POST['opt_delivery_confirmation']) ? true : false;
	    $this->current_shipment['insurance'] = !empty($_POST['insurance']) ? floatval($_POST['insurance']) : '';
	    $this->current_shipment['opt_promocode'] = !empty($_POST['opt_promocode']) ? woocommerce_clean($_POST['opt_promocode']) : '';
	    $this->current_shipment['opt_packinginstructions'] = isset($_POST['opt_packinginstructions']) ? true : false;
	    $this->current_shipment['opt_postrate'] = isset($_POST['opt_postrate']) ? true : false;
	    $this->current_shipment['opt_insuredvalue'] = isset($_POST['opt_insuredvalue']) ? true : false;
	    $this->current_shipment['opt_required'] = !empty($_POST['opt_required']) ? woocommerce_clean($_POST['opt_required']) : '';
	    $this->current_shipment['opt_delivery_door'] = !empty($_POST['opt_delivery_door']) ? woocommerce_clean($_POST['opt_delivery_door']) : '';
	    $this->current_shipment['opt_outputformat'] = !empty($_POST['opt_outputformat']) ? woocommerce_clean($_POST['opt_outputformat']) : '';
	    $this->current_shipment['payment_method'] = !empty($_POST['payment_method']) ? woocommerce_clean($_POST['payment_method']) : '';
	    // customs
	    $this->current_shipment['customs_currency'] = isset($_POST['customs_currency']) ? woocommerce_clean($_POST['customs_currency']) : null;
	    $this->current_shipment['customs_export'] = isset($_POST['customs_export']) ?  woocommerce_clean($_POST['customs_export']) : null;
	    $this->current_shipment['customs_export_other'] = isset($_POST['customs_export_other']) ?  $this->truncate(woocommerce_clean($_POST['customs_export_other']), 44) : null;
	    $this->current_shipment['customs_invoice'] = isset($_POST['customs_invoice']) ?  woocommerce_clean($_POST['customs_invoice']) : null;
	    $this->current_shipment['customs_licenseid'] = isset($_POST['customs_licenseid']) ?  woocommerce_clean($_POST['customs_licenseid']) : null;
	    $this->current_shipment['customs_certificateid'] = isset($_POST['customs_certificateid']) ?  woocommerce_clean($_POST['customs_certificateid']) : null;
	    $this->current_shipment['customs_nondelivery'] = isset($_POST['customs_nondelivery']) ?  woocommerce_clean($_POST['customs_nondelivery']) : null;	  
	    // custom products
	    if(isset($_POST['custom_product_unitcost']) && is_array($_POST['custom_product_unitcost']) ) {
	        $custom_products = array();
	        	
	        for ($i=0; $i < count($_POST['custom_product_unitcost']); $i++){
	            $row = array();
	            $row['unitcost'] = isset($_POST['custom_product_unitcost'][$i]) ? number_format(floatval($_POST['custom_product_unitcost'][$i]),2,'.','') : '';
	            $row['sku'] = isset($_POST['custom_product_sku'][$i]) ? $this->truncate(woocommerce_clean($_POST['custom_product_sku'][$i]), 15) : '';
	            $row['quantity'] = !empty($_POST['custom_product_quantity'][$i]) ? number_format(floatval($_POST['custom_product_quantity'][$i]),0,'.','') : '';
	            $row['description'] = isset($_POST['custom_product_description'][$i]) ? woocommerce_clean($_POST['custom_product_description'][$i]) : '';
	            $row['unitweight'] = isset($_POST['custom_product_unitweight'][$i]) ? cpwebservice_resources::save_weight(floatval($_POST['custom_product_unitweight'][$i]),  $this->options->display_weights) : '';
	            $row['hs_code'] = isset($_POST['custom_product_hs_code'][$i]) ? woocommerce_clean($_POST['custom_product_hs_code'][$i]) : '';
	            $row['origin_country'] = isset($_POST['custom_product_origin_country'][$i]) ? woocommerce_clean($_POST['custom_product_origin_country'][$i]) : '';
	            $row['origin_prov'] = isset($_POST['custom_product_origin_prov'][$i]) ? woocommerce_clean($_POST['custom_product_origin_prov'][$i]) : '';
	            $custom_products[] = $row;
	        }
	        $this->current_shipment['custom_products'] = $custom_products;
	    }
	    // email
	    $this->current_shipment['email_on_shipment'] = isset($_POST['email_on_shipment']) ? true : false;
	    $this->current_shipment['email_on_exception'] = isset($_POST['email_on_exception']) ? true : false;
	    $this->current_shipment['email_on_delivery'] = isset($_POST['email_on_delivery']) ? true : false;
	    
	    // Save Method Name (for display)
	    if (!empty($this->current_shipment['method_id']) && $this->shippingmethod != null){
	        $this->current_shipment['method_name'] =  isset($this->shippingmethod->available_services[$this->current_shipment['method_id']]) ? $this->shippingmethod->available_services[$this->current_shipment['method_id']] : '';
	    }
	    
	    // Save the rates that were looked up.  This is really just for display.
	    $this->current_rate = null;
	    if (!empty($_POST['rate'])) {
	        $this->current_rate = new stdClass();  
	        $rate = json_decode(stripslashes($_POST['rate']), false);
	        if (isset($rate) && is_object($rate)){
	            $this->current_rate->price = !empty($rate->price) ? number_format(floatval($rate->price),2,'.','') : null;
	            $this->current_rate->service = !empty($rate->service) ? woocommerce_clean($rate->service) : '';
	            $this->current_rate->service_code = !empty($rate->service_code) ? woocommerce_clean($rate->service_code) : '';
	            $this->current_rate->guaranteed = !empty($rate->guaranteed) ? woocommerce_clean($rate->guaranteed)=='1' : false; 
	            $this->current_rate->expected_delivery = !empty($rate->expected_delivery) ? woocommerce_clean($rate->expected_delivery) : '';
	        }
	    }
	}
	
	/*
	 * Get Shipment data from meta data.
	 */
	public function get_shipment_data($order, $package_index = 0){
	   
	    $shipping_info  = get_post_meta( $order['order']->id, '_cpwebservice_shipping_info', true);
	    
	    $shipments = !empty($shipping_info) && isset($shipping_info['shipment']) && is_array($shipping_info['shipment']) ? $shipping_info['shipment'] : array();
	    if (!empty($shipments) && !empty($shipments[$package_index])){
	        return array_merge($this->default_shipment, $shipments[$package_index]);
	    }
	    // Retrieve packages and rates data.
	    $packages = !empty($shipping_info) && isset($shipping_info['packages']) && is_array($shipping_info['packages']) ? $shipping_info['packages'] : array();
	    $rates = !empty($shipping_info) && isset($shipping_info['rates']) && is_object($shipping_info['rates']) ? $shipping_info['rates'] : null;
	    $origin = !empty($shipping_info) && !empty($shipping_info['origin']) ? $shipping_info['origin'] : null;

	    // Set up new Shipment.
	    // package data
	    $package = isset($packages[$package_index]) ? $packages[$package_index] : array();
	    // default array attribs. Populate with order information.
	    $method_id = isset($rates) && is_object($rates) ? $rates->service_code : '';
	    // Setup package products if Customs is needed.
	    $custom_products = null;
	    if ($order['order']->shipping_country != 'CA'){
	        $package = isset($packages[$package_index]) ? $packages[$package_index] : array();
	        $next_index = !empty($packages) ? count($packages) : 0;
	        if (!empty($package['products']) && is_array($package['products'])){
	            // convert to custom_products arrray.
	            $product_reference = $this->orderdetails->get_product_array($package['products']);
	            $product_groups = $this->orderdetails->group_products($package['products']);
	            foreach($product_groups as $item){
	                if (isset($item['item_id']) && isset($product_reference[$item['item_id']])){
	                    $p = $product_reference[$item['item_id']];
	                    $custom_info = $this->get_product_customs($p);
	                    $custom_products[] = array('quantity'=>$item['count'], 'description'=> $this->get_product_variation($p) . $p->get_title(), 'sku' => $p->get_sku(), 'unitcost' => $p->get_price(),
	                        'unitweight'=> $this->shippingmethod->convertWeight($p->get_weight(), get_option('woocommerce_weight_unit')), 'hs_code'=>$custom_info->hscodes, 'origin_country'=>$custom_info->origin_country, 'origin_prov'=>$custom_info->origin_prov
	                    );
	            	}// endif
	            }// end foreach
	        } else if ($package_index == $next_index) {
	            // Load Products from Order. 
	            $order_products = $order['order']->get_items();
	            $order_product_ids = array();
	            $item_qty = array();
	            foreach($order_products as $item){
	                if ( $item['product_id'] > 0 ) { 
	                    $order_product_ids[] =  array('item_id' => $item['product_id']); 
	                    $item_qty[$item['product_id']] = $item['qty']; 
	                }
	            } // end foreach
	            if (count($order_product_ids) > 0){
    	            // get $product_reference arrray.
    	            $product_reference = $this->orderdetails->get_product_array(array($order_product_ids));
    	            foreach($order_products as $item){
    	                if (isset($item['product_id']) && $item['product_id'] > 0 && isset($product_reference[$item['product_id']])){
    	                    $p = $product_reference[$item['product_id']];
    	                    $custom_info = $this->get_product_customs($p);
    	                    $qty = isset($item_qty[$item['product_id']]) ? $item_qty[$item['product_id']] : '';
    	                    $custom_products[] = array('quantity'=>$qty, 'description'=>$this->get_product_variation($p) . $p->get_title(), 'sku' => $p->get_sku(), 'unitcost' => $p->get_price(),
    	                        'unitweight'=> $this->shippingmethod->convertWeight($p->get_weight(), get_option('woocommerce_weight_unit')), 'hs_code'=>$custom_info->hscodes, 'origin_country'=>$custom_info->origin_country, 'origin_prov'=>$custom_info->origin_prov
    	                    );
    	                }// endif
    	            }// end foreach
	            }// endif
	        } else {
	            // default custom_products array
	            $custom_products = $this->default_customs;
	        }
	    }
	    $sender_address_index = 0;
	    if (!empty($origin)){
	        // find address index.
	        for($i=0;$i<count($this->shipment_address); $i++){
	            if ($this->shipment_address[$i]['postalcode'] == $origin) { $sender_address_index = $i;  }
	        }
	    }
	    // Return new $shipment[$package_index]
	    return array_merge($this->default_shipment, array('order_id'=>$order['order']->id, 'method_id'=> $method_id, 'custom_products' => $custom_products, 'package' => $package,
	                       'contact_phone'=>$order['order']->billing_phone, 'destination_email'=>$order['order']->billing_email, 'reference' => 'WC'.$order['order']->id, 'sender_address_index'=>$sender_address_index));
	}
	
	/*
	 * Save shipment data to order meta data.
	 */
	public function save_shipment_data($shipment, $order_id, $package_index = 0, $rate = null){
	    $index = isset($package_index) ? intval($package_index) : 0;
	    if (!empty($shipment) && is_array($shipment)){
	        // Retrieve from meta data
	        $shipping_info  = get_post_meta( $order_id, '_cpwebservice_shipping_info', true);
	        $packages = !empty($shipping_info) && isset($shipping_info['packages']) && is_array($shipping_info['packages']) ? $shipping_info['packages'] : array();
	        $rates = !empty($shipping_info) && isset($shipping_info['rates']) && is_object($shipping_info['rates']) ? $shipping_info['rates'] : null;
	        $shipment_array = !empty($shipping_info) && isset($shipping_info['shipment']) && is_array($shipping_info['shipment']) ? $shipping_info['shipment'] : array();
	        
	        // Write Shipment
	        $shipment_array[$index] = $shipment;
	        // Save Shipment
	        $shipping_info['shipment'] = $shipment_array;
	        
	        // Update package and rates
	        $p = $shipment['package']; // contains dimensions and weight.
	        $p['cubic'] = (!empty($p['length']) && !empty($p['length']) && !empty($p['width'])) ? ($p['height'] * $p['width'] * $p['height']) : 0; // calc cubic.
	        $p['actual_weight'] = null;
	        $packages[$index] = !empty($packages[$index]) ? array_merge($packages[$index], $p) : $p;
	        
	        // Save Rates (in Package)
	        if (isset($rate) && is_object($rate)){
	            $rate_array = array();
	            $rate_array[0] = $rate;
	            $packages[$index]['rate'] = $rate_array;
	        }
	        
	        // Save Products 
	        // TODO (maybe)
	        
	        // Save Packages
	        $shipping_info['packages'] = $packages;
	        
// 	        echo json_encode($shipping_info);
// 	        exit;
	        
	        // Save to order meta data.
	        update_post_meta( $order_id, '_cpwebservice_shipping_info', $shipping_info );
	    }
	}
	
	/*
	 * Delete shipment data to order meta data.
	 */
	public function delete_shipment_draft($order_id, $package_index = 0){
	    $index = isset($package_index) ? intval($package_index) : 0;
	    if (!empty($order_id) && $index >= 0){
	        // Retrieve from meta data
	        $shipping_info  = get_post_meta( $order_id, '_cpwebservice_shipping_info', true);
	        $packages = !empty($shipping_info) && isset($shipping_info['packages']) && is_array($shipping_info['packages']) ? $shipping_info['packages'] : array();
	        $shipment_array = !empty($shipping_info) && isset($shipping_info['shipment']) && is_array($shipping_info['shipment']) ? $shipping_info['shipment'] : array();
	       
	        // This package index
	        $label_created = (isset($shipment_array[$index]) && isset($shipment_array[$index]['label']));
	        
	        // Can only delete if label has not been created (and therefore would no longer be draft, but a real shipment).
	        if (!$label_created){
	            
	            // Remove this package index.
	            if ($packages != null && isset($packages[$index])){
	                unset($packages[$index]);
	                $shipping_info['packages'] = $packages;
	            }
	            if ($shipment_array != null && isset($shipment_array[$index])){
	                unset($shipment_array[$index]);
	                $shipping_info['shipment'] = $shipment_array;
	            }
	            
    	        // Save to order meta data.
    	        update_post_meta( $order_id, '_cpwebservice_shipping_info', $shipping_info );
	        }
	    }
	}
	
	// not used
// 	public function get_shipment_session($order, $package_index = 0)
// 	{
// 	    $session = WC()->session->get( 'cpwebservice_shipments_'.$order['order']->id );
// 	    return $session;
// 	}
	
// 	// not used.
// 	public function save_shipment_session($shipment, $order_id, $package_index = 0){
// 	    $index = isset($package_index) ? intval($package_index) : 0;
// 	    if (!empty($shipment) && is_array($shipment)){
//     	    // Retrieve from session
//     	    $shipments = WC()->session->get( 'cpwebservice_shipments_'.$order_id );
//     	    // ensure it is an array.
//     	    if (empty($shipments) || !is_array($shipments)){ $shipments = array(); }
//     	    // Write.
//     	    $shipments[$index] = $shipment;
//     	    // Set to session.
//     	    WC()->session->set( 'cpwebservice_shipments_'.$order_id, $shipments );
// 	    }
// 	}
	
	/*
	 * Get Woocommerce order information as well as shipping metadata.
	 */
	public function get_order_details($order_id)
	{
	    // Lookup order in Woocommerce.
	    $order = new WC_Order( $order_id );
	    if (!empty($order) && is_object($order)){
    	    $order_items    = $order->get_items();
    	    $order_shipping = $order->get_shipping_methods();
    	    $shipping_address = $order->get_formatted_shipping_address();
    	    
    	    return array('order'=>$order, 'order_items'=>$order_items, 'order_shipping'=>$order_shipping, 'shipping_address'=>$shipping_address);
	    }
	    return null; // not found.
	}
	
	
	/* 
	 * Use Lookup Rates and Services functions from ShipMethod class
	 */
	public function init_shippingmethod() 
	{
	    // Load up woocommerce shipping stack.
	    do_action('woocommerce_shipping_init');
	    // Add shipping method class
	    $this->shippingmethod = new woocommerce_cpwebservice();
	}
	
	public function init_orderdetails()
	{
	    $this->orderdetails = new woocommerce_cpwebservice_orderdetails();
	}
	
	
	/**
	 * Ajax function to Display Shipment Log.
	 */
	public function shipment_log_display() {
	
	    // Let the backend only access the page
	    if( !is_admin() ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	
	    // Check the user privileges
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	
	    // Nonce.
	    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_shipment_log_display' ) )
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    	
	    	
	    if (false !== ( $shipmentlog = get_transient('cpwebservice_shipment_log') ) && !empty($shipmentlog) && is_array($shipmentlog)) :
	      foreach($shipmentlog as $logitem):
	        $log = (object) array_merge(array('params'=>array(),'request'=>array(),'result'=>array(), 'datestamp'=>''), (array) $logitem);
	        ?>
										<h4><?php _e('Shipment Log at', 'woocommerce-canadapost-webservice')?> <?php echo date("F j, Y, g:i a",$log->datestamp); ?></h4>
										
										<h4><?php _e('Request / API Response', 'woocommerce-canadapost-webservice')?></h4>
										<div style="max-width:800px;">
										<?php foreach($log as $key=>$l):?>
										<?php if (!empty($l)) { ?>
    										<h4><?php echo esc_html($key); ?>:</h4> 
    										<p><?php if (is_array($l)) {
    										      foreach($l as $n=>$m) {
    										          echo esc_html($n).' '. esc_html(json_encode($m)).'<br />';
    										      }
    										} else {  echo esc_html(json_encode($l)); }
    										?></p>
										<?php } ?>
										<?php endforeach;?>
										</div>
										
           <?php endforeach;
           else: ?>
				<?php _e('No log information.. yet.  Go to an order and click on the "Create Shipment" button.', 'woocommerce-canadapost-webservice') ?>
		   <?php  endif;
			exit;
	}
	
	
	/*
	 * API function for Creating non-contract shipments 
	 */
	public abstract function create_nc_shipment($shipment, $order, $package_index, $sender);
	
	/*
	 * API function to get receipt details from non-contract shipments
	 */
	public abstract function nc_shipment_getdetails($shipment);
	
	/*
	 * Api function to retrieve PDF shipping label.
	 */
	public abstract function get_shipping_label($id, $label_url, $type = "pdf");
	
	/*
	 * Returns the shipping label pdf file to the browser.
	 */
	public function shipment_label_pdf()
	{
	    // Let the backend only access the page
	    if( !is_admin() ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    
	    // Check the user privileges
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    
	    // Nonce.
	    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_shipment_label_pdf' ) )
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    
	    
	    // Parameters
	    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
	    $package_index = isset($_GET['package_index']) ? intval($_GET['package_index']) : 0;
	    $embed = isset($_GET['embed']) ? ($_GET['embed']=='true') : false;
	    
	    $order = $this->get_order_details($order_id);
	    if (!empty($order) && !empty($order['order'])){
	        $shipment = $this->get_shipment_data($order, $package_index);
	        if (!empty($shipment) && !empty($shipment['label'])){
	       
            // Begin shipping label pdf
        	    $label = $shipment['label'];
        	    // Get pdf label
        	    $label_url = '';
        	    $label_type = 'application/pdf'; // default
        	    foreach($label->links as $link){
        	        if ($link->type == 'label') {
        	            $label_url = $link->href;
        	            $label_type = $link->media_type != '' ? $link->media_type : 'application/pdf';
        	        }
        	    }
        	    if (!empty($label_url) && !empty($label->id)){
        	        
            	    // Get label pdf from webservice.
            	    $label_pdf = $this->get_shipping_label($label->id, $label_url, ($label_type != 'application/pdf' ? 'zpl' : 'pdf'));
        	    
            	    if (!empty($label_pdf)) {
            	        
            	        // Send PDF contents to browser.
            	        header('Cache-Control: private');
            	        header('Content-Type: '. $label_type);
            	        header('Content-Disposition: '.($embed ? 'inline' : 'attachment').'; filename="'.$label->id.($label_type != 'application/pdf' ? '.zpl' : '.pdf').'"');
            	        header('Content-Length: '.( $this->cachemode == 'filecache' ? filesize($label_pdf) : strlen($label_pdf)));
            	        header("Content-Transfer-Encoding: Binary");
            	        
            	        if ($this->cachemode == 'filecache'){
                	        // Send the file.
                	        readfile($label_pdf);
            	        } else if ($this->cachemode == 'dbcache'){
            	            // echo byte array
            	            echo $label_pdf;
            	        }
            	        
            	        // end response.
            	        exit;
            	        
            	    } else {
            	        // Not found.
            	    }
        	    } else {
        	        // None found.
        	    }
	      } // endif
	    } // endif
	    
	    _e('PDF file not found. ', 'woocommerce-canadapost-webservice');
	    
	    if (!empty($this->log->request['service'])) {
	        echo $this->log->request['service'];
	    }
	    
	    exit;
	}
	
	// Clear any transients that may have been used for dbcache.
	public function delete_expired_transients() {
	
	    global $wpdb, $_wp_using_ext_object_cache;
	
	    if( $_wp_using_ext_object_cache )
	        return;
	
	    $time = time() ;
	    $expired = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_cpwebservice_%' AND option_value < {$time};" );
	
	    foreach( $expired as $transient ) {
	
	        $key = str_replace('_transient_timeout_', '', $transient);
	        delete_transient($key);
	    }
	}
	
	
	public function custom_upload_subdir( $uploads ) {
	    // custom subdir
	    $uploads['subdir'] = '/cpwebservice';
	    $uploads['path'] =  $uploads['basedir'].$uploads['subdir'];
	    $uploads['url'] = $uploads['baseurl'].$uploads['subdir'];
	    return $uploads;
	}
	
	public function ensure_subdir_security($subdir){
	    if ( !file_exists( $subdir.'/.htaccess') ) {
	        file_put_contents($subdir.'/.htaccess', 'deny from all');
	    }
	    if ( !file_exists( $subdir.'/index.html') ) {
	        file_put_contents($subdir.'/index.html', ' ');
	    }
	}
	
	// utility string truncate function.
	public function truncate($string,$length=100,$append="") {
	    $string = trim($string);
	
	    if(strlen($string) > $length) {
	        $string = wordwrap($string, $length);
	        $string = explode("\n", $string, 2);
	        $string = $string[0] . $append;
	    }
	
	    return $string;
	}
	
	public function get_tracking_exists($order_id, $pin){
	    $trackingPin = get_post_meta( $order_id, '_cpwebservice_tracking', true);
	    $trackingPins = $trackingPin; // TODO change to array or multiple valued key.
	    if (!empty($trackingPins) && in_array($pin, $trackingPins)){
	       return true;
	    } else {
	       return false;
	    }
	}
	
	public function cp_cardtype($type){
	    switch ($type){
	        case "MC": return "MasterCard";
	        case "VIS": return "Visa";
	        case "AME": return "AmericanExpress";
	        default: return $type;
	    }
	}
	
	public function get_product_customs($product){
	    
	    $product_customs = get_post_meta( $product->is_type('variation') ? $product->variation_id : $product->id, '_cpwebservice_product_customs', true );
	    $product_customs_value = !empty($product_customs) ? implode('',$product_customs) : '';
	    if((empty($product_customs) || empty($product_customs_value)) && $product->is_type('variation') && !empty($product->parent)){
	        $product_customs = get_post_meta( $product->parent->id, '_cpwebservice_product_customs', true );
	    }
	    $default = array('hscodes'=>'','origin_country'=>'','origin_prov'=>'');
	    $product_customs = (object) array_merge($default, !empty($product_customs) ? $product_customs : array());
	    
	    return $product_customs;
	}
	public function get_product_variation($product) {
	    if ($product->is_type('variation')){
	        $variation = $product->get_variation_attributes();
	        return implode(',', $variation) . ' ';
	    }
	    return '';
	}
	
	/*
	 * Api function for refunding non-contract shipments.
	 * Returns refund details.
	 */
	public abstract function nc_shipment_refund($refund_url, $shipment_email);
	
	/*
	 * Ajax request for shipment refund.
	 */
	public function shipment_refund(){
	    if( !is_admin() ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	     
	    // Check the user privileges
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	     
	    // Nonce.
	    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cpwebservice_shipment_refund' ) )
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	     
	    // Parameters
	    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
	    $package_index = isset($_POST['package_index']) ? intval($_POST['package_index']) : 0;
	    
	    // Get Shipment info
	    $order = $this->get_order_details($order_id);
	    $shipment = $this->get_shipment_data($order, $package_index);
	    
	    // Validate whether this shipment label can be refunded.
	    if (isset($shipment['label']) && $shipment['label'] != null ){
	        $label = $shipment['label'];
	        // Get refund url
	        $refund_url = '';
	        foreach($label->links as $link){
	            if ($link->type == 'refund') {
	                $refund_url = $link->href;
	                break;
	            }
	        }
	        
	        if (!empty($refund_url)){
	        
    	        $shipment_email = isset($_POST['shipment_email']) ? sanitize_email($_POST['shipment_email']) : '';
    	        // Ensure it is a valid email.
    	        if (empty($shipment_email) || !is_email($shipment_email)) {
    	            // Use Administrator email.
    	            $shipment_email = get_option('admin_email');
    	        }
    	        
    	        // Do Refund Request.
    	        $refund = $this->nc_shipment_refund($refund_url, $shipment_email);
    	        
    	        if (isset($refund) && is_object($refund) && !empty($refund->ticket_id)) {
    	           
    	            // Save to Shipment data (object)
    	            $shipment['refund'] = $refund;
    	            $shipment['refund']->date_created = current_time( 'mysql' ); // local wp date/time
    	            
    	            $this->save_shipment_data($shipment, $order_id, $package_index);
    	            
    	            // Return success/Message.
    	            echo 'true';
    	            //_e('Success! Refund request was submitted.', 'woocommerce-canadapost-webservice');
    	        } else {
    	            // Error. Response was empty.
    	            _e('Error: Refund request was invalid.', 'woocommerce-canadapost-webservice');
    	            _e('Please request a refund by contacting', 'woocommerce-canadapost-webservice' );
    	            echo $this->get_resource('method_title');
    	        }
	        } else {
	            // Label Refund link not found.
	            _e('Error: Refund request was invalid.', 'woocommerce-canadapost-webservice');
	            _e('Please request a refund by contacting', 'woocommerce-canadapost-webservice' );
	            echo $this->get_resource('method_title');
	        }
	        
	    } else {
	        // Invalid. Label has not been created.
	        _e('Error: Cannot refund. Shipment label has not been created.', 'woocommerce-canadapost-webservice');
	        
	    }
	    exit; // ajax response.
	}
	
	
	/*
	 * Ajax request for draft shipment removal. 
	 */
	public function shipment_remove(){
	    if( !is_admin() ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	
	    // Check the user privileges
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	
	    // Nonce.
	    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cpwebservice_shipment_remove' ) )
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	
	    // Parameters
	    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
	    $package_index = isset($_GET['package_index']) ? intval($_GET['package_index']) : 0;
	     
	    // Get Shipment info
	    $order = $this->get_order_details($order_id);
	    $shipment = $this->get_shipment_data($order, $package_index);
	     
	    // Validate whether this shipment is just a draft (no label or refund) and can be deleted.
	    if (!isset($shipment['label']) && !isset($shipment['refund'])){
	        
	        // Delete shipment data.
	        $this->delete_shipment_draft($order_id, $package_index);
		    _e('Success! Draft shipment was deleted.', 'woocommerce-canadapost-webservice');
		
		} else {
		        // Invalid. Label has already been created.
		        _e('Error: Cannot remove. Shipment label has already been created.', 'woocommerce-canadapost-webservice');
	    }
	    exit; // ajax response.
	}
	
	// Begin Shipment Templates
	
	public function shipment_ajax_security() {
	    if( !is_admin() ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	     
	    // Check the user privileges
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	     
	    // Nonce.
	    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cpwebservice_save_shipment_template' ) ){
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	}
	
	public function shipment_template() {
	    $result = false;
	    $this->shipment_ajax_security();
	    
	    // Parameters	    
	    $template_name = isset($_POST['name']) ? woocommerce_clean($_POST['name']) : __('Template', 'woocommerce-canadapost-webservice');
	    
	    // Parse posted shipment values.
	    $this->parse_shipment_values();
	    
	    if (!empty($this->current_shipment)){
	        $this->save_shipment_template($this->current_shipment, $template_name);
	        // Saved successfully.
	        $result = true;
	    }
	    
	    echo $result ? 'true' : 'false';
	    
	    exit; // ajax response.
	}
	
	// Ajax function
	public function shipment_template_list() {
	    $this->shipment_ajax_security();
	    
	    $this->load_shipment_template_list();
	    echo json_encode($this->templates);
	    exit; // ajax response.
	}
	
	
	
	// Ajax function
	public function shipment_template_remove() {
	    $this->shipment_ajax_security();
	            
	    if ( isset($_POST['index'])){
    	    // Params
    	    $index = intval($_POST['index']);
    	    
    	    $this->load_shipment_template_list();
    	    if (isset($this->templates[$index])){
    	        unset($this->templates[$index]);
    	    }
    	    // Reset array index
    	    $this->templates = array_values($this->templates);
    	    // Save to options.
    	    update_option('woocommerce_cpwebservice_shipment_templates', $this->templates);
    	    echo 'true';
    	} else {
	        echo 'false';
	    }
	    exit; // ajax response.
	}
	
	
	public function save_shipment_template($shipment, $name){
	    // Save template-data.  $shipment is already validated/cleaned/parsed with parse_values function.
	    $data = array('sender_address_index' => intval($shipment['sender_address_index']),
	                   'shipping_point_id' => $shipment['shipping_point_id'],
	                   'pickup_indicator' => $shipment['pickup_indicator'],
	                   //'contact_phone'=> $shipment['contact_phone'],
	                   //'destination_email'=> $shipment['destination_email'],
	                   'shipment_type'=>$shipment['shipment_type'],
	                    // Method id. If available in dropdownlist.
	                    'method_id' => $shipment['method_id'], 
            	        //  Package array
	                    'package' => $shipment['package'],
            	        //  Options
	                    'opt_signature' => $shipment['opt_signature'],
            	        'opt_delivery_confirmation'=> $shipment['opt_delivery_confirmation'],
            	        'insurance' => $shipment['insurance'],
            	        'opt_promocode' => $shipment['opt_promocode'],
            	        'opt_packinginstructions' => $shipment['opt_packinginstructions'],
            	        'opt_postrate' => $shipment['opt_postrate'],
            	        'opt_insuredvalue' => $shipment['opt_insuredvalue'],
            	        'opt_required' => $shipment['opt_required'],
            	        'opt_delivery_door' => $shipment ['opt_delivery_door'],
            	        'opt_outputformat' => $shipment['opt_outputformat'],
            	        'payment_method' => $shipment['payment_method'],
            	        // Notification
            	        'email_on_shipment' => $shipment['email_on_shipment'],
            	        'email_on_exception' => $shipment['email_on_exception'],
            	        'email_on_delivery' => $shipment['email_on_delivery']
	    );
	    if (!empty($this->options->template_customs) && $this->options->template_customs == true){
	        // Customs Data
	        $data = array_merge($data, array(
            	        'customs_currency' => $shipment['customs_currency'],
            	        'customs_export' => $shipment['customs_export'],
            	        'customs_export_other' => $shipment['customs_export_other'],
            	        'customs_invoice' => $shipment['customs_invoice'],
            	        'customs_licenseid' => $shipment['customs_licenseid'],
            	        'customs_certificateid' => $shipment['customs_certificateid'],
            	        'customs_nondelivery' => $shipment['customs_nondelivery']));
	        if (!empty($this->options->template_customs_products) && $this->options->template_customs_products == true){
	            // Customs Products
	            $data['custom_products'] = $shipment['custom_products'];   
	        }
	    }
	    $this->load_shipment_template_list();
	    // Append to array
	    $this->templates[] = array('name'=>woocommerce_clean($name), 'data'=> $data );
	    // Save to options.
	    update_option('woocommerce_cpwebservice_shipment_templates', $this->templates);
	}
	
	public function load_shipment_template_list() {
	    $this->templates = array_values((array)get_option('woocommerce_cpwebservice_shipment_templates', array()));
	}
	
	
	
	/*
	* Api function for Creating Contract Shipments
	*/
	public abstract function create_ct_shipment($shipment, $order, $package_index, $sender, $mode);
	
	/*
	 * API function to get receipt details from contract shipments
	 */
	public abstract function ct_shipment_getdetails($shipment);
	
	/*
	 * Api function for retrieving Manifest report/document
	 */
	public abstract function get_manifest();
	
	
	public function save_log($log) {
	    // Get current transient (if any)
	    $shipmentlog = get_transient('cpwebservice_shipment_log');
	    if (!empty($shipmentlog) && is_array($shipmentlog)){
	        // save in array
	        $newlog = array($log);
	        // Only keep 5 at a time. LIFO
	        for($i=0;$i<count($shipmentlog);$i++){
	            $newlog[] = $shipmentlog[$i];
	            if ($i >= 3) break;
	        }
	        $shipmentlog = $newlog;
	    } else {
	        // save in array
	        $shipmentlog = array($log);
	    }
	    // Save Transient.
	    set_transient( 'cpwebservice_shipment_log', $shipmentlog, 20 * MINUTE_IN_SECONDS );
	}
	
}