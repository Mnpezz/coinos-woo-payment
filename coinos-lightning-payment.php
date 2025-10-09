<?php
/**
 * Plugin Name: Coinos Lightning Payment Gateway
 * Plugin URI: https://github.com/mnpezz/coinos-woo-payment
 * Description: Accept Bitcoin Lightning Network payments via Coinos API for WooCommerce
 * Version: 1.1.0
 * Author: mnpezz
 * License: GPL v2 or later
 * Text Domain: coinos-lightning-payment
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('COINOS_LIGHTNING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COINOS_LIGHTNING_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('COINOS_LIGHTNING_VERSION', '1.0.0');

add_filter('woocommerce_payment_gateways', 'add_coinos_lightning_gateway');
function add_coinos_lightning_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Coinos_Lightning';
    return $gateways;
}

add_action('plugins_loaded', 'init_coinos_lightning_gateway', 11);
function init_coinos_lightning_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>WooCommerce is not active. The Coinos Lightning Gateway plugin requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    // Include required files
    require_once COINOS_LIGHTNING_PLUGIN_PATH . 'includes/class-coinos-api.php';
    require_once COINOS_LIGHTNING_PLUGIN_PATH . 'includes/class-coinos-payment-gateway.php';
    require_once COINOS_LIGHTNING_PLUGIN_PATH . 'includes/class-coinos-admin.php';
}

// Block support
add_action('woocommerce_blocks_loaded', 'coinos_lightning_register_payment_method_type');

function coinos_lightning_register_payment_method_type() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-coinos-lightning-gateway-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_Coinos_Lightning_Gateway_Blocks_Support());
        }
    );
}

// Pass payment method data to frontend
add_filter('woocommerce_blocks_payment_method_data_registration', 'coinos_lightning_add_payment_method_data');

function coinos_lightning_add_payment_method_data($payment_method_data) {
    $gateway_settings = get_option('woocommerce_coinos_lightning_settings', array());
    
    if (!empty($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes') {
        $payment_method_data['coinos_lightning_data'] = array(
            'title' => isset($gateway_settings['title']) ? $gateway_settings['title'] : 'Bitcoin Lightning Payment',
            'description' => isset($gateway_settings['description']) ? $gateway_settings['description'] : 'Pay with Bitcoin Lightning Network via Coinos',
            'icon' => COINOS_LIGHTNING_PLUGIN_URL . 'assets/images/lightning-icon.svg',
            'supports' => array('products'),
        );
    }
    
    return $payment_method_data;
}

// Declare compatibility
add_action('before_woocommerce_init', 'coinos_lightning_cart_checkout_blocks_compatibility');
function coinos_lightning_cart_checkout_blocks_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

// AJAX handler for checking payment status
add_action('wp_ajax_coinos_check_payment', 'coinos_lightning_ajax_check_payment');
add_action('wp_ajax_nopriv_coinos_check_payment', 'coinos_lightning_ajax_check_payment');

// Debug AJAX handler to check settings
add_action('wp_ajax_coinos_debug_settings', 'coinos_lightning_debug_settings');

function coinos_lightning_debug_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Check various possible option names
    $possible_options = array(
        'woocommerce_coinos_lightning_settings',
        'woocommerce_gateway_coinos_lightning_settings', 
        'woocommerce_coinos_lightning_gateway_settings',
    );
    
    echo '<h3>Coinos Lightning Settings Debug</h3>';
    
    foreach ($possible_options as $option_name) {
        $settings = get_option($option_name, array());
        echo '<h4>' . esc_html($option_name) . ':</h4>';
        echo '<pre>' . print_r($settings, true) . '</pre>';
    }
    
    // Check if gateway is instantiated and what its settings are
    $gateways = WC()->payment_gateways->payment_gateways();
    if (isset($gateways['coinos_lightning'])) {
        echo '<h4>Gateway Instance Settings:</h4>';
        echo '<pre>' . print_r($gateways['coinos_lightning']->settings, true) . '</pre>';
        echo '<h4>Gateway API Key:</h4>';
        echo '<pre>API Key: ' . (empty($gateways['coinos_lightning']->api_key) ? 'NOT SET' : 'SET (length: ' . strlen($gateways['coinos_lightning']->api_key) . ')') . '</pre>';
    }
    
    wp_die();
}

function coinos_lightning_ajax_check_payment() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'coinos_check_payment')) {
        wp_send_json_error('Invalid security token');
        return;
    }
    
    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('Invalid order');
        return;
    }
    
    // Check if order is already paid
    if ($order->is_paid()) {
        wp_send_json_success(array('paid' => true));
        return;
    }
    
    $payment_data = get_post_meta($order_id, '_coinos_payment_data', true);
    
    if (!$payment_data || empty($payment_data['invoice_hash'])) {
        wp_send_json_error('No payment data found');
        return;
    }
    
    // Get gateway settings from WooCommerce
    $gateway_settings = get_option('woocommerce_coinos_lightning_settings', array());
    $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
    
    
    if (empty($api_key)) {
        wp_send_json_error('API key not configured');
        return;
    }
    
    $coinos_api = new Coinos_API($api_key);
    
    // Check invoice status using the Coinos API
    $invoice_response = $coinos_api->get_invoice($payment_data['invoice_hash']);
    
    if ($invoice_response['success'] && !empty($invoice_response['data'])) {
        $invoice_data = $invoice_response['data'];
        
        // Check if payment has been received
        if (isset($invoice_data['received']) && $invoice_data['received'] > 0) {
            // Payment received! Mark order as paid
            $order->payment_complete();
            $order->add_order_note(__('Lightning payment completed via Coinos', 'coinos-lightning-payment'));
            
            // Clear the cart
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            
            wp_send_json_success(array('paid' => true));
        } else {
            // Payment not yet received
            
            wp_send_json_success(array(
                'paid' => false, 
                'status' => 'waiting',
                'received' => isset($invoice_data['received']) ? $invoice_data['received'] : 0,
                'amount' => isset($invoice_data['amount']) ? $invoice_data['amount'] : 0
            ));
        }
    } else if (!$invoice_response['success']) {
        // API error
        $error_msg = isset($invoice_response['error']) ? $invoice_response['error'] : 'Unknown API error';
        wp_send_json_error('API error: ' . $error_msg);
        return;
    }
    
    wp_send_json_success(array('paid' => false));
}

// Activation hook
register_activation_hook(__FILE__, 'coinos_lightning_activate');

function coinos_lightning_activate() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'coinos-lightning-payment'));
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'coinos_lightning_deactivate');

function coinos_lightning_deactivate() {
    // Clean up any scheduled events or temporary data
}
