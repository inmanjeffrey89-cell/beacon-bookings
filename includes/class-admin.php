<?php
/**
 * Bookings admin: menu, list, calendar, manual booking, settings, actions.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'beacon-bookings';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_beacon_bookings_booking_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_beacon_bookings_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_beacon_bookings_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_beacon_bookings_settings', array( __CLASS__, 'handle_settings' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
	}

	public static function menu(): void {
		add_menu_page( __( 'Bookings', 'beacon-bookings' ), __( 'Bookings', 'beacon-bookings' ), 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page_list' ), 'dashicons-calendar-alt', 56 );
		add_submenu_page( self::SLUG, __( 'All Bookings', 'beacon-bookings' ), __( 'All Bookings', 'beacon-bookings' ), 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page_list' ) );
		add_submenu_page( self::SLUG, __( 'Calendar', 'beacon-bookings' ), __( 'Calendar', 'beacon-bookings' ), 'manage_woocommerce', 'beacon-bookings-calendar', array( __CLASS__, 'page_calendar' ) );
		add_submenu_page( self::SLUG, __( 'Add Booking', 'beacon-bookings' ), __( 'Add Booking', 'beacon-bookings' ), 'manage_woocommerce', 'beacon-bookings-add', array( __CLASS__, 'page_add' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'beacon-bookings' ), __( 'Settings', 'beacon-bookings' ), 'manage_woocommerce', 'beacon-bookings-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function is_our_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return false !== strpos( (string) $screen->id, 'beacon-bookings-' ) || Resources::CPT === $screen->post_type;
	}

	public static function assets(): void {
		if ( self::is_our_screen() ) {
			wp_enqueue_style( 'beacon-bookings-admin', BEACON_BOOKINGS_URL . 'assets/css/admin.css', array(), BEACON_BOOKINGS_VERSION );
			wp_enqueue_script( 'beacon-bookings-admin-list', BEACON_BOOKINGS_URL . 'assets/js/admin-list.js', array(), BEACON_BOOKINGS_VERSION, true );
		}
	}

	public static function footer_text( $text ) {
		if ( ! self::is_our_screen() ) {
			return $text;
		}
		return wp_kses_post(
			sprintf(
				/* translators: %s: link to the review form */
				__( 'Enjoying Beacon Bookings? A quick <a href="%s" target="_blank" rel="noopener">review</a> helps other shops find it.', 'beacon-bookings' ),
				'https://wordpress.org/support/plugin/beacon-bookings/reviews/#new-post'
			)
		);
	}

	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirects.
		if ( empty( $_GET['beacon_bookings_msg'] ) ) {
			return;
		}
		$msg  = sanitize_key( wp_unslash( $_GET['beacon_bookings_msg'] ) );
		$text = isset( $_GET['beacon_bookings_text'] ) ? sanitize_text_field( wp_unslash( $_GET['beacon_bookings_text'] ) ) : '';
		// phpcs:enable
		$map = array(
			'updated' => __( 'Booking updated.', 'beacon-bookings' ),
			'bulk'    => __( 'Bookings updated.', 'beacon-bookings' ),
			'added'   => __( 'Booking added.', 'beacon-bookings' ),
			'saved'   => __( 'Settings saved.', 'beacon-bookings' ),
			'error'   => $text ?: __( 'Something went wrong.', 'beacon-bookings' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $msg ? 'error' : 'success', esc_html( $map[ $msg ] ) );
		}
	}

	private static function redirect( string $page, string $msg, string $text = '' ): void {
		$url = add_query_arg( array( 'page' => $page, 'beacon_bookings_msg' => $msg ), admin_url( 'admin.php' ) );
		if ( $text ) {
			$url = add_query_arg( 'beacon_bookings_text', rawurlencode( $text ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------- list */

	public static function page_list(): void {
		$table = new Admin_List();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'beacon-bookings' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=beacon-bookings-add' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add booking', 'beacon-bookings' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notice(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php $table->search_box( __( 'Search name, email, order #', 'beacon-bookings' ), 'bkb' ); ?>
				<?php $table->display(); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="beacon-bookings-bulk-form" style="display:none">
				<input type="hidden" name="action" value="beacon_bookings_bulk">
				<?php wp_nonce_field( 'beacon_bookings_bulk', 'beacon_bookings_bulk_nonce' ); ?>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------- calendar */

	public static function page_calendar(): void {
		$tz = wp_timezone();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameters.
		$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['month'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );
		$day   = isset( $_GET['day'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['day'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
		$pid   = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		// phpcs:enable
		$first = new \DateTimeImmutable( $month . '-01', $tz );
		$last  = $first->modify( 'last day of this month' );
		$rows  = Repo::in_local_range( $first->format( 'Y-m-d 00:00:00' ), $last->format( 'Y-m-d 23:59:59' ), $pid, 0, false );
		$by    = array();
		foreach ( $rows as $r ) {
			$s = new \DateTimeImmutable( $r['start_local'], $tz );
			$e = new \DateTimeImmutable( $r['end_local'], $tz );
			if ( $r['all_day'] ) {
				$e = $e->modify( '-1 second' );
			}
			for ( $d = $s->setTime( 0, 0 ); $d <= $e; $d = $d->modify( '+1 day' ) ) {
				$by[ $d->format( 'Y-m-d' ) ][] = $r;
			}
		}
		$products = wc_get_products( array( 'type' => Product::TYPE, 'limit' => 200, 'status' => array( 'publish', 'private', 'draft' ), 'orderby' => 'title', 'order' => 'ASC' ) );
		$labels   = Repo::labels();
		global $wp_locale;
		$start_of_week = (int) get_option( 'start_of_week', 0 );
		$base_url      = admin_url( 'admin.php?page=beacon-bookings-calendar' . ( $pid ? '&product=' . $pid : '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Booking calendar', 'beacon-bookings' ); ?></h1>
			<?php self::notice(); ?>
			<div class="bkb-admin-cal-head">
				<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $first->modify( '-1 month' )->format( 'Y-m' ), $base_url ) ); ?>">‹</a>
				<h2><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp(), $tz ) ); ?></h2>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'month', $first->modify( '+1 month' )->format( 'Y-m' ), $base_url ) ); ?>">›</a>
				<form method="get" style="margin-left:auto">
					<input type="hidden" name="page" value="beacon-bookings-calendar"><input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
					<select name="product" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All products', 'beacon-bookings' ); ?></option>
						<?php foreach ( $products as $p ) : ?>
							<option value="<?php echo (int) $p->get_id(); ?>" <?php selected( $pid, $p->get_id() ); ?>><?php echo esc_html( $p->get_name() ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<?php if ( $day ) : ?>
				<h2><?php echo esc_html( wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( $day, $tz ) )->getTimestamp(), $tz ) ); ?></h2>
				<div class="bkb-day-list">
				<?php if ( empty( $by[ $day ] ) ) : ?>
					<p><?php esc_html_e( 'No bookings on this day.', 'beacon-bookings' ); ?></p>
				<?php else : ?>
					<?php foreach ( $by[ $day ] as $r ) : ?>
						<?php $product = wc_get_product( $r['product_id'] ); ?>
						<a class="bkb-ev bkb-ev-<?php echo esc_attr( $r['status'] ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&s=' . (int) $r['id'] ) ); ?>">
							#<?php echo (int) $r['id']; ?> · <?php echo esc_html( Bookings::when( $r, $product ?: null ) ); ?> · <?php echo esc_html( $product ? $product->get_name() : '' ); ?> · <?php echo esc_html( $r['customer_name'] ?: $r['customer_email'] ); ?> · <?php echo esc_html( $labels[ $r['status'] ] ?? $r['status'] ); ?>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
				</div>
				<p><a href="<?php echo esc_url( add_query_arg( 'month', $month, $base_url ) ); ?>">← <?php esc_html_e( 'Back to month', 'beacon-bookings' ); ?></a></p>
			<?php else : ?>
				<div class="bkb-admin-cal">
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<div class="bkb-wd"><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $start_of_week + $i ) % 7 ) ) ); ?></div>
					<?php endfor; ?>
					<?php
					$lead  = ( (int) $first->format( 'w' ) - $start_of_week + 7 ) % 7;
					$today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
					for ( $i = 0; $i < $lead; $i++ ) {
						echo '<div class="bkb-cell bkb-other"></div>';
					}
					for ( $d = $first; $d <= $last; $d = $d->modify( '+1 day' ) ) :
						$k = $d->format( 'Y-m-d' );
						?>
						<div class="bkb-cell <?php echo $k === $today ? 'bkb-today' : ''; ?>">
							<span class="bkb-num"><a href="<?php echo esc_url( add_query_arg( array( 'month' => $month, 'day' => $k ), $base_url ) ); ?>"><?php echo (int) $d->format( 'j' ); ?></a></span>
							<?php foreach ( array_slice( $by[ $k ] ?? array(), 0, 4 ) as $r ) : ?>
								<?php $product = wc_get_product( $r['product_id'] ); ?>
								<a class="bkb-ev bkb-ev-<?php echo esc_attr( $r['status'] ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&s=' . (int) $r['id'] ) ); ?>" title="<?php echo esc_attr( Bookings::describe( $r, $product ?: null ) ); ?>">
									<?php echo esc_html( $r['all_day'] ? '' : wp_date( get_option( 'time_format' ), ( new \DateTimeImmutable( $r['start_local'], $tz ) )->getTimestamp(), $tz ) . ' ' ); ?><?php echo esc_html( $product ? $product->get_name() : '' ); ?><?php echo $r['customer_name'] ? ' · ' . esc_html( $r['customer_name'] ) : ''; ?>
								</a>
							<?php endforeach; ?>
							<?php if ( count( $by[ $k ] ?? array() ) > 4 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'month' => $month, 'day' => $k ), $base_url ) ); ?>">
								<?php /* translators: %d: number of additional bookings */ echo esc_html( sprintf( __( '+%d more', 'beacon-bookings' ), count( $by[ $k ] ) - 4 ) ); ?></a>
							<?php endif; ?>
						</div>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- add */

	public static function page_add(): void {
		$products  = wc_get_products( array( 'type' => Product::TYPE, 'limit' => 200, 'status' => array( 'publish', 'private', 'draft' ), 'orderby' => 'title', 'order' => 'ASC' ) );
		$resources = Resources::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add booking', 'beacon-bookings' ); ?></h1>
			<?php self::notice(); ?>
			<p class="description"><?php esc_html_e( 'Create a booking by hand — for a phone reservation, or to block time off. Manual bookings do not create an order.', 'beacon-bookings' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="beacon_bookings_add">
				<?php wp_nonce_field( 'beacon_bookings_add', 'beacon_bookings_add_nonce' ); ?>
				<table class="form-table">
					<tr><th><label for="beacon_bookings_product"><?php esc_html_e( 'Product', 'beacon-bookings' ); ?></label></th><td>
						<select name="product_id" id="beacon_bookings_product" required>
							<option value=""><?php esc_html_e( 'Choose…', 'beacon-bookings' ); ?></option>
							<?php foreach ( $products as $p ) : ?>
								<option value="<?php echo (int) $p->get_id(); ?>"><?php echo esc_html( $p->get_name() ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( empty( $products ) ) : ?><p class="description"><?php esc_html_e( 'No bookable products yet. Create a product and set its type to "Bookable product".', 'beacon-bookings' ); ?></p><?php endif; ?>
					</td></tr>
					<tr><th><label for="beacon_bookings_date"><?php esc_html_e( 'Start', 'beacon-bookings' ); ?></label></th><td>
						<input type="date" name="date" id="beacon_bookings_date" required> <input type="time" name="time" step="300" aria-label="<?php esc_attr_e( 'Start time', 'beacon-bookings' ); ?>">
						<p class="description"><?php esc_html_e( 'Leave the time empty for day and night products.', 'beacon-bookings' ); ?></p>
					</td></tr>
					<tr><th><label for="beacon_bookings_blocks"><?php esc_html_e( 'Blocks', 'beacon-bookings' ); ?></label></th><td><input type="number" min="1" step="1" value="1" name="blocks" id="beacon_bookings_blocks" class="small-text"> <span class="description"><?php esc_html_e( 'Hours / nights / days, in the product\'s block unit.', 'beacon-bookings' ); ?></span></td></tr>
					<tr><th><label for="beacon_bookings_persons"><?php esc_html_e( 'People', 'beacon-bookings' ); ?></label></th><td><input type="number" min="1" step="1" value="1" name="persons" id="beacon_bookings_persons" class="small-text"></td></tr>
					<tr><th><label for="beacon_bk_resource"><?php esc_html_e( 'Resource', 'beacon-bookings' ); ?></label></th><td>
						<select name="resource_id" id="beacon_bk_resource">
							<option value="0"><?php esc_html_e( 'Automatic / none', 'beacon-bookings' ); ?></option>
							<?php foreach ( $resources as $id => $title ) : ?>
								<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th><label for="beacon_bookings_status"><?php esc_html_e( 'Status', 'beacon-bookings' ); ?></label></th><td>
						<select name="status" id="beacon_bookings_status">
							<?php foreach ( array( 'confirmed', 'paid', 'unpaid', 'pending' ) as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( Repo::labels()[ $s ] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th><label for="beacon_bookings_name"><?php esc_html_e( 'Customer name', 'beacon-bookings' ); ?></label></th><td><input type="text" name="customer_name" id="beacon_bookings_name" class="regular-text"></td></tr>
					<tr><th><label for="beacon_bookings_email"><?php esc_html_e( 'Customer email', 'beacon-bookings' ); ?></label></th><td><input type="email" name="customer_email" id="beacon_bookings_email" class="regular-text"> <span class="description"><?php esc_html_e( 'Leave both empty to block the time without a customer.', 'beacon-bookings' ); ?></span></td></tr>
					<tr><th><label for="beacon_bookings_note"><?php esc_html_e( 'Note', 'beacon-bookings' ); ?></label></th><td><textarea name="note" id="beacon_bookings_note" class="large-text" rows="3"></textarea></td></tr>
					<tr><th><?php esc_html_e( 'Availability', 'beacon-bookings' ); ?></th><td><label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'Skip the availability check (overbook on purpose)', 'beacon-bookings' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Add booking', 'beacon-bookings' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_add(): void {
		if ( ! Plugin::can_manage() || ! isset( $_POST['beacon_bookings_add_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beacon_bookings_add_nonce'] ) ), 'beacon_bookings_add' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beacon-bookings' ) );
		}
		$p       = beacon_bookings_clean_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deep-sanitised here, cast per field below.
		$product = wc_get_product( absint( $p['product_id'] ?? 0 ) );
		if ( ! $product || Product::TYPE !== $product->get_type() ) {
			self::redirect( 'beacon-bookings-add', 'error', __( 'Choose a bookable product.', 'beacon-bookings' ) );
		}
		$date  = sanitize_text_field( (string) ( $p['date'] ?? '' ) );
		$time  = sanitize_text_field( (string) ( $p['time'] ?? '' ) );
		$cfg   = Bookings::config( $product );
		$start = $cfg->is_day_unit() || '' === $time ? $date : $date . ' ' . $time;
		$req   = Bookings::request(
			$product,
			array(
				'beacon_bookings_start'    => $start,
				'beacon_bookings_blocks'   => absint( $p['blocks'] ?? 1 ),
				'beacon_bookings_persons'  => absint( $p['persons'] ?? 1 ),
				'beacon_bk_resource' => absint( $p['resource_id'] ?? 0 ),
			)
		);
		if ( ! $req ) {
			self::redirect( 'beacon-bookings-add', 'error', __( 'Invalid start date or time.', 'beacon-bookings' ) );
		}
		$status = sanitize_key( $p['status'] ?? 'confirmed' );
		if ( ! in_array( $status, array( 'confirmed', 'paid', 'unpaid', 'pending' ), true ) ) {
			$status = 'confirmed';
		}
		$force = ! empty( $p['force'] );
		$av    = Bookings::availability( $product, $force ? new \DateTimeImmutable( '1970-01-02', wp_timezone() ) : null );
		list( $s, $e ) = $av->span( $req['start'], $req['blocks'] );
		if ( $force ) {
			if ( ! $av->is_open( $s, $e ) && $req['start'] instanceof \DateTimeImmutable ) {
				// Forced bookings may sit outside the hours; keep the span, skip the rules.
				$result = \Beacon\Bookings\Engine\Result::pass( $s, $e, (int) $req['resource_id'], 0 );
			} else {
				$result = $av->check( $req['start'], $req['blocks'], $req['qty'], $req['resource_id'] ?: null, array() );
				if ( ! $result->ok ) {
					$result = \Beacon\Bookings\Engine\Result::pass( $s, $e, (int) $req['resource_id'], 0 );
				}
			}
		} else {
			$result = Bookings::check( $product, $req );
			if ( ! $result->ok ) {
				self::redirect( 'beacon-bookings-add', 'error', Bookings::reason_text( $result->reason ) );
			}
		}
		$row                   = Bookings::row_from_result( $product, $req, $result );
		$row['status']         = $status;
		$row['customer_name']  = sanitize_text_field( (string) ( $p['customer_name'] ?? '' ) );
		$row['customer_email'] = sanitize_email( (string) ( $p['customer_email'] ?? '' ) );
		$row['note']           = sanitize_textarea_field( (string) ( $p['note'] ?? '' ) );
		$id                    = Repo::insert( $row );
		if ( $id ) {
			do_action( 'beacon_bookings_status_changed', Repo::get( $id ), 'new', $status );
			self::redirect( self::SLUG, 'added' );
		}
		self::redirect( 'beacon-bookings-add', 'error' );
	}

	/* ---------------------------------------------------------- actions */

	/** Apply one action to one booking. Returns true when something changed. */
	public static function apply( int $id, string $do ): bool {
		$row = Repo::get( $id );
		if ( ! $row ) {
			return false;
		}
		switch ( $do ) {
			case 'confirm':
				return Bookings::transition( $id, 'confirmed', 'Confirmed by ' . wp_get_current_user()->user_login );
			case 'paid':
				return Bookings::transition( $id, 'paid', 'Marked paid by ' . wp_get_current_user()->user_login );
			case 'complete':
				return Bookings::transition( $id, 'complete', '' );
			case 'cancel':
				$ok = Bookings::transition( $id, 'cancelled', 'Cancelled by ' . wp_get_current_user()->user_login );
				if ( $ok && (int) $row['order_id'] > 0 ) {
					$order = wc_get_order( $row['order_id'] );
					if ( $order ) {
						/* translators: %d: booking id */
						$order->add_order_note( sprintf( __( 'Booking #%d cancelled from Bookings. Refund manually if needed.', 'beacon-bookings' ), $id ) );
					}
				}
				return $ok;
			case 'delete':
				if ( in_array( $row['status'], array( 'cancelled', 'hold' ), true ) ) {
					Repo::delete( $id );
					return true;
				}
				return false;
		}
		return false;
	}

	public static function handle_action(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		if ( ! Plugin::can_manage() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'beacon_bookings_action_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beacon-bookings' ) );
		}
		self::apply( $id, $do );
		self::redirect( self::SLUG, 'updated' );
	}

	public static function handle_bulk(): void {
		if ( ! Plugin::can_manage() || ! isset( $_POST['beacon_bookings_bulk_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beacon_bookings_bulk_nonce'] ) ), 'beacon_bookings_bulk' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beacon-bookings' ) );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		foreach ( $ids as $id ) {
			self::apply( $id, $do );
		}
		self::redirect( self::SLUG, 'bulk' );
	}

	/* --------------------------------------------------------- settings */

	public static function page_settings(): void {
		$s = Plugin::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Booking settings', 'beacon-bookings' ); ?></h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="beacon_bookings_settings">
				<?php wp_nonce_field( 'beacon_bookings_settings', 'beacon_bookings_settings_nonce' ); ?>
				<table class="form-table">
					<tr><th><label for="hold_minutes"><?php esc_html_e( 'Hold a slot in the cart for (minutes)', 'beacon-bookings' ); ?></label></th>
						<td><input type="number" min="5" max="180" name="hold_minutes" id="hold_minutes" value="<?php echo (int) $s['hold_minutes']; ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'A slot added to a cart is reserved for this long (renewed while the customer is on the cart or checkout). This is what prevents two people buying the last slot.', 'beacon-bookings' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Customer cancellation', 'beacon-bookings' ); ?></th>
						<td><label><input type="checkbox" name="customer_cancel" value="yes" <?php checked( 'yes', $s['customer_cancel'] ); ?>> <?php esc_html_e( 'Customers may cancel from My Account', 'beacon-bookings' ); ?></label><br>
						<label><?php esc_html_e( 'up to', 'beacon-bookings' ); ?> <input type="number" min="0" name="cancel_hours" value="<?php echo (int) $s['cancel_hours']; ?>" class="small-text"> <?php esc_html_e( 'hours before the start', 'beacon-bookings' ); ?></label>
						<p class="description"><?php esc_html_e( 'Cancelling frees the slot and adds a note to the order. Refunds stay a manual decision.', 'beacon-bookings' ); ?></p></td></tr>
					<tr><th><label for="reminder_hours"><?php esc_html_e( 'Reminder email (hours before)', 'beacon-bookings' ); ?></label></th>
						<td><input type="number" min="0" max="720" name="reminder_hours" id="reminder_hours" value="<?php echo (int) $s['reminder_hours']; ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0 switches reminders off. Sent by the hourly task.', 'beacon-bookings' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Calendar feeds (iCal)', 'beacon-bookings' ); ?></th>
						<td>
							<?php $key = Plugin::feed_key(); ?>
							<code><?php echo esc_html( Ics::feed_url( 'all', $key ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Subscribe to this address in Google Calendar, Apple Calendar or Outlook to see every booking. Per-product and per-resource feeds: add &product=ID or &resource=ID.', 'beacon-bookings' ); ?></p>
							<label><input type="checkbox" name="regen_key" value="1"> <?php esc_html_e( 'Generate a new key (old feed addresses stop working)', 'beacon-bookings' ); ?></label>
						</td></tr>
					<tr><th><?php esc_html_e( 'Uninstall', 'beacon-bookings' ); ?></th>
						<td><label><input type="checkbox" name="delete_on_uninstall" value="yes" <?php checked( 'yes', $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all bookings, resources and settings when the plugin is deleted', 'beacon-bookings' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_settings(): void {
		if ( ! Plugin::can_manage() || ! isset( $_POST['beacon_bookings_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beacon_bookings_settings_nonce'] ) ), 'beacon_bookings_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beacon-bookings' ) );
		}
		$p = beacon_bookings_clean_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deep-sanitised here, cast per field below.
		$s = Plugin::settings();
		$s['hold_minutes']        = min( 180, max( 5, absint( $p['hold_minutes'] ?? 15 ) ) );
		$s['customer_cancel']     = isset( $p['customer_cancel'] ) ? 'yes' : 'no';
		$s['cancel_hours']        = absint( $p['cancel_hours'] ?? 24 );
		$s['reminder_hours']      = min( 720, absint( $p['reminder_hours'] ?? 24 ) );
		$s['delete_on_uninstall'] = isset( $p['delete_on_uninstall'] ) ? 'yes' : 'no';
		if ( ! empty( $p['regen_key'] ) ) {
			$s['feed_key'] = wp_generate_password( 24, false, false );
		}
		update_option( Plugin::OPTION_SETTINGS, $s, false );
		self::redirect( 'beacon-bookings-settings', 'saved' );
	}
}
