<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
/**
* @package PaytalkKopokopoWoocommerce
*/
/*
Plugin Name: KopoKopo Lipa Na Mpesa
Plugin URI: https://wordpress.org/plugins/paytalk-kopokopo-gateway/
Description: KopoKopo Lipa Na Mpesa is a woocommerce extension plugin that allows website owners to receive payment via Mpesa Paybill/Till Number. It uses KopoKopo APIs (K2-Connect) to process payments. The plugin has been developed by <a href='https://paytalk.co.ke' target='_blank'>paytalk.co.ke.</a>
Version: 3.0.7
Author: Paytalk.co.ke
Author URI: https://paytalk.co.ke
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Text Domain: woo-paytalk-kopokopo-gateway
WC requires at least: 4.0.0
WC tested up to: 9.5
*/

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || class_exists( 'WooCommerce' ) ) {

add_action( 'plugins_loaded', 'init_kopokopo_class' );
//add_action('woocommerce_checkout_init','disable_billing');

function init_kopokopo_class() {
    class WC_Paytalk_KopoKopo_Gateway extends WC_Payment_Gateway {
    	function __construct() {

			// Setup our default vars
			$this->id                 = 'kopokopo';
			$this->method_title       = __('PayTalk.co.ke(KopoKopo)', 'woocommerce');
			$this->method_description = __('Paytalk KopoKopo gateway works by adding form fields on the checkout page and then sending the details to Paytalk.co.ke for verification and processing. Get API keys from <a href="https://app.kopokopo.com" target="_blank">https://app.kopokopo.com</a>', 'woocommerce');
			$this->icon               = plugins_url( '/images/paytalk_160x68.png', __FILE__ );
			$this->has_fields         = true;
			$this->supports           = array( 'products' );
			
			$this->liveurl            = 'https://developer.paytalk.co.ke/api/';

			$this->init_form_fields();
			$this->init_settings();

			// Get setting values
			$this->title       = "Lipa Na Mpesa";
			$this->description = $this->settings['description'];
			$this->enabled     = $this->settings['enabled'];
			$this->stk_push    = $this->settings['stk_push'];
			$this->sms    	   = $this->settings['sms'];
			$this->paytill     = $this->settings['paytill'];
			$this->description = $this->settings['description'];
			$this->api_user    = $this->settings['api_user'];
			$this->trans_key   = $this->settings['trans_key'];
			
			$this->client_id   = $this->settings['client_id'];
			$this->client_secret	= $this->settings['client_secret'];
			$this->opa   			= $this->settings['opa'];

			// Hooks
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );

		}

		function init_form_fields() {

			$this->form_fields = array(
				/*'title' => array(
		            'title'       => __( 'Title', 'woocommerce' ),
		            'type'        => 'text',
		            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'woocommerce' ),
		            'default'     => __( 'Paytalk.co.ke', 'wc-gateway-offline' ),
		            'desc_tip'    => true,
		        	),*/

				'enabled' => array(
					'title'       => __( 'Enable/Disable', 'woocommerce' ),
					'label'       => __( 'Enable KopoKopo', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
					),

				'stk_push' => array(
					'title'       => __( 'Enable/Disable', 'woocommerce' ),
					'label'       => __( 'Enable KopoKopo K2 STK Push', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => __( 'Enable KopoKopo K2 STK Push', 'woocommerce' ),
					'default'     => 'no',
					'desc_tip'    => true
					),

				'sms' => array(
					'title'       => __( 'Enable/Disable SMS', 'woocommerce' ),
					'label'       => __( 'Enable SMS notification', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => __( 'Receive/Send SMS with your own sender ID. Contact info@paytalk.co.ke to setup your SMS account.', 'woocommerce' ),
					'default'     => 'no',
					//'desc_tip'    => true
					),

				'paytill' => array(
					'title'       => __( 'KopoKopo Paybill/Till Number', 'woocommerce' ),
					'type'        => 'text',
					'description' => '',
					'default'     => ''
					),

				'description' => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
					'default'     => 'Pay with Till Number/Paybill via PayTalk.',
					'desc_tip'    => true
					),
				
				'client_id' => array(
					'title'       => __( 'KopoKopo Client ID', 'woocommerce' ),
					'type'        => 'password',
					'description' => sprintf( __( 'Get your KopoKopo Client ID from KopoKopo <a href="%s" target="_blank">KopoKopo</a>', 'woocommerce' ), 'https://app.kopokopo.com/' ),
					'default'     => ''
					),

				'client_secret' => array(
					'title'       => __( 'KopoKopo Client Secret', 'woocommerce' ),
					'type'        => 'password',
					'description' => sprintf( __( 'Get your client secret from KopoKopo <a href="%s" target="_blank">KopoKopo</a>', 'woocommerce' ), 'https://app.kopokopo.com/' ),
					'default'     => '',
					'placeholder' => ''
					),

				'opa' => array(
					'title'       => __( 'Online Payment Account', 'woocommerce' ),
					'type'        => 'text',
					'description' => sprintf( __( 'Get your Online Payment Account from KopoKopo <a href="%s" target="_blank">KopoKopo</a>', 'woocommerce' ), 'https://app.kopokopo.com/' ),
					'default'     => '',
					'placeholder' => 'Online Payment Account starts with a K eg. K123456'
					),


			);

		}

		public function payment_fields() {
			if ($description = $this->get_description()) {
				echo wpautop(wptexturize($description));
			}
			$stk_push = ( $this->stk_push == "yes" ) ? 'TRUE' : 'FALSE';
			$sms = ( $this->sms == "yes" ) ? 'TRUE' : 'FALSE';
			if($stk_push == "FALSE"){
			?> 
						<div style="max-width:300px"> 
						<p class="form-row form-row form-row-wide woocommerce-validated" id="mpesa_phone_field" data-o_class="form-row form-row form-row-wide">
							<label for="mpesa_phone" class="">Phone Number <abbr class="required" title="required">*</abbr></label>
							<input type="text" class="input-text" name="mpesa_phone" id="mpesa_phone" placeholder="Phone Number" required />
						</p>
						<p class="form-row form-row form-row-wide woocommerce-validated" id="mpesa_code_field" data-o_class="form-row form-row form-row-wide">
							<label for="mpesa_code" class="">Transaction ID <abbr class="required" title="required">*</abbr></label>
							<input type="text" class="input-text" name="mpesa_code" id="mpesa_code" placeholder="Transaction ID" />
						</p>
						</div>
			<?php
				}else{
			?>


						<div style="max-width:300px"> 
						<p class="form-row form-row form-row-wide woocommerce-validated" id="mpesa_phone_field" data-o_class="form-row form-row form-row-wide">
							<label for="mpesa_phone" class="">Phone Number <abbr class="required" title="required">*</abbr></label>
							<input type="text" class="input-text" name="mpesa_phone" id="mpesa_phone" placeholder="Phone Number" required />
						</p>
						</div>


			<?php
				}
			}

		public function validate_fields() { 
			if($stk_push == "FALSE"){
				if ($_POST['mpesa_phone']) {
					$success = true;
				} else {					
					$error_message = __("The ", 'woothemes') . $this->field_title . __(" Phone Number is required", 'woothemes');
					wc_add_notice(__('Field error: ', 'woothemes') . $error_message, 'error');
					$success = False;
				}

				if ($_POST['mpesa_code']) {
					$success = true;
				} else {					
					$error_message = __("The ", 'woothemes') . $this->phone_title . __(" Transaction ID is required", 'woothemes');
					wc_add_notice(__('Field error: ', 'woothemes') . $error_message, 'error');
					$success = False;
				}
				return $success;
	
			}else{
				if ($_POST['mpesa_phone']) {
					$success = true;
				} else {					
					$error_message = __("The ", 'woothemes') . $this->field_title . __(" Phone Number is required", 'woothemes');
					wc_add_notice(__('Field error: ', 'woothemes') . $error_message, 'error');
					$success = False;
				}
			}
		}

			// Submit payment and handle response
	public function process_payment( $order_id ) {
		global $woocommerce;
		
		// Get this Order's information so that we know
		// who to charge and how much
		$customer_order = new WC_Order( $order_id );
		
		$trans_key = $this->trans_key;

		$environment_url = 'https://developer.paytalk.co.ke/api/kopokopo/';

		$mpesa_phone    = isset($_POST['mpesa_phone']) ? woocommerce_clean($_POST['mpesa_phone']) : '';
		$mpesa_code    = isset($_POST['mpesa_code']) ? woocommerce_clean($_POST['mpesa_code']) : '';

		//get items
		$items = $woocommerce->cart->get_cart();

        foreach($items as $item => $values) { 
            $_product =  wc_get_product( $values['data']->get_id()); 
            $order_items[] = "<b> ".$_product->get_title().'</b>  <br> Quantity: '.$values['quantity'].'<br> Price: '.get_post_meta($values['product_id'] , '_price', true);
        } 

		// This is where the fun stuff begins
		$payload = [
			// Paytalk.co.ke Credentials and API Info
			"trans_key"           	=> $trans_key,
			"api_user"              => $this->api_user,
			"paytill"              	=> $this->paytill,
			"x_version"            	=> "3.0.5",
			
			// Order total
			"x_amount"             	=> $customer_order->order_total,
			
			// Lipa Na Mpesa Information
			"mpesa_code"			=> $mpesa_code,
			"mpesa_phone"			=> $mpesa_phone,
			"stk_push"				=> $this->stk_push,
			"opa"					=> $this->opa,
			"klient_id"				=> $this->client_id,
			"klient_sec"			=> $this->client_secret,
			
			"x_type"               	=> 'AUTH_CAPTURE',
			"x_invoice_num"        	=> str_replace( "#", "", $customer_order->get_order_number() ),
			"x_delim_char"         	=> '|',
			"x_encap_char"         	=> '',
			"x_delim_data"         	=> "TRUE",
			"x_relay_response"     	=> "FALSE",
			"x_method"             	=> "CC",
			
			// Billing Information
			"x_first_name"         	=> $customer_order->billing_first_name,
			"x_last_name"          	=> $customer_order->billing_last_name,
			"x_address"            	=> $customer_order->billing_address_1,
			"x_city"              	=> $customer_order->billing_city,
			"x_state"              	=> $customer_order->billing_state,
			"x_zip"                	=> $customer_order->billing_postcode,
			"x_country"            	=> $customer_order->billing_country,
			"x_phone"              	=> $customer_order->billing_phone,
			"x_email"              	=> $customer_order->billing_email,
			"order_id"              => $customer_order->get_id(),
			"items" 				=> $order_items,
			
			// Shipping Information
			"x_ship_to_first_name" 	=> $customer_order->shipping_first_name,
			"x_ship_to_last_name"  	=> $customer_order->shipping_last_name,
			"x_ship_to_company"    	=> $customer_order->shipping_company,
			"x_ship_to_address"    	=> $customer_order->shipping_address_1,
			"x_ship_to_city"       	=> $customer_order->shipping_city,
			"x_ship_to_country"    	=> $customer_order->shipping_country,
			"x_ship_to_state"      	=> $customer_order->shipping_state,
			"x_ship_to_zip"        	=> $customer_order->shipping_postcode,
			
			// Some Customer Information
			"x_cust_id"            	=> $customer_order->user_id,
			"x_customer_ip"        	=> $_SERVER['REMOTE_ADDR'],

			//Get site URL
			"x_url"					=> home_url(),
			"x_sms"					=> $sms
			
		];
	
		// Send this payload to Paytalk.co.ke for processing

		$response = wp_remote_post( $environment_url, array(
			'method'    => 'POST',
			'body'      => http_build_query( $payload ),
			'timeout'   => 90,
			'sslverify' => true,
		) );


		//$file = ABSPATH . 'wp-content/plugins/woo-paytalk-lipa-na-mpesa/errors.txt'; 
		//file_put_contents($file, $response['body'], FILE_TEXT ); 

		if ( is_wp_error( $response ) ) 
			throw new Exception( __( 'We are currently experiencing problems trying to connect to PayTalk.co.ke. Sorry for the inconvenience.', 'woocommerce' ) );

		if ( empty( $response['body'] ) )
			throw new Exception( __( 'PayTalk\'s Response was empty.', 'woocommerce' ) );

		if (  $response['body'] == "no_account" )
			throw new Exception( __( 'Make sure you are using PayTalk\'s API Username and API Transaction Key. Paybill/Till Number field is also required. Go to <a href="https://developer.paytalk.co.ke" target="_blank">Paytalk.co.ke Developer</a> to copy these credentials.', 'woocommerce' ) );

		if ( $response['body'] == "used_trans" )
			throw new Exception( __( 'Sorry, the Transaction ID you are trying to use has already been used. Please check and try again.', 'woocommerce' ) );

		if ( is_numeric($response['body']) )
			throw new Exception( __( 'We have detected that you have made payment less <b>Ksh'.$response['body'].'</b>. Kindly pay the balance before we can accept your order. Please make sure you have paid the balance of exactly <b>Ksh'.$response['body'].'</b> to avoid any further delays. Thank you.', 'woocommerce' ) );

		if ( $response['body'] == "no_trans" )
			throw new Exception( __( 'Sorry, we could not verify your payment. Please check your Phone and enter your M-Pesa Pin to complete payment.', 'woocommerce' ) );

		if ( $response['body'] == "stk_fail" )
			throw new Exception( __( 'Sorry, we are unable to initiate payment at this time, please try again later.', 'woocommerce' ) );
		if ( $response['body'] == "phone_err" )
			throw new Exception( __( 'Please enter a valid phone number.', 'woocommerce' ) );
			
		// Retrieve the body's resopnse if no errors found	
		if ( ( $response['body'] == "Success" ) ) {
			// Payment has been successful
			$customer_order->add_order_note( __( 'Paytalk.co.ke payment completed.', 'woocommerce' ) );
												 
			// Mark order as Paid
			$customer_order->payment_complete();

			// Reduce stock levels
    		$customer_order->reduce_order_stock();

			// Empty the cart (Very important step)
			$woocommerce->cart->empty_cart();

			// Redirect to thank you page
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $customer_order ),
			);
		} 

		if ( ( $response['body'] == "offline") ){

			$customer_order->add_order_note( __( 'Paytalk offline Payment - Awaiting confirmation.', 'woocommerce' ) );

			// Mark as on-hold 
    		$customer_order->update_status('on-hold', __( 'Paytalk offline Payment - Awaiting confirmation.', 'woocommerce' ));

    		// Reduce stock levels
    		$customer_order->reduce_order_stock();

			// Empty the cart
			$woocommerce->cart->empty_cart();

			// Redirect to thank you page
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $customer_order ),
			);
		} else {
			// Transaction was not succesful
			// Add notice to the cart
			wc_add_notice( $r['response_reason_text'], 'error' );
			// Add note to the order for your reference
			$customer_order->add_order_note( 'Error: '. $r['response_reason_text'] );
		}

	}
    	
}

function add_init_kopokopo_paytalk_class($methods) {
		$methods[] = 'WC_Paytalk_KopoKopo_Gateway'; 
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'add_init_kopokopo_paytalk_class');
}
}else{
		function my_error_notice() {
	    ?>
	    <div class="error notice">
	        <p><?php _e( '<b>KopoKopo Lipa Na Mpesa Paytalk gateway requires WooCommerce to be activated</b>', 'woocommerce' ); ?></p>
	    </div>
	    <?php
	}
	add_action( 'admin_notices', 'my_error_notice' );
}