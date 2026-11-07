<?php
/**
 * Plugin Name:       Bob Go Smart Shipping for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/bobgo-shipping/
 * Description:       Display Bob Go shipping rates at checkout and provide order tracking for your customers.
 * Author:            Bob Group
 * Author URI:        https://www.bob.co.za
 * Developer:         Bob Group
 * Developer URI:     https://www.bob.co.za
 * Text Domain:       bobgo-shipping
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Version:           4.0.58
 * WC requires at least: 9.0
 * WC tested up to:   11.1
 * License:           GPLv2 or later
 *
 * @package BobGo_Shipping
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// CRITICAL: Check for conflicting plugin BEFORE any constants or class loading.
// This must happen at the very top to prevent fatal "Cannot redeclare class" errors.
//
// WordPress loads plugins alphabetically, so bobgo-shipping (B) loads BEFORE uafrica-shipping (U).
// We can't rely on constants being defined - we must check the active_plugins option directly.

// Check if old uAfrica plugin is active (loads AFTER us alphabetically, would cause class conflict).
$bobgo_active_plugins      = (array) get_option( 'active_plugins', [] );
$bobgo_conflicting_plugins = [
	'uafrica-shipping/uafrica-shipping.php',
	'woocommerce-uafrica-shipping/uafrica-shipping.php',
];
foreach ( $bobgo_conflicting_plugins as $bobgo_conflict ) {
	if ( in_array( $bobgo_conflict, $bobgo_active_plugins, true ) ) {
		$GLOBALS['bobgo_shipping_conflict_plugin'] = $bobgo_conflict;
		add_action( 'admin_notices', 'bobgo_shipping_conflict_notice' );
		return;
	}
}
// Clean up temporary variables.
unset( $bobgo_active_plugins, $bobgo_conflicting_plugins, $bobgo_conflict );

// Also check if constants are defined (in case plugin loaded before us somehow).
if ( defined( 'BOBGO_SHIPPING_VERSION' ) || defined( 'UAFRICA_SHIPPING_VERSION' ) ) {
	add_action( 'admin_notices', 'bobgo_shipping_conflict_notice' );
	return;
}

// Check if the BobGo_Shipping namespace classes already exist.
if ( class_exists( 'BobGo_Shipping\app\Shipping', false ) ) {
	add_action( 'admin_notices', 'bobgo_shipping_conflict_notice' );
	return;
}

/**
 * Display admin notice when conflicting plugin is detected.
 */
function bobgo_shipping_conflict_notice() {
	$plugin_file    = isset( $GLOBALS['bobgo_shipping_conflict_plugin'] ) ? (string) $GLOBALS['bobgo_shipping_conflict_plugin'] : '';
	$plugin_name    = __( 'Bob Go smart shipping solution for WooCommerce', 'bobgo-shipping' );
	$deactivate_url = '';

	if ( '' !== $plugin_file ) {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( file_exists( $plugin_path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = get_plugin_data( $plugin_path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				$plugin_name = $data['Name'];
			}
		}
		$deactivate_url = wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $plugin_file ) ),
			'deactivate-plugin_' . $plugin_file
		);
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Bob Go Shipping Plugin Conflict Detected', 'bobgo-shipping' ); ?></strong>
		</p>
		<p>
			<?php
			printf(
				/* translators: 1: conflicting plugin display name, 2: plugin file path */
				esc_html__( 'The previously installed %1$s plugin (file: %2$s) is still active. Both plugins cannot run at the same time. Please deactivate it before using "Bob Go Smart Shipping for WooCommerce".', 'bobgo-shipping' ),
				'<strong>' . esc_html( $plugin_name ) . '</strong>',
				'<code>' . esc_html( $plugin_file ) . '</code>'
			);
			?>
		</p>
		<p>
			<?php if ( '' !== $deactivate_url ) : ?>
				<a href="<?php echo esc_url( $deactivate_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Deactivate the old plugin', 'bobgo-shipping' ); ?>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button">
				<?php esc_html_e( 'Go to Plugins', 'bobgo-shipping' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Constants.
 */
define( 'BOBGO_SHIPPING_VERSION', '4.0.58' );
// Internal Directories.
define( 'BOBGO_SHIPPING_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOBGO_SHIPPING_APP_DIR', BOBGO_SHIPPING_DIR . 'app' . DIRECTORY_SEPARATOR );
define( 'BOBGO_SHIPPING_TEMPLATES_DIR', BOBGO_SHIPPING_APP_DIR . 'templates' . DIRECTORY_SEPARATOR );
define( 'BOBGO_SHIPPING_BUILD_DIR', BOBGO_SHIPPING_DIR . 'block' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR );
define( 'BOBGO_SHIPPING_SLUG', plugin_basename( BOBGO_SHIPPING_DIR ) );
// Internal URL's.
define( 'BOBGO_SHIPPING_URL', plugin_dir_url( __FILE__ ) );
define( 'BOBGO_SHIPPING_BUILD_URL', BOBGO_SHIPPING_URL . 'block/build/' );

/**
 * Autoload classes.
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'BobGo_Shipping\app' ) !== 0 ) {
				return; // Not in the BobGo namespace, don't check.
		}
		$bare_class = str_replace( 'BobGo_Shipping\app\\', '', $class_name );
		$lower      = strtolower( $bare_class );

		// Try class file first, then trait.
		foreach ( [ 'class-', 'trait-' ] as $prefix ) {
			$file_path = BOBGO_SHIPPING_APP_DIR . $prefix . $lower . '.php';
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}
);

/**
 * Hook everything.
 */

// Plugin activation / deactivation.
register_activation_hook( __FILE__, [ 'BobGo_Shipping\app\Activation', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'BobGo_Shipping\app\Activation', 'deactivation' ] );
add_action( 'admin_notices', [ 'BobGo_Shipping\app\Activation', 'notice' ] );
add_action( 'admin_notices', [ 'BobGo_Shipping\app\Activation', 'migration_notice' ] );
add_action( 'wpmu_new_blog', [ 'BobGo_Shipping\app\Activation', 'create_new_blog' ] );

// Fallback full setup - runs on admin_init in case the activation hook was skipped
// (e.g., when conflict detection blocked the plugin while the old v3 plugin was still
// active, then the user deactivated it without re-activating v4). Idempotent; runs once.
add_action( 'admin_init', [ 'BobGo_Shipping\app\Activation', 'maybe_run_deferred_setup' ] );
add_action( 'admin_init', [ 'BobGo_Shipping\app\Activation', 'maybe_upgrade_db' ] );

// New consolidated settings page under WooCommerce.
add_action( 'admin_menu', [ 'BobGo_Shipping\app\SettingsPage', 'register_menu' ] );
add_action( 'init', [ 'BobGo_Shipping\app\SettingsPage', 'register_hooks' ] );

// Admin styles and scripts for settings page.
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		// Guard: only load on our settings page.
		if ( 'woocommerce_page_bobgo-settings' !== $hook ) {
			return;
		}
		// Extra safety: verify page parameter.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for asset loading.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'bobgo-settings' !== $page ) {
			return;
		}
		// Depends on core's button styles so it is always printed after them:
		// several rules here override core at equal specificity, which only wins
		// on source order.
		wp_enqueue_style(
			'bobgo-admin',
			BOBGO_SHIPPING_URL . 'assets/css/admin.css',
			[ 'buttons' ],
			BOBGO_SHIPPING_VERSION
		);
		wp_enqueue_script(
			'bobgo-admin',
			BOBGO_SHIPPING_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			BOBGO_SHIPPING_VERSION,
			true
		);
		wp_localize_script(
			'bobgo-admin',
			'bobgo_admin',
			[
				'copiedText'        => __( 'Copied!', 'bobgo-shipping' ),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'customFieldsNonce' => wp_create_nonce( 'bobgo_custom_fields' ),
				'maxCustomFields'   => 10,
				'dokanActive'       => function_exists( 'dokan_get_vendor_by_product' ),
				'i18n'              => [
					'selectMetaKey'  => __( '— Select meta key —', 'bobgo-shipping' ),
					'noSample'       => __( 'No sample available', 'bobgo-shipping' ),
					'refreshing'     => __( 'Refreshing…', 'bobgo-shipping' ),
					'loading'        => __( 'Loading…', 'bobgo-shipping' ),
					/* translators: 1: current field count 2: maximum allowed fields */
					'fieldsUsed'     => __( '%1$d of %2$d fields used', 'bobgo-shipping' ),
					'testing'        => __( 'Testing…', 'bobgo-shipping' ),
					'sessionExpired' => __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ),
					'failedToTest'   => __( 'Failed to test connection.', 'bobgo-shipping' ),
				],
			]
		);
	}
);

// Plugin action links (Settings link).
add_filter( 'plugin_action_links_' . BOBGO_SHIPPING_SLUG . '/bobgo-shipping.php', [ 'BobGo_Shipping\app\Admin', 'add_plugin_page_settings_link' ] );

// Add label to pages overview.
add_filter( 'display_post_states', [ 'BobGo_Shipping\app\Admin', 'label_page_as_shipping' ], 10, 2 );

// Tracking widget shortcode. The legacy [uafrica] shortcode is not registered
// (WordPress.org requires plugin-prefixed names); existing content is rewritten
// to [bobgo] by Activation::migrate_legacy_tracking_markup().
add_shortcode( 'bobgo', [ 'BobGo_Shipping\app\Shortcode', 'render' ] );
add_action( 'wp_enqueue_scripts', [ 'BobGo_Shipping\app\Shortcode', 'styles_scripts' ] );

// Everything needed to register the block.
add_action( 'init', [ 'BobGo_Shipping\app\Block', 'register_block' ] );
add_action(
	'elementor/elements/categories_registered',
	function ( $elements_manager ) {
		// Add "bobgo" Elementor widget category.
		$elements_manager->add_category(
			'bobgo',
			[
				'title' => 'Bob Go',
			]
		);
	},
	10,
	1
);
add_action( 'elementor/widgets/widgets_registered', [ 'BobGo_Shipping\app\Block', 'register_elementor_widget' ] );

// WooCommerce-specific configs, hooks and filter.
// Track order button - for legacy CPT-based orders.
add_filter( 'manage_edit-shop_order_columns', [ 'BobGo_Shipping\app\Admin', 'order_column_name' ] );
add_action( 'manage_shop_order_posts_custom_column', [ 'BobGo_Shipping\app\Admin', 'order_column_content' ], 10, 2 );
// Track order - for HPOS-based orders.
add_filter( 'manage_woocommerce_page_wc-orders_columns', [ 'BobGo_Shipping\app\Admin', 'order_column_name' ], 20 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ 'BobGo_Shipping\app\Admin', 'order_column_content' ], 20, 2 );
// Order screen: the rate detail stored on the Bob Go shipping line is rendered by
// WooCommerce under its raw meta key. Hide the machine service code and label the rest.
add_filter( 'woocommerce_hidden_order_itemmeta', [ 'BobGo_Shipping\app\Admin', 'hide_service_code_itemmeta' ] );
add_filter( 'woocommerce_order_item_display_meta_key', [ 'BobGo_Shipping\app\Admin', 'label_shipping_itemmeta' ], 10, 3 );
// Checkout.
add_filter( 'woocommerce_shipping_methods', [ 'BobGo_Shipping\app\Admin', 'register_shipping_method' ] );
add_filter( 'woocommerce_package_rates', [ 'BobGo_Shipping\app\Admin', 'filter_shipping_methods' ], 10 );
add_action( 'woocommerce_after_shipping_rate', [ 'BobGo_Shipping\app\WooCommerce', 'shipping_methods_description' ], 10, 1 );
add_filter( 'woocommerce_checkout_fields', [ 'BobGo_Shipping\app\WooCommerce', 'add_suburb_to_checkout_fields' ], 20, 1 );
add_action( 'woocommerce_checkout_update_order_review', [ 'BobGo_Shipping\app\WooCommerce', 'save_suburb_in_session_during_order_review' ], 10 );
add_action( 'woocommerce_checkout_update_order_meta', [ 'BobGo_Shipping\app\WooCommerce', 'save_order_meta_checkout' ], 20, 2 );
add_filter( 'woocommerce_cart_shipping_packages', [ 'BobGo_Shipping\app\WooCommerce', 'modify_shipping_packages' ], 30, 1 );
// Checkout blocks.
add_action( 'woocommerce_init', [ 'BobGo_Shipping\app\WooCommerce', 'register_checkout_field' ] );
add_action( 'woocommerce_set_additional_field_value', [ 'BobGo_Shipping\app\WooCommerce', 'set_additional_field_value' ], 10, 4 );
// Order.
add_action( 'woocommerce_new_order', [ 'BobGo_Shipping\app\WooCommerce', 'save_order_meta' ], 10, 2 );
add_action( 'woocommerce_update_order', [ 'BobGo_Shipping\app\WooCommerce', 'save_order_meta' ], 10, 2 );
add_filter( 'woocommerce_admin_billing_fields', [ 'BobGo_Shipping\app\WooCommerce', 'add_suburb_to_admin_address_fields' ], 20, 1 );
add_filter( 'woocommerce_admin_shipping_fields', [ 'BobGo_Shipping\app\WooCommerce', 'add_suburb_to_admin_address_fields' ], 20, 1 );

// Custom order statuses.
add_action( 'init', [ 'BobGo_Shipping\app\OrderStatus', 'register_statuses' ] );
add_filter( 'wc_order_statuses', [ 'BobGo_Shipping\app\OrderStatus', 'add_to_order_statuses' ] );
add_action( 'admin_enqueue_scripts', [ 'BobGo_Shipping\app\OrderStatus', 'enqueue_status_styles' ] );

// Email notification control.
add_filter( 'woocommerce_email_enabled_customer_completed_order', [ 'BobGo_Shipping\app\EmailControl', 'maybe_suppress_email' ], 10, 3 );

// Customer shipped / partially-shipped notification email (only when custom statuses are enabled).
add_filter( 'woocommerce_email_classes', [ 'BobGo_Shipping\app\EmailOrderShipped', 'register_email_class' ] );

// Outbound order sync.
add_action( 'woocommerce_checkout_order_processed', [ 'BobGo_Shipping\app\OrderSync', 'on_order_created' ] );
add_action( 'woocommerce_store_api_checkout_order_processed', [ 'BobGo_Shipping\app\OrderSync', 'on_order_created_from_block' ] );
add_action( 'woocommerce_update_order', [ 'BobGo_Shipping\app\OrderSync', 'on_order_updated' ], 20, 2 );
add_action( 'woocommerce_order_status_changed', [ 'BobGo_Shipping\app\OrderSync', 'on_order_status_changed' ], 10, 4 );
add_action( 'bobgo_sync_order', [ 'BobGo_Shipping\app\OrderSync', 'handle_sync_order' ] );
add_action( 'bobgo_update_order', [ 'BobGo_Shipping\app\OrderSync', 'handle_update_order' ] );
add_action( 'bobgo_update_order_status', [ 'BobGo_Shipping\app\OrderSync', 'handle_update_order_status' ], 10, 2 );
add_action( 'bobgo_backfill_items_fingerprints', [ 'BobGo_Shipping\app\OrderSync', 'handle_items_fingerprint_backfill' ] );
// Trash/delete handling.
add_action( 'woocommerce_trash_order', [ 'BobGo_Shipping\app\OrderSync', 'on_order_trashed' ] );
add_action( 'wp_trash_post', [ 'BobGo_Shipping\app\OrderSync', 'on_post_trashed' ] );
// WooCommerce Subscriptions copies meta from the subscription onto renewal and
// resubscribe orders. Bob Go link/sync meta must never be inherited: a copied
// _bobgo_order_id would mark the new order as already synced and point inbound
// webhooks at the wrong Bob Go order. Both current and legacy WCS copy filters
// are hooked; the filters simply never fire when Subscriptions is not installed.
add_filter( 'wc_subscriptions_renewal_order_data', [ 'BobGo_Shipping\app\OrderSync', 'exclude_bobgo_meta_from_copy' ] );
add_filter( 'wc_subscriptions_resubscribe_order_data', [ 'BobGo_Shipping\app\OrderSync', 'exclude_bobgo_meta_from_copy' ] );
add_filter( 'wcs_renewal_order_meta', [ 'BobGo_Shipping\app\OrderSync', 'exclude_bobgo_meta_from_copy' ] );
add_filter( 'wcs_resubscribe_order_meta', [ 'BobGo_Shipping\app\OrderSync', 'exclude_bobgo_meta_from_copy' ] );

// Inbound webhooks (REST API).
add_action( 'rest_api_init', [ 'BobGo_Shipping\app\Webhook', 'register_routes' ] );
add_action( 'bobgo_order_updated_webhook', [ 'BobGo_Shipping\app\Webhook', 'apply_delivery_address' ], 10, 2 );
add_action( 'bobgo_order_updated_webhook', [ 'BobGo_Shipping\app\Webhook', 'maybe_cancel_order' ], 10, 2 );

// Block legacy v3-era WC webhooks (Bob Go / uAfrica delivery URLs) at delivery time.
// A v3 channel that self-heals its provisioning re-creates them the moment it sees
// none; this guarantees they never fire, and the recurring cleanup deletes the rows.
add_filter( 'woocommerce_webhook_should_deliver', [ 'BobGo_Shipping\app\Activation', 'suppress_legacy_webhook_delivery' ], 10, 2 );

// Order detail meta box.
add_action( 'add_meta_boxes', [ 'BobGo_Shipping\app\MetaBox', 'register' ], 10, 2 );
add_action( 'wp_ajax_bobgo_resync_order', [ 'BobGo_Shipping\app\MetaBox', 'handle_resync_ajax' ] );

// Reconciliation (Action Scheduler).
add_action( 'bobgo_reconciliation', [ 'BobGo_Shipping\app\Reconciliation', 'handle_reconciliation' ] );
add_action( 'bobgo_purge_sync_log', [ 'BobGo_Shipping\app\Reconciliation', 'handle_purge' ] );
add_action( 'bobgo_full_reconciliation_batch', [ 'BobGo_Shipping\app\Reconciliation', 'handle_full_reconciliation_batch' ], 10, 2 );

// One-shot link audit (Action Scheduler) — verifies stored Bob Go order links.
add_action( 'bobgo_link_audit_batch', [ 'BobGo_Shipping\app\LinkAudit', 'handle_batch' ] );

// Link reset (Action Scheduler) — drops every stored link after an account change.
add_action( 'bobgo_link_reset_batch', [ 'BobGo_Shipping\app\LinkAudit', 'handle_reset_batch' ] );

// Re-arm an interrupted link reset. The hourly reconciliation also does this, but
// only while the store is connected — and a disconnect both removes the key and
// unschedules that job, so the pass a disconnect queues is exactly the one
// reconciliation can never resume. This hook covers it: no-ops in one cached
// option read unless a pass is genuinely mid-flight, and needs no API access.
add_action( 'admin_init', [ 'BobGo_Shipping\app\LinkAudit', 'maybe_resume_reset' ] );

// Meta-query support for wc_get_orders() on the legacy CPT order datastore,
// which silently drops the standard meta_query argument.
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'BobGo_Shipping\app\OrderQuery', 'translate_cpt_meta_query' ], 10, 2 );

// Deferred webhook (re-)registration after (re)activation — keeps the activation hook free of remote HTTP calls.
add_action( 'bobgo_register_webhooks', [ 'BobGo_Shipping\app\Activation', 'run_deferred_webhook_registration' ] );

// Failed sync recovery.
add_action( 'admin_notices', [ 'BobGo_Shipping\app\Admin', 'display_failed_sync_notice' ] );
add_action( 'wp_ajax_bobgo_bulk_resync_failed', [ 'BobGo_Shipping\app\Admin', 'handle_bulk_resync_failed' ] );

// Connection-health warning (invalid key / webhooks not registered).
add_action( 'admin_notices', [ 'BobGo_Shipping\app\Admin', 'display_connection_health_notice' ] );

// Custom order field mapping (meta key discovery).
add_action( 'wp_ajax_bobgo_refresh_meta_keys', [ 'BobGo_Shipping\app\OrderMetaDiscovery', 'handle_ajax_refresh_keys' ] );
add_action( 'wp_ajax_bobgo_get_meta_sample', [ 'BobGo_Shipping\app\OrderMetaDiscovery', 'handle_ajax_get_sample' ] );

// Meta box assets for order edit screens.
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		// HPOS order admin uses a dedicated screen; legacy CPT order admin uses
		// post.php / post-new.php / edit.php — but only when the post type is shop_order.
		if ( 'woocommerce_page_wc-orders' !== $hook ) {
			if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'shop_order' !== $screen->post_type ) {
				return;
			}
		}
		wp_enqueue_style(
			'bobgo-metabox',
			BOBGO_SHIPPING_URL . 'assets/css/metabox.css',
			[],
			BOBGO_SHIPPING_VERSION
		);
		wp_enqueue_script(
			'bobgo-metabox',
			BOBGO_SHIPPING_URL . 'assets/js/metabox.js',
			[],
			BOBGO_SHIPPING_VERSION,
			true
		);
		wp_localize_script(
			'bobgo-metabox',
			'bobgo_metabox',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bobgo_resync_order' ),
				'syncingText' => __( 'Syncing...', 'bobgo-shipping' ),
				'syncedText'  => __( 'Synced!', 'bobgo-shipping' ),
			]
		);
	}
);

// Declare compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true ); // HPOS.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true ); // Blocks.
		}
	}
);
