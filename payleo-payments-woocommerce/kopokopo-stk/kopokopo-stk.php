<?php
/**
 * Plugin Name: Kopokopo STK Push Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push with a "Pay Now" button before checkout, plus a WP REST API callback.
 * Version:     1.2.0
 * Author:      Jovi
 * Author URI:  https://thekenyanprogrammer.co.ke/
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access.
}

// ---------------------------------------------------------------------------
// 1. Register the Kopokopo gateway with WooCommerce
// ---------------------------------------------------------------------------
add_filter('woocommerce_payment_gateways', 'add_kopokopo_gateway');
function add_kopokopo_gateway($gateways)
{
    $gateways[] = 'WC_Kopokopo_Gateway';
    return $gateways;
}

// ---------------------------------------------------------------------------
// 2. Initialize the gateway only after WooCommerce is loaded
// ---------------------------------------------------------------------------
add_action('plugins_loaded', 'init_kopokopo_gateway');
function init_kopokopo_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return; // WooCommerce not active
    }

    class WC_Kopokopo_Gateway extends WC_Payment_Gateway
    {
        // Explicitly declare properties to avoid PHP 8.2+ deprecated dynamic property warnings
        public $client_id;
        public $client_secret;
        public $till_number;
        public $callback_url;

        public function __construct()
        {
            $this->id                 = 'kopokopo_stk';
            $this->has_fields         = true;
            $this->method_title       = 'Kopokopo STK Push';
            $this->method_description = 'Pay via Kopokopo STK Push before placing your order.';

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Assign declared properties
            $this->enabled       = $this->get_option('enabled');
            $this->title         = $this->get_option('title');
            $this->description   = $this->get_option('description');
            $this->client_id     = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->till_number   = $this->get_option('till_number');
            $this->callback_url  = $this->get_option('callback_url');

            // Save admin settings
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                [$this, 'process_admin_options']
            );

            // Logging to confirm constructor runs
            error_log('jovi Registering wp_ajax_kopokopo_stk_push now...');
            $log_message = "jovi all the variables: \n" .
                " - client_id: " . $this->client_id . "\n" .
                " - client_secret: " . $this->client_secret . "\n" .
                " - till_number: " . $this->till_number . "\n" .
                " - callback_url: " . $this->callback_url;
            error_log($log_message);

            // -----------------------------------------------------------------
            // Register the AJAX hooks for STK push
            // -----------------------------------------------------------------
            add_action('wp_ajax_kopokopo_stk_push', [$this, 'kopokopo_stk_push']);
            add_action('wp_ajax_nopriv_kopokopo_stk_push', [$this, 'kopokopo_stk_push']);

            // Register the REST API route for callback
            add_action('rest_api_init', [$this, 'register_kopokopo_rest_routes']);

            error_log('jovi after Registering wp_ajax_kopokopo_stk_push later...');
        }

        /**
         * Register a custom REST route:
         *  https://YOURDOMAIN.com/wp-json/kopokopo/v1/kopokopo_callback
         */
        public function register_kopokopo_rest_routes()
        {
            register_rest_route(
                'kopokopo/v1',
                '/kopokopo_callback',
                [
                    'methods'  => ['GET', 'POST'], // Kopokopo typically sends POST
                    'callback' => [$this, 'handle_kopokopo_rest_callback'],
                    'permission_callback' => '__return_true', // Adjust as needed for security
                ]
            );
        }

        /**
         * Handle the Kopokopo REST callback.
         * Returns a simple "success" JSON by default.
         */
        public function handle_kopokopo_rest_callback(\WP_REST_Request $request)
        {
            // Log the received data for debugging
            error_log("jovi Kopokopo Callback Data: " . print_r($request->get_params(), true));

            // TODO: Parse the callback data and update the order accordingly

            // Return a success JSON response
            $response_data = [
                'status'  => 'success',
                'message' => 'Kopokopo callback received.',
            ];
            error_log('jovi Rest callback in handle_kopokopo_rest_callback success...');

            return new \WP_REST_Response($response_data, 200);
        }

        /**
         * Admin form fields
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Kopokopo STK Push',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Kopokopo STK Push',
                ],
                'description' => [
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Enter your phone number and click "Pay Now" to receive an M-PESA prompt.',
                ],
                'client_id' => [
                    'title'       => 'Kopokopo Client ID',
                    'type'        => 'text',
                    'description' => 'Your Kopokopo Client ID.',
                ],
                'client_secret' => [
                    'title'       => 'Kopokopo Client Secret',
                    'type'        => 'password',
                    'description' => 'Your Kopokopo Client Secret.',
                ],
                'till_number' => [
                    'title'       => 'Kopokopo Till Number',
                    'type'        => 'text',
                    'description' => 'Your Kopokopo Till Number.',
                ],
                'callback_url' => [
                    'title'       => 'Callback URL',
                    'type'        => 'text',
                    'description' => 'Example: https://YOURDOMAIN.com/wp-json/kopokopo/v1/kopokopo_callback',
                ],
            ];
        }

        /**
         * Payment fields shown on the checkout
         */
        public function payment_fields()
        {
            ?>
            <p><?php echo esc_html($this->description); ?></p>
            <fieldset>
                <label for="kopokopo_phone">Phone Number <span class="required">*</span></label>
                <input type="text" id="kopokopo_phone" name="kopokopo_phone" required placeholder="07XXXXXXXX" />
                <button type="button" id="kopokopo_pay_now" class="button">Pay Now</button>
                <p id="kopokopo_status" style="margin-top: 10px;"></p>
                <input type="hidden" id="kopokopo_payment_status" name="kopokopo_payment_status" value="0">
            </fieldset>

            <script>
            jQuery(document).ready(function($) {
                $('#kopokopo_pay_now').on('click', function() {
                    var phone = $('#kopokopo_phone').val();
                    var total = '<?php echo esc_js(WC()->cart->get_total('edit')); ?>';

                    // Basic validation for a phone number
                    if (!phone || !phone.match(/^07\d{8}$/)) {
                        alert('Please enter a valid M-PESA phone number (e.g., 07XXXXXXXX)');
                        return;
                    }

                    $('#kopokopo_status').text('Sending STK Push...');

                    $.ajax({
                        type: 'POST',
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        data: {
                            action: 'kopokopo_stk_push',
                            phone: phone,
                            amount: total
                        },
                        success: function(response) {
                            if (response.success) {
                                console.log('Kopokopo STK Push Success:', response);
                                $('#kopokopo_status').text('Payment request sent. Please complete on your phone.');
                                $('#kopokopo_payment_status').val('1');
                            } else {
                                console.error('Kopokopo STK Error:', response.data ? response.data.message : 'Unknown');
                                $('#kopokopo_status').text('Payment failed: ' + (response.data ? response.data.message : 'Unknown'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error, xhr.responseText);
                            $('#kopokopo_status').text('An error occurred. Please try again.');
                        }
                    });
                });

                // Prevent placing the order if STK push wasn't triggered/completed
                $('form.checkout').on('submit', function(e) {
                    if ($('#kopokopo_payment_status').val() !== '1') {
                        e.preventDefault();
                        alert('Please complete payment before placing the order.');
                    }
                });
            });
            </script>
            <?php
        }

        /**
         * process_payment: when the user clicks "Place Order"
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', 'Awaiting Kopokopo STK push confirmation');

            error_log('jovi process_payment success... order: ' . print_r($order, true));

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        /**
         * 3. The Ajax callback for STK push
         */
        public function kopokopo_stk_push()
        {
            // Log to confirm the callback is reached
            error_log('jovi in kopokopo_stk_push() callback...');

            // Retrieve phone & amount
            $phone  = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : 0;

            // Validate phone number
            if (!preg_match('/^07\d{8}$/', $phone)) {
                wp_send_json_error(['message' => 'Invalid phone number format.']);
            }

            // Convert 07xxxxxxx -> 2547xxxxxxx
            if (substr($phone, 0, 1) === '0') {
                $phone = '254' . substr($phone, 1);
            }

            // Retrieve token
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error(['message' => 'Authentication failed (no token).']);
            }
            error_log("jovi after kopokopo_stk_push token gotten: $token");

            // Attempt to find or create a WooCommerce order in session
            $order_id = WC()->session->get('order_id');
            if (!$order_id) {
                // Create a draft order for demonstration
                $order = wc_create_order();
                $order_id = $order->get_id();

                WC()->session->set('order_id', $order_id);
                WC()->session->set('kopokopo_phone', $phone);
            } else {
                $order = wc_get_order($order_id);
            }

            if (!$order) {
                wp_send_json_error(['message' => 'Order not found.']);
            }
            error_log("jovi my order id: $order_id");

            // Do the STK push
            $response = $this->initiate_stk_push($order, $phone, $token);
            error_log("jovi response after initiate_stk_push: " . print_r($response, true));

            if ($response && isset($response['data']['id'])) {
                // If success from KopoKopo
                wp_send_json_success([
                    'message'  => 'STK Push sent successfully.',
                    'order_id' => $order_id,
                ]);
            } else {
                wp_send_json_error([
                    'message'  => 'STK Push failed.',
                    'response' => $response,
                ]);
            }
        }

        /**
         * 4. Get an OAuth token from Kopokopo
         */
        private function get_access_token()
        {
            $url = 'https://api.kopokopo.com/oauth/token';

            // KopoKopo expects x-www-form-urlencoded for client_credentials
            $body = http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ]);

            $args = [
                'body'    => $body,
                'headers' => [
                    // 'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json'
                ],
                'timeout' => 60,
            ];

            $response = wp_remote_post($url, $args);
            error_log('jovi get_access_token response: ' . print_r($response, true));

            if (is_wp_error($response)) {
                return false;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            error_log('jovi get_access_token data: ' . print_r($data, true));

            if (!empty($data['access_token'])) {
                return $data['access_token'];
            }
            return false;
        }

        /**
         * 5. Send the STK push request
         */
        private function initiate_stk_push($order, $phone_number, $token)
        {
            $url = 'https://api.kopokopo.com/api/v1/incoming_payments';

            // If $order is draft with no items, get_total() may be 0.
            $body = [
                'payment_channel' => 'M-PESA STK Push',
                'till_number'     => $this->till_number,
                'subscriber'      => [
                    'phone_number' => $phone_number,
                ],
                'amount' => [
                    'currency' => 'KES',
                    'value'    => $order->get_total(), // or pass $amount if you prefer
                ],
                '_links' => [
                    'callback_url' => $this->callback_url,
                ],
            ];

            $args = [
                'body'    => json_encode($body),
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'timeout' => 60,
            ];

            $response = wp_remote_post($url, $args);
            error_log('jovi initiate_stk_push response: ' . print_r($response, true));

            if (is_wp_error($response)) {
                return false;
            }
            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
}
