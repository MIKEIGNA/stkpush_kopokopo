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

// Add Kopokopo to the list of WooCommerce payment gateways
add_filter('woocommerce_payment_gateways', 'add_kopokopo_gateway');
function add_kopokopo_gateway($gateways)
{
    $gateways[] = 'WC_Kopokopo_Gateway';
    return $gateways;
}

// Initialize the gateway only after WooCommerce is fully loaded
add_action('plugins_loaded', 'init_kopokopo_gateway');
function init_kopokopo_gateway()
{
    // Make sure the WC_Payment_Gateway class exists (i.e., WooCommerce is active)
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Kopokopo_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id                 = 'kopokopo_stk';
            $this->has_fields         = true;
            $this->method_title       = 'Kopokopo STK Push';
            $this->method_description = 'Pay via Kopokopo STK Push before placing your order.';

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled      = $this->get_option('enabled');
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->client_id    = $this->get_option('client_id');
            $this->client_secret= $this->get_option('client_secret');
            $this->till_number  = $this->get_option('till_number');
            $this->callback_url = $this->get_option('callback_url');

            // Save admin settings
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options')
            );

            // AJAX hooks for STK push
            add_action('wp_ajax_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
            add_action('wp_ajax_nopriv_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
        }

        /**
         * Admin form fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable Kopokopo STK Push',
                    'default'     => 'yes',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'default'     => 'Kopokopo STK Push',
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'default'     => 'Enter your phone number and click "Pay Now" to receive an M-PESA prompt.',
                ),
                'client_id' => array(
                    'title'       => 'Kopokopo Client ID',
                    'type'        => 'text',
                ),
                'client_secret' => array(
                    'title'       => 'Kopokopo Client Secret',
                    'type'        => 'password',
                ),
                'till_number' => array(
                    'title'       => 'Kopokopo Till Number',
                    'type'        => 'text',
                ),
                'callback_url' => array(
                    'title'       => 'Callback URL',
                    'type'        => 'text',
                    'description' => 'Publicly accessible URL for KopoKopo to send payment confirmation.',
                ),
            );
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
                       var total = '<?php echo WC()->cart->total; ?>';

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
                                   console.error('Kopokopo STK Error:', response.message);
                                   $('#kopokopo_status').text('Payment failed: ' + response.message);
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
         * When user clicks "Place Order" in WC, process payment (mark on-hold).
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', 'Awaiting Kopokopo STK push confirmation');
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
            // Retrieve phone and amount
            $phone  = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : 0;

            // Convert 07xxxxxxx -> 2547xxxxxxx
            if (substr($phone, 0, 1) === '0') {
                $phone = '254' . substr($phone, 1);
            }

            // Get OAuth token
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error(['message' => 'Authentication failed (no token).']);
            }

            // Attempt to find or create a WooCommerce order in session
            $order_id = WC()->session->get('order_id');
            if (!$order_id) {
                // Create an empty/draft order for demonstration
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

            // Do the STK push
            $response = $this->initiate_stk_push($order, $phone, $token);
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
         * Get an OAuth token from KopoKopo
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
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json'
                ],
                'timeout' => 60,
            ];

            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                // Could log error details
                return false;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (!empty($data['access_token'])) {
                return $data['access_token'];
            }
            return false;
        }

        /**
         * Send the STK push request
         */
        private function initiate_stk_push($order, $phone_number, $token)
        {
            $url = 'https://api.kopokopo.com/api/v1/incoming_payments';

            // If $order is a draft with no items, get_total() might be 0. 
            // In real usage, you'd do STK push after order is created with items.
            $body = [
                'payment_channel' => 'M-PESA STK Push',
                'till_number'     => $this->till_number,
                'subscriber'      => [
                    'phone_number' => $phone_number,
                ],
                'amount' => [
                    'currency' => 'KES',
                    'value'    => $order->get_total(), // or use $amount if you prefer
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
            if (is_wp_error($response)) {
                return false;
            }
            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
}
