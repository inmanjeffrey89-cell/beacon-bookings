<?php
/**
 * Public read-only REST endpoints used by the booking form.
 *
 * GET /wp-json/beacon-bookings/v1/availability?product=ID&from=Y-m-d&to=Y-m-d&blocks=N&persons=N&resource=ID
 * GET /wp-json/beacon-bookings/v1/price?product=ID&blocks=N&persons=N&resource=ID
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'beacon-bookings/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$common = array(
			'product'  => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'blocks'   => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'persons'  => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'resource' => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route(
			self::NS,
			'/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => $common + array(
					'from' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'to'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/price',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'price' ),
				'permission_callback' => '__return_true',
				'args'                => $common + array(
					'start' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	private static function product( \WP_REST_Request $r ) {
		$product = wc_get_product( (int) $r['product'] );
		if ( ! $product || Product::TYPE !== $product->get_type() || ! $product->is_purchasable() ) {
			return new \WP_Error( 'beacon_bookings_no_product', __( 'Unknown bookable product.', 'beacon-bookings' ), array( 'status' => 404 ) );
		}
		return $product;
	}

	/** Blocks/persons/resource from the request, clamped to the product's bounds. */
	private static function req( \WC_Product $product, \WP_REST_Request $r ): array {
		$cfg     = Bookings::config( $product );
		$blocks  = (int) $r['blocks'];
		$blocks  = 'customer' === $cfg->duration_type ? min( max( $blocks ?: $cfg->min_blocks, $cfg->min_blocks ), $cfg->max_blocks ) : 1;
		$lengths = Bookings::set_lengths( $product );
		if ( $lengths && ! in_array( $blocks, $lengths, true ) ) {
			// "Only these lengths": quote and show times for the nearest length that is sold (the next one up, else the longest).
			$up     = array_values( array_filter( $lengths, static fn( $l ) => $l >= $blocks ) );
			$blocks = $up ? $up[0] : end( $lengths );
		}
		$persons = (int) $r['persons'];
		$persons = $cfg->persons_enabled() ? min( max( $persons ?: max( 1, $cfg->persons_min ), max( 1, $cfg->persons_min ) ), $cfg->persons_max ) : 1;
		$res     = (int) $r['resource'];
		if ( ! $cfg->uses_resources() || ! isset( $cfg->resources[ $res ] ) ) {
			$res = 0;
		}
		return array(
			'blocks'      => $blocks,
			'persons'     => $persons,
			'qty'         => $cfg->persons_as_qty ? $persons : 1,
			'resource_id' => $res,
		);
	}

	public static function availability( \WP_REST_Request $r ) {
		$product = self::product( $r );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$tz = wp_timezone();
		try {
			$from = new \DateTimeImmutable( (string) $r['from'], $tz );
			$to   = new \DateTimeImmutable( (string) $r['to'], $tz );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'beacon_bookings_bad_range', __( 'Invalid date range.', 'beacon-bookings' ), array( 'status' => 400 ) );
		}
		$from  = $from->setTime( 0, 0, 0 );
		$to    = $to->setTime( 0, 0, 0 );
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );
		if ( $from < $today->modify( '-1 day' ) ) {
			$from = $today->modify( '-1 day' );
		}
		if ( $to < $from ) {
			$to = $from;
		}
		if ( $to > $from->modify( '+62 days' ) ) {
			$to = $from->modify( '+62 days' );
		}
		$req      = self::req( $product, $r );
		$av       = Bookings::availability( $product );
		$existing = Bookings::existing( $product, $from, $to->modify( '+1 day' ) );
		$days     = $av->summary( $from, $to, $req['blocks'], $req['qty'], $req['resource_id'] ?: null, $existing );
		$resp     = new \WP_REST_Response(
			array(
				'product' => $product->get_id(),
				'unit'    => $av->config()->unit,
				'from'    => $from->format( 'Y-m-d' ),
				'to'      => $to->format( 'Y-m-d' ),
				'today'   => $today->format( 'Y-m-d' ),
				'days'    => $days,
			)
		);
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	public static function price( \WP_REST_Request $r ) {
		$product = self::product( $r );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$req          = self::req( $product, $r );
		$req['start'] = null;
		$start_raw    = sanitize_text_field( (string) $r->get_param( 'start' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $start_raw ) ) {
			try {
				$req['start'] = new \DateTimeImmutable( $start_raw, wp_timezone() );
			} catch ( \Exception $e ) {
				$req['start'] = null;
			}
		}
		$cost = Bookings::cost( $product, $req, $req['resource_id'] );
		$resp = new \WP_REST_Response(
			array(
				'cost' => $cost,
				'html' => wp_kses_post( wc_price( $cost ) ),
			)
		);
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}
}
