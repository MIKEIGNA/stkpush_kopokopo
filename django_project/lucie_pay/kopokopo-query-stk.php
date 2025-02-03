<?php
/**
 * Plugin Name: Kopokopo Query STK Push Gateway
 * Plugin URI:  https://omukiguy.com
 * Description: A simple WooCommerce payment gateway using "Query APIs" style logic for Kopokopo STK push. Adds a phone field and a "Pay Now" button at checkout.
 * Version:     1.3.2
 * Author:      Jovis
 * Author URI:  https://omukiguy.com
 * Text Domain: kopokopo-query-stk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

/**
 * Helper function for debug logging (optional).
 * Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to review the log.
 */
if ( ! function_exists( 'kopokopo_debug_log' ) ) {
	function kopokopo_debug_log( $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$output = print_r( $data, true );
			error_log( "Kopokopo Debug: " . $output );
		}
	}
}

/**
 * Only proceed if WooCommerce is active.
 */
add_action( 'plugins_loaded', 'kopokopo_query_stk_init', 11 );
function kopokopo_query_stk_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // WooCommerce not loaded or inactive.
	}

	class WC_Gateway_Kopokopo_Query_STK extends WC_Payment_Gateway {

		// Declare properties to avoid dynamic property deprecation.
		public $client_id;
		public $client_secret;
		public $till_number;
		public $callback_url;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id                 = 'kopokopo_query_stk';
			$this->method_title       = 'Kopokopo STK (Query API Style)';
			$this->method_description = 'Sends a Kopokopo STK push from checkout via a "Pay Now" button.';
			$this->has_fields         = true; // We output a phone field + button.

			// Load settings.
			$this->init_form_fields();
			$this->init_settings();

			// Retrieve the settings.
			$this->enabled       = $this->get_option( 'enabled' );
			$this->title         = $this->get_option( 'title' );
			$this->description   = $this->get_option( 'description' );
			$this->client_id     = $this->get_option( 'client_id' );
			$this->client_secret = $this->get_option( 'client_secret' );
			$this->till_number   = $this->get_option( 'till_number' );
			$this->callback_url  = $this->get_option( 'callback_url' );

			// Save admin settings.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

			// Register AJAX callbacks for sending STK push.
			add_action( 'wp_ajax_kopokopo_query_stk_push', [ $this, 'kopokopo_query_stk_push' ] );
			add_action( 'wp_ajax_nopriv_kopokopo_query_stk_push', [ $this, 'kopokopo_query_stk_push' ] );
		}

		/**
		 * Initialize gateway form fields for WooCommerce admin.
		 */
		public function init_form_fields() {
			$this->form_fields = [
				'enabled' => [
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable Kopokopo Query STK Gateway',
					'default' => 'no',
				],
				'title' => [
					'title'       => 'Method Title',
					'type'        => 'text',
					'default'     => 'Kopokopo STK Push',
					'description' => 'Title shown during checkout.',
				],
				'description' => [
					'title'       => 'Description',
					'type'        => 'textarea',
					'default'     => 'Pay via Kopokopo STK before completing your order.',
					'description' => 'Shown to customers during checkout.',
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
					'description' => 'Enter your Kopokopo Till Number, e.g. K123456.',
				],
				'callback_url' => [
					'title'       => 'Callback URL',
					'type'        => 'text',
					'description' => 'Enter your callback URL. This is required by Kopokopo.',
					'default'     => '',
				],
			];
		}

		/**
		 * Output the phone field and "Pay Now" button on the checkout page.
		 */
		public function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wptexturize( $this->description ) );
			}
			?>
			<div style="margin-bottom: 10px;">
				<label for="kopokopo_query_phone">Enter your phone number (07XXXXXXXX):</label>
				<input type="text" id="kopokopo_query_phone" name="kopokopo_query_phone" style="width: 100%; max-width: 300px;" placeholder="07XXXXXXXX" />
			</div>
			<button type="button" id="kopokopo_query_pay_btn" class="button">Pay Now</button>
			<p id="kopokopo_query_response" style="margin-top:10px; color:green;"></p>

			<script>
			jQuery(document).ready(function($){
				$('#kopokopo_query_pay_btn').on('click', function(e){
					e.preventDefault();
					var phone = $('#kopokopo_query_phone').val();
					// Get the cart total from PHP.
					var order_total = <?php echo json_encode( WC()->cart->total ); ?>;

					// Validate phone format: must be 07XXXXXXXX.
					if (!phone.match(/^07\d{8}$/)) {
						$('#kopokopo_query_response').css('color','red').text('Invalid phone. Format: 07XXXXXXXX');
						return;
					}

					$('#kopokopo_query_response').css('color','black').text('Sending STK push...');

					$.ajax({
						url: '<?php echo admin_url('admin-ajax.php'); ?>',
						method: 'POST',
						dataType: 'json',
						data: {
							action: 'kopokopo_query_stk_push',
							phone: phone,
							amount: order_total,
						},
						success: function(res) {
							if (res.success) {
								$('#kopokopo_query_response').css('color','green').text('STK Push Sent! Check your phone.');
							} else {
								var msg = (res.data && res.data.message) ? res.data.message : 'Unknown error';
								$('#kopokopo_query_response').css('color','red').text('Error: ' + msg);
							}
						},
						error: function(jqXHR, textStatus, errorThrown) {
							console.log('AJAX error details:', jqXHR, textStatus, errorThrown);
							$('#kopokopo_query_response').css('color','red').text('Ajax request failed: ' + textStatus + ' - ' + errorThrown);
						}
					});
				});
			});
			</script>
			<?php
		}

		/**
		 * The AJAX callback: obtains an access token and sends the STK push.
		 */
		public function kopokopo_query_stk_push() {
			kopokopo_debug_log( 'kopokopo_query_stk_push called with POST: ' . print_r( $_POST, true ) );

			$phone  = isset( $_POST['phone'] )  ? sanitize_text_field( $_POST['phone'] ) : '';
			$amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;

			// Validate phone format on server side.
			if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
				wp_send_json_error( [ 'message' => 'Invalid phone format on server side' ] );
			}
			if ( $amount <= 0 ) {
				wp_send_json_error( [ 'message' => 'Invalid order amount' ] );
			}

			// Convert phone to international format (2547XXXXXXXX).
			$phone_international = '254' . substr( $phone, 1 );
			kopokopo_debug_log( 'Phone converted to: ' . $phone_international );

			// Step 1: Obtain access token from Kopokopo.
			$token_url = add_query_arg(
				[
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
				],
				'https://api.kopokopo.com/oauth/token'
			);

			$token_resp = wp_remote_post( $token_url, [
				'headers' => [ 'Accept' => 'application/json' ],
				'timeout' => 45,
			]);

			if ( is_wp_error( $token_resp ) ) {
				wp_send_json_error( [ 'message' => 'Token request error: ' . $token_resp->get_error_message() ] );
			}

			$token_body = wp_remote_retrieve_body( $token_resp );
			kopokopo_debug_log( 'Token response body: ' . $token_body );

			$token_data = json_decode( $token_body, true );
			if ( empty( $token_data['access_token'] ) ) {
				wp_send_json_error( [ 'message' => 'No access_token found: ' . $token_body ] );
			}

			$access_token = $token_data['access_token'];
			kopokopo_debug_log( 'Access Token: ' . $access_token );

			// Step 2: Initiate STK push.
			$payload = [
				'payment_channel' => 'M-PESA STK Push',
				'till_number'     => $this->till_number,
				'subscriber'      => [
					'first_name'   => 'Checkout',
					'last_name'    => 'User',
					'phone_number' => $phone_international,
					'email'        => 'user@example.com'
				],
				'amount' => [
					'currency' => 'KES',
					'value'    => (int) round( $amount ),
				],
				'metadata' => [
					'notes' => 'WooCommerce STK Payment'
				],
				'_links' => [
					'callback_url' => $this->callback_url ? $this->callback_url : ''
				],
			];

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
				wp_send_json_error( [ 'message' => 'STK request error: ' . $stk_resp->get_error_message() ] );
			}

			$stk_body = wp_remote_retrieve_body( $stk_resp );
			kopokopo_debug_log( 'STK Push response body: ' . $stk_body );

			$stk_data = json_decode( $stk_body, true );
			if ( isset( $stk_data['error'] ) || isset( $stk_data['errors'] ) ) {
				wp_send_json_error( [ 'message' => 'Kopokopo API error: ' . print_r( $stk_data, true ) ] );
			}

			// If no errors, send a success response.
			wp_send_json_success( [ 'kopokopo_response' => $stk_data ] );
		}

		/**
		 * Finalize the order by setting its status to on-hold.
		 */
		public function process_payment( $order_id ) {
			kopokopo_debug_log( "process_payment called for order: $order_id" );

			$order = wc_get_order( $order_id );
			$order->update_status( 'on-hold', 'Awaiting Kopokopo payment' );

			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}
	} // end class WC_Gateway_Kopokopo_Query_STK
} // end function kopokopo_query_stk_init

/**
 * Register this gateway with WooCommerce.
 */
add_filter( 'woocommerce_payment_gateways', 'kopokopo_query_add_gateway_class' );
function kopokopo_query_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Gateway_Kopokopo_Query_STK';
	return $gateways;
}

/**
 * Register the AJAX callback outside the class so it is always available.
 */
function kopokopo_query_stk_push_callback() {
	$gateway = new WC_Gateway_Kopokopo_Query_STK();
	$gateway->kopokopo_query_stk_push();
}
add_action( 'wp_ajax_kopokopo_query_stk_push', 'kopokopo_query_stk_push_callback' );
add_action( 'wp_ajax_nopriv_kopokopo_query_stk_push', 'kopokopo_query_stk_push_callback' );
