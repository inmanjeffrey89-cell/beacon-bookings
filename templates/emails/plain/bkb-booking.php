<?php
/**
 * Booking email (plain text).
 *
 * @package Beacon\Bookings
 */

defined( 'ABSPATH' ) || exit;

$beacon_bookings_labels = \Beacon\Bookings\Repo::labels();
$beacon_bookings_name   = $product ? $product->get_name() : __( 'Booking', 'beacon-bookings' );

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html( sprintf( '#%d · %s', (int) $booking['id'], $beacon_bookings_name ) ) . "\n";
echo esc_html( __( 'When:', 'beacon-bookings' ) . ' ' . \Beacon\Bookings\Bookings::when( $booking, $product ) ) . "\n";
if ( (int) $booking['persons'] > 1 ) {
	echo esc_html( __( 'People:', 'beacon-bookings' ) . ' ' . (int) $booking['persons'] ) . "\n";
}
if ( (int) $booking['resource_id'] > 0 ) {
	echo esc_html( get_the_title( (int) $booking['resource_id'] ) ) . "\n";
}
echo esc_html( __( 'Status:', 'beacon-bookings' ) . ' ' . ( $beacon_bookings_labels[ $booking['status'] ] ?? $booking['status'] ) ) . "\n";
/** Extra plain-text lines in the booking email. */
do_action( 'beacon_bookings_email_lines', $booking, $type, $sent_to_admin );
if ( $sent_to_admin ) {
	echo esc_html( __( 'Customer:', 'beacon-bookings' ) . ' ' . trim( $booking['customer_name'] . ' ' . $booking['customer_email'] ) ) . "\n";
}
if ( $order ) {
	echo esc_html( __( 'Order:', 'beacon-bookings' ) . ' #' . $order->get_order_number() . ' · ' . wp_strip_all_tags( wc_price( (float) $booking['cost'] ) ) ) . "\n";
	echo esc_url( $sent_to_admin ? $order->get_edit_order_url() : $order->get_view_order_url() ) . "\n";
}
if ( ! $sent_to_admin && 'beacon_bookings_cancelled' !== $type ) {
	echo "\n" . esc_html__( 'Add to calendar:', 'beacon-bookings' ) . ' ' . esc_url( \Beacon\Bookings\Ics::download_url( $booking ) ) . "\n";
}
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
