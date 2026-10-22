<?php
/**
 * Database table, activation and upgrades.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const DB_VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'beacon_bookings';
	}

	public static function activate(): void {
		// The activation request runs before Plugin::boot(), so register the custom interval here too.
		add_filter( 'cron_schedules', array( Cron::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		self::create_table();
		update_option( 'beacon_bookings_db_version', self::DB_VERSION, false );
		update_option( 'beacon_bookings_flush_rewrites', 1, false );
		if ( ! wp_next_scheduled( 'beacon_bookings_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'beacon_bookings_hourly' );
		}
		if ( ! wp_next_scheduled( 'beacon_bookings_every_five' ) ) {
			wp_schedule_event( time() + 60, 'beacon_bookings_five_minutes', 'beacon_bookings_every_five' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'beacon_bookings_hourly' );
		wp_clear_scheduled_hook( 'beacon_bookings_every_five' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'beacon_bookings_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
			update_option( 'beacon_bookings_db_version', self::DB_VERSION, false );
		}
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'hold',
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  start_local datetime NOT NULL,
  end_local datetime NOT NULL,
  all_day tinyint(1) NOT NULL DEFAULT 0,
  persons int(11) unsigned NOT NULL DEFAULT 1,
  qty int(11) unsigned NOT NULL DEFAULT 1,
  blocks int(11) unsigned NOT NULL DEFAULT 1,
  cost decimal(19,4) NOT NULL DEFAULT 0,
  hold_expires datetime NULL,
  cart_key varchar(64) NOT NULL DEFAULT '',
  customer_email varchar(200) NOT NULL DEFAULT '',
  customer_name varchar(200) NOT NULL DEFAULT '',
  note text NULL,
  reminded tinyint(1) NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  modified datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY product_range (product_id,start_utc,end_utc),
  KEY resource_range (resource_id,start_utc),
  KEY order_id (order_id),
  KEY order_item_id (order_item_id),
  KEY status (status),
  KEY customer_id (customer_id),
  KEY cart_key (cart_key)
) {$charset};";
		dbDelta( $sql );
	}
}
