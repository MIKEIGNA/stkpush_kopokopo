<?php
/**
 * Plugin Name: Kopokopo STK Push Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push with a "Pay Now" button before checkout.
 * Version:     1.1.7
 * Author:      Jovi
 * Author URI:  https://thekenyanprogrammer.co.ke/
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Register the payment gateway
add_filter('woocommerce_payment_gateways', 'add_kopokopo_gateway');
function add_kopokopo_gateway($gateways)
{
    $gateways[] = 'WC_Kopokopo_Gateway';
    return $gateways;
}

// Define Kopokopo Payment Gateway
add_action('plugins_loaded', 'init_kopokopo_gateway');
function init_kopokopo_gateway()
{
    class WC_Kopokopo_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id                 = 'kopokopo_stk';
            $this->has_fields         = true;
            $this->method_title       = 'Kopokopo STK Push';
            $this->method_description = 'Pay via Kopokopo STK Push before placing your order.';

            // Load settings fields
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled        = $this->get_option('enabled');
            $this->title          = $this->get_option('title');
            $this->description    = $this->get_option('description');
            $this->client_id      = $this->get_option('client_id');
            $this->client_secret  = $this->get_option('client_secret');
            $this->till_number    = $this->get_option('till_number');
            $this->callback_url   = $this->get_option('callback_url');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // AJAX handler for initiating STK push
            add_action('wp_ajax_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
            add_action('wp_ajax_nopriv_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Kopokopo STK Push',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Kopokopo STK Push',
                ),
                'description' => array(
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Enter your phone number and click "Pay Now" to receive an M-PESA prompt.',
                ),
                'client_id' => array(
                    'title'   => 'Kopokopo Client ID',
                    'type'    => 'text',
                ),
                'client_secret' => array(
                    'title'   => 'Kopokopo Client Secret',
                    'type'    => 'password',
                ),
                'till_number' => array(
                    'title'   => 'Kopokopo Till Number',
                    'type'    => 'text',
                ),
                'callback_url' => array(
                    'title'   => 'Callback URL',
                    'type'    => 'text',
                    'description' => 'This must be a publicly accessible URL for KopoKopo to send confirmation.',
                ),
            );
        }

        /**
         * Render the payment fields at checkout
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
                       var total = '<?php echo WC()->cart->total; ?>';

                       // Basic phone check
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
                                   $('#kopokopo_status').text('Payment failed: ' + response.message);
                                   console.error('Error occurred:', response.message);
                               }
                           },
                           error: function(xhr, status, error) {
                               console.error('AJAX Error:', status, error, xhr.responseText);
                               $('#kopokopo_status').text('An error occurred. Please try again.');
                           }
                       });
                   });

                   $('form.checkout').on('submit', function(e) {
                       // Ensure user clicked "Pay Now" and phone STK push has been triggered
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
         * Process the WooCommerce order payment.
         * This method is triggered when the user actually clicks "Place Order".
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            // Mark the order as on-hold to await external confirmation
            $order->update_status('on-hold', 'Awaiting payment confirmation from Kopokopo STK Push.');

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        /**
         * AJAX callback to initiate STK push
         */
        public function kopokopo_stk_push()
        {
            $phone  = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : 0;

            // Convert phone format: 07xxxxxxxx -> 2547xxxxxxxx
            if (substr($phone, 0, 1) === '0') {
                $phone = '254' . substr($phone, 1);
            }

            // Get OAuth access token
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error(['message' => 'Authentication failed. No valid token.']);
            }

            // Try to fetch an existing order from session
            $order_id = WC()->session->get('order_id');
            if (!$order_id) {
                // If no order, create a new one (draft) for demonstration.
                // Note: This order will not have line items unless you add them.
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

            // Attempt the STK push
            $response = $this->initiate_stk_push($order, $phone, $token);
            error_log("Kopokopo STK Push Response: " . print_r($response, true));

            // KopoKopo success is indicated by a "data" object containing an "id"
            if ($response && isset($response['data']['id'])) {
                wp_send_json_success([
                    'message'  => 'STK Push sent successfully.',
                    'order_id' => $order_id
                ]);
            } else {
                wp_send_json_error([
                    'message'   => 'STK Push failed.',
                    'response'  => $response
                ]);
            }
        }

        /**
         * Retrieves an OAuth token from KopoKopo
         */
        private function get_access_token()
        {
            $url = 'https://api.kopokopo.com/oauth/token';

            // KopoKopo expects x-www-form-urlencoded for client_credentials
            $body = http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret
            ]);

            $args = [
                'body'    => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json'
                ],
                'timeout' => 60
            ];

            error_log("Kopokopo Access Token Request: " . print_r($args, true));

            $response = wp_remote_post($url, $args);
            error_log("Kopokopo Access Token Response: " . print_r($response, true));

            if (is_wp_error($response)) {
                error_log("Kopokopo Access Token WP Error: " . $response->get_error_message());
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            error_log("Kopokopo Access Token Decoded: " . print_r($data, true));

            if (!empty($data['access_token'])) {
                error_log('Access token retrieved successfully.');
                return $data['access_token'];
            }

            error_log('Failed to retrieve access token from KopoKopo.');
            return false;
        }

        /**
         * Initiates an STK push to the KopoKopo API
         */
        private function initiate_stk_push($order, $phone_number, $token)
        {
            error_log('Initiating STK push...');

            $url = 'https://api.kopokopo.com/api/v1/incoming_payments';
            $body = [
                'payment_channel' => 'M-PESA STK Push',
                'till_number'     => $this->till_number,
                'subscriber'      => [
                    'phone_number' => $phone_number
                ],
                'amount' => [
                    'currency' => 'KES',
                    // If your newly-created order has no items, ensure it has a total > 0
                    'value'    => $order->get_total(),
                ],
                '_links' => [
                    'callback_url' => $this->callback_url,
                ],
            ];

            error_log('STK push body: ' . json_encode($body));

            $args = [
                'body'    => json_encode($body),
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ],
                'timeout' => 60
            ];

            error_log('STK push args: ' . json_encode($args));

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                error_log('STK push request failed: ' . $response->get_error_message());
                return false;
            } else {
                error_log('STK push raw response: ' . wp_remote_retrieve_body($response));
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
}
