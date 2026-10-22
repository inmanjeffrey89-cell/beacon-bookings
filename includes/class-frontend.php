<?php
/**
 * Product page: the booking form and its assets (loaded only where used).
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function register(): void {
		add_action( 'woocommerce_' . Product::TYPE . '_add_to_cart', array( __CLASS__, 'form' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( $product && Product::TYPE === $product->get_type() ) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'beacon-bookings-frontend', BEACON_BOOKINGS_URL . 'assets/css/frontend.css', array(), BEACON_BOOKINGS_VERSION );
		wp_enqueue_script( 'beacon-bookings-booking-form', BEACON_BOOKINGS_URL . 'assets/js/booking-form.js', array(), BEACON_BOOKINGS_VERSION, true );
	}

	/** Hook-safe wrapper so the theme can call it from templates. */
	public static function form(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || Product::TYPE !== $product->get_type() ) {
			return;
		}
		if ( ! $product->is_purchasable() ) {
			return;
		}
		self::enqueue();
		$cfg    = Bookings::config( $product );
		$tz     = wp_timezone();
		$today  = new \DateTimeImmutable( 'now', $tz );
		$months = $cfg->max_advance > 0 ? max( 1, (int) ceil( $cfg->max_advance / 1440 / 28 ) ) : 12;
		global $wp_locale;
		$weekdays = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$weekdays[] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) );
		}
		$resources = array();
		foreach ( $cfg->resources as $id => $r ) {
			$resources[] = array(
				'id'    => (int) $id,
				'label' => (string) $r['label'],
			);
		}
		$preselect = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only convenience parameter, validated as a date.
		if ( isset( $_GET['beacon_bookings_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['beacon_bookings_date'] ) ), $m ) ) {
			$preselect = $m[0];
		}
		$data = array(
			'restUrl'      => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'product'      => $product->get_id(),
			'unit'         => $cfg->unit,
			'isDay'        => $cfg->is_day_unit(),
			'durationDays' => $cfg->is_day_unit() ? $cfg->duration : 0,
			'durationType' => $cfg->duration_type,
			'minBlocks'    => $cfg->min_blocks,
			'maxBlocks'    => $cfg->max_blocks,
			'unitLabel'    => Bookings::unit_label( $product ),
			'persons'      => array(
				'enabled' => $cfg->persons_enabled(),
				'min'     => max( 1, $cfg->persons_min ),
				'max'     => $cfg->persons_max,
			),
			'assignment'   => $cfg->assignment,
			'resources'    => $resources,
			'firstDay'     => (int) get_option( 'start_of_week', 0 ),
			'weekdays'     => $weekdays,
			'locale'       => str_replace( '_', '-', get_locale() ),
			'today'        => $today->format( 'Y-m-d' ),
			'months'       => $months,
			'preselect'    => $preselect,
			'i18n'         => array(
				'loading'     => __( 'Loading availability…', 'beacon-bookings' ),
				'noSlots'     => __( 'No times available on this day.', 'beacon-bookings' ),
				'pickDay'     => __( 'Pick a day to see times.', 'beacon-bookings' ),
				'pickStart'   => __( 'Pick a start date.', 'beacon-bookings' ),
				'available'   => __( 'Available', 'beacon-bookings' ),
				'unavailable' => __( 'Unavailable', 'beacon-bookings' ),
				/* translators: %d: remaining spaces */
				'left'        => __( '%d left', 'beacon-bookings' ),
				'total'       => __( 'Total', 'beacon-bookings' ),
				'prev'        => __( 'Previous month', 'beacon-bookings' ),
				'next'        => __( 'Next month', 'beacon-bookings' ),
				'error'       => __( 'Could not load availability. Please reload the page.', 'beacon-bookings' ),
				'to'          => __( 'to', 'beacon-bookings' ),
				'checkout'    => __( 'check-out', 'beacon-bookings' ),
			),
		);
		?>
		<form class="cart bkb-form" method="post" enctype="multipart/form-data" data-bkb="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
			<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>
			<div class="bkb-controls">
				<?php if ( 'customer' === $cfg->duration_type ) : ?>
					<label class="bkb-control"><span><?php esc_html_e( 'Duration', 'beacon-bookings' ); ?></span>
						<select name="beacon_bookings_blocks" class="bkb-blocks">
							<?php foreach ( self::block_choices( $cfg, $product ) as $b ) : ?>
								<option value="<?php echo (int) $b; ?>"><?php echo esc_html( self::blocks_label( $product, $b ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( $cfg->persons_enabled() ) : ?>
					<label class="bkb-control"><span><?php echo esc_html( Bookings::count_label( $product ) ?: __( 'People', 'beacon-bookings' ) ); ?></span>
						<input type="number" name="beacon_bookings_persons" class="bkb-persons" min="<?php echo (int) max( 1, $cfg->persons_min ); ?>" max="<?php echo (int) $cfg->persons_max; ?>" value="<?php echo (int) max( 1, $cfg->persons_min ); ?>" inputmode="numeric">
					</label>
				<?php endif; ?>
				<?php if ( 'customer' === $cfg->assignment ) : ?>
					<label class="bkb-control"><span><?php echo esc_html( $product->get_meta( '_beacon_bookings_resource_label', true ) ?: __( 'Option', 'beacon-bookings' ) ); ?></span>
						<select name="beacon_bk_resource" class="bkb-resource" required>
							<option value=""><?php esc_html_e( 'Choose…', 'beacon-bookings' ); ?></option>
							<?php foreach ( $resources as $r ) : ?>
								<option value="<?php echo (int) $r['id']; ?>"><?php echo esc_html( $r['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</div>
			<div class="bkb-calendar" aria-live="polite"><p class="bkb-note"><?php esc_html_e( 'Loading availability…', 'beacon-bookings' ); ?></p></div>
			<div class="bkb-slots" aria-live="polite"></div>
			<div class="bkb-summary" aria-live="polite"><span class="bkb-selected"></span><span class="bkb-total"></span></div>
			<input type="hidden" name="beacon_bookings_start" class="bkb-start" value="">
			<input type="hidden" name="add-to-cart" value="<?php echo (int) $product->get_id(); ?>">
			<button type="submit" class="single_add_to_cart_button button alt wp-element-button bkb-submit" disabled><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>
			<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
		</form>
		<?php
	}

	/**
	 * Block counts offered in the Duration select. Overnight hour/minute rentals keep every length up to a day plus whole
	 * days, so "4 hours … a week" doesn't become a 168-row list.
	 */
	private static function block_choices( $cfg, \WC_Product $product ): array {
		$lengths = Bookings::set_lengths( $product );
		if ( $lengths ) {
			return $lengths; // "Only these lengths": exactly the lengths that have their own price.
		}
		$all      = range( $cfg->min_blocks, $cfg->max_blocks );
		$hour_per = 'hour' === $cfg->unit ? $cfg->duration : ( 'minute' === $cfg->unit ? $cfg->duration / 60 : 0 );
		if ( ! $cfg->overnight || ! $hour_per ) {
			return $all;
		}
		return array_values( array_filter( $all, static fn( $b ) => $b === $cfg->min_blocks || $b * $hour_per <= 24 || self::whole_days( $b * $hour_per ) ) );
	}

	/** True when a length in hours is a whole number of days (fmod returns a float, so never compare it with === 0). */
	private static function whole_days( float $hours ): bool {
		return abs( fmod( $hours, 24 ) ) < 1e-9;
	}

	/** "3 hours", "2 days" (overnight hour rentals in whole days), "5 nights": a length in the product's own unit. */
	public static function blocks_label( \WC_Product $product, int $blocks ): string {
		$cfg = Bookings::config( $product );
		$n   = $blocks * $cfg->duration;
		$h   = 'hour' === $cfg->unit ? $n : ( 'minute' === $cfg->unit ? $n / 60 : 0 );
		if ( $cfg->overnight && $h >= 24 && self::whole_days( $h ) ) {
			$d = (int) ( $h / 24 );
			/* translators: %d: days */
			return sprintf( _n( '%d day', '%d days', $d, 'beacon-bookings' ), $d );
		}
		switch ( $cfg->unit ) {
			case 'minute':
				/* translators: %d: minutes */
				return sprintf( _n( '%d minute', '%d minutes', $n, 'beacon-bookings' ), $n );
			case 'hour':
				/* translators: %d: hours */
				return sprintf( _n( '%d hour', '%d hours', $n, 'beacon-bookings' ), $n );
			case 'night':
				/* translators: %d: nights */
				return sprintf( _n( '%d night', '%d nights', $n, 'beacon-bookings' ), $n );
			default:
				/* translators: %d: days */
				return sprintf( _n( '%d day', '%d days', $n, 'beacon-bookings' ), $n );
		}
	}
}
