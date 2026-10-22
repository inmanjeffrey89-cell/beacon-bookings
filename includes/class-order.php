<?php
/**
 * Orders ↔ bookings: linking at checkout, status mapping, trash/delete, admin display.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Order {

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'link' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'item_deleted' ) );
		add_action( 'woocommerce_before_trash_order', array( __CLASS__, 'order_trashed' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'order_deleted' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'post_trashed' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'after_itemmeta' ), 10, 2 );
	}

	/** Turn cart holds into unpaid bookings tied to the order. Idempotent. */
	public static function link( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$linked = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$bid = (int) $item->get_meta( '_beacon_bookings_booking_id', true );
			if ( $bid <= 0 ) {
				continue;
			}
			$row = Repo::get( $bid );
			if ( ! $row ) {
				continue;
			}
			if ( (int) $row['order_id'] === $order->get_id() && 'hold' !== $row['status'] ) {
				continue;
			}
			if ( (int) $row['order_id'] > 0 && (int) $row['order_id'] !== $order->get_id() && 'hold' !== $row['status'] ) {
				// Already belongs to another order (double submit / stale cart): never re-link it.
				continue;
			}
			Repo::update(
				$bid,
				array(
					'status'         => 'unpaid',
					'order_id'       => $order->get_id(),
					'order_item_id'  => (int) $item_id,
					'customer_id'    => (int) $order->get_customer_id(),
					'customer_email' => (string) $order->get_billing_email(),
					'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'hold_expires'   => null,
					'cart_key'       => '',
				)
			);
			$new = Repo::get( $bid );
			do_action( 'beacon_bookings_status_changed', $new, 'hold', 'unpaid' );
			do_action( 'beacon_bookings_created', $new, $order );
			$linked++;
		}
		if ( $linked ) {
			/* translators: %d: number of bookings */
			$order->add_order_note( sprintf( _n( '%d booking reserved by Beacon Bookings.', '%d bookings reserved by Beacon Bookings.', $linked, 'beacon-bookings' ), $linked ) );
			// Free / already-paid orders never emit a later status change; map now.
			self::apply_order_status( $order );
		}
	}

	public static function status_changed( $order_id, $from, $to, $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::apply_order_status( $order );
		}
	}

	/** Map the order status onto every booking of the order. */
	public static function apply_order_status( \WC_Order $order ): void {
		$status = $order->get_status();
		foreach ( Repo::by_order( $order->get_id() ) as $row ) {
			$target = self::target_status( $status, $row );
			if ( $target && $target !== $row['status'] ) {
				Bookings::transition( $row['id'], $target, sprintf( 'Order #%d → %s', $order->get_id(), $status ) );
			}
		}
	}

	private static function target_status( string $order_status, array $row ): ?string {
		switch ( $order_status ) {
			case 'processing':
			case 'completed':
				if ( in_array( $row['status'], array( 'confirmed', 'paid', 'complete', 'pending' ), true ) ) {
					return null;
				}
				$product  = wc_get_product( $row['product_id'] );
				$requires = $product && 'yes' === $product->get_meta( '_beacon_bookings_requires_confirmation', true );
				return $requires ? 'pending' : 'paid';
			case 'on-hold':
			case 'pending':
				return in_array( $row['status'], array( 'hold', 'cancelled' ), true ) ? 'unpaid' : null;
			case 'cancelled':
			case 'refunded':
			case 'failed':
				return 'cancelled';
			default:
				return null;
		}
	}

	public static function item_deleted( $item_id ): void {
		$row = Repo::by_order_item( (int) $item_id );
		if ( $row && 'cancelled' !== $row['status'] ) {
			Bookings::transition( $row['id'], 'cancelled', 'Order item removed' );
		}
	}

	public static function order_trashed( $order_id ): void {
		foreach ( Repo::by_order( (int) $order_id ) as $row ) {
			if ( 'cancelled' !== $row['status'] ) {
				Bookings::transition( $row['id'], 'cancelled', 'Order moved to the bin' );
			}
		}
	}

	public static function order_deleted( $order_id ): void {
		foreach ( Repo::by_order( (int) $order_id ) as $row ) {
			Repo::delete( $row['id'] );
		}
	}

	/** Legacy (posts) order storage. */
	public static function post_trashed( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::order_trashed( (int) $post_id );
		}
	}

	public static function hidden_meta( array $keys ): array {
		$keys[] = '_beacon_bookings_booking_id';
		return $keys;
	}

	public static function after_itemmeta( $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$bid = (int) $item->get_meta( '_beacon_bookings_booking_id', true );
		if ( $bid <= 0 ) {
			return;
		}
		$row = Repo::get( $bid );
		if ( ! $row ) {
			return;
		}
		$labels = Repo::labels();
		printf(
			'<div class="bkb-order-item"><a href="%s">%s</a> · <span class="bkb-status bkb-status-%s">%s</span></div>',
			esc_url( admin_url( 'admin.php?page=beacon-bookings&s=' . $bid ) ),
			/* translators: %d: booking id */
			esc_html( sprintf( __( 'Booking #%d', 'beacon-bookings' ), $bid ) ),
			esc_attr( $row['status'] ),
			esc_html( $labels[ $row['status'] ] ?? $row['status'] )
		);
	}
}
