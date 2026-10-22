<?php
/**
 * Wires every component. Nothing here does work; it only registers hooks.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const OPTION_SETTINGS = 'beacon_bookings_settings';

	/** @var bool */
	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Schema::maybe_upgrade();
		Product::register();
		Resources::register();
		Cart::register();
		Order::register();
		Rest::register();
		Frontend::register();
		Ics::register();
		Account::register();
		Calendar::register();
		Cron::register();
		Emails::register();
		if ( is_admin() ) {
			Product_Admin::register();
			Admin::register();
		}

		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/** Flush rewrites once, AFTER endpoints are registered (never inside the activation hook). */
	public static function maybe_flush(): void {
		if ( get_option( 'beacon_bookings_flush_rewrites' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'beacon_bookings_flush_rewrites' );
		}
	}

	/** Settings with defaults. */
	public static function settings(): array {
		$defaults = array(
			'hold_minutes'        => 15,
			'customer_cancel'     => 'yes',
			'cancel_hours'        => 24,
			'reminder_hours'      => 24,
			'feed_key'            => '',
			'delete_on_uninstall' => 'no',
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	public static function setting( string $key ) {
		$s = self::settings();
		return $s[ $key ] ?? null;
	}

	/** The ICS feed key, created on first use. */
	public static function feed_key(): string {
		$s = self::settings();
		if ( empty( $s['feed_key'] ) ) {
			$s['feed_key'] = wp_generate_password( 24, false, false );
			update_option( self::OPTION_SETTINGS, $s, false );
		}
		return (string) $s['feed_key'];
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}
}
