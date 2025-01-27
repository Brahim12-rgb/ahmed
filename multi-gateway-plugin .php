<?php
/*
Plugin Name: Multi-Gateway Payment Links for WooCommerce
Description: Custom payment gateways based on product ID with support for multiple payment processors
Version: 2.1
Author: Modified from Codarab Payment
*/

if (!defined('ABSPATH')) {
    exit;
}

// Initialize the custom payment gateways
function multi_payment_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Multi_Payment_Gateway extends WC_Payment_Gateway {
        private $gateway_id;

        public function __construct($gateway_id = '') {
            $this->gateway_id = $gateway_id ?: 'payment_gateway_1';
            $this->id = 'custom_' . $this->gateway_id;
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('gateway_label'); 
            $this->method_title = $this->get_option('gateway_label');
            $this->description = $this->get_option('gateway_description') ?: 'Pay with ' . $this->get_option('gateway_label');
            $this->method_description = $this->get_option('gateway_description') ?: 'Pay with ' . $this->get_option('gateway_label');
            $this->icon = $this->get_option('logo_image');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_filter('woocommerce_gateway_icon', array($this, 'display_gateway_icon'), 10, 2);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable this payment gateway', 'woocommerce'),
                    'default' => 'yes',
                ),
                'gateway_label' => array(
                    'title'       => __('Gateway Label', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter the name for this payment gateway (e.g., Stripe, PayPal, etc.)', 'woocommerce'),
                    'default'     => 'Payment Gateway ' . str_replace('payment_gateway_', '', $this->gateway_id),
                ),
                'gateway_description' => array(
                    'title'       => __('Gateway Description', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter the description for this payment gateway (e.g., Pay with Debit & Credit Cards)', 'woocommerce'),
                    'default'     => '',
                ),
                'product_links' => array(
                    'title'       => __('Product Links', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('Enter product ID and payment link pairs. Put each pair on a new line in format: ProductID=PaymentLink', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'css'         => 'height: 200px;', // Make textarea bigger
                ),
                'logo_image' => array(
                    'title'       => __('Logo Image', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter the URL of the logo image', 'woocommerce'),
                    'default'     => '',
                ),
                'logo_size' => array(
                    'title'       => __('Logo Size', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter the logo size (e.g., 50px)', 'woocommerce'),
                    'default'     => '50px',
                ),
            );
        }

        public function process_payment($order_id) {
            $product_links = $this->get_option('product_links');
            $order_items = $this->get_order_items($order_id);

            foreach ($order_items as $item) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                
                $id_to_check = $variation_id ?: $product_id;
                $product_links_array = $this->parse_product_links($product_links);

                if (isset($product_links_array[$id_to_check])) {
                    $this->update_order_status($order_id);
                    return array(
                        'result'   => 'success',
                        'redirect' => esc_url($product_links_array[$id_to_check]),
                    );
                }
            }

            wc_add_notice(__('This payment gateway is not available for the selected products.', 'woocommerce'), 'error');
            return;
        }

        private function parse_product_links($product_links) {
            $parsed_links = array();
            if (!empty($product_links)) {
                // Split by newlines instead of commas
                $links_array = preg_split('/\r\n|\r|\n/', $product_links);
                foreach ($links_array as $link) {
                    $link = trim($link);
                    if (empty($link)) continue;
                    
                    list($product_id, $payment_link) = array_map('trim', explode('=', $link, 2));
                    if (!empty($product_id) && !empty($payment_link)) {
                        $parsed_links[$product_id] = $payment_link;
                    }
                }
            }
            return $parsed_links;
        }

        private function get_order_items($order_id) {
            return wc_get_order($order_id)->get_items();
        }

        private function update_order_status($order_id) {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', __('Awaiting payment confirmation.', 'woocommerce'));
        }

        public function display_gateway_icon($icon, $gateway_id) {
            if ($gateway_id === $this->id && $this->icon) {
                $logo_size = $this->get_option('logo_size');
                $icon = '<img src="' . esc_url($this->icon) . '" alt="' . esc_attr($this->get_title()) . '" style="max-width: ' . esc_attr($logo_size) . ';" />';
            }
            return $icon;
        }
    }

    // Initialize multiple payment gateways
    function add_multi_payment_gateways($methods) {
        $gateway_count = get_option('multi_gateway_count', 1);
        for ($i = 1; $i <= $gateway_count; $i++) {
            $methods[] = new WC_Multi_Payment_Gateway("payment_gateway_$i");
        }
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_multi_payment_gateways');

    // Add admin menu for managing gateways
    function multi_gateway_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Payment Gateways Manager',
            'Payment Gateways Manager',
            'manage_options',
            'multi-gateway-manager',
            'multi_gateway_manager_page'
        );
    }
    add_action('admin_menu', 'multi_gateway_admin_menu');

    // Admin page for managing number of gateways
    function multi_gateway_manager_page() {
        if (isset($_POST['gateway_count'])) {
            $count = intval($_POST['gateway_count']);
            if ($count > 0) {
                update_option('multi_gateway_count', $count);
                echo '<div class="updated"><p>Number of payment gateways updated. Please refresh the page.</p></div>';
            }
        }

        $current_count = get_option('multi_gateway_count', 1);
        ?>
        <div class="wrap">
            <h2>Payment Gateways Manager</h2>
            <form method="post">
                <label for="gateway_count">Number of Payment Gateways:</label>
                <input type="number" name="gateway_count" id="gateway_count" value="<?php echo esc_attr($current_count); ?>" min="1">
                <input type="submit" class="button button-primary" value="Update">
            </form>
            <p>After updating the number of gateways, go to WooCommerce → Settings → Payments to configure each gateway.</p>
        </div>
        <?php
    }
}

add_action('plugins_loaded', 'multi_payment_gateway_init');
?>