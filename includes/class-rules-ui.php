<?php
/**
 * Shared admin form for availability rules (products and resources).
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

use Beacon\Bookings\Engine\Rules;

defined( 'ABSPATH' ) || exit;

final class Rules_Ui {

	public static function day_names(): array {
		return array(
			1 => __( 'Monday', 'beacon-bookings' ),
			2 => __( 'Tuesday', 'beacon-bookings' ),
			3 => __( 'Wednesday', 'beacon-bookings' ),
			4 => __( 'Thursday', 'beacon-bookings' ),
			5 => __( 'Friday', 'beacon-bookings' ),
			6 => __( 'Saturday', 'beacon-bookings' ),
			7 => __( 'Sunday', 'beacon-bookings' ),
		);
	}

	/**
	 * Render the weekly-hours and date-overrides repeaters.
	 *
	 * @param string $prefix Field name prefix, e.g. 'beacon_bookings_rules'.
	 * @param array  $rules  Canonical rules.
	 */
	public static function render( string $prefix, array $rules ): void {
		$days   = self::day_names();
		$weekly = array();
		foreach ( (array) ( $rules['weekly'] ?? array() ) as $d => $wins ) {
			foreach ( (array) $wins as $w ) {
				$weekly[] = array( 'day' => (int) $d, 'from' => Rules::to_hhmm( (int) $w[0] ), 'to' => Rules::to_hhmm( (int) $w[1] ) );
			}
		}
		usort( $weekly, static fn( $a, $b ) => ( $a['day'] <=> $b['day'] ) ?: strcmp( $a['from'], $b['from'] ) );
		$overrides = (array) ( $rules['overrides'] ?? array() );
		?>
		<div class="bkb-rules" data-prefix="<?php echo esc_attr( $prefix ); ?>">
			<h4><?php esc_html_e( 'Weekly hours', 'beacon-bookings' ); ?></h4>
			<p class="description"><?php esc_html_e( 'When bookings can start and must finish. Add several rows for a day to leave a gap (for example a lunch break).', 'beacon-bookings' ); ?></p>
			<table class="widefat bkb-repeater" data-kind="weekly">
				<thead><tr><th><?php esc_html_e( 'Day', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'From', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'To', 'beacon-bookings' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $weekly as $i => $row ) : ?>
					<tr>
						<td><select name="<?php echo esc_attr( $prefix ); ?>[weekly][<?php echo (int) $i; ?>][day]">
							<?php foreach ( $days as $n => $label ) : ?>
								<option value="<?php echo (int) $n; ?>" <?php selected( $n, $row['day'] ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
						<td><input type="time" step="300" name="<?php echo esc_attr( $prefix ); ?>[weekly][<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $row['from'] ); ?>"></td>
						<td><input type="time" step="300" name="<?php echo esc_attr( $prefix ); ?>[weekly][<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( '24:00' === $row['to'] ? '23:59' : $row['to'] ); ?>"></td>
						<td><button type="button" class="button-link-delete bkb-remove"><?php esc_html_e( 'Remove', 'beacon-bookings' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button bkb-add" data-kind="weekly"><?php esc_html_e( 'Add hours', 'beacon-bookings' ); ?></button>
			<button type="button" class="button bkb-add-weekdays" data-kind="weekly"><?php esc_html_e( 'Add Mon–Fri 9–5', 'beacon-bookings' ); ?></button></p>

			<h4><?php esc_html_e( 'Date overrides', 'beacon-bookings' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Close dates (holidays) or open extra ones. Leave the times empty for the whole day. Later rows win over earlier rows.', 'beacon-bookings' ); ?></p>
			<table class="widefat bkb-repeater" data-kind="overrides">
				<thead><tr><th><?php esc_html_e( 'From date', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'To date', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'Status', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'From time', 'beacon-bookings' ); ?></th><th><?php esc_html_e( 'To time', 'beacon-bookings' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $overrides as $i => $o ) : ?>
					<?php $w = ! empty( $o['windows'] ) ? $o['windows'][0] : null; ?>
					<tr>
						<td><input type="date" name="<?php echo esc_attr( $prefix ); ?>[overrides][<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $o['from'] ); ?>"></td>
						<td><input type="date" name="<?php echo esc_attr( $prefix ); ?>[overrides][<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( $o['to'] ); ?>"></td>
						<td><select name="<?php echo esc_attr( $prefix ); ?>[overrides][<?php echo (int) $i; ?>][bookable]">
							<option value="0" <?php selected( empty( $o['beacon_bookable'] ) ); ?>><?php esc_html_e( 'Not available', 'beacon-bookings' ); ?></option>
							<option value="1" <?php selected( ! empty( $o['beacon_bookable'] ) ); ?>><?php esc_html_e( 'Available', 'beacon-bookings' ); ?></option>
						</select></td>
						<td><input type="time" step="300" name="<?php echo esc_attr( $prefix ); ?>[overrides][<?php echo (int) $i; ?>][time_from]" value="<?php echo esc_attr( $w ? Rules::to_hhmm( (int) $w[0] ) : '' ); ?>"></td>
						<td><input type="time" step="300" name="<?php echo esc_attr( $prefix ); ?>[overrides][<?php echo (int) $i; ?>][time_to]" value="<?php echo esc_attr( $w ? ( 1440 === (int) $w[1] ? '23:59' : Rules::to_hhmm( (int) $w[1] ) ) : '' ); ?>"></td>
						<td><button type="button" class="button-link-delete bkb-remove"><?php esc_html_e( 'Remove', 'beacon-bookings' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button bkb-add" data-kind="overrides"><?php esc_html_e( 'Add date override', 'beacon-bookings' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Canonical rules from a submitted (already unslashed) form array under $prefix.
	 * '23:59' as an end time is treated as end of day.
	 */
	public static function sanitize( $submitted ): array {
		$raw = array( 'weekly' => array(), 'overrides' => array() );
		foreach ( (array) ( $submitted['weekly'] ?? array() ) as $row ) {
			$to = sanitize_text_field( (string) ( $row['to'] ?? '' ) );
			if ( '23:59' === $to ) {
				$to = '24:00';
			}
			$raw['weekly'][] = array(
				'day'  => (int) ( $row['day'] ?? 0 ),
				'from' => sanitize_text_field( (string) ( $row['from'] ?? '' ) ),
				'to'   => $to,
			);
		}
		foreach ( (array) ( $submitted['overrides'] ?? array() ) as $row ) {
			$tt = sanitize_text_field( (string) ( $row['time_to'] ?? '' ) );
			if ( '23:59' === $tt ) {
				$tt = '24:00';
			}
			$raw['overrides'][] = array(
				'from'      => sanitize_text_field( (string) ( $row['from'] ?? '' ) ),
				'to'        => sanitize_text_field( (string) ( $row['to'] ?? '' ) ),
				'beacon_bookable'  => sanitize_text_field( (string) ( $row['beacon_bookable'] ?? '0' ) ),
				'time_from' => sanitize_text_field( (string) ( $row['time_from'] ?? '' ) ),
				'time_to'   => $tt,
			);
		}
		return Rules::normalize( $raw );
	}
}
