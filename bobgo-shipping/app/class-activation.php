<?php
/**
 * Plugin activation, migration, and per-site setup.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activation
 *
 * @package BobGo_Shipping\app
 */
class Activation {

	/**
	 * Display the activation notice
	 */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return; // only check this on the plugins page.
		}
		$option = get_option( 'bobgo', [] );
		if ( empty( $option['activate_message'] ) ) {
			return; // no message to display.
		}
		$message = $option['activate_message'];

		echo '<div class="updated notice is-dismissible"><p>' . nl2br( esc_html( $message ) ) . '</p></div>';

		unset( $option['activate_message'] ); // display notice only once, so we clear it.
		update_option( 'bobgo', $option );
	}

	/**
	 * Display migration notice after importing settings from uAfrica plugin.
	 */
	public static function migration_notice() {
		if ( get_transient( 'bobgo_migrated_from_uafrica' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Bob Go: Settings have been imported from your previous uAfrica Shipping installation.', 'bobgo-shipping' );
			echo '</p></div>';
			delete_transient( 'bobgo_migrated_from_uafrica' );
		}
	}

	/**
	 * On activation do stuff
	 *
	 * @param bool $network_wide if the plugin in installed network wide.
	 */
	public static function activation( $network_wide ) {
		try {
			if ( is_multisite() && $network_wide ) {
				$blog_ids = get_sites( [ 'fields' => 'ids' ] );
				foreach ( $blog_ids as $blog_id ) {
					// Verify switch_to_blog succeeded before proceeding.
					if ( ! switch_to_blog( $blog_id ) ) {
						continue; // Skip if switch fails.
					}
					self::setup_for_site();
					restore_current_blog();
				}
			} else {
				self::setup_for_site();
			}
		} catch ( \Exception $e ) {
			// Deactivate plugin in case of any error and show a meaningful error message.
			deactivate_plugins( plugin_basename( BOBGO_SHIPPING_DIR . '/bobgo-shipping.php' ) );
			if ( is_admin() ) {
				// Use WC_Logger if available, fallback to error_log.
				if ( function_exists( 'wc_get_logger' ) ) {
					$logger = wc_get_logger();
					$logger->error( 'Plugin activation failed: ' . $e->getMessage(), [ 'source' => 'bobgo-shipping' ] );
				}
				add_action(
					'admin_notices',
					function () {
						?>
					<div class="notice notice-error">
						<p><?php esc_html_e( 'Plugin activation failed.', 'bobgo-shipping' ); ?></p>
					</div>
						<?php
					}
				);
			}
			return;
		}
	}

	/**
	 * On deactivation: deregister remote webhook subscriptions while the API key is still
	 * valid, and unschedule recurring Action Scheduler jobs. Leaves options and tables
	 * intact so reactivation is non-destructive.
	 */
	public static function deactivation(): void {
		try {
			// Deregister BEFORE Api::save_api_key('') would happen — but we don't clear the key on
			// deactivation, so this can safely call the Bob Go API to remove its subscriptions.
			if ( class_exists( '\BobGo_Shipping\app\Webhook' ) ) {
				Webhook::deregister_subscriptions();
			}

			// Stop recurring jobs so they don't keep firing against a dead API.
			if ( class_exists( '\BobGo_Shipping\app\Reconciliation' ) ) {
				Reconciliation::unschedule();
			}

			// Stop any in-flight link-audit batches; the pass resumes from its
			// per-order markers when the plugin is reactivated. A reset pass
			// resumes the same way — its remaining work is whatever orders
			// still carry a link.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( LinkAudit::HOOK_BATCH );
				as_unschedule_all_actions( LinkAudit::HOOK_RESET );
			}

			// Parting sweep: legacy v3-era WC webhooks are delivered by WC core,
			// so they would keep firing (and looping order updates) with this
			// plugin disabled. Remove them while we still can.
			self::cleanup_legacy_webhooks();

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'bobgo_sync_order' );
				as_unschedule_all_actions( 'bobgo_update_order' );
				as_unschedule_all_actions( 'bobgo_update_order_status' );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Bob Go deactivation cleanup failed: ' . $e->getMessage(),
					[ 'source' => 'bobgo-shipping' ]
				);
			}
		}
	}

	/**
	 * Migrate settings from the old uAfrica plugin if present.
	 * Only migrates if new keys don't already exist (non-destructive).
	 *
	 * @return bool True if migration occurred, false otherwise.
	 */
	public static function migrate_from_uafrica(): bool {
		$migrated = false;

		// Migrate general settings.
		$old_options = get_option( 'uafrica' );
		$new_options = get_option( 'bobgo' );
		if ( false !== $old_options && false === $new_options ) {
			update_option( 'bobgo', $old_options );
			$migrated = true;
		}

		// Migrate shipping settings. The option name is WooCommerce core's own
		// key for a shipping method's settings — woocommerce_{method_id}_settings,
		// created by WC_Settings_API::get_option_key() — not a name this plugin chose.
		$old_shipping = get_option( 'woocommerce_uafrica_shipping_settings' );
		$new_shipping = get_option( 'woocommerce_bobgo_shipping_settings' );
		if ( false !== $old_shipping && false === $new_shipping ) {
			update_option( 'woocommerce_bobgo_shipping_settings', $old_shipping );
			$migrated = true;
		}

		if ( $migrated ) {
			// Store flag to show admin notice.
			set_transient( 'bobgo_migrated_from_uafrica', true, 60 );
			// Log migration.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( 'Settings migrated from uAfrica plugin', [ 'source' => 'bobgo-shipping' ] );
			}
		}

		return $migrated;
	}

	/**
	 * Run the full per-site setup once, on admin_init, if it has not run yet.
	 *
	 * This is the fallback for the case where the activation hook was skipped because
	 * conflict detection caused bobgo-shipping.php to return early (the old uAfrica/Bob Go
	 * v3 plugin was still active when v4 was activated). In that scenario WordPress fires
	 * `activate_{plugin}` but our callback was never registered, so setup_for_site() never
	 * ran. Deactivating the old plugin does not re-fire activation, so without this the full
	 * setup — webhook cleanup, page creation, defaults, reconciliation scheduling — would only
	 * happen on a genuine re-activation.
	 *
	 * setup_for_site() is composed of individually-guarded, idempotent steps, so running it
	 * here is equivalent to a real activation. Guarded by OPTION_SETUP_COMPLETED so it runs
	 * at most once per site.
	 */
	public static function maybe_run_deferred_setup(): void {
		if ( get_option( self::OPTION_SETUP_COMPLETED, false ) ) {
			return;
		}

		self::setup_for_site();
	}

	/**
	 * Fire on creating a new blog.
	 *
	 * @param int $blog_id the blog id of the new blog.
	 */
	public static function create_new_blog( $blog_id ) {
		// wpmu_new_blog fires on non-admin site-creation paths too (REST, WP-CLI,
		// programmatic wp_insert_site), where wp-admin/includes/plugin.php — which
		// defines is_plugin_active_for_network() — is not loaded.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( BOBGO_SHIPPING_DIR ) . '/bobgo-shipping.php' ) ) {
			switch_to_blog( $blog_id );
			self::setup_for_site();
			restore_current_blog();
		}
	}

	/**
	 * Option key tracking the schema version applied to this site.
	 */
	const OPTION_DB_VERSION = 'bobgo_db_version';

	/**
	 * Option key recording that full per-site setup has completed.
	 * Gates the admin_init deferred-setup fallback so it runs at most once.
	 */
	const OPTION_SETUP_COMPLETED = 'bobgo_setup_completed';

	/**
	 * Run all per-site activation steps.
	 */
	private static function setup_for_site(): void {
		self::migrate_from_uafrica();
		self::migrate_zone_methods();
		self::migrate_order_meta();
		self::migrate_legacy_tracking_markup();
		self::cleanup_legacy_webhooks();
		self::create_page();
		self::enable_suburb_fields( true );
		SyncLog::create_table();
		self::set_default_email_notifications();
		self::set_default_custom_order_statuses();
		Reconciliation::schedule();
		Reconciliation::schedule_purge();
		OrderSync::schedule_items_fingerprint_backfill();
		self::schedule_webhook_registration();
		update_option( self::OPTION_DB_VERSION, BOBGO_SHIPPING_VERSION, false );
		update_option( self::OPTION_SETUP_COMPLETED, 1, false );
	}

	/**
	 * Schedule a one-time webhook (re-)registration shortly after setup.
	 *
	 * Activation hooks must not make remote HTTP calls — they run per-blog on
	 * multisite and a timeout would fail the whole activation — yet deactivation
	 * deregisters the Bob Go subscriptions. Without this, a deactivate/reactivate
	 * cycle silently loses all inbound webhooks. Deferring registration to an
	 * Action Scheduler job keeps the activation hook fast while still restoring
	 * delivery on the next request. Only runs when a key is present and not already
	 * queued. See Phase 3 #2.
	 *
	 * @return void
	 */
	private static function schedule_webhook_registration(): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( 'bobgo_register_webhooks', [], 'bobgo-shipping' ) ) {
			return;
		}

		as_schedule_single_action( time(), 'bobgo_register_webhooks', [], 'bobgo-shipping' );
	}

	/**
	 * Action Scheduler callback: (re-)register webhook subscriptions if connected.
	 *
	 * Runs outside the activation hook so the remote POST never blocks activation.
	 * register_subscriptions() is idempotent (it deregisters then registers). See
	 * Phase 3 #2.
	 *
	 * @return void
	 */
	public static function run_deferred_webhook_registration(): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		Webhook::register_subscriptions();
	}

	/**
	 * One-shot rename of shipping-zone rows from the pre-v4 method ID `uafrica_shipping`
	 * to the current ID `bobgo_shipping`, plus the matching per-instance option keys.
	 *
	 * Without this, every existing v3 merchant's shipping zones would silently stop
	 * returning rates after upgrade because WooCommerce stores method instances by
	 * method_id and the new class only registers under `bobgo_shipping`.
	 */
	public static function migrate_zone_methods(): void {
		if ( get_option( 'bobgo_zone_methods_migrated' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';

		// The table is missing when this plugin activates before WooCommerce has
		// installed its schema (fresh site, or WooCommerce added later). There are
		// no zone rows to rename in that case; leave the flag unset so the next
		// activation or upgrade pass retries once the table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$instance_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s",
				'uafrica_shipping'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			[ 'method_id' => 'bobgo_shipping' ],
			[ 'method_id' => 'uafrica_shipping' ],
			[ '%s' ],
			[ '%s' ]
		);

		// Migrate the matching instance-settings options so the renamed methods keep
		// their config. These key names are WooCommerce core's own format for
		// per-instance shipping method settings (WC_Settings_API::get_option_key()),
		// not names this plugin chose.
		if ( is_array( $instance_ids ) ) {
			foreach ( $instance_ids as $instance_id ) {
				$old_key = 'woocommerce_uafrica_shipping_' . (int) $instance_id . '_settings';
				$new_key = 'woocommerce_bobgo_shipping_' . (int) $instance_id . '_settings';
				$old     = get_option( $old_key );
				$new     = get_option( $new_key );
				if ( false !== $old && false === $new ) {
					update_option( $new_key, $old );
				}
				if ( false !== $old ) {
					delete_option( $old_key );
				}
			}
		}

		update_option( 'bobgo_zone_methods_migrated', 1, false );

		if ( function_exists( 'wc_get_logger' ) && false !== $updated && $updated > 0 ) {
			wc_get_logger()->info(
				sprintf( 'Renamed %d shipping zone method(s) from uafrica_shipping to bobgo_shipping', (int) $updated ),
				[ 'source' => 'bobgo-shipping' ]
			);
		}
	}

	/**
	 * One-shot rewrite of legacy uAfrica tracking-widget markup in post content.
	 *
	 * The [uafrica] shortcode and the uafrica/shipping block are no longer
	 * registered — WordPress.org requires all registered names to carry this
	 * plugin's own prefix — so pages created by the pre-rebrand plugin would
	 * render the raw shortcode/nothing after upgrade. Rewriting the stored
	 * content to the [bobgo] / bobgo/shipping equivalents is behaviour-preserving:
	 * both names always rendered through the same callback.
	 */
	public static function migrate_legacy_tracking_markup(): void {
		if ( get_option( 'bobgo_legacy_markup_migrated' ) ) {
			return;
		}

		global $wpdb;

		// Match the exact legacy markers only: "[uafrica]" / "[uafrica " (with
		// attributes) and the block name "wp:uafrica/shipping" — never a
		// substring of some other plugin's shortcode like [uafrica_foo].
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ( 'page', 'post' )
				   AND post_status NOT IN ( 'trash', 'auto-draft' )
				   AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
				'%' . $wpdb->esc_like( '[uafrica]' ) . '%',
				'%' . $wpdb->esc_like( '[uafrica ' ) . '%',
				'%' . $wpdb->esc_like( 'wp:uafrica/shipping' ) . '%'
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$content = str_replace(
				[ '[uafrica]', '[uafrica ', 'wp:uafrica/shipping' ],
				[ '[bobgo]', '[bobgo ', 'wp:bobgo/shipping' ],
				$post->post_content
			);

			if ( $content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => (int) $post_id,
						'post_content' => $content,
					]
				);
			}
		}

		update_option( 'bobgo_legacy_markup_migrated', 1, false );

		if ( ! empty( $post_ids ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'Rewrote legacy uAfrica tracking markup to Bob Go equivalents in %d post(s)', count( $post_ids ) ),
				[ 'source' => 'bobgo-shipping' ]
			);
		}
	}

	/**
	 * Apply schema upgrades when the plugin version changes.
	 *
	 * Activation hooks only fire on activate, not on plugin update — so a
	 * site that upgrades through a version that adds a table never runs
	 * setup_for_site(). This compares the stored schema version against the
	 * current plugin version and runs idempotent dbDelta migrations to
	 * close the gap. Wired to admin_init so it runs on the next admin load
	 * after an upgrade.
	 */
	public static function maybe_upgrade_db(): void {
		$stored = (string) get_option( self::OPTION_DB_VERSION, '' );
		if ( BOBGO_SHIPPING_VERSION === $stored ) {
			return;
		}

		// dbDelta is idempotent — safe to run on every version bump.
		SyncLog::create_table();
		// Activation hooks don't fire on plugin update; run the zone-method rename here too (idempotent).
		self::migrate_zone_methods();
		self::migrate_order_meta();
		self::migrate_legacy_tracking_markup();
		// Orders linked before the item fingerprint existed must be stamped
		// before their next update, not by it. See OrderSync::items_need_pushing().
		OrderSync::schedule_items_fingerprint_backfill();

		update_option( self::OPTION_DB_VERSION, BOBGO_SHIPPING_VERSION, false );
	}

	/**
	 * One-shot copy of any `_bobgo_order_ref` order meta to `_bobgo_order_id`, then delete
	 * the legacy key. v4 standardises on `_bobgo_order_id` (item 12 of the v4 review).
	 */
	public static function migrate_order_meta(): void {
		if ( get_option( 'bobgo_order_meta_migrated' ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 SELECT pm.post_id, %s, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 LEFT JOIN {$wpdb->postmeta} existing
				   ON existing.post_id = pm.post_id AND existing.meta_key = %s
				 WHERE pm.meta_key = %s AND existing.meta_id IS NULL",
				'_bobgo_order_id',
				'_bobgo_order_id',
				'_bobgo_order_ref'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_bobgo_order_ref' ] );

		// HPOS: also migrate from wc_orders_meta if present.
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_hpos = (int) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === 0
			? false
			: true;
		if ( $has_hpos ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (order_id, meta_key, meta_value)
					 SELECT m.order_id, %s, m.meta_value
					 FROM %i m
					 LEFT JOIN %i existing
					   ON existing.order_id = m.order_id AND existing.meta_key = %s
					 WHERE m.meta_key = %s AND existing.id IS NULL',
					$hpos_table,
					'_bobgo_order_id',
					$hpos_table,
					$hpos_table,
					'_bobgo_order_id',
					'_bobgo_order_ref'
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->delete( $hpos_table, [ 'meta_key' => '_bobgo_order_ref' ] );
		}

		update_option( 'bobgo_order_meta_migrated', 1, false );
	}

	/**
	 * Create a page and set the shortcode or add the gutenbergblock.
	 */
	public static function create_page() {
		$option = get_option( 'bobgo', [] );

		// Check if the 'shipping_page' option exists and the page ID is valid.
		if ( ! empty( $option['shipping_page'] ) && get_post_status( $option['shipping_page'] ) ) {

			$option['activate_message']  = __( 'A shipping page was already found from a previous activation.', 'bobgo-shipping' ) . PHP_EOL;
			$option['activate_message'] .= __( 'You can change this page in the settings.', 'bobgo-shipping' );
			update_option( 'bobgo', $option );

			return;
		}

		$existing_pages = get_posts(
			[
				'post_type'              => 'page',
				'title'                  => 'Shipping details',
				'post_status'            => 'all',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			]
		);

		if ( ! empty( $existing_pages ) ) {
			$option['shipping_page']     = $existing_pages[0]->ID;
			$option['activate_message']  = __( 'Found an existing shipping page.', 'bobgo-shipping' ) . PHP_EOL;
			$option['activate_message'] .= __( 'You can change this page in the settings.', 'bobgo-shipping' );
			update_option( 'bobgo', $option );

			return;
		}

		// Create content based on editor type.
		$content = ! use_block_editor_for_post_type( 'page' )
			? '[bobgo bg_color="#000000" text_color="#ffffff"]'
			: '<!-- wp:bobgo/shipping /-->';

		$arr = [
			'post_title'   => __( 'Shipping details', 'bobgo-shipping' ),
			'post_content' => $content,
			'post_type'    => 'page',
			'post_status'  => 'publish',
		];

		$new_page_id                 = wp_insert_post( $arr );
		$option['shipping_page']     = $new_page_id;
		$option['activate_message']  = __( 'Created a new shipping page.', 'bobgo-shipping' ) . PHP_EOL;
		$option['activate_message'] .= __( 'You can change this page in the settings.', 'bobgo-shipping' );

		update_option( 'bobgo', $option );
	}

	/**
	 * Enable suburb fields at checkout by default.
	 *
	 * @param bool $force Whether to force-enable even if already set.
	 */
	public static function enable_suburb_fields( $force = false ) {
		$option = get_option( 'bobgo', [] );
		if ( $force || ! isset( $option['suburb_at_checkout'] ) ) {
			$option['suburb_at_checkout'] = 1;
			update_option( 'bobgo', $option );
		}
	}

	/**
	 * Delivery-URL fragments identifying legacy v3-era WooCommerce webhooks.
	 * Covers both the Bob Go and the pre-rebrand uAfrica backends. Native WC
	 * webhooks delivering to these hosts are always stale in a v4 install —
	 * worse, they form one leg of an order-update sync loop: WC core fires
	 * them on every order save (including saves made by v4's own inbound
	 * webhook handler), the backend re-ingests the order and echoes an update
	 * back. See the 2026-07 address-growth incident.
	 *
	 * @var string[]
	 */
	const LEGACY_WEBHOOK_URL_FRAGMENTS = [
		'bobgo.co.za',
		'uafrica',
	];

	/**
	 * Filter callback (woocommerce_webhook_should_deliver): block delivery of
	 * legacy v3-era webhooks at source.
	 *
	 * The cleanup sweep alone leaves a window — a v3 channel that self-heals
	 * its provisioning re-creates its WC webhooks the moment it sees none, and
	 * a sync loop can run hundreds of laps before the next sweep. WooCommerce
	 * consults this filter before every delivery, so a legacy webhook never
	 * fires even while its row still exists; the sweeps just collect the
	 * corpses.
	 *
	 * @param bool  $should_deliver Whether WooCommerce intends to deliver.
	 * @param mixed $webhook        The WC_Webhook being delivered.
	 * @return bool
	 */
	public static function suppress_legacy_webhook_delivery( $should_deliver, $webhook ): bool {
		if ( ! $should_deliver || ! is_object( $webhook ) || ! method_exists( $webhook, 'get_delivery_url' ) ) {
			return (bool) $should_deliver;
		}

		$delivery_url = (string) $webhook->get_delivery_url();

		foreach ( self::LEGACY_WEBHOOK_URL_FRAGMENTS as $fragment ) {
			if ( false !== stripos( $delivery_url, $fragment ) ) {
				// One log line per request, not per delivery — a loop can attempt
				// many deliveries in a single request and would spam the log.
				static $logged = false;
				if ( ! $logged && function_exists( 'wc_get_logger' ) ) {
					$logged = true;
					wc_get_logger()->warning(
						sprintf( 'Blocked delivery of legacy WooCommerce webhook to %s — superseded by v4 API subscriptions; it will be removed by the next cleanup sweep.', $delivery_url ),
						[ 'source' => 'bobgo-shipping' ]
					);
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove legacy WooCommerce webhooks from v3 that deliver to Bob Go or
	 * uAfrica. These are superseded by v4's Bob Go API webhook subscriptions.
	 *
	 * Public because it runs defensively from more than one place: activation,
	 * deactivation, and the hourly reconciliation job — the backend can
	 * re-provision these webhooks over the WC REST API at any time, so a
	 * one-shot cleanup at activation is not enough. Delivery of matching
	 * webhooks is additionally blocked in real time by
	 * suppress_legacy_webhook_delivery(), so the sweep cadence is not a
	 * correctness window.
	 */
	public static function cleanup_legacy_webhooks(): void {
		if ( ! class_exists( 'WC_Webhook' ) || ! function_exists( 'wc_get_webhook' ) ) {
			return;
		}

		$data_store  = \WC_Data_Store::load( 'webhook' );
		$webhook_ids = $data_store->search_webhooks( [ 'limit' => -1 ] );
		$deleted     = 0;

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}

			$delivery_url = $webhook->get_delivery_url();
			foreach ( self::LEGACY_WEBHOOK_URL_FRAGMENTS as $fragment ) {
				if ( false !== stripos( $delivery_url, $fragment ) ) {
					$webhook->delete( true );
					++$deleted;
					break;
				}
			}
		}

		if ( $deleted > 0 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'Removed %d legacy WooCommerce webhook(s) delivering to Bob Go/uAfrica. These are superseded by v4 API subscriptions and can cause order-update sync loops.', $deleted ),
				[ 'source' => 'bobgo-shipping' ]
			);
		}
	}

	/**
	 * Set default email notification preference to 'woocommerce' if not already set.
	 */
	private static function set_default_email_notifications(): void {
		$option = get_option( 'bobgo', [] );
		if ( ! isset( $option['email_notifications'] ) ) {
			$option['email_notifications'] = EmailControl::MODE_WOOCOMMERCE;
			update_option( 'bobgo', $option );
		}
	}

	/**
	 * Set default custom order statuses to enabled if not already set.
	 */
	private static function set_default_custom_order_statuses(): void {
		$option = get_option( 'bobgo', [] );
		if ( ! isset( $option['custom_order_statuses'] ) ) {
			$option['custom_order_statuses'] = 1;
			update_option( 'bobgo', $option );
		}
	}
}
