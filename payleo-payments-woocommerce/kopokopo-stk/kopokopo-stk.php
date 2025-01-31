<?php
/**
 * Plugin Name: Kopokopo STK Push REST API Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push via REST API.
 * Version:     1.3.0
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
// 2. Initialize the gateway after WooCommerce is loaded
// ---------------------------------------------------------------------------
add_action('plugins_loaded', 'init_kopokopo_gateway');
function init_kopokopo_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Kopokopo_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id                 = 'kopokopo_stk';
            $this->method_title       = 'Kopokopo STK Push';
            $this->method_description = 'Pay via Kopokopo STK Push via REST API.';
            $this->has_fields         = false;

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled       = $this->get_option('enabled');
            $this->client_id     = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->till_number   = $this->get_option('till_number');
            $this->callback_url  = $this->get_option('callback_url');

            // Save admin settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

            // Register REST API routes
            add_action('rest_api_init', [$this, 'register_kopokopo_rest_routes']);
        }

        /**
         * Register REST API Routes
         */
        public function register_kopokopo_rest_routes()
        {
            register_rest_route('kopokopo/v1', '/initiate-stk', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_stk_push_request'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('kopokopo/v1', '/callback', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_kopokopo_callback'],
                'permission_callback' => '__return_true',
            ]);
        }

        /**
         * Handle STK Push Request via REST API
         */
        public function handle_stk_push_request(\WP_REST_Request $request)
        {
            $params = $request->get_json_params();
            $phone = sanitize_text_field($params['phone']);
            $amount = sanitize_text_field($params['amount']);

            if (!preg_match('/^07\d{8}$/', $phone)) {
                return new \WP_REST_Response(['status' => 'error', 'message' => 'Invalid phone number.'], 400);
            }

            $phone = '254' . substr($phone, 1);
            $token = $this->get_access_token();

            if (!$token) {
                return new \WP_REST_Response(['status' => 'error', 'message' => 'Authentication failed.'], 401);
            }

            $response = $this->initiate_stk_push($phone, $amount, $token);
            return new \WP_REST_Response($response, 200);
        }

        /**
         * Handle Kopokopo Callback
         */
        public function handle_kopokopo_callback(\WP_REST_Request $request)
        {
            $data = $request->get_json_params();
            error_log("Kopokopo Callback Received: " . print_r($data, true));

            return new \WP_REST_Response(['status' => 'success', 'message' => 'Callback received.'], 200);
        }

        /**
         * Get Kopokopo Access Token
         */
        private function get_access_token()
        {
            $url = 'https://api.kopokopo.com/oauth/token';
            $body = [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ];

            $response = wp_remote_post($url, [
                'body'    => http_build_query($body),
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response)) {
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            return $data['access_token'] ?? false;
        }

        /**
         * Initiate Kopokopo STK Push
         */
        private function initiate_stk_push($phone, $amount, $token)
        {
            $url = 'https://api.kopokopo.com/api/v1/incoming_payments';
            $body = [
                'payment_channel' => 'M-PESA STK Push',
                'till_number'     => $this->till_number,
                'subscriber'      => ['phone_number' => $phone],
                'amount'          => ['currency' => 'KES', 'value' => $amount],
                '_links'          => ['callback_url' => $this->callback_url],
            ];

            $response = wp_remote_post($url, [
                'body'    => json_encode($body),
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
            ]);

            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }
}
