<?php
/**
 * Product editor: the Booking / Availability / People / Resources tabs and saving.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

use Beacon\Bookings\Engine\Pricing;
use Beacon\Bookings\Engine\Rules;

defined( 'ABSPATH' ) || exit;

final class Product_Admin {

	public static function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panels' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_product_filters', array( __CLASS__, 'type_filter' ) );
	}

	public static function tabs( array $tabs ): array {
		$tabs['beacon_bookings_booking']      = array(
			'label'    => __( 'Booking', 'beacon-bookings' ),
			'target'   => 'beacon_bookings_booking_data',
			'class'    => array( 'show_if_beacon_bookable' ),
			'priority' => 11,
		);
		$tabs['beacon_bookings_availability'] = array(
			'label'    => __( 'Availability', 'beacon-bookings' ),
			'target'   => 'beacon_bookings_availability_data',
			'class'    => array( 'show_if_beacon_bookable' ),
			'priority' => 12,
		);
		$tabs['beacon_bookings_persons']      = array(
			'label'    => __( 'People', 'beacon-bookings' ),
			'target'   => 'beacon_bookings_persons_data',
			'class'    => array( 'show_if_beacon_bookable' ),
			'priority' => 13,
		);
		$tabs['beacon_bookings_resources']    = array(
			'label'    => __( 'Resources', 'beacon-bookings' ),
			'target'   => 'beacon_bookings_resources_data',
			'class'    => array( 'show_if_beacon_bookable' ),
			'priority' => 14,
		);
		if ( isset( $tabs['shipping'] ) ) {
			$tabs['shipping']['class'][] = 'hide_if_beacon_bookable';
		}
		/** Filter the product data tabs after Beacon Bookings adds its own (Pro adds Pricing). */
		return apply_filters( 'beacon_bookings_product_tabs', $tabs );
	}

	/** Keep the "Filter by product type" dropdown honest. */
	public static function type_filter( string $html ): string {
		return str_replace( '</select>', '<option value="bookable">' . esc_html__( 'Bookable product', 'beacon-bookings' ) . '</option></select>', $html );
	}

	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'product', Resources::CPT ), true ) ) {
			return;
		}
		wp_enqueue_style( 'beacon-bookings-admin', BEACON_BOOKINGS_URL . 'assets/css/admin.css', array(), BEACON_BOOKINGS_VERSION );
		wp_enqueue_script( 'beacon-bookings-admin-rules', BEACON_BOOKINGS_URL . 'assets/js/admin-rules.js', array(), BEACON_BOOKINGS_VERSION, true );
		wp_localize_script(
			'beacon-bookings-admin-rules',
			'beaconBookingsRulesL10n',
			array(
				'days'         => Rules_Ui::day_names(),
				'remove'       => __( 'Remove', 'beacon-bookings' ),
				'available'    => __( 'Available', 'beacon-bookings' ),
				'notAvailable' => __( 'Not available', 'beacon-bookings' ),
			)
		);
	}

	public static function panels(): void {
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		$get     = static function ( $key ) use ( $product ) {
			$v = $product ? $product->get_meta( $key, true ) : '';
			return ( '' === $v || null === $v ) ? Product::META[ $key ] : $v;
		};
		$unit  = $get( '_beacon_bookings_unit' );
		$rules = $get( '_beacon_bookings_rules' );
		if ( ! is_array( $rules ) || empty( $rules['weekly'] ) ) {
			$rules = Rules::defaults();
		}
		?>
		<div id="beacon_bookings_booking_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_block_cost',
						'label'             => __( 'Cost per block', 'beacon-bookings' ) . ' (' . get_woocommerce_currency_symbol() . ')',
						'value'             => $product ? wc_format_localized_price( $product->get_regular_price() ) : '',
						'data_type'         => 'price',
						'description'       => __( 'The price of one block (one hour, one night…). Shown as the product price.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_beacon_bookings_base_cost',
						'label'       => __( 'Base cost', 'beacon-bookings' ) . ' (' . get_woocommerce_currency_symbol() . ')',
						'value'       => wc_format_localized_price( $get( '_beacon_bookings_base_cost' ) ),
						'data_type'   => 'price',
						'description' => __( 'Charged once per booking on top of the blocks. Optional.', 'beacon-bookings' ),
						'desc_tip'    => true,
					)
				);
				$tier_text = implode(
					"\n",
					array_map(
						static fn( $t ) => $t['blocks'] . ' = ' . wc_format_localized_price( (string) $t['cost'] ),
						Pricing::tiers( $get( '_beacon_bookings_duration_prices' ) )
					)
				);
				woocommerce_wp_textarea_input(
					array(
						'id'            => '_beacon_bookings_duration_prices_text',
						'label'         => __( 'Duration prices', 'beacon-bookings' ),
						'value'         => $tier_text,
						'placeholder'   => "4 hours = 80\n1 day = 120",
						'rows'          => 3,
						'description'   => __( 'Optional. One per line: a length and its price, e.g. "4 hours = 80" or "1 week = 300" (a plain number counts blocks). Customers always get the cheapest mix, so a longer price also caps shorter bookings.', 'beacon-bookings' ),
						'wrapper_class' => 'bkb-if-customer',
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'            => '_beacon_bookings_set_lengths_only',
						'label'         => __( 'Only offer these lengths', 'beacon-bookings' ),
						'value'         => $get( '_beacon_bookings_set_lengths_only' ),
						'description'   => __( 'Customers choose one of the lengths above (a 2, 4 or 8 hour charter; a 3, 5 or 7 day dumpster) at exactly that price.', 'beacon-bookings' ),
						'wrapper_class' => 'bkb-if-customer',
					)
				);
				?>
			</div>
			<div class="options_group">
				<?php
				woocommerce_wp_select(
					array(
						'id'      => '_beacon_bookings_unit',
						'label'   => __( 'Block unit', 'beacon-bookings' ),
						'value'   => $unit,
						'options' => array(
							'minute' => __( 'Minutes', 'beacon-bookings' ),
							'hour'   => __( 'Hours', 'beacon-bookings' ),
							'day'    => __( 'Days', 'beacon-bookings' ),
							'night'  => __( 'Nights (check-in / check-out)', 'beacon-bookings' ),
						),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_duration',
						'label'             => __( 'Block length', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_duration' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
						'description'       => __( 'How many units make one block: 30 minutes, 1 hour, 1 night.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_beacon_bookings_duration_type',
						'label'   => __( 'Duration', 'beacon-bookings' ),
						'value'   => $get( '_beacon_bookings_duration_type' ),
						'options' => array(
							'fixed'    => __( 'Fixed: one block per booking', 'beacon-bookings' ),
							'customer' => __( 'Customer chooses how many blocks', 'beacon-bookings' ),
						),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_min_blocks',
						'label'             => __( 'Minimum blocks', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_min_blocks' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
						'wrapper_class'     => 'bkb-if-customer',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_max_blocks',
						'label'             => __( 'Maximum blocks', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_max_blocks' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
						'wrapper_class'     => 'bkb-if-customer',
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'            => '_beacon_bookings_overnight',
						'label'         => __( 'Overnight rentals', 'beacon-bookings' ),
						'value'         => $get( '_beacon_bookings_overnight' ),
						'description'   => __( 'Rentals can run past closing or for several days. Customers pick up and return during your hours; closed days in between don\'t matter. For up to a week, set Maximum blocks to 168 hours.', 'beacon-bookings' ),
						'wrapper_class' => 'bkb-if-overnight',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_interval',
						'label'             => __( 'Start times every (minutes)', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_interval' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 5 ),
						'description'       => __( 'Leave 0 to offer a start every block. Time units only.', 'beacon-bookings' ),
						'desc_tip'          => true,
						'wrapper_class'     => 'bkb-if-time',
					)
				);
				?>
			</div>
			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_capacity',
						'label'             => __( 'Capacity per slot', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_capacity' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
						'description'       => __( 'How many bookings (or people, see the People tab) can share the same time. Ignored when resources are used.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_buffer_before',
						'label'             => __( 'Buffer before', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_buffer_before' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'description'       => __( 'Minutes (time units) or days (day units) kept free before every booking.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_buffer_after',
						'label'             => __( 'Buffer after', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_buffer_after' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'description'       => __( 'Minutes (time units) or days (day units) kept free after every booking, e.g. cleaning.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_min_notice_hours',
						'label'             => __( 'Minimum notice (hours)', 'beacon-bookings' ),
						'value'             => round( (int) $get( '_beacon_bookings_min_notice' ) / 60, 2 ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 0.5 ),
						'description'       => __( 'How far ahead a booking must start. 24 = one day.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_max_advance_days',
						'label'             => __( 'Maximum advance (days)', 'beacon-bookings' ),
						'value'             => (int) round( (int) $get( '_beacon_bookings_max_advance' ) / 1440 ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'description'       => __( 'How far ahead customers may book. 0 = no limit.', 'beacon-bookings' ),
						'desc_tip'          => true,
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => '_beacon_bookings_requires_confirmation',
						'label'       => __( 'Requires confirmation', 'beacon-bookings' ),
						'value'       => $get( '_beacon_bookings_requires_confirmation' ),
						'description' => __( 'Paid bookings wait for you to confirm them (Bookings → All Bookings).', 'beacon-bookings' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_beacon_bookings_customer_cancel',
						'label'   => __( 'Customer cancellation', 'beacon-bookings' ),
						'value'   => $get( '_beacon_bookings_customer_cancel' ),
						'options' => array(
							'inherit' => __( 'Use the global setting', 'beacon-bookings' ),
							'yes'     => __( 'Allowed (within the global notice)', 'beacon-bookings' ),
							'no'      => __( 'Not allowed', 'beacon-bookings' ),
						),
					)
				);
				?>
			</div>
		</div>

		<div id="beacon_bookings_availability_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group bkb-rules-wrap">
				<?php Rules_Ui::render( 'beacon_bookings_rules', $rules ); ?>
			</div>
		</div>

		<div id="beacon_bookings_persons_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="description bkb-panel-intro"><?php esc_html_e( 'Let customers say how many people are coming. Set the maximum to 0 to switch this off.', 'beacon-bookings' ); ?></p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_persons_min',
						'label'             => __( 'Minimum people', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_persons_min' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => '_beacon_bookings_persons_max',
						'label'             => __( 'Maximum people', 'beacon-bookings' ),
						'value'             => $get( '_beacon_bookings_persons_max' ),
						'type'              => 'number',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_beacon_bookings_count_label',
						'label'       => __( 'Label shown to customers', 'beacon-bookings' ),
						'value'       => $get( '_beacon_bookings_count_label' ),
						'placeholder' => __( 'People', 'beacon-bookings' ),
						'desc_tip'    => true,
						'description' => __( 'Rename the count for rentals, like Canoes, Kayaks or Bikes. It shows on the booking form, the cart and emails.', 'beacon-bookings' ),
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => '_beacon_bookings_persons_as_qty',
						'label'       => __( 'People use capacity', 'beacon-bookings' ),
						'value'       => $get( '_beacon_bookings_persons_as_qty' ),
						'description' => __( 'Each person takes one unit of capacity (a class with 10 seats). Off: one booking takes one unit regardless of group size (a table for four).', 'beacon-bookings' ),
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => '_beacon_bookings_persons_multiply',
						'label'       => __( 'Multiply cost by people', 'beacon-bookings' ),
						'value'       => $get( '_beacon_bookings_persons_multiply' ),
						'description' => __( 'The cost is per person.', 'beacon-bookings' ),
					)
				);
				?>
			</div>
		</div>

		<div id="beacon_bookings_resources_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="description bkb-panel-intro"><?php esc_html_e( 'Resources are the things a booking occupies: rooms, staff, boats, bikes. Each has its own capacity and can have its own hours. A resource shared by several products is never double-booked across them.', 'beacon-bookings' ); ?></p>
				<?php
				woocommerce_wp_select(
					array(
						'id'      => '_beacon_bookings_assignment',
						'label'   => __( 'Resources', 'beacon-bookings' ),
						'value'   => $get( '_beacon_bookings_assignment' ),
						'options' => array(
							'none'     => __( 'Not used', 'beacon-bookings' ),
							'customer' => __( 'Customer chooses one', 'beacon-bookings' ),
							'auto'     => __( 'Assigned automatically', 'beacon-bookings' ),
						),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_beacon_bookings_resource_label',
						'label'       => __( 'Label shown to customers', 'beacon-bookings' ),
						'value'       => $get( '_beacon_bookings_resource_label' ),
						'placeholder' => __( 'Room', 'beacon-bookings' ),
					)
				);
				$all      = Resources::all();
				$selected = array_map( 'intval', (array) $get( '_beacon_bookings_resource_ids' ) );
				?>
				<p class="form-field">
					<label for="_beacon_bookings_resource_ids"><?php esc_html_e( 'Available resources', 'beacon-bookings' ); ?></label>
					<select id="_beacon_bookings_resource_ids" name="_beacon_bookings_resource_ids[]" class="wc-enhanced-select" multiple="multiple" style="width:50%" data-placeholder="<?php esc_attr_e( 'Choose resources…', 'beacon-bookings' ); ?>">
						<?php foreach ( $all as $id => $title ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( in_array( (int) $id, $selected, true ) ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Resources::CPT ) ); ?>"><?php esc_html_e( 'Manage resources', 'beacon-bookings' ); ?></a></span>
				</p>
			</div>
		</div>
		<?php
		/**
		 * Fires after the bookable product panels so add-ons can print their own.
		 *
		 * @param \WC_Product|null $product Product being edited (null on a new product).
		 */
		do_action( 'beacon_bookings_product_panels', $product );
	}

	public static function save( \WC_Product $product ): void {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		$type = isset( $_POST['product-type'] ) ? sanitize_key( wp_unslash( $_POST['product-type'] ) ) : '';
		if ( Product::TYPE !== $type ) {
			if ( 'yes' === $product->get_meta( '_beacon_bookings_is_bookable', true ) ) {
				$product->delete_meta_data( '_beacon_bookings_is_bookable' );
			}
			return;
		}
		$post = beacon_bookings_clean_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deep-sanitised here, cast per field below.
		$int  = static fn( $k, $min = 0 ) => max( $min, (int) ( $post[ $k ] ?? 0 ) );
		$yes  = static fn( $k ) => isset( $post[ $k ] ) ? 'yes' : 'no';

		$unit = sanitize_key( $post['_beacon_bookings_unit'] ?? 'hour' );
		$product->update_meta_data( '_beacon_bookings_is_bookable', 'yes' );
		$product->update_meta_data( '_beacon_bookings_unit', in_array( $unit, array( 'minute', 'hour', 'day', 'night' ), true ) ? $unit : 'hour' );
		$product->update_meta_data( '_beacon_bookings_duration', $int( '_beacon_bookings_duration', 1 ) );
		$dtype = sanitize_key( $post['_beacon_bookings_duration_type'] ?? 'fixed' );
		$product->update_meta_data( '_beacon_bookings_duration_type', 'customer' === $dtype ? 'customer' : 'fixed' );
		$min = $int( '_beacon_bookings_min_blocks', 1 );
		$max = max( $min, $int( '_beacon_bookings_max_blocks', 1 ) );
		// Duration prices typed as "4 = 80" (blocks = price, the price in the store's own decimal format), one per line.
		// Read with sanitize_textarea_field: the generic cleaner above flattens line breaks. Pairs are matched anywhere in
		// the text, so "4 = 80, 8 = 120" on one line works too. The nonce was verified at the top of this method.
		$tier_text = isset( $_POST['_beacon_bookings_duration_prices_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_beacon_bookings_duration_prices_text'] ) ) : '';
		$tiers     = array();
		if ( preg_match_all( '/(\d+)\s*([a-z]*)[a-z ]*?[=:]\s*[^\d\s]{0,3}\s*(\d[\d.,]*)/i', $tier_text, $tm, PREG_SET_ORDER ) ) {
			foreach ( $tm as $one ) {
				$tiers[] = array(
					'blocks' => self::tier_blocks( (int) $one[1], strtolower( $one[2] ), $unit, $int( '_beacon_bookings_duration', 1 ) ),
					'cost'   => (float) wc_format_decimal( rtrim( $one[3], '.,' ) ),
				);
			}
		}
		$tiers    = Pricing::tiers( $tiers );
		$set_only = 'customer' === $dtype && $tiers && isset( $post['_beacon_bookings_set_lengths_only'] );
		if ( $set_only ) {
			// "Only these lengths": the shortest and longest sold lengths are the bounds.
			$min = $tiers[0]['blocks'];
			$max = $tiers[ count( $tiers ) - 1 ]['blocks'];
		}
		$product->update_meta_data( '_beacon_bookings_duration_prices', $tiers );
		$product->update_meta_data( '_beacon_bookings_set_lengths_only', $set_only ? 'yes' : 'no' );
		$product->update_meta_data( '_beacon_bookings_min_blocks', $min );
		$product->update_meta_data( '_beacon_bookings_max_blocks', $max );
		$product->update_meta_data( '_beacon_bookings_overnight', in_array( $unit, array( 'minute', 'hour' ), true ) ? $yes( '_beacon_bookings_overnight' ) : 'no' );
		$product->update_meta_data( '_beacon_bookings_interval', $int( '_beacon_bookings_interval' ) );
		$product->update_meta_data( '_beacon_bookings_capacity', $int( '_beacon_bookings_capacity', 1 ) );
		$product->update_meta_data( '_beacon_bookings_buffer_before', $int( '_beacon_bookings_buffer_before' ) );
		$product->update_meta_data( '_beacon_bookings_buffer_after', $int( '_beacon_bookings_buffer_after' ) );
		$product->update_meta_data( '_beacon_bookings_min_notice', (int) round( max( 0, (float) ( $post['_beacon_bookings_min_notice_hours'] ?? 0 ) ) * 60 ) );
		$product->update_meta_data( '_beacon_bookings_max_advance', $int( '_beacon_bookings_max_advance_days' ) * 1440 );
		$product->update_meta_data( '_beacon_bookings_requires_confirmation', $yes( '_beacon_bookings_requires_confirmation' ) );
		$cancel = sanitize_key( $post['_beacon_bookings_customer_cancel'] ?? 'inherit' );
		$product->update_meta_data( '_beacon_bookings_customer_cancel', in_array( $cancel, array( 'inherit', 'yes', 'no' ), true ) ? $cancel : 'inherit' );

		$product->update_meta_data( '_beacon_bookings_rules', Rules_Ui::sanitize( $post['beacon_bookings_rules'] ?? array() ) );

		$pmin = $int( '_beacon_bookings_persons_min' );
		$pmax = $int( '_beacon_bookings_persons_max' );
		$product->update_meta_data( '_beacon_bookings_persons_min', $pmax > 0 ? min( $pmin, $pmax ) : 0 );
		$product->update_meta_data( '_beacon_bookings_persons_max', $pmax );
		$product->update_meta_data( '_beacon_bookings_persons_as_qty', $yes( '_beacon_bookings_persons_as_qty' ) );
		$product->update_meta_data( '_beacon_bookings_persons_multiply', $yes( '_beacon_bookings_persons_multiply' ) );
		$product->update_meta_data( '_beacon_bookings_count_label', mb_substr( trim( sanitize_text_field( (string) ( $post['_beacon_bookings_count_label'] ?? '' ) ) ), 0, 30 ) );

		$assign = sanitize_key( $post['_beacon_bookings_assignment'] ?? 'none' );
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $post['_beacon_bookings_resource_ids'] ?? array() ) ) ) );
		$product->update_meta_data( '_beacon_bookings_assignment', ( in_array( $assign, array( 'customer', 'auto' ), true ) && $ids ) ? $assign : 'none' );
		$product->update_meta_data( '_beacon_bookings_resource_ids', $ids );
		$product->update_meta_data( '_beacon_bookings_resource_label', sanitize_text_field( (string) ( $post['_beacon_bookings_resource_label'] ?? '' ) ) );

		$block = wc_format_decimal( sanitize_text_field( (string) ( $post['_beacon_bookings_block_cost'] ?? '' ) ) );
		$base  = wc_format_decimal( sanitize_text_field( (string) ( $post['_beacon_bookings_base_cost'] ?? '' ) ) );
		$product->update_meta_data( '_beacon_bookings_base_cost', '' === $base ? '' : $base );
		$product->set_regular_price( '' === $block ? '0' : $block );
		$product->set_sale_price( '' );
		$product->set_price( '' === $block ? '0' : $block );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		/**
		 * Fires while a bookable product is being saved, after the core fields. $post is the unslashed, deep-sanitised request.
		 *
		 * @param \WC_Product $product Product object (not yet saved).
		 * @param array       $post    Unslashed, sanitised POST data (strings through sanitize_text_field()).
		 */
		do_action( 'beacon_bookings_product_save', $product, $post );
		Bookings::forget( $product->get_id() );
	}

	/**
	 * A typed duration in blocks: "4" is 4 blocks; "4 hours", "2 days", "1 week" are converted into the product's own
	 * blocks (so "1 day" on an hourly product is 24 blocks, not 1). 0 when it doesn't fit (the tier is then dropped).
	 */
	private static function tier_blocks( int $n, string $word, string $unit, int $duration ): int {
		$duration = max( 1, $duration );
		$per      = array( 'min' => 1, 'mins' => 1, 'minute' => 1, 'minutes' => 1, 'h' => 60, 'hr' => 60, 'hrs' => 60, 'hour' => 60, 'hours' => 60, 'd' => 1440, 'day' => 1440, 'days' => 1440, 'night' => 1440, 'nights' => 1440, 'w' => 10080, 'wk' => 10080, 'week' => 10080, 'weeks' => 10080 );
		if ( '' === $word || ! isset( $per[ $word ] ) ) {
			return $n;
		}
		$minutes = $n * $per[ $word ];
		$block   = in_array( $unit, array( 'day', 'night' ), true ) ? $duration * 1440 : ( 'hour' === $unit ? $duration * 60 : $duration );
		return 0 === $minutes % $block ? intdiv( $minutes, $block ) : 0;
	}
}
