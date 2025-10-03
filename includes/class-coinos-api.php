<?php
/**
 * Coinos API Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Coinos_API {
    
    private $api_key;
    private $base_url;
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
        $this->base_url = 'https://coinos.io/api';
    }
    
    /**
     * Create a lightning invoice
     */
    public function create_lightning_invoice($amount, $webhook_url = '', $secret = '') {
        $data = array(
            'invoice' => array(
                'amount' => $amount,
                'type' => 'lightning'
            )
        );
        
        // Add webhook if provided
        if (!empty($webhook_url)) {
            $data['invoice']['webhook'] = $webhook_url;
        }
        
        // Add secret if provided
        if (!empty($secret)) {
            $data['invoice']['secret'] = $secret;
        }
        
        return $this->make_request('/invoice', 'POST', $data);
    }
    
    /**
     * Create a bitcoin address invoice
     */
    public function create_bitcoin_invoice($amount, $webhook_url = '', $secret = '') {
        $data = array(
            'invoice' => array(
                'amount' => $amount,
                'type' => 'bitcoin'
            )
        );
        
        // Add webhook if provided
        if (!empty($webhook_url)) {
            $data['invoice']['webhook'] = $webhook_url;
        }
        
        // Add secret if provided
        if (!empty($secret)) {
            $data['invoice']['secret'] = $secret;
        }
        
        return $this->make_request('/invoice', 'POST', $data);
    }
    
    /**
     * Get invoice details by hash/address
     */
    public function get_invoice($hash) {
        return $this->make_request('/invoice/' . $hash, 'GET');
    }
    
    /**
     * Get account details and balance
     */
    public function get_account_details() {
        return $this->make_request('/me', 'GET');
    }
    
    /**
     * Send a lightning payment
     */
    public function send_lightning_payment($payreq) {
        $data = array(
            'payreq' => $payreq
        );
        
        return $this->make_request('/payments', 'POST', $data);
    }
    
    /**
     * Send an internal payment to another user
     */
    public function send_internal_payment($amount, $username) {
        $data = array(
            'amount' => $amount,
            'username' => $username
        );
        
        return $this->make_request('/send', 'POST', $data);
    }
    
    /**
     * Send a bitcoin payment
     */
    public function send_bitcoin_payment($amount, $address) {
        $data = array(
            'amount' => $amount,
            'address' => $address
        );
        
        return $this->make_request('/bitcoin/send', 'POST', $data);
    }
    
    /**
     * Get payment history
     */
    public function get_payments($start = null, $end = null, $limit = null, $offset = null) {
        $params = array();
        
        if ($start !== null) {
            $params['start'] = $start;
        }
        if ($end !== null) {
            $params['end'] = $end;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        
        $query_string = !empty($params) ? '?' . http_build_query($params) : '';
        
        return $this->make_request('/payments' . $query_string, 'GET');
    }
    
    /**
     * Register a new user account
     */
    public function register_user($username, $password) {
        $data = array(
            'user' => array(
                'username' => $username,
                'password' => $password
            )
        );
        
        return $this->make_request('/register', 'POST', $data);
    }
    
    /**
     * Login to get auth token
     */
    public function login($username, $password) {
        $data = array(
            'username' => $username,
            'password' => $password
        );
        
        return $this->make_request('/login', 'POST', $data);
    }
    
    /**
     * Update user account settings
     */
    public function update_user($username = null, $display = null, $currency = null, $language = null) {
        $data = array();
        
        if ($username !== null) {
            $data['username'] = $username;
        }
        if ($display !== null) {
            $data['display'] = $display;
        }
        if ($currency !== null) {
            $data['currency'] = $currency;
        }
        if ($language !== null) {
            $data['language'] = $language;
        }
        
        return $this->make_request('/user', 'POST', $data);
    }
    
    /**
     * Make API request
     */
    private function make_request($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            return array(
                'success' => true,
                'data' => json_decode($response_body, true)
            );
        } else {
            // Parse error response from Coinos API
            $error_data = json_decode($response_body, true);
            $error_message = 'API Error: ' . $response_code;
            
            if ($error_data && isset($error_data['message'])) {
                $error_message = $error_data['message'];
            } elseif ($error_data && isset($error_data['error'])) {
                $error_message = $error_data['error'];
            }
            
            return array(
                'success' => false,
                'error' => $error_message,
                'response_code' => $response_code,
                'response' => $response_body
            );
        }
    }
}
