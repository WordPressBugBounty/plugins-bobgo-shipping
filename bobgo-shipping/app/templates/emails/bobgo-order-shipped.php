<?php
/**
 * Customer "order shipped" / "partially shipped" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/bobgo-order-shipped.php
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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php if ( $is_partial ) : ?>
	<p>
	<?php
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s, part of your order is on its way. The remaining items will be shipped separately, and we\'ll email you again when they do.', 'bobgo-shipping' ),
		esc_html( $order->get_billing_first_name() )
	);
	?>
	</p>
<?php else : ?>
	<p>
	<?php
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s, good news — your order is on its way!', 'bobgo-shipping' ),
		esc_html( $order->get_billing_first_name() )
	);
	?>
	</p>
<?php endif; ?>

<?php if ( ! empty( $shipments ) ) : ?>
	<?php foreach ( $shipments as $shipment ) : ?>
		<div style="margin:0 0 24px; padding:16px; border:1px solid #e5e5e5; border-radius:4px;">
			<?php if ( ! empty( $shipment['courier'] ) ) : ?>
				<p style="margin:0 0 8px;"><strong><?php echo esc_html( $shipment['courier'] ); ?></strong></p>
			<?php endif; ?>

			<?php if ( ! empty( $shipment['tracking_number'] ) ) : ?>
				<p style="margin:0 0 12px;">
					<?php esc_html_e( 'Tracking number:', 'bobgo-shipping' ); ?>
					<strong><?php echo esc_html( $shipment['tracking_number'] ); ?></strong>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $shipment['tracking_url'] ) ) : ?>
				<p style="margin:0 0 16px;">
					<a href="<?php echo esc_url( $shipment['tracking_url'] ); ?>" style="display:inline-block; padding:10px 18px; background-color:#0a1f44; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:bold;">
						<?php esc_html_e( 'Track your shipment', 'bobgo-shipping' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $shipment['items'] ) && is_array( $shipment['items'] ) ) : ?>
				<p style="margin:0 0 4px;"><strong><?php esc_html_e( 'Items in this shipment', 'bobgo-shipping' ); ?></strong></p>
				<ul style="margin:0; padding-left:18px;">
					<?php foreach ( $shipment['items'] as $item ) : ?>
						<?php if ( ! empty( $item['name'] ) ) : ?>
							<li>
								<?php echo esc_html( $item['name'] ); ?>
								<?php if ( ! empty( $item['quantity'] ) ) : ?>
									&times; <?php echo esc_html( $item['quantity'] ); ?>
								<?php endif; ?>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
<?php endif; ?>

<?php
/*
 * Standard WooCommerce order summary, meta and customer details, so the email
 * inherits the store's email branding and gives the customer full context.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_footer', $email );
