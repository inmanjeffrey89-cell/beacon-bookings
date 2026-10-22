<?php
/**
 * Result of an availability check.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings\Engine;

defined( 'ABSPATH' ) || exit;

final class Result {

	/** @var bool */
	public $ok = false;
	/** @var string ok|duration|qty|closed|past|notice|advance|alignment|resource|full */
	public $reason = '';
	/** @var \DateTimeImmutable|null Local start. */
	public $start = null;
	/** @var \DateTimeImmutable|null Local end (exclusive). */
	public $end = null;
	/** @var int Assigned resource id (0 when the product has no resources). */
	public $resource_id = 0;
	/** @var int Remaining capacity for the chosen resource/product AFTER this booking would be added. */
	public $remaining = 0;

	public static function fail( string $reason, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null ): Result {
		$r         = new self();
		$r->ok     = false;
		$r->reason = $reason;
		$r->start  = $start;
		$r->end    = $end;
		return $r;
	}

	public static function pass( \DateTimeImmutable $start, \DateTimeImmutable $end, int $resource_id, int $remaining ): Result {
		$r              = new self();
		$r->ok          = true;
		$r->reason      = 'ok';
		$r->start       = $start;
		$r->end         = $end;
		$r->resource_id = $resource_id;
		$r->remaining   = $remaining;
		return $r;
	}
}
