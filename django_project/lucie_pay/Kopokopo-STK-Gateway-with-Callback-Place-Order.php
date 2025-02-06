<?php
/**
 * Plugin Name: Kopokopo STK Gateway with Callback (Place Order Initiates STK Push)
 * Plugin URI:  https://omukiguy.com
 * Description: A WooCommerce payment gateway that automatically initiates a Kopokopo STK push when the customer clicks the standard Place Order button. A REST callback endpoint then updates order statuses based on the payment result.
 * Version:     1.0.1
 * Author:      Jovi
 * Author URI:  https://omukiguy.com
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
			$this->method_description = 'Automatically initiates a Kopokopo STK push when the customer clicks Place Order. The order is held until payment is confirmed via callback.';
			$this->has_fields         = true; // We output a phone field.

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
					'default'     => 'Enter your phone number below. When you click Place Order, an STK push will be sent to your phone. Once you complete the mobile payment, your order will be processed.',
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
		 * In this version we simply ask for the phone number.
		 */
		public function payment_fields() {
			?>
			<p><?php echo wp_kses_post( $this->description ); ?></p>
			<p>
				<label for="kopokopo_phone">Phone Number (07XXXXXXXX): </label>
				<input type="text" name="kopokopo_phone" id="kopokopo_phone" placeholder="07XXXXXXXX" required />
			</p>
			<?php
		}

		/**
		 * Process payment when Place Order is clicked.
		 * This method retrieves the phone number, initiates the STK push, and sets the order status to on-hold.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$phone = isset( $_POST['kopokopo_phone'] ) ? sanitize_text_field( $_POST['kopokopo_phone'] ) : '';

			// Validate phone number (local format: 07XXXXXXXX).
			if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
				wc_add_notice( 'Invalid phone format. Use 07XXXXXXXX.', 'error' );
				return;
			}

			// Convert phone to international format (2547XXXXXXXX).
			$phone_int = '254' . substr( $phone, 1 );
			kopokopo_debug_log( 'Phone converted to: ' . $phone_int );

			// Build metadata with the order ID.
			$metadata = [
				'order_reference' => 'order-' . $order_id,
				'notes'           => 'WooCommerce STK Payment'
			];

			// Build payload.
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

			// Step 1: Obtain access token from Kopokopo.
			$token_url = add_query_arg( [
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			], 'https://api.kopokopo.com/oauth/token' );
			$token_resp = wp_remote_post( $token_url, [
				'headers' => [ 'Accept' => 'application/json' ],
				'timeout' => 45,
			] );
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
			] );
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
			$order->save();

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
 *     "id": "b8cffbd5-b699-4d86-9449-e49c5bc77e6c",
 *     "type": "incoming_payment",
 *     "attributes": {
 *       "initiation_time": "2025-02-04T09:47:33.621+03:00",
 *       "status": "Success",
 *       "metadata": {
 *         "order_reference": "order-50",
 *         "notes": "WooCommerce STK Payment"
 *       }
 *     }
 *   }
 * }
 */
function kopokopo_handle_callback( WP_REST_Request $request ) {
	$data = $request->get_json_params();
	kopokopo_debug_log( 'Kopokopo callback received: ' . print_r( $data, true ) );

	// Get the order reference.
	if ( isset( $data['data']['attributes']['metadata']['order_reference'] ) ) {
		$order_ref = $data['data']['attributes']['metadata']['order_reference'];
	} else {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order reference missing.' ], 400 );
	}
	// Extract order ID (assuming format "order-50").
	$order_id = intval( filter_var( $order_ref, FILTER_SANITIZE_NUMBER_INT ) );
	if ( ! $order_id ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid order reference.' ], 400 );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order not found.' ], 404 );
	}

	// Get the payment status from the callback.
	$status = isset( $data['data']['attributes']['status'] ) ? strtolower( $data['data']['attributes']['status'] ) : '';
	$payment_success = ( $status === 'success' );
	if ( $payment_success ) {
		$order->update_status( 'processing', 'Kopokopo payment confirmed via callback.' );
		$order->add_order_note( 'Payment confirmed via Kopokopo callback. Details: ' . print_r( $data, true ) );
	} else {
		$order->update_status( 'failed', 'Kopokopo payment failed via callback.' );
		$order->add_order_note( 'Kopokopo payment failed. Details: ' . print_r( $data, true ) );
	}
	$order->save();
	return new WP_REST_Response( [ 'status' => 'success', 'message' => 'Callback processed.' ], 200 );
}
