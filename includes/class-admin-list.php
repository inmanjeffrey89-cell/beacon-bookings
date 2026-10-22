<?php
/**
 * Bookings → All Bookings table.
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Admin_List extends \WP_List_Table {

	/** @var array */
	private $filters = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'booking',
				'plural'   => 'bookings',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'id'       => __( 'ID', 'beacon-bookings' ),
			'booking'  => __( 'Booking', 'beacon-bookings' ),
			'when'     => __( 'When', 'beacon-bookings' ),
			'customer' => __( 'Customer', 'beacon-bookings' ),
			'persons'  => __( 'People', 'beacon-bookings' ),
			'cost'     => __( 'Cost', 'beacon-bookings' ),
			'status'   => __( 'Status', 'beacon-bookings' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'id'     => array( 'id', false ),
			'when'   => array( 'start_utc', true ),
			'cost'   => array( 'cost', false ),
			'status' => array( 'status', false ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'confirm'  => __( 'Confirm', 'beacon-bookings' ),
			'paid'     => __( 'Mark paid', 'beacon-bookings' ),
			'complete' => __( 'Mark complete', 'beacon-bookings' ),
			'cancel'   => __( 'Cancel', 'beacon-bookings' ),
			'delete'   => __( 'Delete', 'beacon-bookings' ),
		);
	}

	/** Read-only filters from the URL (no state changes here, so no nonce). */
	private function filters(): array {
		if ( $this->filters ) {
			return $this->filters;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g             = static fn( $k ) => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		$this->filters = array(
			'status'     => in_array( $g( 'status' ), Repo::ALL, true ) ? $g( 'status' ) : '',
			'product_id' => absint( $g( 'product' ) ),
			'from'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g( 'from' ) ) ? $g( 'from' ) : '',
			'to'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g( 'to' ) ) ? $g( 'to' ) : '',
			'search'     => $g( 's' ),
			'orderby'    => $g( 'orderby' ),
			'order'      => $g( 'order' ),
			'paged'      => max( 1, absint( $g( 'paged' ) ) ),
		);
		// phpcs:enable
		return $this->filters;
	}

	public function prepare_items(): void {
		$f        = $this->filters();
		$per_page = 20;
		list( $rows, $total ) = Repo::query(
			array(
				'status'     => $f['status'],
				'product_id' => $f['product_id'],
				'from'       => $f['from'],
				'to'         => $f['to'],
				'search'     => $f['search'],
				'orderby'    => $f['orderby'] ?: 'start_utc',
				'order'      => $f['order'] ?: ( $f['orderby'] ? 'ASC' : 'DESC' ),
				'per_page'   => $per_page,
				'page'       => $f['paged'],
				'exclude_holds' => '' === $f['status'],
			)
		);
		$this->items = $rows;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'booking' );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$f        = $this->filters();
		$products = wc_get_products(
			array(
				'type'    => Product::TYPE,
				'limit'   => 200,
				'status'  => array( 'publish', 'private', 'draft' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		?>
		<div class="alignleft actions bkb-filters">
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'beacon-bookings' ); ?></option>
				<?php foreach ( Repo::labels() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="product">
				<option value=""><?php esc_html_e( 'All products', 'beacon-bookings' ); ?></option>
				<?php foreach ( $products as $p ) : ?>
					<option value="<?php echo (int) $p->get_id(); ?>" <?php selected( $f['product_id'], $p->get_id() ); ?>><?php echo esc_html( $p->get_name() ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'From date', 'beacon-bookings' ); ?>">
			<input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'To date', 'beacon-bookings' ); ?>">
			<?php submit_button( __( 'Filter', 'beacon-bookings' ), 'secondary', 'filter_action', false ); ?>
		</div>
		<?php
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	protected function column_id( $item ): string {
		return '<strong>#' . (int) $item['id'] . '</strong>';
	}

	protected function column_booking( $item ): string {
		$product = wc_get_product( $item['product_id'] );
		$name    = $product ? $product->get_name() : __( '(deleted product)', 'beacon-bookings' );
		$html    = $product ? '<a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		if ( (int) $item['resource_id'] > 0 ) {
			$html .= '<br><span class="description">' . esc_html( get_the_title( (int) $item['resource_id'] ) ) . '</span>';
		}
		$actions = array();
		$nonce   = wp_create_nonce( 'beacon_bookings_action_' . $item['id'] );
		$base    = admin_url( 'admin-post.php?action=beacon_bookings_booking_action&id=' . (int) $item['id'] . '&_wpnonce=' . $nonce );
		$s       = $item['status'];
		if ( in_array( $s, array( 'pending', 'unpaid' ), true ) ) {
			$actions['confirm'] = '<a href="' . esc_url( $base . '&do=confirm' ) . '">' . esc_html__( 'Confirm', 'beacon-bookings' ) . '</a>';
		}
		if ( in_array( $s, array( 'pending', 'unpaid', 'confirmed' ), true ) ) {
			$actions['paid'] = '<a href="' . esc_url( $base . '&do=paid' ) . '">' . esc_html__( 'Mark paid', 'beacon-bookings' ) . '</a>';
		}
		if ( in_array( $s, array( 'paid', 'confirmed' ), true ) ) {
			$actions['complete'] = '<a href="' . esc_url( $base . '&do=complete' ) . '">' . esc_html__( 'Complete', 'beacon-bookings' ) . '</a>';
		}
		if ( 'cancelled' !== $s ) {
			$actions['cancel'] = '<a href="' . esc_url( $base . '&do=cancel' ) . '" class="submitdelete">' . esc_html__( 'Cancel', 'beacon-bookings' ) . '</a>';
		} else {
			$actions['delete'] = '<a href="' . esc_url( $base . '&do=delete' ) . '" class="submitdelete">' . esc_html__( 'Delete', 'beacon-bookings' ) . '</a>';
		}
		/** Filter the row actions of a booking in Bookings → All Bookings. */
		$actions = apply_filters( 'beacon_bookings_row_actions', $actions, $item );
		return $html . $this->row_actions( $actions );
	}

	protected function column_when( $item ): string {
		$product = wc_get_product( $item['product_id'] );
		return esc_html( Bookings::when( $item, $product ?: null ) );
	}

	protected function column_customer( $item ): string {
		$html = esc_html( $item['customer_name'] ?: '—' );
		if ( $item['customer_email'] ) {
			$html .= '<br><a href="mailto:' . esc_attr( $item['customer_email'] ) . '">' . esc_html( $item['customer_email'] ) . '</a>';
		}
		if ( (int) $item['order_id'] > 0 ) {
			$order = wc_get_order( $item['order_id'] );
			if ( $order ) {
				/* translators: %s: order number */
				$html .= '<br><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%s', 'beacon-bookings' ), $order->get_order_number() ) ) . '</a> <span class="description">(' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ')</span>';
			}
		}
		return $html;
	}

	protected function column_persons( $item ): string {
		return (int) $item['persons'];
	}

	protected function column_cost( $item ): string {
		/** Filter the Cost cell (Pro appends deposit / balance). */
		return apply_filters( 'beacon_bookings_cost_cell', wp_kses_post( wc_price( (float) $item['cost'] ) ), $item );
	}

	protected function column_status( $item ): string {
		$labels = Repo::labels();
		return '<span class="bkb-status bkb-status-' . esc_attr( $item['status'] ) . '">' . esc_html( $labels[ $item['status'] ] ?? $item['status'] ) . '</span>';
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		esc_html_e( 'No bookings yet. Bookings appear here as soon as a customer checks out, or when you add one.', 'beacon-bookings' );
	}
}
