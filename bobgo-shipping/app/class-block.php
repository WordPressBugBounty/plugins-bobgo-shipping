<?php
/**
 * Gutenberg block and Elementor widget registration.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block Handles the Gutenberg and Elementor blocks/widgets.
 *
 * @package BobGo_Shipping\app
 */
class Block {

	const HANDLE = 'bobgo-shipping-block';

	/**
	 * Register all files for the Bob Go block.
	 */
	public static function register_block() {
		$script_asset_path = BOBGO_SHIPPING_BUILD_DIR . 'index.asset.php';
		// If the script asset file doesn't exist, gracefully exit and notify admin users.
		if ( ! file_exists( $script_asset_path ) ) {
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					function () {
						?>
					<div class="notice notice-error">
						<p><?php esc_html_e( '"bobgo/shipping" block not found. Please run `npm start` or `npm run build` to resolve the issue.', 'bobgo-shipping' ); ?></p>
					</div>
						<?php
					}
				);
			}

			return; // Exit early to prevent block registration.
		}

		$script_asset = require $script_asset_path;
		wp_register_script(
			self::HANDLE . '-editor',
			BOBGO_SHIPPING_BUILD_URL . 'index.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			false
		);

		// No path argument: translations are delivered by translate.wordpress.org
		// into WP_LANG_DIR/plugins/ — the plugin bundles none of its own.
		wp_set_script_translations( self::HANDLE . '-editor', 'bobgo-shipping' );

		$style_path = BOBGO_SHIPPING_BUILD_DIR . 'index.css';
		$style_ver  = file_exists( $style_path ) ? filemtime( $style_path ) : $script_asset['version'];
		wp_register_style(
			self::HANDLE . '-editor',
			BOBGO_SHIPPING_BUILD_URL . 'index.css',
			[],
			$style_ver
		);

		$block_args = [
			'editor_script'   => self::HANDLE . '-editor',
			'editor_style'    => self::HANDLE . '-editor',
			'style'           => self::HANDLE,
			'render_callback' => [ '\BobGo_Shipping\app\Shortcode', 'render' ],
		];

		// The legacy uafrica/shipping alias is not registered (WordPress.org requires
		// plugin-prefixed names); existing content saved as <!-- wp:uafrica/shipping /-->
		// is rewritten to bobgo/shipping by Activation::migrate_legacy_tracking_markup().
		register_block_type( 'bobgo/shipping', $block_args );
	}

	/**
	 * Register the Bob Go Elementor widget with the widgets manager.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager instance.
	 */
	public static function register_elementor_widget( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$bobgo_elementor_widget = new ElementorWidget();
		if ( method_exists( $widgets_manager, 'register' ) ) {
			// Elementor >= 3.5.0.
			$widgets_manager->register( $bobgo_elementor_widget );
		} else {
			// Elementor < 3.5.0.
			$widgets_manager->register_widget_type( $bobgo_elementor_widget );
		}
	}
}
