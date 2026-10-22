<?php
/**
 * All access to the bookings table.
 *
 * Rows are plain arrays keyed by column. Datetimes in the table are 'Y-m-d H:i:s' strings; *_utc columns
 * are UTC, *_local columns are site-timezone wall-clock (kept so reports and the calendar never need a
 * conversion). The engine only ever sees UTC-backed DateTimeImmutable objects.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Repo {

	/** Statuses that occupy capacity. */
	const ACTIVE = array( 'hold', 'unpaid', 'pending', 'confirmed', 'paid', 'complete' );
	/** Statuses a customer sees as "booked". */
	const LIVE = array( 'unpaid', 'pending', 'confirmed', 'paid', 'complete' );
	const ALL  = array( 'hold', 'unpaid', 'pending', 'confirmed', 'paid', 'complete', 'cancelled' );

	public static function labels(): array {
		return array(
			'hold'      => __( 'In cart', 'beacon-bookings' ),
			'unpaid'    => __( 'Awaiting payment', 'beacon-bookings' ),
			'pending'   => __( 'Pending confirmation', 'beacon-bookings' ),
			'confirmed' => __( 'Confirmed', 'beacon-bookings' ),
			'paid'      => __( 'Paid', 'beacon-bookings' ),
			'complete'  => __( 'Complete', 'beacon-bookings' ),
			'cancelled' => __( 'Cancelled', 'beacon-bookings' ),
		);
	}

	/* ---------------------------------------------------------------- reads */

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, per-request read.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table(), $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	public static function by_order_item( int $order_item_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_item_id = %d ORDER BY id DESC LIMIT 1', Schema::table(), $order_item_id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	public static function by_order( int $order_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY start_utc ASC', Schema::table(), $order_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	public static function by_cart_key( string $cart_key ): ?array {
		global $wpdb;
		if ( '' === $cart_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE cart_key = %s AND status = %s ORDER BY id DESC LIMIT 1', Schema::table(), $cart_key, 'hold' ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Active bookings touching [$from_utc, $to_utc) for a product (any resource), as engine arrays.
	 * Live holds are included with their expiry; the engine drops expired ones itself.
	 */
	public static function engine_bookings( int $product_id, \DateTimeImmutable $from_utc, \DateTimeImmutable $to_utc, int $exclude_id = 0, array $resource_ids = array() ): array {
		global $wpdb;
		$utc      = new \DateTimeZone( 'UTC' );
		$statuses = implode( "','", array_map( 'esc_sql', self::ACTIVE ) );
		$params   = array( Schema::table(), $from_utc->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to_utc->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $exclude_id );
		$where    = '';
		if ( ! empty( $resource_ids ) ) {
			// Shared resources: bookings of OTHER products on these resources block them too.
			$ids   = implode( ',', array_map( 'intval', $resource_ids ) );
			$where = "AND ( product_id = %d OR resource_id IN ({$ids}) )";
		} else {
			$where = 'AND product_id = %d';
		}
		$params[] = $product_id;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; $statuses and $ids are escaped/int-cast above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, resource_id, qty, start_utc, end_utc, hold_expires, status FROM %i
				 WHERE status IN ('{$statuses}') AND end_utc > %s AND start_utc < %s AND id <> %d {$where}",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'           => (int) $r['id'],
				'start'        => new \DateTimeImmutable( $r['start_utc'], $utc ),
				'end'          => new \DateTimeImmutable( $r['end_utc'], $utc ),
				'resource_id'  => (int) $r['resource_id'],
				'qty'          => max( 1, (int) $r['qty'] ),
				'hold_expires' => ( 'hold' === $r['status'] && ! empty( $r['hold_expires'] ) ) ? new \DateTimeImmutable( $r['hold_expires'], $utc ) : null,
			);
		}
		return $out;
	}

	/**
	 * Admin/customer listing.
	 *
	 * @param array $args status, product_id, resource_id, customer_id, email, from (local Y-m-d), to, search, orderby, order, per_page, page, upcoming(bool), reminder_due(hours)
	 * @return array [ rows, total ]
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$a = array_merge(
			array(
				'status'      => '',
				'product_id'  => 0,
				'resource_id' => 0,
				'customer_id' => 0,
				'email'       => '',
				'from'        => '',
				'to'          => '',
				'search'      => '',
				'orderby'     => 'start_utc',
				'order'       => 'ASC',
				'per_page'    => 20,
				'page'        => 1,
				'exclude_holds' => true,
			),
			$args
		);
		$where  = array( '1=1' );
		$params = array();
		if ( $a['exclude_holds'] && '' === $a['status'] ) {
			$where[] = "status <> 'hold'";
		}
		if ( '' !== $a['status'] && in_array( $a['status'], self::ALL, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $a['status'];
		}
		foreach ( array( 'product_id', 'resource_id', 'customer_id' ) as $k ) {
			if ( (int) $a[ $k ] > 0 ) {
				$where[]  = "{$k} = %d";
				$params[] = (int) $a[ $k ];
			}
		}
		if ( '' !== $a['email'] ) {
			$where[]  = 'customer_email = %s';
			$params[] = $a['email'];
		}
		if ( '' !== $a['from'] ) {
			$where[]  = 'end_local > %s';
			$params[] = $a['from'] . ' 00:00:00';
		}
		if ( '' !== $a['to'] ) {
			$where[]  = 'start_local < %s';
			$params[] = $a['to'] . ' 23:59:59';
		}
		if ( '' !== $a['search'] ) {
			$like     = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$where[]  = '( customer_name LIKE %s OR customer_email LIKE %s OR order_id = %d OR id = %d )';
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $a['search'];
			$params[] = (int) $a['search'];
		}
		$orderby = in_array( $a['orderby'], array( 'start_utc', 'id', 'status', 'product_id', 'cost', 'created' ), true ) ? $a['orderby'] : 'start_utc';
		$order   = 'DESC' === strtoupper( $a['order'] ) ? 'DESC' : 'ASC';
		$limit   = max( 1, (int) $a['per_page'] );
		$offset  = ( max( 1, (int) $a['page'] ) - 1 ) * $limit;
		$sql_w   = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; fragments are whitelisted above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql_w}", array_merge( array( Schema::table() ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE {$sql_w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", array_merge( array( Schema::table() ), $params, array( $limit, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable
		return array( array_map( array( __CLASS__, 'cast' ), (array) $rows ), $total );
	}

	/** Bookings (non-hold) overlapping a local calendar range, for the admin calendar and the ICS feed. */
	public static function in_local_range( string $from_local, string $to_local, int $product_id = 0, int $resource_id = 0, bool $live_only = true ): array {
		global $wpdb;
		$statuses = implode( "','", array_map( 'esc_sql', $live_only ? self::LIVE : self::ALL ) );
		$params   = array( Schema::table(), $from_local, $to_local );
		$extra    = '';
		if ( $product_id ) {
			$extra   .= ' AND product_id = %d';
			$params[] = $product_id;
		}
		if ( $resource_id ) {
			$extra   .= ' AND resource_id = %d';
			$params[] = $resource_id;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE status IN ('{$statuses}') AND end_local > %s AND start_local < %s {$extra} ORDER BY start_local ASC", $params ),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/* --------------------------------------------------------------- writes */

	public static function insert( array $row ): int {
		global $wpdb;
		$now             = gmdate( 'Y-m-d H:i:s' );
		$row['created']  = $now;
		$row['modified'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( Schema::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$fields['modified'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( Schema::table(), $fields, array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** Remove expired holds. Returns rows removed. */
	public static function purge_expired_holds(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND hold_expires IS NOT NULL AND hold_expires <= %s', Schema::table(), 'hold', gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** Paid/confirmed bookings whose end has passed become complete. */
	public static function complete_past(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, modified = %s WHERE status IN (%s, %s) AND end_utc <= %s', Schema::table(), 'complete', gmdate( 'Y-m-d H:i:s' ), 'paid', 'confirmed', gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** Live bookings starting within $hours that have not been reminded. */
	public static function reminder_due( int $hours ): array {
		global $wpdb;
		$statuses = "'paid','confirmed'";
		$now      = gmdate( 'Y-m-d H:i:s' );
		$until    = gmdate( 'Y-m-d H:i:s', time() + $hours * 3600 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ({$statuses}) AND reminded = 0 AND start_utc > %s AND start_utc <= %s LIMIT 200", Schema::table(), $now, $until ), ARRAY_A );
		// phpcs:enable
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * Serialised claim: run $check under a global advisory lock and insert only if it passes.
	 * On MySQL/MariaDB GET_LOCK makes two simultaneous checkouts for the last slot impossible; other
	 * engines (SQLite dev sites) fall back to check-then-insert.
	 *
	 * @param callable $check  fn(): ?array  returns the row to insert, or null when unavailable.
	 * @return int booking id, 0 when unavailable.
	 */
	public static function claim( callable $check ): int {
		global $wpdb;
		$locked = false;
		if ( ! empty( $wpdb->is_mysql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'beacon_bookings_claim', 5 ) );
		}
		try {
			$row = $check();
			return $row ? self::insert( $row ) : 0;
		} finally {
			if ( $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'beacon_bookings_claim' ) );
			}
		}
	}

	/* ---------------------------------------------------------------- utils */

	private static function cast( array $r ): array {
		foreach ( array( 'id', 'product_id', 'resource_id', 'order_id', 'order_item_id', 'customer_id', 'persons', 'qty', 'blocks', 'all_day', 'reminded' ) as $k ) {
			if ( isset( $r[ $k ] ) ) {
				$r[ $k ] = (int) $r[ $k ];
			}
		}
		$r['cost'] = isset( $r['cost'] ) ? (float) $r['cost'] : 0.0;
		return $r;
	}
}
