<?php
/**
 * Plugin Name: Query APIs
 * Plugin URI: https://omukiguy.com
 * Description: Exchange information with external APIs in WordPress
 * Author: Laurence Bahiirwa
 * Author URI: https://omukiguy.com
 * Text Domain: query-apis
 */

// If this file is accessed directly, abort!
defined( 'ABSPATH' ) or die( 'Unauthorized Access' );

function get_send_data() {
    echo '<h2>KoPoKoPo API STK Push Test</h2>';

    // -------------------------------------------------------------------------
    // STEP 1: GET THE ACCESS TOKEN FROM KOPOKOPO
    // -------------------------------------------------------------------------
    
    // Set your KoPoKoPo credentials (use secure storage in production!)
    $client_id     = 'gGWQm4hFEn5iqI_9pz_-6ki5yfZY_G7aSecF0bUMiH8';
    $client_secret = 'opQW-Q7duzJ3FnrV7d7hM21o8ixEViM-dX21q9AX5E4';

    // Build the token URL with query parameters
    $token_url = add_query_arg(
        array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret
        ),
        'https://api.kopokopo.com/oauth/token'
    );

    // Set the headers for the token request
    $token_headers = array(
        'Accept' => 'application/json',
    );

    // Make the POST request to get the token
    $token_response = wp_remote_post( $token_url, array(
        'headers' => $token_headers,
        'timeout' => 45,
    ));

    // Check for errors in the token request
    if ( is_wp_error( $token_response ) ) {
        $error_message = $token_response->get_error_message();
        echo "<p>Something went wrong with the token request: $error_message</p>";
        return;
    }

    // Retrieve and decode the response body
    $token_body = wp_remote_retrieve_body( $token_response );
    $token_data = json_decode( $token_body, true );

    if ( empty( $token_data['access_token'] ) ) {
        echo "<p>Failed to retrieve access token. Response: $token_body</p>";
        return;
    }

    $access_token = $token_data['access_token'];
    echo "<pre>Access Token: $access_token</pre>";

    // -------------------------------------------------------------------------
    // STEP 2: INITIATE THE STK PUSH REQUEST
    // -------------------------------------------------------------------------

    // Prepare the payload for the STK Push request
    $stk_payload = array(
        "payment_channel" => "M-PESA STK Push",
        "till_number"     => "K856735",
        "subscriber"      => array(
            "first_name"   => "Yonah",
            "last_name"    => "Owiti",
            "phone_number" => "0795680221",
            "email"        => "watiapi@gmail.com"
        ),
        "amount"          => array(
            "currency" => "KES",
            "value"    => 1
        ),
        "metadata"        => array(
            "customer_id" => "123456789",
            "reference"   => "123456",
            "notes"       => "Payment for invoice 12345"
        ),
        "_links"          => array(
            "callback_url" => "https://kopokopotest.oldonyo.com/wp-json/kopokopo/v1/callback"
        )
    );

    // Encode the payload as JSON
    $stk_payload_json = json_encode( $stk_payload );

    // Set headers for the STK Push request, including the Authorization header
    $stk_headers = array(
        'Authorization' => 'Bearer ' . $access_token,
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json'
    );

    // The STK Push endpoint URL
    $stk_url = "https://api.kopokopo.com/api/v1/incoming_payments";

    // Make the POST request for the STK Push
    $stk_response = wp_remote_post( $stk_url, array(
        'headers' => $stk_headers,
        'body'    => $stk_payload_json,
        'timeout' => 45,
    ));

    // Check for errors in the STK Push request
    if ( is_wp_error( $stk_response ) ) {
        $error_message = $stk_response->get_error_message();
        echo "<p>Something went wrong with the STK Push request: $error_message</p>";
        return;
    }

    // Retrieve and display the response
    $stk_response_body = wp_remote_retrieve_body( $stk_response );
    echo "<pre>STK Push Response: \n" . print_r( $stk_response_body, true ) . "</pre>";
}

/**
 * Register a custom admin menu page for testing the API calls
 */
function wpdocs_register_my_custom_menu_page() {
	add_menu_page(
		__( 'API Test Settings', 'query-apis' ),
		'API Test',
		'manage_options',
		'api-test.php',
		'get_send_data',
		'dashicons-testimonial',
		85
	);
}
add_action( 'admin_menu', 'wpdocs_register_my_custom_menu_page' );
