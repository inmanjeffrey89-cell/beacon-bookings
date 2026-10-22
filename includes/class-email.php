<?php
/**
 * Generic booking email (one instance per type).
 *
 * @package Beacon\Bookings
 */

namespace Beacon\Bookings;

defined( 'ABSPATH' ) || exit;

final class Email extends \WC_Email {

	/** @var array|null Current booking row. */
	public $booking = null;
	/** @var \WC_Product|null */
	public $product = null;
	/** @var array */
	private $defaults = array();

	public function __construct( string $type ) {
		$this->id             = $type;
		$this->template_base  = BEACON_BOOKINGS_DIR . 'templates/';
		$this->template_html  = 'emails/bkb-booking.php';
		$this->template_plain = 'emails/plain/bkb-booking.php';
		$this->placeholders   = array(
			'{booking_id}' => '',
			'{site_title}' => $this->get_blogname(),
		);
		$customer = true;
		switch ( $type ) {
			case 'beacon_bookings_confirmed':
				$this->title       = __( 'Booking confirmed', 'beacon-bookings' );
				$this->description = __( 'Sent to the customer when a booking is confirmed or paid. Includes a calendar (.ics) file.', 'beacon-bookings' );
				$this->defaults    = array( 'subject' => __( 'Your booking is confirmed — {site_title}', 'beacon-bookings' ), 'heading' => __( 'Your booking is confirmed', 'beacon-bookings' ) );
				break;
			case 'beacon_bookings_pending':
				$this->title       = __( 'Booking received', 'beacon-bookings' );
				$this->description = __( 'Sent to the customer when a booking waits for your confirmation.', 'beacon-bookings' );
				$this->defaults    = array( 'subject' => __( 'We received your booking — {site_title}', 'beacon-bookings' ), 'heading' => __( 'We received your booking', 'beacon-bookings' ) );
				break;
			case 'beacon_bookings_cancelled':
				$this->title       = __( 'Booking cancelled', 'beacon-bookings' );
				$this->description = __( 'Sent to the customer when a booking is cancelled.', 'beacon-bookings' );
				$this->defaults    = array( 'subject' => __( 'Your booking was cancelled — {site_title}', 'beacon-bookings' ), 'heading' => __( 'Your booking was cancelled', 'beacon-bookings' ) );
				break;
			case 'beacon_bookings_reminder':
				$this->title       = __( 'Booking reminder', 'beacon-bookings' );
				$this->description = __( 'Sent to the customer before the booking starts (Bookings → Settings).', 'beacon-bookings' );
				$this->defaults    = array( 'subject' => __( 'Reminder: your booking is coming up — {site_title}', 'beacon-bookings' ), 'heading' => __( 'Your booking is coming up', 'beacon-bookings' ) );
				break;
			default:
				$customer          = false;
				$this->title       = __( 'New booking (admin)', 'beacon-bookings' );
				$this->description = __( 'Sent to you when a customer books.', 'beacon-bookings' );
				$this->defaults    = array( 'subject' => __( '[{site_title}] New booking #{booking_id}', 'beacon-bookings' ), 'heading' => __( 'New booking', 'beacon-bookings' ) );
		}
		$this->customer_email = $customer;
		parent::__construct();
		if ( ! $customer ) {
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}
	}

	public function get_default_subject() {
		return $this->defaults['subject'];
	}

	public function get_default_heading() {
		return $this->defaults['heading'];
	}

	public function init_form_fields() {
		parent::init_form_fields();
		if ( ! $this->customer_email ) {
			$this->form_fields['recipient'] = array(
				'title'       => __( 'Recipient(s)', 'beacon-bookings' ),
				'type'        => 'text',
				/* translators: %s: admin email */
				'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'beacon-bookings' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			);
		}
	}

	public function trigger( array $row ): void {
		$this->setup_locale();
		$this->booking = $row;
		$this->product = wc_get_product( $row['product_id'] ) ?: null;
		$this->placeholders['{booking_id}'] = (string) $row['id'];
		if ( $this->customer_email ) {
			$this->recipient = (string) $row['customer_email'];
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$attachments = array();
			$tmp         = '';
			if ( in_array( $this->id, array( 'beacon_bookings_confirmed', 'beacon_bookings_reminder' ), true ) ) {
				$tmp = Ics::temp_file( $row );
				if ( $tmp ) {
					$attachments[] = $tmp;
				}
			}
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $attachments );
			if ( $tmp ) {
				wp_delete_file( $tmp );
			}
		}
		$this->restore_locale();
	}

	private function args( bool $plain ): array {
		$order = ! empty( $this->booking['order_id'] ) ? wc_get_order( $this->booking['order_id'] ) : null;
		return array(
			'booking'            => $this->booking,
			'product'            => $this->product,
			'order'              => $order ?: null,
			'type'               => $this->id,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->args( true ), '', $this->template_base );
	}
}
