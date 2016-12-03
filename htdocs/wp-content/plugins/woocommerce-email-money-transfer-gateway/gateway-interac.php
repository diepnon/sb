<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Plugin Name: WooCommerce 'Email Money Transfer' Gateway
 * Plugin URI: http://blazingspider.com/plugins/woocommerce-email-money-transfer
 * Description: Many customers prefer to pay by Email Money Transfer, like Interac e-Transfer. This plugin provides a unique and secret question & answer for them.
 * Version: 1.0.4
 * Author: Massoud Shakeri, BlazingSpider
 * Author URI: http://www.blazingspider.com/
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * @class 		WC_Gateway_emt
 * @extends		WC_Payment_Gateway
 * @since 3.9.0
 */

add_action( 'plugins_loaded', 'woocommerce_eTransfer_init', 0 );

function woocommerce_eTransfer_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    	return;
 	};
	// include this Gateway Class
	include_once( 'gateway-interac.php' );

	DEFINE ('PLUGIN_DIR', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/' );

	/**
	 * Email Money Transfer Gateway Class
	 */
	class WC_Gateway_emt extends WC_Payment_Gateway {

		function __construct() {

	        // Register plugin information
		    $this->id		  = 'emt';
	        // Load plugin checkout icon
		    $this->icon = PLUGIN_DIR . 'images/e-transfer.jpg';
			$this->method_title       = __( 'Email Money Transfer', 'woocommerce' );
			$this->method_description = __( 'Have your customers pay thru Interac (or any other Email Transfer means).', 'woocommerce' );
		    $this->has_fields = true;
/*		    $this->supports   = array(
          		'products', 
          		'subscriptions',
          		'subscription_cancellation', 
          		'subscription_suspension', 
          		'subscription_reactivation',
          		'subscription_amount_changes',
          		'subscription_date_changes',
          		'subscription_payment_method_change',
          		'refunds'
               );
*/
        // Create plugin fields and settings
				$this->init_form_fields();
				$this->init_settings();

				// Get settings
				$this->title              = $this->get_option( 'title' );
				$this->description        = $this->get_option( 'description' );
				$this->instructions       = $this->get_option( 'instructions', $this->description );
				$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
				$this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes' ? true : false;

        // Add hooks
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ) );
		    	// Customer Emails
		    	add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

				// Get setting values
				foreach ( $this->settings as $key => $val ) $this->$key = $val;



		}

      /**
       * Initialize Gateway Settings Form Fields.
       */
	    function init_form_fields() {
	    	$shipping_methods = array();

	    	if ( is_admin() )
		    	foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
			    	$shipping_methods[ $method->id ] = $method->get_title();
		    	}

	    	$this->form_fields = array(
				'enabled' => array(
					'title'       => __( 'Enable EMT', 'woocommerce' ),
					'label'       => __( 'Enable Email Money Transfer', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Email Money Transfer', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
					'default'     => __( 'After placing your order, please send an Email money transfer to us (thru Interac or any other Email Transfer means).', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'MAKE SURE YOU KEEP {1} and {2} PARAMETERS, and provide a legitimate Email address', 'woocommerce' ),
					'default'     => __( 'After placing your order, please send an Email money transfer to the following:<br />Email: xxx@yyy.com<br />Secret Question: Your Order Number {1}<br />Secret Answer: {2} (MAKE SURE YOU DO NOT REMOVE THESE TWO PARAMETERS)<br />Thanks for choosing us! We appreciate your business.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'enable_for_methods' => array(
					'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
					'type'              => 'multiselect',
					'class'             => 'wc-enhanced-select',
					'css'               => 'width: 450px;',
					'default'           => '',
					'description'       => __( 'If EMAIL is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
					'options'           => $shipping_methods,
					'desc_tip'          => true,
					'custom_attributes' => array(
						'data-placeholder' => __( 'Select shipping methods', 'woocommerce' )
					)
				),
				'enable_for_virtual' => array(
					'title'             => __( 'Enable for virtual orders', 'woocommerce' ),
					'label'             => __( 'Enable EMAIL if the order is virtual', 'woocommerce' ),
					'type'              => 'checkbox',
					'default'           => 'yes'
				)
	 	   	);
	    }


	    /**
	     * Generate a string of 36 alphanumeric characters to associate with each saved billing method.
	     */
	    function random_key() {

	      $valid_chars = array( 'a','b','c','d','e','f','g','h','i','j','k','m','n','p','q','r','s','t','u','v','w','x','y','z','2','3','4','5','6','7','8','9' );
	      $key = '';
	      for( $i = 0; $i < 6; $i ++ ) {
	        $key .= $valid_chars[ mt_rand( 0, 31 ) ];
	      }
	      return $key;

	    }

		/**
		 * Check If The Gateway Is Available For Use
		 *
		 * @return bool
		 */
		public function is_available() {
			$order = null;

			if ( ! $this->enable_for_virtual ) {
				if ( WC()->cart && ! WC()->cart->needs_shipping() ) {
					return false;
				}

				if ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
					$order_id = absint( get_query_var( 'order-pay' ) );
					$order    = wc_get_order( $order_id );

					// Test if order needs shipping.
					$needs_shipping = false;

					if ( 0 < sizeof( $order->get_items() ) ) {
						foreach ( $order->get_items() as $item ) {
							$_product = $order->get_product_from_item( $item );

							if ( $_product->needs_shipping() ) {
								$needs_shipping = true;
								break;
							}
						}
					}

					$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

					if ( $needs_shipping ) {
						return false;
					}
				}
			}

			if ( ! empty( $this->enable_for_methods ) ) {
				// -------- Updated in ver. 1.0.1:
				// Apparently, in presence of other plugins, this plugin was called before woocommerce was initiated.
				// So I just added a few lines to check if WC()->session exists.
				if ( !is_object( WC() ) ) {
					return false;
				}
				if ( !is_object( WC()->session)) {
					return false;
				}
				//-------- end of update

				// Only apply if all packages are being shipped via local pickup
				$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

				if ( isset( $chosen_shipping_methods_session ) ) {
					$chosen_shipping_methods = array_unique( $chosen_shipping_methods_session );
				} else {
					$chosen_shipping_methods = array();
				}

				$check_method = false;

				if ( is_object( $order ) ) {
					if ( $order->shipping_method ) {
						$check_method = $order->shipping_method;
					}

				} elseif ( empty( $chosen_shipping_methods ) || sizeof( $chosen_shipping_methods ) > 1 ) {
					$check_method = false;
				} elseif ( sizeof( $chosen_shipping_methods ) == 1 ) {
					$check_method = $chosen_shipping_methods[0];
				}

				if ( ! $check_method ) {
					return false;
				}

				$found = false;

				foreach ( $this->enable_for_methods as $method_id ) {
					if ( strpos( $check_method, $method_id ) === 0 ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					return false;
				}
			}

			return parent::is_available();
		}


	    /**
	     * Process the payment and return the result
	     *
	     * @param int $order_id
	     * @return array
	     */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			// Add secret question as an order note
			$rndKey = $this->random_key();
			$order->add_order_note("Answer to the Secret Question: $rndKey");

			// Mark as On-Hold (payment won't be taken until delivery)
			$order->update_status( 'on-hold' );

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			WC()->cart->empty_cart();

			// Put order number & secret answer in the instructions

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order )
			);
		}

	    /**
	     * Output for the order received page.
	     */
		public function thankyou_page($order_id) {
			// -------- Updated in ver. 1.0.2:
			// to show instructions only if this payment method is selected.
			$order = wc_get_order( $order_id );
			if ( $this->instructions && 'emt' === $order->payment_method ) {
	        	echo wpautop( wptexturize( $this->get_instructions( $order ) ) );
			}
		}

		/**
		 * It retrieves tthe Answer to the secret question from order note
		 * @param  WC_Order $order
		 * @return string   $instructions
		 */
		public function get_instructions($order) {
			$args = array(
				'post_id' => $order->id,
				'type' => 'order_note',
				'status' => 'all',
			);
			$rndKey = "{2}";
	    	remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
			$comments = get_comments( $args );
	    	foreach ( $comments as $comment ) {
				$pos = strpos($comment->comment_content, "Answer to the Secret Question");
				if ($pos !== false) {
					$rndKey = substr($comment->comment_content, -6);
					break;
				}
	    	}
			$instructions = str_replace("{1}", $order->id, $this->instructions);
			$instructions = str_replace("{2}", $rndKey, $instructions);
			return $instructions;
		}
	    /**
	     * Add content to the WC emails.
	     *
	     * @access public
	     * @param WC_Order $order
	     * @param bool $sent_to_admin
	     * @param bool $plain_text
	     */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && 'emt' === $order->payment_method ) {
				echo wpautop( wptexturize( $this->get_instructions( $order ) ) ) . PHP_EOL;
			}
		}

	}

/*
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && 'emt' === $order->payment_method ) {
				$rndKey = "{2}";




				$args = array(
					'post_id' => $order->id,
					'type' => 'order_note',
					'status' => 'all',
				);

		    	remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );

		    	$comments = get_comments( $args );
		    	$instructions = $this->instructions;
		    	foreach ( $comments as $comment ) {
					$instructions .= $comment->comment_content;
		    	}
		
		    	add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
				$instructions = str_replace("{1}", $order->id, $instructions);
				$instructions = str_replace("{2}", $rndKey, $instructions);
				echo wpautop( wptexturize( $instructions ) ) . PHP_EOL;
			}
		}

	}
*/




	/**
	 * Add the gateway to woocommerce
	 */
	function add_email_money_transfer_gateway( $methods ) {
		$methods[] = 'WC_Gateway_emt';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_email_money_transfer_gateway' );

}
