<?php
/**
 * Plugin Name: Dummy STK Payment Gateway
 * Plugin URI:  https://dummy.com/
 * Description: A demo plugin that mimics a Kopokopo-like STK push flow for WooCommerce.
 * Version:     1.0.0
 * Author:      Dummy
 * Author URI:  https://dummy.com
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Register the gateway in WooCommerce
add_filter('woocommerce_payment_gateways', 'dummy_stk_add_gateway');
function dummy_stk_add_gateway($gateways)
{
    $gateways[] = 'WC_Gateway_Dummy_STK';
    return $gateways;
}

// Initialize the gateway after WooCommerce is loaded
add_action('plugins_loaded', 'dummy_stk_init_gateway');
function dummy_stk_init_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return; // WooCommerce not active
    }

    class WC_Gateway_Dummy_STK extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id                 = 'dummy_stk';
            $this->has_fields         = true;
            $this->method_title       = 'Dummy STK Payment';
            $this->method_description = 'Mimics Kopokopo STK push with a "Pay Now" button before checkout + REST callback.';

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Set variables from settings
            $this->enabled       = $this->get_option('enabled');
            $this->title         = $this->get_option('title');
            $this->description   = $this->get_option('description');
            $this->dummy_api_key = $this->get_option('dummy_api_key'); // Just an example setting

            // Save admin settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Register AJAX actions (both logged in and logged out)
            add_action('wp_ajax_dummy_stk_push', array($this, 'dummy_stk_push'));
            add_action('wp_ajax_nopriv_dummy_stk_push', array($this, 'dummy_stk_push'));

            // Register REST route for the callback
            add_action('rest_api_init', array($this, 'register_dummy_rest_callback'));
        }

        /**
         * Admin settings
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable Dummy STK Payment',
                    'default'     => 'yes',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Payment method title user sees at checkout.',
                    'default'     => 'Dummy STK Payment',
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'default'     => 'Enter your phone number and click "Pay Now" for a fake STK push simulation.',
                ),
                'dummy_api_key' => array(
                    'title'       => 'Dummy API Key',
                    'type'        => 'text',
                    'description' => 'An example setting if you needed an API key. Not actually used here.',
                ),
            );
        }

        /**
         * Fields displayed at checkout
         */
        public function payment_fields()
        {
            ?>
            <p><?php echo esc_html($this->description); ?></p>
            <fieldset>
                <label for="dummy_phone">Phone Number <span class="required">*</span></label>
                <input type="text" id="dummy_phone" name="dummy_phone" required placeholder="07XXXXXXXX" />
                <button type="button" id="dummy_stk_pay_now" class="button">Pay Now</button>
                <p id="dummy_stk_status" style="margin-top: 10px; color: green;"></p>
                <input type="hidden" id="dummy_payment_status" name="dummy_payment_status" value="0">
            </fieldset>

            <script>
            jQuery(document).ready(function($) {
                $('#dummy_stk_pay_now').on('click', function() {
                    var phone = $('#dummy_phone').val();
                    var total = '<?php echo WC()->cart->total; ?>';

                    if (!phone || !phone.match(/^07\d{8}$/)) {
                        alert('Please enter a valid phone number (e.g., 07XXXXXXXX)');
                        return;
                    }

                    $('#dummy_stk_status').text('Sending fake STK Push...');

                    $.ajax({
                        type: 'POST',
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        data: {
                            action: 'dummy_stk_push',
                            phone: phone,
                            amount: total
                        },
                        success: function(response) {
                            if (response.success) {
                                console.log('Dummy STK Push success:', response);
                                $('#dummy_stk_status').text('Fake Payment request sent. (Check console logs.)');
                                $('#dummy_payment_status').val('1');
                            } else {
                                console.error('Dummy STK Error:', response.data ? response.data.message : 'Unknown');
                                $('#dummy_stk_status').text('Payment failed: ' + (response.data ? response.data.message : 'Unknown'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error, xhr.responseText);
                            $('#dummy_stk_status').text('An error occurred. Please try again.');
                        }
                    });
                });

                // Prevent placing the order if the user didn't do "Pay Now"
                $('form.checkout').on('submit', function(e) {
                    if ($('#dummy_payment_status').val() !== '1') {
                        e.preventDefault();
                        alert('Please complete the dummy STK push before placing the order.');
                    }
                });
            });
            </script>
            <?php
        }

        /**
         * Process payment on "Place order". For this dummy, we just mark on-hold.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // Mark order on-hold, like we’re waiting for real callback
            $order->update_status('on-hold', 'Awaiting fake STK push confirmation');
            $order->add_order_note('Dummy STK payment initiated.');

            // Possibly reduce stock, clear cart, etc.:
            // wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        /**
         * The AJAX callback that "pretends" to do STK push
         */
        public function dummy_stk_push()
        {
            // Typically we read phone & amount from $_POST
            $phone  = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : 0;

            // For demonstration, we won't do any real API call
            // We just pretend we got a token and initiated STK push
            // Log to debug if needed
            error_log("Dummy STK push triggered. Phone: $phone, Amount: $amount");

            // Return success response
            wp_send_json_success(['message' => 'Fake STK push initiated.']);
        }

        /**
         * Register our REST callback route: /wp-json/dummy/v1/callback
         */
        public function register_dummy_rest_callback()
        {
            register_rest_route('dummy/v1', '/callback', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_dummy_callback'),
                'permission_callback' => '__return_true',
            ));
        }

        /**
         * The fake callback. In a real scenario, Kopokopo would POST here.
         */
        public function handle_dummy_callback(\WP_REST_Request $request)
        {
            $body = $request->get_body();
            $data = json_decode($body, true);

            // Log to debug
            error_log("Dummy callback data: " . print_r($data, true));

            // For demonstration, we'll pretend the order is found & updated
            // In real usage, parse $data['reference'] or something similar

            // Return a success
            return new \WP_REST_Response([
                'status'  => 'success',
                'message' => 'Dummy STK callback received.'
            ], 200);
        }
    }
}
