<?php
/**
 * Order detail meta box — displays Bob Go sync status and shipment details.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;

/**
 * Class MetaBox
 *
 * Registers and renders the Bob Go Shipping meta box on order edit screens.
 */
class MetaBox {

	/**
	 * Register the meta box on order screens.
	 *
	 * @param string $post_type The post type (or screen ID for HPOS).
	 * @param mixed  $post      The post/order object.
	 */
	public static function register( string $post_type, $post = null ): void {
		$order = null;

		// HPOS screen.
		if ( 'woocommerce_page_wc-orders' === $post_type ) {
			$order = $post instanceof WC_Order ? $post : ( isset( $_GET['id'] ) ? wc_get_order( absint( $_GET['id'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Legacy CPT screen.
		if ( 'shop_order' === $post_type && $post ) {
			$order = wc_get_order( $post->ID ?? 0 );
		}

		// Only show on orders that have been synced to Bob Go.
		if ( ! $order || empty( $order->get_meta( '_bobgo_order_id' ) ) ) {
			return;
		}

		$screens = [ 'woocommerce_page_wc-orders', 'shop_order' ];
		foreach ( $screens as $screen ) {
			add_meta_box(
				'bobgo-shipping-meta-box',
				__( 'Bob Go Shipping', 'bobgo-shipping' ),
				[ self::class, 'render' ],
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param mixed $post_or_order The WP_Post or WC_Order.
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID ?? 0 );

		if ( ! $order ) {
			return;
		}

		$order_id     = $order->get_id();
		$order_ref    = $order->get_meta( '_bobgo_order_id' );
		$sync_status  = $order->get_meta( '_bobgo_sync_status' );
		$sync_status  = ! empty( $sync_status ) ? $sync_status : 'pending';
		$last_synced  = $order->get_meta( '_bobgo_last_synced' );
		$shipments    = $order->get_meta( '_bobgo_shipments' );
		$shipments    = is_array( $shipments ) ? $shipments : [];
		$last_webhook = $order->get_meta( '_bobgo_last_webhook' );

		$dashboard_url = SettingsPage::get_dashboard_url( 'orders/' . rawurlencode( $order_ref ) );

		wp_nonce_field( 'bobgo_resync_order', 'bobgo_resync_nonce' );
		?>
		<div class="bobgo-metabox">
			<?php // Header. ?>
			<div class="bobgo-metabox-header">
				<div class="bobgo-metabox-ref">
					<strong><?php esc_html_e( 'Order Ref:', 'bobgo-shipping' ); ?></strong>
					<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $order_ref ); ?> &#8599;
					</a>
				</div>
				<div class="bobgo-metabox-sync-status">
					<span class="bobgo-badge bobgo-badge--<?php echo esc_attr( $sync_status ); ?>">
						<?php echo esc_html( ucfirst( $sync_status ) ); ?>
					</span>
					<?php
					// Fulfillment status badge.
					if ( ! empty( $shipments ) ) {
						if ( Webhook::all_shipments_delivered( $shipments ) ) {
							$fulfillment_badge_class = 'success';
							$fulfillment_badge_label = __( 'Delivered', 'bobgo-shipping' );
						} else {
							$fulfillment_state = Webhook::determine_fulfillment_status( $order, $shipments );
							if ( 'shipped' === $fulfillment_state ) {
								$fulfillment_badge_class = 'info';
								$fulfillment_badge_label = __( 'Shipped', 'bobgo-shipping' );
							} elseif ( 'partially-shipped' === $fulfillment_state ) {
								$fulfillment_badge_class = 'warning';
								$fulfillment_badge_label = __( 'Partially Shipped', 'bobgo-shipping' );
							} else {
								$fulfillment_badge_class = 'neutral';
								$fulfillment_badge_label = __( 'Fulfilling', 'bobgo-shipping' );
							}
						}
						?>
						<span class="bobgo-badge bobgo-badge--<?php echo esc_attr( $fulfillment_badge_class ); ?>">
							<?php echo esc_html( $fulfillment_badge_label ); ?>
						</span>
						<?php
					}
					?>
				</div>
				<?php if ( $last_synced ) : ?>
					<div class="bobgo-metabox-last-synced">
						<?php
						printf(
							/* translators: %s: relative time */
							esc_html__( 'Synced: %s', 'bobgo-shipping' ),
							esc_html( self::time_ago( $last_synced ) )
						);
						?>
					</div>
				<?php endif; ?>
				<div class="bobgo-metabox-actions">
					<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
						<?php esc_html_e( 'View in Bob Go', 'bobgo-shipping' ); ?>
					</a>
					<button type="button" class="button button-small bobgo-resync-btn" data-order-id="<?php echo esc_attr( (string) $order_id ); ?>">
						&#8635; <?php esc_html_e( 'Re-sync', 'bobgo-shipping' ); ?>
					</button>
				</div>
			</div>

			<?php
			// Live fetch: if no shipments stored, try fetching fulfillments now.
			// Throttle via a short-lived per-order transient so a slow or down Bob
			// Go API cannot be re-hit (and block render) on every order-screen
			// load; the manual "Re-sync" button above remains for an immediate retry.
			if ( empty( $shipments ) && Api::is_connected() && ! empty( $order_ref ) ) {
				$fetch_throttle_key = 'bobgo_metabox_fetch_' . $order_id;
				if ( false === get_transient( $fetch_throttle_key ) ) {
					set_transient( $fetch_throttle_key, 1, 5 * MINUTE_IN_SECONDS );
					$shipments = self::fetch_and_save_fulfillments( $order, $order_ref );
				}
			}
			?>

			<?php // Shipment groups. ?>
			<?php if ( ! empty( $shipments ) ) : ?>
				<?php foreach ( $shipments as $index => $shipment ) : ?>
					<?php self::render_shipment( $shipment, $index ); ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php // Unfulfilled items. ?>
			<?php self::render_unfulfilled( $order, $shipments ); ?>

			<?php // Footer. ?>
			<?php if ( $last_webhook ) : ?>
				<div class="bobgo-metabox-footer">
					<?php
					printf(
						/* translators: %s: relative time */
						esc_html__( 'Last webhook: %s', 'bobgo-shipping' ),
						esc_html( self::time_ago( $last_webhook ) )
					);
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single shipment group.
	 *
	 * @param array<string, mixed> $shipment  Shipment data.
	 * @param int                  $index     Shipment index.
	 */
	private static function render_shipment( array $shipment, int $index ): void {
		$courier            = $shipment['courier'] ?? __( 'Unknown courier', 'bobgo-shipping' );
		$tracking           = $shipment['tracking_number'] ?? '';
		$tracking_url       = $shipment['tracking_url'] ?? '';
		$status             = $shipment['status'] ?? 'created';
		$submission_status  = $shipment['submission_status'] ?? '';
		$service_level      = $shipment['service_level'] ?? '';
		$collection_type    = $shipment['collection_location_type'] ?? '';
		$delivery_type      = $shipment['delivery_location_type'] ?? '';
		$location_pair      = self::format_location_pair( $collection_type, $delivery_type );
		$fulfilled_at       = $shipment['fulfilled_at'] ?? '';
		$estimated_delivery = $shipment['estimated_delivery'] ?? '';
		$parcels            = $shipment['parcels'] ?? [];
		$events             = $shipment['tracking_events'] ?? [];
		$status_class       = self::get_status_class( $status );
		?>
		<div class="bobgo-shipment">
			<div class="bobgo-shipment-header">
				<?php if ( ! empty( $tracking ) ) : ?>
					<span class="bobgo-shipment-tracking">
						<?php if ( ! empty( $tracking_url ) ) : ?>
							<a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $tracking ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $tracking ); ?>
						<?php endif; ?>
					</span>
				<?php endif; ?>
				<span class="bobgo-shipment-badges">
					<span class="bobgo-badge bobgo-badge--<?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $status ) ) ); ?>
					</span>
					<?php
					// Only surface submission status when it's an exception worth flagging
					// (failed/error). A "success" submission is implied by the shipment
					// progressing through its lifecycle and would just add visual noise.
					if ( ! empty( $submission_status ) && 'success' !== $submission_status ) :
						?>
						<span class="bobgo-badge bobgo-badge--<?php echo esc_attr( self::get_status_class( $submission_status ) ); ?>">
							<?php echo esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $submission_status ) ) ); ?>
						</span>
					<?php endif; ?>
				</span>
			</div>
			<div class="bobgo-shipment-meta">
				<?php echo esc_html( $courier ); ?>
				<?php if ( ! empty( $service_level ) ) : ?>
					&mdash; <?php echo esc_html( $service_level ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $location_pair ) ) : ?>
					&nbsp;&middot; <?php echo esc_html( $location_pair ); ?>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $fulfilled_at ) || ! empty( $estimated_delivery ) || count( $parcels ) > 1 ) : ?>
				<div class="bobgo-shipment-extra">
					<?php if ( ! empty( $fulfilled_at ) ) : ?>
						<span>
							<?php
							$formatted_fulfilled = wp_date( 'j M Y', strtotime( $fulfilled_at ) );
							printf(
								/* translators: %s: fulfillment created date */
								esc_html__( 'Fulfilled: %s', 'bobgo-shipping' ),
								esc_html( ! empty( $formatted_fulfilled ) ? $formatted_fulfilled : $fulfilled_at )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $estimated_delivery ) ) : ?>
						<span>
							<?php
							$formatted_delivery = wp_date( 'j M Y', strtotime( $estimated_delivery ) );
							printf(
								/* translators: %s: estimated delivery date */
								esc_html__( 'Est. delivery: %s', 'bobgo-shipping' ),
								esc_html( ! empty( $formatted_delivery ) ? $formatted_delivery : $estimated_delivery )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( count( $parcels ) > 1 ) : ?>
						<span>
							<?php
							printf(
								/* translators: %d: number of parcels */
								esc_html( _n( '%d parcel', '%d parcels', count( $parcels ), 'bobgo-shipping' ) ),
								count( $parcels )
							);
							?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php // Items. ?>
			<?php if ( ! empty( $shipment['items'] ) && is_array( $shipment['items'] ) ) : ?>
				<div class="bobgo-shipment-items">
					<?php foreach ( $shipment['items'] as $item ) : ?>
						<?php
						$qty        = $item['quantity'] ?? 0;
						$wc_item_id = $item['wc_item_id'] ?? 0;
						$display    = self::get_item_display_data( $wc_item_id );
						$item_name  = ! empty( $item['name'] ) ? $item['name'] : $display['name'];
						?>
						<div class="bobgo-shipment-item">
							<?php
							if ( ! empty( $display['thumbnail'] ) ) {
								echo '<span class="bobgo-item-thumb">' . wp_kses_post( $display['thumbnail'] ) . '</span>';
							}
							?>
							<span class="bobgo-item-text"><?php echo esc_html( "{$qty}x {$item_name}" ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php // Tracking timeline. ?>
			<?php if ( ! empty( $events ) ) : ?>
				<div class="bobgo-timeline-toggle" data-index="<?php echo esc_attr( (string) $index ); ?>">
					&#9654; <?php esc_html_e( 'Tracking Timeline', 'bobgo-shipping' ); ?>
				</div>
				<div class="bobgo-timeline" id="bobgo-timeline-<?php echo esc_attr( (string) $index ); ?>" style="display:none;">
					<?php foreach ( $events as $event ) : ?>
						<?php
						$ts       = $event['timestamp'] ?? '';
						$desc     = $event['message'] ?? $event['description'] ?? $event['status'] ?? '';
						$location = $event['location'] ?? '';
						$time     = ! empty( $ts ) ? wp_date( 'j M H:i', strtotime( $ts ) ) : '';
						?>
						<div class="bobgo-timeline-event">
							<span class="bobgo-timeline-dot bobgo-timeline-dot--filled"></span>
							<span class="bobgo-timeline-text">
								<?php echo esc_html( $desc ); ?>
								<?php if ( ! empty( $location ) ) : ?>
									<span class="bobgo-timeline-location">(<?php echo esc_html( $location ); ?>)</span>
								<?php endif; ?>
								<?php if ( $time ) : ?>
									<span class="bobgo-timeline-time">&mdash; <?php echo esc_html( $time ); ?></span>
								<?php endif; ?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render unfulfilled items section.
	 *
	 * Calculates remaining quantities from item-level shipment data (cancelled and
	 * failed shipments excluded, so their items show as unfulfilled again). When no
	 * shipment records exist at all, falls back to Bob Go's order-level
	 * fulfillment_status.
	 *
	 * @param WC_Order                         $order     The order.
	 * @param array<int, array<string, mixed>> $shipments Shipments data.
	 */
	private static function render_unfulfilled( WC_Order $order, array $shipments ): void {
		// Item-level fulfilled quantities (cancelled/failed shipments excluded).
		$fulfilled_qty  = Webhook::calculate_fulfilled_qty( $shipments );
		$has_item_level = ! empty( $fulfilled_qty );

		// Active shipments exist but their items couldn't be mapped to WC line
		// items — trust their presence and show nothing as unfulfilled.
		if ( ! $has_item_level && Webhook::has_active_shipment( $shipments ) ) {
			return;
		}

		// No shipment records at all — defer to Bob Go's order-level fulfillment
		// status. When only cancelled/failed shipments exist we fall through here,
		// so every line item is listed as unfulfilled below.
		if ( empty( $shipments ) ) {
			$fulfillment_status = $order->get_meta( '_bobgo_fulfillment_status' );
			if ( 'fulfilled' === $fulfillment_status ) {
				return;
			}
			if ( 'partial' === $fulfillment_status ) {
				?>
				<div class="bobgo-unfulfilled">
					<strong><?php esc_html_e( 'Partially Fulfilled', 'bobgo-shipping' ); ?></strong>
				</div>
				<?php
				return;
			}
		}

		// Calculate remaining quantity per line item. With no fulfilled quantities
		// (e.g. the only shipment was cancelled) every item shows as unfulfilled.
		$unfulfilled = [];
		foreach ( $order->get_items() as $item ) {
			$item_id   = $item->get_id();
			$total_qty = $item->get_quantity();
			$fulfilled = $fulfilled_qty[ $item_id ] ?? 0;
			$remaining = $total_qty - $fulfilled;

			if ( $remaining > 0 ) {
				$product       = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
				$unfulfilled[] = [
					'name'      => $item->get_name(),
					'quantity'  => $remaining,
					'thumbnail' => $product ? $product->get_image( [ 32, 32 ] ) : '',
				];
			}
		}

		if ( empty( $unfulfilled ) ) {
			return;
		}
		self::render_unfulfilled_list( $unfulfilled );
	}

	/**
	 * Render the unfulfilled items list HTML.
	 *
	 * @param array<int, array{name: string, quantity: int, thumbnail: string}> $unfulfilled Items to render.
	 */
	private static function render_unfulfilled_list( array $unfulfilled ): void {
		?>
		<div class="bobgo-unfulfilled">
			<strong><?php esc_html_e( 'Unfulfilled', 'bobgo-shipping' ); ?></strong>
			<?php foreach ( $unfulfilled as $item ) : ?>
				<div class="bobgo-unfulfilled-item">
					<?php
					if ( ! empty( $item['thumbnail'] ) ) {
						echo '<span class="bobgo-item-thumb">' . wp_kses_post( $item['thumbnail'] ) . '</span>';
					}
					?>
					<span class="bobgo-item-text"><?php echo esc_html( $item['quantity'] . 'x ' . $item['name'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle re-sync AJAX request.
	 *
	 * Runs the same sync the automatic paths run — resolve which Bob Go order
	 * represents this one, push the store's current state onto it (creating it if
	 * none exists), then pull shipment and tracking state back. It used to hold
	 * its own partial version of that: a pull only, which still stamped the order
	 * 'synced', so re-syncing an order whose outbound push had failed cleared the
	 * failure marker without ever sending the order and reported success.
	 */
	public static function handle_resync_ajax(): void {
		check_ajax_referer( 'bobgo_resync_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'bobgo-shipping' ) ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'bobgo-shipping' ) ] );
		}

		if ( ! Api::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Not connected to Bob Go. Add your API key on the Bob Go settings page.', 'bobgo-shipping' ) ] );
		}

		// Caught here rather than left to the sync, which declines these silently:
		// the merchant clicked a button and is owed the reason.
		if ( ! OrderSync::should_sync( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'This order cannot be sent to Bob Go: it has no items, or it is not a standard order.', 'bobgo-shipping' ) ] );
		}

		$status = $order->get_status();
		if ( ! in_array( $status, OrderSync::SYNCABLE_STATUSES, true ) ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: WooCommerce order status, e.g. "cancelled" */
						__( 'Orders with the status "%s" are not sent to Bob Go.', 'bobgo-shipping' ),
						$status
					),
				]
			);
		}

		// The sync reports its own outcome. Reading _bobgo_sync_status back instead
		// would misreport a deferral, which leaves the status untouched: a
		// previously-synced order would claim success for a push that never left.
		$outcome = OrderSync::sync_order( $order_id );

		// Re-read: the sync wrote meta through its own order instance.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'bobgo-shipping' ) ] );
		}

		if ( 'synced' === $outcome ) {
			$order->add_order_note( __( 'Order manually re-synced with Bob Go.', 'bobgo-shipping' ) );

			wp_send_json_success( [ 'message' => __( 'Order re-synced successfully.', 'bobgo-shipping' ) ] );
		}

		[ $note, $message ] = match ( $outcome ) {
			'deferred' => [
				__( 'Manual re-sync could not reach Bob Go; it is queued to retry automatically.', 'bobgo-shipping' ),
				__( 'Bob Go could not be reached. The re-sync is queued and will retry automatically.', 'bobgo-shipping' ),
			],
			'skipped'  => [
				__( 'Manual re-sync was declined: this order is not eligible to be sent to Bob Go.', 'bobgo-shipping' ),
				__( 'This order is not eligible to be sent to Bob Go.', 'bobgo-shipping' ),
			],
			default    => [
				__( 'Order was manually re-synced with Bob Go, but sending it failed.', 'bobgo-shipping' ),
				__( 'Sending this order to Bob Go failed. See the sync log for details.', 'bobgo-shipping' ),
			],
		};

		$order->add_order_note( $note );

		wp_send_json_error( [ 'message' => $message ] );
	}

	/**
	 * Fetch fulfillments from Bob Go and save as shipments on the order.
	 *
	 * Delegates to Webhook::refresh_fulfillment_data() to avoid duplicating
	 * the fulfillment parsing logic.
	 *
	 * @param WC_Order $order     The WooCommerce order.
	 * @param string   $order_ref The Bob Go order reference/ID.
	 * @return array<int, array<string, mixed>> Parsed shipments array.
	 */
	private static function fetch_and_save_fulfillments( WC_Order $order, string $order_ref ): array {
		$result = Webhook::refresh_fulfillment_data( $order, $order_ref );

		if ( ! $result ) {
			return [];
		}

		$shipments = $order->get_meta( '_bobgo_shipments' );
		$shipments = is_array( $shipments ) ? $shipments : [];

		// Log to the sync log so this pull is visible alongside real webhook
		// deliveries. http_status is null because this is an outbound pull, not
		// an inbound HTTP webhook. The event type is this pull's own — real
		// fulfillment webhooks log as fulfillment_created — because the Overview
		// excludes this one from "Updates From Bob Go" (Health::INBOUND_INTERNAL_EVENTS):
		// opening an order panel must not read as Bob Go reaching this store.
		SyncLog::insert(
			[
				'wc_order_id' => $order->get_id(),
				'event_type'  => 'fulfillment_received',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'source'         => 'metabox_live_fetch',
						'order_ref'      => $order_ref,
						'shipment_count' => count( $shipments ),
					]
				),
				'http_status' => null,
				'success'     => true,
			]
		);

		return $shipments;
	}

	/**
	 * Get a human-readable time ago string.
	 *
	 * @param string $datetime ISO datetime string.
	 * @return string
	 */
	private static function time_ago( string $datetime ): string {
		$timestamp = strtotime( $datetime );
		if ( false === $timestamp ) {
			return $datetime;
		}

		return human_time_diff( $timestamp, time() ) . ' ' . __( 'ago', 'bobgo-shipping' );
	}

	/**
	 * Get both name and thumbnail for an order item in a single fetch.
	 *
	 * @param int $wc_item_id The WooCommerce order item ID.
	 * @return array{name: string, thumbnail: string}
	 */
	private static function get_item_display_data( int $wc_item_id ): array {
		$default = [
			'name'      => __( 'Unknown item', 'bobgo-shipping' ),
			'thumbnail' => '',
		];

		if ( $wc_item_id <= 0 ) {
			return $default;
		}

		$item = \WC_Order_Factory::get_order_item( $wc_item_id );
		if ( ! $item ) {
			$default['name'] = sprintf(
				/* translators: %d: item ID */
				__( 'Item #%d', 'bobgo-shipping' ),
				$wc_item_id
			);
			return $default;
		}

		$name      = method_exists( $item, 'get_name' ) ? $item->get_name() : $default['name'];
		$thumbnail = '';

		if ( method_exists( $item, 'get_product' ) ) {
			$product = $item->get_product();
			if ( $product ) {
				$thumbnail = $product->get_image( [ 32, 32 ] );
			}
		}

		return [
			'name'      => $name,
			'thumbnail' => $thumbnail,
		];
	}

	/**
	 * Format the collection/delivery location-type pair for display.
	 *
	 * Returns a humanised string like "Door to Locker" when both are set,
	 * or a labelled single value (e.g. "Delivery: Locker") when only one is.
	 *
	 * @param string $collection Collection location type (e.g. "door").
	 * @param string $delivery   Delivery location type (e.g. "locker").
	 * @return string Display string, or empty when neither is set.
	 */
	private static function format_location_pair( string $collection, string $delivery ): string {
		$collection = self::humanize_location_type( $collection );
		$delivery   = self::humanize_location_type( $delivery );

		if ( '' !== $collection && '' !== $delivery ) {
			return sprintf(
				/* translators: 1: collection location type, 2: delivery location type */
				__( '%1$s to %2$s', 'bobgo-shipping' ),
				$collection,
				$delivery
			);
		}

		if ( '' !== $delivery ) {
			return sprintf(
				/* translators: %s: delivery location type */
				__( 'Delivery: %s', 'bobgo-shipping' ),
				$delivery
			);
		}

		if ( '' !== $collection ) {
			return sprintf(
				/* translators: %s: collection location type */
				__( 'Collection: %s', 'bobgo-shipping' ),
				$collection
			);
		}

		return '';
	}

	/**
	 * Humanise a snake_case location type (e.g. "door_to_locker" → "Door To Locker").
	 *
	 * @param string $type Raw location type from the API.
	 * @return string
	 */
	private static function humanize_location_type( string $type ): string {
		$type = trim( $type );
		if ( '' === $type ) {
			return '';
		}
		return ucwords( str_replace( [ '_', '-' ], ' ', $type ) );
	}

	/**
	 * Map a shipment status to a CSS class.
	 *
	 * @param string $status The shipment status.
	 * @return string CSS class suffix.
	 */
	private static function get_status_class( string $status ): string {
		$map = [
			'delivered'          => 'success',
			'in_transit'         => 'info',
			'in-transit'         => 'info',
			'out-for-delivery'   => 'info',
			'out_for_delivery'   => 'info',
			'collected'          => 'warning',
			'pending-collection' => 'warning',
			'pending_collection' => 'warning',
			'submitted'          => 'warning',
			'pending'            => 'warning',
			'created'            => 'neutral',
			'failed'             => 'error',
			'cancelled'          => 'error',
		];

		return $map[ $status ] ?? 'neutral';
	}
}
