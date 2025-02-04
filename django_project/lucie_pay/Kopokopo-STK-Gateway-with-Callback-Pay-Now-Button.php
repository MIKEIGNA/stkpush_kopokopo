<?php
/**
 * Plugin Name: Kopokopo STK Gateway with Callback & Pay Now Button
 * Plugin URI:  https://thekenyanprogrammer.co.ke
 * Description: A WooCommerce payment gateway that initiates a Kopokopo STK push when the customer clicks a custom “Pay Now” button at checkout. The customer then completes the order by clicking the standard Place Order button. A REST callback endpoint updates order statuses based on the payment result.
 * Version:     1.5.0
 * Author:      Jovi
 * Author URI:  https://thekenyanprogrammer.co.ke
 * Text Domain: kopokopo-stk-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Debug logging helper.
 */
if ( ! function_exists( 'kopokopo_debug_log' ) ) {
	function kopokopo_debug_log( $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( print_r( $data, true ) );
		}
	}
}

/**
 * Only proceed if WooCommerce is active.
 */
add_action( 'plugins_loaded', 'kopokopo_stk_gateway_init', 11 );
function kopokopo_stk_gateway_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // WooCommerce not active.
	}

	class WC_Gateway_Kopokopo_STK extends WC_Payment_Gateway {

		// Declare properties.
		public $client_id;
		public $client_secret;
		public $till_number;
		public $callback_url;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id                 = 'kopokopo_stk';
			$this->method_title       = 'Kopokopo STK Payment';
			$this->method_description = 'Initiates a Kopokopo STK push when the customer clicks "Pay Now" and then places the order. The order is held until payment is confirmed via callback.';
			$this->has_fields         = true;

			// Load settings.
			$this->init_form_fields();
			$this->init_settings();

			// Retrieve settings.
			$this->enabled       = $this->get_option( 'enabled' );
			$this->title         = $this->get_option( 'title' );
			$this->description   = $this->get_option( 'description' );
			$this->client_id     = $this->get_option( 'client_id' );
			$this->client_secret = $this->get_option( 'client_secret' );
			$this->till_number   = $this->get_option( 'till_number' );
			$this->callback_url  = $this->get_option( 'callback_url' );

			// Save admin settings.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		}

		/**
		 * Define gateway admin settings.
		 */
		public function init_form_fields() {
			$this->form_fields = [
				'enabled' => [
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable Kopokopo STK Payment',
					'default' => 'no',
				],
				'title' => [
					'title'       => 'Method Title',
					'type'        => 'text',
					'default'     => 'Kopokopo STK Payment',
					'description' => 'Title shown to customers during checkout.',
				],
				'description' => [
					'title'       => 'Description',
					'type'        => 'textarea',
					'default'     => 'After clicking "Pay Now", you will receive a mobile payment prompt. Then, click "Place Order" to complete your purchase.',
					'description' => 'Description shown during checkout.',
				],
				'client_id' => [
					'title'       => 'Kopokopo Client ID',
					'type'        => 'text',
					'description' => 'Enter your Kopokopo Client ID.',
				],
				'client_secret' => [
					'title'       => 'Kopokopo Client Secret',
					'type'        => 'password',
					'description' => 'Enter your Kopokopo Client Secret.',
				],
				'till_number' => [
					'title'       => 'Kopokopo Till Number',
					'type'        => 'text',
					'description' => 'Enter your Kopokopo Till Number (e.g. K123456).',
				],
				'callback_url' => [
					'title'       => 'Callback URL',
					'type'        => 'text',
					'description' => 'Enter your callback URL (e.g. https://yourdomain.com/wp-json/kopokopo/v1/callback).',
					'default'     => '',
				],
			];
		}

		/**
		 * Output the payment fields on checkout.
		 * Here we include a phone field and a hidden field that will be set when "Pay Now" is clicked.
		 */
		public function payment_fields() {
			?>
			<p><?php echo wp_kses_post( $this->description ); ?></p>
			<p>
				<label for="kopokopo_phone">Phone Number (07XXXXXXXX): </label>
				<input type="text" name="kopokopo_phone" id="kopokopo_phone" placeholder="07XXXXXXXX" required />
			</p>
			<!-- Hidden field to indicate that Pay Now was clicked -->
			<input type="hidden" name="kopokopo_paynow" id="kopokopo_paynow" value="0" />
			<p>
				<button type="button" id="kopokopo_pay_now" class="button">Pay Now</button>
			</p>
			<p id="kopokopo_ajax_response" style="color:green;"></p>
			<p style="font-style: italic; font-size: 0.9em;">
				After receiving the STK push on your phone, please click the <strong>Place Order</strong> button below to complete your purchase.
			</p>
			<script>
			jQuery(document).ready(function($){
				$('#kopokopo_pay_now').on('click', function(e){
					e.preventDefault();
					var phone = $('#kopokopo_phone').val();
					if(!phone.match(/^07\d{8}$/)){
						$('#kopokopo_ajax_response').css('color','red').text('Invalid phone format. Use 07XXXXXXXX');
						return;
					}
					// Set a hidden field so we know the customer clicked Pay Now.
					$('#kopokopo_paynow').val('1');
					$('#kopokopo_ajax_response').css('color','green').text('STK push will be initiated when you place your order.');
				});
			});
			</script>
			<?php
		}

		/**
		 * Process payment when Place Order is clicked.
		 * This method uses the phone number and the "pay now" flag to initiate the STK push
		 * with the actual order ID included in the metadata.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$phone = isset( $_POST['kopokopo_phone'] ) ? sanitize_text_field( $_POST['kopokopo_phone'] ) : '';
			$paynow = isset( $_POST['kopokopo_paynow'] ) ? sanitize_text_field( $_POST['kopokopo_paynow'] ) : '0';

			// Require that the customer clicked "Pay Now".
			if ( $paynow !== '1' ) {
				wc_add_notice( 'Please click "Pay Now" to initiate payment before placing your order.', 'error' );
				return;
			}

			// Validate phone number (local format: 07XXXXXXXX).
			if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
				wc_add_notice( 'Invalid phone format. Use 07XXXXXXXX.', 'error' );
				return;
			}

			// Convert phone to international format.
			$phone_int = '254' . substr( $phone, 1 );
			kopokopo_debug_log( 'Phone converted to: ' . $phone_int );

			// Prepare payload metadata including the order ID.
			$metadata = [
				'order_reference' => 'order-' . $order_id,
				'notes'           => 'WooCommerce STK Payment'
			];

			// Build the STK push payload.
			$payload = [
				'payment_channel' => 'M-PESA STK Push',
				'till_number'     => $this->till_number,
				'subscriber'      => [
					'first_name'   => $order->get_billing_first_name() ?: 'Customer',
					'last_name'    => $order->get_billing_last_name() ?: 'Name',
					'phone_number' => $phone_int,
					'email'        => $order->get_billing_email() ?: 'user@example.com'
				],
				'amount' => [
					'currency' => 'KES',
					'value'    => (int) round( $order->get_total() ),
				],
				'metadata' => $metadata,
				'_links'   => [
					'callback_url' => $this->callback_url,
				],
			];

			// Step 1: Get an access token from Kopokopo.
			$token_url = add_query_arg( [
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			], 'https://api.kopokopo.com/oauth/token' );

			$token_resp = wp_remote_post( $token_url, [
				'headers' => [ 'Accept' => 'application/json' ],
				'timeout' => 45,
			]);

			if ( is_wp_error( $token_resp ) ) {
				wc_add_notice( 'Token request error: ' . $token_resp->get_error_message(), 'error' );
				return;
			}

			$token_body = wp_remote_retrieve_body( $token_resp );
			kopokopo_debug_log( 'Token response body: ' . $token_body );
			$token_data = json_decode( $token_body, true );
			if ( empty( $token_data['access_token'] ) ) {
				wc_add_notice( 'Failed to retrieve access token.', 'error' );
				return;
			}
			$access_token = $token_data['access_token'];
			kopokopo_debug_log( 'Access Token: ' . $access_token );

			// Step 2: Initiate the STK push.
			$stk_headers = [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json'
			];

			$stk_resp = wp_remote_post( 'https://api.kopokopo.com/api/v1/incoming_payments', [
				'headers' => $stk_headers,
				'body'    => json_encode( $payload ),
				'timeout' => 45,
			]);

			if ( is_wp_error( $stk_resp ) ) {
				wc_add_notice( 'STK push request error: ' . $stk_resp->get_error_message(), 'error' );
				return;
			}

			$stk_body = wp_remote_retrieve_body( $stk_resp );
			kopokopo_debug_log( 'STK Push response body: ' . $stk_body );
			$stk_data = json_decode( $stk_body, true );
			if ( isset( $stk_data['error'] ) || isset( $stk_data['errors'] ) ) {
				wc_add_notice( 'Kopokopo API error: ' . print_r( $stk_data, true ), 'error' );
				return;
			}

			// Add an order note with the STK push response.
			$order->add_order_note( 'Kopokopo STK push initiated. Response: ' . print_r( $stk_data, true ) );
			// Set the order status to on-hold.
			$order->update_status( 'on-hold', 'Awaiting Kopokopo payment confirmation.' );

			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}
	} // end class WC_Gateway_Kopokopo_STK
} // end function kopokopo_stk_gateway_init

/**
 * Register the gateway with WooCommerce.
 */
add_filter( 'woocommerce_payment_gateways', 'kopokopo_stk_add_gateway_class' );
function kopokopo_stk_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Gateway_Kopokopo_STK';
	return $gateways;
}

/**
 * Register REST API endpoint for Kopokopo callback.
 */
add_action( 'rest_api_init', 'kopokopo_register_callback_route' );
function kopokopo_register_callback_route() {
	register_rest_route( 'kopokopo/v1', '/callback', [
		'methods'             => 'POST',
		'callback'            => 'kopokopo_handle_callback',
		'permission_callback' => '__return_true',
	] );
}

/**
 * Handle Kopokopo callback.
 * Expected payload example:
 * {
 *   "data": {
 *     "id": "49416235-44d1-4396-aa64-b309ba3e6192",
 *     "type": "incoming_payment",
 *     "attributes": {
 *       "initiation_time": "2025-02-03T21:56:05.600+03:00",
 *       "status": "Success",
 *       "metadata": {
 *         "order_reference": "order-1234",
 *         "notes": "WooCommerce STK Payment"
 *       }
 *     }
 *   }
 * }
 */
function kopokopo_handle_callback( WP_REST_Request $request ) {
	$data = $request->get_json_params();
	kopokopo_debug_log( 'Kopokopo callback received: ' . print_r( $data, true ) );
	// Check for order_reference in the payload.
	if ( isset( $data['data']['attributes']['metadata']['order_reference'] ) ) {
		$order_ref = $data['data']['attributes']['metadata']['order_reference'];
	} else {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order reference missing.' ], 400 );
	}
	// Extract order ID from the reference (assuming format "order-1234").
	$order_id = intval( filter_var( $order_ref, FILTER_SANITIZE_NUMBER_INT ) );
	if ( ! $order_id ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid order reference.' ], 400 );
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order not found.' ], 404 );
	}
	// Determine payment result.
	$status = isset( $data['data']['attributes']['status'] ) ? strtolower( $data['data']['attributes']['status'] ) : '';
	$payment_success = ( $status === 'success' );
	if ( $payment_success ) {
		$order->update_status( 'processing', 'Kopokopo payment confirmed via callback.' );
		$order->add_order_note( 'Payment confirmed via Kopokopo callback. Details: ' . print_r( $data, true ) );
	} else {
		$order->update_status( 'failed', 'Kopokopo payment failed via callback.' );
		$order->add_order_note( 'Kopokopo payment failed. Details: ' . print_r( $data, true ) );
	}
	return new WP_REST_Response( [ 'status' => 'success', 'message' => 'Callback processed.' ], 200 );
}
