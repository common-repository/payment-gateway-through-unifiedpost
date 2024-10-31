<?php
/*
 * Plugin Name: Payment Gateway through Unifiedpost
 * Description: Smart business payment solutions that allow your business to pay and get paid on time
 * Author: OnePix
 * Plugin URI: https://onepix.net/
 * Version: 1.0.0
 * Text Domain: payment-gateway-through-unifiedpost
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_UnifiedPayment
{
    private static $instance;
    public static $plugin_url;
    public static $gateway_id = 'unifiedpost_payment';
    public static $plugin_icon;
    public static $plugin_icon_error;
    public static $plugin_path;

    private function __construct()
    {
        self::$plugin_url = plugin_dir_url(__FILE__);
        self::$plugin_path = plugin_dir_path(__FILE__);
        self::$plugin_icon = self::$plugin_url . 'assets/images/logo.png';

        add_action('plugins_loaded', [$this, 'pluginsLoaded']);
        add_filter('woocommerce_payment_gateways', [$this, 'woocommercePaymentGateways']);
    }

    public function pluginsLoaded()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerceMissingWcNotice']);
            return;
        }

        require_once 'includes/wc-unifiedpost-payment-gateway.php';
    }

    public function woocommerceMissingWcNotice()
    {
        echo '<div class="error"><p><strong>' . sprintf('Unifiedpost payment gateway requires WooCommerce to be installed and active. You can download %s here.', '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
    }

    public function woocommercePaymentGateways($gateways)
    {
        $gateways[] = 'WC_UnifiedPayment_Gateway';
        return $gateways;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

}

WC_UnifiedPayment::getInstance();
