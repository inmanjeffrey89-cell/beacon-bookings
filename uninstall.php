<?php
/**
 * Uninstall: always clears scheduled tasks and caches; removes data only when the shop asked for it.
 *
 * @package Beacon\Bookings
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'beacon_bookings_hourly' );
wp_clear_scheduled_hook( 'beacon_bookings_every_five' );
delete_option( 'beacon_bookings_flush_rewrites' );
delete_option( 'beacon_bookings_cal_ver' );

$beacon_bookings_settings = get_option( 'beacon_bookings_settings', array() );
if ( ! is_array( $beacon_bookings_settings ) || 'yes' !== ( $beacon_bookings_settings['delete_on_uninstall'] ?? 'no' ) ) {
	return;
}

global $wpdb;
$beacon_bookings_table = $wpdb->prefix . 'beacon_bookings';
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $beacon_bookings_table ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like( '_beacon_bookings_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->prefix . 'woocommerce_order_itemmeta', '_beacon_bookings_booking_id' ) );
// phpcs:enable

$beacon_bookings_resources = get_posts(
	array(
		'post_type'   => 'beacon_bk_resource',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $beacon_bookings_resources as $beacon_bookings_id ) {
	wp_delete_post( $beacon_bookings_id, true );
}

delete_option( 'beacon_bookings_settings' );
delete_option( 'beacon_bookings_db_version' );
foreach ( array( 'beacon_bookings_confirmed', 'beacon_bookings_pending', 'beacon_bookings_cancelled', 'beacon_bookings_reminder', 'beacon_bookings_new_booking' ) as $beacon_bookings_email ) {
	delete_option( 'woocommerce_' . $beacon_bookings_email . '_settings' );
}
