<?php
/**
 * Cart integration: validation, holds, pricing, display, order line items.
 *
 * A booking in a cart is a HOLD row in the bookings table. It counts against capacity while it lives,
 * is refreshed whenever the cart is looked at, and is deleted when the item is removed or the hold
 * expires unrenewed. Checkout turns the hold into an unpaid booking linked to the order.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Cart {

	/** @var array|null Request values for the current add-to-cart (classic POST or Store API). */
	private static $input = null;

	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'item_removed' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity_html' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 3 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( __CLASS__, 'store_api_input' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', array( __CLASS__, 'allow_multiple_bookings' ), 10, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'no_order_again' ), 10, 2 );
	}

	/** Booking form values from whichever channel is adding to the cart. */
	private static function input(): array {
		if ( null !== self::$input ) {
			return self::$input;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart form carries no nonce; every value is validated by the engine.
		$keys = array( 'beacon_bookings_start', 'beacon_bookings_blocks', 'beacon_bookings_persons', 'beacon_bk_resource' );
		$out  = array();
		foreach ( $keys as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			} elseif ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$out[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		// phpcs:enable
		self::$input = $out;
		return $out;
	}

	/** Store API: /wc/store/v1/cart/add-item with beacon_bookings_* params. */
	public static function store_api_input( array $data, $request ): array {
		$out = array();
		foreach ( array( 'beacon_bookings_start', 'beacon_bookings_blocks', 'beacon_bookings_persons', 'beacon_bk_resource' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		if ( $out ) {
			self::$input = $out;
		}
		return $data;
	}

	private static function bookable( int $product_id ): ?\WC_Product {
		$product = wc_get_product( $product_id );
		return ( $product && Product::TYPE === $product->get_type() ) ? $product : null;
	}

	public static function validate( $passed, $product_id, $quantity ) {
		$product = self::bookable( (int) $product_id );
		if ( ! $product ) {
			return $passed;
		}
		$req = Bookings::request( $product, self::input() );
		if ( ! $req ) {
			wc_add_notice( __( 'Please choose a date and time.', 'beacon-bookings' ), 'error' );
			return false;
		}
		$err = Bookings::persons_error( $product, $req['persons'] );
		if ( '' !== $err ) {
			wc_add_notice( $err, 'error' );
			return false;
		}
		$res = Bookings::check( $product, $req );
		if ( ! $res->ok ) {
			wc_add_notice( Bookings::reason_text( $res->reason ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$product = self::bookable( (int) $product_id );
		if ( ! $product ) {
			return $cart_item_data;
		}
		$req = Bookings::request( $product, self::input() );
		if ( ! $req ) {
			throw new \Exception( esc_html__( 'Please choose a date and time.', 'beacon-bookings' ) );
		}
		$cart_key = wp_generate_password( 32, false, false );
		list( $id, $res ) = Bookings::hold( $product, $req, $cart_key );
		if ( $id <= 0 ) {
			throw new \Exception( esc_html( Bookings::reason_text( $res->reason ) ) );
		}
		$row                    = Repo::get( $id );
		$cart_item_data['bkb'] = array(
			'booking_id' => $id,
			'cart_key'   => $cart_key,
			'cost'       => (float) $row['cost'],
			/**
			 * Filter the amount charged at checkout for a booking (e.g. a deposit). The booking keeps its full cost.
			 *
			 * @param float       $charge  Amount to charge now.
			 * @param array       $row     Booking row.
			 * @param \WC_Product $product Bookable product.
			 */
			'charge'     => (float) apply_filters( 'beacon_bookings_cart_charge', (float) $row['cost'], $row, $product ),
			'label'      => Bookings::describe( $row, $product ),
		);
		return $cart_item_data;
	}

	public static function from_session( $cart_item, $values ) {
		if ( isset( $values['bkb'] ) ) {
			$cart_item['bkb'] = $values['bkb'];
		}
		return $cart_item;
	}

	/** Runs on cart/checkout views: renew holds, drop bookings whose slot vanished. */
	public static function check_cart_items(): void {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['bkb']['booking_id'] ) ) {
				continue;
			}
			if ( ! Bookings::touch_hold( (int) $item['bkb']['booking_id'] ) ) {
				WC()->cart->remove_cart_item( $key );
				/* translators: 1: product name, 2: booking date/time */
				wc_add_notice( sprintf( __( '“%1$s” for %2$s is no longer available and was removed from your cart.', 'beacon-bookings' ), $item['data']->get_name(), $item['bkb']['label'] ), 'error' );
			}
		}
	}

	public static function set_prices( $cart ): void {
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['bkb']['cost'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( (float) ( $item['bkb']['charge'] ?? $item['bkb']['cost'] ) );
			}
		}
	}

	public static function item_data( $data, $cart_item ) {
		if ( ! empty( $cart_item['bkb']['label'] ) ) {
			$data[] = array(
				'key'   => __( 'Booking', 'beacon-bookings' ),
				'value' => wc_clean( $cart_item['bkb']['label'] ),
			);
		}
		return $data;
	}

	public static function item_removed( $key, $cart ): void {
		$item = $cart->removed_cart_contents[ $key ] ?? null;
		if ( ! empty( $item['bkb']['booking_id'] ) ) {
			$row = Repo::get( (int) $item['bkb']['booking_id'] );
			if ( $row && 'hold' === $row['status'] ) {
				Repo::delete( $row['id'] );
			}
		}
	}

	public static function quantity_html( $html, $key, $item ) {
		if ( ! empty( $item['bkb'] ) ) {
			return '<span class="bkb-qty">1</span>';
		}
		return $html;
	}

	/** Two different bookings of the same product may coexist in one cart. */
	public static function allow_multiple_bookings( $found, $product_id ) {
		return self::bookable( (int) $product_id ) ? false : $found;
	}

	public static function no_order_again( $data, $item ) {
		if ( $item instanceof \WC_Order_Item_Product && $item->get_meta( '_beacon_bookings_booking_id', true ) ) {
			throw new \Exception( esc_html__( 'Bookings cannot be reordered — please book a new date.', 'beacon-bookings' ) );
		}
		return $data;
	}

	public static function order_line_item( $item, $cart_item_key, $values ): void {
		if ( empty( $values['bkb']['booking_id'] ) ) {
			return;
		}
		$item->add_meta_data( '_beacon_bookings_booking_id', (int) $values['bkb']['booking_id'], true );
		$item->add_meta_data( __( 'Booking', 'beacon-bookings' ), wc_clean( $values['bkb']['label'] ), true );
		/**
		 * Fires after the booking meta is added to an order line item.
		 *
		 * @param \WC_Order_Item_Product $item   Line item.
		 * @param array                  $values Cart item values (bkb key holds booking_id, cost, charge, label).
		 */
		do_action( 'beacon_bookings_order_line_item', $item, $values );
	}
}
