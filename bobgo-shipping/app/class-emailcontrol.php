<?php
/**
 * Email notification control — suppress WooCommerce emails when Bob Go handles notifications.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailControl
 *
 * Hooks into WooCommerce email system to optionally suppress fulfillment/shipping emails
 * when the merchant chooses to let Bob Go handle notifications.
 */
class EmailControl {

	/**
	 * Option value: let WooCommerce send emails (default).
	 */
	const MODE_WOOCOMMERCE = 'woocommerce';

	/**
	 * Option value: let Bob Go send emails.
	 */
	const MODE_BOBGO = 'bobgo';

	/**
	 * Email IDs that are suppressed when Bob Go handles notifications.
	 * customer_processing_order (purchase confirmation) is always allowed.
	 */
	const SUPPRESSED_EMAILS = [
		'customer_completed_order',
	];

	/**
	 * Get the current email notification mode.
	 *
	 * @return string 'woocommerce' or 'bobgo'.
	 */
	public static function get_mode(): string {
		$options = WooCommerce::get_options();
		$mode    = $options['email_notifications'] ?? self::MODE_WOOCOMMERCE;

		return in_array( $mode, [ self::MODE_WOOCOMMERCE, self::MODE_BOBGO ], true ) ? $mode : self::MODE_WOOCOMMERCE;
	}

	/**
	 * Check if Bob Go is handling notifications.
	 *
	 * @return bool
	 */
	public static function is_bobgo_mode(): bool {
		return self::MODE_BOBGO === self::get_mode();
	}

	/**
	 * Filter callback for woocommerce_email_enabled_{email_id}.
	 * Suppresses specific emails when Bob Go handles notifications.
	 *
	 * @param bool  $enabled     Whether the email is enabled.
	 * @param mixed $email_object The object (usually WC_Order) the email is for.
	 * @param mixed $email       The WC_Email instance (optional, WC 3.0+).
	 * @return bool
	 */
	public static function maybe_suppress_email( bool $enabled, $email_object = null, $email = null ): bool {
		if ( ! $enabled ) {
			return false;
		}

		if ( ! self::is_bobgo_mode() ) {
			return $enabled;
		}

		if ( ! $email || ! isset( $email->id ) || ! in_array( $email->id, self::SUPPRESSED_EMAILS, true ) ) {
			return $enabled;
		}

		// Only suppress when the email is for an order that was actually synced to Bob Go —
		// otherwise the merchant's non-Bob-Go orders would also lose their completion email.
		$order = $email_object instanceof \WC_Order ? $email_object : ( $email->object ?? null );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return $enabled;
		}
		if ( '' === (string) $order->get_meta( '_bobgo_order_id' ) ) {
			return $enabled;
		}

		return false;
	}
}
