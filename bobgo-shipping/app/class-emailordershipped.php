<?php
/**
 * Customer "order shipped" notification email.
 *
 * A single WooCommerce email that covers both the "Shipped" and
 * "Partially Shipped" custom order statuses. It fires when Bob Go reports a
 * fulfillment and the order advances along the fulfillment ladder, and it
 * carries the courier, tracking link and the specific items that shipped.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailOrderShipped
 *
 * Extends WC_Email so the notification appears under
 * WooCommerce → Settings → Emails with its own enable toggle, subject and
 * heading. Registered only when the custom statuses are enabled (see
 * {@see register_email_class()}).
 */
class EmailOrderShipped extends \WC_Email {

	/**
	 * Fulfillment-ladder rank, mirroring Webhook::can_advance_to_status(). Used to
	 * gate the email to forward moves only, so a shipment cancelled on Bob Go
	 * (which can regress "shipped" back to "partially-shipped") never emails the
	 * customer that their order un-shipped.
	 *
	 * @var array<string, int>
	 */
	private const LADDER_RANK = [
		'pending'           => 0,
		'on-hold'           => 0,
		'processing'        => 0,
		'partially-shipped' => 1,
		'shipped'           => 2,
		'completed'         => 3,
	];

	/**
	 * Shipment statuses that no longer contribute to fulfillment and must not be
	 * shown to the customer. Kept in sync with
	 * Webhook::INACTIVE_SHIPMENT_STATUSES. Compared lower-cased.
	 *
	 * @var string[]
	 */
	private const INACTIVE_SHIPMENT_STATUSES = [ 'cancelled', 'canceled', 'failed' ];

	/**
	 * Constructor — wires up the WC_Email identity, templates and trigger.
	 */
	public function __construct() {
		$this->id             = 'bobgo_order_shipped';
		$this->customer_email = true;
		$this->title          = __( 'Order shipped (Bob Go)', 'bobgo-shipping' );
		$this->description    = __( 'Sent to the customer when Bob Go ships all or part of their order. Includes the courier, tracking link and the items that shipped. If you have chosen "Let Bob Go handle notifications", disable this to avoid duplicate emails.', 'bobgo-shipping' );

		$this->template_html  = 'emails/bobgo-order-shipped.php';
		$this->template_plain = 'emails/plain/bobgo-order-shipped.php';
		$this->template_base  = BOBGO_SHIPPING_APP_DIR . 'templates/';

		$this->placeholders = [
			'{order_date}'   => '',
			'{order_number}' => '',
		];

		// Fire off a single status-change hook so we have both the previous and new
		// status and can restrict sending to forward moves.
		add_action( 'woocommerce_order_status_changed', [ $this, 'maybe_trigger' ], 10, 4 );

		parent::__construct();
	}

	/**
	 * Whether the shipped-order email feature is enabled in the plugin settings.
	 *
	 * Default OFF — the email must be explicitly opted into on the Bob Go
	 * Notifications tab before it is registered with WooCommerce at all. This
	 * is deliberately stricter than the WC_Email enable toggle: while off, the
	 * email does not appear under WooCommerce → Settings → Emails and its
	 * status-change trigger is never hooked.
	 *
	 * @return bool
	 */
	public static function is_email_enabled(): bool {
		$options = get_option( 'bobgo', [] );

		return ! empty( $options['shipped_email_enabled'] );
	}

	/**
	 * Register this email with WooCommerce, but only when the merchant has
	 * opted in on the Notifications tab (default off) AND the custom statuses
	 * are enabled — otherwise orders never reach shipped/partially-shipped and
	 * the email would be dead weight in the settings list.
	 *
	 * @param array<string, \WC_Email> $emails Registered email instances.
	 * @return array<string, \WC_Email>
	 */
	public static function register_email_class( array $emails ): array {
		if ( ! self::is_email_enabled() ) {
			return $emails;
		}

		if ( ! OrderStatus::is_enabled() ) {
			return $emails;
		}

		$emails['BobGo_Order_Shipped'] = new self();

		return $emails;
	}

	/**
	 * Whether a status transition should send this notification.
	 *
	 * Sends only when the order moves TO one of our two fulfillment statuses AND
	 * the move is forward on the ladder. This suppresses regressions (e.g. a
	 * cancelled shipment moving "shipped" back to "partially-shipped") and any
	 * transition to a non-fulfillment status.
	 *
	 * Pure and side-effect-free so it can be unit-tested directly.
	 *
	 * @param string $from Previous order status (no wc- prefix).
	 * @param string $to   New order status (no wc- prefix).
	 * @return bool
	 */
	public static function should_notify( string $from, string $to ): bool {
		if ( ! in_array( $to, [ 'shipped', 'partially-shipped' ], true ) ) {
			return false;
		}

		$from_rank = self::LADDER_RANK[ $from ] ?? 0;
		$to_rank   = self::LADDER_RANK[ $to ] ?? 0;

		return $to_rank > $from_rank;
	}

	/**
	 * Status-change hook callback. Delegates to trigger() for forward moves only.
	 *
	 * @param int       $order_id    Order ID.
	 * @param string    $status_from Previous status.
	 * @param string    $status_to   New status.
	 * @param \WC_Order $order       The order (passed by WooCommerce).
	 */
	public function maybe_trigger( $order_id, $status_from, $status_to, $order = null ): void {
		if ( ! self::should_notify( (string) $status_from, (string) $status_to ) ) {
			return;
		}

		$this->trigger( $order_id, $order );
	}

	/**
	 * Build and send the email for a given order.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    The order, if already loaded.
	 */
	public function trigger( $order_id, $order = null ): void {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Default subject line. Single subject that reads correctly for both a full
	 * and a partial shipment; the body spells out which one it is.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( 'Your {site_title} order #{order_number} has shipped', 'bobgo-shipping' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( 'Your order is on its way', 'bobgo-shipping' );
	}

	/**
	 * Render the HTML body.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			$this->get_template_args( false ),
			'',
			$this->template_base
		);
	}

	/**
	 * Render the plain-text body.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_template_args( true ),
			'',
			$this->template_base
		);
	}

	/**
	 * Shared template arguments for both HTML and plain renderers.
	 *
	 * @param bool $plain_text Whether this is the plain-text variant.
	 * @return array<string, mixed>
	 */
	private function get_template_args( bool $plain_text ): array {
		return [
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'shipments'     => $this->object instanceof \WC_Order ? $this->get_active_shipments( $this->object ) : [],
			'is_partial'    => $this->object instanceof \WC_Order ? $this->object->has_status( 'partially-shipped' ) : false,
			'sent_to_admin' => false,
			'plain_text'    => $plain_text,
			'email'         => $this,
		];
	}

	/**
	 * Active shipments for the order — cancelled/failed shipments are dropped so
	 * the customer is never shown a shipment that no longer contributes to
	 * fulfillment.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_active_shipments( \WC_Order $order ): array {
		$shipments = $order->get_meta( '_bobgo_shipments' );
		if ( ! is_array( $shipments ) ) {
			return [];
		}

		$active = [];
		foreach ( $shipments as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$status = strtolower( (string) ( $shipment['status'] ?? '' ) );
			if ( in_array( $status, self::INACTIVE_SHIPMENT_STATUSES, true ) ) {
				continue;
			}
			$active[] = $shipment;
		}

		return $active;
	}
}
