<?php
/**
 * iCalendar output: per-booking downloads/attachments and subscribable feeds.
 *
 * Feeds: /?beacon_bookings_feed=all&key=KEY[&product=ID][&resource=ID]  (text/calendar, read-only, keyed)
 * One booking: /?beacon_bookings_ics=ID&t=TOKEN
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Ics {

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 5 );
	}

	public static function feed_url( string $what, string $key ): string {
		return add_query_arg( array( 'beacon_bookings_feed' => $what, 'key' => $key ), home_url( '/' ) );
	}

	public static function token( array $row ): string {
		return substr( wp_hash( 'beacon-bookings-ics-' . (int) $row['id'] . '|' . strtolower( (string) $row['customer_email'] ) ), 0, 20 );
	}

	public static function download_url( array $row ): string {
		return add_query_arg( array( 'beacon_bookings_ics' => (int) $row['id'], 't' => self::token( $row ) ), home_url( '/' ) );
	}

	public static function maybe_serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only feed guarded by its own key/token.
		if ( isset( $_GET['beacon_bookings_feed'] ) ) {
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			if ( '' === $key || ! hash_equals( Plugin::feed_key(), $key ) ) {
				status_header( 403 );
				exit( 'Forbidden' );
			}
			$product  = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
			$resource = isset( $_GET['resource'] ) ? absint( $_GET['resource'] ) : 0;
			$tz       = wp_timezone();
			$from     = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '-30 days' )->format( 'Y-m-d 00:00:00' );
			$to       = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+400 days' )->format( 'Y-m-d 23:59:59' );
			$rows     = Repo::in_local_range( $from, $to, $product, $resource, true );
			self::output( self::calendar( $rows, true, get_bloginfo( 'name' ) . ' – ' . __( 'Bookings', 'beacon-bookings' ) ), 'bookings.ics' );
		}
		if ( isset( $_GET['beacon_bookings_ics'] ) ) {
			$row = Repo::get( absint( $_GET['beacon_bookings_ics'] ) );
			$t   = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
			if ( ! $row || '' === $t || ! hash_equals( self::token( $row ), $t ) ) {
				status_header( 404 );
				exit( 'Not found' );
			}
			self::output( self::calendar( array( $row ), false, get_bloginfo( 'name' ) ), 'booking-' . (int) $row['id'] . '.ics' );
		}
		// phpcs:enable
	}

	private static function output( string $body, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body, every field escaped in esc().
		exit;
	}

	/**
	 * Write a temp .ics for an email attachment. Returns the path or ''. Uses get_temp_dir() + wp_unique_filename()
	 * (both always loaded) instead of wp_tempnam(), which only exists once wp-admin/includes/file.php is loaded —
	 * reminder emails go out from WP-Cron and confirmation emails from front-end checkout, where it is not.
	 */
	public static function temp_file( array $row ): string {
		$dir = trailingslashit( get_temp_dir() );
		if ( ! wp_is_writable( $dir ) ) {
			return '';
		}
		$ics = $dir . wp_unique_filename( $dir, 'booking-' . (int) $row['id'] . '-' . wp_generate_password( 8, false ) . '.ics' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for a mail attachment, deleted after send.
		if ( false === file_put_contents( $ics, self::calendar( array( $row ), false, get_bloginfo( 'name' ) ) ) ) {
			return '';
		}
		return $ics;
	}

	public static function calendar( array $rows, bool $admin, string $name ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Beacon Bookings//' . BEACON_BOOKINGS_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::esc( $name ),
		);
		foreach ( $rows as $row ) {
			$lines = array_merge( $lines, self::vevent( $row, $admin ) );
		}
		$lines[] = 'END:VCALENDAR';
		$out     = '';
		foreach ( $lines as $l ) {
			$out .= self::fold( $l ) . "\r\n";
		}
		return $out;
	}

	public static function vevent( array $row, bool $admin ): array {
		$product = wc_get_product( $row['product_id'] );
		$name    = $product ? $product->get_name() : __( 'Booking', 'beacon-bookings' );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
		$utc     = new \DateTimeZone( 'UTC' );
		$s       = new \DateTimeImmutable( $row['start_utc'], $utc );
		$e       = new \DateTimeImmutable( $row['end_utc'], $utc );
		$tz      = wp_timezone();
		$summary = $admin && $row['customer_name'] ? $name . ' – ' . $row['customer_name'] : $name;
		$desc    = Bookings::describe( $row, $product ?: null );
		if ( $admin ) {
			$desc .= "\n" . ( Repo::labels()[ $row['status'] ] ?? $row['status'] );
			if ( $row['customer_email'] ) {
				$desc .= "\n" . $row['customer_email'];
			}
			if ( $row['order_id'] ) {
				$desc .= "\n" . sprintf( 'Order #%d', (int) $row['order_id'] );
			}
		}
		$status = 'cancelled' === $row['status'] ? 'CANCELLED' : ( in_array( $row['status'], array( 'unpaid', 'pending' ), true ) ? 'TENTATIVE' : 'CONFIRMED' );
		$lines  = array(
			'BEGIN:VEVENT',
			'UID:bkb-' . (int) $row['id'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $row['modified'] . ' UTC' ) ?: time() ),
			'SEQUENCE:' . (int) ( strtotime( $row['modified'] . ' UTC' ) ?: 0 ) % 1000000,
		);
		if ( ! empty( $row['all_day'] ) ) {
			$sl      = new \DateTimeImmutable( $row['start_local'], $tz );
			$el      = new \DateTimeImmutable( $row['end_local'], $tz );
			$lines[] = 'DTSTART;VALUE=DATE:' . $sl->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $el->format( 'Ymd' );
		} else {
			$lines[] = 'DTSTART:' . $s->format( 'Ymd\THis\Z' );
			$lines[] = 'DTEND:' . $e->format( 'Ymd\THis\Z' );
		}
		$lines[] = 'SUMMARY:' . self::esc( $summary );
		$lines[] = 'DESCRIPTION:' . self::esc( $desc );
		$lines[] = 'STATUS:' . $status;
		if ( $product ) {
			$lines[] = 'URL:' . self::esc( $product->get_permalink() );
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/** RFC 5545 text escaping. Normalises CR/CRLF first so nothing can inject a new property. */
	public static function esc( string $s ): string {
		$s = preg_replace( "/\r\n|\r/", "\n", $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s );
		$s = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\,' ), $s );
		return str_replace( "\n", '\n', $s );
	}

	/** Fold lines longer than 75 octets. */
	public static function fold( string $line ): string {
		$out = '';
		while ( strlen( $line ) > 75 ) {
			$chunk = mb_strcut( $line, 0, 75, 'UTF-8' );
			$out  .= $chunk . "\r\n ";
			$line  = substr( $line, strlen( $chunk ) );
		}
		return $out . $line;
	}
}
