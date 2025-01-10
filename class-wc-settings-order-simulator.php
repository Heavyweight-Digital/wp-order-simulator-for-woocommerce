<?php
/**
 * WooCommerce Order Simulator Settings
 *
 * @author      Byron Jacobs @ Heavyweight Digital
 * @version     1.0
 */

 if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'WC_Settings_Order_Generator' ) ) :

class WC_Settings_Order_Generator extends WC_Settings_Page {

    public function __construct() {
        $this->id    = 'order_generator';
        $this->label = __( 'Order Generator', 'wc-order-generator' );

        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
        add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
        add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
    }

    public function get_settings() {
        $settings = array(
            array(
                'title' => __( 'Order Generator Settings', 'wc-order-generator' ),
                'type'  => 'title',
                'desc'  => '',
                'id'    => 'wp_order_generator_settings_start'
            ),
            array(
                'title'    => __( 'Time Period (hours)', 'wc-order-generator' ),
                'desc'     => __( 'The time period over which to generate orders.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_time_period',
                'default'  => '24',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Orders per Period', 'wc-order-generator' ),
                'desc'     => __( 'The number of orders to generate within the specified time period.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_orders_per_period',
                'default'  => '30',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Minimum Products per Order', 'wc-order-generator' ),
                'desc'     => __( 'The minimum number of products to add to generated orders.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_min_order_products',
                'default'  => '1',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Maximum Products per Order', 'wc-order-generator' ),
                'desc'     => __( 'The maximum number of products to add to generated orders.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_max_order_products',
                'default'  => '5',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Create User Accounts', 'wc-order-generator' ),
                'desc'     => __( 'Create new user accounts for orders', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_create_users',
                'default'  => 'yes',
                'type'     => 'checkbox'
            ),
            array(
                'title'    => __( 'Completed Order Percentage', 'wc-order-generator' ),
                'desc'     => __( 'Percentage of orders to mark as completed.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_order_completed_pct',
                'default'  => '90',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Processing Order Percentage', 'wc-order-generator' ),
                'desc'     => __( 'Percentage of orders to mark as processing.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_order_processing_pct',
                'default'  => '5',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Failed Order Percentage', 'wc-order-generator' ),
                'desc'     => __( 'Percentage of orders to mark as failed.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_order_failed_pct',
                'default'  => '5',
                'type'     => 'number',
                'css'      => 'width:100px;',
                'desc_tip' => true,
            ),
            array(
                'title'    => __( 'Products', 'wc-order-generator' ),
                'desc'     => __( 'Select specific products to use in generated orders. Leave empty to use all products.', 'wc-order-generator' ),
                'id'       => 'wp_order_generator_products',
                'default'  => '',
                'type'     => 'multiselect',
                'options'  => $this->get_products(),
                'class'    => 'wc-enhanced-select',
                'css'      => 'width: 450px;',
                'desc_tip' => true,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'wp_order_generator_settings_end'
            )
        );

        return apply_filters( 'wp_order_generator_settings', $settings );
    }

    public function output() {
        $settings = $this->get_settings();
        WC_Admin_Settings::output_fields( $settings );
    }

    public function save() {
        $settings = $this->get_settings();
        WC_Admin_Settings::save_fields( $settings );
    }

    private function get_products() {
        $products = array();
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1
        );
        $loop = new WP_Query( $args );
        while ( $loop->have_posts() ) : $loop->the_post();
            global $product;
            $products[get_the_ID()] = get_the_title();
        endwhile;
        wp_reset_query();
        return $products;
    }
}

endif;

return new WC_Settings_Order_Generator();