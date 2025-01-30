<?phpfi
/**
 * Plugin Name: Kopokopo STKr Push Gateway
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
                ),
            );
        }

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
                             
                                console.log('Kopokopo STK Push Success:', response); // Log the whole response
                                $('#kopokopo_status').text('Payment request sent. Please complete on your phone.');
                                $('#kopokopo_payment_status').val('1');
                                console.log('Payment request sent successfully:', response);
                            } else {
                              
                                $('#kopokopo_status').text('Payment failed: ' + response.message);
                                console.error('Error occurred:', response.message);
                            }
                        },
                        error: function(xhr, status, error) {  // Add error handler
                            console.error('AJAX Error:', status, error, xhr.responseText); // Log details
                            $('#kopokopo_status').text('An error occurred. Please try again.');
                        }
                    });
                });

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

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', 'Awaiting payment confirmation from Kopokopo STK Push.');
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
        public function kopokopo_stk_push() {
            $phone = sanitize_text_field($_POST['phone']);
            $amount = sanitize_text_field($_POST['amount']); // You might remove this later
            $phone = '254' . substr($phone, 1);
    
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error(['message' => 'Authentication failed.']);
            }
    
            // Get the WooCommerce order object (you'll need the order ID)
            // This is a simplified example. You'll need to adapt it based on how you're getting the order ID.
            // If this is called *before* checkout, you will need to create a draft order first, and then get the order object.
            $order_id = WC()->session->get('order_id'); // Example: getting from session
            if(!$order_id){
                $order = wc_create_order();
                $order_id = $order->get_id();
                WC()->session->set('order_id', $order_id);
                WC()->session->set('kopokopo_phone', $phone);
    
            }else{
                $order = wc_get_order($order_id);
            }
    
    
            if (!$order) {
                wp_send_json_error(['message' => 'Order not found.']);
            }
    
            $response = $this->initiate_stk_push($order, $phone, $token);  // Pass the $order object
    
            error_log("Kopokopo STK Push Response: " . print_r($response, true));
    
            if ($response && isset($response['data']['id'])) { // Access data correctly
                wp_send_json_success(['message' => 'STK Push sent successfully.', 'order_id' => $order_id]);
            } else {
                wp_send_json_error(['message' => 'STK Push failed.', 'response' => $response]);
            }
        }
    
        private function get_access_token() {
            $url = 'https://api.kopokopo.com/oauth/token';
            $body = json_encode(['grant_type' => 'client_credentials', 'client_id' => $this->client_id, 'client_secret' => $this->client_secret]);
            $args = ['body' => $body, 'headers' => ['Content-Type' => 'application/json']];
    
            error_log("Kopokopo Access Token Request: " . print_r($args, true));
    
            $response = wp_remote_post($url, $args);
    
            error_log("Kopokopo Access Token Response: " . print_r($response, true));
    
            if (is_wp_error($response)) {
                error_log("Kopokopo Access Token WP Error: " . $response->get_error_message());
                return false;
            }
    
            $data = json_decode(wp_remote_retrieve_body($response), true); // Decode as associative array
            if ($data) {
                error_log('Access token retrieved successfully.');
            } else {
                error_log('Failed to retrieve access token.');
            }
            return $data['access_token'] ?? false;
        }
    
        private function initiate_stk_push($order, $phone_number, $token) { // Correct parameters
            error_log('Initiating STK push...');
            $url = 'https://api.kopokopo.com/api/v1/incoming_payments'; // Correct endpoint
            $body = [
                'payment_channel' => 'M-PESA STK Push',
                'till_number' => $this->till_number,
                'subscriber' => ['phone_number' => $phone_number],
                'amount' => [
                    'currency' => 'KES',
                    'value' => $order->get_total(), // Use $order->get_total()
                ],
                '_links' => ['callback_url' => $this->callback_url],
            ];
        
            error_log('STK push body: ' . json_encode($body));
        
            $args = [
                'body' => json_encode($body),
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
            ];
        
            error_log('STK push args: ' . json_encode($args));
        
            $response = wp_remote_post($url, $args);
        
            if (is_wp_error($response)) {
                error_log('STK push request failed: ' . $response->get_error_message());
            } else {
                error_log('STK push response: ' . wp_remote_retrieve_body($response));
            }
        
            return json_decode(wp_remote_retrieve_body($response), true); // Decode as associative array
        }
    }
}
