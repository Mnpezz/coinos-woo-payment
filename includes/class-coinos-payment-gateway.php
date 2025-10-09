<?php
/**
 * Coinos Lightning Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Coinos_Lightning extends WC_Payment_Gateway {
    
    private $coinos_api;
    
    public function __construct() {
        $this->id = 'coinos_lightning';
        $this->icon = COINOS_LIGHTNING_PLUGIN_URL . 'assets/images/lightning-icon.svg';
        $this->has_fields = false;
        $this->method_title = __('Coinos Lightning Payment', 'coinos-lightning-payment');
        $this->method_description = __('Accept Bitcoin Lightning Network payments via Coinos API', 'coinos-lightning-payment');
        
        $this->supports = array('products');
        
        // Load the settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->api_key = $this->get_option('api_key');
        
        // Initialize Coinos API
        if (!empty($this->api_key)) {
            $this->coinos_api = new Coinos_API($this->api_key);
        }
        
        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        
        // Handle webhooks
        add_action('woocommerce_api_coinos_lightning_webhook', array($this, 'handle_webhook'));
    }
    
    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'coinos-lightning-payment'),
                'type' => 'checkbox',
                'label' => __('Enable Coinos Lightning Payment', 'coinos-lightning-payment'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'coinos-lightning-payment'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'coinos-lightning-payment'),
                'default' => __('Bitcoin Lightning Payment', 'coinos-lightning-payment'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'coinos-lightning-payment'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'coinos-lightning-payment'),
                'default' => __('Pay with Bitcoin Lightning Network via Coinos', 'coinos-lightning-payment'),
            ),
            'api_key' => array(
                'title' => __('Coinos API Key', 'coinos-lightning-payment'),
                'type' => 'password',
                'description' => __('Enter your Coinos API key (JWT token)', 'coinos-lightning-payment'),
                'default' => '',
                'desc_tip' => true,
            ),
        );
    }
    
    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        // Use the same QR library as your NanoPay plugin
        wp_enqueue_script('qrcode', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', array(), '1.0.0', true);
        wp_enqueue_script('coinos-lightning', COINOS_LIGHTNING_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), COINOS_LIGHTNING_VERSION, true);
        wp_enqueue_style('coinos-lightning', COINOS_LIGHTNING_PLUGIN_URL . 'assets/css/frontend.css', array(), COINOS_LIGHTNING_VERSION);
        
        // Localize script for AJAX
        wp_localize_script('coinos-lightning', 'coinos_lightning_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('coinos_check_payment')
        ));
    }
    
    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'result' => 'failure',
                'redirect' => wc_get_checkout_url()
            );
        }
        
        // Set order status to pending payment (crucial for Lightning payments)
        $order->update_status('pending', __('Awaiting Lightning payment', 'coinos-lightning-payment'));
        
        // Clear any existing payment data to start fresh
        delete_post_meta($order_id, '_coinos_payment_data');
        
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }
    
    /**
     * Receipt page - shows payment form
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        
        // Only skip rendering if order is already paid
        if ($order->is_paid()) {
            echo '<p>' . __('This order has already been paid.', 'coinos-lightning-payment') . '</p>';
            return;
        }
        
        // Check if we've already rendered for this specific request to prevent duplicates
        static $rendered_orders = array();
        if (in_array($order_id, $rendered_orders)) {
            return;
        }
        $rendered_orders[] = $order_id;
        
        echo '<p>' . __('Please complete your payment using Bitcoin Lightning Network.', 'coinos-lightning-payment') . '</p>';
        $this->generate_coinos_lightning_form($order);
    }
    
    /**
     * Generate the Coinos Lightning payment form
     */
    public function generate_coinos_lightning_form($order) {
        $amount = $order->get_total();
        $currency = $order->get_currency();
        $order_id = $order->get_id();
        
        // Create fresh payment data using Coinos API
        $payment_data = $this->create_fresh_payment_data($order);
        
        if (!$payment_data) {
            echo '<p>' . __('Error creating payment. Please contact support.', 'coinos-lightning-payment') . '</p>';
            return;
        }
        
        $payment_text = $payment_data['payment_text'];
        $usd_amount = $payment_data['usd_amount'];
        
        ?>
        <div id="coinos-lightning-payment" class="coinos-lightning-payment-container">
            <h3><?php _e('Pay with Bitcoin Lightning', 'coinos-lightning-payment'); ?></h3>
            
            <div class="payment-amounts">
                <p><strong><?php _e('Amount to Pay:', 'coinos-lightning-payment'); ?></strong></p>
                <p class="btc-amount"><?php echo number_format($payment_data['satoshis'], 0); ?> satoshis</p>
                <p class="usd-amount"><?php echo $usd_amount; ?> USD</p>
            </div>
            
            <div class="payment-methods">
                <div class="qr-code-section">
                    <h4><?php _e('Scan QR Code', 'coinos-lightning-payment'); ?></h4>
                    <div id="lightning-qr-code" data-payment="<?php echo esc_attr($payment_text); ?>"></div>
                    <p class="qr-instructions"><?php _e('Scan this QR code with your Lightning wallet', 'coinos-lightning-payment'); ?></p>
                </div>
                
                <div class="lightning-address-section">
                    <h4><?php _e('Lightning Invoice', 'coinos-lightning-payment'); ?></h4>
                    <div class="invoice-container">
                        <textarea id="lightning-invoice" readonly><?php echo esc_textarea($payment_text); ?></textarea>
                        <button id="copy-invoice" class="button"><?php _e('Copy Invoice', 'coinos-lightning-payment'); ?></button>
                    </div>
                </div>
            </div>
            
            <div class="payment-status">
                <p id="payment-status-message"><?php _e('Waiting for payment...', 'coinos-lightning-payment'); ?></p>
            </div>
            
            <div class="payment-actions">
                <a href="<?php echo wc_get_cart_url(); ?>" class="button"><?php _e('Cancel Order', 'coinos-lightning-payment'); ?></a>
            </div>
            
            <!-- Hidden data for JavaScript -->
            <div data-order-id="<?php echo $order->get_id(); ?>" data-nonce="<?php echo wp_create_nonce('coinos_check_payment'); ?>" style="display: none;"></div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // Generate QR code using the same library as NanoPay
            const paymentText = '<?php echo esc_js($payment_text); ?>';
            const qrContainer = document.getElementById('lightning-qr-code');
            
            if (typeof QRCode !== 'undefined') {
                new QRCode(qrContainer, {
                    text: paymentText,
                    width: 256,
                    height: 256,
                    colorDark: '#000000',
                    colorLight: '#ffffff'
                });
            }
            
            // Copy invoice functionality
            document.getElementById('copy-invoice').addEventListener('click', function() {
                const invoiceText = document.getElementById('lightning-invoice');
                invoiceText.select();
                document.execCommand('copy');
                this.textContent = '<?php _e('Copied!', 'coinos-lightning-payment'); ?>';
                setTimeout(() => {
                    this.textContent = '<?php _e('Copy Invoice', 'coinos-lightning-payment'); ?>';
                }, 2000);
            });
            
            // Auto-check payment status every 10 seconds
            let checkInterval = setInterval(function() {
                fetch(coinos_lightning_ajax.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=coinos_check_payment&order_id=<?php echo $order->get_id(); ?>&nonce=' + coinos_lightning_ajax.nonce
                })
                .then(response => {
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.data && data.data.paid === true) {
                        document.getElementById('payment-status-message').textContent = '<?php _e('Payment received! Redirecting...', 'coinos-lightning-payment'); ?>';
                        clearInterval(checkInterval);
                        setTimeout(() => {
                            window.location.href = '<?php echo $order->get_checkout_order_received_url(); ?>';
                        }, 2000);
                    } else if (data.data && data.data.status === 'waiting') {
                        document.getElementById('payment-status-message').textContent = '<?php _e('Waiting for payment...', 'coinos-lightning-payment'); ?>';
                    }
                })
                .catch(error => {
                    console.error('Error checking payment:', error);
                });
            }, 10000); // Check every 10 seconds
        });
        </script>
        <?php
    }
    
    /**
     * Create fresh payment data using Coinos API
     */
    private function create_fresh_payment_data($order) {
        $amount = $order->get_total();
        $currency = $order->get_currency();
        
        if (empty($this->api_key) || !$this->coinos_api) {
            return false;
        }
        
        $webhook_url = add_query_arg(array(
            'wc-api' => 'coinos_lightning_webhook',
            'order_id' => $order->get_id()
        ), home_url('/'));
        
        $satoshis = $this->convert_to_satoshis($amount, $currency);
        
        $invoice_response = $this->coinos_api->create_lightning_invoice($satoshis, $webhook_url, 'order_' . $order->get_id(), $currency, false);
        
        if (!$invoice_response['success']) {
            return false;
        }
        
        $invoice_data = $invoice_response['data'];
        
        // Cache Coinos rate for future use
        if (isset($invoice_data['rate']) && $invoice_data['rate'] > 0) {
            set_transient('coinos_btc_price', floatval($invoice_data['rate']), 300);
        }
        
        $actual_satoshis = isset($invoice_data['amount']) ? $invoice_data['amount'] : 0;
        
        $payment_data = array(
            'invoice_hash' => $invoice_data['hash'],
            'payment_text' => $invoice_data['text'],
            'btc_amount' => $actual_satoshis / 100000000,
            'satoshis' => $actual_satoshis,
            'usd_amount' => $amount,
            'currency' => $invoice_data['currency'],
            'created_at' => time()
        );
        
        update_post_meta($order->get_id(), '_coinos_payment_data', $payment_data);
        
        return $payment_data;
    }
    
    /**
     * Convert USD amount to satoshis using current BTC price
     */
    private function convert_to_satoshis($amount, $currency) {
        if ($currency === 'USD') {
            $btc_price = $this->get_btc_price();
            
            if ($btc_price > 0) {
                $btc_amount = $amount / $btc_price;
                return intval($btc_amount * 100000000);
            }
        }
        
        return intval($amount);
    }
    
    /**
     * Get current BTC/USD price
     * Uses cached rate from Coinos invoice or fetches from CoinGecko
     */
    private function get_btc_price() {
        $cached_price = get_transient('coinos_btc_price');
        if ($cached_price !== false) {
            return floatval($cached_price);
        }
        
        $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd', array(
            'timeout' => 5
        ));
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['bitcoin']['usd'])) {
                $btc_price = floatval($data['bitcoin']['usd']);
                set_transient('coinos_btc_price', $btc_price, 300);
                return $btc_price;
            }
        }
        
        return 120000;
    }
    
    /**
     * Handle webhook from Coinos
     */
    public function handle_webhook() {
        $raw_body = file_get_contents('php://input');
        $data = json_decode($raw_body, true);
        
        if (!$data || !isset($data['hash'])) {
            status_header(400);
            exit;
        }
        
        $invoice_hash = $data['hash'];
        $received_amount = isset($data['received']) ? $data['received'] : 0;
        
        $order = $this->find_order_by_invoice_hash($invoice_hash);
        
        if ($order && !$order->is_paid() && $received_amount > 0) {
            $order->payment_complete();
            $order->add_order_note(__('Lightning payment completed via Coinos webhook', 'coinos-lightning-payment'));
        }
        
        status_header(200);
        exit;
    }
    
    /**
     * Find order by invoice hash
     */
    private function find_order_by_invoice_hash($invoice_hash) {
        $orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_query' => array(
                array(
                    'key' => '_coinos_payment_data',
                    'value' => $invoice_hash,
                    'compare' => 'LIKE'
                )
            )
        ));
        
        if (!empty($orders)) {
            return wc_get_order($orders[0]->ID);
        }
        
        return null;
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        if (empty($this->api_key)) {
            return array('success' => false, 'error' => 'API key not set');
        }
        
        if (!$this->coinos_api) {
            return array('success' => false, 'error' => 'API not initialized');
        }
        
        // Test with a simple API call
        $response = $this->coinos_api->get_account_details();
        
        if ($response['success']) {
            return array('success' => true, 'data' => 'API connection successful');
        } else {
            return array('success' => false, 'error' => $response['error']);
        }
    }
}
