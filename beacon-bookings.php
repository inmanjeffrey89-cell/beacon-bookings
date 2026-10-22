<?php
/**
 * Plugin Name:       Beacon Bookings – Bookings & Rentals for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/beacon-bookings/
 * Description:       Sell appointments, classes, rentals, rooms and tours as WooCommerce products with real availability, resources, persons, buffers and multi-day stays. No double bookings, no add-on sprawl.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 * Author:            Beacon Events
 * Author URI:        https://profiles.wordpress.org/beaconevents/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beacon-bookings
 *
 * @package Beacon\Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deep-sanitise a request array: every string leaf through sanitize_text_field(), keys through sanitize_key().
 * Numeric fields are cast again where they are read; this just guarantees nothing raw is ever passed around.
 */
function beacon_bookings_clean_request( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_string( $k ) ? sanitize_key( $k ) : $k ] = beacon_bookings_clean_request( $v );
		}
		return $out;
	}
	return is_string( $value ) ? sanitize_text_field( $value ) : $value;
}

define( 'BEACON_BOOKINGS_VERSION', '0.3.0' );
define( 'BEACON_BOOKINGS_FILE', __FILE__ );
define( 'BEACON_BOOKINGS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEACON_BOOKINGS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Beacon\\Bookings\\' ) ) {
			return;
		}
		$rel  = substr( $class, strlen( 'Beacon\\Bookings\\' ) );
		$dir  = BEACON_BOOKINGS_DIR . 'includes/';
		if ( 0 === strpos( $rel, 'Engine\\' ) ) {
			$dir .= 'engine/';
			$rel  = substr( $rel, 7 );
		}
		$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $rel ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Beacon\\Bookings\\Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beacon\\Bookings\\Schema', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Beacon Bookings needs WooCommerce to be installed and active.', 'beacon-bookings' ) . '</p></div>';
					}
				}
			);
			return;
		}
		Beacon\Bookings\Plugin::boot();
	},
	20
);
