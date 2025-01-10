<?php
/**
 * Plugin Name: Order Simulator for WooCommerce
 * Plugin URI: https://heavyweightdigital.co.za
 * Description: Simplify order generation and testing for WooCommerce storefronts.
 * Version: 1.0
 * Author: Byron Jacobs @ Heavyweight Digital
 * Author URI: https://heavyweightdigital.co.za
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Order_Simulator {
    private $users = array();
    public $settings = array();

    public function __construct() {
        register_activation_hook( __FILE__, array($this, 'install') );
        register_deactivation_hook( __FILE__, array($this, 'deactivate') );

        add_filter( 'cron_schedules', array($this, 'add_cron_schedule') );
        add_filter( 'woocommerce_get_settings_pages', array($this, 'settings_page') );

        add_action( '_create_order', array($this, 'create_single_order') );
        
        $this->settings = self::get_settings();
        
        add_action( 'init', array($this, 'maybe_schedule_next_order') );
        add_action( 'admin_menu', array($this, 'add_admin_menu') );
        add_action( 'admin_init', array($this, 'handle_manual_order_creation') );
    }

    public function install() {
        global $wpdb;

        $wpdb->hide_errors();
        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $sql = "CREATE TABLE {$wpdb->prefix}_names (
            id int(11) NOT NULL AUTO_INCREMENT,
            gender varchar(6) NOT NULL,
            givenname varchar(20) NOT NULL,
            surname varchar(23) NOT NULL,
            streetaddress varchar(100) NOT NULL,
            city varchar(100) NOT NULL,
            state varchar(22) NOT NULL,
            zipcode varchar(15) NOT NULL,
            country varchar(2) NOT NULL,
            countryfull varchar(100) NOT NULL,
            emailaddress varchar(100) NOT NULL,
            username varchar(25) NOT NULL,
            password varchar(25) NOT NULL,
            telephonenumber tinytext NOT NULL,
            maidenname varchar(20) NOT NULL,
            birthday varchar(10) NOT NULL,
            company varchar(70) NOT NULL,
            PRIMARY KEY  (id)
        ) $collate;";

        dbDelta( $sql );

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}_names");

        if ( $count == 0 ) {
            $lines = explode( "\n", file_get_contents( dirname(__FILE__) .'/names.sql' ) );

            foreach ( $lines as $sql )
                $wpdb->query($sql);
        }

        wp_clear_scheduled_hook( '_create_order' );
        $this->schedule_next_order();
    }

    public function deactivate() {
        wp_clear_scheduled_hook( '_create_order' );
    }

    public function add_cron_schedule( $schedules ) {
        $schedules['_random'] = array(
            'interval' => 60,
            'display'  => __('Order Simulator Random Schedule', 'wc-order-simulator')
        );
        return $schedules;
    }

    public static function get_settings() {
        $settings = get_option( 'wc_order_simulator_settings', array() );
        $defaults = array(
            'time_period' => 24,
            'orders_per_period' => 30,
            'min_order_products' => 1,
            'max_order_products' => 5,
            'create_users' => true,
            'order_completed_pct' => 40,
            'order_processing_pct' => 50,
            'order_failed_pct' => 10,
            'products' => array(),
        );
        return wp_parse_args( $settings, $defaults );
    }

    public function maybe_schedule_next_order() {
        if ( ! wp_next_scheduled( '_create_order' ) ) {
            $this->schedule_next_order();
        }
    }

    public function schedule_next_order() {
        $time_period = $this->settings['time_period'] * 3600;
        $orders_per_period = $this->settings['orders_per_period'];
        
        if ( $orders_per_period > 0 ) {
            $avg_time_between_orders = $time_period / $orders_per_period;
            $next_order_time = time() + mt_rand(1, $avg_time_between_orders * 2);
            
            wp_schedule_single_event( $next_order_time, '_create_order' );
        }
    }

    private function get_product_ids() {
        global $wpdb;
        $product_ids = $this->settings['products'];

        if ( empty( $product_ids ) ) {
            $product_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");
        }

        return $product_ids;
    }

    private function create_or_get_user() {
        return mt_rand(0, 1) ? $this->create_user() : $this->get_random_user();
    }

    private function create_user() {
        global $wpdb;

        $max_attempts = 5;
        $attempt = 0;

        do {
            $user_row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}_names ORDER BY RAND() LIMIT 1");
            
            if ($user_row === null) {
                error_log('WC Order Simulator: Failed to fetch user data from _names table.');
                return false;
            }

            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", $user_row->username));
            $attempt++;
        } while ($count > 0 && $attempt < $max_attempts);

        if ($attempt >= $max_attempts) {
            error_log('WC Order Simulator: Failed to find a unique username after ' . $max_attempts . ' attempts.');
            return false;
        }

        $user_data = array(
            'user_login' => $user_row->username,
            'user_pass' => wp_generate_password(),
            'user_email' => $user_row->emailaddress,
            'first_name' => $user_row->givenname,
            'last_name' => $user_row->surname,
            'role' => 'customer'
        );

        $user_id = wp_insert_user($user_data);

        if ( is_wp_error( $user_id ) ) {
            error_log('WC Order Simulator: Failed to create user. Error: ' . $user_id->get_error_message());
            return false;
        }

        $this->update_user_meta($user_id, $user_row);

        return $user_id;
    }

    public function create_single_order() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log('WC Order Simulator: WooCommerce class not found.');
            return;
        }

        WC()->init();
        WC()->frontend_includes();

        WC()->session = new WC_Session_Handler();
        WC()->cart = new WC_Cart();
        WC()->customer = new WC_Customer();

        WC()->countries = new WC_Countries();
        WC()->checkout = new WC_Checkout();
        WC()->order_factory = new WC_Order_Factory();
        WC()->integrations = new WC_Integrations();

        if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
            define( 'WOOCOMMERCE_CHECKOUT', true );
        }
        WC()->cart->empty_cart();

        $product_ids = $this->get_product_ids();
        if (empty($product_ids)) {
            error_log('WC Order Simulator: No products found to create an order.');
            return;
        }

        $num_products = mt_rand( $this->settings['min_order_products'], $this->settings['max_order_products'] );

        $user_id = $this->settings['create_users'] ? $this->create_or_get_user() : $this->get_random_user();

        if (!$user_id) {
            error_log('WC Order Simulator: Failed to get or create a user for the order.');
            return;
        }

        for ( $i = 0; $i < $num_products; $i++ ) {
            $product_id = $product_ids[array_rand($product_ids)];
            WC()->cart->add_to_cart( $product_id, 1 );
        }

        $order_data = $this->get_order_data($user_id);
        
        // Ensure payment method is set
        $order_data['payment_method'] = 'bacs';
        $order_data['payment_method_title'] = 'Direct Bank Transfer';

        WC()->cart->calculate_totals();

        $order_id = WC()->checkout->create_order($order_data);

        if ( $order_id ) {
            $this->update_order_meta($order_id, $user_id, $order_data);
            $this->set_order_status($order_id);
            error_log('WC Order Simulator: Order ' . $order_id . ' created successfully.');
        } else {
            error_log('WC Order Simulator: Failed to create order.');
        }

        WC()->cart->empty_cart();
        $this->schedule_next_order();
    }

    private function get_random_user() {
        if ( empty( $this->users ) ) {
            $this->users = get_users( array('role' => 'customer', 'fields' => 'ID') );
        }

        return $this->users[array_rand($this->users)];
    }

    private function get_order_data($user_id) {
        $data = array();
        $meta_keys = array(
            'billing_country', 'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
            'billing_postcode', 'billing_email', 'billing_phone',
            'shipping_country', 'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state',
            'shipping_postcode', 'shipping_email', 'shipping_phone'
        );

        foreach ( $meta_keys as $key ) {
            $data[$key] = get_user_meta($user_id, $key, true);
        }

        return $data;
    }

    private function update_order_meta($order_id, $user_id, $data) {
        update_post_meta($order_id, '_payment_method', 'bacs');
        update_post_meta($order_id, '_payment_method_title', 'Direct Bank Transfer');
        update_post_meta($order_id, '_customer_user', $user_id);

        foreach ( $data as $key => $value ) {
            update_post_meta($order_id, '_' . $key, $value);
        }

        do_action('woocommerce_checkout_order_processed', $order_id, $data);
    }


    private function set_order_status($order_id) {
        $order = wc_get_order($order_id);
        $rand = mt_rand(1, 100);
        $completed_pct = $this->settings['order_completed_pct'];
        $processing_pct = $completed_pct + $this->settings['order_processing_pct'];

        if ( $rand <= $completed_pct ) {
            $status = 'completed';
        } elseif ( $rand <= $processing_pct ) {
            $status = 'processing';
        } else {
            $status = 'failed';
        }

        $order->update_status($status);

        // Set the order date to the current time
        $current_time = current_time('mysql');
        $order->set_date_created($current_time);
        $order->save();
    }

    private function update_user_meta($user_id, $user_data) {
        $meta = array(
            'billing_country' => $user_data->country,
            'billing_first_name' => $user_data->givenname,
            'billing_last_name' => $user_data->surname,
            'billing_address_1' => $user_data->streetaddress,
            'billing_city' => $user_data->city,
            'billing_state' => $user_data->state,
            'billing_postcode' => $user_data->zipcode,
            'billing_email' => $user_data->emailaddress,
            'billing_phone' => $user_data->telephonenumber,
            'shipping_country' => $user_data->country,
            'shipping_first_name' => $user_data->givenname,
            'shipping_last_name' => $user_data->surname,
            'shipping_address_1' => $user_data->streetaddress,
            'shipping_city' => $user_data->city,
            'shipping_state' => $user_data->state,
            'shipping_postcode' => $user_data->zipcode,
            'shipping_email' => $user_data->emailaddress,
            'shipping_phone' => $user_data->telephonenumber
        );

        foreach ($meta as $key => $value) {
            update_user_meta($user_id, $key, $value);
        }
    }

    public function settings_page($settings) {
        $settings[] = include(plugin_dir_path(__FILE__) . 'class-wc-settings-order-simulator.php');
        return $settings;
    }

    public function add_admin_menu() {
        add_menu_page(
            'Order Simulator',
            'Order Simulator',
            'manage_options',
            'wc-order-simulator',
            array($this, 'admin_page'),
            'dashicons-cart',
            56
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WooCommerce Order Simulator</h1>
            <form method="post" action="">
                <?php wp_nonce_field('create_simulated_order', '_nonce'); ?>
                <p>Click the button below to manually generate a simulated order:</p>
                <input type="submit" name="create_simulated_order" class="button button-primary" value="Generate Order">
            </form>
        </div>
        <?php
    }

    public function handle_manual_order_creation() {
        if (isset($_POST['create_simulated_order']) && check_admin_referer('create_simulated_order', '_nonce')) {
            $this->create_single_order();
            add_action('admin_notices', array($this, 'order_created_notice'));
        }
    }

    public function order_created_notice() {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Simulated order has been created successfully!', 'wc-order-simulator'); ?></p>
        </div>
        <?php
    }
}

$GLOBALS['wc_order_simulator'] = new WC_Order_Simulator();

function wc_order_simulator_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-order-simulator">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wp_order_generator_settings_link');