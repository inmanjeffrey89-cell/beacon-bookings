<?php
/**
 * My Account → Bookings: list, .ics download, customer cancellation.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Account {

	const ENDPOINT = 'bookings';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_cancel' ) );
	}

	public static function endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function title(): string {
		return __( 'Bookings', 'beacon-bookings' );
	}

	public static function menu_item( array $items ): array {
		$out = array();
		foreach ( $items as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'orders' === $k ) {
				$out[ self::ENDPOINT ] = __( 'Bookings', 'beacon-bookings' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Bookings', 'beacon-bookings' );
		}
		return $out;
	}

	/** All live bookings of the current user (by account id, plus guest bookings on the same email). */
	public static function mine(): array {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return array();
		}
		list( $a ) = Repo::query( array( 'customer_id' => $user->ID, 'per_page' => 200, 'orderby' => 'start_utc', 'order' => 'DESC' ) );
		list( $b ) = Repo::query( array( 'email' => $user->user_email, 'per_page' => 200, 'orderby' => 'start_utc', 'order' => 'DESC' ) );
		$rows = array();
		foreach ( array_merge( $a, $b ) as $r ) {
			$rows[ $r['id'] ] = $r;
		}
		usort( $rows, static fn( $x, $y ) => strcmp( $y['start_utc'], $x['start_utc'] ) );
		return $rows;
	}

	public static function can_cancel( array $row ): bool {
		if ( ! in_array( $row['status'], array( 'unpaid', 'pending', 'confirmed', 'paid' ), true ) ) {
			return false;
		}
		$product = wc_get_product( $row['product_id'] );
		$policy  = $product ? (string) $product->get_meta( '_beacon_bookings_customer_cancel', true ) : 'inherit';
		if ( 'no' === $policy ) {
			return false;
		}
		if ( 'inherit' === $policy && 'yes' !== Plugin::setting( 'customer_cancel' ) ) {
			return false;
		}
		$hours = (int) Plugin::setting( 'cancel_hours' );
		return strtotime( $row['start_utc'] . ' UTC' ) - time() >= $hours * 3600;
	}

	public static function render(): void {
		$rows   = self::mine();
		$labels = Repo::labels();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$up     = array_filter( $rows, static fn( $r ) => $r['end_utc'] > $now && 'cancelled' !== $r['status'] );
		$past   = array_filter( $rows, static fn( $r ) => $r['end_utc'] <= $now || 'cancelled' === $r['status'] );
		wc_print_notices();
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'You have no bookings yet.', 'beacon-bookings' ) . '</p>';
			return;
		}
		foreach ( array( __( 'Upcoming', 'beacon-bookings' ) => $up, __( 'Past & cancelled', 'beacon-bookings' ) => $past ) as $heading => $list ) :
			if ( empty( $list ) ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $heading ); ?></h3>
			<table class="woocommerce-orders-table shop_table shop_table_responsive bkb-account-table">
				<thead><tr>
					<th><?php esc_html_e( 'Booking', 'beacon-bookings' ); ?></th>
					<th><?php esc_html_e( 'When', 'beacon-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'beacon-bookings' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'beacon-bookings' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $list as $r ) : ?>
					<?php $product = wc_get_product( $r['product_id'] ); ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Booking', 'beacon-bookings' ); ?>">
							<?php if ( $product ) : ?><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a><?php else : ?><?php esc_html_e( 'Booking', 'beacon-bookings' ); ?><?php endif; ?>
							<br><small>#<?php echo (int) $r['id']; ?><?php echo (int) $r['persons'] > 1 ? ' · ' . esc_html( sprintf( /* translators: %d: people */ _n( '%d person', '%d people', (int) $r['persons'], 'beacon-bookings' ), (int) $r['persons'] ) ) : ''; ?><?php echo (int) $r['resource_id'] > 0 ? ' · ' . esc_html( get_the_title( (int) $r['resource_id'] ) ) : ''; ?></small>
						</td>
						<td data-title="<?php esc_attr_e( 'When', 'beacon-bookings' ); ?>"><?php echo esc_html( Bookings::when( $r, $product ?: null ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Status', 'beacon-bookings' ); ?>"><?php echo esc_html( $labels[ $r['status'] ] ?? $r['status'] ); ?><?php do_action( 'beacon_bookings_account_status', $r ); ?></td>
						<td data-title="<?php esc_attr_e( 'Actions', 'beacon-bookings' ); ?>">
							<?php if ( (int) $r['order_id'] > 0 ) : ?>
								<a class="woocommerce-button button" href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', $r['order_id'], wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Order', 'beacon-bookings' ); ?></a>
							<?php endif; ?>
							<?php if ( 'cancelled' !== $r['status'] ) : ?>
								<a class="woocommerce-button button" href="<?php echo esc_url( Ics::download_url( $r ) ); ?>"><?php esc_html_e( 'Add to calendar', 'beacon-bookings' ); ?></a>
							<?php endif; ?>
							<?php if ( self::can_cancel( $r ) ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'beacon_bookings_cancel_' . $r['id'], 'beacon_bookings_cancel_nonce' ); ?>
									<input type="hidden" name="beacon_bookings_cancel_booking" value="<?php echo (int) $r['id']; ?>">
									<button type="submit" class="woocommerce-button button" onclick="return confirm('<?php echo esc_js( __( 'Cancel this booking?', 'beacon-bookings' ) ); ?>')"><?php esc_html_e( 'Cancel', 'beacon-bookings' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endforeach;
	}

	public static function handle_cancel(): void {
		if ( empty( $_POST['beacon_bookings_cancel_booking'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = absint( $_POST['beacon_bookings_cancel_booking'] );
		if ( ! isset( $_POST['beacon_bookings_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beacon_bookings_cancel_nonce'] ) ), 'beacon_bookings_cancel_' . $id ) ) {
			return;
		}
		$row  = Repo::get( $id );
		$user = wp_get_current_user();
		$mine = $row && ( (int) $row['customer_id'] === (int) $user->ID || ( $row['customer_email'] && strtolower( $row['customer_email'] ) === strtolower( $user->user_email ) ) );
		if ( ! $mine || ! self::can_cancel( $row ) ) {
			wc_add_notice( __( 'This booking can no longer be cancelled online.', 'beacon-bookings' ), 'error' );
			return;
		}
		Bookings::transition( $id, 'cancelled', 'Cancelled by the customer' );
		if ( (int) $row['order_id'] > 0 ) {
			$order = wc_get_order( $row['order_id'] );
			if ( $order ) {
				/* translators: %d: booking id */
				$order->add_order_note( sprintf( __( 'Customer cancelled booking #%d from My Account. Refund manually if applicable.', 'beacon-bookings' ), $id ) );
			}
		}
		wc_add_notice( __( 'Your booking was cancelled.', 'beacon-bookings' ) );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
