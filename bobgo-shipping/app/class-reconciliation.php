<?php
/**
 * Reconciliation engine — hourly safety net for missed webhooks + sync log maintenance.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reconciliation
 *
 * Schedules and handles recurring reconciliation and sync log purge jobs.
 */
class Reconciliation {

	use Loggable;

	/**
	 * Action Scheduler hook for reconciliation.
	 */
	const HOOK_RECONCILIATION = 'bobgo_reconciliation';

	/**
	 * Action Scheduler hook for sync log purge.
	 */
	const HOOK_PURGE = 'bobgo_purge_sync_log';

	/**
	 * Action Scheduler hook for a single full-reconciliation batch.
	 */
	const HOOK_FULL_RECONCILIATION_BATCH = 'bobgo_full_reconciliation_batch';

	/**
	 * Option key for last reconciliation timestamp.
	 */
	const OPTION_LAST_RECONCILIATION = 'bobgo_last_reconciliation';

	/**
	 * Active order statuses used for reconciliation queries.
	 */
	const ACTIVE_STATUSES = [ 'processing', 'on-hold', 'shipped', 'partially-shipped' ];

	/**
	 * Transient key holding the snapshot of order IDs queued for the
	 * currently-running full reconciliation pass.
	 */
	const TRANSIENT_FULL_RECON_QUEUE = 'bobgo_full_reconciliation_queue';

	/**
	 * Hard cap on how many orders a single full reconciliation run will queue.
	 * Filterable via `bobgo_reconciliation_max_orders`.
	 */
	const FULL_RECON_MAX_ORDERS = 100000;

	/**
	 * Schedule recurring reconciliation (hourly).
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK_RECONCILIATION ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK_RECONCILIATION, [], 'bobgo-shipping' );
		}
	}

	/**
	 * Schedule recurring sync log purge (daily).
	 */
	public static function schedule_purge(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK_PURGE ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_PURGE, [], 'bobgo-shipping' );
		}
	}

	/**
	 * Unschedule all recurring jobs.
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_RECONCILIATION );
		as_unschedule_all_actions( self::HOOK_PURGE );
		as_unschedule_all_actions( self::HOOK_FULL_RECONCILIATION_BATCH );

		delete_transient( self::TRANSIENT_FULL_RECON_QUEUE );
	}

	/**
	 * Handle hourly reconciliation.
	 * Queries active local orders and refreshes fulfillment data from Bob Go.
	 */
	public static function handle_reconciliation(): void {
		// Defensive sweep: remove any legacy v3-era WC webhooks that reappeared
		// (e.g. re-provisioned by the backend over the WC REST API). They loop
		// order updates, so they must die even while the v4 API connection is
		// down — hence before the is_connected bail-out.
		Activation::cleanup_legacy_webhooks();

		if ( ! Api::is_connected() ) {
			return;
		}

		// Verify the Bob Go webhook subscriptions are still active and
		// re-register them if the backend disabled them (rate-limited to one
		// conclusive check per day inside the method).
		Webhook::maybe_check_subscription_health();

		// Kick off the one-shot link audit if it has never completed on this
		// store (also the retry path when a pass aborted on an unreachable API).
		LinkAudit::maybe_schedule();

		// Pick a link reset back up if one was interrupted (deactivation, a
		// cleared Action Scheduler queue) while orders still hold their links.
		LinkAudit::maybe_resume_reset();

		self::run_reconciliation();
	}

	/**
	 * Kick off a full reconciliation.
	 * Used by the "Force Full Reconciliation" admin button.
	 *
	 * Schedules the first paginated batch via Action Scheduler; subsequent
	 * batches chain themselves until exhausted. Avoids loading the full active
	 * order set into a single request.
	 *
	 * @return bool True if a run is queued (or already in flight), false if not connected
	 *              or Action Scheduler is unavailable.
	 */
	public static function handle_full_reconciliation(): bool {
		if ( ! Api::is_connected() ) {
			return false;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		// Avoid stacking duplicate runs if the button is clicked twice.
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK_FULL_RECONCILIATION_BATCH ) ) {
			self::log( 'Full reconciliation already in flight; new request ignored.', 'info' );
			return true;
		}

		$batch_size = (int) apply_filters( 'bobgo_reconciliation_batch_size', 50 );

		as_schedule_single_action(
			time(),
			self::HOOK_FULL_RECONCILIATION_BATCH,
			[ 0, $batch_size ],
			'bobgo-shipping'
		);

		self::log( "Full reconciliation queued (batch size {$batch_size}).", 'info' );

		SyncLog::insert(
			[
				'wc_order_id' => 0,
				'event_type'  => 'reconciliation',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'type'       => 'full',
						'status'     => 'queued',
						'batch_size' => $batch_size,
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);

		return true;
	}

	/**
	 * Action Scheduler callback: process one batch of the full reconciliation.
	 *
	 * Each invocation reconciles one page of active orders ordered by ID, then
	 * — if the batch was full — schedules the next batch a few seconds later.
	 * This keeps memory bounded and lets AS handle retries/visibility.
	 *
	 * @param int $offset     Starting offset for this batch.
	 * @param int $batch_size Number of orders to process in this batch.
	 */
	public static function handle_full_reconciliation_batch( int $offset, int $batch_size ): void {
		if ( ! Api::is_connected() ) {
			return;
		}

		$batch_size = max( 1, $batch_size );

		// Build the snapshot on the first batch so subsequent batches can't
		// skip orders that were promoted out of ACTIVE_STATUSES (e.g. moved
		// to completed) by an earlier batch's webhook handling.
		if ( 0 === $offset ) {
			$snapshot = self::build_full_reconciliation_snapshot();
			set_transient( self::TRANSIENT_FULL_RECON_QUEUE, $snapshot, 6 * HOUR_IN_SECONDS );
		} else {
			$snapshot = get_transient( self::TRANSIENT_FULL_RECON_QUEUE );
			if ( ! is_array( $snapshot ) ) {
				self::log( 'Full reconciliation snapshot expired; aborting run.', 'warning' );
				return;
			}
		}

		$slice      = array_slice( $snapshot, $offset, $batch_size );
		$count      = count( $slice );
		$total      = count( $snapshot );
		$reconciled = 0;

		foreach ( $slice as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$order_ref = $order->get_meta( '_bobgo_order_id' );
			if ( empty( $order_ref ) ) {
				continue;
			}

			$changed = false;
			$success = Webhook::refresh_fulfillment_data( $order, $order_ref, $changed );
			// Advance the marker even on failure so one persistently-failing order
			// can't monopolise the batch; the fairness queue keeps cycling.
			$order->update_meta_data( '_bobgo_last_reconciled', gmdate( 'c' ) );
			$order->save();

			// Count only orders that actually changed, so the "N updated" log/payload
			// is no longer inflated by no-op refreshes. See QA #7.
			if ( $success && $changed ) {
				++$reconciled;
			}
		}

		$next_offset = $offset + $batch_size;

		// More orders queued — schedule the next batch.
		if ( $next_offset < $total && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 5,
				self::HOOK_FULL_RECONCILIATION_BATCH,
				[ $next_offset, $batch_size ],
				'bobgo-shipping'
			);
			self::log( "Full reconciliation batch @offset {$offset}: {$reconciled}/{$count} updated; next batch scheduled.", 'info' );
			return;
		}

		// Final batch — record completion and drop the snapshot.
		delete_transient( self::TRANSIENT_FULL_RECON_QUEUE );
		update_option( self::OPTION_LAST_RECONCILIATION, gmdate( 'c' ), false );

		self::log( "Full reconciliation finished at offset {$offset}; final batch {$reconciled}/{$count} updated.", 'info' );

		SyncLog::insert(
			[
				'wc_order_id' => 0,
				'event_type'  => 'reconciliation',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'type'   => 'full',
						'status' => 'complete',
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);
	}

	/**
	 * Handle daily sync log purge.
	 */
	public static function handle_purge(): void {
		$deleted = SyncLog::purge_older_than( 30 );

		if ( $deleted > 0 ) {
			self::log( "Purged {$deleted} sync log entries older than 30 days.", 'info' );
		}
	}

	/**
	 * Build the ordered list of order IDs to process in a full reconciliation run.
	 *
	 * Capturing this once up front prevents pagination skips when batch
	 * processing promotes orders out of ACTIVE_STATUSES.
	 *
	 * @return int[] Order IDs ordered by ID ASC.
	 */
	private static function build_full_reconciliation_snapshot(): array {
		$max = (int) apply_filters( 'bobgo_reconciliation_max_orders', self::FULL_RECON_MAX_ORDERS );

		$ids = wc_get_orders(
			[
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key' => '_bobgo_order_id',
				'status'   => self::ACTIVE_STATUSES,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'limit'    => $max,
				'return'   => 'ids',
			]
		);

		if ( ! is_array( $ids ) ) {
			return [];
		}

		// wc_get_orders() is typed as returning WC_Order[]|stdClass[]|int[] depending on
		// the 'return' arg; PHPStan can't narrow on the string literal. With 'return' => 'ids'
		// the runtime values are always integers — assert that to PHPStan.
		/**
		 * Narrow the wc_get_orders() return shape for the static analyser.
		 *
		 * @phpstan-var int[] $ids
		 */
		return array_values( $ids );
	}

	/**
	 * Select the orders for an incremental reconciliation run.
	 *
	 * Prioritises fairness so no active order starves: never-reconciled orders come
	 * first, then the least-recently-reconciled. This replaces ordering by the WC
	 * `modified` date, which an order edited often by other processes could keep
	 * pushing to the back of the queue so it was never reconciled. See QA #9.
	 *
	 * @param int $batch_size Maximum number of orders to return.
	 * @return \WC_Order[] Orders to reconcile this run.
	 */
	private static function get_incremental_reconciliation_orders( int $batch_size ): array {
		$batch_size = max( 1, $batch_size );

		// Phase 1: orders that have never been reconciled (no marker yet).
		// Routed through OrderQuery — the legacy CPT datastore silently drops a
		// raw meta_query arg, which filled this batch with unlinked orders and
		// starved the linked ones on non-HPOS stores.
		$never = OrderQuery::orders_with_meta(
			[
				'status' => self::ACTIVE_STATUSES,
				'limit'  => $batch_size,
				'return' => 'objects',
			],
			[
				'relation' => 'AND',
				[
					'key'     => '_bobgo_order_id',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_bobgo_last_reconciled',
					'compare' => 'NOT EXISTS',
				],
			]
		);

		if ( count( $never ) >= $batch_size ) {
			return array_slice( $never, 0, $batch_size );
		}

		// Phase 2: fill the remaining slots with the least-recently-reconciled.
		// _bobgo_last_reconciled is an ISO-8601 timestamp, so meta_value ASC sorts
		// chronologically (oldest first). The meta_key powers both the existence
		// filter and the ordering (supported on both datastores); the order-ref
		// filter goes through OrderQuery for the same reason as phase 1.
		$oldest = OrderQuery::orders_with_meta(
			[
				'status'   => self::ACTIVE_STATUSES,
				'limit'    => $batch_size - count( $never ),
				'return'   => 'objects',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key' => '_bobgo_last_reconciled',
				'orderby'  => 'meta_value',
				'order'    => 'ASC',
			],
			[
				[
					'key'     => '_bobgo_order_id',
					'compare' => 'EXISTS',
				],
			]
		);

		return array_merge( $never, $oldest );
	}

	/**
	 * Run the incremental reconciliation process.
	 *
	 * Selects active orders with a Bob Go order ref, prioritised by least-recently
	 * reconciled (never-reconciled first), and refreshes fulfillment data from the
	 * API.
	 */
	private static function run_reconciliation(): void {
		$batch_size = (int) apply_filters( 'bobgo_reconciliation_batch_size', 50 );

		// A filter returning <= 0 is treated as "reconciliation disabled".
		if ( $batch_size < 1 ) {
			return;
		}

		$orders = self::get_incremental_reconciliation_orders( $batch_size );

		$checked    = 0;
		$reconciled = 0;

		foreach ( $orders as $order ) {
			// Re-load before refreshing: the batch spends up to ~50 sequential
			// API calls' worth of time in this loop, and the link can be
			// rewritten meanwhile (LinkAudit relink/unlink, webhook backfill).
			// Refreshing against the selection-time snapshot would apply
			// another Bob Go order's fulfillment data under a stale ref. The
			// full-reconciliation path already loads inside its loop.
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$order_ref = $order->get_meta( '_bobgo_order_id' );
			if ( empty( $order_ref ) ) {
				continue;
			}

			++$checked;

			$changed = false;
			$success = Webhook::refresh_fulfillment_data( $order, $order_ref, $changed );
			// Advance the marker even on failure so one persistently-failing order
			// can't monopolise the batch; the fairness queue keeps cycling.
			$order->update_meta_data( '_bobgo_last_reconciled', gmdate( 'c' ) );
			$order->save();

			// Count only orders that actually changed, so the "N updated" log/payload
			// is no longer inflated by no-op refreshes. See QA #7.
			if ( $success && $changed ) {
				++$reconciled;
			}
		}

		update_option( self::OPTION_LAST_RECONCILIATION, gmdate( 'c' ), false );

		self::log( "Reconciliation (incremental) complete: {$reconciled} orders updated out of {$checked} checked.", 'info' );

		SyncLog::insert(
			[
				'wc_order_id' => 0,
				'event_type'  => 'reconciliation',
				'direction'   => 'inbound',
				'payload'     => wp_json_encode(
					[
						'type'       => 'incremental',
						'checked'    => $checked,
						'reconciled' => $reconciled,
					]
				),
				'http_status' => 200,
				'success'     => true,
			]
		);
	}
}
