<?php
/*
 Main Tracking Class
woocommerce_cpwebservice_tracking.php

Copyright (c) 2013-2016 Jamez Picard

*/
abstract class cpwebservice_tracking
{

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return woocommerce_cpwebservice_tracking
	 */
	function __construct() {
		$this->init();
	}

	/**
	 * init function.
	 *
	 * @access public
	 * @return void
	 */
	function init() {
		$default_options = (object) array('enabled'=>'no', 'title'=>'', 'api_user'=>'', 'api_key'=>'','account'=>'','contractid'=>'','source_postalcode'=>'','mode'=>'live', 'delivery'=>'', 'margin'=>'', 'packageweight'=>floatval('0.02'), 'boxes_enable'=> false, 'lettermail_enable'=> false, 'shipping_tracking'=> true, 'email_tracking'=> true, 'log_enable'=>false,'lettermail_limits'=>false,'lettermail_maxlength'=>'','lettermail_maxwidth'=>'','lettermail_maxheight'=>'', 
		    'tracking_icons'=> true, 'api_dev_user'=>'', 'api_dev_key'=>'', 'tracking_colcss'=>'width:21%;float:left;padding:4px;', 'tracking_msgcss'=>'width:50%;float:left;padding:4px;text-align:center;');
		$this->options		= get_option('woocommerce_cpwebservice', $default_options);
		$this->options		= (object) array_merge((array) $default_options, (array) $this->options); // ensure all keys exist, as defined in default_options.
		$this->enabled		= $this->options->shipping_tracking && ( !empty($this->options->api_user) && !empty($this->options->api_key) );
		$this->log 	        = (object) array('params'=>array(),'request'=>array('http'=>''), 'apierror'=> '');

		if ($this->enabled) {
			// Actions
			add_action( 'add_meta_boxes', array(&$this, 'add_tracking_details_box') );
			add_action('wp_ajax_cpwebservice_update_order_tracking', array(&$this, 'update_order_tracking'));
			add_action('woocommerce_order_details_after_order_table',  array(&$this, 'add_tracking_details_customer') );
			add_action('woocommerce_email_after_order_table',  array(&$this, 'add_tracking_details_customer') );
			add_action('cpwebservice_tracking_lookup', array(&$this, 'lookup_tracking'), 10, 3 );
		}

	}
	
	/*
	 * Return resources
	 */
	abstract function get_resource($id);
	
	
	// Customer My Order page displays tracking information.
	public function add_tracking_details_customer($order) {
		$post_id = $order->id;
		//if ($order->status!='pending'){
		// Lookup Tracking data, then look for postmeta with a Tracking Number.
		$trackingPin = get_post_meta( $post_id, '_cpwebservice_tracking', true);
		$trackingData = array();
			
		if (!empty($trackingPin) && is_array($trackingPin)){
				
			foreach($trackingPin as $pin){
				// Does cached lookup
				$trackingData[] = $this->lookup_tracking($post_id, $pin);
			}
			echo '<header><h2>'.__( 'Order Shipping Tracking', 'woocommerce-canadapost-webservice' ).'</h2></header>';
			echo $this->display_tracking($trackingData, $post_id, false, false, true); // does not display admin btns./activates inline styles.
		}
		//}
	}

	/* Adds a box to the main column on the Post and Page edit screens */
	public function add_tracking_details_box() {
		add_meta_box( 'cpwebservice_tracking', __( 'Order Shipping Tracking', 'woocommerce-canadapost-webservice' ),  array(&$this,'display_tracking_view'), 'shop_order', 'normal', 'default' );
	}

	public function display_tracking_view(){
		global $post_id;
		?>
		<div id="cpwebservice_tracking_result">
		<?php 
		// Lookup Shipping method used. Then look for postmeta with a Tracking Number.
		$trackingPin = get_post_meta( $post_id, '_cpwebservice_tracking', true);
		
		$trackingData = array();
		
		if (!empty($trackingPin) && is_array($trackingPin)){
			
			foreach($trackingPin as $pin){
				// Does cached lookup 
				$trackingData[] = $this->lookup_tracking($post_id, $pin);
			}
	
			echo $this->display_tracking($trackingData, $post_id, false, true);
			
		}
		?>
		</div>
		<ul> 
		<li><img src="<?php echo plugins_url( $this->get_resource('method_logo_url') , dirname(__FILE__) ); ?>" style="vertical-align:middle" /> <input type="text" class="input-text" size="22" name="cpwebservice_trackingid" id="cpwebservice_trackingid" placeholder="" value="" /> 
		<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_update_order_tracking&order_id=' . $post_id ), 'cpwebservice_update_order_tracking' ); ?>&trackingno=" class="button tips canadapost-tracking" target="_blank" title="<?php _e( 'Add Tracking Pin', 'woocommerce-canadapost-webservice' ); ?>" data-tip="<?php _e( 'Add Tracking Pin', 'woocommerce-canadapost-webservice' ); ?>">
		<?php _e( 'Add Tracking Pin', 'woocommerce-canadapost-webservice' ); ?> 
		</a> <div class="cpwebservice_ajaxsave canadapost-spinner" style="display: none;"><div class="canadapost-spinner-b1"></div><div class="canadapost-spinner-b2"></div><div class="canadapost-spinner-b3"></div></div> </li>
		</ul>
		
		<?php wp_nonce_field( plugin_basename( __FILE__ ), 'cpwebservice_tracking_noncename' ); ?>
		
		<?php 
		
	}
	
	/*
	 * Lookup Tracking from Api
	 */
	abstract public function tracking_url($pin, $locale);
	
	
	
	/* Does Lookup & Displays Tracking information */
	public function display_tracking($trackingData, $post_id, $only_rows=false, $display_buttons=false, $inline_styles=false){
		
		// Locale for Link to CP.
		// $locale = 'en' : 'fr';
		if (defined('ICL_LANGUAGE_CODE')){
			$locale = (ICL_LANGUAGE_CODE=='fr') ? 'fr':'en'; // 'en' is default
		} else if (get_locale() == 'fr_FR' || get_locale() == 'fr_CA'){
			$locale = 'fr';
		} else {
			$locale = 'en';
		}
		// Inline styles (for email)
		// row
		$inline_row = $inline_styles ? ' style="clear:both;margin-bottom:4px;"' : '';
		// Columns
		$inline = $inline_styles ? ' style="'.esc_attr($this->options->tracking_colcss).'"' : '';
		$inline_pin = $inline_styles ? $inline.'font-size:110%;white-space: nowrap;min-width: 154px;"' : '';
		$inline_message = $inline_styles ? ' style="'.esc_attr($this->options->tracking_msgcss).'"' : '';
		
		// Display Tracking info:
		$html = '';
		if (count($trackingData) > 0){
			
		    // Get Output, return as string.
		    ob_start();
		    
		    ?><div class="widefat canadapost-tracking-display">
			<?php if (!$only_rows): ?>
			<div class="canadapost-tracking-header"<?php echo $inline_row?>><?php if ($display_buttons): ?><div class="canadapost-tracking-col canadapost-tracking-col-sm"<?php echo $inline?>></div><?php endif; ?><div class="canadapost-tracking-col"<?php echo $inline_pin?>><?php _e( 'Tracking Number', 'woocommerce-canadapost-webservice' )?></div><div class="canadapost-tracking-col"<?php echo $inline?>><?php _e( 'Event', 'woocommerce-canadapost-webservice' )?></div><div class="canadapost-tracking-col"<?php echo $inline?>><?php _e( 'Shipping Service', 'woocommerce-canadapost-webservice' )?></div><div class="canadapost-tracking-col"<?php echo $inline?>><?php _e( 'Shipment', 'woocommerce-canadapost-webservice' ) ?> / <?php _e( 'Delivery', 'woocommerce-canadapost-webservice' ) ?></div>
			     <br style="clear:both" />
			</div>
			<?php endif; ?>
			<?php foreach ($trackingData as $trackingRow) {
				if (count($trackingRow) > 0){
					foreach($trackingRow as $track){ ?>
						<div class="canadapost-tracking-row cpwebservice_track_<?php echo esc_attr($track['pin']) ?>"<?php echo $inline_row?>>
						<?php if ($display_buttons): ?>
							<div class="canadapost-tracking-col canadapost-tracking-col-sm"<?php echo $inline?>>
							<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_update_order_tracking&refresh_row=1&order_id=' . $post_id.'&trackingno='.esc_attr($track['pin']) ), 'cpwebservice_update_order_tracking' ) ?>" class="button canadapost-btn-icon cpwebservice_refresh" data-pin="<?php echo esc_attr($track['pin']) ?>" title="<?php _e('Update','woocommerce-canadapost-webservice')?>"><span class="dashicons dashicons-update"></span></a> 
							<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_update_order_tracking&remove_tracking=1&order_id=' . $post_id.'&trackingno='.esc_attr($track['pin']) ), 'cpwebservice_update_order_tracking' )?>" class="button canadapost-btn-icon cpwebservice_remove" data-pin="<?php echo esc_attr($track['pin'])?>" title="<?php _e('Remove','woocommerce-canadapost-webservice')?>"><span class="dashicons dashicons-no"></span></a></div>
						<?php endif; ?>
						<div class="canadapost-tracking-col shipping-trackingno"<?php echo $inline_pin?>>
						    <a href="<?php echo $this->tracking_url($track['pin'], $locale) ?>" target="_blank" class="canadapost-tracking-link">
						      <?php if ($this->options->tracking_icons) : ?><img src="<?php echo plugins_url( 'img/shipped.png' , dirname(__FILE__) )?>" width="16" height="16" border="0" style="vertical-align:middle" alt="Tracking" /><?php endif; ?>
						    <?php echo esc_html($track['pin']) ?></a>
						</div>
						<?php if (!empty($track['event-description']) && !empty($track['event-date-time'])): ?>
							<div class="canadapost-tracking-col shipping-eventinfo"<?php echo $inline?>>
							<?php echo esc_html($track['event-description']) ?>
							  <br /><?php echo esc_html($track['event-date-time']) . ' ' . esc_html($track['event-location']) ?></div>
							<div class="canadapost-tracking-col shipping-servicename"<?php echo $inline?>>
							<?php if ($this->options->tracking_icons) { ?><img src="<?php echo plugins_url( $this->get_resource('shipment_icon') , dirname(__FILE__) ) ?>"  style="vertical-align:middle" /><br /><?php } else { echo $this->get_resource('method_title'); } ?>
							<?php echo esc_html($track['service-name']) ?></div>
							<div class="canadapost-tracking-col shipping-delivered"<?php echo $inline?>>
							 <?php _e('Shipped', 'woocommerce-canadapost-webservice') ?>: <strong><?php echo esc_html($track['mailed-on-date'])?></strong>
							    <?php echo esc_html($track['origin-postal-id'])?><?php if (!empty($track['destination-postal-id'])) : ?> <?php _e('to', 'woocommerce-canadapost-webservice')?> <?php endif; ?><?php echo esc_html($track['destination-postal-id']) ?>
							     <?php if ($track['actual-delivery-date']) { ?> 
								  <br /><?php _e('Delivered','woocommerce-canadapost-webservice')?>: <strong><?php echo esc_html($track['actual-delivery-date'])?></strong>
							     <?php } else if ($track['expected-delivery-date']) { ?>
								 <br /><?php _e('Expected Delivery','woocommerce-canadapost-webservice')?>: <strong><?php echo esc_html($track['expected-delivery-date']) ?></strong>
								 <?php } // endif?>
								 <?php if (!empty($track['customer-ref-1'])): ?>
								 <br /><?php _e( 'Reference', 'woocommerce-canadapost-webservice' )?>: <strong><?php echo esc_html($track['customer-ref-1']) ?></strong>
								 <?php endif; ?>
							</div>
						<?php else: ?>
							<div class="canadapost-tracking-col-message"<?php echo $inline_message?>><p class="description"><?php _e( 'No Tracking Data Found', 'woocommerce-canadapost-webservice' )?></p></div>
						<?php endif; ?>
						<br style="clear:both" />
						</div>
						<?php 
					} // end foreach
				} // endif
			} // end foreach ?>
			</div>
		<?php 
		} // endif
		// Return display Html
		$html = ob_get_contents();
		ob_end_clean();
		
		return $html;
	}
	
	
	
	/**
	 * Load and generate the template output with ajax
	 */
	public function update_order_tracking() {
		// Let the backend only access the page
		if( !is_admin() ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
			
		// Check the user privileges
		if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
			
		// Check the action
		if( empty( $_GET['action'] ) || !check_admin_referer( $_GET['action'] ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
			
		// Check if all parameters are set
		if( empty( $_GET['trackingno'] ) || empty( $_GET['order_id'] ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		
		// Nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_update_order_tracking' ) )
			return;
			
		// Get tracking no, post_id
		$trackingnumber = sanitize_text_field( $_GET['trackingno'] );
		$post_id = intval( $_GET['order_id'] );
		
		// Remove spaces and dashes from tracking number
		$trackingnumber = preg_replace('/[\r\n\t \-]+/', '', $trackingnumber);
		
		// Current tracking pins:
		$trackingPins = get_post_meta($post_id, '_cpwebservice_tracking', true);
		
		// Do action: Refresh
		if( !empty( $_GET['refresh_row'] ) && !empty($trackingPins) ) {
			$t = $this->lookup_tracking($post_id, $trackingnumber, true); // force refresh.
			echo $this->display_tracking(array($t),$post_id, true, true);
			exit;
		}
		
		// Do action: Remove
		if( !empty( $_GET['remove_tracking'] ) && !empty($trackingPins) ) {
			$updatedPins = array_diff($trackingPins, array($trackingnumber));			

			// Remove data (if any)
			$this->delete_tracking_meta($post_id, $trackingnumber);
			
			// Remove Pin
			if (!empty($updatedPins)){
				update_post_meta($post_id, '_cpwebservice_tracking' , $updatedPins );
			} else {
				delete_post_meta($post_id, '_cpwebservice_tracking' );
			}
			echo 'Removed.';
			exit;
		}
		
		// Do action: Add
		if (empty($trackingPins) || !in_array($trackingnumber, $trackingPins)){ // ensures pin isn't added twice.
		
			$addmode = empty($trackingPins);			

			if (!is_array($trackingPins))
				$trackingPins = array();
			
			$trackingPins[] = $trackingnumber;
			
			// Save Tracking Pins.
			if ($addmode){
				add_post_meta($post_id, '_cpwebservice_tracking' , $trackingPins, true);
			} else {
				update_post_meta($post_id, '_cpwebservice_tracking' , $trackingPins );
			}
			
			// Lookup & display tracking
			$t = $this->lookup_tracking($post_id, $trackingnumber);
			echo $this->display_tracking(array($t),$post_id, (count($trackingPins)!=1), true);
			
			exit;
		}
		
		echo __('Duplicate Pin.', 'woocommerce-canadapost-webservice');
		
		exit;
	}
	
	/*
	 * Lookup Tracking from Api
	 */
	abstract public function lookup_tracking($post_id, $trackingPin, $refresh=false);
	
	
	/*
	 * Gets Tracking Meta data dbcache
	 * //New: get_post_meta( $post_id,'cpwebservice_tracking_meta', false);
	 */
	public function get_tracking_meta($post_id, $trackingPin){
	    // Get metadata
	   //return  get_post_meta( $post_id,'cpwebservice_tracking_'.$trackingPin, true);
	   $trackingArray = get_post_meta( $post_id,'_cpwebservice_tracking_meta', true);
       return (!empty($trackingArray) && !empty($trackingArray[$trackingPin])) ? $trackingArray[$trackingPin] : null;
	}
	
	public function delete_tracking_meta($post_id, $trackingPin){
	    // Remove data (if any)
	    //delete_post_meta($post_id, 'cpwebservice_tracking_'.$trackingPin );
	    $trackingArray = get_post_meta( $post_id,'_cpwebservice_tracking_meta', true);
	    if (!empty($trackingArray) && isset($trackingArray[$trackingPin])){ unset($trackingArray[$trackingPin]); }
	    if (!empty($trackingArray) && count($trackingArray) > 0){
	        update_post_meta($post_id, '_cpwebservice_tracking_meta', $trackingArray );
	    } else {
	        delete_post_meta($post_id, '_cpwebservice_tracking_meta');
	    }
	}
	
	public function save_tracking_meta($post_id, $trackingPin, $trackingData){
	    // Save meta data.
	    //update_post_meta($post_id, 'cpwebservice_tracking_'.$trackingPin, $trackingData );
	    $trackingArray = get_post_meta( $post_id,'_cpwebservice_tracking_meta', true);
	    if (empty($trackingArray) && !is_array($trackingArray)){ $trackingArray = array(); }
	    $trackingArray[$trackingPin] = $trackingData;
	    update_post_meta($post_id, '_cpwebservice_tracking_meta', $trackingArray );
	}
	/*
	 * End Tracking Meta
	 */
	
	/*
	 * Format Time.
	 */
	public function format_cp_time($datetime){
		// format: 20130703:175923
		if (strlen($datetime)>13){
			$d = substr($datetime,0,4).'-'.substr($datetime,4,2).'-'.substr($datetime,6,2);
			$d .=  ' ' .substr($datetime,9,2).':'.substr($datetime,11,2);
			return $d; //date("m/d/Y",strtotime($d));
		}		
		return $datetime;
	}
	

	// This function runs on a regular basis to update recent orders that have tracking attached.
	// It will send an email if configured.
	public function scheduled_update_tracked_orders() {

		global $woocommerce;
		$orders = '';
		$order_email_queue = array();
		add_filter('posts_where', array( &$this,  'tracked_orders_where_dates') );
		$orders = get_posts( array(
				'numberposts' => 50,
				'offset' => 0,
				'orderby' => 'post_date',
				'order' => 'DESC',
				'post_type' => 'shop_order',
				'meta_key' => '_cpwebservice_tracking',
				'tax_query' => array(
	                array(
	                    'taxonomy' => 'shop_order_status',
	                    'field' => 'slug',
	                    'terms' => array('pending','processing','completed')
	                )
	            )
		) );
		remove_filter('posts_where', array( &$this,  'tracked_orders_where_dates'));
		
		if (!empty($orders)) {

		    foreach( $orders as $order ) {  setup_postdata($order);
				// Check for tracking numbers.
				$trackingPins = get_post_meta($order->ID, '_cpwebservice_tracking', true);
				// Check for last update.
				$trackingUpdates = array();
				
				if (!empty($trackingPins) && is_array($trackingPins)){
						
					foreach($trackingPins as $pin){
						
						$trackingData = $this->get_tracking_meta($order->ID, $pin);
						
						// If data is older than 1 day but less than 30 days, do update.
						if (!empty($trackingData) && is_array($trackingData) && isset($trackingData[0]['update-date-time'])){
							$update = intval($trackingData[0]['update-date-time']);
							if ($update > 0){
								$diff = time() - $update;
								if ($diff > 86400 && $diff < 86400 * 30 ){ // More then 1 day but less than 30 days in seconds
									
									// DO TRACKING UPDATE.
									// Update Tracking
									$trackingUpdated = $this->lookup_tracking($order->ID, $pin, true);
									// Compare to current data
									if (!empty($trackingUpdated) && is_array($trackingUpdated) && isset($trackingUpdated[0]['update-date-time'])){
													
										// Compare 'mailed-on-date', if it is now a value, then an email notification should go out.
										if ((empty($trackingData[0]['mailed-on-date']) && !empty($trackingUpdated[0]['mailed-on-date'])) 
											|| (isset($trackingData[0]['mailed-on-date']) && !empty($trackingUpdated[0]['mailed-on-date']) && $trackingUpdated[0]['mailed-on-date'] != $trackingData[0]['mailed-on-date'])) {
											// Send out email notification for this order.
											if (!in_array($order->ID,$order_email_queue)) { $order_email_queue[] = 	$order->ID; }

										}
										
										// Compare 'actual-delivery-date', if it is now a value (and was not before), then an email notification should go out.
										elseif ((empty($trackingData[0]['actual-delivery-date']) && !empty($trackingUpdated[0]['actual-delivery-date']))
												|| (isset($trackingData[0]['actual-delivery-date']) && !empty($trackingUpdated[0]['actual-delivery-date']) && $trackingUpdated[0]['actual-delivery-date'] != $trackingData[0]['actual-delivery-date'])) {
											// Send out email notification for this order.
											if (!in_array($order->ID,$order_email_queue)) { $order_email_queue[] = 	$order->ID; }
										
										}

									}

								} // end if within specified update time.
							}
						}
						
					}
					
				}
						
			} // endforeach
			
			// Loop through $order_email_queue and send out notification emails.  Will be 'resending' invoice email.
			$invoice = null;
			$mailer = $woocommerce->mailer();
			$mails = $mailer->get_emails();
			if ( ! empty( $mails ) ) {
				foreach ( $mails as $mail ) {
					if ( $mail->id == 'customer_invoice' ) {
						$invoice = $mail;
					}
				}
			} 
			
			if ($invoice) {
			
				foreach($order_email_queue as $order_id_email) {
					try {
						$invoice->trigger( $order_id_email );
					}
					 catch (Exception $ex){
						// email unable to send.
					}
					
				}
			}

		}

	}
	
	// Only update tracking on order updated in the last 30 days.
	public function tracked_orders_where_dates( $where ){
		global $wpdb;
	
		$where .= $wpdb->prepare(" AND post_date >= '%s' ", date("Y-m-d",time() - 30 * 24 * 60 * 60));
	
		return $where;
	}
	
}