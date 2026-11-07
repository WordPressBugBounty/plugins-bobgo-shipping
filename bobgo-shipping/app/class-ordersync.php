<?php
/**
 * Outbound order sync engine — pushes orders to Bob Go via Action Scheduler.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;

/**
 * Class OrderSync
 *
 * Handles outbound order creation, update, and status change sync to Bob Go.
 */
class OrderSync {

	use Loggable;

	/**
	 * Order statuses considered valid for syncing (payment confirmed).
	 */
	const SYNCABLE_STATUSES = [
		'processing',
		'on-hold',
		'completed',
		'shipped',
		'partially-shipped',
	];

	/**
	 * Order meta counting how many times the pre-push lookup has failed on
	 * transport for this order. Cleared as soon as a lookup answers.
	 */
	const META_RESOLVE_ATTEMPTS = '_bobgo_resolve_attempts';

	/**
	 * How many times to retry a pre-push lookup that could not reach Bob Go
	 * before leaving the order failed for the admin notice to surface.
	 */
	const RESOLVE_MAX_ATTEMPTS = 3;

	/**
	 * Seconds between those retries.
	 */
	const RESOLVE_RETRY_DELAY = 300;

	/**
	 * Maximum number of times a pending/checkout-draft order may be re-polled
	 * before we give up and rely on woocommerce_order_status_changed instead.
	 * 60 attempts × 60s ≈ 1 hour of polling.
	 */
	const PENDING_SYNC_MAX_ATTEMPTS = 60;

	/**
	 * Order meta holding a fingerprint of the item list Bob Go last accepted.
	 * What lets an update tell a genuine item change from an unchanged list.
	 */
	const META_ITEMS_HASH = '_bobgo_items_hash';

	/**
	 * Action Scheduler hook draining the one-shot item-fingerprint backfill.
	 */
	const HOOK_ITEMS_BACKFILL = 'bobgo_backfill_items_fingerprints';

	/**
	 * Option flag recording that the backfill has stamped every linked order.
	 */
	const OPTION_ITEMS_BACKFILLED = 'bobgo_items_fingerprint_backfilled';

	/**
	 * Order meta holding the payload Bob Go last refused outright (JSON:
	 * fingerprint, http status, time). Its presence is what stops the same
	 * rejected request being re-sent on every later trigger.
	 */
	const META_PUSH_REJECTED = '_bobgo_push_rejected';

	/**
	 * HTTP statuses that mean Bob Go read the payload and refused it, so
	 * re-sending the identical payload can only fail again.
	 *
	 * Deliberately excludes 401/403 (credentials — fixed elsewhere and then
	 * every order should retry), 404 (a stale link, which the full sync
	 * re-resolves) and 429 (rate limiting, which is transient).
	 */
	const REJECTED_STATUSES = [ 400, 409, 422 ];

	/**
	 * Statuses that trigger an outbound status update to Bob Go.
	 *
	 * The gate on the whole path: a status missing from this list never reaches
	 * resolve_remote_status(), so the two are widened together or not at all.
	 */
	const STATUS_SYNC_TRIGGERS = [
		'completed',
		'cancelled',
		'processing',
	];

	/**
	 * Maximum number of display options forwarded per order item.
	 */
	const DISPLAY_OPTIONS_MAX_ENTRIES = 30;

	/**
	 * Maximum length (characters) of a forwarded display option field.
	 */
	const DISPLAY_OPTIONS_MAX_LENGTH = 500;

	/**
	 * Hook handler: order created at checkout.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function on_order_created( int $order_id ): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::should_sync( $order ) ) {
			return;
		}

		// Guard: don't re-sync already synced orders.
		$existing_ref = $order->get_meta( '_bobgo_order_id' );
		if ( ! empty( $existing_ref ) ) {
			return;
		}

		// Always defer to Action Scheduler so Bob Go API latency cannot block
		// checkout or admin requests. Pending/checkout-draft orders wait 60s
		// for payment confirmation; ready orders run as soon as the queue is
		// processed.
		$status = $order->get_status();
		$delay  = ( 'pending' === $status || 'checkout-draft' === $status ) ? 60 : 0;

		self::schedule_sync_order( $order_id, $delay );
	}

	/**
	 * Hook handler: order created via block checkout.
	 *
	 * The block checkout hook passes a WC_Order object instead of an order ID.
	 *
	 * @param WC_Order $order The order object.
	 */
	public static function on_order_created_from_block( WC_Order $order ): void {
		self::on_order_created( $order->get_id() );
	}

	/**
	 * Hook handler: order updated.
	 *
	 * @param int           $order_id The order ID.
	 * @param WC_Order|null $order    The order object.
	 */
	public static function on_order_updated( int $order_id, $order = null ): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! self::should_sync( $order ) ) {
			return;
		}

		// Only sync orders that have been synced before.
		$sync_status = $order->get_meta( '_bobgo_sync_status' );
		if ( empty( $sync_status ) || 'pending' === $sync_status ) {
			return;
		}

		// Check dirty state.
		$current_hash = self::compute_sync_hash( $order );
		$stored_hash  = $order->get_meta( '_bobgo_sync_hash' );

		if ( $current_hash === $stored_hash ) {
			return;
		}

		// Prevent duplicate scheduling.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bobgo_update_order', [ $order_id ] ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'bobgo_update_order', [ $order_id ], 'bobgo-shipping' );
		}
	}

	/**
	 * Hook handler: order status changed.
	 *
	 * @param int      $order_id  The order ID.
	 * @param string   $old_status Old status (without wc- prefix).
	 * @param string   $new_status New status (without wc- prefix).
	 * @param WC_Order $order     The order object.
	 */
	public static function on_order_status_changed( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		// Echo loop guard: skip if this change was triggered by an inbound webhook.
		if ( self::is_webhook_triggered( $order_id ) ) {
			return;
		}

		$order_ref = $order->get_meta( '_bobgo_order_id' );

		// If the order hasn't been synced yet and is now in a syncable status,
		// trigger the initial sync. This catches orders that were deferred
		// (pending/checkout-draft at checkout) but whose Action Scheduler job
		// hasn't run yet.
		if ( empty( $order_ref ) && in_array( $new_status, self::SYNCABLE_STATUSES, true ) ) {
			self::schedule_sync_order( $order_id, 0 );
			return;
		}

		// For already-synced orders, only act on specific status transitions.
		if ( empty( $order_ref ) || ! in_array( $new_status, self::STATUS_SYNC_TRIGGERS, true ) ) {
			return;
		}

		// Prevent duplicate scheduling.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bobgo_update_order_status', [ $order_id, $new_status ] ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'bobgo_update_order_status', [ $order_id, $new_status ], 'bobgo-shipping' );
		}
	}

	/**
	 * Schedule the initial-sync Action Scheduler job for an order.
	 *
	 * Deduplicates against any already-scheduled job for the same order.
	 *
	 * @param int $order_id The order ID.
	 * @param int $delay    Seconds to wait before running.
	 */
	private static function schedule_sync_order( int $order_id, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bobgo_sync_order', [ $order_id ] ) ) {
			return;
		}
		as_schedule_single_action( time() + max( 0, $delay ), 'bobgo_sync_order', [ $order_id ], 'bobgo-shipping' );
	}

	/**
	 * Action Scheduler callback: sync an order to Bob Go.
	 *
	 * Nothing is listening to a scheduled job's outcome, so this discards it.
	 * Callers that need to report back to someone — the manual re-sync button —
	 * call sync_order() directly.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public static function handle_sync_order( int $order_id ): void {
		self::sync_order( $order_id );
	}

	/**
	 * Sync an order to Bob Go and report what happened.
	 *
	 * The outcome is returned because `_bobgo_sync_status` cannot answer for it:
	 * the sync leaves the status untouched whenever it declines or defers, so a
	 * caller reading it back sees whatever the previous sync left behind. That is
	 * how the manual re-sync button came to report success for an order it had
	 * not sent.
	 *
	 * @param int $order_id The order ID.
	 * @return string synced | failed | deferred (queued to retry) | skipped (not eligible).
	 */
	public static function sync_order( int $order_id ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::log( "Sync skipped: order {$order_id} not found.", 'warning' );
			return 'skipped';
		}

		if ( ! self::should_sync( $order ) ) {
			return 'skipped';
		}

		// If order is still pending (payment not confirmed), reschedule —
		// but only up to PENDING_SYNC_MAX_ATTEMPTS, after which we stop
		// polling and rely on woocommerce_order_status_changed to pick it up.
		$status = $order->get_status();
		if ( 'pending' === $status || 'checkout-draft' === $status ) {
			$attempts = (int) $order->get_meta( '_bobgo_pending_sync_attempts' );
			if ( $attempts >= self::PENDING_SYNC_MAX_ATTEMPTS ) {
				self::log( "Order {$order_id} still pending after {$attempts} attempts; giving up. Will sync when status changes.", 'info' );
				return 'skipped';
			}

			$order->update_meta_data( '_bobgo_pending_sync_attempts', (string) ( $attempts + 1 ) );
			$order->save();

			$max = self::PENDING_SYNC_MAX_ATTEMPTS;
			self::log( "Order {$order_id} still pending, rescheduling sync in 60s (attempt {$attempts}/{$max}).", 'info' );
			self::schedule_sync_order( $order_id, 60 );
			return 'deferred';
		}

		// Order is no longer pending — clear the attempt counter.
		if ( $order->get_meta( '_bobgo_pending_sync_attempts' ) ) {
			$order->delete_meta_data( '_bobgo_pending_sync_attempts' );
			$order->save();
		}

		// Status allowlist: never POST an order whose status is not one we
		// consider syncable (e.g. failed/cancelled/refunded). The deferred
		// queue can run after the order has already moved on.
		if ( ! in_array( $status, self::SYNCABLE_STATUSES, true ) ) {
			self::log( "Order {$order_id} sync skipped: status '{$status}' is not syncable.", 'info' );
			return 'skipped';
		}

		// Resolve first, then push. The WooCommerce order is the source of truth
		// for a sync: whichever Bob Go order represents it gets our current state,
		// and one is only created when none exists.
		$resolved = self::resolve_bobgo_order( $order );

		if ( 'inconclusive' === $resolved['status'] ) {
			// The match carries no channel_ref_id (so it may be another store's
			// order on this account), several orders claim the number, or the
			// lookup itself failed. Creating risks a duplicate and adopting risks
			// the wrong order, so hold either way.
			//
			// A lookup that failed on transport, though, says nothing about the
			// order — a few seconds of Bob Go being unreachable would otherwise
			// strand a new order as 'failed' with nothing to retry it, since
			// Action Scheduler does not re-run a job that returned normally.
			// Retry a bounded number of times before leaving it for the admin
			// notice.
			$attempts = (int) $order->get_meta( self::META_RESOLVE_ATTEMPTS );
			$retry    = 'inconclusive_transport' === $resolved['reason'] && $attempts < self::RESOLVE_MAX_ATTEMPTS;

			if ( $retry ) {
				$order->update_meta_data( self::META_RESOLVE_ATTEMPTS, (string) ( $attempts + 1 ) );
				$order->save();

				$next = $attempts + 1;
				$max  = self::RESOLVE_MAX_ATTEMPTS;
				// Not "could not reach Bob Go": this covers a 2xx whose body was
				// unreadable too (a proxy or maintenance page answering 200 with
				// HTML), which is just as worth retrying and just as inconclusive.
				self::log( "Order {$order_id} sync deferred: no usable answer from Bob Go on whether the order already exists (attempt {$next}/{$max}); retrying in " . self::RESOLVE_RETRY_DELAY . 's.', 'warning' );
				self::schedule_sync_order( $order_id, self::RESOLVE_RETRY_DELAY );
				return 'deferred';
			}

			// Log the hold where support and the merchant look. The push helpers
			// record their own request/response rows, but a hold never reaches one,
			// so this was the single failure mode that marked an order failed while
			// leaving the sync log — which the re-sync button tells the merchant to
			// check — with nothing at all to show for the attempt.
			SyncLog::insert(
				[
					'wc_order_id' => $order_id,
					'event_type'  => 'order_sync_held',
					'direction'   => 'outbound',
					'payload'     => wp_json_encode(
						[
							'reason'       => $resolved['reason'],
							'order_number' => $order->get_order_number(),
							'attempts'     => $attempts,
						]
					),
					'http_status' => 0,
					'success'     => false,
				]
			);

			self::mark_push_failed( $order, "Order {$order_id} sync held: the existing-order lookup was inconclusive ({$resolved['reason']}); refusing to create a possible duplicate." );
			return 'failed';
		}

		// Past the lookup — reset the retry budget so a later transient failure
		// gets its own attempts.
		if ( '' !== (string) $order->get_meta( self::META_RESOLVE_ATTEMPTS ) ) {
			$order->delete_meta_data( self::META_RESOLVE_ATTEMPTS );
			$order->save();
		}

		if ( 'found' === $resolved['status'] ) {
			$bobgo_id = $resolved['id'];

			// Adopting an order found by number: record the link before pushing,
			// so a failed PATCH still leaves the order linked rather than looking
			// unsynced and being created next time round.
			if ( (string) $bobgo_id !== (string) $order->get_meta( '_bobgo_order_id' ) ) {
				$order->update_meta_data( '_bobgo_order_id', (string) $bobgo_id );
				// Recorded here rather than left to the push below, which only
				// stamps when Bob Go accepts it: a refused adoption would
				// otherwise leave the order linked with no record of its items.
				self::ensure_items_fingerprint( $order );
				$order->save();

				SyncLog::insert(
					[
						'wc_order_id' => $order_id,
						'event_type'  => 'order_linked',
						'direction'   => 'outbound',
						'payload'     => wp_json_encode( [ 'bobgo_order_id' => $bobgo_id ] ),
						'http_status' => 200,
						'success'     => true,
					]
				);

				self::log( "Order {$order_id} already exists on Bob Go as {$bobgo_id}; linked instead of created.", 'info' );
			}

			$pushed = self::push_order_update( $order, $bobgo_id );
		} else {
			$pushed   = self::create_order( $order );
			$bobgo_id = (int) $order->get_meta( '_bobgo_order_id' );
		}

		if ( ! $pushed || $bobgo_id <= 0 ) {
			return 'failed';
		}

		// Bring shipment and tracking state back. Bob Go's rule engine can fulfil
		// on order creation, so this matters on the create path too.
		Webhook::refresh_fulfillment_data( $order, (string) $bobgo_id );

		return 'synced';
	}

	/**
	 * Work out which Bob Go order, if any, represents this WooCommerce order.
	 *
	 * A stored link is trusted only as far as it still resolves: an id left over
	 * from a previous account (or an order deleted on Bob Go) would otherwise
	 * make every PATCH 404 forever. When it does not resolve the link is dropped
	 * and the search falls back to the order number, the same lookup an unlinked
	 * order uses.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{status: string, id: int, reason: string} status: found | none | inconclusive.
	 */
	private static function resolve_bobgo_order( \WC_Order $order ): array {
		$stored = (string) $order->get_meta( '_bobgo_order_id' );

		if ( '' !== $stored && is_numeric( $stored ) ) {
			$result = Api::get( 'v2/orders?id=' . rawurlencode( $stored ) );

			if ( $result['success'] && is_array( $result['body'] ) ) {
				// Normally { "orders": [ … ] }, but accept a bare order object too —
				// the code this replaced handled both, and reading an unexpected
				// shape as "the link is gone" would delete a valid link.
				$body    = $result['body'];
				$matches = $body['orders'] ?? ( isset( $body['id'] ) ? [ $body ] : [] );

				if ( is_array( $matches ) && ! empty( $matches[0]['id'] ) ) {
					// The by-id route is account-scoped, not channel-scoped, so
					// "it exists" is not "it is ours". A channel_ref_id naming a
					// different order is positive evidence of a wrong link (the
					// order-id collision class LinkAudit exists for) and must not
					// be PATCHed over. An absent channel_ref_id is no evidence
					// either way and is left alone.
					$channel_ref = trim( (string) ( $matches[0]['channel_ref_id'] ?? '' ) );

					if ( '' !== $channel_ref && $channel_ref !== (string) $order->get_id() ) {
						self::drop_stale_link( $order, "Order {$order->get_id()} carries Bob Go link {$stored}, but that order belongs to channel ref {$channel_ref}; dropping the link rather than overwriting another order." );
					} else {
						// Bob Go's own fulfilment state comes back on this body, and
						// nothing else in the sync reads it — take it while it's free.
						$fulfillment_status = $matches[0]['fulfillment_status'] ?? '';
						if ( ! empty( $fulfillment_status ) ) {
							$order->update_meta_data( '_bobgo_fulfillment_status', sanitize_text_field( (string) $fulfillment_status ) );
							$order->save();
						}

						return [
							'status' => 'found',
							'id'     => (int) $matches[0]['id'],
							'reason' => 'stored link resolved',
						];
					}
				} else {
					// A 2xx with no order means the stored id is gone.
					self::drop_stale_link( $order, "Order {$order->get_id()} carries Bob Go link {$stored}, which no longer exists on this account; dropping it and searching by order number." );
				}
			} elseif ( 404 === $result['status'] ) {
				self::drop_stale_link( $order, "Order {$order->get_id()} carries Bob Go link {$stored}, which returned 404; dropping it and searching by order number." );
			} else {
				// Could not reach Bob Go: not a conclusive answer about the
				// order, so worth retrying rather than stranding it.
				return [
					'status' => 'inconclusive',
					'id'     => 0,
					'reason' => 'inconclusive_transport',
				];
			}
		}

		// Never create blind. An order carrying no link may still exist on Bob
		// Go — pushed before the link was cleared, or ingested by another
		// integration — and creating regardless is what produces duplicates,
		// and with them double shipments.
		$twin = LinkAudit::find_verified_twin( $order );

		if ( 'found' === $twin['status'] && $twin['verified'] ) {
			return [
				'status' => 'found',
				'id'     => (int) $twin['id'],
				'reason' => 'matched by order number',
			];
		}

		if ( 'none' === $twin['status'] ) {
			return [
				'status' => 'none',
				'id'     => 0,
				'reason' => 'no existing order',
			];
		}

		// 'error' is a failed request — retryable. 'ambiguous' and an unverified
		// match are conclusive answers we simply must not act on, so they hold.
		//
		// The reason names what happened rather than passing the lookup's own
		// status through: an unverified match arrives here as 'found', which read
		// as "the lookup was inconclusive (found)" in the log and in the held-order
		// sync-log row support reads.
		switch ( $twin['status'] ) {
			case 'error':
				$reason = 'inconclusive_transport';
				break;

			case 'found':
				$reason = 'unverified_match';
				break;

			default:
				$reason = $twin['status'];
				break;
		}

		return [
			'status' => 'inconclusive',
			'id'     => 0,
			'reason' => $reason,
		];
	}

	/**
	 * Drop a link that does not represent this order, along with everything
	 * written on the back of it.
	 *
	 * The shipment, tracking and fulfilment meta all describe the order the link
	 * pointed at — a previous account's, or another store's. Removing only the id
	 * leaves that on display: the tracking column and the order panel would keep
	 * showing a courier and waybill for an order that no longer has either, and
	 * the pull that follows cannot correct it, because
	 * Webhook::refresh_fulfillment_data() returns early when the order it does
	 * resolve has no fulfilments of its own.
	 *
	 * Same key set as LinkAudit::unlink(), for the same reason — the sync markers
	 * go too, and the push that follows writes its own.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $message Why the link was dropped, for the log.
	 * @return void
	 */
	private static function drop_stale_link( \WC_Order $order, string $message ): void {
		foreach ( LinkAudit::UNLINK_META_KEYS as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}
		$order->save();

		self::log( $message, 'warning' );
	}

	/**
	 * Create the order on Bob Go and record what came back.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool True when the order was created and linked.
	 */
	private static function create_order( \WC_Order $order ): bool {
		$order_id = $order->get_id();
		$payload  = self::build_order_payload( $order );
		$result   = Api::post( 'v2/orders', $payload );

		SyncLog::insert(
			[
				'wc_order_id' => $order_id,
				'event_type'  => 'order_created',
				'direction'   => 'outbound',
				'payload'     => wp_json_encode(
					[
						'request'  => $payload,
						'response' => $result['body'],
					]
				),
				'http_status' => $result['status'],
				'success'     => $result['success'],
			]
		);

		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			self::mark_push_failed( $order, "Order {$order_id} sync failed: " . ( ! empty( $result['error'] ) ? $result['error'] : 'Unknown error' ) );
			return false;
		}

		$body = $result['body'];

		// A 2xx whose body carries no positive order id is a contract
		// violation, not a sync: without the id the order is invisible to
		// reconciliation, fulfillment refreshes and the metabox, yet a
		// 'synced' status would stop every retry path — the order would be
		// orphaned. Mark it failed so the existing retry surfaces apply.
		$bobgo_id = $body['id'] ?? 0;
		if ( ! is_numeric( $bobgo_id ) || (int) $bobgo_id <= 0 ) {
			self::mark_push_failed( $order, "Order {$order_id} sync returned HTTP {$result['status']} but no Bob Go order id in the response; marked failed for retry." );
			return false;
		}

		// Store Bob Go numeric order ID for fulfillment API calls.
		$order->update_meta_data( '_bobgo_order_id', sanitize_text_field( (string) $bobgo_id ) );

		// Store item IDs from response. Bob Go returns the array under `order_items`;
		// `items` is accepted as a fallback in case the API shape changes.
		$response_items = $body['order_items'] ?? $body['items'] ?? null;
		if ( ! empty( $response_items ) && is_array( $response_items ) ) {
			self::store_item_ids( $order, $response_items );
		}

		self::clear_rejection( $order );
		self::stamp_items_fingerprint( $order );
		$order->update_meta_data( '_bobgo_sync_status', 'synced' );
		$order->update_meta_data( '_bobgo_sync_hash', self::compute_sync_hash( $order ) );
		$order->update_meta_data( '_bobgo_last_synced', gmdate( 'c' ) );
		$order->save();

		self::log( "Order {$order_id} synced to Bob Go as {$bobgo_id}.", 'info' );

		return true;
	}

	/**
	 * PATCH the order's current state onto an existing Bob Go order.
	 *
	 * @param \WC_Order $order    The order.
	 * @param int       $bobgo_id The Bob Go order id to update.
	 * @param bool      $declined Set to true when nothing was sent because Bob Go
	 *                            has already refused this exact payload. Callers
	 *                            use it to tell "no answer, worth re-resolving"
	 *                            apart from "answered, and refused".
	 * @return bool True when Bob Go accepted the update.
	 */
	private static function push_order_update( \WC_Order $order, int $bobgo_id, bool &$declined = false ): bool {
		$declined      = false;
		$order_id      = $order->get_id();
		$payload       = self::build_order_payload( $order );
		$payload['id'] = $bobgo_id;
		$payload       = self::without_unchanged_items( $order, $payload );

		if ( self::already_rejected( $order, $payload ) ) {
			$declined = true;
			return false;
		}

		$result = Api::patch( 'v2/orders', $payload );

		SyncLog::insert(
			[
				'wc_order_id' => $order_id,
				'event_type'  => 'order_updated',
				'direction'   => 'outbound',
				'payload'     => wp_json_encode(
					[
						'request'  => $payload,
						'response' => $result['body'],
					]
				),
				'http_status' => $result['status'],
				'success'     => $result['success'],
			]
		);

		if ( ! $result['success'] ) {
			self::record_rejection( $order, $payload, (int) $result['status'] );
			self::mark_push_failed( $order, "Order {$order_id} update failed: " . ( ! empty( $result['error'] ) ? $result['error'] : 'Unknown error' ) );
			return false;
		}

		self::clear_rejection( $order );
		self::stamp_items_fingerprint( $order );
		$order->update_meta_data( '_bobgo_sync_status', 'synced' );
		$order->update_meta_data( '_bobgo_sync_hash', self::compute_sync_hash( $order ) );
		$order->update_meta_data( '_bobgo_last_synced', gmdate( 'c' ) );
		$order->save();

		return true;
	}

	/**
	 * Record a failed push, without inviting the save to re-trigger the push.
	 *
	 * The sync hash is stamped to the payload that just failed. It means "this is
	 * what we last sent", and we did send it — so on_order_updated(), which fires
	 * on the save below and compares that hash against the current payload, sees no
	 * change and schedules nothing. Leaving the hash stale instead produced an
	 * endless loop: save → dirty → schedule update → push fails → save → …, one
	 * round of API calls and a sync-log row per pass, forever.
	 *
	 * The order still reads 'failed', so the admin notice and the bulk re-sync
	 * both still surface it, and a genuine edit changes the payload and retries.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $message Log message.
	 * @return void
	 */
	private static function mark_push_failed( \WC_Order $order, string $message ): void {
		$order->update_meta_data( '_bobgo_sync_status', 'failed' );
		$order->update_meta_data( '_bobgo_sync_hash', self::compute_sync_hash( $order ) );
		$order->save();

		self::log( $message, 'error' );
	}

	/**
	 * Action Scheduler callback: update an existing order on Bob Go.
	 *
	 * The lean edit path — the link was resolved when the order was first synced,
	 * so this pushes straight to it rather than paying for a lookup on every
	 * order edit. An unlinked order is handed to the full sync, which resolves
	 * before pushing.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function handle_update_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// PATCH targets the numeric Bob Go order id. Without a usable one there is
		// nothing to update — hand off to the full sync, which resolves the order
		// on Bob Go and creates it if it genuinely does not exist. This is the
		// path an order takes after a held or failed first sync: it carries a
		// sync status (so edits reach here) but no link, and used to dead-end on
		// this guard with only a log line.
		$bobgo_id = $order->get_meta( '_bobgo_order_id' );
		if ( empty( $bobgo_id ) || ! is_numeric( $bobgo_id ) ) {
			self::log( "Order {$order_id} has no usable Bob Go link; handing the update to the full sync.", 'info' );
			self::schedule_sync_order( $order_id, 0 );
			return;
		}

		$declined = false;

		if ( self::push_order_update( $order, (int) $bobgo_id, $declined ) ) {
			return;
		}

		// Nothing was sent because Bob Go has already refused this exact payload.
		// There is no link to re-resolve and no answer to wait for, so the full
		// sync would only re-run the lookup and decline again — one wasted GET and
		// one wasted job per trigger, which on an hourly refresh is the same loop
		// in a different shape.
		if ( $declined ) {
			return;
		}

		// The push failed. Hand off to the full sync, which re-resolves the link
		// — a 404 here means the stored id is stale (order deleted, or created on
		// an account this store no longer uses), and only a re-resolve can tell
		// that apart from a transient error.
		self::schedule_sync_order( $order_id, 0 );
	}

	/**
	 * Action Scheduler callback: update order status on Bob Go.
	 *
	 * @param int    $order_id   The order ID.
	 * @param string $new_status The new status (without wc- prefix).
	 */
	public static function handle_update_order_status( int $order_id, string $new_status ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order_ref = $order->get_meta( '_bobgo_order_id' );
		if ( empty( $order_ref ) ) {
			return;
		}

		// PATCH targets the numeric Bob Go order id, which is stored separately
		// from order_ref (which may be an opaque string token).
		$bobgo_id = $order->get_meta( '_bobgo_order_id' );
		if ( empty( $bobgo_id ) || ! is_numeric( $bobgo_id ) ) {
			self::log( "Order {$order_id} update skipped: missing or non-numeric _bobgo_order_id.", 'warning' );
			return;
		}

		$payload       = self::build_order_payload( $order );
		$payload['id'] = (int) $bobgo_id;

		// Bob Go's orders PATCH accepts an explicit status, and for a WooCommerce
		// API channel it keeps the one it is sent rather than deriving its own.
		// The create POST accepts no status field, so a status is only ever added
		// here, never in build_order_payload. resolve_remote_status() decides
		// which WooCommerce statuses travel.
		$remote_status = self::resolve_remote_status( $new_status );
		if ( null !== $remote_status ) {
			$payload['status'] = $remote_status;
		}

		// The status is the point of this request, and Bob Go refuses the whole
		// update when the item list it carries would delete a fulfilled item —
		// which is exactly the shape of order this path fires on (a shipped
		// order being completed or cancelled). Send the list only when it has
		// genuinely changed.
		$payload = self::without_unchanged_items( $order, $payload );

		if ( self::already_rejected( $order, $payload ) ) {
			return;
		}

		$result = Api::patch( 'v2/orders', $payload );

		SyncLog::insert(
			[
				'wc_order_id' => $order_id,
				'event_type'  => 'status_updated',
				'direction'   => 'outbound',
				'payload'     => wp_json_encode(
					[
						'request'  => $payload,
						'response' => $result['body'],
					]
				),
				'http_status' => $result['status'],
				'success'     => $result['success'],
			]
		);

		if ( ! $result['success'] ) {
			if ( self::record_rejection( $order, $payload, (int) $result['status'] ) ) {
				$order->save();
			}
			self::log( "Order {$order_id} status update to '{$new_status}' failed: " . ( ! empty( $result['error'] ) ? $result['error'] : 'Unknown error' ), 'error' );
			return;
		}

		// Saved only when one of these actually wrote something: an unconditional
		// save on every accepted status update would re-enter the dirty check
		// and queue a redundant follow-up PATCH.
		$touched = self::clear_rejection( $order );
		$touched = self::stamp_items_fingerprint( $order ) || $touched;

		if ( $touched ) {
			$order->save();
		}
	}

	/**
	 * Map a WooCommerce status to the status Bob Go's orders PATCH accepts, or
	 * null when the transition must not be forwarded.
	 *
	 * Bob Go holds four statuses: new, processing, completed and cancelled.
	 * Three of them are a WooCommerce status under the same name, and those are
	 * the three we send:
	 *
	 * - "cancelled" and "completed" are what Bob Go acts on. Forwarding
	 *   "completed" lets it close an order it cannot complete on its own, such
	 *   as a Dokan parent order, which has no shipments of its own and so is
	 *   never fulfilled (and therefore never auto-completed) on the Bob Go side.
	 *   See Phase 2 QA #5.
	 * - "processing" is what takes an order back out of cancelled. A merchant
	 *   who cancels an order, then takes payment and sets it back to Processing,
	 *   left the Bob Go order cancelled: no status was sent, and Bob Go derives
	 *   none from a PATCH that carries no status. Bob Go keeps what it is sent,
	 *   so this equally moves an order out of completed when the merchant moves
	 *   the WooCommerce order back to Processing.
	 *
	 * The rest are deliberately not sent:
	 *
	 * - "pending" and "on-hold" say the order is awaiting payment, which the
	 *   payload says already in payment_status. Bob Go's "new" is where an order
	 *   starts, not somewhere to put one back.
	 * - "refunded" and "failed" have no Bob Go status of their own. The v3
	 *   plugin cancelled a refunded order because paid/unpaid was all it could
	 *   say about payment; this plugin sends payment_status "refunded", so
	 *   cancelling on top of that would be a second and stronger action nobody
	 *   asked for.
	 * - "shipped" and "partially-shipped" (added by other plugins) describe
	 *   fulfilment, which Bob Go is the source of, not WooCommerce.
	 *
	 * Kept pure and side-effect-free so the forwarding decision is unit testable
	 * without the Api / SyncLog dependencies.
	 *
	 * @param string $new_status WooCommerce status (without the wc- prefix).
	 * @return string|null The Bob Go status to send, or null to omit it.
	 */
	private static function resolve_remote_status( string $new_status ): ?string {
		// WooCommerce status => Bob Go status. All three map 1:1.
		$forwarded = [
			'cancelled'  => 'cancelled',
			'completed'  => 'completed',
			'processing' => 'processing',
		];

		return $forwarded[ $new_status ] ?? null;
	}

	/**
	 * Check if an order should be synced.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public static function should_sync( WC_Order $order ): bool {
		if ( ! Api::is_connected() ) {
			return false;
		}

		// Skip subscription container objects — but NOT renewal orders, which
		// are real shippable orders. Renewals never pass through checkout, so
		// their initial sync is picked up by on_order_status_changed() when
		// the gateway moves them into a syncable status.
		if ( method_exists( $order, 'get_type' ) && 'shop_subscription' === $order->get_type() ) {
			return false;
		}

		// Skip orders with no items.
		if ( count( $order->get_items() ) === 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Strip Bob Go link/sync meta when WooCommerce Subscriptions copies order
	 * data onto a new renewal or resubscribe order.
	 *
	 * A renewal that inherited _bobgo_order_id (or the sync status/hash) would
	 * be treated as already synced — silently skipping its own creation on Bob
	 * Go — and inbound webhooks would resolve it against the original order's
	 * Bob Go record. Registered on both the current (`wc_subscriptions_*_data`,
	 * meta_key => value map) and legacy (`wcs_*_meta`, list of
	 * meta_key/meta_value rows) copy filters; handles either shape.
	 *
	 * @param mixed $meta Meta collection being copied to the new order.
	 * @return mixed The collection without _bobgo_* entries.
	 */
	public static function exclude_bobgo_meta_from_copy( $meta ) {
		if ( ! is_array( $meta ) ) {
			return $meta;
		}

		foreach ( $meta as $key => $value ) {
			$meta_key = is_array( $value ) && isset( $value['meta_key'] ) ? (string) $value['meta_key'] : (string) $key;
			if ( str_starts_with( $meta_key, '_bobgo_' ) ) {
				unset( $meta[ $key ] );
			}
		}

		return $meta;
	}

	/**
	 * Check if a status change was triggered by a webhook (echo loop guard).
	 *
	 * @param int $order_id The order ID.
	 * @return bool
	 */
	public static function is_webhook_triggered( int $order_id ): bool {
		$transient_key = "bobgo_webhook_status_change_{$order_id}";
		if ( get_transient( $transient_key ) ) {
			delete_transient( $transient_key );
			return true;
		}
		return false;
	}

	/**
	 * Build the order payload for the Bob Go API.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return array<string, mixed>
	 */
	public static function build_order_payload( WC_Order $order ): array {
		// Get the buyer-selected shipping method and cost.
		$shipping_methods = $order->get_shipping_methods();
		$shipping_method  = '';
		foreach ( $shipping_methods as $method ) {
			$shipping_method = $method->get_method_title();
			break;
		}

		$payload = [
			'channel_ref_id'                 => (string) $order->get_id(),
			'channel_order_number'           => $order->get_order_number(),
			'customer_name'                  => self::first_non_empty( $order->get_shipping_first_name(), $order->get_billing_first_name() ),
			'customer_surname'               => self::first_non_empty( $order->get_shipping_last_name(), $order->get_billing_last_name() ),
			'customer_email'                 => $order->get_billing_email(),
			'customer_phone'                 => self::first_non_empty( $order->get_shipping_phone(), $order->get_billing_phone() ),
			'currency'                       => $order->get_currency(),
			'buyer_selected_shipping_cost'   => (float) $order->get_shipping_total(),
			'buyer_selected_shipping_method' => $shipping_method,
			'delivery_address'               => self::format_delivery_address( $order ),
			'order_items'                    => self::format_order_items( $order ),
			'payment_status'                 => self::get_payment_status( $order ),
			'note'                           => self::compose_note( $order ),
		];

		$total_tax = (float) $order->get_total_tax();
		if ( $total_tax > 0 ) {
			$payload['total_tax'] = $total_tax;
		}

		$total_discount = (float) $order->get_total_discount();
		if ( $total_discount > 0 ) {
			$payload['total_discount'] = $total_discount;
		}

		$date_created = $order->get_date_created();
		if ( $date_created ) {
			$payload['date_placed_on_channel'] = $date_created->format( 'c' );
		}

		$service_code = $order->get_meta( 'bobgo_service_code' );
		if ( ! empty( $service_code ) ) {
			$payload['buyer_selected_service_code'] = sanitize_text_field( $service_code );
		}

		// Custom field mappings → tags.
		$custom_tags = self::build_custom_field_tags( $order );
		if ( ! empty( $custom_tags ) ) {
			$payload['tags'] = $custom_tags;
		}

		/**
		 * Filter the complete order payload before sending to Bob Go.
		 *
		 * @since 4.1.0
		 * @param array    $payload The order payload array.
		 * @param WC_Order $order   The WooCommerce order object.
		 */
		$payload = apply_filters( 'bobgo_order_payload', $payload, $order );

		return $payload;
	}

	/**
	 * Get the payment status for the Bob Go API from WC order status.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function get_payment_status( WC_Order $order ): string {
		switch ( $order->get_status() ) {
			case 'processing':
			case 'completed':
			case 'shipped':
			case 'partially-shipped':
				return 'paid';
			case 'on-hold':
				return 'pending';
			case 'refunded':
				return 'refunded';
			default:
				return 'unpaid';
		}
	}

	/**
	 * Compute a sync hash for dirty-checking.
	 *
	 * @param WC_Order $order The order.
	 * @return string SHA-256 hash.
	 */
	public static function compute_sync_hash( WC_Order $order ): string {
		return hash( 'sha256', wp_json_encode( self::build_order_payload( $order ) ) );
	}

	/**
	 * Fingerprint of the order's current item list, as the payload would carry it.
	 *
	 * @param WC_Order $order The order.
	 * @return string SHA-256 fingerprint.
	 */
	private static function items_fingerprint( WC_Order $order ): string {
		return hash( 'sha256', (string) wp_json_encode( self::format_order_items( $order ) ) );
	}

	/**
	 * Record the item list Bob Go has just accepted.
	 *
	 * Called after the response has been applied — on the create path that means
	 * after store_item_ids(), so the fingerprint includes the Bob Go item ids the
	 * response carried and the next update does not read them as a change.
	 *
	 * A no-op when the fingerprint already matches, so callers can save only
	 * when something actually changed rather than on every accepted update.
	 *
	 * The caller saves the order.
	 *
	 * @param WC_Order $order The order.
	 * @return bool True when the meta was written.
	 */
	private static function stamp_items_fingerprint( WC_Order $order ): bool {
		$fingerprint = self::items_fingerprint( $order );
		if ( $fingerprint === (string) $order->get_meta( self::META_ITEMS_HASH ) ) {
			return false;
		}

		$order->update_meta_data( self::META_ITEMS_HASH, $fingerprint );

		return true;
	}

	/**
	 * Record the current item list on an order that has just been linked to
	 * Bob Go by a route other than the create POST, unless one is already
	 * recorded.
	 *
	 * The backfill establishes "a linked order carries a fingerprint" once and
	 * then sets its flag for good, but three paths write `_bobgo_order_id`
	 * afterwards: the webhook linker, the link audit's relink, and adoption of
	 * an order Bob Go already had under this order number. An order linked by
	 * one of those and left unstamped is back in the position the backfill
	 * exists to prevent, where the next update omits the list (no evidence it
	 * changed), Bob Go accepts the rest, and the item edit that update was
	 * carrying is recorded as delivered. Stamping at the point of linking is
	 * what makes the invariant hold whenever an order is linked, rather than
	 * only at upgrade time.
	 *
	 * Only fills a gap, never overwrites: a stored fingerprint is evidence of
	 * a list Bob Go actually accepted, and relink() keeps the one it has on
	 * purpose. Replacing it with an unverified claim about a different Bob Go
	 * order would lose a pending item change.
	 *
	 * The caller saves the order.
	 *
	 * @param WC_Order $order The newly linked order.
	 * @return bool True when the meta was written.
	 */
	public static function ensure_items_fingerprint( WC_Order $order ): bool {
		if ( '' !== trim( (string) $order->get_meta( self::META_ITEMS_HASH ) ) ) {
			return false;
		}

		return self::stamp_items_fingerprint( $order );
	}

	/**
	 * Queue the one-shot backfill that stamps a fingerprint on orders linked
	 * before this version shipped.
	 *
	 * Without it, a linked order carrying no fingerprint stays "item list
	 * unknown" until its next update — and that update is usually the one
	 * carrying the merchant's first item edit. The list is omitted (no evidence
	 * it changed), Bob Go accepts the rest, and the *edited* list is then
	 * stamped as the one Bob Go holds: the edit is silently swallowed and the
	 * order looks in sync. Stamping at upgrade time instead reads the items
	 * while they still match what Bob Go last accepted, so the very next edit
	 * is seen as a change and sent.
	 *
	 * Called from activation and from the version-bump upgrade pass, both of
	 * which can run more than once; the option flag makes that a no-op.
	 */
	public static function schedule_items_fingerprint_backfill(): void {
		if ( get_option( self::OPTION_ITEMS_BACKFILLED ) ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK_ITEMS_BACKFILL ) ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK_ITEMS_BACKFILL, [], 'bobgo-shipping' );
	}

	/**
	 * Action Scheduler callback: stamp one batch of un-fingerprinted orders.
	 *
	 * Self-draining — stamping an order removes it from the query — so each
	 * pass simply asks for the next page and re-queues itself while the page
	 * comes back full. Newest orders are stamped first: they are the ones a
	 * merchant is most likely to edit in the window before the run finishes.
	 *
	 * @return void
	 */
	public static function handle_items_fingerprint_backfill(): void {
		if ( get_option( self::OPTION_ITEMS_BACKFILLED ) ) {
			return;
		}

		$batch_size = max( 1, (int) apply_filters( 'bobgo_items_backfill_batch_size', 50 ) );

		$orders = OrderQuery::orders_with_meta(
			[
				'limit'   => $batch_size,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'status'  => 'any',
			],
			[
				[
					'key'     => '_bobgo_order_id',
					'compare' => 'EXISTS',
				],
				[
					'key'     => self::META_ITEMS_HASH,
					'compare' => 'NOT EXISTS',
				],
			]
		);

		$stamped = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			// Writing this meta leaves build_order_payload() untouched, so the
			// save below cannot look like an order change to on_order_updated()
			// and queue a PATCH of its own.
			if ( self::stamp_items_fingerprint( $order ) ) {
				$order->save();
				++$stamped;
			}
		}

		// A short batch means the query has run dry: every linked order now
		// carries a fingerprint, so the run is done.
		if ( count( $orders ) < $batch_size ) {
			update_option( self::OPTION_ITEMS_BACKFILLED, 1, false );
			self::log( "Item-fingerprint backfill finished; final batch stamped {$stamped} order(s).", 'info' );
			return;
		}

		// A full page that stamped nothing cannot drain: the next pass would
		// read the same orders back. Stop rather than re-queue forever, and say
		// so — the fingerprints did not persist, which is worth investigating.
		if ( 0 === $stamped ) {
			update_option( self::OPTION_ITEMS_BACKFILLED, 1, false );
			self::log( 'Item-fingerprint backfill stopped: a full batch stamped no orders, so the fingerprints are not persisting.', 'warning' );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::HOOK_ITEMS_BACKFILL, [], 'bobgo-shipping' );
		}

		self::log( "Item-fingerprint backfill stamped {$stamped} order(s); next batch scheduled.", 'info' );
	}

	/**
	 * Whether an update needs to carry the item list at all.
	 *
	 * Bob Go's orders PATCH treats `order_items` as a full replacement: an item
	 * it holds that the payload omits is deleted. Sending an unchanged list
	 * therefore buys nothing and risks two real failures — on a wrongly-linked
	 * order it overwrites another order's contents, and on an order with a
	 * fulfilled item Bob Go refuses the entire update ("cannot delete
	 * order_item=…, fulfilled quantity is greater than 0"), so the address or
	 * status change riding along is lost too. A live store spent a day on an
	 * hourly failing PATCH for exactly this reason.
	 *
	 * No stored fingerprint means no evidence the list changed, so it is left
	 * out and recorded on success. Orders linked before fingerprinting existed
	 * are stamped up front by schedule_items_fingerprint_backfill() rather than
	 * being left to reach that state on their next update — otherwise the
	 * update that first found them unstamped would be the one carrying the
	 * merchant's edit, and stamping it on success would record the edited list
	 * as the one Bob Go holds. The gap that remains is the window between the
	 * upgrade and the backfill draining: an item edit made inside it, on an
	 * order that is never edited again, will not reach Bob Go.
	 *
	 * @param WC_Order $order The order.
	 * @return bool True when the list differs from the one Bob Go accepted.
	 */
	private static function items_need_pushing( WC_Order $order ): bool {
		$stored  = trim( (string) $order->get_meta( self::META_ITEMS_HASH ) );
		$current = self::items_fingerprint( $order );

		return '' !== $stored && $current !== $stored;
	}

	/**
	 * Drop the item list from an update payload when it has not changed.
	 *
	 * `order_items` is omitted entirely rather than sent empty — Bob Go rejects
	 * an empty array ("cannot delete all order items") and skips the item diff
	 * only when the key is absent.
	 *
	 * @param WC_Order             $order   The order.
	 * @param array<string, mixed> $payload The update payload.
	 * @return array<string, mixed> The payload, possibly without order_items.
	 */
	private static function without_unchanged_items( WC_Order $order, array $payload ): array {
		if ( ! self::items_need_pushing( $order ) ) {
			unset( $payload['order_items'] );
		}

		return $payload;
	}

	/**
	 * Fingerprint of an outbound payload, used to recognise a re-send.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @return string SHA-256 fingerprint.
	 */
	private static function payload_fingerprint( array $payload ): string {
		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Whether Bob Go has already refused this exact payload.
	 *
	 * Every trigger that dirties an order queues an update, and a refresh from
	 * Bob Go dirties orders on its own hourly schedule — so a payload Bob Go
	 * will never accept was re-sent every hour, indefinitely, one failing
	 * request and one sync-log row per pass. The order is already marked failed
	 * and surfaced in the admin notice; repeating the request adds nothing.
	 *
	 * Keyed on the payload, not the order: any genuine change produces a
	 * different fingerprint and is sent immediately.
	 *
	 * @param WC_Order             $order   The order.
	 * @param array<string, mixed> $payload The payload about to be sent.
	 * @return bool True when this payload has already been refused.
	 */
	private static function already_rejected( WC_Order $order, array $payload ): bool {
		$stored = json_decode( (string) $order->get_meta( self::META_PUSH_REJECTED ), true );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		if ( ( $stored['hash'] ?? '' ) !== self::payload_fingerprint( $payload ) ) {
			return false;
		}

		$status = (int) ( $stored['status'] ?? 0 );
		self::log( "Order {$order->get_id()} update skipped: Bob Go already refused this exact payload (HTTP {$status}). It will be sent again when the order changes.", 'info' );

		return true;
	}

	/**
	 * Remember a payload Bob Go refused, so it is not re-sent unchanged.
	 *
	 * Only the statuses in REJECTED_STATUSES are recorded — everything else is
	 * either transient or fixed outside the payload, and must stay retryable.
	 *
	 * The caller saves the order.
	 *
	 * @param WC_Order             $order   The order.
	 * @param array<string, mixed> $payload The payload that was refused.
	 * @param int                  $status  HTTP status Bob Go answered with.
	 * @return bool True when the meta was written.
	 */
	private static function record_rejection( WC_Order $order, array $payload, int $status ): bool {
		if ( ! in_array( $status, self::REJECTED_STATUSES, true ) ) {
			return false;
		}

		$order->update_meta_data(
			self::META_PUSH_REJECTED,
			(string) wp_json_encode(
				[
					'hash'   => self::payload_fingerprint( $payload ),
					'status' => $status,
					'time'   => gmdate( 'c' ),
				]
			)
		);

		return true;
	}

	/**
	 * Forget a recorded rejection after Bob Go accepts an update.
	 *
	 * The caller saves the order.
	 *
	 * @param WC_Order $order The order.
	 * @return bool True when a record was removed.
	 */
	private static function clear_rejection( WC_Order $order ): bool {
		if ( '' === (string) $order->get_meta( self::META_PUSH_REJECTED ) ) {
			return false;
		}

		$order->delete_meta_data( self::META_PUSH_REJECTED );

		return true;
	}

	/**
	 * Format the delivery address for the API payload.
	 *
	 * @param WC_Order $order The order.
	 * @return array<string, string>
	 */
	private static function format_delivery_address( WC_Order $order ): array {
		// Determine if shipping address is present by checking key fields.
		$has_shipping = ! empty( $order->get_shipping_address_1() )
			|| ! empty( $order->get_shipping_city() )
			|| ! empty( $order->get_shipping_postcode() );

		if ( $has_shipping ) {
			return [
				'company'        => $order->get_shipping_company(),
				'street_address' => $order->get_shipping_address_1(),
				'local_area'     => self::get_order_suburb( $order, 'shipping' ),
				'city'           => $order->get_shipping_city(),
				'zone'           => $order->get_shipping_state(),
				'country'        => $order->get_shipping_country(),
				'code'           => $order->get_shipping_postcode(),
			];
		}

		// Fall back to billing address.
		return [
			'company'        => $order->get_billing_company(),
			'street_address' => $order->get_billing_address_1(),
			'local_area'     => self::get_order_suburb( $order, 'billing' ),
			'city'           => $order->get_billing_city(),
			'zone'           => $order->get_billing_state(),
			'country'        => $order->get_billing_country(),
			'code'           => $order->get_billing_postcode(),
		];
	}

	/**
	 * Resolve the suburb (Bob Go delivery local_area) for an order, with a
	 * fallback to the parent order when the order itself has none.
	 *
	 * Marketplace plugins such as Dokan split a checkout into per-vendor
	 * sub-orders. A sub-order inherits the core shipping/billing address but NOT
	 * our custom suburb meta, which is captured on the PARENT order at checkout.
	 * Without this fallback the sub-order syncs to Bob Go with an empty
	 * local_area and the vendor cannot fulfil it. When the suburb is missing on
	 * the order itself and the order has a parent (Dokan sub-order, subscription
	 * renewal, etc.), inherit it from the parent. Standalone orders have no
	 * parent (parent id 0), so their behaviour is unchanged.
	 *
	 * Each order is read through read_group_suburb(), which additionally recovers
	 * a billing suburb when the order ships to the billing address.
	 *
	 * @param WC_Order $order The order being synced.
	 * @param string   $group Address group: 'shipping' or 'billing'.
	 * @return string The suburb, or '' if none is found.
	 */
	private static function get_order_suburb( WC_Order $order, string $group ): string {
		$suburb = self::read_group_suburb( $order, $group );
		if ( '' !== $suburb ) {
			return $suburb;
		}

		$parent_id = $order->get_parent_id();
		if ( $parent_id > 0 ) {
			$parent = wc_get_order( $parent_id );
			if ( $parent instanceof WC_Order ) {
				return self::read_group_suburb( $parent, $group );
			}
		}

		return '';
	}

	/**
	 * Read one order's suburb for an address group, falling back to its billing
	 * suburb when the order ships to the billing address.
	 *
	 * On the classic checkout the plugin never writes the suburb itself: it relies
	 * on WooCommerce mirroring every registered shipping field from its billing
	 * counterpart while "Ship to a different address" is off
	 * (WC_Checkout::get_posted_data()). Anything that intercepts that path — a
	 * checkout-field editor, a replacement checkout plugin, a themed checkout
	 * template — copies the core address fields but not our custom field, which
	 * leaves _billing_suburb populated and _shipping_suburb empty on an order
	 * whose shipping address is otherwise complete. Bob Go then receives the
	 * order with no local_area.
	 *
	 * The fallback is gated on the two addresses actually matching. When the
	 * buyer really did enter a different delivery address, borrowing the billing
	 * suburb would put a suburb from another city on the shipment — worse than
	 * sending none.
	 *
	 * @param WC_Order $order The order to read.
	 * @param string   $group Address group: 'shipping' or 'billing'.
	 * @return string The suburb, or '' if none is found.
	 */
	private static function read_group_suburb( WC_Order $order, string $group ): string {
		// Prefixed (WC auto-save) then non-prefixed meta keys — the same keys the
		// classic checkout and admin order screen write to.
		$billing_keys = [ '_billing_suburb', 'billing_suburb' ];
		$keys         = 'billing' === $group
			? $billing_keys
			: [ '_shipping_suburb', 'shipping_suburb' ];

		$suburb = self::read_first_non_empty_meta( $order, $keys );
		if ( '' !== $suburb ) {
			return $suburb;
		}

		if ( 'shipping' === $group && self::shipping_matches_billing( $order ) ) {
			return self::read_first_non_empty_meta( $order, $billing_keys );
		}

		return '';
	}

	/**
	 * Whether the order's shipping address is the same address as its billing
	 * address — the state WooCommerce produces when the buyer leaves "Ship to a
	 * different address" off and every shipping field is mirrored from billing.
	 *
	 * @param WC_Order $order The order to inspect.
	 * @return bool True when every compared address field matches.
	 */
	private static function shipping_matches_billing( WC_Order $order ): bool {
		$pairs = [
			[ $order->get_shipping_address_1(), $order->get_billing_address_1() ],
			[ $order->get_shipping_address_2(), $order->get_billing_address_2() ],
			[ $order->get_shipping_city(), $order->get_billing_city() ],
			[ $order->get_shipping_state(), $order->get_billing_state() ],
			[ $order->get_shipping_postcode(), $order->get_billing_postcode() ],
			[ $order->get_shipping_country(), $order->get_billing_country() ],
		];

		foreach ( $pairs as $pair ) {
			if ( self::normalise_address_part( $pair[0] ) !== self::normalise_address_part( $pair[1] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalise one address field for comparison: trimmed, collapsed whitespace
	 * and lowercased, so " 0153 " and "0153" compare equal.
	 *
	 * @param mixed $value Raw address field value.
	 * @return string The normalised value.
	 */
	private static function normalise_address_part( $value ): string {
		$collapsed = preg_replace( '/\s+/', ' ', trim( (string) $value ) );

		return mb_strtolower( (string) $collapsed );
	}

	/**
	 * Return the first non-empty meta value among the given keys.
	 *
	 * @param WC_Order $order The order to read from.
	 * @param string[] $keys  Meta keys to try, in priority order.
	 * @return string The first non-empty value as a string, or '' if none.
	 */
	private static function read_first_non_empty_meta( WC_Order $order, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Format order items for the API payload.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_order_items( WC_Order $order ): array {
		$items = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product    = $item->get_product();
			$qty        = max( 1, $item->get_quantity() );
			$unit_price = (float) $item->get_total() / $qty;
			$weight_kg  = 0.0;

			if ( $product ) {
				$weight = $product->get_weight();
				if ( ! empty( $weight ) ) {
					// Match server-side persistence (rounded to 1 decimal in Bob Go) so the payload reflects the stored value.
					$weight_kg = round( (float) wc_get_weight( (float) $weight, 'kg' ), 1 );
				}
			}

			$item_data = [
				'channel_ref_id' => (int) $item->get_id(),
				'description'    => $item->get_name(),
				'sku'            => $product ? $product->get_sku() : '',
				'unit_price'     => $unit_price,
				'qty'            => $qty,
				'unit_weight_kg' => $weight_kg,
			];

			// Include the Bob Go item ID if previously stored (required for PATCH).
			$bobgo_item_id = wc_get_order_item_meta( $item->get_id(), '_bobgo_item_id', true );
			if ( ! empty( $bobgo_item_id ) ) {
				$item_data['id'] = (int) $bobgo_item_id;
			}

			if ( $product ) {
				$length = $product->get_length();
				if ( ! empty( $length ) ) {
					$length_cm = (float) wc_get_dimension( (float) $length, 'cm' );
					if ( $length_cm > 0 ) {
						$item_data['unit_length_cm'] = $length_cm;
					}
				}

				$width = $product->get_width();
				if ( ! empty( $width ) ) {
					$width_cm = (float) wc_get_dimension( (float) $width, 'cm' );
					if ( $width_cm > 0 ) {
						$item_data['unit_width_cm'] = $width_cm;
					}
				}

				$height = $product->get_height();
				if ( ! empty( $height ) ) {
					$height_cm = (float) wc_get_dimension( (float) $height, 'cm' );
					if ( $height_cm > 0 ) {
						$item_data['unit_height_cm'] = $height_cm;
					}
				}

				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$image_url = wp_get_attachment_url( (int) $image_id );
					if ( $image_url ) {
						$item_data['channel_image_url'] = $image_url;
					}
				}

				// Vendor support (Dokan): when enabled, the vendor is the source location.
				$vendor_name = self::get_item_vendor( $product->get_id() );
				if ( ! empty( $vendor_name ) ) {
					$item_data['channel_location_name'] = $vendor_name;
				}

				if ( empty( $vendor_name ) ) {
					$shipping_class_slug = $product->get_shipping_class();
					if ( ! empty( $shipping_class_slug ) ) {
						$term = get_term_by( 'slug', $shipping_class_slug, 'product_shipping_class' );
						if ( $term instanceof \WP_Term ) {
							$item_data['channel_location_name'] = $term->name;
						}
					}
				}
			}

			$display_options = self::format_item_display_options( $item );
			if ( ! empty( $display_options ) ) {
				$item_data['display_options'] = $display_options;
			}

			/**
			 * Filter individual order item data before sending to Bob Go.
			 *
			 * @since 4.1.0
			 * @param array                  $item_data The item payload array.
			 * @param \WC_Order_Item_Product  $item      The WooCommerce order item.
			 * @param \WC_Product|false       $product   The product (false if deleted).
			 * @param WC_Order               $order     The WooCommerce order object.
			 */
			$item_data = apply_filters( 'bobgo_order_item_payload', $item_data, $item, $product, $order );

			$items[] = $item_data;
		}

		return $items;
	}

	/**
	 * Format visible line-item meta as display options for the API payload.
	 *
	 * Forwards customer-facing item meta — product add-on selections, variation
	 * attributes, personalisation fields — one entry per meta row, each with
	 * the raw channel key/value (e.g. attribute slugs like "pa_colour" /
	 * "pure-hazel") and the display_key/display_value the customer saw.
	 * WooCommerce's get_formatted_meta_data() supplies only visible
	 * (non-underscore-prefixed) meta; include_all keeps variation attributes
	 * that WooCommerce would otherwise hide because they already appear in the
	 * item name. Display fields are normalised to plain text, raw fields are
	 * forwarded faithfully, and insertion order is preserved — duplicate keys
	 * (e.g. checkbox-group add-ons) stay as separate entries so raw values are
	 * never merged.
	 *
	 * Merchants can switch the feature off or exclude individual raw keys on
	 * the Advanced settings tab. Exclusion is a blocklist: keys the merchant
	 * has never seen (or unticked) are still sent, so newly added product
	 * options flow without a settings visit.
	 *
	 * @param \WC_Order_Item_Product $item The order item.
	 * @return array<int, array{key: string, value: string, display_key: string, display_value: string}>
	 */
	private static function format_item_display_options( \WC_Order_Item_Product $item ): array {
		$bobgo_options = get_option( 'bobgo', [] );

		// Default on: only an explicitly saved falsy flag disables the feature.
		if ( isset( $bobgo_options['display_options_enabled'] ) && empty( $bobgo_options['display_options_enabled'] ) ) {
			return [];
		}

		$excluded_keys = $bobgo_options['display_options_excluded_keys'] ?? [];
		if ( ! is_array( $excluded_keys ) ) {
			$excluded_keys = [];
		}

		$options = [];

		foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
			if ( ! is_scalar( $meta->display_key ?? null ) || ! is_scalar( $meta->display_value ?? null ) ) {
				continue;
			}

			$raw_key = is_scalar( $meta->key ?? null ) ? (string) $meta->key : '';

			if ( '' !== $raw_key && in_array( $raw_key, $excluded_keys, true ) ) {
				continue;
			}

			$display_key   = self::truncate_meta_text( self::normalize_meta_text( (string) $meta->display_key ) );
			$display_value = self::truncate_meta_text( self::normalize_meta_text( (string) $meta->display_value ) );

			if ( '' === $display_key || '' === $display_value ) {
				continue;
			}

			$options[] = [
				'key'           => self::sanitize_raw_meta_text( $raw_key ),
				'value'         => is_scalar( $meta->value ?? null ) ? self::sanitize_raw_meta_text( (string) $meta->value ) : '',
				'display_key'   => $display_key,
				'display_value' => $display_value,
			];

			if ( count( $options ) >= self::DISPLAY_OPTIONS_MAX_ENTRIES ) {
				break;
			}
		}

		return $options;
	}

	/**
	 * Normalise a meta display string to plain single-line text.
	 *
	 * Strips markup first, then decodes entities — this order preserves text a
	 * customer literally typed (e.g. "&lt;3" stays "<3" instead of being
	 * stripped). Control characters are removed because PostgreSQL JSONB
	 * rejects \u0000 outright, then whitespace is collapsed.
	 *
	 * @param string $text Raw display key or value (may contain HTML).
	 * @return string
	 */
	private static function normalize_meta_text( string $text ): string {
		// Strip invalid UTF-8 byte sequences up front (WooCommerce rawurldecode()s
		// meta, so percent-encoded garbage can produce them). Without this the
		// /u regexes below fail on the whole string and the value would be lost.
		$text = wp_check_invalid_utf8( $text, true );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Sanitise a raw meta key/value for the API payload.
	 *
	 * Raw fields are machine values (attribute slugs, option identifiers) and
	 * are forwarded faithfully — no tag or entity handling. Only two things
	 * are removed before the length cap: invalid UTF-8 byte sequences (they
	 * would otherwise force wp_json_encode into scrubbing the whole request
	 * body) and the NUL byte, which PostgreSQL JSONB rejects outright.
	 *
	 * @param string $text Raw meta key or value.
	 * @return string
	 */
	private static function sanitize_raw_meta_text( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );

		return self::truncate_meta_text( trim( str_replace( "\0", '', $text ) ) );
	}

	/**
	 * Cap a meta string at DISPLAY_OPTIONS_MAX_LENGTH characters, marking
	 * truncation with an ellipsis so it is visible on the Bob Go side.
	 *
	 * @param string $text Normalised text.
	 * @return string
	 */
	private static function truncate_meta_text( string $text ): string {
		if ( mb_strlen( $text ) <= self::DISPLAY_OPTIONS_MAX_LENGTH ) {
			return $text;
		}

		return mb_substr( $text, 0, self::DISPLAY_OPTIONS_MAX_LENGTH - 1 ) . '…';
	}

	/**
	 * Store Bob Go item IDs in order item meta.
	 *
	 * @param WC_Order          $order The order.
	 * @param array<int, mixed> $bobgo_items Items from Bob Go response.
	 */
	private static function store_item_ids( WC_Order $order, array $bobgo_items ): void {
		$wc_items       = $order->get_items();
		$wc_items_array = array_values( $wc_items );

		foreach ( $bobgo_items as $index => $bobgo_item ) {
			if ( ! is_array( $bobgo_item ) ) {
				continue;
			}

			$bobgo_item_id = $bobgo_item['id'] ?? $bobgo_item['item_id'] ?? '';
			if ( empty( $bobgo_item_id ) ) {
				continue;
			}

			$wc_item_id = (int) ( $bobgo_item['channel_ref_id'] ?? $bobgo_item['wc_item_id'] ?? 0 );

			$matched = false;
			if ( $wc_item_id > 0 ) {
				foreach ( $wc_items as $wc_item ) {
					if ( $wc_item->get_id() === $wc_item_id ) {
						wc_update_order_item_meta( $wc_item->get_id(), '_bobgo_item_id', sanitize_text_field( (string) $bobgo_item_id ) );
						$matched = true;
						break;
					}
				}
			}

			// Fallback: match by index position.
			if ( ! $matched && isset( $wc_items_array[ $index ] ) ) {
				wc_update_order_item_meta( $wc_items_array[ $index ]->get_id(), '_bobgo_item_id', sanitize_text_field( (string) $bobgo_item_id ) );
			}
		}
	}

	/**
	 * Check if vendor plugin support is enabled.
	 *
	 * @return bool
	 */
	private static function is_vendor_support_enabled(): bool {
		$options       = get_option( 'bobgo', [] );
		$vendor_plugin = $options['vendor_plugin'] ?? 'none';
		return ! empty( $vendor_plugin ) && 'none' !== $vendor_plugin;
	}

	/**
	 * Get the vendor name for a product via Dokan.
	 *
	 * Also called from the rates-at-checkout payload (see Shipping::
	 * format_cart_items()), so the "is marketplace support on" check lives here
	 * rather than at each call site.
	 *
	 * @param int $product_id The product ID.
	 * @return string Vendor shop name, or empty string when marketplace support is off or no vendor is found.
	 */
	public static function get_item_vendor( int $product_id ): string {
		if ( ! self::is_vendor_support_enabled() ) {
			return '';
		}

		if ( ! function_exists( 'dokan_get_vendor_by_product' ) ) {
			return '';
		}

		$vendor = dokan_get_vendor_by_product( $product_id );
		if ( ! $vendor || ! method_exists( $vendor, 'get_shop_name' ) ) {
			return '';
		}

		$shop_name = $vendor->get_shop_name();
		return ! empty( $shop_name ) ? sanitize_text_field( $shop_name ) : '';
	}

	/**
	 * Return the first non-empty string from the given arguments.
	 *
	 * @param string ...$values Values to check.
	 * @return string
	 */
	private static function first_non_empty( string ...$values ): string {
		foreach ( $values as $value ) {
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Build custom field tags from mapped order meta.
	 *
	 * Reads the custom_field_mappings from the bobgo option, fetches each
	 * meta value from the order, and returns an array of tag objects.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, array{name: string, type: string}>
	 */
	/**
	 * Compose the order note: customer note plus any custom field mappings routed to the note destination.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function compose_note( WC_Order $order ): string {
		$customer_note = sanitize_textarea_field( $order->get_customer_note() );
		$custom_note   = self::build_custom_field_note( $order );

		if ( '' === $custom_note ) {
			return $customer_note;
		}

		if ( '' === $customer_note ) {
			return $custom_note;
		}

		return $customer_note . "\n\n" . $custom_note;
	}

	/**
	 * Build the custom field tags array from custom field mappings configured for the tag destination.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, array{name: string}>
	 */
	private static function build_custom_field_tags( WC_Order $order ): array {
		$tags = [];

		foreach ( self::resolve_custom_field_values( $order, 'tag' ) as $entry ) {
			$tags[] = [
				'name' => $entry['label'] . ': ' . $entry['value'],
			];
		}

		return $tags;
	}

	/**
	 * Build the note addendum from custom field mappings configured for the note destination.
	 *
	 * @param WC_Order $order The order.
	 * @return string Newline-joined "Label: value" lines, or empty string if none.
	 */
	private static function build_custom_field_note( WC_Order $order ): string {
		$lines = [];

		foreach ( self::resolve_custom_field_values( $order, 'note' ) as $entry ) {
			$lines[] = $entry['label'] . ': ' . $entry['value'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Resolve the configured custom field mappings to label/value pairs for a given destination.
	 *
	 * @param WC_Order $order       The order.
	 * @param string   $destination 'tag' or 'note'.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function resolve_custom_field_values( WC_Order $order, string $destination ): array {
		$options  = get_option( 'bobgo', [] );
		$mappings = $options['custom_field_mappings'] ?? [];

		if ( empty( $mappings ) || ! is_array( $mappings ) ) {
			return [];
		}

		$resolved = [];

		foreach ( $mappings as $mapping ) {
			$meta_key = $mapping['field_key'] ?? '';
			$label    = $mapping['label'] ?? '';
			$dest     = $mapping['destination'] ?? 'tag';

			if ( empty( $meta_key ) || $dest !== $destination ) {
				continue;
			}

			$value = $order->get_meta( $meta_key );

			if ( empty( $value ) || is_array( $value ) || is_object( $value ) || is_serialized( (string) $value ) ) {
				continue;
			}

			$value = (string) $value;

			if ( mb_strlen( $value ) > 200 ) {
				$value = mb_substr( $value, 0, 200 );
			}

			$resolved[] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return $resolved;
	}

	/**
	 * Hook handler: order trashed (HPOS).
	 *
	 * Schedules a cancelled status update to Bob Go when a synced order is trashed.
	 *
	 * @param int $order_id The order ID.
	 */
	public static function on_order_trashed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order_ref = $order->get_meta( '_bobgo_order_id' );
		if ( empty( $order_ref ) ) {
			return;
		}

		// Prevent duplicate scheduling.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bobgo_update_order_status', [ $order_id, 'cancelled' ] ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'bobgo_update_order_status', [ $order_id, 'cancelled' ], 'bobgo-shipping' );
		}
	}

	/**
	 * Hook handler: post trashed (legacy CPT).
	 *
	 * Filters by shop_order post type, then delegates to on_order_trashed().
	 *
	 * @param int $post_id The post ID.
	 */
	public static function on_post_trashed( int $post_id ): void {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		self::on_order_trashed( $post_id );
	}
}
