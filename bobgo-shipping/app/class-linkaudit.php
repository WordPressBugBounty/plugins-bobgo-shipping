<?php
/**
 * Link audit — one-shot self-heal of wrongly-linked Bob Go order references.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LinkAudit
 *
 * Background (Mondvol incident, 2026-07-08): a lookup bug attached webhooks
 * for CSV-imported Bob Go orders to unrelated shop orders. Affected orders
 * carried a stranger's Bob Go link and tracking data, were treated as already
 * synced (so they were never sent to Bob Go), and could be completed by the
 * stranger's delivery. The lookup is fixed in Webhook::resolve_order(); this
 * audit heals damage that already exists.
 *
 * It runs once per store, in paced Action Scheduler batches, and verifies for
 * every order with a stored _bobgo_order_id that the linked Bob Go order
 * actually references it:
 *
 * - Link confirmed → marked audited, untouched.
 * - Link wrong but the order's true Bob Go twin is found → relinked and its
 *   fulfillment data re-fetched (never re-sent, so no duplicate Bob Go orders).
 * - Link foreign on affirmative evidence (the stored Bob Go order was fetched
 *   and demonstrably references another order) → link and foreign tracking
 *   data removed; active orders are re-queued for outbound sync, all others
 *   are only flagged (merchants may have shipped manually in the meantime).
 * - Link unverifiable (no evidence ties the stored order to this one, but
 *   nothing affirmatively contradicts it either) → KEPT, marked audited:
 *   never destroy a link without affirmative foreign evidence. Absence of
 *   evidence includes a correct link whose order number drifted
 *   (sequential-order-number plugins) — unlinking it would re-send the order
 *   and create a duplicate on Bob Go (double-shipment risk), confirmed by
 *   backend duplicate detection requiring number AND ref AND channel to
 *   match.
 * - Verification inconclusive (API unreachable) → left untouched and retried.
 *
 * Everything is silent: internal debug/sync-log entries only, no order notes.
 */
class LinkAudit {

	use Loggable;

	/**
	 * Action Scheduler hook for one audit batch.
	 */
	const HOOK_BATCH = 'bobgo_link_audit_batch';

	/**
	 * Action Scheduler hook for one link-reset batch.
	 */
	const HOOK_RESET = 'bobgo_link_reset_batch';

	/**
	 * Option holding the reason for the reset pass currently in progress.
	 * Its presence is also what marks a pass as running.
	 */
	const OPTION_RESET_REASON = 'bobgo_link_reset_reason';

	/**
	 * Option holding whether the reset pass in progress should re-queue the
	 * orders it unlinks for outbound sync. True when the store moved to another
	 * Bob Go account (the orders belong on the new one); false when the store
	 * disconnected, where re-syncing is precisely what the merchant stopped.
	 */
	const OPTION_RESET_RESYNC = 'bobgo_link_reset_resync';

	/**
	 * Option holding the snapshot of order ids the reset pass still has to work
	 * through. Captured once when the pass starts so orders the pass re-syncs
	 * (and which therefore acquire a fresh link) are not selected a second time.
	 */
	const OPTION_RESET_QUEUE = 'bobgo_link_reset_queue';

	/**
	 * Option holding the highest order id the reset pass has snapshotted so far.
	 * A store with more linked orders than one snapshot holds continues from here,
	 * and because the bound only ever rises, the orders the pass re-linked on its
	 * way through cannot be picked up again — which is what makes continuing
	 * terminate instead of cycling.
	 */
	const OPTION_RESET_CURSOR = 'bobgo_link_reset_cursor';

	/**
	 * Option written when a full audit pass has completed. Holds the
	 * completion timestamp and outcome counts.
	 */
	const OPTION_DONE = 'bobgo_link_audit_done';

	/**
	 * Option accumulating outcome counts across batches while a pass runs.
	 */
	const OPTION_STATS = 'bobgo_link_audit_stats';

	/**
	 * Per-order marker (ISO-8601 timestamp) recording that the link was audited.
	 * Doubles as the pagination cursor: batches select orders without it.
	 */
	const META_AUDITED = '_bobgo_link_audited';

	/**
	 * Per-order record of a corrective action (JSON), for support visibility.
	 */
	const META_FLAG = '_bobgo_link_audit_flag';

	/**
	 * Orders verified per batch. Filterable via `bobgo_link_audit_batch_size`;
	 * a filtered value < 1 disables the audit.
	 */
	const DEFAULT_BATCH_SIZE = 20;

	/**
	 * Seconds between batches. Each order costs one to four API calls, so
	 * pacing keeps the audit invisible to store and API alike.
	 */
	const BATCH_INTERVAL = 60;

	/**
	 * Orders unlinked per reset batch. Far larger than the audit's because a reset
	 * makes no API call per order — it strips meta — so the pacing that keeps the
	 * audit invisible to the Bob Go API buys nothing here, and at the audit's size
	 * a sizeable order history would take hours to clear.
	 */
	const RESET_BATCH_SIZE = 100;

	/**
	 * Meta stripped when a foreign link is removed. Everything written on the
	 * back of the bad link: the link itself, the stranger's shipment/tracking
	 * data, and the sync markers that made the order look already-synced.
	 */
	const UNLINK_META_KEYS = [
		'_bobgo_order_id',
		'_bobgo_shipments',
		'_bobgo_last_webhook',
		'_bobgo_last_reconciled',
		'_bobgo_sync_status',
		'_bobgo_sync_hash',
		'_bobgo_last_synced',
		'_bobgo_fulfillment_status',
	];

	/**
	 * Schedule an audit pass if none has completed and none is queued.
	 *
	 * Called from the hourly reconciliation job (already gated on a connected
	 * API), which also makes it the retry path when a pass aborts because the
	 * API was unreachable.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( get_option( self::OPTION_DONE ) ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_BATCH ) ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK_BATCH, [], 'bobgo-shipping' );
		self::log( 'Link audit scheduled: verifying that stored Bob Go order links belong to their orders.', 'info' );
	}

	/**
	 * Ask Bob Go which account the stored key belongs to, and drop every stored
	 * link when it is not the account those links were created on.
	 *
	 * Called wherever the credentials are exercised deliberately — when the
	 * connection is saved, and once a day from the webhook health check. The
	 * daily pass is what records the account for stores that never revisit their
	 * connection settings, and what catches a switch made outside the plugin.
	 *
	 * @return string The verdict from Api::refresh_account_identity().
	 */
	public static function check_for_account_switch(): string {
		$verdict = Api::refresh_account_identity();

		if ( 'changed' !== $verdict ) {
			return $verdict;
		}

		if ( self::schedule_link_reset( 'store reconnected to a different Bob Go account' ) ) {
			return $verdict;
		}

		// The switch was recorded but the reset could not be queued (no Action
		// Scheduler). Leaving the new code stored would make every later check
		// report 'unchanged', so the store would keep links into an account it
		// cannot reach with nothing left to notice. Forget the code instead and
		// let the next check detect the switch again.
		Api::clear_account_code();
		self::log( 'Account switch detected but the link reset could not be queued; the account record was rolled back so the next check retries.', 'error' );

		return $verdict;
	}

	/**
	 * Queue removal of every stored Bob Go order link.
	 *
	 * Used when the links provably belong to a namespace this store can no
	 * longer reach — it was reconnected to a different Bob Go account, or
	 * disconnected altogether. Unlike the audit this needs no per-order evidence:
	 * the whole namespace is wrong, so there is nothing left to verify order by
	 * order.
	 *
	 * @param string $reason               Why the links are being removed; recorded per order.
	 * @param bool   $resync_active_orders Whether to re-queue the unlinked active orders for outbound sync.
	 * @return bool True when a pass is queued.
	 */
	public static function schedule_link_reset( string $reason, bool $resync_active_orders = true ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::log( 'Link reset requested but Action Scheduler is unavailable; stored links left in place.', 'error' );
			return false;
		}

		update_option( self::OPTION_RESET_REASON, $reason, false );
		update_option( self::OPTION_RESET_RESYNC, $resync_active_orders ? 'yes' : 'no', false );

		// Drop any snapshot a previous pass left behind, and the cursor with it, so
		// this request starts from the first linked order. Inheriting either would
		// silently skip every order the earlier pass had already reached —
		// including ones it re-synced, which now hold a fresh link this request is
		// supposed to remove.
		delete_option( self::OPTION_RESET_QUEUE );
		delete_option( self::OPTION_RESET_CURSOR );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_RESET ) ) {
			return true;
		}

		as_schedule_single_action( time(), self::HOOK_RESET, [], 'bobgo-shipping' );
		self::log( "Link reset scheduled ({$reason}): removing every stored Bob Go order link.", 'warning' );

		return true;
	}

	/**
	 * Re-arm an interrupted reset pass.
	 *
	 * The reason option is only cleared when a pass finishes, so its presence
	 * without a queued batch means the pass was cut short — a deactivation, or
	 * an Action Scheduler queue that was flushed.
	 *
	 * @return void
	 */
	public static function maybe_resume_reset(): void {
		if ( '' === (string) get_option( self::OPTION_RESET_REASON, '' ) ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_RESET ) ) {
			return;
		}

		as_schedule_single_action( time(), self::HOOK_RESET, [], 'bobgo-shipping' );
		self::log( 'Link reset resumed: an interrupted pass still has linked orders to clear.', 'warning' );
	}

	/**
	 * Action Scheduler callback: strip the link from one batch of orders.
	 *
	 * Works from a snapshot of order ids captured on the first batch, the same
	 * way the full reconciliation does. Selecting live on "still has a link"
	 * cannot terminate when the pass also re-queues active orders for sync: those
	 * syncs write a new link within seconds, so the next batch re-selected the
	 * same low-id orders, unlinked them again, and never reached anything further
	 * down the list. The snapshot makes each order's turn come exactly once.
	 *
	 * Active orders are re-queued for outbound sync so they are recreated on the
	 * current account; the rest are only unlinked, since merchants may already
	 * have shipped them by hand.
	 *
	 * @return void
	 */
	public static function handle_reset_batch(): void {
		$reason = (string) get_option( self::OPTION_RESET_REASON, '' );
		if ( '' === $reason ) {
			return;
		}

		$batch_size = (int) apply_filters( 'bobgo_link_reset_batch_size', self::RESET_BATCH_SIZE );
		if ( $batch_size < 1 ) {
			$batch_size = self::RESET_BATCH_SIZE;
		}

		$queue = get_option( self::OPTION_RESET_QUEUE, null );
		if ( ! is_array( $queue ) ) {
			$queue = self::build_reset_queue();
			update_option( self::OPTION_RESET_QUEUE, $queue, false );
			$total = count( $queue );
			self::log( "Link reset starting ({$reason}): {$total} linked orders queued.", 'info' );
		}

		if ( empty( $queue ) ) {
			self::finish_reset( $reason );
			return;
		}

		$slice  = array_slice( $queue, 0, $batch_size );
		$queue  = array_slice( $queue, count( $slice ) );
		$resync = 'no' !== (string) get_option( self::OPTION_RESET_RESYNC, 'yes' );

		// Persist the advanced queue before doing the work: a fatal mid-batch then
		// costs one batch rather than spinning on it forever.
		update_option( self::OPTION_RESET_QUEUE, $queue, false );

		$removed  = 0;
		$resynced = 0;
		foreach ( $slice as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$stored = (string) $order->get_meta( '_bobgo_order_id' );
			if ( '' === $stored ) {
				continue;
			}

			// Drop the audit marker too — whatever link this order acquires
			// next deserves verifying on its own merits.
			$order->delete_meta_data( self::META_AUDITED );
			self::unlink( $order, $stored, $reason );
			++$removed;

			if ( $resync && self::is_active( $order ) ) {
				self::schedule_resync( $order_id );
				++$resynced;
			}
		}

		$remaining = count( $queue );
		self::log( "Link reset batch: {$removed} links removed, {$resynced} orders re-queued for sync, {$remaining} remaining.", 'info' );

		if ( empty( $queue ) ) {
			self::finish_reset( $reason );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::BATCH_INTERVAL, self::HOOK_RESET, [], 'bobgo-shipping' );
		}
	}

	/**
	 * Wind up a drained pass — or carry it on, when one snapshot could not hold
	 * every linked order.
	 *
	 * A store past the snapshot cap would otherwise stop here with half its order
	 * history still linked to an account it cannot reach, and nothing to finish the
	 * job: the audit and reconciliation only look at orders that still carry a
	 * link, and there is no manual button. So ask for the next stretch, and only
	 * clear the pass when there is genuinely nothing left.
	 *
	 * @param string $reason The reason the pass ran, for the log line.
	 * @return void
	 */
	private static function finish_reset( string $reason ): void {
		$next = self::build_reset_queue();

		if ( ! empty( $next ) ) {
			update_option( self::OPTION_RESET_QUEUE, $next, false );

			$total = count( $next );
			self::log( "Link reset continuing ({$reason}): {$total} further linked orders queued.", 'info' );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::BATCH_INTERVAL, self::HOOK_RESET, [], 'bobgo-shipping' );
			}

			return;
		}

		delete_option( self::OPTION_RESET_REASON );
		delete_option( self::OPTION_RESET_RESYNC );
		delete_option( self::OPTION_RESET_QUEUE );
		delete_option( self::OPTION_RESET_CURSOR );

		self::log( "Link reset complete ({$reason}).", 'info' );
	}

	/**
	 * Cancel a reset pass that is queued or in flight.
	 *
	 * Reports whether the cancelled pass had already unlinked orders, because the
	 * two cases need different repairs. Part-way through, the order history is left
	 * split — some orders unlinked, the rest still pointing at the previous account
	 * — and only another pass can make it consistent. Cancelled before its first
	 * batch, nothing has been touched, and cancelling is the whole repair: starting
	 * a pass anyway would strip a history that was intact, and the re-sync only
	 * restores the active orders, so every completed order would lose its tracking
	 * for nothing.
	 *
	 * The queue snapshot is what tells them apart: it is written by the first batch
	 * and cleared whenever a pass is requested.
	 *
	 * @param string $why Why the pass is being cancelled, for the log line.
	 * @return bool True when the cancelled pass had already unlinked orders.
	 */
	public static function cancel_link_reset( string $why ): bool {
		$reason  = (string) get_option( self::OPTION_RESET_REASON, '' );
		$queued  = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_RESET );
		$started = false !== get_option( self::OPTION_RESET_QUEUE, false );

		if ( '' === $reason && ! $queued ) {
			return false;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_RESET );
		}

		delete_option( self::OPTION_RESET_REASON );
		delete_option( self::OPTION_RESET_RESYNC );
		delete_option( self::OPTION_RESET_QUEUE );
		delete_option( self::OPTION_RESET_CURSOR );

		$progress = $started ? 'orders had already been unlinked' : 'no orders had been unlinked yet';
		self::log( "Link reset cancelled ({$why}); it was running because: {$reason}; {$progress}.", 'warning' );

		return $started;
	}

	/**
	 * Snapshot the next stretch of orders that carry a Bob Go link.
	 *
	 * Bounded, because the snapshot lives in an option row. Where a store has more
	 * linked orders than one snapshot holds, the pass takes them in id order and
	 * records how far it reached, so finish_reset() can ask for the next stretch.
	 *
	 * Ids at or below the cursor are dropped rather than skipped by the query,
	 * which has no way to express an id range across both order datastores. They
	 * are the orders a previous stretch already unlinked and then re-synced, and
	 * they re-appear here holding the fresh link that sync gave them. Excluding
	 * them is what stops the pass from unlinking its own work over and over; the
	 * cost is that they take up room in the window, so a store that re-linked more
	 * orders than the cap in one stretch can leave the tail unreached. That is
	 * logged rather than retried, since retrying would be the cycle again.
	 *
	 * @return int[] Order ids, ordered by id, empty when nothing is left.
	 */
	private static function build_reset_queue(): array {
		$max    = (int) apply_filters( 'bobgo_link_reset_max_orders', 5000 );
		$cursor = (int) get_option( self::OPTION_RESET_CURSOR, 0 );

		$orders = OrderQuery::orders_with_meta(
			[
				'status'  => 'any',
				'limit'   => $max,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			],
			[
				[
					'key'     => '_bobgo_order_id',
					'compare' => 'EXISTS',
				],
			]
		);

		$found = array_values( array_map( 'intval', $orders ) );
		$ids   = array_values(
			array_filter(
				$found,
				static function ( $order_id ) use ( $cursor ) {
					return $order_id > $cursor;
				}
			)
		);

		if ( empty( $ids ) ) {
			if ( count( $found ) >= $max ) {
				self::log( "Link reset stopped short: the {$max}-order window is full of orders already reset and re-synced, so the rest cannot be reached this pass. Raise bobgo_link_reset_max_orders and disconnect or re-save the connection to finish.", 'warning' );
			}

			return [];
		}

		update_option( self::OPTION_RESET_CURSOR, max( $ids ), false );

		return $ids;
	}

	/**
	 * Action Scheduler callback: audit one batch of linked orders.
	 *
	 * Selects orders with a Bob Go link and no audited-marker (active orders
	 * first), verifies each, and chains the next batch. Restart-safe: the
	 * marker is the cursor, so an interrupted pass resumes where it stopped.
	 *
	 * @return void
	 */
	public static function handle_batch(): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		$batch_size = (int) apply_filters( 'bobgo_link_audit_batch_size', self::DEFAULT_BATCH_SIZE );
		if ( $batch_size < 1 ) {
			self::log( 'Link audit disabled via bobgo_link_audit_batch_size filter.', 'info' );
			return;
		}

		$orders = self::next_batch( $batch_size );

		if ( empty( $orders ) ) {
			self::finish();
			return;
		}

		$outcomes = [];
		foreach ( $orders as $order ) {
			$outcome              = self::audit_order( $order );
			$outcomes[ $outcome ] = ( $outcomes[ $outcome ] ?? 0 ) + 1;

			if ( 'inconclusive' !== $outcome ) {
				$order->update_meta_data( self::META_AUDITED, gmdate( 'c' ) );
				$order->save();
			}
		}

		self::accumulate_stats( $outcomes );
		self::log( 'Link audit batch: ' . wp_json_encode( $outcomes ), 'info' );

		// Every order in the batch was inconclusive (API down): stop chaining
		// instead of spinning on the same orders. The next hourly
		// reconciliation reschedules the pass.
		$inconclusive = $outcomes['inconclusive'] ?? 0;
		if ( count( $orders ) === $inconclusive ) {
			self::log( 'Link audit paused: entire batch was inconclusive (API unreachable?). Will retry on the next reconciliation run.', 'warning' );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::BATCH_INTERVAL, self::HOOK_BATCH, [], 'bobgo-shipping' );
		}
	}

	/**
	 * Select the next orders to audit: linked, not yet audited, active first.
	 *
	 * @param int $batch_size Maximum orders to return.
	 * @return \WC_Order[]
	 */
	private static function next_batch( int $batch_size ): array {
		$meta_query = [
			'relation' => 'AND',
			[
				'key'     => '_bobgo_order_id',
				'compare' => 'EXISTS',
			],
			[
				'key'     => self::META_AUDITED,
				'compare' => 'NOT EXISTS',
			],
		];

		$orders = OrderQuery::orders_with_meta(
			[
				'status'  => Reconciliation::ACTIVE_STATUSES,
				'limit'   => $batch_size,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			],
			$meta_query
		);

		$orders = array_values(
			array_filter(
				$orders,
				static fn( $order ) => $order instanceof \WC_Order
			)
		);

		if ( count( $orders ) < $batch_size ) {
			$seen = [];
			foreach ( $orders as $order ) {
				$seen[ $order->get_id() ] = true;
			}

			// Fill with non-active linked orders (completed, cancelled, …).
			// No status arg: wc_get_orders defaults to all registered
			// statuses, so the active orders taken above can reappear here —
			// dropped in PHP rather than with an exclusionary query param
			// (a slow-query pattern flagged by Plugin Check). The first query
			// returning under batch_size means it exhausted the active set,
			// so at most count($orders) rows here are duplicates and a full
			// batch_size fetch always covers the remainder.
			$rest = OrderQuery::orders_with_meta(
				[
					'limit'   => $batch_size,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				],
				$meta_query
			);

			foreach ( $rest as $order ) {
				if ( count( $orders ) >= $batch_size ) {
					break;
				}
				if ( $order instanceof \WC_Order && ! isset( $seen[ $order->get_id() ] ) ) {
					$orders[]                 = $order;
					$seen[ $order->get_id() ] = true;
				}
			}
		}

		return $orders;
	}

	/**
	 * Verify one order's stored Bob Go link and correct it when it is foreign.
	 *
	 * Verification never trusts data the bad link itself wrote (the stored
	 * tracking reference resolves back to the foreign order — circular).
	 * Instead it asks three independent questions:
	 *
	 * A. Which Bob Go order claims THIS order's number? (v2/orders lookup by
	 *    channel_order_number, requiring exactly one match, mirroring the
	 *    webhook link-resolution semantics.)
	 * B. Does the stored Bob Go order itself reference this order? (Fetched by
	 *    id; confirmed via channel_ref_id round-trip, order number, or
	 *    customer email — see stored_order_reference(). Absence of a match is
	 *    NOT foreign on its own: sequential-order-number plugins rewrite
	 *    get_order_number() after sync, so a correct link can stop matching by
	 *    number, and unlinking + re-sending it would create a duplicate Bob Go
	 *    order.)
	 * C. Do the stored link's fulfillments reference THIS order's line items?
	 *    (v2/order-fulfillments; item channel_ref_ids are WC line-item ids for
	 *    orders pushed by a WooCommerce channel. Item evidence can only prove
	 *    a link FOREIGN, never confirm one: item ids are per-store
	 *    auto-increments and the endpoint is account-scoped, so a colliding
	 *    id from another store would otherwise permanently confirm a foreign
	 *    link. Only consulted when B was not already affirmative.)
	 *
	 * Only affirmative evidence acts: a link is confirmed by A or B, and
	 * unlinked (re-queued for sync only while active and twin-less) on
	 * affirmative foreign evidence from B or C. Everything in between is kept
	 * as is — a duplicate Bob Go order means a possible double shipment, and
	 * an unlinked-but-correct order silently stops syncing, both worse than a
	 * link awaiting the next affirmative signal.
	 *
	 * @param \WC_Order $order The linked order.
	 * @return string Outcome: confirmed | relinked | unlinked_resync |
	 *                unlinked_flagged | kept_unverified | inconclusive | skipped.
	 */
	public static function audit_order( \WC_Order $order ): string {
		$stored = (int) $order->get_meta( '_bobgo_order_id' );
		if ( $stored <= 0 ) {
			// Non-numeric residue can't be queried; strip it so the order can sync.
			self::unlink( $order, (string) $order->get_meta( '_bobgo_order_id' ), 'non-numeric link' );
			return 'skipped';
		}

		// A: which Bob Go order claims this order's number?
		$twin = self::find_verified_twin( $order );

		if ( 'error' === $twin['status'] ) {
			return 'inconclusive';
		}

		if ( 'found' === $twin['status'] && $twin['id'] === $stored ) {
			return 'confirmed';
		}

		if ( 'found' === $twin['status'] && $twin['verified'] ) {
			self::relink( $order, $stored, $twin['id'] );
			return 'relinked';
		}

		// B: does the stored Bob Go order itself reference this order?
		$fetched = self::fetch_stored_order( $stored );

		if ( 'error' === $fetched['status'] ) {
			return 'inconclusive';
		}

		$reference = 'found' === $fetched['status']
			? self::stored_order_reference( $order, $fetched['order'] )
			: 'unavailable';

		if ( in_array( $reference, [ 'ref', 'number', 'email' ], true ) ) {
			return 'confirmed';
		}

		if ( 'contested' === $reference ) {
			// channel_ref_id round-trips, but a WC order id is only unique per
			// store — and both the number and the customer contradict. Likely
			// an order-id collision from another store on this account; refuse
			// to confirm it permanently, refuse to destroy it on a guess.
			self::log( "Link audit: order {$order->get_id()} link {$stored} round-trips by channel_ref_id but its order number and customer email both contradict this order (order-id collision from another store on this account?); leaving as is.", 'warning' );
			return 'kept_unverified';
		}

		$foreign = 'foreign' === $reference || 'mismatch' === $reference;

		// C: item evidence can only prove the link foreign, never confirm it
		// (colliding per-store item ids). Skipped when B already decided.
		if ( ! $foreign ) {
			$ownership = self::stored_link_ownership( $order, $stored );

			if ( 'error' === $ownership ) {
				return 'inconclusive';
			}

			$foreign = 'foreign' === $ownership;
		}

		if ( 'ambiguous' === $twin['status'] ) {
			// Multiple Bob Go orders share this order's number (dual ingestion)
			// and none disproves the stored link — refuse to guess.
			self::log( "Link audit: order {$order->get_id()} link {$stored} could not be verified (ambiguous twins); leaving as is.", 'warning' );
			return 'kept_unverified';
		}

		if ( ! $foreign ) {
			// Nothing ties the stored order to this one, nothing contradicts it
			// either (e.g. a legacy link whose number drifted and that has no
			// fulfillments yet). Never destroy a link without affirmative
			// foreign evidence: an unfetchable link's refresh writes nothing,
			// while unlinking a correct one silently stops its sync — or worse,
			// re-sends it as a duplicate.
			self::log( "Link audit: order {$order->get_id()} link {$stored} could not be verified (no affirmative evidence either way); leaving as is.", 'warning' );
			return 'kept_unverified';
		}

		if ( 'foreign' === $reference ) {
			$reason = 'foreign link; stored order channel_ref_id names another WC order';
		} elseif ( 'mismatch' === $reference ) {
			$reason = 'foreign link; stored order number and customer email both contradict this order';
		} else {
			$reason = "foreign link; stored order's fulfillments reference other line items";
		}
		if ( 'found' === $twin['status'] ) {
			$reason .= "; unverified twin candidate {$twin['id']}";
		}

		self::unlink( $order, (string) $stored, $reason );

		// Re-send only when no twin candidate exists (an unverified candidate
		// means re-sending risks a duplicate) and the order is still active
		// (merchants may have fulfilled non-active orders manually meanwhile).
		if ( 'found' !== $twin['status'] && self::is_active( $order ) ) {
			self::schedule_resync( $order->get_id() );
			return 'unlinked_resync';
		}

		return 'unlinked_flagged';
	}

	/**
	 * B: fetch the stored Bob Go order by id.
	 *
	 * Uses the list endpoint's id filter — v2/orders/{id} is not a registered
	 * backend route. The by-id branch is account-scoped and deliberately NOT
	 * channel-scoped server-side, which is what the evidence model needs:
	 * legacy-channel orders and manual imports on the same account must be
	 * fetchable so they can confirm or contradict the link.
	 *
	 * @param int $bobgo_id The stored Bob Go order id.
	 * @return array{status: string, order: array<string, mixed>}
	 *         status: found | missing | error. `missing` (no order carries the
	 *         id, or the body was unrecognisable) degrades to the remaining
	 *         evidence instead of blocking the audit; `error` (transport)
	 *         pauses and retries.
	 */
	private static function fetch_stored_order( int $bobgo_id ): array {
		$result = Api::get( 'v2/orders?id=' . rawurlencode( (string) $bobgo_id ) );

		if ( ! $result['success'] ) {
			return [
				'status' => 404 === $result['status'] ? 'missing' : 'error',
				'order'  => [],
			];
		}

		$body   = is_array( $result['body'] ) ? $result['body'] : [];
		$orders = $body['orders'] ?? null;

		if ( is_array( $orders ) ) {
			// The candidate must echo the requested id to count.
			foreach ( $orders as $candidate ) {
				if ( is_array( $candidate ) && (int) ( $candidate['id'] ?? 0 ) === $bobgo_id ) {
					return [
						'status' => 'found',
						'order'  => $candidate,
					];
				}
			}

			// The API answered with a list and no order carries this id:
			// deleted, or not visible to this account.
			return [
				'status' => 'missing',
				'order'  => [],
			];
		}

		// Tolerate a bare-object shape in case the endpoint evolves.
		if ( (int) ( $body['id'] ?? 0 ) === $bobgo_id ) {
			return [
				'status' => 'found',
				'order'  => $body,
			];
		}

		self::log( "Link audit: v2/orders?id={$bobgo_id} returned an unrecognisable body; treating the stored order as unavailable for evidence.", 'warning' );

		return [
			'status' => 'missing',
			'order'  => [],
		];
	}

	/**
	 * B: how the fetched stored Bob Go order relates to this WC order.
	 *
	 * @param \WC_Order            $order       The order.
	 * @param array<string, mixed> $bobgo_order The fetched Bob Go order.
	 * @return string ref | number | email — the signal confirming the link;
	 *                foreign — its channel_ref_id names another WC order;
	 *                contested — channel_ref_id matches but order number AND
	 *                customer email both contradict; mismatch — no
	 *                channel_ref_id and number AND email both contradict;
	 *                unknown — nothing decisive either way.
	 */
	private static function stored_order_reference( \WC_Order $order, array $bobgo_order ): string {
		$channel_ref = trim( (string) ( $bobgo_order['channel_ref_id'] ?? '' ) );

		$own_number      = self::normalize_order_number( (string) $order->get_order_number() );
		$their_number    = self::normalize_order_number( (string) ( $bobgo_order['channel_order_number'] ?? '' ) );
		$number_match    = '' !== $own_number && $own_number === $their_number;
		$number_mismatch = '' !== $own_number && '' !== $their_number && ! $number_match;

		$customer       = is_array( $bobgo_order['customer'] ?? null ) ? $bobgo_order['customer'] : [];
		$own_email      = strtolower( trim( (string) $order->get_billing_email() ) );
		$their_email    = strtolower( trim( (string) ( $bobgo_order['customer_email'] ?? $customer['email'] ?? '' ) ) );
		$email_match    = '' !== $own_email && $own_email === $their_email;
		$email_mismatch = '' !== $own_email && '' !== $their_email && ! $email_match;

		if ( '' !== $channel_ref ) {
			if ( $channel_ref !== (string) $order->get_id() ) {
				return 'foreign';
			}

			return ( $number_mismatch && $email_mismatch ) ? 'contested' : 'ref';
		}

		if ( $number_match ) {
			return 'number';
		}

		if ( $email_match ) {
			return 'email';
		}

		return ( $number_mismatch && $email_mismatch ) ? 'mismatch' : 'unknown';
	}

	/**
	 * Normalise an order number for comparison: '#'-prefix and case are
	 * presentation, not identity (some channels store the number prefixed).
	 *
	 * @param string $number Order number.
	 * @return string
	 */
	private static function normalize_order_number( string $number ): string {
		return strtolower( ltrim( trim( $number ), '#' ) );
	}

	/**
	 * A: find the Bob Go order that claims this WC order's number.
	 *
	 * Also used by OrderSync before creating an order, so that an order which
	 * already exists on Bob Go is linked rather than duplicated.
	 *
	 * The number is sent exactly as WooCommerce reports it, and only that.
	 * Bob Go never prepends or appends anything to a channel order number — it
	 * stores what the channel sent — so speculatively retrying a '#'-prefixed
	 * variant asks for a number no WooCommerce store produced, and any match it
	 * returned would be a different order.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{status: string, id: int, verified: bool}
	 *         status: found | none | ambiguous | error. When found, `verified`
	 *         is true when the match's channel_ref_id round-trips to this order.
	 */
	public static function find_verified_twin( \WC_Order $order ): array {
		$number = trim( (string) $order->get_order_number() );
		if ( '' === $number ) {
			return [
				'status'   => 'none',
				'id'       => 0,
				'verified' => false,
			];
		}

		$none = [
			'status'   => 'none',
			'id'       => 0,
			'verified' => false,
		];

		$result = Api::get( 'v2/orders?channel_order_number=' . rawurlencode( $number ) );
		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			return [
				'status'   => 'error',
				'id'       => 0,
				'verified' => false,
			];
		}

		$matches = $result['body']['orders'] ?? [];
		if ( ! is_array( $matches ) || empty( $matches ) ) {
			return $none;
		}

		if ( count( $matches ) > 1 ) {
			// If one of the twins IS the stored link, audit_order() treats a
			// 'found' equal id as confirmed — check for it before giving up.
			$stored = (int) $order->get_meta( '_bobgo_order_id' );
			foreach ( $matches as $match ) {
				if ( is_array( $match ) && (int) ( $match['id'] ?? 0 ) === $stored && $stored > 0 ) {
					return [
						'status'   => 'found',
						'id'       => $stored,
						'verified' => false,
					];
				}
			}
			return [
				'status'   => 'ambiguous',
				'id'       => 0,
				'verified' => false,
			];
		}

		$match = $matches[0];
		if ( ! is_array( $match ) || ! is_numeric( $match['id'] ?? null ) ) {
			return $none;
		}

		$channel_ref = trim( (string) ( $match['channel_ref_id'] ?? '' ) );
		if ( '' !== $channel_ref && $channel_ref !== (string) $order->get_id() ) {
			// The number maps to a Bob Go order belonging to a different WC
			// order — no claim on this one.
			return $none;
		}

		return [
			'status'   => 'found',
			'id'       => (int) $match['id'],
			'verified' => '' !== $channel_ref,
		];
	}

	/**
	 * C: whether the stored link's fulfillments reference this order's items.
	 *
	 * Item channel_ref_ids are per-store auto-increment WC line-item ids and
	 * the endpoint is account-scoped, so audit_order() only ever acts on the
	 * 'foreign' outcome — an 'own' match may be a cross-store collision and
	 * never confirms a link by itself.
	 *
	 * @param \WC_Order $order    The order.
	 * @param int       $bobgo_id The stored Bob Go order id.
	 * @return string own | foreign | unknown | error.
	 */
	private static function stored_link_ownership( \WC_Order $order, int $bobgo_id ): string {
		$result = Api::get( 'v2/order-fulfillments?order_id=' . rawurlencode( (string) $bobgo_id ) );

		if ( ! $result['success'] ) {
			// A 404 is conclusive (no such Bob Go order / no fulfillments
			// visible); anything else is a transport problem.
			return 404 === $result['status'] ? 'unknown' : 'error';
		}

		$fulfillments = is_array( $result['body'] ) ? ( $result['body']['order_fulfillments'] ?? [] ) : [];
		if ( ! is_array( $fulfillments ) || empty( $fulfillments ) ) {
			return 'unknown';
		}

		$own_item_ids = [];
		foreach ( $order->get_items() as $item ) {
			$own_item_ids[ (int) $item->get_id() ] = true;
		}

		$saw_channel_item = false;
		foreach ( $fulfillments as $entry ) {
			$of    = is_array( $entry ) ? ( $entry['order_fulfillment'] ?? $entry ) : [];
			$items = is_array( $of ) ? ( $of['items'] ?? [] ) : [];
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $fulfillment_item ) {
				if ( ! is_array( $fulfillment_item ) ) {
					continue;
				}
				$order_item     = is_array( $fulfillment_item['order_item'] ?? null ) ? $fulfillment_item['order_item'] : [];
				$channel_ref_id = (int) ( $fulfillment_item['channel_ref_id'] ?? $order_item['channel_ref_id'] ?? 0 );

				if ( $channel_ref_id > 0 ) {
					if ( isset( $own_item_ids[ $channel_ref_id ] ) ) {
						return 'own';
					}
					$saw_channel_item = true;
				}
			}
		}

		return $saw_channel_item ? 'foreign' : 'unknown';
	}

	/**
	 * Remove a foreign link and everything written on the back of it.
	 *
	 * Deliberately silent — internal flag meta and sync-log row only, no order
	 * note. The order's status is not touched: merchants may have acted on it
	 * (e.g. shipped manually) and an automated regression would be worse.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $stored The stored (bad) Bob Go id, for the record.
	 * @param string    $reason Why the link was removed.
	 * @return void
	 */
	private static function unlink( \WC_Order $order, string $stored, string $reason ): void {
		foreach ( self::UNLINK_META_KEYS as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}

		$order->update_meta_data(
			self::META_FLAG,
			wp_json_encode(
				[
					'action'     => 'unlinked',
					'bobgo_id'   => $stored,
					'reason'     => $reason,
					'audited_at' => gmdate( 'c' ),
				]
			)
		);
		$order->save();

		// Not "foreign link" or "audit": the reset pass shares this method, and on a
		// disconnect the link was this store's own and no audit ran. The reason says
		// which it was.
		self::log( "Removed Bob Go order link {$stored} from order {$order->get_id()} ({$reason}).", 'warning' );

		SyncLog::insert(
			[
				'wc_order_id' => $order->get_id(),
				'event_type'  => 'link_audit',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'action'   => 'unlinked',
						'bobgo_id' => $stored,
						'reason'   => $reason,
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);
	}

	/**
	 * Replace a wrong link with the verified one and re-fetch real fulfillment
	 * data. Used when the order's true Bob Go twin was found — re-sending is
	 * never needed, so no duplicate Bob Go order can be created.
	 *
	 * @param \WC_Order $order  The order.
	 * @param int       $stored The stored (bad) Bob Go id.
	 * @param int       $verified_id The verified Bob Go id.
	 * @return void
	 */
	private static function relink( \WC_Order $order, int $stored, int $verified_id ): void {
		// Drop the foreign tracking data; the refresh below rebuilds it from
		// the verified order. Sync status/hash are left alone — they describe
		// this order's own outbound history, which remains true.
		foreach ( [ '_bobgo_shipments', '_bobgo_last_webhook', '_bobgo_last_reconciled', '_bobgo_fulfillment_status' ] as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}

		$order->update_meta_data( '_bobgo_order_id', (string) $verified_id );
		// Fills the gap only. An order that arrived here already carrying an
		// item fingerprint keeps it, per the note above.
		OrderSync::ensure_items_fingerprint( $order );
		$order->update_meta_data(
			self::META_FLAG,
			wp_json_encode(
				[
					'action'     => 'relinked',
					'old_id'     => $stored,
					'new_id'     => $verified_id,
					'audited_at' => gmdate( 'c' ),
				]
			)
		);
		$order->save();

		self::log( "Link audit: relinked order {$order->get_id()} from foreign Bob Go id {$stored} to verified id {$verified_id}.", 'warning' );

		SyncLog::insert(
			[
				'wc_order_id' => $order->get_id(),
				'event_type'  => 'link_audit',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'action' => 'relinked',
						'old_id' => $stored,
						'new_id' => $verified_id,
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);

		Webhook::refresh_fulfillment_data( $order, (string) $verified_id );
	}

	/**
	 * Whether the order is still active (may be re-sent to Bob Go).
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private static function is_active( \WC_Order $order ): bool {
		return in_array( $order->get_status(), Reconciliation::ACTIVE_STATUSES, true );
	}

	/**
	 * Queue the standard outbound sync for an order whose link was removed.
	 * handle_sync_order() re-checks all its own guards (status allowlist,
	 * empty-link, connectivity), so this only enqueues.
	 *
	 * @param int $order_id The order id.
	 * @return void
	 */
	private static function schedule_resync( int $order_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bobgo_sync_order', [ $order_id ] ) ) {
			return;
		}

		as_schedule_single_action( time(), 'bobgo_sync_order', [ $order_id ], 'bobgo-shipping' );
		// Neither "audit" nor "foreign link": the reset pass shares this, where the
		// link was the store's own and no audit ran. Both callers already log which
		// removal this follows.
		self::log( "Order {$order_id} re-queued for outbound sync after its Bob Go link was removed.", 'info' );
	}

	/**
	 * Merge one batch's outcome counts into the running stats option.
	 *
	 * @param array<string, int> $outcomes Outcome => count for this batch.
	 * @return void
	 */
	private static function accumulate_stats( array $outcomes ): void {
		$stats = get_option( self::OPTION_STATS, [] );
		$stats = is_array( $stats ) ? $stats : [];

		foreach ( $outcomes as $outcome => $count ) {
			$stats[ $outcome ] = ( $stats[ $outcome ] ?? 0 ) + $count;
		}

		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * Record completion of the pass and stop the chain.
	 *
	 * @return void
	 */
	private static function finish(): void {
		$stats = get_option( self::OPTION_STATS, [] );
		$stats = is_array( $stats ) ? $stats : [];

		update_option(
			self::OPTION_DONE,
			[
				'completed_at' => gmdate( 'c' ),
				'stats'        => $stats,
			],
			false
		);
		delete_option( self::OPTION_STATS );

		self::log( 'Link audit complete: ' . ( empty( $stats ) ? 'no linked orders required verification.' : wp_json_encode( $stats ) ), 'info' );

		SyncLog::insert(
			[
				'wc_order_id' => 0,
				'event_type'  => 'link_audit',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'action' => 'complete',
						'stats'  => $stats,
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);
	}
}
