<?php
/**
 * Plugin Name: Kopokopo STK Push Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push with a "Pay Now" button before checkout.
 * Version:     1.1.3
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
                                $('#kopokopo_status').text('Payment request sent. Please complete on your phone.');
                                $('#kopokopo_payment_status').val('1');
                            } else {
                                $('#kopokopo_status').text('Payment failed: ' + response.message);
                            }
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

        public function kopokopo_stk_push()
        {
            $phone = sanitize_text_field($_POST['phone']);
            $amount = sanitize_text_field($_POST['amount']);

            $phone = '254' . substr($phone, 1);

            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json(['success' => false, 'message' => 'Authentication failed.']);
            }

            $response = $this->initiate_stk_push($token, $phone, $amount);
            if ($response && isset($response->data->id)) {
                wp_send_json(['success' => true, 'message' => 'STK Push sent successfully.']);
            } else {
                wp_send_json(['success' => false, 'message' => 'STK Push failed.']);
            }
        }

        private function get_access_token()
        {
            $url = 'https://api.kopokopo.com/oauth/token';
            $response = wp_remote_post($url, ['body' => json_encode(['grant_type' => 'client_credentials', 'client_id' => $this->client_id, 'client_secret' => $this->client_secret]), 'headers' => ['Content-Type' => 'application/json']]);
            $data = json_decode(wp_remote_retrieve_body($response));
            return $data->access_token ?? false;
        }
    }
}
