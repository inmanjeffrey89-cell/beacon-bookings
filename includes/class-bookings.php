<?php
/**
 * Service layer: product meta → engine config, request parsing, checks, holds, transitions, labels.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

use Beacon\Bookings\Engine\Availability;
use Beacon\Bookings\Engine\Config;
use Beacon\Bookings\Engine\Pricing;
use Beacon\Bookings\Engine\Result;
use Beacon\Bookings\Engine\Rules;

defined( 'ABSPATH' ) || exit;

final class Bookings {

	/** @var array<int,Config> */
	private static $cfg_cache = array();

	/* --------------------------------------------------------------- config */

	public static function config( \WC_Product $product ): Config {
		$pid = $product->get_id();
		if ( isset( self::$cfg_cache[ $pid ] ) ) {
			return self::$cfg_cache[ $pid ];
		}
		$m = static function ( $key, $default = '' ) use ( $product ) {
			$v = $product->get_meta( $key, true );
			return ( '' === $v || null === $v ) ? $default : $v;
		};
		$rules = $m( '_beacon_bookings_rules', array() );
		if ( ! is_array( $rules ) || empty( $rules['weekly'] ) ) {
			$rules = Rules::defaults();
		}
		$resources  = array();
		$assignment = (string) $m( '_beacon_bookings_assignment', 'none' );
		if ( 'none' !== $assignment ) {
			foreach ( (array) $m( '_beacon_bookings_resource_ids', array() ) as $rid ) {
				$rc = Resources::config( (int) $rid );
				if ( $rc ) {
					$resources[ (int) $rid ] = $rc;
				}
			}
		}
		$cfg = new Config(
			array(
				'unit'           => (string) $m( '_beacon_bookings_unit', 'hour' ),
				'duration'       => (int) $m( '_beacon_bookings_duration', 1 ),
				'duration_type'  => (string) $m( '_beacon_bookings_duration_type', 'fixed' ),
				'min_blocks'     => (int) $m( '_beacon_bookings_min_blocks', 1 ),
				'max_blocks'     => (int) $m( '_beacon_bookings_max_blocks', 1 ),
				'interval'       => (int) $m( '_beacon_bookings_interval', 0 ),
				'capacity'       => (int) $m( '_beacon_bookings_capacity', 1 ),
				'buffer_before'  => (int) $m( '_beacon_bookings_buffer_before', 0 ),
				'buffer_after'   => (int) $m( '_beacon_bookings_buffer_after', 0 ),
				'min_notice'     => (int) $m( '_beacon_bookings_min_notice', 0 ),
				'max_advance'    => (int) $m( '_beacon_bookings_max_advance', 0 ),
				'rules'          => $rules,
				'persons_as_qty' => 'yes' === $m( '_beacon_bookings_persons_as_qty', 'no' ),
				'persons_min'    => (int) $m( '_beacon_bookings_persons_min', 0 ),
				'persons_max'    => (int) $m( '_beacon_bookings_persons_max', 0 ),
				'assignment'     => $assignment,
				'resources'      => $resources,
				'overnight'      => 'yes' === $m( '_beacon_bookings_overnight', 'no' ),
			)
		);
		self::$cfg_cache[ $pid ] = $cfg;
		return $cfg;
	}

	public static function forget( int $product_id ): void {
		unset( self::$cfg_cache[ $product_id ] );
	}

	public static function availability( \WC_Product $product, ?\DateTimeImmutable $now = null ): Availability {
		return new Availability( self::config( $product ), wp_timezone(), $now );
	}

	/** Existing bookings for the engine, sized to the product's largest possible span. */
	public static function existing( \WC_Product $product, \DateTimeImmutable $from_local, \DateTimeImmutable $to_local, int $exclude_id = 0 ): array {
		$cfg  = self::config( $product );
		$pad  = $cfg->max_span_minutes() + 1;
		$from = ( new \DateTimeImmutable( '@' . ( $from_local->getTimestamp() - $pad * 60 ) ) );
		$to   = ( new \DateTimeImmutable( '@' . ( $to_local->getTimestamp() + $pad * 60 ) ) );
		return Repo::engine_bookings( $product->get_id(), $from, $to, $exclude_id, $cfg->uses_resources() ? array_keys( $cfg->resources ) : array() );
	}

	/* -------------------------------------------------------------- request */

	/**
	 * Parse untrusted input into a request. Returns null when the start cannot be parsed.
	 *
	 * @return array{start:\DateTimeImmutable,blocks:int,persons:int,qty:int,resource_id:int}|null
	 */
	public static function request( \WC_Product $product, array $input ): ?array {
		$cfg   = self::config( $product );
		$tz    = wp_timezone();
		$raw   = isset( $input['beacon_bookings_start'] ) ? trim( (string) $input['beacon_bookings_start'] ) : '';
		$start = null;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $raw ) ) {
			try {
				$start = new \DateTimeImmutable( $raw, $tz );
			} catch ( \Exception $e ) {
				$start = null;
			}
		}
		if ( ! $start ) {
			return null;
		}
		$blocks  = 'customer' === $cfg->duration_type ? (int) ( $input['beacon_bookings_blocks'] ?? $cfg->min_blocks ) : 1;
		$persons = $cfg->persons_enabled() ? (int) ( $input['beacon_bookings_persons'] ?? max( 1, $cfg->persons_min ) ) : 1;
		$persons = max( 1, $persons );
		$qty     = $cfg->persons_as_qty ? $persons : 1;
		$res     = (int) ( $input['beacon_bk_resource'] ?? 0 );
		return array(
			'start'       => $start,
			'blocks'      => max( 1, $blocks ),
			'persons'     => $persons,
			'qty'         => $qty,
			'resource_id' => $res,
		);
	}

	/** Validate persons against the product bounds. Returns an error string or ''. */
	public static function persons_error( \WC_Product $product, int $persons ): string {
		$cfg = self::config( $product );
		if ( ! $cfg->persons_enabled() ) {
			return '';
		}
		if ( $persons < max( 1, $cfg->persons_min ) || $persons > $cfg->persons_max ) {
			$label = self::count_label( $product );
			if ( '' !== $label ) {
				/* translators: 1: minimum, 2: maximum, 3: what is counted, e.g. "canoes" */
				return sprintf( __( 'Please choose between %1$d and %2$d %3$s.', 'beacon-bookings' ), max( 1, $cfg->persons_min ), $cfg->persons_max, mb_strtolower( $label ) );
			}
			/* translators: 1: minimum, 2: maximum */
			return sprintf( __( 'Please choose between %1$d and %2$d people.', 'beacon-bookings' ), max( 1, $cfg->persons_min ), $cfg->persons_max );
		}
		return '';
	}

	public static function check( \WC_Product $product, array $req, int $exclude_id = 0 ): Result {
		$lengths = self::set_lengths( $product );
		if ( $lengths && ! in_array( (int) $req['blocks'], $lengths, true ) ) {
			return Result::fail( 'duration' ); // "Only these lengths": a length without its own price isn't sold.
		}
		$av            = self::availability( $product );
		list( $s, $e ) = $av->span( $req['start'], $req['blocks'] );
		$existing      = self::existing( $product, $s, $e, $exclude_id );
		return $av->check( $req['start'], $req['blocks'], $req['qty'], $req['resource_id'] ?: null, $existing );
	}

	public static function reason_text( string $reason ): string {
		$map = array(
			'duration'  => __( 'That duration is not available for this product.', 'beacon-bookings' ),
			'qty'       => __( 'Please choose at least one.', 'beacon-bookings' ),
			'closed'    => __( 'That time is outside the available hours.', 'beacon-bookings' ),
			'past'      => __( 'That time has already passed.', 'beacon-bookings' ),
			'notice'    => __( 'That start is too soon. Please pick a later time.', 'beacon-bookings' ),
			'advance'   => __( 'That date is too far ahead to book yet.', 'beacon-bookings' ),
			'alignment' => __( 'Please pick one of the offered start times.', 'beacon-bookings' ),
			'resource'  => __( 'Please choose an option.', 'beacon-bookings' ),
			'full'      => __( 'Sorry, that time is fully booked. Please choose another.', 'beacon-bookings' ),
		);
		return $map[ $reason ] ?? __( 'That booking is not available.', 'beacon-bookings' );
	}

	/* --------------------------------------------------------------- pricing */

	/** Duration prices ("4 hours for 80", "7 days for 300"), shortest first; [] when none are set. */
	public static function tiers( \WC_Product $product ): array {
		return Pricing::tiers( $product->get_meta( '_beacon_bookings_duration_prices', true ) );
	}

	/**
	 * The lengths an "only these lengths" product sells (block counts, shortest first), or [] when any length between the
	 * minimum and maximum can be booked.
	 */
	public static function set_lengths( \WC_Product $product ): array {
		if ( 'yes' !== $product->get_meta( '_beacon_bookings_set_lengths_only', true ) || 'customer' !== self::config( $product )->duration_type ) {
			return array();
		}
		return Pricing::set_lengths( $product->get_meta( '_beacon_bookings_duration_prices', true ) );
	}

	public static function cost( \WC_Product $product, array $req, int $resource_id = 0 ): float {
		$cfg      = self::config( $product );
		$base     = (float) $product->get_meta( '_beacon_bookings_base_cost', true );
		$block    = (float) $product->get_regular_price();
		$multiply = 'yes' === $product->get_meta( '_beacon_bookings_persons_multiply', true );
		$res_cost = 0.0;
		if ( $cfg->uses_resources() && $resource_id ) {
			$res_cost = (float) get_post_meta( $resource_id, '_beacon_bookings_cost', true );
		}
		$cost = Pricing::cost( $base, $block, $req['blocks'], $req['persons'], $multiply && $cfg->persons_enabled(), $res_cost, self::tiers( $product ), (bool) self::set_lengths( $product ) );
		/**
		 * Filter the cost of a booking request (seasonal rules, per-resource prices…).
		 *
		 * @param float       $cost        Computed cost.
		 * @param \WC_Product $product     Bookable product.
		 * @param array       $req         Request: start (DateTimeImmutable|null), blocks, persons, qty, resource_id.
		 * @param int         $resource_id Assigned/chosen resource id (0 = none).
		 */
		return (float) apply_filters( 'beacon_bookings_cost', $cost, $product, $req, $resource_id );
	}

	/* ------------------------------------------------------------------ hold */

	/**
	 * Check under lock and insert a hold row. Returns [ id, Result ].
	 */
	public static function hold( \WC_Product $product, array $req, string $cart_key ): array {
		$result = null;
		$id     = Repo::claim(
			function () use ( $product, $req, $cart_key, &$result ) {
				$result = self::check( $product, $req );
				if ( ! $result->ok ) {
					return null;
				}
				$ttl = max( 5, (int) Plugin::setting( 'hold_minutes' ) );
				$row = self::row_from_result( $product, $req, $result );
				$row['status']       = 'hold';
				$row['hold_expires'] = gmdate( 'Y-m-d H:i:s', time() + $ttl * 60 );
				$row['cart_key']     = $cart_key;
				$row['customer_id']  = get_current_user_id();
				return $row;
			}
		);
		if ( $id <= 0 && $result && $result->ok ) {
			$result = Result::fail( 'full' );
		}
		return array( $id, $result ?: Result::fail( 'full' ) );
	}

	/** Extend or re-validate a hold that sits in a cart. Returns false when the slot is gone. */
	public static function touch_hold( int $booking_id ): bool {
		$row = Repo::get( $booking_id );
		if ( ! $row || 'hold' !== $row['status'] ) {
			return (bool) $row;
		}
		$ttl     = max( 5, (int) Plugin::setting( 'hold_minutes' ) );
		$expired = ! empty( $row['hold_expires'] ) && strtotime( $row['hold_expires'] . ' UTC' ) <= time();
		if ( $expired ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product ) {
				return false;
			}
			$req = self::request_from_row( $row );
			$res = self::check( $product, $req, $booking_id );
			if ( ! $res->ok ) {
				Repo::delete( $booking_id );
				return false;
			}
		}
		Repo::update( $booking_id, array( 'hold_expires' => gmdate( 'Y-m-d H:i:s', time() + $ttl * 60 ) ) );
		return true;
	}

	public static function request_from_row( array $row ): array {
		return array(
			'start'       => new \DateTimeImmutable( $row['start_local'], wp_timezone() ),
			'blocks'      => max( 1, (int) $row['blocks'] ),
			'persons'     => max( 1, (int) $row['persons'] ),
			'qty'         => max( 1, (int) $row['qty'] ),
			'resource_id' => (int) $row['resource_id'],
		);
	}

	/** A table row (without status/hold fields) from a passing Result. */
	public static function row_from_result( \WC_Product $product, array $req, Result $r ): array {
		$cfg = self::config( $product );
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			'product_id'  => $product->get_id(),
			'resource_id' => (int) $r->resource_id,
			'start_utc'   => $r->start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_utc'     => $r->end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'start_local' => $r->start->format( 'Y-m-d H:i:s' ),
			'end_local'   => $r->end->format( 'Y-m-d H:i:s' ),
			'all_day'     => $cfg->is_day_unit() ? 1 : 0,
			'persons'     => (int) $req['persons'],
			'qty'         => (int) $req['qty'],
			'blocks'      => (int) $req['blocks'],
			'cost'        => self::cost( $product, $req, (int) $r->resource_id ),
		);
	}

	/* ----------------------------------------------------------- transitions */

	public static function transition( int $id, string $status, string $note = '' ): bool {
		if ( ! in_array( $status, Repo::ALL, true ) ) {
			return false;
		}
		$row = Repo::get( $id );
		if ( ! $row || $row['status'] === $status ) {
			return (bool) $row;
		}
		$fields = array( 'status' => $status );
		if ( 'hold' !== $status ) {
			$fields['hold_expires'] = null;
		}
		if ( '' !== $note ) {
			$fields['note'] = trim( (string) $row['note'] . "\n" . $note );
		}
		Repo::update( $id, $fields );
		$new = Repo::get( $id );
		/**
		 * Fires after a booking changes status.
		 *
		 * @param array  $new  Booking row.
		 * @param string $old  Previous status.
		 * @param string $status New status.
		 */
		do_action( 'beacon_bookings_status_changed', $new, $row['status'], $status );
		return true;
	}

	/* ---------------------------------------------------------------- labels */

	/** "Oct 5, 2026 10:00 – 11:00" or "Oct 5 – Oct 8, 2026 (3 nights)". */
	public static function when( array $row, ?\WC_Product $product = null ): string {
		$tz    = wp_timezone();
		$s     = new \DateTimeImmutable( $row['start_local'], $tz );
		$e     = new \DateTimeImmutable( $row['end_local'], $tz );
		$df    = get_option( 'date_format', 'M j, Y' );
		$tf    = get_option( 'time_format', 'g:i a' );
		if ( ! empty( $row['all_day'] ) ) {
			$last = $e->modify( '-1 day' );
			$unit = $product ? (string) $product->get_meta( '_beacon_bookings_unit', true ) : 'day';
			$n    = max( 1, (int) $row['blocks'] ) * ( $product ? max( 1, (int) $product->get_meta( '_beacon_bookings_duration', true ) ) : 1 );
			if ( 'night' === $unit ) {
				/* translators: 1: check-in date, 2: check-out date, 3: number of nights */
				return sprintf( _n( '%1$s → %2$s (%3$d night)', '%1$s → %2$s (%3$d nights)', $n, 'beacon-bookings' ), wp_date( $df, $s->getTimestamp(), $tz ), wp_date( $df, $e->getTimestamp(), $tz ), $n );
			}
			if ( $last->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ) ) {
				return wp_date( $df, $s->getTimestamp(), $tz );
			}
			/* translators: 1: first day, 2: last day, 3: number of days */
			return sprintf( _n( '%1$s – %2$s (%3$d day)', '%1$s – %2$s (%3$d days)', $n, 'beacon-bookings' ), wp_date( $df, $s->getTimestamp(), $tz ), wp_date( $df, $last->getTimestamp(), $tz ), $n );
		}
		if ( $s->format( 'Y-m-d' ) !== $e->format( 'Y-m-d' ) && ! ( '00:00' === $e->format( 'H:i' ) && $e->modify( '-1 day' )->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ) ) ) {
			/* translators: 1: start date, 2: start time, 3: return date, 4: return time (an overnight rental) */
			return sprintf( __( '%1$s %2$s – %3$s %4$s', 'beacon-bookings' ), wp_date( $df, $s->getTimestamp(), $tz ), wp_date( $tf, $s->getTimestamp(), $tz ), wp_date( $df, $e->getTimestamp(), $tz ), wp_date( $tf, $e->getTimestamp(), $tz ) );
		}
		/* translators: 1: date, 2: start time, 3: end time */
		return sprintf( __( '%1$s %2$s – %3$s', 'beacon-bookings' ), wp_date( $df, $s->getTimestamp(), $tz ), wp_date( $tf, $s->getTimestamp(), $tz ), wp_date( $tf, $e->getTimestamp(), $tz ) );
	}

	/** The owner's name for the count ("Canoes", "Kayaks"), or '' for the default "people". */
	public static function count_label( ?\WC_Product $product ): string {
		return $product ? trim( (string) $product->get_meta( '_beacon_bookings_count_label', true ) ) : '';
	}

	/** Full one-line description for cart/order/email. */
	public static function describe( array $row, ?\WC_Product $product = null ): string {
		$product = $product ?: wc_get_product( $row['product_id'] );
		$parts   = array( self::when( $row, $product ?: null ) );
		if ( (int) $row['persons'] > 1 || ( $product && self::config( $product )->persons_enabled() ) ) {
			$label   = self::count_label( $product ?: null );
			/* translators: %d: number of people */
			$parts[] = '' !== $label ? $label . ': ' . (int) $row['persons'] : sprintf( _n( '%d person', '%d people', (int) $row['persons'], 'beacon-bookings' ), (int) $row['persons'] );
		}
		if ( (int) $row['resource_id'] > 0 ) {
			$parts[] = get_the_title( (int) $row['resource_id'] );
		}
		return implode( ' · ', array_filter( $parts ) );
	}

	public static function unit_label( \WC_Product $product ): string {
		$cfg = self::config( $product );
		$n   = $cfg->duration;
		switch ( $cfg->unit ) {
			case 'minute':
				/* translators: %d: minutes */
				return sprintf( _n( '%d minute', '%d minutes', $n, 'beacon-bookings' ), $n );
			case 'hour':
				/* translators: %d: hours */
				return sprintf( _n( '%d hour', '%d hours', $n, 'beacon-bookings' ), $n );
			case 'night':
				/* translators: %d: nights */
				return sprintf( _n( '%d night', '%d nights', $n, 'beacon-bookings' ), $n );
			default:
				/* translators: %d: days */
				return sprintf( _n( '%d day', '%d days', $n, 'beacon-bookings' ), $n );
		}
	}
}
