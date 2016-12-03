<?php
/*
 Order Details class
cpwebservice_orderdetails.php

Copyright (c) 2013-2016 Jamez Picard

*/
abstract class cpwebservice_orderdetails
{
	
	public $method_id = 'woocommerce_cpwebservice';
    
	private $shipment_address = null;
	
	/**
	 * __construct function.
	 *
	 * @access public
	 * @return woocommerce_cpwebservice_orderdetails
	 */
	function __construct() {

		$this->init();
	}
	
	/*
	 * Init
	 */ 
	function init() {
	    $default_options = array('enabled'=>'no','shipments_enabled'=>true, 'geolocate_origin'=>true, 'geolocate_limit'=>false, 'display_units'=>'cm', 'display_weights'=>'kg');
		$this->options = get_option('woocommerce_cpwebservice', $default_options);
		$this->options =	(object) array_merge((array) $default_options, (array) $this->options); // ensure all keys exist, as defined in default_options.
		if ($this->get_resource('shipments_implemented')===false) { $this->options->shipments_enabled = false; }
		if ($this->options->enabled){
			// Wire up actions
			if (is_admin()){
			    $this->shipment_address = (array)get_option('woocommerce_cpwebservice_shipment_address', array());
				add_action( 'add_meta_boxes', array(&$this, 'add_shipping_details_box') );
				wp_enqueue_script( 'cpwebservice_admin_orders' ,plugins_url( 'framework/lib/admin-orders.js' , dirname(__FILE__) ) , array( 'jquery' ) );
				wp_localize_script( 'cpwebservice_admin_orders', 'cpwebservice_admin_orders', array( 'confirm'=>__('Are you sure you wish to delete?', 'woocommerce-canadapost-webservice') ) );
				wp_enqueue_script( 'cpwebservice_modal' ,plugins_url( 'framework/lib/modal.js' , dirname(__FILE__) ) , array( 'jquery' ) );
				wp_localize_script( 'cpwebservice_modal', 'cpwebservice_order_actions', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'postNonce' => wp_create_nonce( 'cpwebservice_order_actions' ), 'removeNonce' => wp_create_nonce( 'cpwebservice_shipment_remove' ), 'confirm' => __('Are you sure you wish to remove this draft Shipment Package?', 'woocommerce-canadapost-webservice')) );
				add_action( 'wp_ajax_cpwebservice_order_actions' , array(&$this, 'order_actions_ajax')  );
				// Display Units (only in/lb and cm/kg supported).
				$dimension_unit                = get_option( 'woocommerce_dimension_unit' );
				$this->options->display_units  = $dimension_unit == 'in' ? 'in' : 'cm';
				$weight_unit                   = get_option( 'woocommerce_weight_unit' );
				$this->options->display_weights= $weight_unit == 'lbs' ? 'lbs' : 'kg';
			} else {
				add_action( 'cpwebservice_order_shipping_info', array(&$this, 'order_shipping_info'), 10, 1 );
				add_action( 'woocommerce_cart_emptied', array(&$this, 'order_shipping_info_reset'));
				// The following 2 are not used as these are wired up earlier.
				//add_action( 'woocommerce_order_add_shipping', array(&$this, 'order_add_shipping'), 10, 3 );
				//add_action( 'woocommerce_checkout_order_processed', array(&$this, 'order_processed') , 10, 2);
			}
		}
	}
	
	/*
	 * Return resources
	 */
	abstract function get_resource($id);
	
	/* 
	 * Shipping rate data saved in session
	 */
	function order_shipping_info($shipping_info = array()){
		// Keep $packages in session.
		WC()->session->set( 'cpwebservice_shipping_info', $shipping_info );
	}
	
	/*
	 Hooks: //do_action( 'woocommerce_order_add_shipping', $this->id, $item_id, $shipping_rate );
	 */
	function order_add_shipping($id, $item_id, $shipping_rate) {
		
		// Only process data if the order is placed with this shipping method.
		if ($shipping_rate->method_id == $this->method_id){
			// Retrieve from session
			$shipping_info = WC()->session->get( 'cpwebservice_shipping_info' );
			if (!empty($shipping_info) && is_array($shipping_info)){
				// Add selected shipping.
				if (isset($shipping_info['rates']) && is_array($shipping_info['rates'])) {
					foreach($shipping_info['rates'] as $rate){
						if (preg_match('/'.$this->method_id.':([0-9]*\:?)'.$rate->service_code.'/i', $shipping_rate->id)) { //($this->method_id.':'.$rate->service_code == $shipping_rate->id)
							$shipping_rate->guaranteed = $rate->guaranteed;
							$shipping_rate->expected_delivery = $rate->expected_delivery;
							$shipping_rate->expected_mailing_date = $rate->expected_mailing_date;
							$shipping_rate->service_code = $rate->service_code;
						}
					}
				}
				// Replace rates with rate used
				$shipping_info['rates'] = $shipping_rate;
				$shipping_info['item_id'] = $item_id;
				// Add address info (if using geo-location)
				if (!empty($shipping_info['origin']) && $this->options->geolocate_origin){
				    $this->shipment_address = (array)get_option('woocommerce_cpwebservice_shipment_address', array());
				    if (count($this->shipment_address) > 1){
    				    $sender_address_index = 0;
    				    // find address index.
    				    for($i=0;$i<count($this->shipment_address); $i++){
    				        if ($this->shipment_address[$i]['postalcode'] == $shipping_info['origin']) { $sender_address_index = $i;  }
    				    }
    				    $shipping_info['sender_address_index'] = $sender_address_index;
    				    $shipping_info['sender_address'] = $this->shipment_address[$sender_address_index];
				    }
				}
				// Save with order.
				update_post_meta($id, '_cpwebservice_shipping_info', $shipping_info);
			}
		}
	}
	
	
	/*
	 * Shipping rate data saved in session
	*/
	function order_shipping_info_reset(){
		if (WC()->session->cpwebservice_shipping_info!=null){
			unset( WC()->session->cpwebservice_shipping_info );
		}
	}
	
	public function add_shipping_details_box() {
		global $post_id;
		$shipping_info = get_post_meta( $post_id, '_cpwebservice_shipping_info', true);
		if ($this->options->shipments_enabled || !empty($shipping_info)){
		  add_meta_box( 'cpwebservice_shipping_details', __( 'Order Shipping Details', 'woocommerce-canadapost-webservice' ),  array(&$this,'display_shipping_view'), 'shop_order', 'normal', 'default' );
		}
	}
	
	public function display_shipping_view(){
		global $post_id;
		?>
		<div><img src="<?php echo plugins_url( $this->get_resource('method_logo_url') , dirname(__FILE__) ); ?>" /></div>
    <div id="cpwebservice_shipping_info" data-orderid="<?php echo $post_id; ?>">
		<?php 
		// Shipping information. array('rates'=>array(),'packages'=>array())
		$shipping_info = get_post_meta( $post_id, '_cpwebservice_shipping_info', true);
		?>
		<div id="cpwebservice_order_actions"><?php 
        if (!empty($shipping_info)) {
	     
            if (!empty($shipping_info) && isset($shipping_info['rates']) && is_object($shipping_info['rates'])) {
				// Get selected rate.
				?><p><strong><?php echo esc_html($this->get_resource('method_title')); ?> <?php echo !empty($shipping_info['rates']->label) ? $shipping_info['rates']->label : '' ?></strong> 
				   <?php echo !empty($shipping_info['rates']->expected_delivery) ? '<br />'.( !empty($shipping_info['rates']->guaranteed) ? __('Guaranteed', 'woocommerce-canadapost-webservice') . ' ' : '') .__('Delivered by', 'woocommerce-canadapost-webservice'). ' ' .$shipping_info['rates']->expected_delivery : ''  ?>
					<?php echo (!empty($shipping_info['rates']->expected_delivery) && !empty($shipping_info['rates']->expected_mailing_date)) ? __('if mailed by').': ' . $shipping_info['rates']->expected_mailing_date : '' ?> 
                  </p>
		<?php 
			}
			if (!empty($shipping_info['sender_address'])){
			    ?><p><?php _e('Send From (Origin Address)', 'woocommerce-canadapost-webservice')?>: <br />
			   <strong><?php echo esc_html($shipping_info['sender_address']['contact'])?></strong><?php if (!empty($shipping_info['sender_address']['phone'])) { ?><br /><?php } ?>
				    <?php echo esc_html($shipping_info['sender_address']['phone'])?><br />
					<?php echo esc_html($shipping_info['sender_address']['address'])?><?php if (!empty($shipping_info['sender_address']['address2'])) { ?><br /><?php } ?>
					<?php echo esc_html($shipping_info['sender_address']['address2'])?><br />
					<?php echo esc_html($shipping_info['sender_address']['city'])?>, <?php echo esc_html($shipping_info['sender_address']['prov'])?> <?php echo esc_html($shipping_info['sender_address']['postalcode'])?><br />
					<?php echo esc_html($shipping_info['sender_address']['country'])?>
			    </p>
			    <?php 
			}
			if (!empty($shipping_info) && isset($shipping_info['packages']) && is_array($shipping_info['packages'])) {
				
			    $this->display_shipment_rows($shipping_info, $post_id);
				 
			} // end if
				
			
		} else {
			echo '<p>' . __('No shipment information saved with order', 'woocommerce-canadapost-webservice') . '.</p>'; 
		}
			?>
		</div>
	<?php if ($this->options->shipments_enabled) : ?>
    <div style="margin:6px 0;">
    <?php $next_index = !empty($shipping_info['packages']) ? count($shipping_info['packages']) : 0; ?>
    <a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $post_id . '&package_index=' . $next_index ), 'cpwebservice_create_shipment' ); ?>" class="button <?php if ($next_index == 0): ?>button-primary<?php endif;?> cpwebservice_iframe_modal_btn cpwebservice_createnew_btn" target="_blank">
    <?php _e('Create New Shipment', 'woocommerce-canadapost-webservice'); ?></a>
    <a href="#" onclick="return cpwebservice_order_refresh();" class="button button-secondary"><span class="dashicons dashicons-update" style="margin-top: 5px;"></span> Refresh</a>
    </div>						
    <?php endif; ?>
</div>
<?php 
	}
	
	public function display_shipment_rows($shipping_info, $post_id) {
	    ?>
	    <h4> <?php _e('Packages', 'woocommerce-canadapost-webservice') ?> (<?php echo count($shipping_info['packages']) ?>)
	    					<?php _e('to be shipped by', 'woocommerce-canadapost-webservice') ?> <?php echo esc_html($this->get_resource('method_title')) ?></h4>
	    					
	    	<table class="widefat">
	    	<thead>
	    		<tr>
	    			<?php if ($this->options->shipments_enabled) : ?><th width="10%"></th><?php endif; ?>
	    			<th><?php _e('Package', 'woocommerce-canadapost-webservice'); ?> <?php _e('Dimensions', 'woocommerce-canadapost-webservice'); ?>, <?php _e('Shipping Weight', 'woocommerce-canadapost-webservice'); ?>, <?php _e('Volume/Cubic', 'woocommerce-canadapost-webservice'); ?></th>
	    			<th><?php _e('Service', 'woocommerce-canadapost-webservice'); ?></th>
	    			<th><?php _e('Products Packed', 'woocommerce-canadapost-webservice'); ?></th>
	    		</tr>
	    </thead>
	    <tbody>
	    <?php 
	    foreach($shipping_info['packages'] as $index=>$packages ){ ?>
	    			<?php if (isset($packages['length']) && isset($packages['width']) && isset($packages['height']) && isset($packages['weight'])){ ?>
	    			<tr>
	    			<?php if ($this->options->shipments_enabled) : ?>
	    			<td class="cpwebservice_order_actions" nowrap="nowrap">
	    			<?php if (isset($shipping_info['shipment']) && is_array($shipping_info['shipment']) && !empty($shipping_info['shipment'][$index]) && !empty($shipping_info['shipment'][$index]['label'])) { ?>
	    			<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $post_id . '&package_index=' . $index ), 'cpwebservice_create_shipment' ); ?>" class="button button-primary button-canadapost-print cpwebservice_iframe_modal_btn" target="_blank">
	    				<?php _e('Shipment Label', 'woocommerce-canadapost-webservice'); ?></a>
	    			<?php } else { ?>
	    			<a href="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $post_id . '&package_index=' . $index ), 'cpwebservice_create_shipment' ); ?>" class="button button-primary cpwebservice_iframe_modal_btn" target="_blank"><?php _e('Create Shipment', 'woocommerce-canadapost-webservice'); ?></a>
	    			<a href="<?php echo admin_url( 'admin-ajax.php?action=cpwebservice_shipment_remove&order_id=' . $post_id . '&package_index=' . $index ); ?>" class="button canadapost-btn-icon cpwebservice_shipment_remove" target="_blank" title="<?php _e('Remove', 'woocommerce-canadapost-webservice'); ?>"><span class="dashicons dashicons-no"></span></a>
	    			<?php } // endif ?>
	    		    </td>
	    		    <?php endif; ?>
	    			<td><?php echo esc_html(cpwebservice_resources::display_unit($packages['length'], $this->options->display_units) .' x '. cpwebservice_resources::display_unit($packages['width'], $this->options->display_units) .' x ' . cpwebservice_resources::display_unit($packages['height'], $this->options->display_units) . ' ' . $this->options->display_units )?> 
	    				<?php echo isset($packages['box_name']) ? ' (' . __('Box Name', 'woocommerce-canadapost-webservice').': '.esc_html($packages['box_name']).')' : ''?>
	    				<?php echo (isset($packages['prepacked']) && $packages['prepacked']=='yes') ? ' ('.__('Prepackaged', 'woocommerce-canadapost-webservice').')' : ''?> 
	    			<br /><?php echo isset($packages['cubic']) ? esc_html(cpwebservice_resources::display_unit_cubed($packages['cubic'], $this->options->display_units)) . ' '.$this->options->display_units.'<sup>3</sup>' : ''?>
	    			<br /><?php echo esc_html(cpwebservice_resources::display_weight($packages['weight'], $this->options->display_weights) . ' ' . $this->options->display_weights)?> <?php echo isset($packages['actual_weight']) ? esc_html('('.__('Actual', 'woocommerce-canadapost-webservice') .': '.cpwebservice_resources::display_weight($packages['actual_weight'], $this->options->display_weights) . ' '.$this->options->display_weights.')') : '' ?> 
	    			</td>
	    			<td><?php
	    			$selected_rate = null;
	    			$selected_method_name = null;
	    			if (isset($shipping_info['rates']) && is_object($shipping_info['rates'])) {
	    				$selected_rate = !empty($shipping_info['rates']->service_code) ? $shipping_info['rates']->service_code : '';
	    			}
	    			// use shipment if set.
	    			if (isset($shipping_info['shipment']) && isset($shipping_info['shipment'][$index]) && !empty($shipping_info['shipment'][$index]['method_id'])){
	    			    $selected_rate = $shipping_info['shipment'][$index]['method_id'];
	    			    $selected_method_name = isset($shipping_info['shipment'][$index]['method_name']) ? $shipping_info['shipment'][$index]['method_name'] : '';
	    			}
	    			if (!empty($selected_rate) && !empty($packages['rate']) && is_array($packages['rate'])) {
	    						foreach($packages['rate'] as $itemrate){
	    							if ($itemrate->service_code == $selected_rate){
	    							?>
	    							<p> <strong><?php echo esc_html($this->get_resource('method_title')); ?> <?php echo !empty($itemrate->service) ? $itemrate->service : '' ?></strong> 
	    							<br /><?php _e('My Cost', 'woocommerce-canadapost-webservice') ?>: $<?php echo !empty($itemrate->price) ? number_format(floatval($itemrate->price),2,'.','') : '' ?>
	    								<?php echo !empty($itemrate->expected_delivery) ? '<br />'.( !empty($itemrate->guaranteed) ? __('Guaranteed', 'woocommerce-canadapost-webservice') . ' ' : '') .__('Delivered by', 'woocommerce-canadapost-webservice'). ' ' .$itemrate->expected_delivery : ''  ?>
	    							</p>
	    							<?php
	    							}
	    						}// endforeach
	    
	    				} // endif
	    				else if (!empty($selected_rate) && !empty($selected_method_name)){ ?>
	    			     <p><strong><?php echo esc_html($this->get_resource('method_title')); ?> <?php echo esc_html($selected_method_name); ?></strong> </p>	    
	    		    <?php }
	    			?></td>
	    			<td><?php if (!empty($packages['products']) && is_array($packages['products'])) { ?>
	    				<?php 
	    				$product_reference = $this->get_product_array($packages['products']);
	    				// Display
	    				$product_groups = $this->group_products($packages['products']);
	    				foreach($product_groups as $item){ 
	    					if (isset($item['item_id']) && isset($product_reference[$item['item_id']])){
	    					$p = $product_reference[$item['item_id']];
	    					?>
	    					&bull; <?php echo esc_html($item['count']); ?>x <?php $this->display_product_variation($p); ?> <a href="<?php echo $p->get_permalink(); ?>"><?php echo $p->get_title(); ?></a> <?php echo esc_html($p->get_sku()); ?> (<?php echo esc_html($p->get_dimensions()); ?> &nbsp; <?php echo esc_html($p->get_weight() . ' ' . get_option('woocommerce_weight_unit')); ?>) <br />
	    					<?php 
	    					}// endif
	    				}// end foreach
	    				?>
	    				<?php } // endif ?>
	    			</td>
	    			</tr>
	    	<?php } // endif ?>
	    <?php } // end foreach ?> 
	    </tbody>
        </table>
        <?php 
	}
	
	
	public function display_product_variation($product) {
	    if ($product->is_type('variation')){
	        $variation = $product->get_variation_attributes();
	        echo esc_html(implode(',', $variation));
	    }
	}
	
	public function order_actions_ajax() {
	    // Displays Order actions rows by ajax.
	    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cpwebservice_order_actions' ) )
	        return;
	    
	    if( !current_user_can( 'manage_woocommerce_orders' ) && !current_user_can( 'edit_shop_orders' ) ) {
	        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	    }
	    
	    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
	    
	    // Get shipping Info from order metadata.
	    $shipping_info = get_post_meta( $order_id, '_cpwebservice_shipping_info', true);
	    if (!empty($shipping_info)){
	        $this->display_shipment_rows($shipping_info, $order_id);
	    } else {
	        echo '<p>' . __('No information available', 'woocommerce-canadapost-webservice') . '</p>';
	    }
	    $next_index = (!empty($shipping_info) && !empty($shipping_info['packages']) && is_array($shipping_info['packages'])) ? count($shipping_info['packages']) : 0;
	    ?>
	    <div class="cpwebservice_createnew" data-url="<?php echo wp_nonce_url( admin_url( 'admin-ajax.php?action=cpwebservice_create_shipment&order_id=' . $order_id . '&package_index=' . $next_index ), 'cpwebservice_create_shipment' ); ?>"></div><?php
	    exit; // ajax return.
	}
	
	// Get item_ids into an easy-to-use array.
	public function get_product_ids($products){
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
	
	// Looks up products in $packages['products']
	public function get_product_array($products){
		// Begin Do Lookup
		$ids = $this->get_product_ids($products);
		$items = get_posts(array('post_type' => array('product','product_variation'), 'post__in' => $ids ));
		$product_reference = array();
		foreach($items as $item){ 
			$p = wc_get_product($item);
			$product_reference[$item->ID] = $p;
		} // endforeach
		wp_reset_postdata();
		// End Do Lookup.
		
		return $product_reference;
	}
	
	// Group (and count) Products by ID
	public function group_products($products){
		$group = array();
		foreach($products as $level){
			foreach($level as $p){
				if (isset($p['item_id'])){
					$id = $p['item_id'];
					if (isset($group[$id])) {
						$group[$id]['count'] += 1;
					} else {
						$group[$id] = $p;
						$group[$id]['count'] = 1;
					}
				}
			}
		}
		return $group;
	}

}