<?php
/**
 * The availability engine.
 *
 * Pure PHP: no WordPress, no database. Given a Config, a timezone, "now" and the existing bookings
 * that touch a window, it answers: which starts are possible, is this request bookable, and how much
 * capacity is left. Capacity is computed POINTWISE (peak concurrent load across the requested span),
 * so multi-day stays that only partially overlap are counted correctly, and buffers are enforced on
 * both sides of every booking.
 *
 * Bookings are arrays: [ 'start' => DateTimeImmutable, 'end' => DateTimeImmutable, 'resource_id' => int,
 * 'qty' => int, 'hold_expires' => DateTimeImmutable|null ]. Any timezone is fine; timestamps are used.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings\Engine;

defined( 'ABSPATH' ) || exit;

final class Availability {

	/** @var Config */
	private $cfg;
	/** @var \DateTimeZone */
	private $tz;
	/** @var \DateTimeImmutable */
	private $now;

	public function __construct( Config $cfg, \DateTimeZone $tz, ?\DateTimeImmutable $now = null ) {
		$this->cfg = $cfg;
		$this->tz  = $tz;
		$this->now = ( $now ?: new \DateTimeImmutable( 'now' ) )->setTimezone( $tz );
	}

	public function config(): Config {
		return $this->cfg;
	}

	public function timezone(): \DateTimeZone {
		return $this->tz;
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	/* ---------------------------------------------------------------------- spans */

	/**
	 * Local [start, end) for a request. Day units snap to midnight and advance by calendar days
	 * (wall-clock, DST-safe). Time units advance by elapsed minutes.
	 */
	public function span( \DateTimeImmutable $start_local, int $blocks ): array {
		$start_local = $start_local->setTimezone( $this->tz );
		$blocks      = max( 1, $blocks );
		if ( $this->cfg->is_day_unit() ) {
			$start = $start_local->setTime( 0, 0, 0 );
			$end   = $start->modify( '+' . ( $blocks * $this->cfg->duration ) . ' days' );
			return array( $start, $end );
		}
		$start = $start_local->setTime( (int) $start_local->format( 'G' ), (int) $start_local->format( 'i' ), 0 );
		$end   = $this->add_minutes( $start, $blocks * $this->cfg->block_minutes() );
		return array( $start, $end );
	}

	/** Absolute (elapsed) minute arithmetic, independent of PHP version DST quirks. */
	public function add_minutes( \DateTimeImmutable $dt, int $minutes ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . ( $dt->getTimestamp() + $minutes * 60 ) ) )->setTimezone( $this->tz );
	}

	/* ------------------------------------------------------------------- openness */

	/**
	 * True when the whole local span lies inside bookable windows of $rules (product rules by default).
	 * Time-unit spans must stay within one local day (an end exactly at the next midnight is allowed).
	 */
	public function is_open( \DateTimeImmutable $start, \DateTimeImmutable $end, ?array $rules = null ): bool {
		$rules = $rules ?? $this->cfg->rules;
		$start = $start->setTimezone( $this->tz );
		$end   = $end->setTimezone( $this->tz );
		if ( $end <= $start ) {
			return false;
		}
		if ( $this->cfg->is_day_unit() ) {
			for ( $d = $start->setTime( 0, 0, 0 ); $d < $end; $d = $d->modify( '+1 day' ) ) {
				if ( empty( Rules::windows_for_date( $rules, $d ) ) ) {
					return false;
				}
			}
			return true;
		}
		$from = (int) $start->format( 'G' ) * 60 + (int) $start->format( 'i' );
		$same = $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' );
		if ( $same ) {
			$to = (int) $end->format( 'G' ) * 60 + (int) $end->format( 'i' );
		} elseif ( $end->format( 'Y-m-d' ) === $start->modify( '+1 day' )->format( 'Y-m-d' ) && '00:00' === $end->format( 'H:i' ) ) {
			$to = Rules::DAY_MINUTES;
		} elseif ( $this->cfg->overnight ) {
			return $this->open_at_ends( $start, $end, $rules );
		} else {
			return false;
		}
		return Rules::span_open( Rules::windows_for_date( $rules, $start ), $from, $to );
	}

	/** Overnight rentals: pickup falls inside an open window on its day and return inside one on the return day. */
	private function open_at_ends( \DateTimeImmutable $start, \DateTimeImmutable $end, array $rules ): bool {
		$from = (int) $start->format( 'G' ) * 60 + (int) $start->format( 'i' );
		$open = false;
		foreach ( Rules::windows_for_date( $rules, $start ) as $w ) {
			$open = $open || ( $from >= $w[0] && $from < $w[1] );
		}
		if ( ! $open ) {
			return false;
		}
		$day = $end;
		$to  = (int) $end->format( 'G' ) * 60 + (int) $end->format( 'i' );
		if ( 0 === $to ) {
			$day = $end->modify( '-1 day' );
			$to  = Rules::DAY_MINUTES;
		}
		foreach ( Rules::windows_for_date( $rules, $day ) as $w ) {
			if ( $to >= $w[0] && $to <= $w[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every local start on $date whose span of $blocks is open under the product rules.
	 * Does not check notice, advance or capacity.
	 *
	 * @return \DateTimeImmutable[]
	 */
	public function candidate_starts( \DateTimeImmutable $date, int $blocks ): array {
		$date   = $date->setTimezone( $this->tz )->setTime( 0, 0, 0 );
		$blocks = max( 1, $blocks );
		if ( $this->cfg->is_day_unit() ) {
			list( $s, $e ) = $this->span( $date, $blocks );
			return $this->is_open( $s, $e ) ? array( $s ) : array();
		}
		$out  = array();
		$seen = array();
		$len  = $blocks * $this->cfg->block_minutes();
		$step = max( 1, $this->cfg->step_minutes() );
		foreach ( Rules::windows_for_date( $this->cfg->rules, $date ) as $w ) {
			// An overnight rental may start any time the window is open; is_open() then checks the return time.
			$last = $this->cfg->overnight ? $w[1] - 1 : $w[1] - $len;
			for ( $t = $w[0]; $t <= $last; $t += $step ) {
				$start = $date->setTime( intdiv( $t, 60 ), $t % 60, 0 );
				$ts    = $start->getTimestamp();
				if ( isset( $seen[ $ts ] ) ) {
					continue;
				}
				list( $s, $e ) = $this->span( $start, $blocks );
				if ( $this->is_open( $s, $e ) ) {
					$seen[ $ts ] = true;
					$out[]       = $s;
				}
			}
		}
		return $out;
	}

	/** True when $start is one of the candidate starts of its date (slots are aligned to the interval). */
	public function is_candidate( \DateTimeImmutable $start, int $blocks ): bool {
		$start = $start->setTimezone( $this->tz );
		foreach ( $this->candidate_starts( $start, $blocks ) as $c ) {
			if ( $c->getTimestamp() === $start->getTimestamp() ) {
				return true;
			}
		}
		return false;
	}

	/* ----------------------------------------------------------------- time gates */

	/** 'ok' | 'past' | 'notice' | 'advance' */
	public function time_gate( \DateTimeImmutable $start ): string {
		$start = $start->setTimezone( $this->tz );
		$ref   = $this->cfg->is_day_unit() ? $this->now->setTime( 0, 0, 0 ) : $this->now;
		$lead  = $start->getTimestamp() - $ref->getTimestamp();
		if ( $lead < 0 ) {
			return 'past';
		}
		if ( $lead < $this->cfg->min_notice * 60 ) {
			return 'notice';
		}
		if ( $this->cfg->max_advance > 0 && $lead > $this->cfg->max_advance * 60 ) {
			return 'advance';
		}
		return 'ok';
	}

	/* ------------------------------------------------------------------- capacity */

	/**
	 * Peak concurrent qty of $bookings over the candidate span, with every booking (and the candidate)
	 * expanded by the buffers. Expired holds are ignored. $resource_id null = ignore resources.
	 */
	public function peak_load( \DateTimeImmutable $start, \DateTimeImmutable $end, array $bookings, ?int $resource_id ): int {
		$bb = $this->cfg->buffer_before_minutes() * 60;
		$ba = $this->cfg->buffer_after_minutes() * 60;
		$cs = $start->getTimestamp() - $bb;
		$ce = $end->getTimestamp() + $ba;
		$ev = array();
		foreach ( $bookings as $b ) {
			if ( null !== $resource_id && (int) ( $b['resource_id'] ?? 0 ) !== $resource_id ) {
				continue;
			}
			if ( ! empty( $b['hold_expires'] ) && $b['hold_expires']->getTimestamp() <= $this->now->getTimestamp() ) {
				continue;
			}
			$bs = $b['start']->getTimestamp() - $bb;
			$be = $b['end']->getTimestamp() + $ba;
			if ( $bs >= $ce || $be <= $cs ) {
				continue;
			}
			$q    = max( 1, (int) ( $b['qty'] ?? 1 ) );
			$ev[] = array( max( $bs, $cs ), 1, $q );   // start: +q (sorted after ends at the same instant)
			$ev[] = array( min( $be, $ce ), 0, -$q );  // end: -q
		}
		if ( empty( $ev ) ) {
			return 0;
		}
		usort(
			$ev,
			static function ( $a, $b ) {
				return ( $a[0] <=> $b[0] ) ?: ( $a[1] <=> $b[1] );
			}
		);
		$load = 0;
		$peak = 0;
		foreach ( $ev as $e ) {
			$load += $e[2];
			if ( $load > $peak ) {
				$peak = $load;
			}
		}
		return $peak;
	}

	/**
	 * Remaining capacity per resource for the span. Without resources the key is 0 (product level).
	 * A resource whose own rules close the span reports 0.
	 *
	 * @return array<int,int>
	 */
	public function remaining( \DateTimeImmutable $start, \DateTimeImmutable $end, array $bookings ): array {
		if ( ! $this->cfg->uses_resources() ) {
			return array( 0 => max( 0, $this->cfg->capacity - $this->peak_load( $start, $end, $bookings, null ) ) );
		}
		$out = array();
		foreach ( $this->cfg->resources as $id => $r ) {
			if ( ! empty( $r['rules'] ) && ! $this->is_open( $start, $end, $r['rules'] ) ) {
				$out[ $id ] = 0;
				continue;
			}
			$out[ $id ] = max( 0, (int) $r['capacity'] - $this->peak_load( $start, $end, $bookings, (int) $id ) );
		}
		return $out;
	}

	/** Collapse a remaining-map to one number for a requested resource (null/0 = "any"). */
	public function resolve_remaining( array $remaining, ?int $resource_id ): int {
		if ( ! $this->cfg->uses_resources() ) {
			return (int) ( $remaining[0] ?? 0 );
		}
		if ( $resource_id ) {
			return (int) ( $remaining[ $resource_id ] ?? 0 );
		}
		return empty( $remaining ) ? 0 : max( $remaining );
	}

	/* ---------------------------------------------------------------------- check */

	/**
	 * Full validation of a booking request. Resource semantics:
	 *  - assignment 'none'     : $resource_id ignored.
	 *  - assignment 'customer' : $resource_id required and must exist.
	 *  - assignment 'auto'     : $resource_id optional; the first resource (configured order) with room is assigned.
	 */
	public function check( \DateTimeImmutable $start_local, int $blocks, int $qty, ?int $resource_id, array $bookings ): Result {
		$cfg = $this->cfg;
		if ( $blocks < $cfg->min_blocks || $blocks > $cfg->max_blocks ) {
			return Result::fail( 'duration' );
		}
		if ( $qty < 1 ) {
			return Result::fail( 'qty' );
		}
		list( $s, $e ) = $this->span( $start_local, $blocks );
		if ( ! $this->is_open( $s, $e ) ) {
			return Result::fail( 'closed', $s, $e );
		}
		$gate = $this->time_gate( $s );
		if ( 'ok' !== $gate ) {
			return Result::fail( $gate, $s, $e );
		}
		if ( ! $this->is_candidate( $s, $blocks ) ) {
			return Result::fail( 'alignment', $s, $e );
		}
		$rem = $this->remaining( $s, $e, $bookings );
		if ( ! $cfg->uses_resources() ) {
			$left = (int) $rem[0];
			return $left >= $qty ? Result::pass( $s, $e, 0, $left - $qty ) : Result::fail( 'full', $s, $e );
		}
		if ( 'customer' === $cfg->assignment ) {
			if ( ! $resource_id || ! isset( $cfg->resources[ $resource_id ] ) ) {
				return Result::fail( 'resource', $s, $e );
			}
			$left = (int) $rem[ $resource_id ];
			return $left >= $qty ? Result::pass( $s, $e, $resource_id, $left - $qty ) : Result::fail( 'full', $s, $e );
		}
		// auto: honour an explicit, valid choice first; otherwise first fit in configured order.
		if ( $resource_id && isset( $rem[ $resource_id ] ) && $rem[ $resource_id ] >= $qty ) {
			return Result::pass( $s, $e, $resource_id, $rem[ $resource_id ] - $qty );
		}
		foreach ( $cfg->resources as $id => $r ) {
			if ( $rem[ $id ] >= $qty ) {
				return Result::pass( $s, $e, (int) $id, $rem[ $id ] - $qty );
			}
		}
		return Result::fail( 'full', $s, $e );
	}

	/* -------------------------------------------------------------------- summary */

	/**
	 * Calendar data for a local date range (inclusive). Time units return per-date slot lists; day
	 * units return per-date availability of a stay of $blocks starting that day. Past/too-soon/too-far
	 * starts are omitted (time units) or marked unavailable (day units).
	 */
	public function summary( \DateTimeImmutable $from, \DateTimeImmutable $to, int $blocks, int $qty, ?int $resource_id, array $bookings ): array {
		$from   = $from->setTimezone( $this->tz )->setTime( 0, 0, 0 );
		$to     = $to->setTimezone( $this->tz )->setTime( 0, 0, 0 );
		$blocks = max( 1, $blocks );
		$qty    = max( 1, $qty );
		$out    = array();
		for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) {
			$key   = $d->format( 'Y-m-d' );
			$cands = $this->candidate_starts( $d, $blocks );
			if ( $this->cfg->is_day_unit() ) {
				if ( empty( $cands ) ) {
					$out[ $key ] = array(
						'available' => false,
						'remaining' => 0,
						'reason'    => 'closed',
					);
					continue;
				}
				list( $s, $e ) = $this->span( $cands[0], $blocks );
				$gate          = $this->time_gate( $s );
				if ( 'ok' !== $gate ) {
					$out[ $key ] = array(
						'available' => false,
						'remaining' => 0,
						'reason'    => $gate,
					);
					continue;
				}
				$left        = $this->resolve_remaining( $this->remaining( $s, $e, $bookings ), $resource_id );
				$out[ $key ] = array(
					'available' => $left >= $qty,
					'remaining' => $left,
					'reason'    => $left >= $qty ? 'ok' : 'full',
				);
				continue;
			}
			$slots = array();
			$any   = false;
			foreach ( $cands as $c ) {
				if ( 'ok' !== $this->time_gate( $c ) ) {
					continue;
				}
				list( $s, $e ) = $this->span( $c, $blocks );
				$left          = $this->resolve_remaining( $this->remaining( $s, $e, $bookings ), $resource_id );
				$ok            = $left >= $qty;
				$any           = $any || $ok;
				$slots[]       = array(
					'start'     => $s->format( 'H:i' ),
					'end'       => $e->format( 'H:i' ),
					'end_date'  => $e->format( 'Y-m-d' ),
					'ts'        => $s->getTimestamp(),
					'remaining' => $left,
					'available' => $ok,
				);
			}
			$out[ $key ] = array(
				'available' => $any,
				'slots'     => $slots,
			);
		}
		return $out;
	}
}
