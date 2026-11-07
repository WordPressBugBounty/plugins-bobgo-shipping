<?php
/**
 * Health signals for the Overview tab.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Health
 *
 * Collects what the plugin can actually prove about itself, so the Overview
 * reports observed facts instead of configuration.
 *
 * The previous Overview was misleading in three ways that cost real support
 * time on a store whose orders never reached Bob Go while every tile was green:
 *
 * - "Connection: Connected" meant only that a key string was stored. A key that
 *   has never been used, or that the API rejects with anything other than 401,
 *   read as healthy.
 * - "Last Sync" showed `bobgo_last_reconciliation` — the hourly background
 *   check — under a label merchants read as "orders last went to Bob Go". On a
 *   store whose Action Scheduler queue never ran it said "Awaiting first order"
 *   indefinitely, which sounds like a store with no orders rather than a
 *   stalled queue.
 * - Nothing surfaced the queue at all, even though every order push, every
 *   status update and the hourly check are queued through Action Scheduler. A
 *   queue that is not being processed silently disables the whole integration.
 *
 * State derivation lives in pure static methods that take their inputs as
 * arguments (`connection_state()`, `push_state()`, `queue_state()`, …) so the
 * judgement calls are unit-testable without a WordPress or database runtime.
 * `snapshot()` is the thin wiring layer that gathers the inputs and calls them.
 *
 * This class only reports. It deliberately performs no API calls and schedules
 * nothing, so loading the Overview cannot change the state it describes.
 */
class Health {

	/**
	 * Signal states, in increasing severity. These map to the
	 * `bobgo-status-item--*` CSS modifiers.
	 */
	const STATE_OK      = 'ok';
	const STATE_NEUTRAL = 'neutral';
	const STATE_WARNING = 'warning';
	const STATE_ERROR   = 'error';

	/**
	 * Action Scheduler group every plugin job is queued under. Must match the
	 * group passed by OrderSync, Reconciliation, LinkAudit and Activation.
	 */
	const QUEUE_GROUP = 'bobgo-shipping';

	/**
	 * How late a due background job may be before the queue is reported as
	 * running behind. Action Scheduler aims to process due actions within a
	 * minute, so 15 minutes is comfortably outside normal jitter while still
	 * catching a queue that is not running at all.
	 *
	 * Being late is not on its own an error — see queue_state().
	 */
	const QUEUE_OVERDUE_GRACE = 900;

	/**
	 * How old the last completed background check may be before it is reported
	 * as stale. The check is scheduled hourly; three hours means at least two
	 * runs were missed.
	 */
	const BACKGROUND_CHECK_STALE_AFTER = 10800;

	/**
	 * Log events that mean "this order's state reached Bob Go". An order is
	 * created once and updated thereafter, so reading only the create would
	 * report the last order sent as older than it is on any store past its
	 * first sync.
	 *
	 * The two lists must cover the same events, because the signal is derived by
	 * comparing the newest row in each: any event type that can be logged with one
	 * outcome and not the other would let that outcome outrank the other forever.
	 * A single failed status push once put a store on "orders are not reaching Bob
	 * Go" until its next order, with order creation working the whole time. The
	 * lists differ only where an event can genuinely have one outcome: an adoption
	 * is logged as `order_linked` when it succeeds and `order_sync_held` when the
	 * existing-order lookup was inconclusive.
	 */
	const PUSH_SUCCESS_EVENTS = [ 'order_created', 'order_updated', 'status_updated', 'order_linked' ];

	/**
	 * Log events that mean an attempt to send failed. See PUSH_SUCCESS_EVENTS for
	 * why the two lists mirror each other.
	 */
	const PUSH_FAILURE_EVENTS = [ 'order_created', 'order_updated', 'status_updated', 'order_sync_held' ];

	/**
	 * Inbound log events the plugin writes about its own activity: the hourly
	 * reconciliation pull, the link audit, and the fulfilment fetch behind the
	 * order panel. They are inbound in the sense that data came back, but none of
	 * them is Bob Go reaching this store, so counting them as a delivery would
	 * report a healthy webhook path on a store whose webhooks are dead — the
	 * reconciliation alone writes one an hour, indefinitely.
	 *
	 * `fulfillment_received` here is the order panel's fetch. Genuine
	 * fulfillment/created webhook deliveries log as `fulfillment_created`; they
	 * used to share this name, which silently excluded them from the inbound
	 * signal. Rows written before that rename stay excluded — an old panel fetch
	 * counting as a delivery would be the false green this list exists to stop,
	 * while an old real delivery not counting only understates until the next
	 * webhook arrives (or the 30-day purge clears the history).
	 */
	const INBOUND_INTERNAL_EVENTS = [ 'reconciliation', 'link_audit', 'fulfillment_received' ];

	/**
	 * How overdue WordPress's own scheduler must be before it is reported as
	 * behind. WP-Cron fires on site traffic, so a busy store's due events are
	 * picked up within a minute or two; a quiet store can legitimately drift by a
	 * few minutes.
	 *
	 * Being overdue past this is not on its own an error — see cron_state(). Every
	 * WooCommerce store carries a WP-Cron event on an every-minute schedule
	 * (Action Scheduler's queue runner), so this measures how long it is since
	 * WP-Cron last ran, which on a quiet store is simply how long it is since
	 * anyone visited.
	 */
	const CRON_OVERDUE_GRACE = 900;

	/**
	 * Action Scheduler hook carrying an order's outbound sync. Counted separately
	 * from the rest of the group because "orders waiting to be sent" is the number
	 * a merchant is actually asking about.
	 */
	const HOOK_SYNC_ORDER = 'bobgo_sync_order';

	/**
	 * Signals ordered by how likely each is to be the reason orders are not
	 * reaching Bob Go, worst cause first. The banner names the first one that is
	 * in an error state, so a store with several faults is told where to start
	 * rather than being handed a list.
	 */
	const PROBLEM_PRIORITY = [ 'queue', 'cron', 'connection', 'webhooks', 'order_push' ];

	/**
	 * Transient holding the three log lookups behind this page's order-sync
	 * signals, and how long it is kept.
	 *
	 * The log is indexed on created_at only, so a lookup filtered by direction,
	 * outcome and event type walks that index until it finds a match — and finds
	 * none at all on the two cases that matter most: a store that has never
	 * failed a push, and one that has never received a webhook. Measured at
	 * 262,144 rows that is ~170ms per unmatched lookup, and the log grows without
	 * bound on precisely the stores this page is for, because the job that purges
	 * it is queued on the same stalled queue. Caching the three reads for a minute
	 * keeps repeated page loads flat; the values describe minutes-old history, so
	 * a minute of staleness costs nothing.
	 */
	const TRANSIENT_LOG_SIGNALS = 'bobgo_health_log_signals';

	/**
	 * How long this page's cached lookups are kept, for both of its transients.
	 */
	const SIGNALS_TTL = 60;

	/**
	 * Transient holding this page's three Action Scheduler counts.
	 *
	 * Each is a COUNT over `actionscheduler_actions`, and the store this page
	 * exists for — one whose queue is not being processed — is by definition the
	 * one whose actions table is largest and still growing. Cached on the same
	 * terms as the log lookups, and for the same reason: they describe a backlog
	 * measured in hours, so a minute of staleness costs nothing.
	 */
	const TRANSIENT_QUEUE_COUNTS = 'bobgo_health_queue_counts';

	/**
	 * Gather every signal the Overview renders.
	 *
	 * @return array<string, array<string, mixed>> Signal name => signal data,
	 *                                             each with at least a 'state'
	 *                                             and a 'code'.
	 */
	public static function snapshot(): array {
		$now       = time();
		$has_key   = Api::is_connected();
		$log_ready = SyncLog::table_exists();

		$queue = self::queue_counts();
		$log   = self::log_signals( $log_ready );

		return [
			'connection'       => self::connection_state( $has_key, Api::get_connection_health(), Api::get_account_code() ),
			'environment'      => self::environment_state( SettingsPage::get_current_env() ),
			'channel'          => self::channel_state( Api::get_store_url() ),
			'order_push'       => self::push_state(
				$log['push_ok'],
				$log['push_failed'],
				self::failed_order_count( $has_key ),
				$log_ready
			),
			'inbound'          => self::inbound_state( $log['inbound'], $log_ready ),
			'webhooks'         => self::webhooks_state(
				Webhook::has_registered_subscriptions(),
				Webhook::is_deliberately_disconnected()
			),
			'queue'            => self::queue_state(
				function_exists( 'as_next_scheduled_action' ),
				self::next_reconciliation_run(),
				$queue['pending'],
				$queue['failed'],
				$now,
				$queue['waiting']
			),
			'cron'             => self::cron_state(
				defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				self::earliest_due_cron_event(),
				$now,
				$queue['waiting']
			),
			'background_check' => self::background_check_state(
				(string) get_option( Reconciliation::OPTION_LAST_RECONCILIATION, '' ),
				$now
			),
		];
	}

	/**
	 * Derive the WordPress-scheduler signal.
	 *
	 * Action Scheduler is triggered by WP-Cron, so a stalled queue is usually a
	 * stalled WP-Cron — and that is visible without leaving WordPress: if the
	 * earliest event WordPress has scheduled for itself is long overdue, nothing
	 * is running the scheduler. This says so explicitly rather than leaving the
	 * merchant to infer it from a queue that is merely "stalled".
	 *
	 * Overdue alone is not proof of a fault, and calling it one was wrong on the
	 * commonest store there is: WP-Cron runs on site traffic, so on a quiet store
	 * "the earliest event is 40 minutes late" means only that nobody has visited
	 * for 40 minutes. The merchant opening this page triggers the scheduler in the
	 * act of loading it, so the fault they are shown has usually already cleared by
	 * the time they read it. What separates a quiet store from a broken one is
	 * whether anything is stuck behind the scheduler: orders queued and unsent
	 * prove work is being held up, and a store with none is not being harmed,
	 * whatever its cron is doing. So the depth decides the severity, and a store
	 * that cannot be asked (null) keeps the stricter reading.
	 *
	 * @param bool     $cron_disabled Whether DISABLE_WP_CRON is set (an external cron is expected instead).
	 * @param int|null $earliest_due  Timestamp of the earliest scheduled event, null when none is scheduled.
	 * @param int      $now           Current Unix timestamp.
	 * @param int|null $waiting       Orders queued for outbound sync, null when the queue could not be read.
	 * @return array<string, mixed>
	 */
	public static function cron_state( bool $cron_disabled, ?int $earliest_due, int $now, ?int $waiting = null ): array {
		$signal = [
			'overdue_by' => 0,
			'external'   => $cron_disabled,
			'waiting'    => $waiting,
		];

		if ( null === $earliest_due ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_NEUTRAL,
					'code'  => 'unknown',
				]
			);
		}

		$overdue = $now - $earliest_due;

		if ( $overdue > self::CRON_OVERDUE_GRACE ) {
			$holding_up_work = null === $waiting || $waiting > 0;

			return array_merge(
				$signal,
				[
					'state'      => $holding_up_work ? self::STATE_ERROR : self::STATE_WARNING,
					'code'       => self::cron_late_code( $cron_disabled, $holding_up_work ),
					'overdue_by' => $overdue,
				]
			);
		}

		return array_merge(
			$signal,
			[
				'state' => self::STATE_OK,
				'code'  => $cron_disabled ? 'external' : 'running',
			]
		);
	}

	/**
	 * Name the four ways WordPress's scheduler can be late: whether the site
	 * expects an external cron, and whether anything is stuck behind it.
	 *
	 * @param bool $cron_disabled   Whether DISABLE_WP_CRON is set.
	 * @param bool $holding_up_work Whether work is queued behind the scheduler.
	 * @return string
	 */
	private static function cron_late_code( bool $cron_disabled, bool $holding_up_work ): string {
		if ( $cron_disabled ) {
			return $holding_up_work ? 'external_not_running' : 'external_behind';
		}

		return $holding_up_work ? 'not_running' : 'behind';
	}

	/**
	 * Derive the state for the count of orders queued and not yet sent.
	 *
	 * Depth alone is not a fault. A working store always has some: an order placed
	 * while payment is confirming waits 60 seconds by design, and every order sits
	 * in the queue for the moment between being placed and the queue picking it up.
	 * Colouring that amber would put a warning on a healthy store every time
	 * someone checked out, which is how a dashboard teaches people to ignore it.
	 * The depth only means something once the queue itself is in trouble — and
	 * then it says how much is waiting on the fix.
	 *
	 * @param int|null $waiting       Orders queued, null when the queue could not be read.
	 * @param bool     $queue_healthy Whether the queue signal itself is healthy.
	 * @return string One of the STATE_* values.
	 */
	public static function waiting_orders_state( ?int $waiting, bool $queue_healthy ): string {
		if ( null === $waiting ) {
			return self::STATE_NEUTRAL;
		}

		if ( $queue_healthy || 0 === $waiting ) {
			return self::STATE_OK;
		}

		return self::STATE_WARNING;
	}

	/**
	 * The signal most likely to explain why orders are not reaching Bob Go.
	 *
	 * A store with no key stored is told to connect before anything else,
	 * whatever other faults it carries. Every other fault is unactionable noise
	 * until a key exists — no order can be queued without one — and the two that
	 * would otherwise outrank it read as alarming on a store that has simply not
	 * been set up yet: telling a merchant who just installed the plugin that
	 * "orders are not reaching Bob Go" over a queue that was never scheduled is
	 * technically true and practically wrong. The not-scheduled queue's own
	 * remedy is saving the connection, which is where connecting happens anyway.
	 *
	 * @param array<string, array<string, mixed>> $snapshot Snapshot from snapshot().
	 * @return array{name: string, signal: array<string, mixed>}|null Null when nothing is in an error state.
	 */
	public static function primary_problem( array $snapshot ): ?array {
		$connection = $snapshot['connection'] ?? [];
		if ( 'no_key' === ( $connection['code'] ?? '' ) && self::STATE_ERROR === ( $connection['state'] ?? '' ) ) {
			return [
				'name'   => 'connection',
				'signal' => $connection,
			];
		}

		foreach ( self::PROBLEM_PRIORITY as $name ) {
			if ( isset( $snapshot[ $name ]['state'] ) && self::STATE_ERROR === $snapshot[ $name ]['state'] ) {
				return [
					'name'   => $name,
					'signal' => $snapshot[ $name ],
				];
			}
		}

		return null;
	}

	/**
	 * Derive the connection signal.
	 *
	 * A stored key proves nothing on its own, so an unused key is reported as
	 * unverified rather than connected. Only Api::record_health() — which fires
	 * on definitive API responses — can promote it to verified.
	 *
	 * @param bool                      $has_key      Whether an API key is stored.
	 * @param array<string, mixed>|null $health       Recorded connection health, if any.
	 * @param string                    $account_code Bob Go account the key was last seen to belong to.
	 * @return array<string, mixed>
	 */
	public static function connection_state( bool $has_key, ?array $health, string $account_code = '' ): array {
		if ( ! $has_key ) {
			return [
				'state'       => self::STATE_ERROR,
				'code'        => 'no_key',
				'http_status' => 0,
				'since'       => '',
				'account'     => $account_code,
			];
		}

		$ok = isset( $health['ok'] ) ? (bool) $health['ok'] : null;

		if ( null === $ok ) {
			return [
				'state'       => self::STATE_WARNING,
				'code'        => 'unverified',
				'http_status' => 0,
				'since'       => '',
				'account'     => $account_code,
			];
		}

		return [
			'state'       => $ok ? self::STATE_OK : self::STATE_ERROR,
			'code'        => $ok ? 'verified' : 'rejected',
			'http_status' => isset( $health['status'] ) ? (int) $health['status'] : 0,
			'since'       => isset( $health['checked_at'] ) ? (string) $health['checked_at'] : '',
			'account'     => $account_code,
		];
	}

	/**
	 * Derive the environment signal.
	 *
	 * Anything other than production is flagged: a live store left on Sandbox
	 * after testing sends its orders to an account the merchant is not looking
	 * at, and production keys will not authenticate against it.
	 *
	 * @param string $env Configured environment key.
	 * @return array<string, mixed>
	 */
	public static function environment_state( string $env ): array {
		$is_prod = 'prod' === $env;

		return [
			'state' => $is_prod ? self::STATE_OK : self::STATE_WARNING,
			'code'  => $is_prod ? 'prod' : 'non_prod',
			'env'   => $env,
		];
	}

	/**
	 * Derive the channel-identifier signal.
	 *
	 * The identifier is sent as the `bobgo-channel-identifier` header on every
	 * request and is how Bob Go resolves the sales channel. The plugin cannot
	 * validate it locally — a mismatch (www vs non-www, a migrated or staging
	 * domain) is answered with a 404 that leaves the stored key looking fine —
	 * so it is surfaced for comparison rather than judged.
	 *
	 * @param string $identifier The identifier being sent.
	 * @return array<string, mixed>
	 */
	public static function channel_state( string $identifier ): array {
		return [
			'state'      => '' === $identifier ? self::STATE_WARNING : self::STATE_NEUTRAL,
			'code'       => '' === $identifier ? 'missing' : 'sending',
			'identifier' => $identifier,
		];
	}

	/**
	 * Derive the outbound order-push signal from the sync log.
	 *
	 * Rows are compared to each other rather than to the current time: the log
	 * stores `created_at` in database time, which is not necessarily UTC.
	 *
	 * @param object|null $last_success  Newest successful order_created row, if any.
	 * @param object|null $last_failure  Newest failed order_created row, if any.
	 * @param int         $failed_orders Orders currently marked as failed to sync.
	 * @param bool        $log_available Whether the log this reads exists at all.
	 * @return array<string, mixed>
	 */
	public static function push_state( ?object $last_success, ?object $last_failure, int $failed_orders, bool $log_available = true ): array {
		$success_at = self::row_time( $last_success );
		$failure_at = self::row_time( $last_failure );

		$signal = [
			'last_success_at'     => $success_at,
			'last_success_order'  => self::row_order_id( $last_success ),
			'last_failure_at'     => $failure_at,
			'last_failure_order'  => self::row_order_id( $last_failure ),
			'last_failure_status' => null !== $last_failure ? (int) ( $last_failure->http_status ?? 0 ) : 0,
			// What failed, not just that something did: a status push failing is
			// not orders failing to reach Bob Go, and the banner says which.
			'last_failure_event'  => null !== $last_failure ? (string) ( $last_failure->event_type ?? '' ) : '',
			'failed_orders'       => $failed_orders,
			'log_available'       => $log_available,
		];

		// Without the log there is no history to read, and reporting "nothing has
		// been sent" from its absence would be the same false reassurance this
		// page exists to remove. The failed-order count still comes from order
		// meta, so it is reported either way.
		if ( ! $log_available ) {
			return array_merge(
				$signal,
				[
					'state' => $failed_orders > 0 ? self::STATE_WARNING : self::STATE_NEUTRAL,
					'code'  => 'unknown',
				]
			);
		}

		// No attempt has ever been logged. Legitimate on a store that has had no
		// orders yet, which is why this is not an error — but it is also what a
		// store whose orders never reach the queue looks like, so it must never
		// read as healthy either.
		if ( '' === $success_at && '' === $failure_at ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_NEUTRAL,
					'code'  => 'never',
				]
			);
		}

		// The most recent attempt failed — the API is refusing orders now.
		if ( $failure_at > $success_at ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_ERROR,
					'code'  => 'failing',
				]
			);
		}

		if ( $failed_orders > 0 ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_WARNING,
					'code'  => 'sending_with_failures',
				]
			);
		}

		return array_merge(
			$signal,
			[
				'state' => self::STATE_OK,
				'code'  => 'sending',
			]
		);
	}

	/**
	 * Derive the inbound-update signal: whether Bob Go has ever reached this
	 * store. Never having received anything is not an error on a new store, but
	 * it is the visible symptom of webhooks that cannot be delivered.
	 *
	 * @param object|null $last_inbound   Newest inbound log row, if any.
	 * @param bool        $log_available  Whether the log this reads exists at all.
	 * @return array<string, mixed>
	 */
	public static function inbound_state( ?object $last_inbound, bool $log_available = true ): array {
		$received_at = self::row_time( $last_inbound );

		if ( ! $log_available ) {
			return [
				'state'       => self::STATE_NEUTRAL,
				'code'        => 'unknown',
				'received_at' => '',
			];
		}

		if ( '' === $received_at ) {
			return [
				'state'       => self::STATE_NEUTRAL,
				'code'        => 'never',
				'received_at' => '',
			];
		}

		return [
			'state'       => self::STATE_OK,
			'code'        => 'receiving',
			'received_at' => $received_at,
		];
	}

	/**
	 * Derive the webhook-subscription signal.
	 *
	 * Unknown (an install predating the tracking option) stays neutral rather
	 * than green: the plugin genuinely does not know, and claiming otherwise is
	 * what made this dashboard untrustworthy. A deliberate disconnect is
	 * reported as the merchant's own choice, not a fault.
	 *
	 * @param bool|null $registered   Recorded registration state, null when unknown.
	 * @param bool      $disconnected Whether the merchant disconnected on purpose.
	 * @return array<string, mixed>
	 */
	public static function webhooks_state( ?bool $registered, bool $disconnected ): array {
		if ( $disconnected ) {
			return [
				'state' => self::STATE_WARNING,
				'code'  => 'disconnected',
			];
		}

		if ( null === $registered ) {
			return [
				'state' => self::STATE_NEUTRAL,
				'code'  => 'unknown',
			];
		}

		return [
			'state' => $registered ? self::STATE_OK : self::STATE_ERROR,
			'code'  => $registered ? 'registered' : 'not_registered',
		];
	}

	/**
	 * Derive the queue signal — the single most diagnostic fact about the
	 * plugin, because every order push, status update and background check is
	 * queued through Action Scheduler. A queue that is not being processed
	 * disables the integration while leaving every setting valid.
	 *
	 * The recurring check falling behind is read the same way as an overdue
	 * WP-Cron, and for the same reason: Action Scheduler runs on WP-Cron, which
	 * runs on site traffic, so on a quiet store an hourly job can sit late for
	 * hours without anything being wrong. It is called stalled when orders are
	 * queued behind it and merely behind when none are — the fault a merchant
	 * needs to hear about is orders not moving, not a job waiting for a visitor.
	 *
	 * @param bool     $available Whether Action Scheduler is loaded.
	 * @param int|bool $next_run  as_next_scheduled_action() result for the
	 *                            recurring check: timestamp, true when running,
	 *                            false when not scheduled.
	 * @param int|null $pending   Pending actions in the plugin's group, null when unknown.
	 * @param int|null $failed    Failed actions in the plugin's group, null when unknown.
	 * @param int      $now       Current Unix timestamp.
	 * @param int|null $waiting   Orders queued for outbound sync, null when unknown.
	 * @return array<string, mixed>
	 */
	public static function queue_state( bool $available, $next_run, ?int $pending, ?int $failed, int $now, ?int $waiting = null ): array {
		$signal = [
			'next_run'     => is_int( $next_run ) ? $next_run : 0,
			'overdue_by'   => 0,
			'pending'      => $pending,
			'failed'       => $failed,
			'waiting'      => $waiting,
			'has_failures' => is_int( $failed ) && $failed > 0,
		];

		if ( ! $available ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_ERROR,
					'code'  => 'unavailable',
				]
			);
		}

		// Nothing scheduled at all. The recurring check is scheduled during
		// activation and whenever the connection is saved, and both call sites
		// silently do nothing when Action Scheduler was not yet loaded — so this
		// state is reachable on a store that looks fully configured.
		if ( false === $next_run ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_ERROR,
					'code'  => 'not_scheduled',
				]
			);
		}

		if ( is_int( $next_run ) && $next_run < ( $now - self::QUEUE_OVERDUE_GRACE ) ) {
			$holding_up_orders = null === $waiting || $waiting > 0;

			return array_merge(
				$signal,
				[
					'state'      => $holding_up_orders ? self::STATE_ERROR : self::STATE_WARNING,
					'code'       => $holding_up_orders ? 'stalled' : 'behind',
					'overdue_by' => $now - $next_run,
				]
			);
		}

		if ( $signal['has_failures'] ) {
			return array_merge(
				$signal,
				[
					'state' => self::STATE_WARNING,
					'code'  => 'failed_actions',
				]
			);
		}

		return array_merge(
			$signal,
			[
				'state' => self::STATE_OK,
				'code'  => true === $next_run ? 'running' : 'scheduled',
			]
		);
	}

	/**
	 * Derive the background-check signal from the last completed run.
	 *
	 * This is the timestamp the old Overview showed as "Last Sync". It is kept,
	 * under its real meaning, because a stale value is a useful corroboration
	 * of a stalled queue.
	 *
	 * @param string $last_run ISO-8601 UTC timestamp of the last completed run.
	 * @param int    $now      Current Unix timestamp.
	 * @return array<string, mixed>
	 */
	public static function background_check_state( string $last_run, int $now ): array {
		if ( '' === $last_run ) {
			return [
				'state'    => self::STATE_NEUTRAL,
				'code'     => 'never',
				'last_run' => '',
			];
		}

		$ran_at = strtotime( $last_run );
		$stale  = false === $ran_at || $ran_at < ( $now - self::BACKGROUND_CHECK_STALE_AFTER );

		return [
			'state'    => $stale ? self::STATE_WARNING : self::STATE_OK,
			'code'     => $stale ? 'stale' : 'recent',
			'last_run' => $last_run,
		];
	}

	/**
	 * Whether this store was stopped on purpose rather than being broken.
	 *
	 * Disconnecting removes the API key and unschedules the recurring jobs, so a
	 * store that did it deliberately holds the whole set of faults the banner
	 * exists to report — and telling that merchant their orders are not reaching
	 * Bob Go is nagging them about their own decision. Both halves are required:
	 * the marker alone survives a merchant who disconnected only their webhooks
	 * and is still syncing orders, and that store's faults are real.
	 *
	 * Read from the snapshot, so it costs no extra query, and it lifts by itself
	 * the moment they reconnect.
	 *
	 * @param array<string, array<string, mixed>> $snapshot Snapshot from snapshot().
	 * @return bool
	 */
	public static function stopped_on_purpose( array $snapshot ): bool {
		return 'disconnected' === ( $snapshot['webhooks']['code'] ?? '' )
			&& 'no_key' === ( $snapshot['connection']['code'] ?? '' );
	}

	/**
	 * Newest log row matching the filters, or null when the log is unavailable
	 * or has no such row.
	 *
	 * @param array<string, mixed> $filters SyncLog::get_recent() filters.
	 * @return object|null
	 */
	private static function last_log_row( array $filters ): ?object {
		if ( ! SyncLog::table_exists() ) {
			return null;
		}

		$rows = SyncLog::get_recent( $filters, 1 );
		$row  = $rows[0] ?? null;

		return is_object( $row ) ? $row : null;
	}

	/**
	 * The three log lookups the order-sync signals read, cached together.
	 *
	 * @param bool $log_ready Whether the log table exists.
	 * @return array{push_ok: object|null, push_failed: object|null, inbound: object|null}
	 */
	private static function log_signals( bool $log_ready ): array {
		$empty = [
			'push_ok'     => null,
			'push_failed' => null,
			'inbound'     => null,
		];

		if ( ! $log_ready ) {
			return $empty;
		}

		$cached = get_transient( self::TRANSIENT_LOG_SIGNALS );
		if ( is_array( $cached ) ) {
			return array_merge( $empty, $cached );
		}

		$signals = [
			'push_ok'     => self::summarise_row( self::last_log_row( self::push_filters( true ) ) ),
			'push_failed' => self::summarise_row( self::last_log_row( self::push_filters( false ) ) ),
			'inbound'     => self::summarise_row( self::last_log_row( self::inbound_filters() ) ),
		];

		set_transient( self::TRANSIENT_LOG_SIGNALS, $signals, self::SIGNALS_TTL );

		return $signals;
	}

	/**
	 * Reduce a log row to the fields the signals read, so the cache does not carry
	 * a payload column that can hold a whole order.
	 *
	 * @param object|null $row Log row.
	 * @return object|null
	 */
	private static function summarise_row( ?object $row ): ?object {
		if ( null === $row ) {
			return null;
		}

		return (object) [
			'created_at'  => (string) ( $row->created_at ?? '' ),
			'wc_order_id' => (int) ( $row->wc_order_id ?? 0 ),
			'http_status' => (int) ( $row->http_status ?? 0 ),
			'event_type'  => (string) ( $row->event_type ?? '' ),
		];
	}

	/**
	 * Filters selecting the newest outbound push attempt with a given outcome.
	 *
	 * @param bool $success Whether to look for a successful or failed push.
	 * @return array<string, mixed>
	 */
	public static function push_filters( bool $success ): array {
		return [
			'direction'   => 'outbound',
			'success'     => $success,
			'event_types' => $success ? self::PUSH_SUCCESS_EVENTS : self::PUSH_FAILURE_EVENTS,
		];
	}

	/**
	 * Filters selecting the newest update Bob Go successfully delivered here.
	 *
	 * Rejected deliveries are excluded, not merely the plugin's own pulls. A
	 * webhook that arrives and is turned away — the shape of a store with a wrong
	 * or missing webhook secret, where every delivery is refused with a 403 — is
	 * logged inbound like any other, and counting it made "Updates From Bob Go"
	 * read green on precisely the store whose updates are all being dropped.
	 * Nothing reached the store, so nothing is reported as having reached it.
	 *
	 * @return array<string, mixed>
	 */
	public static function inbound_filters(): array {
		return [
			'direction'           => 'inbound',
			'success'             => true,
			'exclude_event_types' => self::INBOUND_INTERNAL_EVENTS,
		];
	}

	/**
	 * Orders currently marked as failed to sync. Skipped entirely when no key
	 * is stored, so a disconnected store does not run the count query.
	 *
	 * @param bool $has_key Whether an API key is stored.
	 * @return int
	 */
	private static function failed_order_count( bool $has_key ): int {
		return $has_key ? Admin::get_failed_sync_count() : 0;
	}

	/**
	 * Next scheduled run of the recurring background check.
	 *
	 * @return int|bool Timestamp, true when in progress, false when not
	 *                  scheduled, and false when Action Scheduler is missing
	 *                  (the caller reports availability separately).
	 */
	private static function next_reconciliation_run() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		return as_next_scheduled_action( Reconciliation::HOOK_RECONCILIATION );
	}

	/**
	 * Earliest event WordPress has scheduled for itself, due or not.
	 *
	 * @return int|null Timestamp, or null when nothing is scheduled or the cron
	 *                  array is unreadable.
	 */
	private static function earliest_due_cron_event(): ?int {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return null;
		}

		$events = _get_cron_array();
		if ( ! is_array( $events ) || empty( $events ) ) {
			return null;
		}

		$timestamps = array_filter( array_keys( $events ), 'is_numeric' );

		return empty( $timestamps ) ? null : (int) min( $timestamps );
	}

	/**
	 * The plugin's queue depths: pending and failed across its group, and the
	 * orders queued for outbound sync.
	 *
	 * The last of those is the one number that answers "where are my orders": on a
	 * store whose queue is not running it climbs with every order taken, and it is
	 * what separates a queue that is merely late from one that is holding orders
	 * up. It is read here rather than beside the queue signal because the WP-Cron
	 * signal is decided on it too.
	 *
	 * Action Scheduler's store API is versioned and optional, so every failure
	 * mode degrades to "unknown" rather than breaking the page.
	 *
	 * @return array{pending: int|null, failed: int|null, waiting: int|null}
	 */
	private static function queue_counts(): array {
		$cached = get_transient( self::TRANSIENT_QUEUE_COUNTS );
		if ( is_array( $cached ) ) {
			return array_merge(
				[
					'pending' => null,
					'failed'  => null,
					'waiting' => null,
				],
				$cached
			);
		}

		$counts = [
			'pending' => self::count_actions(
				[
					'group'  => self::QUEUE_GROUP,
					'status' => 'pending',
				]
			),
			'failed'  => self::count_actions(
				[
					'group'  => self::QUEUE_GROUP,
					'status' => 'failed',
				]
			),
			'waiting' => self::count_actions(
				[
					'hook'   => self::HOOK_SYNC_ORDER,
					'status' => 'pending',
				]
			),
		];

		set_transient( self::TRANSIENT_QUEUE_COUNTS, $counts, self::SIGNALS_TTL );

		return $counts;
	}

	/**
	 * Count Action Scheduler actions matching a query.
	 *
	 * Statuses are passed as the plain strings Action Scheduler's STATUS_*
	 * constants hold, so nothing here has to reference the class outside the
	 * guard below. Its store API is versioned and optional, so every failure mode
	 * degrades to "unknown" rather than breaking the page.
	 *
	 * @param array<string, mixed> $query Action Scheduler query args.
	 * @return int|null Null when the store could not be queried.
	 */
	private static function count_actions( array $query ): ?int {
		if ( ! class_exists( '\ActionScheduler_Store' ) ) {
			return null;
		}

		try {
			$store = \ActionScheduler_Store::instance();
			if ( ! method_exists( $store, 'query_actions' ) ) {
				return null;
			}

			return (int) $store->query_actions( $query, 'count' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Row timestamp as stored, or an empty string.
	 *
	 * @param object|null $row Log row.
	 * @return string
	 */
	private static function row_time( ?object $row ): string {
		return null === $row ? '' : (string) ( $row->created_at ?? '' );
	}

	/**
	 * Row order id, or 0.
	 *
	 * @param object|null $row Log row.
	 * @return int
	 */
	private static function row_order_id( ?object $row ): int {
		return null === $row ? 0 : (int) ( $row->wc_order_id ?? 0 );
	}
}
