<?php
/**
 * Scheduled tasks: expired holds, completing past bookings, reminders.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is the WP minimum recommended for hold expiry.
		add_action( 'beacon_bookings_every_five', array( __CLASS__, 'five' ) );
		add_action( 'beacon_bookings_hourly', array( __CLASS__, 'hourly' ) );
	}

	public static function schedules( array $s ): array {
		$s['beacon_bookings_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (Beacon Bookings)', 'beacon-bookings' ),
		);
		return $s;
	}

	public static function five(): void {
		Repo::purge_expired_holds();
	}

	public static function hourly(): void {
		Repo::purge_expired_holds();
		Repo::complete_past();
		$hours = (int) Plugin::setting( 'reminder_hours' );
		if ( $hours <= 0 ) {
			return;
		}
		foreach ( Repo::reminder_due( $hours ) as $row ) {
			Repo::update( $row['id'], array( 'reminded' => 1 ) );
			if ( $row['customer_email'] ) {
				do_action( 'beacon_bookings_send_reminder', $row );
			}
		}
	}
}
