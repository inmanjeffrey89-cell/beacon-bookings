<?php
/**
 * The "bookable" WooCommerce product type.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Product extends \WC_Product {

	const TYPE = 'beacon_bookable';

	/** Meta keys we own, with defaults (used by the admin form and uninstall). */
	const META = array(
		'_beacon_bookings_unit'             => 'hour',
		'_beacon_bookings_duration'         => 1,
		'_beacon_bookings_duration_type'    => 'fixed',
		'_beacon_bookings_min_blocks'       => 1,
		'_beacon_bookings_max_blocks'       => 1,
		'_beacon_bookings_overnight'        => 'no',
		'_beacon_bookings_interval'         => 0,
		'_beacon_bookings_capacity'         => 1,
		'_beacon_bookings_buffer_before'    => 0,
		'_beacon_bookings_buffer_after'     => 0,
		'_beacon_bookings_min_notice'       => 0,
		'_beacon_bookings_max_advance'      => 0,
		'_beacon_bookings_rules'            => array(),
		'_beacon_bookings_persons_min'      => 0,
		'_beacon_bookings_persons_max'      => 0,
		'_beacon_bookings_persons_as_qty'   => 'no',
		'_beacon_bookings_persons_multiply' => 'no',
		'_beacon_bookings_count_label'      => '',
		'_beacon_bookings_duration_prices'  => array(),
		'_beacon_bookings_set_lengths_only' => 'no',
		'_beacon_bookings_assignment'       => 'none',
		'_beacon_bookings_resource_ids'     => array(),
		'_beacon_bookings_resource_label'   => '',
		'_beacon_bookings_base_cost'        => '',
		'_beacon_bookings_requires_confirmation' => 'no',
		'_beacon_bookings_customer_cancel'  => 'inherit',
	);

	public static function register(): void {
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
		add_filter( 'product_type_selector', array( __CLASS__, 'type_selector' ) );
		add_filter( 'woocommerce_product_type_query', array( __CLASS__, 'type_query' ), 10, 2 );
	}

	public static function product_class( $classname, $product_type ) {
		return self::TYPE === $product_type ? __CLASS__ : $classname;
	}

	public static function type_selector( array $types ): array {
		$types[ self::TYPE ] = __( 'Bookable product', 'beacon-bookings' );
		return $types;
	}

	/** Cheap type resolution without the taxonomy lookup once the meta exists. */
	public static function type_query( $override, $product_id ) {
		if ( false === $override && 'yes' === get_post_meta( (int) $product_id, '_beacon_bookings_is_bookable', true ) ) {
			return self::TYPE;
		}
		return $override;
	}

	public function get_type() {
		return self::TYPE;
	}

	public function is_purchasable() {
		$ok = 'publish' === $this->get_status() || current_user_can( 'edit_post', $this->get_id() );
		return apply_filters( 'woocommerce_is_purchasable', $ok, $this );
	}

	public function is_virtual() {
		return true;
	}

	public function is_sold_individually() {
		return true;
	}

	public function supports( $feature ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			return false;
		}
		return parent::supports( $feature );
	}

	public function add_to_cart_text() {
		return apply_filters( 'woocommerce_product_add_to_cart_text', __( 'Book now', 'beacon-bookings' ), $this );
	}

	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Book now', 'beacon-bookings' ), $this );
	}

	public function add_to_cart_url() {
		return apply_filters( 'woocommerce_product_add_to_cart_url', $this->get_permalink(), $this );
	}

	public function add_to_cart_description() {
		/* translators: %s: product name */
		return apply_filters( 'woocommerce_product_add_to_cart_description', sprintf( __( 'Book “%s”', 'beacon-bookings' ), $this->get_name() ), $this );
	}

	/** "From $25 / hour" style price. */
	public function get_price_html( $deprecated = '' ) {
		$price = (float) $this->get_regular_price();
		$base  = (float) $this->get_meta( '_beacon_bookings_base_cost', true );
		if ( $price <= 0 && $base <= 0 && ! Bookings::tiers( $this ) ) {
			$html = '<span class="amount">' . esc_html__( 'Free', 'beacon-bookings' ) . '</span>';
			return apply_filters( 'woocommerce_get_price_html', $html, $this );
		}
		$cfg   = Bookings::config( $this );
		$tiers = Bookings::tiers( $this );
		if ( $tiers && Bookings::set_lengths( $this ) ) {
			// "Only these lengths" (charters, dumpsters): just the lengths and their prices, "2 hours for $400 · 4 hours for $700".
			return apply_filters( 'woocommerce_get_price_html', '<span class="bkb-price">' . $this->tier_parts( $tiers, $base ) . '</span>', $this );
		}
		if ( 'customer' === $cfg->duration_type || $cfg->persons_enabled() || $base > 0 ) {
			/* translators: 1: price, 2: duration unit e.g. "1 hour" */
			$html = sprintf( __( 'From %1$s / %2$s', 'beacon-bookings' ), wc_price( max( $price, $base ) ), Bookings::unit_label( $this ) );
		} else {
			/* translators: 1: price, 2: duration unit e.g. "1 hour" */
			$html = sprintf( __( '%1$s / %2$s', 'beacon-bookings' ), wc_price( $price ), Bookings::unit_label( $this ) );
		}
		return apply_filters( 'woocommerce_get_price_html', '<span class="bkb-price">' . $html . '</span>' . ( 'customer' === $cfg->duration_type ? $this->tier_line( $tiers ) : '' ), $this );
	}

	/** " · 4 hours for $80 · 8 hours for $120" after the price, so the cheaper long rentals are visible before booking. */
	private function tier_line( array $tiers ): string {
		return $tiers ? ' <span class="bkb-tiers">· ' . $this->tier_parts( $tiers ) . '</span>' : '';
	}

	/** "4 hours for $80 · 8 hours for $120" (a base cost, when set, is included in each price). */
	private function tier_parts( array $tiers, float $base = 0.0 ): string {
		$parts = array();
		foreach ( $tiers as $t ) {
			/* translators: 1: length e.g. "4 hours", 2: price */
			$parts[] = sprintf( __( '%1$s for %2$s', 'beacon-bookings' ), Frontend::blocks_label( $this, $t['blocks'] ), wc_price( $t['cost'] + $base ) );
		}
		return implode( ' · ', $parts );
	}
}
