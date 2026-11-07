<?php
/**
 * Custom order statuses for Bob Go fulfillment flow.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderStatus
 *
 * Registers wc-shipped and wc-partially-shipped custom order statuses.
 */
class OrderStatus {

	/**
	 * Check if custom order statuses are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$options = get_option( 'bobgo', [] );
		return ! empty( $options['custom_order_statuses'] );
	}

	/**
	 * Register custom post statuses for orders.
	 */
	public static function register_statuses(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		register_post_status(
			'wc-shipped',
			[
				'label'                     => _x( 'Shipped', 'Order status', 'bobgo-shipping' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'bobgo-shipping' ),
			]
		);

		register_post_status(
			'wc-partially-shipped',
			[
				'label'                     => _x( 'Partially Shipped', 'Order status', 'bobgo-shipping' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>', 'bobgo-shipping' ),
			]
		);
	}

	/**
	 * Add custom statuses to the WooCommerce order statuses list.
	 *
	 * @param array<string, string> $statuses Existing statuses.
	 * @return array<string, string>
	 */
	public static function add_to_order_statuses( array $statuses ): array {
		if ( ! self::is_enabled() ) {
			return $statuses;
		}

		$new_statuses = [];

		// Insert after 'wc-processing' for a logical flow.
		foreach ( $statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$new_statuses['wc-partially-shipped'] = _x( 'Partially Shipped', 'Order status', 'bobgo-shipping' );
				$new_statuses['wc-shipped']           = _x( 'Shipped', 'Order status', 'bobgo-shipping' );
			}
		}

		return $new_statuses;
	}

	/**
	 * Enqueue admin CSS for custom status badges.
	 *
	 * Registers a src-less style handle and attaches the badge rules as inline
	 * CSS, so the styling is delivered through the WordPress enqueue system
	 * rather than a raw <style> block printed on admin_head.
	 */
	public static function enqueue_status_styles(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only load on order-related screens.
		$order_screens = [
			'edit-shop_order',
			'shop_order',
			'woocommerce_page_wc-orders',
		];

		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		/*
		 * Scope badge colours to the in-row status pill only. Bare .wc-shipped /
		 * .wc-partially-shipped selectors are intentionally omitted: the HPOS
		 * status-filter views carry those same classes and would otherwise be
		 * painted with the badge colour (see QA #6).
		 */
		$css = '.order-status.status-shipped,mark.order-status.status-shipped{background:#c8d7e1;color:#2e4453;}'
			. '.order-status.status-partially-shipped,mark.order-status.status-partially-shipped{background:#f8dda7;color:#94660c;}';

		wp_register_style( 'bobgo-order-status', false, [], BOBGO_SHIPPING_VERSION );
		wp_enqueue_style( 'bobgo-order-status' );
		wp_add_inline_style( 'bobgo-order-status', $css );
	}
}
