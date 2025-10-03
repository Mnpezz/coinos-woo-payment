<?php
/**
 * Coinos Lightning Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Coinos_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
    }
    
    public function add_admin_menu() {
        add_options_page(
            __('Coinos Lightning Settings', 'coinos-lightning-payment'),
            __('Coinos Lightning', 'coinos-lightning-payment'),
            'manage_options',
            'coinos-lightning-settings',
            array($this, 'admin_page')
        );
    }
    
    public function init_settings() {
        register_setting('coinos_lightning_options', 'coinos_lightning_options');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Coinos Lightning Settings', 'coinos-lightning-payment'); ?></h1>
            <p><?php _e('Configure your Coinos Lightning payment gateway settings.', 'coinos-lightning-payment'); ?></p>
            
            <p><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=coinos_lightning'); ?>" class="button button-primary">
                <?php _e('Go to Payment Gateway Settings', 'coinos-lightning-payment'); ?>
            </a></p>
        </div>
        <?php
    }
}
