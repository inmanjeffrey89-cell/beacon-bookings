<?php
/**
 * Booking email (HTML). Override: yourtheme/beacon-bookings/emails/bkb-booking.php
 *
 * @var array            $booking
 * @var \WC_Product|null $product
 * @var \WC_Order|null   $order
 * @var string           $type
 * @var string           $email_heading
 * @var string           $additional_content
 * @var bool             $sent_to_admin
 * @var \WC_Email        $email
 *
 * @package Beacon\Bookings
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$beacon_bookings_labels = \Beacon\Bookings\Repo::labels();
$beacon_bookings_name   = $product ? $product->get_name() : __( 'Booking', 'beacon-bookings' );
$beacon_bookings_when   = \Beacon\Bookings\Bookings::when( $booking, $product );
?>
<?php if ( 'beacon_bookings_confirmed' === $type ) : ?>
	<p><?php esc_html_e( 'Thanks — your booking is confirmed. The details are below, and a calendar file is attached so you can add it to your calendar.', 'beacon-bookings' ); ?></p>
<?php elseif ( 'beacon_bookings_pending' === $type ) : ?>
	<p><?php esc_html_e( 'Thanks — we received your booking. We will confirm it shortly and email you again.', 'beacon-bookings' ); ?></p>
<?php elseif ( 'beacon_bookings_cancelled' === $type ) : ?>
	<p><?php esc_html_e( 'This booking has been cancelled. If you paid for it and expect a refund, we will be in touch.', 'beacon-bookings' ); ?></p>
<?php elseif ( 'beacon_bookings_reminder' === $type ) : ?>
	<p><?php esc_html_e( 'A quick reminder about your upcoming booking.', 'beacon-bookings' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'A customer has made a booking.', 'beacon-bookings' ); ?></p>
<?php endif; ?>

<table class="td" cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;margin-bottom:16px" border="1">
	<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'Booking', 'beacon-bookings' ); ?></th><td>#<?php echo (int) $booking['id']; ?> · <?php echo esc_html( $beacon_bookings_name ); ?></td></tr>
	<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'When', 'beacon-bookings' ); ?></th><td><?php echo esc_html( $beacon_bookings_when ); ?></td></tr>
	<?php if ( (int) $booking['persons'] > 1 ) : ?>
		<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'People', 'beacon-bookings' ); ?></th><td><?php echo (int) $booking['persons']; ?></td></tr>
	<?php endif; ?>
	<?php if ( (int) $booking['resource_id'] > 0 ) : ?>
		<tr><th scope="row" style="text-align:left"><?php echo esc_html( $product ? ( $product->get_meta( '_beacon_bookings_resource_label', true ) ?: __( 'Resource', 'beacon-bookings' ) ) : __( 'Resource', 'beacon-bookings' ) ); ?></th><td><?php echo esc_html( get_the_title( (int) $booking['resource_id'] ) ); ?></td></tr>
	<?php endif; ?>
	<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'Status', 'beacon-bookings' ); ?></th><td><?php echo esc_html( $beacon_bookings_labels[ $booking['status'] ] ?? $booking['status'] ); ?></td></tr>
	<?php
	/** Extra rows in the booking email table: echo <tr> markup. @param array $booking @param string $type @param bool $sent_to_admin */
	do_action( 'beacon_bookings_email_rows', $booking, $type, $sent_to_admin );
	?>
	<?php if ( $sent_to_admin ) : ?>
		<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'Customer', 'beacon-bookings' ); ?></th><td><?php echo esc_html( trim( $booking['customer_name'] . ' ' . $booking['customer_email'] ) ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $order ) : ?>
		<tr><th scope="row" style="text-align:left"><?php esc_html_e( 'Order', 'beacon-bookings' ); ?></th><td>
			<?php if ( $sent_to_admin ) : ?>
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
			<?php endif; ?>
			· <?php echo wp_kses_post( wc_price( (float) $booking['cost'] ) ); ?></td></tr>
	<?php endif; ?>
</table>
<?php if ( ! $sent_to_admin && 'beacon_bookings_cancelled' !== $type ) : ?>
	<p><a href="<?php echo esc_url( \Beacon\Bookings\Ics::download_url( $booking ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'beacon-bookings' ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
