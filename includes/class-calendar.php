<?php
/**
 * Multi-product availability calendar: [beacon_bookings_calendar] shortcode and the "Booking Calendar" block.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Calendar {

	public static function register(): void {
		add_shortcode( 'beacon_bookings_calendar', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'beacon_bookings_status_changed', array( __CLASS__, 'bump' ) );
	}

	public static function block(): void {
		register_block_type(
			BEACON_BOOKINGS_DIR . 'blocks/calendar',
			array(
				'render_callback' => array( __CLASS__, 'block_render' ),
			)
		);
	}

	public static function bump(): void {
		update_option( 'beacon_bookings_cal_ver', (int) get_option( 'beacon_bookings_cal_ver', 0 ) + 1, false );
	}

	public static function block_render( array $attributes ): string {
		return self::shortcode(
			array(
				'products' => (string) ( $attributes['products'] ?? '' ),
				'months'   => (int) ( $attributes['months'] ?? 1 ),
			)
		);
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'products' => '',
				'months'   => 1,
			),
			$atts,
			'beacon_bookings_calendar'
		);
		Frontend::enqueue();
		$tz = wp_timezone();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month navigation.
		$month = isset( $_GET['beacon_bookings_month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['beacon_bookings_month'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['beacon_bookings_month'] ) ) : ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $atts['products'] ) ) );
		if ( empty( $ids ) ) {
			$ids = wc_get_products(
				array(
					'type'    => Product::TYPE,
					'status'  => 'publish',
					'limit'   => 12,
					'return'  => 'ids',
					'orderby' => 'title',
					'order'   => 'ASC',
				)
			);
		}
		$ids = array_slice( $ids, 0, 12 );
		if ( empty( $ids ) ) {
			return '<p class="bkb-note">' . esc_html__( 'No bookable products to show.', 'beacon-bookings' ) . '</p>';
		}
		$out = '';
		$n   = max( 1, min( 3, (int) $atts['months'] ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$m    = ( new \DateTimeImmutable( $month . '-01', $tz ) )->modify( '+' . $i . ' months' );
			$out .= self::month_html( $m, $ids, 0 === $i, $n );
		}
		return '<div class="bkb-multi-wrap">' . $out . '</div>';
	}

	private static function month_html( \DateTimeImmutable $first, array $ids, bool $nav, int $n ): string {
		$tz    = wp_timezone();
		$key   = 'beacon_bookings_cal_' . md5( implode( ',', $ids ) . '|' . $first->format( 'Y-m' ) . '|' . get_option( 'beacon_bookings_cal_ver', 0 ) );
		$html  = get_transient( $key );
		if ( is_string( $html ) && '' !== $html ) {
			return $html;
		}
		$last  = $first->modify( 'last day of this month' );
		$days  = array();
		$names = array();
		$links = array();
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || Product::TYPE !== $product->get_type() || ! $product->is_purchasable() ) {
				continue;
			}
			$cfg           = Bookings::config( $product );
			$av            = Bookings::availability( $product );
			$existing      = Bookings::existing( $product, $first, $last->modify( '+1 day' ) );
			$days[ $pid ]  = $av->summary( $first, $last, $cfg->min_blocks, $cfg->persons_as_qty ? max( 1, $cfg->persons_min ) : 1, null, $existing );
			$names[ $pid ] = $product->get_name();
			$links[ $pid ] = $product->get_permalink();
		}
		if ( empty( $days ) ) {
			return '';
		}
		global $wp_locale;
		$sow  = (int) get_option( 'start_of_week', 0 );
		$lead = ( (int) $first->format( 'w' ) - $sow + 7 ) % 7;
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		ob_start();
		?>
		<div class="bkb-multi-head">
			<?php if ( $nav ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'beacon_bookings_month', $first->modify( '-1 month' )->format( 'Y-m' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'beacon-bookings' ); ?>">‹</a>
			<?php endif; ?>
			<strong><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp(), $tz ) ); ?></strong>
			<?php if ( $nav ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'beacon_bookings_month', $first->modify( '+' . $n . ' months' )->format( 'Y-m' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'beacon-bookings' ); ?>">›</a>
			<?php endif; ?>
		</div>
		<div class="bkb-multi bkb-cal-grid">
			<?php for ( $i = 0; $i < 7; $i++ ) : ?>
				<div class="bkb-wd"><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $sow + $i ) % 7 ) ) ); ?></div>
			<?php endfor; ?>
			<?php for ( $i = 0; $i < $lead; $i++ ) : ?><div class="bkb-day bkb-empty"></div><?php endfor; ?>
			<?php for ( $d = $first; $d <= $last; $d = $d->modify( '+1 day' ) ) : ?>
				<?php $k = $d->format( 'Y-m-d' ); ?>
				<div class="bkb-day <?php echo $k === $today ? 'bkb-today' : ''; ?>">
					<span class="bkb-num"><?php echo (int) $d->format( 'j' ); ?></span>
					<div class="bkb-dots">
					<?php foreach ( $days as $pid => $summary ) : ?>
						<?php $ok = ! empty( $summary[ $k ]['available'] ); ?>
						<?php if ( $ok ) : ?>
							<a class="bkb-dot" href="<?php echo esc_url( add_query_arg( 'beacon_bookings_date', $k, $links[ $pid ] ) ); ?>"><?php echo esc_html( $names[ $pid ] ); ?></a>
						<?php elseif ( $k >= $today ) : ?>
							<span class="bkb-dot bkb-full" title="<?php esc_attr_e( 'Unavailable', 'beacon-bookings' ); ?>"><?php echo esc_html( $names[ $pid ] ); ?></span>
						<?php endif; ?>
					<?php endforeach; ?>
					</div>
				</div>
			<?php endfor; ?>
		</div>
		<?php
		$html = (string) ob_get_clean();
		set_transient( $key, $html, 5 * MINUTE_IN_SECONDS );
		return $html;
	}
}
