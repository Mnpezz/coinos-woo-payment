<?php
/**
 * Coinos Lightning Blocks Support
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Coinos_Lightning_Gateway_Blocks_Support extends AbstractPaymentMethodType {
    
    private $gateway;
    
    protected $name = 'coinos_lightning';

    public function initialize() {
        $this->settings = get_option("woocommerce_{$this->name}_settings", array());
        
        // Get gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways[$this->name])) {
            $this->gateway = $gateways[$this->name];
        }
    }

    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-coinos-lightning-blocks-integration',
            COINOS_LIGHTNING_PLUGIN_URL . 'assets/js/blocks.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            COINOS_LIGHTNING_VERSION,
            true
        );

        return array('wc-coinos-lightning-blocks-integration');
    }

    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'icon' => plugin_dir_url(__DIR__) . 'assets/images/lightning-icon.svg',
            'supports' => array('products'),
        );
    }
}
