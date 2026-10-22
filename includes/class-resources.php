<?php
/**
 * Resources: rooms, staff, equipment. A custom post type with capacity, an optional cost and optional own hours.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

use Beacon\Bookings\Engine\Rules;

defined( 'ABSPATH' ) || exit;

final class Resources {

	const CPT = 'beacon_bk_resource';

	/** @var array<int,array|null> */
	private static $cache = array();

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'cpt' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'               => __( 'Resources', 'beacon-bookings' ),
					'singular_name'      => __( 'Resource', 'beacon-bookings' ),
					'add_new'            => __( 'Add resource', 'beacon-bookings' ),
					'add_new_item'       => __( 'Add resource', 'beacon-bookings' ),
					'edit_item'          => __( 'Edit resource', 'beacon-bookings' ),
					'new_item'           => __( 'New resource', 'beacon-bookings' ),
					'all_items'          => __( 'Resources', 'beacon-bookings' ),
					'search_items'       => __( 'Search resources', 'beacon-bookings' ),
					'not_found'          => __( 'No resources yet. A resource is a room, a person, a boat — anything a booking occupies.', 'beacon-bookings' ),
					'not_found_in_trash' => __( 'No resources in the bin.', 'beacon-bookings' ),
					'menu_name'          => __( 'Resources', 'beacon-bookings' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'beacon-bookings',
				'supports'        => array( 'title' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/** id => title of published resources. */
	public static function all(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[ (int) $p->ID ] = $p->post_title;
		}
		return $out;
	}

	/** Engine config for a resource, or null when it does not exist / is not published. */
	public static function config( int $id ): ?array {
		if ( array_key_exists( $id, self::$cache ) ) {
			return self::$cache[ $id ];
		}
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			self::$cache[ $id ] = null;
			return null;
		}
		$rules = null;
		if ( 'yes' === get_post_meta( $id, '_beacon_bookings_custom_rules', true ) ) {
			$r = get_post_meta( $id, '_beacon_bookings_rules', true );
			if ( is_array( $r ) && ! empty( $r['weekly'] ) ) {
				$rules = $r;
			}
		}
		self::$cache[ $id ] = array(
			'capacity' => max( 1, (int) get_post_meta( $id, '_beacon_bookings_capacity', true ) ),
			'rules'    => $rules,
			'label'    => $post->post_title,
		);
		return self::$cache[ $id ];
	}

	public static function meta_boxes(): void {
		add_meta_box( 'beacon_bookings_resource_settings', __( 'Resource settings', 'beacon-bookings' ), array( __CLASS__, 'box' ), self::CPT, 'normal', 'high' );
	}

	public static function box( \WP_Post $post ): void {
		$cap    = max( 1, (int) get_post_meta( $post->ID, '_beacon_bookings_capacity', true ) );
		$cost   = get_post_meta( $post->ID, '_beacon_bookings_cost', true );
		$custom = 'yes' === get_post_meta( $post->ID, '_beacon_bookings_custom_rules', true );
		$rules  = get_post_meta( $post->ID, '_beacon_bookings_rules', true );
		if ( ! is_array( $rules ) || empty( $rules['weekly'] ) ) {
			$rules = Rules::defaults();
		}
		wp_nonce_field( 'beacon_bookings_resource_save', 'beacon_bookings_resource_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label for="beacon_bookings_capacity"><?php esc_html_e( 'Capacity', 'beacon-bookings' ); ?></label></th>
				<td><input type="number" min="1" step="1" id="beacon_bookings_capacity" name="beacon_bookings_capacity" value="<?php echo (int) $cap; ?>" class="small-text">
				<p class="description"><?php esc_html_e( 'How many bookings this resource can hold at the same time. A room: 1. A pool with 8 lanes: 8.', 'beacon-bookings' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="beacon_bookings_cost"><?php esc_html_e( 'Extra cost', 'beacon-bookings' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label></th>
				<td><input type="text" id="beacon_bookings_cost" name="beacon_bookings_cost" value="<?php echo esc_attr( wc_format_localized_price( $cost ) ); ?>" class="small-text wc_input_price">
				<p class="description"><?php esc_html_e( 'Added once per booking when this resource is used. Optional.', 'beacon-bookings' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Hours', 'beacon-bookings' ); ?></th>
				<td><label><input type="checkbox" name="beacon_bookings_custom_rules" value="yes" <?php checked( $custom ); ?> class="bkb-toggle-rules"> <?php esc_html_e( 'This resource has its own hours (otherwise the product hours apply)', 'beacon-bookings' ); ?></label></td>
			</tr>
		</table>
		<div class="bkb-resource-rules" <?php echo $custom ? '' : 'style="display:none"'; ?>>
			<?php Rules_Ui::render( 'beacon_bookings_rules', $rules ); ?>
		</div>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['beacon_bookings_resource_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beacon_bookings_resource_nonce'] ) ), 'beacon_bookings_resource_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$p = beacon_bookings_clean_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deep-sanitised here, cast per field below.
		update_post_meta( $post_id, '_beacon_bookings_capacity', max( 1, (int) ( $p['beacon_bookings_capacity'] ?? 1 ) ) );
		update_post_meta( $post_id, '_beacon_bookings_cost', wc_format_decimal( sanitize_text_field( (string) ( $p['beacon_bookings_cost'] ?? '' ) ) ) );
		update_post_meta( $post_id, '_beacon_bookings_custom_rules', isset( $p['beacon_bookings_custom_rules'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_beacon_bookings_rules', Rules_Ui::sanitize( $p['beacon_bookings_rules'] ?? array() ) );
		unset( self::$cache[ $post_id ] );
	}

	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['beacon_bookings_capacity'] = __( 'Capacity', 'beacon-bookings' );
				$out['beacon_bookings_hours']    = __( 'Hours', 'beacon-bookings' );
			}
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'beacon_bookings_capacity' === $col ) {
			echo (int) max( 1, (int) get_post_meta( $post_id, '_beacon_bookings_capacity', true ) );
		} elseif ( 'beacon_bookings_hours' === $col ) {
			echo 'yes' === get_post_meta( $post_id, '_beacon_bookings_custom_rules', true ) ? esc_html__( 'Own hours', 'beacon-bookings' ) : esc_html__( 'Product hours', 'beacon-bookings' );
		}
	}
}
