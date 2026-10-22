<?php
/**
 * Booking configuration value object for one product (and its resources).
 *
 * Pure PHP. Built from product meta by the plugin, or by hand in tests.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings\Engine;

defined( 'ABSPATH' ) || exit;

final class Config {

	const UNITS = array( 'minute', 'hour', 'day', 'night' );

	/** @var string minute|hour|day|night */
	public $unit = 'hour';
	/** @var int Length of ONE block in $unit (30 minutes, 1 hour, 1 day…). */
	public $duration = 1;
	/** @var string fixed|customer */
	public $duration_type = 'fixed';
	/** @var int Customer-chosen duration bounds, in blocks. */
	public $min_blocks = 1;
	public $max_blocks = 1;
	/** @var int Minutes between candidate starts for time units (0 = one block). Days for day units. */
	public $interval = 0;
	/** @var int Concurrent bookings per span when no resources are used. */
	public $capacity = 1;
	/** @var int Buffers in minutes (time units) or days (day units). */
	public $buffer_before = 0;
	public $buffer_after  = 0;
	/** @var int Minimum notice in minutes. */
	public $min_notice = 0;
	/** @var int Maximum advance in minutes (0 = unlimited). */
	public $max_advance = 0;
	/** @var array Canonical rules (see Rules). */
	public $rules = array();
	/** @var bool Persons consume capacity (each person = 1 unit of capacity). */
	public $persons_as_qty = false;
	/** @var int Persons bounds (0 = persons disabled). */
	public $persons_min = 0;
	public $persons_max = 0;
	/** @var string none|customer|auto */
	public $assignment = 'none';
	/** @var bool Hour/minute rentals that may run past closing: open at pickup and at return, not in between. */
	public $overnight = false;
	/** @var array resource_id => [ 'capacity' => int, 'rules' => array|null, 'label' => string ] in display order. */
	public $resources = array();

	public function __construct( array $a = array() ) {
		foreach ( $a as $k => $v ) {
			if ( property_exists( $this, $k ) ) {
				$this->$k = $v;
			}
		}
		if ( ! in_array( $this->unit, self::UNITS, true ) ) {
			$this->unit = 'hour';
		}
		$this->duration      = max( 1, (int) $this->duration );
		$this->duration_type = 'customer' === $this->duration_type ? 'customer' : 'fixed';
		$this->min_blocks    = max( 1, (int) $this->min_blocks );
		$this->max_blocks    = max( $this->min_blocks, (int) $this->max_blocks );
		if ( 'fixed' === $this->duration_type ) {
			$this->min_blocks = 1;
			$this->max_blocks = 1;
		}
		$this->interval      = max( 0, (int) $this->interval );
		$this->capacity      = max( 1, (int) $this->capacity );
		$this->buffer_before = max( 0, (int) $this->buffer_before );
		$this->buffer_after  = max( 0, (int) $this->buffer_after );
		$this->min_notice    = max( 0, (int) $this->min_notice );
		$this->max_advance   = max( 0, (int) $this->max_advance );
		if ( empty( $this->rules ) ) {
			$this->rules = Rules::defaults();
		}
		$this->overnight = (bool) $this->overnight && ! $this->is_day_unit();
		if ( ! in_array( $this->assignment, array( 'none', 'customer', 'auto' ), true ) || empty( $this->resources ) ) {
			$this->assignment = 'none';
		}
		$res = array();
		foreach ( (array) $this->resources as $id => $r ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$res[ $id ] = array(
				'capacity' => max( 1, (int) ( $r['capacity'] ?? 1 ) ),
				'rules'    => ! empty( $r['rules'] ) ? $r['rules'] : null,
				'label'    => (string) ( $r['label'] ?? ( 'Resource ' . $id ) ),
			);
		}
		$this->resources = $res;
		if ( empty( $this->resources ) ) {
			$this->assignment = 'none';
		}
	}

	public function is_day_unit(): bool {
		return 'day' === $this->unit || 'night' === $this->unit;
	}

	/** Minutes in one block for time units. */
	public function block_minutes(): int {
		return 'minute' === $this->unit ? $this->duration : $this->duration * 60;
	}

	/** Minutes between candidate starts (time units). */
	public function step_minutes(): int {
		return $this->interval > 0 ? $this->interval : $this->block_minutes();
	}

	/** Days between candidate starts (day units). */
	public function step_days(): int {
		return $this->interval > 0 ? $this->interval : 1;
	}

	public function uses_resources(): bool {
		return 'none' !== $this->assignment && ! empty( $this->resources );
	}

	public function persons_enabled(): bool {
		return $this->persons_max > 0;
	}

	/** Buffers expressed in minutes regardless of unit. */
	public function buffer_before_minutes(): int {
		return $this->is_day_unit() ? $this->buffer_before * Rules::DAY_MINUTES : $this->buffer_before;
	}

	public function buffer_after_minutes(): int {
		return $this->is_day_unit() ? $this->buffer_after * Rules::DAY_MINUTES : $this->buffer_after;
	}

	/** Largest possible span in minutes (used to size DB queries). */
	public function max_span_minutes(): int {
		$per_block = $this->is_day_unit() ? $this->duration * Rules::DAY_MINUTES : $this->block_minutes();
		return $per_block * $this->max_blocks + $this->buffer_before_minutes() + $this->buffer_after_minutes();
	}
}
