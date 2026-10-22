<?php
/**
 * WooCommerce emails for bookings: confirmed, pending, cancelled, reminder (customer) and new booking (admin).
 * One generic WC_Email subclass instantiated per type; templates live in templates/emails/.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Emails {

	const TYPES = array( 'beacon_bookings_confirmed', 'beacon_bookings_pending', 'beacon_bookings_cancelled', 'beacon_bookings_reminder', 'beacon_bookings_new_booking' );

	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'classes' ) );
		add_action( 'beacon_bookings_status_changed', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'beacon_bookings_send_reminder', array( __CLASS__, 'on_reminder' ) );
	}

	public static function classes( array $classes ): array {
		if ( ! class_exists( '\WC_Email' ) ) {
			return $classes;
		}
		require_once BEACON_BOOKINGS_DIR . 'includes/class-email.php';
		foreach ( self::TYPES as $type ) {
			$classes[ 'BKB_' . ucfirst( substr( $type, 4 ) ) ] = new Email( $type );
		}
		return $classes;
	}

	private static function send( string $type, array $row ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		foreach ( $emails as $email ) {
			if ( $email instanceof Email && $email->id === $type ) {
				$email->trigger( $row );
			}
		}
	}

	public static function on_status( array $row, string $old, string $new ): void {
		$live = array( 'unpaid', 'pending', 'confirmed', 'paid', 'complete' );
		if ( in_array( $new, array( 'confirmed', 'paid' ), true ) && ! in_array( $old, array( 'confirmed', 'paid', 'complete' ), true ) ) {
			self::send( 'beacon_bookings_confirmed', $row );
		} elseif ( 'pending' === $new && 'pending' !== $old ) {
			self::send( 'beacon_bookings_pending', $row );
		} elseif ( 'cancelled' === $new && in_array( $old, $live, true ) ) {
			self::send( 'beacon_bookings_cancelled', $row );
		}
		if ( 'unpaid' === $new && 'hold' === $old ) {
			self::send( 'beacon_bookings_new_booking', $row );
		}
	}

	public static function on_reminder( array $row ): void {
		self::send( 'beacon_bookings_reminder', $row );
	}
}
