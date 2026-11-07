<?php
/**
 * Shortcode handlers and front-end asset enqueuing for the Bob Go tracking widget.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shortcode
 *
 * @package BobGo_Shipping\app
 */
class Shortcode {

	const HANDLE        = 'bobgo-shipping-shortcode';
	const HANDLE_SUBURB = 'bobgo-shipping-suburb-shortcode';

	const HANDLE_SHIPPING_DESCRIPTION = 'bobgo-shipping-shipping-description-shortcode';

	/**
	 * Register the style and scripts of the shortcode.
	 * Assets are conditionally loaded to improve performance:
	 * - Tracking scripts: Only on the tracking page
	 * - Suburb script: Only on classic checkout
	 * - Shipping description: Only on checkout (blocks)
	 */
	public static function styles_scripts(): void {
		// Tracking assets enqueue from render() so the [bobgo] shortcode works
		// on any page, not just the configured tracking page. The page-id
		// check is kept as a fast path so the assets are queued in <head>
		// when the tracking page is the current page.
		if ( self::is_tracking_page() ) {
			self::enqueue_tracking_assets();
		}

		// Conditionally enqueue the suburb script for the CLASSIC checkout page.
		// is_checkout is only supported for CLASSIC checkout pages, not checkout blocks.
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() ) {
			wp_enqueue_script(
				self::HANDLE_SUBURB,
				BOBGO_SHIPPING_URL . 'assets/build/checkout-suburb.js',
				[],
				BOBGO_SHIPPING_VERSION,
				true
			);
		}

		// Custom shipping descriptions for checkout blocks - only on checkout.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			self::enqueue_shipping_description_assets();
		}

		// Rate description styles need to load anywhere shipping rates are
		// displayed — cart page (shipping calculator), classic checkout, and
		// blocks checkout. The hook fires on all three.
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' )
			&& ( is_cart() || is_checkout() ) ) {
			self::enqueue_checkout_styles();
		}
	}

	/**
	 * Enqueue the rate-description stylesheet on cart and checkout pages.
	 *
	 * Classic checkout and the cart shipping calculator have no dedicated
	 * JS payload, so the styles need to load wherever the description hook
	 * runs for the .bobgo-rate-meta block to render correctly.
	 */
	private static function enqueue_checkout_styles(): void {
		wp_enqueue_style(
			self::HANDLE_SHIPPING_DESCRIPTION,
			BOBGO_SHIPPING_URL . 'assets/build/bobgo-shipping.css',
			[],
			BOBGO_SHIPPING_VERSION
		);
	}

	/**
	 * Check if the current page is the tracking page.
	 *
	 * @return bool
	 */
	private static function is_tracking_page(): bool {
		$options = WooCommerce::get_options();
		if ( empty( $options['shipping_page'] ) ) {
			return false;
		}

		return is_page( (int) $options['shipping_page'] );
	}

	/**
	 * Enqueue tracking page assets (Mustache, main script, styles).
	 */
	private static function enqueue_tracking_assets(): void {
		wp_enqueue_script(
			'bobgo-mustache',
			BOBGO_SHIPPING_URL . 'assets/js/vendor/mustache.min.js',
			[],
			'4.2.0',
			true
		);

		// Main tracking script.
		wp_enqueue_script(
			self::HANDLE,
			BOBGO_SHIPPING_URL . 'assets/build/bobgo-shipping.js',
			[ 'jquery', 'bobgo-mustache' ],
			BOBGO_SHIPPING_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'bobgo_shipping_l10n',
			[
				'v3_api_url' => SettingsPage::get_base_url() . '/v2/tracking?tracking_reference=NUMBER&channel=DOMAIN',
				'domain'     => Admin::get_api_domain(),
				// translators: %s the order number.
				'not_found'  => __( "We were unable to retrieve tracking information for '%s'. Please try again later.", 'bobgo-shipping' ),
			]
		);

		// Theme settings for tracking page styling.
		wp_localize_script(
			self::HANDLE,
			'bobgo_theme_settings',
			[
				'contentBackgroundColor' => get_theme_mod( 'content_background', 'rgba(0, 0, 0, 0)' ),
			]
		);

		wp_enqueue_style(
			self::HANDLE,
			BOBGO_SHIPPING_URL . 'assets/build/bobgo-shipping.css',
			[],
			BOBGO_SHIPPING_VERSION
		);
	}

	/**
	 * Enqueue shipping description assets for checkout blocks.
	 */
	private static function enqueue_shipping_description_assets(): void {
		wp_enqueue_script(
			self::HANDLE_SHIPPING_DESCRIPTION,
			BOBGO_SHIPPING_URL . 'assets/build/custom-shipping-description.js',
			[ 'wc-blocks-checkout' ],
			BOBGO_SHIPPING_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE_SHIPPING_DESCRIPTION,
			'bobgo_shipping_description_data',
			[
				'shippingDescriptions' => WooCommerce::get_shipping_descriptions(),
			]
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param string|array<string, string> $atts Attributes passed to the shortcode.
	 * @return string|false The rendered shortcode content or false on failure.
	 */
	public static function render( string|array $atts = [] ): string|false {
		$defaults_attr = [
			'bg_color'   => '#000000',
			'text_color' => '#ffffff',
			'className'  => 'wp-block-bobgo-shipping',
		];
		$atts          = wp_parse_args( $atts, $defaults_attr );

		// Ensure tracking assets are enqueued whenever the widget renders —
		// covers shortcodes/blocks placed on pages other than the configured
		// tracking page. Late-enqueues print in the footer.
		self::enqueue_tracking_assets();

		ob_start();
		require BOBGO_SHIPPING_TEMPLATES_DIR . 'shipping-template.php';

		return ob_get_clean();
	}
}
