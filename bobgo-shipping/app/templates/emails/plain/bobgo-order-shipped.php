<?php
/**
 * Customer "order shipped" / "partially shipped" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/bobgo-order-shipped.php
 *
 * @package BobGo_Shipping
 *
 * @var \WC_Order                        $order         The order.
 * @var string                          $email_heading Email heading.
 * @var array<int, array<string,mixed>> $shipments     Active Bob Go shipments.
 * @var bool                            $is_partial    Whether this is a partial shipment.
 * @var bool                            $sent_to_admin Whether the email is going to an admin.
 * @var bool                            $plain_text    Whether this is the plain-text variant.
 * @var \WC_Email                        $email         The email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template variables ($order, $shipments, …) are injected by wc_get_template(), and the do_action() calls invoke WooCommerce core email hooks; neither can carry the plugin prefix.

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

if ( $is_partial ) {
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s, part of your order is on its way. The remaining items will be shipped separately, and we\'ll email you again when they do.', 'bobgo-shipping' ),
		esc_html( $order->get_billing_first_name() )
	);
} else {
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s, good news — your order is on its way!', 'bobgo-shipping' ),
		esc_html( $order->get_billing_first_name() )
	);
}

echo "\n\n";

if ( ! empty( $shipments ) ) {
	foreach ( $shipments as $shipment ) {
		echo "----------------------------------------\n\n";

		if ( ! empty( $shipment['courier'] ) ) {
			echo esc_html( $shipment['courier'] ) . "\n";
		}

		if ( ! empty( $shipment['tracking_number'] ) ) {
			echo esc_html__( 'Tracking number:', 'bobgo-shipping' ) . ' ' . esc_html( $shipment['tracking_number'] ) . "\n";
		}

		if ( ! empty( $shipment['tracking_url'] ) ) {
			echo esc_html__( 'Track your shipment:', 'bobgo-shipping' ) . ' ' . esc_url( $shipment['tracking_url'] ) . "\n";
		}

		if ( ! empty( $shipment['items'] ) && is_array( $shipment['items'] ) ) {
			echo "\n" . esc_html__( 'Items in this shipment', 'bobgo-shipping' ) . ":\n";
			foreach ( $shipment['items'] as $item ) {
				if ( empty( $item['name'] ) ) {
					continue;
				}
				$line = '- ' . $item['name'];
				if ( ! empty( $item['quantity'] ) ) {
					$line .= ' x ' . $item['quantity'];
				}
				echo esc_html( $line ) . "\n";
			}
		}

		echo "\n";
	}
}

echo "----------------------------------------\n\n";

/*
 * Standard WooCommerce order summary, meta and customer details.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n" . esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
