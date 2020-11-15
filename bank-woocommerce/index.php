<?php
 /**Plugin Name:bank
  * Text Domain: bank
 *Author:RJ
 *Description:串接某銀行的金流
 */

class bank_Cash_Flow
{
    public static $instance;

    public function __construct()
    {
        $this->include_files();
        $this->register_hooks();
    }

    public static function get_instance()
    {
        if(is_null(self::$instance))
            self::$instance = new self;
        return self::$instance;
    }

    private function include_files()
    {
        require_once (plugin_dir_path(__FILE__).'class-wc-gateway-creditcard.php');
    }

    private function register_hooks()
    {
        add_filter( 'woocommerce_payment_gateways' , array($this, 'add_gateway_bank'));
    }

    public function add_gateway_bank($methods){
        $methods[] = 'WC_Gateway_bank_CreditCard';
        return $methods;
    }


}

add_action( 'woocommerce_loaded', array('bank_Cash_Flow','get_instance'));
