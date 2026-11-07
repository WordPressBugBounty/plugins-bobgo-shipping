<?php
/**
 * Admin-side hooks: settings link, order list column, shipping method registration.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;
use WC_Order_Item_Shipping;

/**
 * Class Admin
 *
 * @package BobGo_Shipping\app
 */
class Admin {

	/**
	 * Most failed orders reported by the notice and drained by one click of the
	 * bulk re-sync action. Both use the same figure so the two never disagree.
	 */
	const FAILED_SYNC_LIMIT = 100;

	/**
	 * Add a settings link to the plugin on the plugin page.
	 *
	 * @param array $links An array of plugin action links.
	 *
	 * @return array
	 */
	public static function add_plugin_page_settings_link( array $links ): array {
		// Point to the new consolidated settings page.
		$href          = esc_url( admin_url( 'admin.php?page=bobgo-settings' ) );
		$settings_link = '<a href="' . $href . '">' . esc_html__( 'Settings', 'bobgo-shipping' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * On the post overview page label the shipping page as such.
	 *
	 * @param array         $post_states current poststates this page might have.
	 * @param \WP_Post|null $post current post.
	 *
	 * @return array
	 */
	public static function label_page_as_shipping( array $post_states, ?\WP_Post $post = null ): array {
		$option = WooCommerce::get_options();
		if ( ! empty( $post->ID ) && ! empty( $option['shipping_page'] ) && $post->ID === $option['shipping_page'] ) {
			$post_states['bobgo_shipping_page'] = __( 'Bob Go page', 'bobgo-shipping' );
		}

		return $post_states;
	}

	/**
	 * Add a new column to the order overview.
	 *
	 * @param array $order_columns array of current admin column names.
	 *
	 * @return array
	 */
	public static function order_column_name( array $order_columns ): array {
		$order_columns['bobgo_shipping'] = _x( 'Bob Go', 'column name', 'bobgo-shipping' );

		return $order_columns;
	}

	/**
	 * Display the shipping link.
	 *
	 * @param string       $column_name       the column name.
	 * @param WC_Order|int $order_or_order_id The order object or order ID.
	 */
	public static function order_column_content( string $column_name, $order_or_order_id ) {
		if ( 'bobgo_shipping' !== $column_name ) {
			return;
		}

		// Legacy CPT-based order compatibility.
		$order = $order_or_order_id instanceof WC_Order ? $order_or_order_id : wc_get_order( $order_or_order_id );
		if ( ! $order ) {
			return;
		}

		/*
		 * The column conveys three independent signals (see QA #4):
		 *   1. Sync health — Failed / Synced / — (not yet synced). Read from
		 *      _bobgo_sync_status independently of _bobgo_order_id so a genuine
		 *      sync failure shows "Failed" rather than the old dead "Track order".
		 *   2. Fulfillment — Pending / Partial / Shipped / Delivered, but only when
		 *      custom order statuses are disabled; otherwise the Status column
		 *      already carries this and the column would just duplicate it.
		 *   3. Tracking — a link to the tracking page, only once a shipment actually
		 *      exists (no dead pre-sync link that always resolves to nothing).
		 */
		$order_ref   = $order->get_meta( '_bobgo_order_id' );
		$sync_status = $order->get_meta( '_bobgo_sync_status' );
		$shipments   = $order->get_meta( '_bobgo_shipments' );
		$shipments   = is_array( $shipments ) ? $shipments : [];

		$is_synced = ! empty( $order_ref );

		if ( 'failed' === $sync_status ) {
			$badge_class = 'failed';
			$badge_label = __( 'Failed', 'bobgo-shipping' );
		} elseif ( ! $is_synced ) {
			$badge_class = 'none';
			$badge_label = '—';
		} elseif ( ! OrderStatus::is_enabled() ) {
			// Custom statuses off: surface the fulfillment state (which also implies
			// a successful sync, so no separate "Synced" badge is needed).
			$badge_class = 'pending';
			$badge_label = __( 'Pending', 'bobgo-shipping' );

			if ( ! empty( $shipments ) ) {
				if ( Webhook::all_shipments_delivered( $shipments ) ) {
					$badge_class = 'delivered';
					$badge_label = __( 'Delivered', 'bobgo-shipping' );
				} else {
					$fulfillment_state = Webhook::determine_fulfillment_status( $order, $shipments );
					if ( 'shipped' === $fulfillment_state ) {
						$badge_class = 'shipped';
						$badge_label = __( 'Shipped', 'bobgo-shipping' );
					} elseif ( 'partially-shipped' === $fulfillment_state ) {
						$badge_class = 'partial';
						$badge_label = __( 'Partial', 'bobgo-shipping' );
					}
				}
			}
		} else {
			// Custom statuses on: the Status column shows fulfillment, so the column
			// only needs to confirm sync health.
			$badge_class = 'synced';
			$badge_label = __( 'Synced', 'bobgo-shipping' );
		}

		echo '<span class="bobgo-col-badge bobgo-col-badge--' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';

		// Tracking link — only once a shipment exists and a tracking page is set.
		if ( empty( $shipments ) ) {
			return;
		}

		$option = WooCommerce::get_options();
		if ( empty( $option['shipping_page'] ) ) {
			return;
		}

		$page_link = get_permalink( $option['shipping_page'] );
		if ( empty( $page_link ) ) {
			return;
		}

		$order_number = sanitize_text_field( $order->get_order_number() );
		$full_link    = empty( $order_number )
			? $page_link
			: add_query_arg( [ 'order-number' => rawurlencode( $order_number ) ], $page_link );

		echo '<br><a href="' . esc_url( $full_link ) . '">' . esc_html_x( 'Track order', 'link in woo admin', 'bobgo-shipping' ) . '</a>';
	}

	/**
	 * Hide the raw Bob Go service code from the order screen's shipping line.
	 *
	 * The code (for example "bobgo_286837_15_0") is the identifier Bob Go uses to
	 * know which service the shopper picked. It is persisted on the order and sent
	 * back as buyer_selected_service_code, so its stored value must never change --
	 * but it means nothing to a merchant reading the order, and the readable name
	 * of the same rate is already the shipping line's own title. Hide the row and
	 * leave the readable ones behind.
	 *
	 * Support can bring it back on a single store with:
	 * add_filter( 'bobgo_show_service_code_itemmeta', '__return_true' );
	 *
	 * @param array $hidden_keys Order item meta keys hidden from the admin order screen.
	 *
	 * @return array
	 */
	public static function hide_service_code_itemmeta( array $hidden_keys ): array {
		/**
		 * Filters whether the raw Bob Go service code stays visible on the order screen.
		 *
		 * @since 4.0.57
		 *
		 * @param bool $show Whether to show the raw service code. Default false.
		 */
		if ( apply_filters( 'bobgo_show_service_code_itemmeta', false ) ) {
			return $hidden_keys;
		}

		$hidden_keys[] = 'bobgo_service_code';

		return $hidden_keys;
	}

	/**
	 * Label the Bob Go shipping line's meta rows in words a merchant reads.
	 *
	 * WooCommerce renders order item meta under its raw key, so the rate detail
	 * stored by Shipping::process_rates_results() showed on the order as
	 * "method_description" and "min_delivery_date". This is display only: the
	 * stored keys and values are untouched, since Bob Go reads them back.
	 *
	 * @param mixed $display_key The label WooCommerce is about to render.
	 * @param mixed $meta        The meta row being rendered.
	 * @param mixed $item        The order item the meta belongs to.
	 *
	 * @return string
	 */
	public static function label_shipping_itemmeta( $display_key, $meta = null, $item = null ): string {
		$display_key = is_scalar( $display_key ) ? (string) $display_key : '';

		if ( ! $item instanceof WC_Order_Item_Shipping ) {
			return $display_key;
		}

		// These key names are generic enough for another shipping plugin to use, so
		// only relabel them on a line the Bob Go method itself placed. The legacy
		// uafrica ID resolves to the same shipping method (Admin::register_shipping_method).
		$method_id = $item->get_method_id();
		if ( 'bobgo_shipping' !== $method_id && 'uafrica_shipping' !== $method_id ) {
			return $display_key;
		}

		$labels = [
			'method_description' => __( 'Description', 'bobgo-shipping' ),
			'min_delivery_date'  => __( 'Delivery from', 'bobgo-shipping' ),
			'max_delivery_date'  => __( 'Delivery by', 'bobgo-shipping' ),
		];

		$meta_key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';

		return $labels[ $meta_key ] ?? $display_key;
	}

	/**
	 * Register the new shipping method.
	 *
	 * @param array $methods list of already available methods.
	 *
	 * @return array mixed
	 */
	public static function register_shipping_method( array $methods ): array {

		$methods['bobgo_shipping'] = '\BobGo_Shipping\app\Shipping';
		// Legacy alias so existing shipping zones referencing the pre-v4 method ID continue to resolve
		// while sites are still in the middle of the activation-time DB migration (Activation::migrate_zone_methods).
		$methods['uafrica_shipping'] = '\BobGo_Shipping\app\Shipping';

		return $methods;
	}

	/**
	 * Check the rates, if at least one Bob Go shipping method is available only return those.
	 *
	 * @param array $rates Current rates.
	 *
	 * @return array
	 */
	public static function filter_shipping_methods( array $rates ): array {
		if ( ! isset( $rates['bobgo_shipping:1'] ) ) {
			return $rates; // No Bob Go rate is set, ignore.
		}

		$bobgo_shipping_settings = WooCommerce::get_shipping_settings();

		// Removes non Bob Go rates if hidden is enabled.
		if ( ! empty( $bobgo_shipping_settings['hide'] ) && 'yes' === $bobgo_shipping_settings['hide'] ) {
			foreach ( $rates as $key => $value ) {
				$method_id = explode( ':', $key )[0];
				if ( 'bobgo_shipping' !== $method_id ) {
					unset( $rates[ $key ] );
				}
			}
		}

		return $rates;
	}

	/**
	 * Get a formatted url for API calls.
	 *
	 * @return string
	 */
	public static function get_api_domain() {
		$bobgo_shipping_settings = WooCommerce::get_shipping_settings();
		if ( ! empty( $bobgo_shipping_settings['Site_Address'] ) && 'yes' === $bobgo_shipping_settings['Site_Address'] ) {
			// home_url() refers to Site Address (URL) as configured via Setting->General.
			$domain = wp_parse_url( home_url(), PHP_URL_HOST ) . wp_parse_url( home_url(), PHP_URL_PATH );
		} else {
			// site_url() refers to WordPress Address (URL) as configured via Setting->General.
			$domain = wp_parse_url( site_url(), PHP_URL_HOST );
		}

		/**
		 * Filters the domain.
		 *
		 * @param string $domain the domain to be checked against.
		 *
		 * @since 0.3.0
		 */
		return apply_filters( 'bobgo_shipping_domain', $domain );
	}

	/**
	 * Display admin notice when there are failed sync orders.
	 */
	public static function display_failed_sync_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$order_screens = [ 'edit-shop_order', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		$count = self::get_failed_sync_count();
		if ( $count <= 0 ) {
			return;
		}

		$nonce = wp_create_nonce( 'bobgo_bulk_resync_failed' );
		?>
		<div class="notice notice-warning bobgo-failed-sync-notice">
			<p>
				<?php
				// The count is capped at what one click of the re-sync can drain, so
				// at the cap it is a floor rather than a total — say so instead of
				// stating a figure the store may well exceed.
				if ( $count >= self::FAILED_SYNC_LIMIT ) {
					printf(
						esc_html(
							/* translators: %d: number of failed orders that can be retried at once */
							__( 'At least %d orders failed to sync to Bob Go.', 'bobgo-shipping' )
						),
						(int) $count
					);
				} else {
					printf(
						esc_html(
							/* translators: %d: number of failed orders */
							_n(
								'%d order failed to sync to Bob Go.',
								'%d orders failed to sync to Bob Go.',
								$count,
								'bobgo-shipping'
							)
						),
						(int) $count
					);
				}
				?>
				<a href="#" class="bobgo-bulk-resync-link" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Re-sync all failed orders', 'bobgo-shipping' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Display a persistent admin notice when the Bob Go connection needs attention.
	 *
	 * Surfaces the two states the green Overview used to hide: the stored API key is
	 * failing auth (Phase 3 #1), or webhook subscriptions are not registered so
	 * inbound updates are silently dead (Phase 3 #2). Not dismissible — it clears
	 * itself once the underlying state recovers (a valid key authenticates, or
	 * webhooks re-register, e.g. via the deferred job after reactivation).
	 */
	public static function display_connection_health_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Deliberately excludes the Bob Go settings page: the Overview status items
		// and the inline save message already surface this state there, so a sticky
		// notice would be redundant. This notice's value is on screens the merchant
		// would not otherwise see the problem (Dashboard, Plugins, Orders).
		$allowed = [
			'dashboard',
			'plugins',
			'edit-shop_order',
			'woocommerce_page_wc-orders',
		];
		if ( ! in_array( $screen->id, $allowed, true ) ) {
			return;
		}

		// Only relevant once a key is configured.
		if ( ! Api::is_connected() ) {
			return;
		}

		$connection_url = add_query_arg(
			[
				'page' => SettingsPage::PAGE_SLUG,
				'tab'  => SettingsPage::TAB_CONNECTION,
			],
			admin_url( 'admin.php' )
		);

		$health      = Api::get_connection_health();
		$key_invalid = is_array( $health ) && isset( $health['ok'] ) && false === $health['ok'];

		if ( $key_invalid ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Bob Go: your API key is not working.', 'bobgo-shipping' ); ?></strong>
					<?php esc_html_e( 'Shipping rates, order sync, and tracking will fail until a valid key is saved.', 'bobgo-shipping' ); ?>
					<a href="<?php echo esc_url( $connection_url ); ?>"><?php esc_html_e( 'Update API key', 'bobgo-shipping' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		// Webhooks-not-registered: warn only on an explicit false marker, never on
		// "unknown" (pre-existing installs that have never re-registered).
		if ( false === Webhook::has_registered_subscriptions() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Bob Go: inbound updates are off.', 'bobgo-shipping' ); ?></strong>
					<?php esc_html_e( 'Webhook subscriptions are not registered, so fulfillment and tracking updates from Bob Go will not reach this store.', 'bobgo-shipping' ); ?>
					<a href="<?php echo esc_url( $connection_url ); ?>"><?php esc_html_e( 'Re-register webhooks', 'bobgo-shipping' ); ?></a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Orders the merchant can actually be asked to re-sync.
	 *
	 * Single definition, shared by the notice and the bulk re-sync action. They
	 * used to query independently — a raw COUNT(*) over the meta table with no
	 * status filter, against a wc_get_orders() call that applies one — so an
	 * order the notice counted could be invisible to the action, leaving a
	 * warning that reported work and a button that found none.
	 *
	 * The status allowlist is the same one handle_sync_order() enforces: an
	 * order outside it is skipped by every sync path, so counting it would
	 * advertise a failure nothing can clear.
	 *
	 * Returns ids rather than hydrated orders: the notice only needs a count and
	 * the bulk action only needs something to queue, so loading full WC_Order
	 * objects on every order-list page view would be waste.
	 *
	 * @return int[]
	 */
	private static function get_failed_sync_order_ids(): array {
		$ids = OrderQuery::orders_with_meta(
			[
				'status' => OrderSync::SYNCABLE_STATUSES,
				'limit'  => self::FAILED_SYNC_LIMIT,
				'return' => 'ids',
			],
			[
				[
					'key'   => '_bobgo_sync_status',
					'value' => 'failed',
				],
			]
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Get count of orders with failed sync status (cached).
	 *
	 * Capped at FAILED_SYNC_LIMIT, which is also how many one click of the bulk
	 * action drains — so the figure shown is always "how many you can fix right
	 * now", and a store with more than that drains them a batch at a time.
	 *
	 * Public so the Overview health signals report the same number as the
	 * order-list notice, from the same cached query.
	 *
	 * @return int
	 */
	public static function get_failed_sync_count(): int {
		$cached = get_transient( 'bobgo_failed_sync_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = count( self::get_failed_sync_order_ids() );

		set_transient( 'bobgo_failed_sync_count', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * AJAX handler: bulk re-sync all failed orders.
	 *
	 * Queues the full sync, which resolves whether the order exists on Bob Go
	 * and then creates or updates it accordingly. This used to be a no-op for
	 * any order that already had a link: the handler returned at an
	 * "already synced" guard without a single API call, so a failed order
	 * carrying a link could never be repaired from here and the notice never
	 * cleared. That guard is gone — the sync now pushes the WooCommerce order
	 * as the source of truth either way.
	 */
	public static function handle_bulk_resync_failed(): void {
		check_ajax_referer( 'bobgo_bulk_resync_failed', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		$orders = self::get_failed_sync_order_ids();

		if ( empty( $orders ) ) {
			// The notice is driven by a 5-minute cache; if it disagrees with a
			// live query the cache is what is stale, so drop it.
			delete_transient( 'bobgo_failed_sync_count' );

			wp_send_json_success(
				[
					'message' => __( 'No failed orders found.', 'bobgo-shipping' ),
					'count'   => 0,
				]
			);
		}

		$queued = 0;
		foreach ( $orders as $order_id ) {
			if ( function_exists( 'as_schedule_single_action' )
				&& ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'bobgo_sync_order', [ $order_id ] ) )
			) {
				as_schedule_single_action( time(), 'bobgo_sync_order', [ $order_id ], 'bobgo-shipping' );
				++$queued;
			}
		}

		delete_transient( 'bobgo_failed_sync_count' );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of orders queued */
					__( '%d orders queued for re-sync.', 'bobgo-shipping' ),
					$queued
				),
				'count'   => $queued,
			]
		);
	}
}
