<?php
/**
 * Plugin Name: Kopokopo STK Gateway with Callback
 * Plugin URI:  https://omukiguy.com
 * Description: WooCommerce payment gateway that sends a Kopokopo STK push during checkout and processes callbacks to update order statuses.
 * Version:     1.1.0
 * Author:      JoviDe
 * Author URI:  https://omukiguy.com
 * Text Domain: kopokopo-stk-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Helper function for debug logging.
 * Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php.
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

		// Declare properties to avoid deprecation warnings.
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
			$this->method_description = 'Customers pay via a Kopokopo STK push. The order is held until payment is confirmed via callback.';
			$this->has_fields         = true; // We'll output a phone field.

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

			// Save settings.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		}

		/**
		 * Initialize admin form fields.
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
					'default'     => 'Pay via Kopokopo STK push. Your order will be held until payment is confirmed.',
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
		 * Output payment fields on checkout.
		 */
		public function payment_fields() {
			?>
			<p>Please enter your mobile phone number. A payment prompt will be sent to your phone.</p>
			<p>
				<label for="kopokopo_phone">Phone Number (07XXXXXXXX): </label>
				<input type="text" name="kopokopo_phone" id="kopokopo_phone" placeholder="07XXXXXXXX" required />
			</p>
			<?php
		}

		/**
		 * Process payment when the order is placed.
		 * This method sends the STK push and sets the order status to on-hold.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$phone = isset( $_POST['kopokopo_phone'] ) ? sanitize_text_field( $_POST['kopokopo_phone'] ) : '';

			// Validate phone number (should be in local format: 07XXXXXXXX).
			if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
				wc_add_notice( 'Invalid phone format. Use 07XXXXXXXX.', 'error' );
				return;
			}

			// Convert phone to international format.
			$phone_int = '254' . substr( $phone, 1 );

			// Prepare metadata to pass in the STK push payload.
			$metadata = [
				'order_id'  => $order_id,
				'notes'     => 'WooCommerce STK Payment',
			];

			// Prepare payload.
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
			] );

			if ( is_wp_error( $token_resp ) ) {
				wc_add_notice( 'Token request error: ' . $token_resp->get_error_message(), 'error' );
				return;
			}

			$token_body = wp_remote_retrieve_body( $token_resp );
			$token_data = json_decode( $token_body, true );
			if ( empty( $token_data['access_token'] ) ) {
				wc_add_notice( 'Failed to retrieve access token.', 'error' );
				return;
			}
			$access_token = $token_data['access_token'];

			// Step 2: Send the STK push.
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
			$stk_data = json_decode( $stk_body, true );
			if ( isset( $stk_data['error'] ) || isset( $stk_data['errors'] ) ) {
				wc_add_notice( 'Kopokopo API error: ' . print_r( $stk_data, true ), 'error' );
				return;
			}

			// Save STK push response in order note.
			$order->add_order_note( 'Kopokopo STK push initiated. Response: ' . print_r( $stk_data, true ) );

			// Set order status to on-hold (awaiting payment confirmation).
			$order->update_status( 'on-hold', 'Awaiting Kopokopo payment confirmation.' );

			// Return success so WooCommerce can redirect the customer.
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
 * Register a REST API endpoint for Kopokopo callback.
 * The callback URL should be set in the gateway settings.
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
 * Expected payload should include the order reference (order_id) and a payment status.
 */
function kopokopo_handle_callback( WP_REST_Request $request ) {
	$data = $request->get_json_params();
	kopokopo_debug_log( 'Kopokopo callback received: ' . print_r( $data, true ) );

	// Assume the callback includes a "reference" that we sent in metadata (order_id)
	$order_id = isset( $data['reference'] ) ? absint( $data['reference'] ) : 0;
	if ( ! $order_id ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order reference missing.' ], 400 );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Order not found.' ], 404 );
	}

	// Determine payment result. (For example, check if error_code is 0.)
	// Adjust this logic based on Kopokopo’s actual callback structure.
	$payment_success = ( isset( $data['error_code'] ) && $data['error_code'] == 0 );
	if ( $payment_success ) {
		$order->update_status( 'processing', 'Kopokopo payment confirmed.' );
		$order->add_order_note( 'Payment confirmed via Kopokopo callback.' );
	} else {
		$order->update_status( 'failed', 'Kopokopo payment failed.' );
		$order->add_order_note( 'Kopokopo payment failed. Details: ' . print_r( $data, true ) );
	}

	return new WP_REST_Response( [ 'status' => 'success', 'message' => 'Callback processed.' ], 200 );
}
