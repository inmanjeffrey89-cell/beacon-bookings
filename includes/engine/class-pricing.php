<?php
/**
 * Booking cost arithmetic. Pure PHP.
 *
 * total = ( base_cost + cheapest price of the blocks + resource_cost ) × ( persons if persons multiply, else 1 )
 *
 * The blocks cost block_cost each, or any mix of duration prices ("4 hours for 80", "7 days for 300"), whichever is
 * cheaper. With "only these lengths" a length that has its own price costs exactly that price.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings\Engine;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	public static function cost( float $base_cost, float $block_cost, int $blocks, int $persons, bool $persons_multiply, float $resource_cost = 0.0, array $tiers = array(), bool $set_only = false ): float {
		$blocks  = max( 1, $blocks );
		$persons = max( 1, $persons );
		$total   = $base_cost + self::blocks_cost( $block_cost, $blocks, $tiers, $set_only ) + $resource_cost;
		if ( $persons_multiply ) {
			$total *= $persons;
		}
		return round( max( 0.0, $total ), 2 );
	}

	/**
	 * Cheapest price for $blocks blocks: each block at $block_cost, or any mix of duration prices. A duration price may
	 * cover more blocks than are left, which is what makes it a cap: at 25 an hour with 4 hours for 80, three hours cost
	 * 75 and five hours cost 105.
	 */
	public static function blocks_cost( float $block_cost, int $blocks, array $tiers = array(), bool $set_only = false ): float {
		$n     = max( 1, $blocks );
		$unit  = max( 0.0, $block_cost );
		$tiers = self::tiers( $tiers );
		if ( $set_only ) {
			foreach ( $tiers as $t ) {
				if ( $t['blocks'] === $n ) {
					return $t['cost'];
				}
			}
		}
		if ( ! $tiers ) {
			return $unit * $n;
		}
		$best    = array_fill( 0, $n + 1, INF );
		$best[0] = 0.0;
		for ( $i = 1; $i <= $n; $i++ ) {
			$best[ $i ] = $best[ $i - 1 ] + $unit;
			foreach ( $tiers as $t ) {
				$best[ $i ] = min( $best[ $i ], $best[ max( 0, $i - $t['blocks'] ) ] + $t['cost'] );
			}
		}
		return $best[ $n ];
	}

	/**
	 * Valid duration prices, shortest first: a whole number of blocks ≥ 1 and a price > 0. Anything else is dropped,
	 * never read as "free". Accepts the stored array or anything else (→ none).
	 *
	 * @param mixed $raw Stored value.
	 * @return array<int, array{blocks:int, cost:float}>
	 */
	public static function tiers( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$b = (int) floor( (float) ( $t['blocks'] ?? 0 ) );
			$c = (float) ( $t['cost'] ?? 0 );
			if ( $b >= 1 && is_finite( $c ) && $c > 0 && ( ! isset( $out[ $b ] ) || $c < $out[ $b ]['cost'] ) ) {
				$out[ $b ] = array(
					'blocks' => $b,
					'cost'   => round( $c, 2 ),
				);
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** The block counts a "only these lengths" product offers: its duration prices' lengths, shortest first. */
	public static function set_lengths( $raw ): array {
		return array_map( static fn( $t ) => $t['blocks'], self::tiers( $raw ) );
	}
}
