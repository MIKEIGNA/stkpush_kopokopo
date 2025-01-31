<?php
/**
 * Plugin Name: Kopokopo STK Push Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push with a "Pay Now" button before checkout, plus a WP REST API callback.
 * Version:     1.1.9
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
    if (!class_exists('WC_Payment_Gateway')) {
        return; // WooCommerce not active
    }

    class WC_Kopokopo_Gateway extends WC_Payment_Gateway
    {
        // Explicitly declare these properties to avoid "dynamic property" warnings in PHP 8.2+
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

            // Assign declared properties instead of creating them dynamically
            $this->enabled       = $this->get_option('enabled');
            $this->title         = $this->get_option('title');
            $this->description   = $this->get_option('description');
            $this->client_id     = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->till_number   = $this->get_option('till_number');
            // In your plugin's settings, set this callback URL to
            // e.g. https://YOURDOMAIN.com/wp-json/kopokopo/v1/kopokopo_callback
            $this->callback_url  = $this->get_option('callback_url');

            // Save admin settings
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options')
            );

            // Logging
            error_log(' jovi Registering wp_ajax_kopokopo_stk_push now...');
            $log_message = "jovi all the variables: \n" .
                " - client_id: " . $this->client_id . "\n" .
                " - client_secret: " . $this->client_secret . "\n" .
                " - till_number: " . $this->till_number . "\n" .
                " - callback_url: " . $this->callback_url;
            error_log($log_message);

            // AJAX hooks for STK push
            // If you'd rather use a method in this class, do:
            // add_action('wp_ajax_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
            // add_action('wp_ajax_nopriv_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
            add_action('wp_ajax_kopokopo_stk_push', 'my_callback');
            add_action('wp_ajax_nopriv_kopokopo_stk_push', 'my_callback');

            // REST API callback route
            add_action('rest_api_init', array($this, 'register_kopokopo_rest_routes'));
            error_log(' jovi after Registering wp_ajax_kopokopo_stk_push later...');
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
                array(
                    'methods'  => array('GET','POST'), // KopoKopo typically sends POST
                    'callback' => array($this, 'handle_kopokopo_rest_callback'),
                )
            );
        }

        /**
         * Handle the Kopokopo REST callback.
         * Returns a simple "success" JSON by default.
         */
        public function handle_kopokopo_rest_callback(\WP_REST_Request $request)
        {
            // If needed, log the data for debugging:
            error_log("jovi Kopokopo Callback Data: " . print_r($request->get_params(), true));

            // Return a success JSON response
            $response_data = array(
                'status'  => 'success',
                'message' => 'Kopokopo callback received.'
            );
            error_log('jovi Rest callback in handle_kopokopo_rest_callback success...');

            return new \WP_REST_Response($response_data, 200);
        }

        /**
         * Admin form fields
         */
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
                    'title' => 'Kopokopo Client ID',
                    'type'  => 'text',
                ),
                'client_secret' => array(
                    'title' => 'Kopokopo Client Secret',
                    'type'  => 'password',
                ),
                'till_number' => array(
                    'title' => 'Kopokopo Till Number',
                    'type'  => 'text',
                ),
                'callback_url' => array(
                    'title'       => 'Callback URL',
                    'type'        => 'text',
                    'description' => 'Example: https://YOURDOMAIN.com/wp-json/kopokopo/v1/kopokopo_callback',
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
                            $('#kopokopo_status').text('An error occurred. Please try again.' + xhr.responseText + error);
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

            error_log("jovi process_payment success... order : " . print_r($order, true));

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        // If you prefer to keep the callback as a method inside the class:
        // public function kopokopo_stk_push() { ... } 
        // Then in your constructor do:
        // add_action('wp_ajax_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
        // add_action('wp_ajax_nopriv_kopokopo_stk_push', array($this, 'kopokopo_stk_push'));
    }
}

/**
 * If you want the Ajax callback to be a standalone function, define it here.
 * But typically you'd do it as a method in the class. For demonstration only:
 */
function my_callback()
{
    // Acquire an instance of the gateway to reuse its methods:
    $gateways = WC()->payment_gateways()->payment_gateways();
    if (isset($gateways['kopokopo_stk']) && $gateways['kopokopo_stk'] instanceof WC_Kopokopo_Gateway) {
        $gateway = $gateways['kopokopo_stk'];
    } else {
        wp_send_json_error(['message' => 'Kopokopo Gateway not found.']);
    }

    // Same code you originally had in the "my_callback()" or "kopokopo_stk_push()" function
    // Retrieve phone and amount
    $phone  = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : 0;

    // Convert 07xxxxxxx -> 2547xxxxxxx
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }

    // Get OAuth token
    $token = $gateway->get_access_token();
    if (!$token) {
        wp_send_json_error(['message' => 'Authentication failed (no token).']);
    }
    error_log("jovi after kopokopo_stk_push token gotten  $token");

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
    error_log("jovi my order id  $order_id");

    // Do the STK push
    $response = $gateway->initiate_stk_push($order, $phone, $token);
    error_log("jovi response after initiate_stk_push response  " . print_r($response, true));

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
