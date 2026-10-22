<?php
/**
 * Availability rules: normalisation and per-date window evaluation.
 *
 * Pure PHP. No WordPress calls. Times are minutes since local midnight (0..1440).
 *
 * Canonical rules array:
 *   [
 *     'weekly'    => [ 1 => [[540, 1020]], 2 => [...], ..., 7 => [] ],   // ISO weekday 1=Mon .. 7=Sun
 *     'overrides' => [ [ 'from' => 'Y-m-d', 'to' => 'Y-m-d', 'beacon_bookable' => bool, 'windows' => [[from,to],...] ], ... ],
 *   ]
 * Overrides apply in list order; a later override wins over an earlier one. An override with no
 * windows means the whole day. A bookable override ADDS windows; a non-bookable override REMOVES them.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings\Engine;

defined( 'ABSPATH' ) || exit;

final class Rules {

	const DAY_MINUTES = 1440;

	/**
	 * Build canonical rules from the raw admin form structure.
	 *
	 * @param array $raw {
	 *   weekly:    list of [ 'day' => 1..7, 'from' => 'HH:MM', 'to' => 'HH:MM' ] (enabled rows only)
	 *   overrides: list of [ 'from' => 'Y-m-d', 'to' => 'Y-m-d', 'beacon_bookable' => '1'|'0', 'time_from' => 'HH:MM'|'', 'time_to' => 'HH:MM'|'' ]
	 * }
	 */
	public static function normalize( array $raw ): array {
		$weekly = array_fill( 1, 7, array() );
		foreach ( (array) ( $raw['weekly'] ?? array() ) as $row ) {
			$day = (int) ( $row['day'] ?? 0 );
			if ( $day < 1 || $day > 7 ) {
				continue;
			}
			$from = self::to_minutes( (string) ( $row['from'] ?? '' ) );
			$to   = self::to_minutes( (string) ( $row['to'] ?? '' ), true );
			if ( null === $from || null === $to || $to <= $from ) {
				continue;
			}
			$weekly[ $day ][] = array( $from, $to );
		}
		foreach ( $weekly as $d => $wins ) {
			$weekly[ $d ] = self::merge( $wins );
		}

		$overrides = array();
		foreach ( (array) ( $raw['overrides'] ?? array() ) as $row ) {
			$from = self::valid_date( (string) ( $row['from'] ?? '' ) );
			$to   = self::valid_date( (string) ( $row['to'] ?? '' ) );
			if ( null === $from ) {
				continue;
			}
			if ( null === $to || $to < $from ) {
				$to = $from;
			}
			$windows = array();
			$tf      = self::to_minutes( (string) ( $row['time_from'] ?? '' ) );
			$tt      = self::to_minutes( (string) ( $row['time_to'] ?? '' ), true );
			if ( null !== $tf && null !== $tt && $tt > $tf ) {
				$windows[] = array( $tf, $tt );
			}
			$overrides[] = array(
				'from'     => $from,
				'to'       => $to,
				'beacon_bookable' => ! empty( $row['beacon_bookable'] ) && '0' !== (string) $row['beacon_bookable'],
				'windows'  => $windows,
			);
		}

		return array(
			'weekly'    => $weekly,
			'overrides' => $overrides,
		);
	}

	/** Default rules: Monday–Friday 09:00–17:00. */
	public static function defaults(): array {
		$weekly = array_fill( 1, 7, array() );
		for ( $d = 1; $d <= 5; $d++ ) {
			$weekly[ $d ] = array( array( 540, 1020 ) );
		}
		return array(
			'weekly'    => $weekly,
			'overrides' => array(),
		);
	}

	/** Always-open rules (every day, all day). Used for resources without their own rules. */
	public static function always(): array {
		return array(
			'weekly'    => array_fill( 1, 7, array( array( 0, self::DAY_MINUTES ) ) ),
			'overrides' => array(),
		);
	}

	/**
	 * Bookable windows for one local date, as a merged, sorted list of [fromMin, toMin].
	 */
	public static function windows_for_date( array $rules, \DateTimeImmutable $date ): array {
		$dow     = (int) $date->format( 'N' );
		$ymd     = $date->format( 'Y-m-d' );
		$windows = isset( $rules['weekly'][ $dow ] ) ? (array) $rules['weekly'][ $dow ] : array();
		foreach ( (array) ( $rules['overrides'] ?? array() ) as $o ) {
			if ( $ymd < $o['from'] || $ymd > $o['to'] ) {
				continue;
			}
			$ow = ! empty( $o['windows'] ) ? $o['windows'] : array( array( 0, self::DAY_MINUTES ) );
			if ( ! empty( $o['beacon_bookable'] ) ) {
				$windows = self::merge( array_merge( $windows, $ow ) );
			} else {
				$windows = self::subtract( $windows, $ow );
			}
		}
		return self::merge( $windows );
	}

	/** True when [from, to) lies fully inside one bookable window of the date. */
	public static function span_open( array $windows, int $from, int $to ): bool {
		foreach ( $windows as $w ) {
			if ( $from >= $w[0] && $to <= $w[1] ) {
				return true;
			}
		}
		return false;
	}

	/** Merge overlapping/adjacent windows. */
	public static function merge( array $windows ): array {
		$windows = array_values(
			array_filter(
				$windows,
				static function ( $w ) {
					return is_array( $w ) && count( $w ) >= 2 && (int) $w[1] > (int) $w[0];
				}
			)
		);
		usort(
			$windows,
			static function ( $a, $b ) {
				return (int) $a[0] <=> (int) $b[0];
			}
		);
		$out = array();
		foreach ( $windows as $w ) {
			$w = array( max( 0, (int) $w[0] ), min( self::DAY_MINUTES, (int) $w[1] ) );
			if ( $w[1] <= $w[0] ) {
				continue;
			}
			$n = count( $out );
			if ( $n && $w[0] <= $out[ $n - 1 ][1] ) {
				$out[ $n - 1 ][1] = max( $out[ $n - 1 ][1], $w[1] );
			} else {
				$out[] = $w;
			}
		}
		return $out;
	}

	/** Remove the $cut windows from $windows. */
	public static function subtract( array $windows, array $cut ): array {
		$windows = self::merge( $windows );
		foreach ( self::merge( $cut ) as $c ) {
			$next = array();
			foreach ( $windows as $w ) {
				if ( $c[1] <= $w[0] || $c[0] >= $w[1] ) {
					$next[] = $w;
					continue;
				}
				if ( $c[0] > $w[0] ) {
					$next[] = array( $w[0], $c[0] );
				}
				if ( $c[1] < $w[1] ) {
					$next[] = array( $c[1], $w[1] );
				}
			}
			$windows = $next;
		}
		return $windows;
	}

	/** 'HH:MM' → minutes since midnight; '24:00' allowed as an end. Null when invalid. */
	public static function to_minutes( string $hhmm, bool $allow_end = false ): ?int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( $i > 59 ) {
			return null;
		}
		if ( 24 === $h && 0 === $i && $allow_end ) {
			return self::DAY_MINUTES;
		}
		if ( $h > 23 ) {
			return null;
		}
		return $h * 60 + $i;
	}

	/** minutes → 'HH:MM'. */
	public static function to_hhmm( int $minutes ): string {
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/** 'Y-m-d' validated; null when invalid. */
	public static function valid_date( string $ymd ): ?string {
		$ymd = trim( $ymd );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}
		$p = explode( '-', $ymd );
		return checkdate( (int) $p[1], (int) $p[2], (int) $p[0] ) ? $ymd : null;
	}
}
