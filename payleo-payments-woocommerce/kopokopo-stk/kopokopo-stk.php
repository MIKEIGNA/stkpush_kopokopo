<?php
/**
 * Plugin Name: Kopokopo STK Push Gateway
 * Plugin URI:  https://thekenyanprogrammer.co.ke/
 * Description: WooCommerce payment gateway using Kopokopo STK Push with a "Pay Now" button before checkout.
 * Version:     1.1.2
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
            $this->icon               = ''; // Optional: Add logo URL
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

        public function kopokopo_stk_push()
        {
            $phone = sanitize_text_field($_POST['phone']);
            $amount = sanitize_text_field($_POST['amount']);

            // Convert 07XXXXXXXX to 2547XXXXXXXX
            if (preg_match('/^07\d{8}$/', $phone)) {
                $phone = '254' . substr($phone, 1);
            } else {
                wp_send_json(['success' => false, 'message' => 'Invalid phone number format.']);
            }

            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json(['success' => false, 'message' => 'Authentication failed.']);
            }

            $response = $this->initiate_stk_push($token, $phone, $amount);
            if ($response && isset($response->data->id)) {
                wp_send_json(['success' => true, 'message' => 'STK Push sent successfully.', 'reference' => $response->data->id]);
            } else {
                wp_send_json(['success' => false, 'message' => 'STK Push failed.']);
            }
        }

        private function get_access_token()
        {
            $url = 'https://api.kopokopo.com/oauth/token';
            $body = json_encode([
                'grant_type' => 'client_credentials',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
            ]);

            $response = wp_remote_post($url, ['body' => $body, 'headers' => ['Content-Type' => 'application/json']]);
            $data = json_decode(wp_remote_retrieve_body($response));
            return $data->access_token ?? false;
        }

        private function initiate_stk_push($token, $phone, $amount)
        {
            $url = 'https://api.kopokopo.com/api/v1/incoming_payments';
            $body = json_encode([
                'payment_channel' => 'M-PESA STK Push',
                'till_number' => $this->till_number,
                'subscriber' => ['phone_number' => $phone],
                'amount' => ['currency' => 'KES', 'value' => $amount],
                '_links' => ['callback_url' => $this->callback_url]
            ]);

            $response = wp_remote_post($url, [
                'body' => $body,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            ]);

            return json_decode(wp_remote_retrieve_body($response));
        }
    }
}
