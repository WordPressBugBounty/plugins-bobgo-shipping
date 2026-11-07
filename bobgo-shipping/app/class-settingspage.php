<?php
/**
 * Bob Go Settings Page - Consolidated settings under WooCommerce menu.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 *
 * Provides a consolidated settings page under WooCommerce → Bob Go.
 */
class SettingsPage {

	/**
	 * Menu slug for the settings page.
	 */
	const PAGE_SLUG = 'bobgo-settings';

	/**
	 * Nonce action for form submissions.
	 */
	const NONCE_ACTION = 'bobgo_settings_nonce';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'bobgo_nonce';

	/**
	 * Tab constants.
	 */
	const TAB_OVERVIEW      = 'overview';
	const TAB_CONNECTION    = 'connection';
	const TAB_RATES         = 'rates';
	const TAB_TRACKING      = 'tracking';
	const TAB_CHECKOUT      = 'checkout';
	const TAB_NOTIFICATIONS = 'notifications';
	const TAB_ADVANCED      = 'advanced';

	/**
	 * Id of the Overview region Test Connection re-renders in place. Shared by
	 * the markup and the button's refresh target so they cannot drift.
	 */
	const OVERVIEW_STATUS_ID = 'bobgo-overview-status';

	/**
	 * API environment base URLs.
	 */
	const ENVIRONMENTS = [
		'dev'   => 'https://api.dev.bobgo.co.za',
		'stage' => 'https://api.sandbox.bobgo.co.za',
		'prod'  => 'https://api.bobgo.co.za',
	];

	/**
	 * Bob Go workspace base URLs, keyed to match ENVIRONMENTS.
	 */
	const DASHBOARD_URLS = [
		'dev'   => 'https://dev.bobgo.co.za',
		'stage' => 'https://sandbox.bobgo.co.za',
		'prod'  => 'https://my.bobgo.co.za',
	];

	/**
	 * Register the menu item under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Bob Go', 'bobgo-shipping' ),
			__( 'Bob Go', 'bobgo-shipping' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Register hooks for form processing and AJAX.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', [ self::class, 'handle_post_actions' ] );
		add_action( 'wp_ajax_bobgo_test_connection', [ self::class, 'handle_ajax_test_connection' ] );
		add_action( 'wp_ajax_bobgo_test_api_connection', [ self::class, 'handle_ajax_test_api_connection' ] );
		add_action( 'wp_ajax_bobgo_force_reconciliation', [ self::class, 'handle_ajax_force_reconciliation' ] );
	}

	/**
	 * Get the current tab from URL parameter.
	 *
	 * @return string
	 */
	private static function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation; no data is processed.
		$tab        = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : self::TAB_OVERVIEW;
		$valid_tabs = [ self::TAB_OVERVIEW, self::TAB_CONNECTION, self::TAB_RATES, self::TAB_TRACKING, self::TAB_CHECKOUT, self::TAB_NOTIFICATIONS, self::TAB_ADVANCED ];
		return in_array( $tab, $valid_tabs, true ) ? $tab : self::TAB_OVERVIEW;
	}

	/**
	 * Render the main settings page.
	 */
	public static function render_page(): void {
		$current_tab = self::get_current_tab();
		?>
		<div class="wrap bobgo-wrap">
			<div class="bobgo-page-header">
				<img src="<?php echo esc_url( BOBGO_SHIPPING_URL . 'assets/img/bobgo-icon.png' ); ?>" alt="Bob Go" class="bobgo-page-header-logo">
				<div>
					<h1><?php esc_html_e( 'Bob Go Smart Shipping', 'bobgo-shipping' ); ?></h1>
					<p><?php esc_html_e( 'Configure shipping rates, tracking, and checkout settings.', 'bobgo-shipping' ); ?></p>
				</div>
			</div>
			<?php
			// Marker so WordPress hoists third-party admin notices to here, as a
			// full-width sibling of our content, instead of squeezing them into
			// the flex header next to the <h1> (its default target).
			?>
			<hr class="wp-header-end">
			<?php self::render_tabs( $current_tab ); ?>
			<?php self::render_notices(); ?>
			<?php
			switch ( $current_tab ) {
				case self::TAB_CONNECTION:
					self::render_connection_tab();
					break;
				case self::TAB_RATES:
					self::render_rates_tab();
					break;
				case self::TAB_TRACKING:
					self::render_tracking_tab();
					break;
				case self::TAB_CHECKOUT:
					self::render_checkout_tab();
					break;
				case self::TAB_NOTIFICATIONS:
					self::render_notifications_tab();
					break;
				case self::TAB_ADVANCED:
					self::render_advanced_tab();
					break;
				case self::TAB_OVERVIEW:
				default:
					self::render_overview_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param string $current_tab The currently active tab.
	 */
	private static function render_tabs( string $current_tab ): void {
		$tabs = [
			self::TAB_OVERVIEW      => __( 'Overview', 'bobgo-shipping' ),
			self::TAB_CONNECTION    => __( 'Connection', 'bobgo-shipping' ),
			self::TAB_RATES         => __( 'Rates at Checkout', 'bobgo-shipping' ),
			self::TAB_TRACKING      => __( 'Tracking', 'bobgo-shipping' ),
			self::TAB_CHECKOUT      => __( 'Checkout Fields', 'bobgo-shipping' ),
			self::TAB_NOTIFICATIONS => __( 'Notifications', 'bobgo-shipping' ),
			self::TAB_ADVANCED      => __( 'Advanced', 'bobgo-shipping' ),
		];

		echo '<nav class="bobgo-nav-tabs">';
		foreach ( $tabs as $tab_id => $tab_name ) {
			$url    = add_query_arg(
				[
					'page' => self::PAGE_SLUG,
					'tab'  => $tab_id,
				],
				admin_url( 'admin.php' )
			);
			$active = ( $current_tab === $tab_id ) ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $tab_name ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render the Overview tab.
	 */
	private static function render_overview_tab(): void {
		$health      = Health::snapshot();
		$system_info = self::get_system_info();

		$connection_tab_url = self::tab_url( self::TAB_CONNECTION );
		$rates_tab_url      = self::tab_url( self::TAB_RATES );
		$tracking_tab_url   = self::tab_url( self::TAB_TRACKING );
		$checkout_tab_url   = self::tab_url( self::TAB_CHECKOUT );
		$advanced_tab_url   = self::tab_url( self::TAB_ADVANCED );
		$is_connected       = Api::is_connected();

		self::render_layout_start();

		// Wrapped so Test Connection can replace it: the button records what the
		// test proved, and these two are the only things on the page that change
		// as a result. Without the swap the merchant pressed the button the
		// Connection card had just told them to press, read "Connected", and was
		// left looking at a card still saying "Not verified" directly above it —
		// the page contradicting itself on the one fact it had just established.
		echo '<div id="' . esc_attr( self::OVERVIEW_STATUS_ID ) . '">';
		self::render_overview_status( $health, $connection_tab_url, $advanced_tab_url );
		echo '</div>';

		self::render_order_sync_status_card( $health, $advanced_tab_url );
		self::render_queue_status_card( $health, $connection_tab_url );
		self::render_setup_status_card( $system_info, $rates_tab_url, $tracking_tab_url, $checkout_tab_url );

		// Quick Actions Card.
		self::render_card_start( __( 'Quick Actions', 'bobgo-shipping' ) );
		?>
		<div class="bobgo-quick-actions">
			<?php if ( ! $is_connected ) : ?>
				<a href="<?php echo esc_url( $connection_tab_url ); ?>" class="bobgo-quick-action bobgo-quick-action--primary">
					<span class="bobgo-quick-action-icon"><span class="dashicons dashicons-admin-links"></span></span>
					<div class="bobgo-quick-action-content">
						<strong><?php esc_html_e( 'Connect to Bob Go', 'bobgo-shipping' ); ?></strong>
						<span><?php esc_html_e( 'Enter your API key to get started', 'bobgo-shipping' ); ?></span>
					</div>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $rates_tab_url ); ?>" class="bobgo-quick-action">
				<span class="bobgo-quick-action-icon"><span class="dashicons dashicons-cart"></span></span>
				<div class="bobgo-quick-action-content">
					<strong><?php esc_html_e( 'Configure Rates', 'bobgo-shipping' ); ?></strong>
					<span><?php esc_html_e( 'Enable and configure shipping rates at checkout', 'bobgo-shipping' ); ?></span>
				</div>
			</a>
			<a href="<?php echo esc_url( $tracking_tab_url ); ?>" class="bobgo-quick-action">
				<span class="bobgo-quick-action-icon"><span class="dashicons dashicons-location"></span></span>
				<div class="bobgo-quick-action-content">
					<strong><?php esc_html_e( 'Set Up Tracking', 'bobgo-shipping' ); ?></strong>
					<span><?php esc_html_e( 'Configure your order tracking page', 'bobgo-shipping' ); ?></span>
				</div>
			</a>
			<a href="<?php echo esc_url( $checkout_tab_url ); ?>" class="bobgo-quick-action">
				<span class="bobgo-quick-action-icon"><span class="dashicons dashicons-admin-settings"></span></span>
				<div class="bobgo-quick-action-content">
					<strong><?php esc_html_e( 'Checkout Fields', 'bobgo-shipping' ); ?></strong>
					<span><?php esc_html_e( 'Configure suburb field for checkout', 'bobgo-shipping' ); ?></span>
				</div>
			</a>
			<a href="<?php echo esc_url( $advanced_tab_url ); ?>" class="bobgo-quick-action">
				<span class="bobgo-quick-action-icon"><span class="dashicons dashicons-admin-tools"></span></span>
				<div class="bobgo-quick-action-content">
					<strong><?php esc_html_e( 'Sync Log & Diagnostics', 'bobgo-shipping' ); ?></strong>
					<span><?php esc_html_e( 'Every request to and from Bob Go, plus system info', 'bobgo-shipping' ); ?></span>
				</div>
			</a>
		</div>
		<?php
		self::render_card_end();

		// The Overview reports whether the key has ever been confirmed; this is
		// how a merchant turns "not verified" into an answer without leaving the
		// page.
		self::render_test_connection_card( 'overview' );

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * The part of the Overview that a connection test can change: the banner
	 * naming the primary fault, and the Connection card itself.
	 *
	 * Rendered through one method so the page and the AJAX refresh cannot drift
	 * apart — whatever Test Connection swaps in is what a reload would have
	 * produced.
	 *
	 * @param array<string, array<string, mixed>> $health             Health snapshot.
	 * @param string                              $connection_tab_url Connection tab URL.
	 * @param string                              $advanced_tab_url   Advanced tab URL.
	 */
	private static function render_overview_status( array $health, string $connection_tab_url, string $advanced_tab_url ): void {
		self::render_problem_banner( $health, $connection_tab_url, $advanced_tab_url );
		self::render_connection_status_card( $health, $connection_tab_url, $advanced_tab_url );
	}

	/**
	 * Render the banner naming why the plugin is not working, when it is not.
	 *
	 * Several faults can be present at once and they are not equally likely to be
	 * the cause — a store whose queue never runs also has no order history and no
	 * completed check, and telling it all three is telling it nothing. This states
	 * the one to start with, in the merchant's terms, with the steps to clear it.
	 *
	 * Nothing is shown on a healthy store: a page that always carries a warning
	 * teaches merchants to ignore warnings.
	 *
	 * @param array<string, array<string, mixed>> $health             Health snapshot.
	 * @param string                              $connection_tab_url Connection tab URL.
	 * @param string                              $advanced_tab_url   Advanced tab URL.
	 */
	private static function render_problem_banner( array $health, string $connection_tab_url, string $advanced_tab_url ): void {
		// A merchant who disconnected on purpose is not experiencing a fault, and
		// telling them their orders are not reaching Bob Go would be nagging them
		// about their own decision. Disconnecting also unschedules the background
		// jobs, so the fault it leaves behind is not only the missing key — the
		// whole banner is suppressed rather than the one state that named it. The
		// cards still report each state plainly, and the marker is cleared by any
		// attempt to register again, so the banner returns by itself if they
		// reconnect and something is wrong.
		if ( Health::stopped_on_purpose( $health ) ) {
			return;
		}

		$problem = Health::primary_problem( $health );
		if ( null === $problem ) {
			return;
		}

		$key      = $problem['name'] . '.' . ( $problem['signal']['code'] ?? '' );
		$headline = self::problem_headline( $key, $problem['signal'] );
		if ( '' === $headline ) {
			return;
		}

		$title  = self::problem_title( $key, $problem['signal'] );
		$steps  = self::problem_steps( $key );
		$action = self::problem_action( $key, $connection_tab_url, $advanced_tab_url );
		?>
		<div class="bobgo-alert bobgo-alert--error" role="alert">
			<span class="bobgo-alert-icon"><span class="dashicons dashicons-warning"></span></span>
			<div class="bobgo-alert-body">
				<strong><?php echo esc_html( $title ); ?></strong>
				<p><?php echo esc_html( $headline ); ?></p>
				<?php if ( ! empty( $steps ) ) : ?>
					<ol class="bobgo-alert-steps">
						<?php foreach ( $steps as $step ) : ?>
							<li><?php echo esc_html( $step ); ?></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
				<?php if ( '' !== $action['url'] ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The banner's headline, which must say what is actually broken.
	 *
	 * Most causes stop orders leaving the store, but not all of them do: a missing
	 * webhook subscription stops Bob Go's updates arriving while orders go out
	 * exactly as before, and setting up for the first time is not a failure at all.
	 * Announcing the wrong fault on a page built to stop the plugin misreporting
	 * itself would be the same defect in a larger font.
	 *
	 * @param string               $key    Problem key, "signal.code".
	 * @param array<string, mixed> $signal The signal itself.
	 * @return string
	 */
	private static function problem_title( string $key, array $signal ): string {
		switch ( $key ) {
			case 'connection.no_key':
				return __( 'This store is not connected to Bob Go yet', 'bobgo-shipping' );

			case 'webhooks.not_registered':
				return __( 'Updates from Bob Go are not reaching this store', 'bobgo-shipping' );

			case 'order_push.failing':
				// A status push failing means orders were sent and a later change to
				// one was not, which is a smaller thing than orders never arriving.
				if ( 'status_updated' === ( $signal['last_failure_event'] ?? '' ) ) {
					return __( 'Order updates are not reaching Bob Go', 'bobgo-shipping' );
				}
				break;
		}

		return __( 'Orders are not reaching Bob Go', 'bobgo-shipping' );
	}

	/**
	 * One sentence on what was observed, for the banner. Deliberately an
	 * observation and not a diagnosis: the plugin can prove that due work is not
	 * running or that a table is missing, but not why the host allows neither.
	 *
	 * @param string               $key    Problem key, "signal.code".
	 * @param array<string, mixed> $signal The signal itself.
	 * @return string Empty when the key has no copy, which suppresses the banner.
	 */
	private static function problem_headline( string $key, array $signal ): string {
		switch ( $key ) {
			case 'queue.unavailable':
				return __( 'Orders are sent by a background queue that WooCommerce provides, and it is not available on this site — so nothing can be sent.', 'bobgo-shipping' );

			case 'queue.not_scheduled':
				return __( 'The background jobs that send orders were never scheduled on this site, so no order has been sent.', 'bobgo-shipping' );

			case 'queue.stalled':
				$waiting = isset( $signal['waiting'] ) ? (int) $signal['waiting'] : 0;

				if ( $waiting > 0 ) {
					return sprintf(
						/* translators: 1: number of orders, 2: relative time, e.g. "9 days ago". */
						_n(
							'%1$d order is queued and waiting to be sent to Bob Go. The queue that sends them was last due %2$s and is not being processed — this is a WordPress scheduling problem on this site, not a Bob Go one.',
							'%1$d orders are queued and waiting to be sent to Bob Go. The queue that sends them was last due %2$s and is not being processed — this is a WordPress scheduling problem on this site, not a Bob Go one.',
							$waiting,
							'bobgo-shipping'
						),
						$waiting,
						self::relative_time( (int) $signal['next_run'] )
					);
				}

				return sprintf(
					/* translators: %s: relative time, e.g. "9 days ago". */
					__( 'The queue that sends orders was last due %s and is not being processed. Orders will wait in it until it runs — this is a WordPress scheduling problem on this site, not a Bob Go one.', 'bobgo-shipping' ),
					self::relative_time( (int) $signal['next_run'] )
				);

			case 'cron.not_running':
			case 'cron.external_not_running':
				return sprintf(
					/* translators: %s: relative time, e.g. "2 months ago". */
					__( 'WordPress is not running its own scheduled events — the earliest was due %s. Everything the plugin does in the background, including sending orders, waits on that.', 'bobgo-shipping' ),
					self::relative_time( time() - (int) $signal['overdue_by'] )
				);

			case 'connection.no_key':
				return __( 'No Bob Go API key is stored, so this store is not connected to an account.', 'bobgo-shipping' );

			case 'connection.rejected':
				// Not "the API key is invalid". Bob Go answers a 401 for anything it
				// refuses at authentication, and the key is only one of the reasons:
				// a channel identifier matching no sales channel is refused the same
				// way, as is a rates request for a channel with rates at checkout
				// switched off — both on a key that is entirely valid. Naming the key
				// as the cause sent merchants to regenerate working keys, and left the
				// real fault in place for the next person to find.
				return sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Bob Go refused this store\'s last request (HTTP %d). That is a rejection at authentication — which the API key can cause, but so can a channel identifier Bob Go cannot match to a sales channel. Nothing will sync until it is cleared.', 'bobgo-shipping' ),
					(int) $signal['http_status']
				);

			case 'webhooks.not_registered':
				return __( 'Bob Go has no webhook subscription for this store, so shipment and tracking updates cannot be delivered back to it.', 'bobgo-shipping' );

			case 'order_push.failing':
				$event = (string) ( $signal['last_failure_event'] ?? '' );

				// Say what actually failed. A failed status push concerns an order
				// that is already on Bob Go (updates are only sent for linked
				// orders) — it says nothing about other orders, which may have
				// their own failures counted on the card below; a held order was
				// never sent at all, by the plugin's own choice. Announcing either
				// as "the attempt to send an order failed" misstates the fault.
				if ( 'status_updated' === $event ) {
					return __( 'The most recent attempt to send an order update to Bob Go failed. That order is already on Bob Go — its latest status change is not — and updates will keep failing until the cause is cleared.', 'bobgo-shipping' );
				}

				if ( 'order_sync_held' === $event ) {
					return __( 'The last order was held back rather than sent: Bob Go could not confirm whether the order already exists there, and sending it blind risks creating a duplicate. Orders will keep being held until the cause is cleared.', 'bobgo-shipping' );
				}

				return __( 'The most recent attempt to send an order to Bob Go failed. Orders will keep failing until the cause is cleared.', 'bobgo-shipping' );
		}

		return '';
	}

	/**
	 * The ordered steps a merchant can work through for a given problem.
	 *
	 * Written for someone with access to their WordPress admin and a hosting
	 * support desk — the two things every merchant has — rather than for a
	 * developer. Where the fix genuinely belongs to the host, the step says so
	 * and says what to ask for.
	 *
	 * @param string $key Problem key, "signal.code".
	 * @return string[]
	 */
	private static function problem_steps( string $key ): array {
		switch ( $key ) {
			case 'queue.unavailable':
				return [
					__( 'Check that WooCommerce is active and up to date — it provides the queue (Action Scheduler) this plugin sends orders through.', 'bobgo-shipping' ),
					__( 'If a security or performance plugin disables Action Scheduler, allow it for this site.', 'bobgo-shipping' ),
					__( 'Reload this page: the Queue should read Running.', 'bobgo-shipping' ),
				];

			case 'queue.not_scheduled':
				return [
					__( 'Open the Connection tab and press Save Connection. Saving re-schedules the background jobs.', 'bobgo-shipping' ),
					__( 'Reload this page and check that Queue reads Running and Last Completed Check fills in within the hour.', 'bobgo-shipping' ),
				];

			case 'queue.stalled':
			case 'queue.behind':
				return [
					__( 'Open WooCommerce → Status → Scheduled Actions and look at the Pending list. A large backlog from other plugins delays these jobs behind it.', 'bobgo-shipping' ),
					__( 'Ask your host whether the site can make HTTP requests to itself. A blocked loopback request stops WordPress from running any scheduled work, and is the usual cause.', 'bobgo-shipping' ),
					__( 'Ask your host to run WordPress\'s scheduler from a system cron every minute, rather than relying on visitor traffic to trigger it.', 'bobgo-shipping' ),
					sprintf(
						/* translators: %s: WP-CLI command, which must not be translated. */
						__( 'To send the waiting orders straight away, ask your host or developer to run: %s', 'bobgo-shipping' ),
						'wp action-scheduler run --group=bobgo-shipping'
					),
				];

			case 'cron.not_running':
			case 'cron.behind':
				return [
					__( 'Ask your host whether loopback requests — the site calling itself — are blocked. WordPress cannot run scheduled events without them.', 'bobgo-shipping' ),
					__( 'Ask your host to add a system cron that calls wp-cron.php every minute.', 'bobgo-shipping' ),
					__( 'If a caching, firewall or security layer blocks wp-cron.php, allow it.', 'bobgo-shipping' ),
				];

			case 'cron.external_not_running':
			case 'cron.external_behind':
				return [
					__( 'This site has WP-Cron switched off (DISABLE_WP_CRON), so something outside WordPress must call wp-cron.php. Check with your host that this cron job still exists and is running.', 'bobgo-shipping' ),
					__( 'Ask for it to run at least every few minutes.', 'bobgo-shipping' ),
					__( 'If the external cron is gone for good, ask your host to switch WP-Cron back on.', 'bobgo-shipping' ),
				];

			case 'connection.no_key':
				return [
					__( 'In your Bob Go account, open Settings → Sales Channels and open (or create) the WooCommerce channel for this store.', 'bobgo-shipping' ),
					__( 'Copy its API key and webhook secret into the plugin\'s Connection tab, then press Save Connection.', 'bobgo-shipping' ),
				];

			// Ordered by what it costs the merchant to check, not by what a 401
			// sounds like. Regenerating the API key is last because it is the only
			// step that changes anything — a new key has to be saved everywhere the
			// old one was used — and on the two faults above it fixes nothing while
			// looking like it should have.
			case 'connection.rejected':
				return [
					__( 'Compare the Channel Identifier shown on this page with the sales channels in your Bob Go account. Bob Go refuses a request whose channel it cannot match, and a www/non-www difference or a moved domain is enough to miss.', 'bobgo-shipping' ),
					__( 'If this store uses Bob Go rates at checkout, open Settings → Sales Channels in Bob Go and check that rates at checkout is switched on for this channel. A rate request to a channel with it switched off is refused the same way.', 'bobgo-shipping' ),
					__( 'If both of those are right, generate a new API key for the channel, paste it into the Connection tab and press Save Connection.', 'bobgo-shipping' ),
					__( 'Press Test Connection: it should report that the key is valid.', 'bobgo-shipping' ),
				];

			case 'webhooks.not_registered':
			case 'webhooks.disconnected':
				return [
					__( 'Open the Connection tab and press Re-register Webhooks.', 'bobgo-shipping' ),
					__( 'If that fails, check that this store is reachable from the internet — Bob Go delivers updates to its WordPress REST URL.', 'bobgo-shipping' ),
				];

			case 'order_push.failing':
				return [
					__( 'Open the sync log on the Advanced tab and read the response on the most recent failure.', 'bobgo-shipping' ),
					__( 'If it is 401, Bob Go refused the request at authentication. Check the Channel Identifier above against your Bob Go sales channels first — a mismatch is refused the same way — and only then generate a new API key on the Connection tab.', 'bobgo-shipping' ),
					__( 'If it is 404, check the Channel Identifier shown above matches a sales channel in your Bob Go account.', 'bobgo-shipping' ),
					__( 'If no response came back at all, ask your host whether outbound connections to api.bobgo.co.za are allowed.', 'bobgo-shipping' ),
				];

			case 'log.unavailable':
				return [
					__( 'Ask your host to grant this site\'s database user permission to create tables, or to create the table named with your database prefix followed by bobgo_sync_log.', 'bobgo-shipping' ),
					__( 'Open the Advanced tab afterwards — the plugin creates the table when that page loads.', 'bobgo-shipping' ),
					__( 'Until then, WooCommerce → Status → Logs holds the same information under the source bobgo-shipping.', 'bobgo-shipping' ),
				];

			case 'environment.non_prod':
				return [
					__( 'Open the Advanced tab and set Environment to Production.', 'bobgo-shipping' ),
					__( 'Save the connection again with an API key from your live Bob Go account.', 'bobgo-shipping' ),
				];
		}

		return [];
	}

	/**
	 * The button on the banner: where the first step actually happens.
	 *
	 * @param string $key                Problem key, "signal.code".
	 * @param string $connection_tab_url Connection tab URL.
	 * @param string $advanced_tab_url   Advanced tab URL.
	 * @return array{url: string, label: string}
	 */
	private static function problem_action( string $key, string $connection_tab_url, string $advanced_tab_url ): array {
		switch ( $key ) {
			case 'queue.stalled':
				return [
					'url'   => admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ),
					'label' => __( 'View scheduled actions', 'bobgo-shipping' ),
				];

			case 'queue.not_scheduled':
			case 'connection.no_key':
			case 'connection.rejected':
			case 'webhooks.not_registered':
				return [
					'url'   => $connection_tab_url,
					'label' => __( 'Open the Connection tab', 'bobgo-shipping' ),
				];

			case 'order_push.failing':
				return [
					'url'   => $advanced_tab_url,
					'label' => __( 'Open the Advanced tab', 'bobgo-shipping' ),
				];
		}

		return [
			'url'   => '',
			'label' => '',
		];
	}

	/**
	 * Render the Connection status card: whether this store can talk to Bob Go,
	 * which account it is talking to, and which sales channel it claims to be.
	 *
	 * @param array<string, array<string, mixed>> $health             Health snapshot.
	 * @param string                              $connection_tab_url Connection tab URL.
	 * @param string                              $advanced_tab_url   Advanced tab URL.
	 */
	private static function render_connection_status_card( array $health, string $connection_tab_url, string $advanced_tab_url ): void {
		$connection = $health['connection'];
		$env        = $health['environment'];
		$channel    = $health['channel'];
		$webhooks   = $health['webhooks'];
		$hints      = [];

		switch ( $connection['code'] ) {
			case 'no_key':
				$connection_text   = __( 'Not connected', 'bobgo-shipping' );
				$connection_detail = '';
				$hints[]           = [
					'state'   => $connection['state'],
					'text'    => __( 'No API key is stored, so nothing is sent to or received from Bob Go.', 'bobgo-shipping' ),
					'url'     => $connection_tab_url,
					'link'    => __( 'Add your API key', 'bobgo-shipping' ),
					'problem' => 'connection.no_key',
				];
				break;

			case 'unverified':
				$connection_text   = __( 'Not verified', 'bobgo-shipping' );
				$connection_detail = __( 'Saved, but no Bob Go request has succeeded yet', 'bobgo-shipping' );
				$hints[]           = [
					'state' => $connection['state'],
					'text'  => __( 'A key is saved but nothing has confirmed that it works. Run Test Connection below before trusting the rest of this page.', 'bobgo-shipping' ),
					'url'   => '',
					'link'  => '',
				];
				break;

			case 'rejected':
				$connection_text = sprintf(
					/* translators: %d: HTTP status code returned by the Bob Go API. */
					__( 'Rejected (HTTP %d)', 'bobgo-shipping' ),
					(int) $connection['http_status']
				);
				$connection_detail = self::since_text( (string) $connection['since'] );
				$hints[]           = [
					'state'   => $connection['state'],
					'text'    => __( 'Bob Go refused this store\'s last request. The API key is one cause, but a channel identifier Bob Go cannot match to a sales channel is refused the same way — and so is a rates request for a channel with rates at checkout switched off. The steps below check them cheapest first.', 'bobgo-shipping' ),
					'url'     => $connection_tab_url,
					'link'    => __( 'Open the Connection tab', 'bobgo-shipping' ),
					'problem' => 'connection.rejected',
				];
				break;

			default:
				$connection_text   = __( 'Verified', 'bobgo-shipping' );
				$since             = self::since_text( (string) $connection['since'] );
				$connection_detail = '' === $since
					? ''
					: sprintf(
						/* translators: %s: relative time, e.g. "3 hours ago". */
						__( 'Confirmed %s', 'bobgo-shipping' ),
						$since
					);
				break;
		}

		// Name the account a working key belongs to. It is the answer to "whose
		// Bob Go is this store talking to", which a key alone cannot give, and it
		// is what support quotes back — so it belongs beside the verdict rather
		// than only on the Advanced tab.
		$account_code = (string) ( $connection['account'] ?? '' );
		if ( '' !== $account_code && 'verified' === $connection['code'] ) {
			$connection_detail = '' === $connection_detail
				? sprintf(
					/* translators: %s: Bob Go account code, e.g. "AAA001". */
					__( 'Account %s', 'bobgo-shipping' ),
					$account_code
				)
				: sprintf(
					/* translators: 1: e.g. "Confirmed 3 hours ago", 2: Bob Go account code. */
					__( '%1$s · account %2$s', 'bobgo-shipping' ),
					$connection_detail,
					$account_code
				);
		}

		$env_labels = [
			'prod'  => __( 'Production', 'bobgo-shipping' ),
			'stage' => __( 'Sandbox', 'bobgo-shipping' ),
			'dev'   => __( 'Development', 'bobgo-shipping' ),
		];
		$env_text   = $env_labels[ $env['env'] ] ?? $env_labels['prod'];

		if ( 'non_prod' === $env['code'] ) {
			$hints[] = [
				'state'   => $env['state'],
				'text'    => __( 'This store is pointed at a test environment. Orders sync to that account, and production API keys will not authenticate against it.', 'bobgo-shipping' ),
				'url'     => $advanced_tab_url,
				'link'    => __( 'Change environment', 'bobgo-shipping' ),
				'problem' => 'environment.non_prod',
			];
		}

		switch ( $webhooks['code'] ) {
			case 'not_registered':
				$webhooks_text   = __( 'Not registered', 'bobgo-shipping' );
				$webhooks_detail = '';
				$hints[]         = [
					'state'   => $webhooks['state'],
					'text'    => __( 'Bob Go has no way to send tracking and fulfilment updates to this store.', 'bobgo-shipping' ),
					'url'     => $connection_tab_url,
					'link'    => __( 'Re-register webhooks', 'bobgo-shipping' ),
					'problem' => 'webhooks.not_registered',
				];
				break;

			case 'disconnected':
				$webhooks_text   = __( 'Disconnected', 'bobgo-shipping' );
				$webhooks_detail = __( 'Disconnected from this store on purpose', 'bobgo-shipping' );
				$hints[]         = [
					'state'   => $webhooks['state'],
					'text'    => __( 'Webhooks were deliberately disconnected, so updates from Bob Go are not being delivered.', 'bobgo-shipping' ),
					'url'     => $connection_tab_url,
					'link'    => __( 'Re-register webhooks', 'bobgo-shipping' ),
					'problem' => 'webhooks.disconnected',
				];
				break;

			case 'unknown':
				$webhooks_text   = __( 'Unknown', 'bobgo-shipping' );
				$webhooks_detail = __( 'Registered before the plugin recorded this', 'bobgo-shipping' );
				break;

			default:
				$webhooks_text   = __( 'Registered', 'bobgo-shipping' );
				$webhooks_detail = '';
				break;
		}

		self::render_card_start( __( 'Connection', 'bobgo-shipping' ) );
		echo '<div class="bobgo-status-banner">';
		self::render_status_item( __( 'API Key', 'bobgo-shipping' ), $connection['state'], $connection_text, '', $connection_detail );
		self::render_status_item( __( 'Environment', 'bobgo-shipping' ), $env['state'], $env_text, '', self::get_base_url() );
		self::render_status_item(
			__( 'Channel Identifier', 'bobgo-shipping' ),
			$channel['state'],
			'' !== $channel['identifier'] ? (string) $channel['identifier'] : __( 'Not detected', 'bobgo-shipping' ),
			self::get_dashboard_url( 'sales-channels' ),
			__( 'Bob Go matches this to your sales channel', 'bobgo-shipping' ),
			true
		);
		self::render_status_item( __( 'Webhooks', 'bobgo-shipping' ), $webhooks['state'], $webhooks_text, '', $webhooks_detail );
		echo '</div>';
		self::render_status_hints( $hints );
		self::render_card_end();
	}

	/**
	 * Render the Order Sync status card: what has actually moved between this
	 * store and Bob Go, in both directions.
	 *
	 * @param array<string, array<string, mixed>> $health           Health snapshot.
	 * @param string                              $advanced_tab_url Advanced tab URL.
	 */
	private static function render_order_sync_status_card( array $health, string $advanced_tab_url ): void {
		$push       = $health['order_push'];
		$inbound    = $health['inbound'];
		$hints      = [];
		$none_yet   = __( 'None yet', 'bobgo-shipping' );
		$orders_url = OrderMetaDiscovery::is_hpos_enabled()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );

		// Without its log the plugin cannot say what it sent. Every value on this
		// card except the failed-order count comes from that log, so they read
		// Unknown rather than None — "nothing was sent" and "we cannot see what was
		// sent" send support in opposite directions.
		if ( ! $push['log_available'] ) {
			$unknown = __( 'Unknown', 'bobgo-shipping' );
			$blind   = __( 'The sync log is unavailable', 'bobgo-shipping' );

			$hints[] = [
				'state'   => Health::STATE_WARNING,
				'text'    => __( 'The plugin cannot record or read its own history: the sync log table does not exist on this site. Orders may well be syncing — this page just cannot show it.', 'bobgo-shipping' ),
				'url'     => $advanced_tab_url,
				'link'    => __( 'Open the Advanced tab', 'bobgo-shipping' ),
				'problem' => 'log.unavailable',
			];

			$failed_blind = (int) $push['failed_orders'];

			self::render_card_start( __( 'Order Sync', 'bobgo-shipping' ) );
			echo '<div class="bobgo-status-banner">';
			self::render_status_item( __( 'Last Order Sent', 'bobgo-shipping' ), Health::STATE_NEUTRAL, $unknown, '', $blind );
			self::render_status_item( __( 'Last Send Error', 'bobgo-shipping' ), Health::STATE_NEUTRAL, $unknown, '', $blind );
			self::render_status_item(
				__( 'Orders Failed', 'bobgo-shipping' ),
				$failed_blind > 0 ? Health::STATE_WARNING : Health::STATE_OK,
				$failed_blind > 0 ? (string) $failed_blind : __( 'None', 'bobgo-shipping' ),
				$failed_blind > 0 ? $orders_url : '',
				__( 'Counted from the orders themselves', 'bobgo-shipping' )
			);
			self::render_status_item( __( 'Updates From Bob Go', 'bobgo-shipping' ), Health::STATE_NEUTRAL, $unknown, '', $blind );
			echo '</div>';
			self::render_status_hints( $hints );
			self::render_card_end();

			return;
		}

		$sent_text = '' !== $push['last_success_at'] ? self::log_time_text( (string) $push['last_success_at'] ) : $none_yet;

		if ( 0 === (int) $push['last_success_order'] ) {
			$sent_detail = __( 'No order has been sent from this store', 'bobgo-shipping' );
		} elseif ( 'failing' === $push['code'] ) {
			// The value above is a real success, but a later attempt failed — say so
			// here rather than leaving a red tile showing a green-looking fact.
			$sent_detail = sprintf(
				/* translators: %d: WooCommerce order number. */
				__( 'Order #%d — attempts since then have failed', 'bobgo-shipping' ),
				(int) $push['last_success_order']
			);
		} else {
			$sent_detail = sprintf(
				/* translators: %d: WooCommerce order number. */
				__( 'Order #%d', 'bobgo-shipping' ),
				(int) $push['last_success_order']
			);
		}

		if ( 'never' === $push['code'] ) {
			$hints[] = [
				'state' => Health::STATE_NEUTRAL,
				'text'  => __( 'No order has ever been sent. If this store has taken orders, none of them reached Bob Go — check Background Jobs below first.', 'bobgo-shipping' ),
				'url'   => $advanced_tab_url,
				'link'  => __( 'Open the sync log', 'bobgo-shipping' ),
			];
		}

		if ( '' === $push['last_failure_at'] ) {
			$error_state  = Health::STATE_OK;
			$error_text   = __( 'None', 'bobgo-shipping' );
			$error_detail = '';
		} else {
			$failure_status = (int) $push['last_failure_status'];
			$failure_event  = (string) ( $push['last_failure_event'] ?? '' );
			$error_state    = 'failing' === $push['code'] ? Health::STATE_ERROR : Health::STATE_WARNING;

			// A held order was never sent, so it has no HTTP status to show —
			// and "No response" would claim a transport failure the plugin did
			// not have. Name the plugin's own decision instead.
			if ( 'order_sync_held' === $failure_event ) {
				$error_text = __( 'Held — not sent', 'bobgo-shipping' );
			} elseif ( $failure_status > 0 ) {
				$error_text = sprintf(
					/* translators: %d: HTTP status code returned by the Bob Go API. */
					__( 'HTTP %d', 'bobgo-shipping' ),
					$failure_status
				);
			} else {
				$error_text = __( 'No response', 'bobgo-shipping' );
			}

			$error_detail = $push['last_failure_order'] > 0
				? sprintf(
					/* translators: 1: WooCommerce order number, 2: relative time of the failure, e.g. "2 hours ago". */
					__( 'Order #%1$d, %2$s', 'bobgo-shipping' ),
					(int) $push['last_failure_order'],
					self::log_time_text( (string) $push['last_failure_at'] )
				)
				: self::log_time_text( (string) $push['last_failure_at'] );

			if ( 'failing' === $push['code'] ) {
				$hints[] = [
					'state'   => Health::STATE_ERROR,
					'text'    => self::push_failure_hint( $failure_status, $failure_event ),
					'url'     => $advanced_tab_url,
					'link'    => __( 'Open the sync log', 'bobgo-shipping' ),
					'problem' => 'order_push.failing',
				];
			}
		}

		// The count is what one click of the bulk re-sync can drain, so at the cap
		// it is a floor, not a total — the tile and the hint both say so rather
		// than quoting a figure the store may exceed.
		$failed_orders = (int) $push['failed_orders'];
		$failed_capped = $failed_orders >= Admin::FAILED_SYNC_LIMIT;

		if ( $failed_orders > 0 ) {
			$hints[] = [
				'state' => Health::STATE_WARNING,
				'text'  => $failed_capped
					? sprintf(
						/* translators: %d: number of orders that can be retried in one go. */
						__( 'At least %d orders are marked as failed to sync. They are retried a batch at a time.', 'bobgo-shipping' ),
						$failed_orders
					)
					: sprintf(
						/* translators: %d: number of orders. */
						_n(
							'%d order is marked as failed to sync and can be retried in bulk.',
							'%d orders are marked as failed to sync and can be retried in bulk.',
							$failed_orders,
							'bobgo-shipping'
						),
						$failed_orders
					),
				'url'   => $orders_url,
				'link'  => __( 'Open the orders list', 'bobgo-shipping' ),
			];
		}

		self::render_card_start( __( 'Order Sync', 'bobgo-shipping' ) );
		echo '<div class="bobgo-status-banner">';
		self::render_status_item( __( 'Last Order Sent', 'bobgo-shipping' ), $push['state'], $sent_text, '', $sent_detail );
		self::render_status_item( __( 'Last Send Error', 'bobgo-shipping' ), $error_state, $error_text, '', $error_detail );
		self::render_status_item(
			__( 'Orders Failed', 'bobgo-shipping' ),
			$failed_orders > 0 ? Health::STATE_WARNING : Health::STATE_OK,
			$failed_orders > 0
				? ( $failed_capped ? $failed_orders . '+' : (string) $failed_orders )
				: __( 'None', 'bobgo-shipping' ),
			$failed_orders > 0 ? $orders_url : '',
			$failed_orders > 0 ? __( 'Waiting to be retried', 'bobgo-shipping' ) : ''
		);
		self::render_status_item(
			__( 'Updates From Bob Go', 'bobgo-shipping' ),
			$inbound['state'],
			'' !== $inbound['received_at'] ? self::log_time_text( (string) $inbound['received_at'] ) : $none_yet,
			'',
			'' !== $inbound['received_at']
				? __( 'Last webhook received', 'bobgo-shipping' )
				: __( 'No webhook has reached this store', 'bobgo-shipping' )
		);
		echo '</div>';
		self::render_status_hints( $hints );
		self::render_card_end();
	}

	/**
	 * Render the Background Jobs status card.
	 *
	 * Every order push, status update and reconciliation pass is queued through
	 * Action Scheduler, so a queue that is not being processed disables the
	 * integration while leaving every setting on this page valid. That made it
	 * the one thing the old Overview most needed and did not show.
	 *
	 * @param array<string, array<string, mixed>> $health             Health snapshot.
	 * @param string                              $connection_tab_url Connection tab URL.
	 */
	private static function render_queue_status_card( array $health, string $connection_tab_url ): void {
		$queue = $health['queue'];
		$check = $health['background_check'];
		$hints = [];

		switch ( $queue['code'] ) {
			case 'unavailable':
				$queue_text   = __( 'Unavailable', 'bobgo-shipping' );
				$queue_detail = __( 'Action Scheduler is not loaded', 'bobgo-shipping' );
				$hints[]      = [
					'state'   => $queue['state'],
					'text'    => __( 'Orders are queued through Action Scheduler, which ships with WooCommerce. Until it is available, nothing can be sent to Bob Go.', 'bobgo-shipping' ),
					'url'     => '',
					'link'    => '',
					'problem' => 'queue.unavailable',
				];
				break;

			case 'not_scheduled':
				$queue_text   = __( 'Not scheduled', 'bobgo-shipping' );
				$queue_detail = __( 'No recurring check is queued', 'bobgo-shipping' );
				$hints[]      = [
					'state'   => $queue['state'],
					'text'    => __( 'The recurring background check was never scheduled, which also means webhook health checks and reconciliation are not running. Saving the connection again schedules them.', 'bobgo-shipping' ),
					'url'     => $connection_tab_url,
					'link'    => __( 'Re-save the connection', 'bobgo-shipping' ),
					'problem' => 'queue.not_scheduled',
				];
				break;

			case 'stalled':
				$queue_text   = __( 'Stalled', 'bobgo-shipping' );
				$queue_detail = sprintf(
					/* translators: %s: relative time, e.g. "2 hours ago". */
					__( 'Was due %s', 'bobgo-shipping' ),
					self::relative_time( (int) $queue['next_run'] )
				);
				$hints[] = [
					'state'   => $queue['state'],
					'text'    => __( 'Due jobs are not being processed, so orders are not reaching Bob Go. A disabled or blocked WP-Cron is the usual cause — WooCommerce → Status → Scheduled Actions shows the backlog.', 'bobgo-shipping' ),
					'url'     => admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ),
					'link'    => __( 'View scheduled actions', 'bobgo-shipping' ),
					'problem' => 'queue.stalled',
				];
				break;

			case 'behind':
				$queue_text   = __( 'Running late', 'bobgo-shipping' );
				$queue_detail = sprintf(
					/* translators: %s: relative time, e.g. "2 hours ago". */
					__( 'Was due %s', 'bobgo-shipping' ),
					self::relative_time( (int) $queue['next_run'] )
				);
				$hints[] = [
					'state'   => $queue['state'],
					'text'    => __( 'The recurring check is overdue, but no orders are queued behind it, so nothing is waiting to reach Bob Go. WordPress runs background jobs when someone visits the site, so on a quiet store this clears itself on the next visit. If it stays late once orders come in, treat it as stalled.', 'bobgo-shipping' ),
					'url'     => admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ),
					'link'    => __( 'View scheduled actions', 'bobgo-shipping' ),
					'problem' => 'queue.behind',
				];
				break;

			case 'failed_actions':
				$queue_text   = __( 'Jobs failing', 'bobgo-shipping' );
				$queue_detail = __( 'Queue is running but jobs are failing', 'bobgo-shipping' );
				$hints[]      = [
					'state'   => $queue['state'],
					// "failed outright" was reaching for Action Scheduler's meaning —
					// an action left in the failed status, which it does not re-run —
					// but "outright" says total, and reading it next to "some" gives a
					// merchant nothing. The consequence says it instead: they failed,
					// and nothing will pick them up again on its own.
					'text'    => __( 'Some queued jobs failed and will not be retried on their own. Scheduled Actions shows the error for each one.', 'bobgo-shipping' ),
					'url'     => admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ),
					'link'    => __( 'View scheduled actions', 'bobgo-shipping' ),
					'problem' => 'queue.failed_actions',
				];
				break;

			default:
				$queue_text   = __( 'Running', 'bobgo-shipping' );
				$queue_detail = $queue['next_run'] > 0
					? sprintf(
						/* translators: %s: relative time, e.g. "in 20 minutes". */
						__( 'Next check %s', 'bobgo-shipping' ),
						self::relative_time( (int) $queue['next_run'] )
					)
					: __( 'A check is in progress', 'bobgo-shipping' );
				break;
		}

		switch ( $check['code'] ) {
			case 'never':
				$check_text   = __( 'Never', 'bobgo-shipping' );
				$check_detail = __( 'The hourly check has not completed on this store', 'bobgo-shipping' );
				break;

			case 'stale':
				$check_text   = self::since_text( (string) $check['last_run'] );
				$check_detail = __( 'Expected hourly', 'bobgo-shipping' );
				break;

			default:
				$check_text   = self::since_text( (string) $check['last_run'] );
				$check_detail = __( 'Reconciliation and webhook health', 'bobgo-shipping' );
				break;
		}

		// WordPress's own scheduler, stated in its own right. Action Scheduler is
		// triggered by it, so a stalled queue is nearly always a stalled WP-Cron —
		// and a merchant told "the queue is stalled" cannot act, while one told
		// "WordPress is not running scheduled events" has something to take to
		// their host.
		$cron = $health['cron'];

		switch ( $cron['code'] ) {
			case 'not_running':
			case 'external_not_running':
				$cron_text   = __( 'Not running', 'bobgo-shipping' );
				$cron_detail = sprintf(
					/* translators: %s: relative time, e.g. "2 months ago". */
					__( 'Earliest event was due %s', 'bobgo-shipping' ),
					self::relative_time( time() - (int) $cron['overdue_by'] )
				);
				$hints[] = [
					'state'   => $cron['state'],
					'text'    => 'external_not_running' === $cron['code']
						? __( 'WP-Cron is switched off on this site and whatever should be calling it from outside is not running. Nothing scheduled will happen until that is fixed.', 'bobgo-shipping' )
						: __( 'WordPress has scheduled events long past due, which means nothing is triggering its scheduler. Every background job on this site is affected, not only Bob Go\'s.', 'bobgo-shipping' ),
					'url'     => '',
					'link'    => '',
					'problem' => 'cron.' . $cron['code'],
				];
				break;

			// Overdue, but with no orders queued behind it. WordPress runs its
			// scheduled events off site traffic, so this is what a store nobody has
			// visited for a while looks like — and the merchant opening this page
			// has just triggered the scheduler by loading it. Reported, because it
			// is the same fault when orders are waiting, but not as a failure.
			case 'behind':
			case 'external_behind':
				$cron_text   = __( 'Running late', 'bobgo-shipping' );
				$cron_detail = sprintf(
					/* translators: %s: relative time, e.g. "40 minutes ago". */
					__( 'Earliest event was due %s', 'bobgo-shipping' ),
					self::relative_time( time() - (int) $cron['overdue_by'] )
				);
				$hints[] = [
					'state'   => $cron['state'],
					'text'    => 'external_behind' === $cron['code']
						? __( 'WP-Cron is switched off on this site, and the external cron that should replace it has not run recently. Nothing is queued behind it at the moment, so no orders are held up — but they will be once orders come in.', 'bobgo-shipping' )
						: __( 'WordPress has events past due, which usually means nobody has visited the site recently — it runs background work on visitor traffic. Nothing is queued behind it, so no orders are held up. If this persists on a store taking orders, it is worth chasing.', 'bobgo-shipping' ),
					'url'     => '',
					'link'    => '',
					'problem' => 'cron.' . $cron['code'],
				];
				break;

			case 'external':
				$cron_text   = __( 'External', 'bobgo-shipping' );
				$cron_detail = __( 'WP-Cron is off; an external cron is running', 'bobgo-shipping' );
				break;

			case 'unknown':
				$cron_text   = __( 'Unknown', 'bobgo-shipping' );
				$cron_detail = __( 'No scheduled events found', 'bobgo-shipping' );
				break;

			default:
				$cron_text   = __( 'Running', 'bobgo-shipping' );
				$cron_detail = __( 'WordPress is running its scheduled events', 'bobgo-shipping' );
				break;
		}

		$unknown = __( 'Unknown', 'bobgo-shipping' );
		$waiting = $queue['waiting'];

		// Failed jobs move into the queue tile's detail: it is context for the
		// queue's own verdict, and the slot buys a signal a merchant can act on.
		if ( is_int( $queue['failed'] ) && $queue['failed'] > 0 && 'failed_actions' !== $queue['code'] ) {
			$queue_detail = sprintf(
				/* translators: 1: existing detail text, 2: number of failed jobs. */
				__( '%1$s · %2$d failed', 'bobgo-shipping' ),
				$queue_detail,
				(int) $queue['failed']
			);
		}

		self::render_card_start( __( 'Background Jobs', 'bobgo-shipping' ) );
		echo '<div class="bobgo-status-banner">';
		self::render_status_item( __( 'Queue', 'bobgo-shipping' ), $queue['state'], $queue_text, '', $queue_detail );
		self::render_status_item(
			__( 'Orders Waiting To Send', 'bobgo-shipping' ),
			Health::waiting_orders_state( $waiting, Health::STATE_OK === $queue['state'] ),
			null === $waiting ? $unknown : (string) $waiting,
			'',
			null === $waiting
				? __( 'The queue could not be read', 'bobgo-shipping' )
				: __( 'Queued, not yet sent to Bob Go', 'bobgo-shipping' )
		);
		self::render_status_item( __( 'WordPress Cron', 'bobgo-shipping' ), $cron['state'], $cron_text, '', $cron_detail );
		self::render_status_item( __( 'Last Completed Check', 'bobgo-shipping' ), $check['state'], $check_text, '', $check_detail );
		echo '</div>';
		self::render_status_hints( $hints );
		self::render_card_end();
	}

	/**
	 * Render the Setup card — configuration rather than health, kept apart from
	 * the signals above so a settings choice never reads as a fault and a fault
	 * never hides among settings.
	 *
	 * @param array<string, string> $system_info      System info map.
	 * @param string                $rates_tab_url    Rates tab URL.
	 * @param string                $tracking_tab_url Tracking tab URL.
	 * @param string                $checkout_tab_url Checkout Fields tab URL.
	 */
	private static function render_setup_status_card( array $system_info, string $rates_tab_url, string $tracking_tab_url, string $checkout_tab_url ): void {
		$bobgo_options     = self::get_bobgo_options();
		$shipping_settings = self::get_shipping_settings();

		$rates_enabled = ! empty( $shipping_settings['enabled'] ) && 'yes' === $shipping_settings['enabled'];

		// Tracking page: explicit status check (exclude trash, auto-draft).
		$tracking_page_id   = ! empty( $bobgo_options['shipping_page'] ) ? (int) $bobgo_options['shipping_page'] : 0;
		$tracking_status    = $tracking_page_id ? get_post_status( $tracking_page_id ) : false;
		$tracking_page_set  = $tracking_status && in_array( $tracking_status, [ 'publish', 'private', 'draft' ], true );
		$tracking_edit_link = $tracking_page_set ? (string) get_edit_post_link( $tracking_page_id, 'raw' ) : $tracking_tab_url;

		$suburb_enabled = ! empty( $bobgo_options['suburb_at_checkout'] );

		self::render_card_start( __( 'Setup', 'bobgo-shipping' ) );
		echo '<div class="bobgo-status-banner">';
		self::render_status_item(
			__( 'Rates at Checkout', 'bobgo-shipping' ),
			$rates_enabled ? Health::STATE_OK : Health::STATE_NEUTRAL,
			$rates_enabled ? __( 'Enabled', 'bobgo-shipping' ) : __( 'Disabled', 'bobgo-shipping' ),
			$rates_tab_url
		);
		self::render_status_item(
			__( 'Tracking Page', 'bobgo-shipping' ),
			$tracking_page_set ? Health::STATE_OK : Health::STATE_NEUTRAL,
			$tracking_page_set ? __( 'Configured', 'bobgo-shipping' ) : __( 'Not set', 'bobgo-shipping' ),
			$tracking_edit_link
		);
		self::render_status_item(
			__( 'Suburb Field', 'bobgo-shipping' ),
			$suburb_enabled ? Health::STATE_OK : Health::STATE_NEUTRAL,
			$suburb_enabled ? __( 'Enabled', 'bobgo-shipping' ) : __( 'Disabled', 'bobgo-shipping' ),
			$checkout_tab_url
		);
		self::render_status_item(
			__( 'Checkout', 'bobgo-shipping' ),
			Health::STATE_NEUTRAL,
			(string) ( $system_info['checkout_type'] ?? '' ),
			'',
			__( 'Detected checkout type', 'bobgo-shipping' )
		);
		echo '</div>';
		self::render_card_end();
	}

	/**
	 * Explain an outbound failure in terms of what the merchant can act on.
	 *
	 * @param int    $status HTTP status of the failed request, 0 when the request
	 *                       never got a response.
	 * @param string $event  Event type of the failure, so a held order — which has
	 *                       no HTTP status because nothing was sent — is not
	 *                       explained as a transport failure.
	 * @return string
	 */
	private static function push_failure_hint( int $status, string $event = '' ): string {
		// A hold has two unlike causes and the signal cannot tell them apart
		// (the reason lives in the log row's payload): the existence lookup went
		// unanswered — a key or connectivity fault — or it was answered with
		// more than one candidate, where the key and connection are provably
		// fine and the fix is on the Bob Go account. Naming only the first sent
		// merchants to regenerate keys the same page showed as Verified, so this
		// names both and points at the row that says which.
		if ( 'order_sync_held' === $event ) {
			return __( 'The last order was held rather than sent: Bob Go could not confirm whether it already exists, and sending it blind risks a duplicate. The sync log records why for each attempt — either the lookup went unanswered (a rejected key or blocked requests), or more than one order on the Bob Go account already claims this order\'s number.', 'bobgo-shipping' );
		}

		switch ( $status ) {
			case 0:
				return __( 'The last order never reached Bob Go — no response came back at all. This server may be blocking outbound connections.', 'bobgo-shipping' );

			// Refused at authentication, which the key can cause and so can a
			// channel identifier Bob Go cannot match. See problem_headline().
			case 401:
				return __( 'Bob Go refused the last order it was sent (HTTP 401) — either the API key or the channel identifier is not being accepted for this store.', 'bobgo-shipping' );

			case 403:
				return __( 'Bob Go refused the last order. The API key may not have access to this sales channel.', 'bobgo-shipping' );

			case 404:
				return __( 'Bob Go could not find a sales channel for this store. Check the channel identifier above against your Bob Go sales channels.', 'bobgo-shipping' );

			case 400:
			case 422:
				return __( 'Bob Go rejected the order details on the last order. The sync log holds the exact response.', 'bobgo-shipping' );
		}

		if ( $status >= 500 ) {
			return __( 'Bob Go returned a server error on the last order. Failed orders can be retried from the orders list.', 'bobgo-shipping' );
		}

		return __( 'The sync log holds the exact request and response for this failure.', 'bobgo-shipping' );
	}

	/**
	 * Render the actionable notes below a status banner.
	 *
	 * A note states what is wrong; the steps behind it say how to clear it. They
	 * are collapsed because the merchant reading a healthy card should not have to
	 * scroll past a runbook, while the one with a broken store should not have to
	 * open a support ticket to get it.
	 *
	 * @param array<int, array<string, mixed>> $hints Hints with state, text, an optional
	 *                                                url/link pair and an optional 'problem'
	 *                                                key naming the steps to offer.
	 */
	private static function render_status_hints( array $hints ): void {
		if ( empty( $hints ) ) {
			return;
		}

		echo '<div class="bobgo-status-hints">';
		foreach ( $hints as $hint ) {
			$state = ! empty( $hint['state'] ) ? (string) $hint['state'] : Health::STATE_NEUTRAL;
			$steps = ! empty( $hint['problem'] ) ? self::problem_steps( (string) $hint['problem'] ) : [];

			echo '<div class="bobgo-status-hint bobgo-status-hint--' . esc_attr( $state ) . '">';
			echo '<p class="bobgo-status-hint-text">';
			echo esc_html( (string) ( $hint['text'] ?? '' ) );
			if ( ! empty( $hint['url'] ) && ! empty( $hint['link'] ) ) {
				echo ' <a class="bobgo-status-hint-link" href="' . esc_url( (string) $hint['url'] ) . '">' . esc_html( (string) $hint['link'] ) . '</a>';
			}
			echo '</p>';

			if ( ! empty( $steps ) ) {
				echo '<details class="bobgo-status-steps">';
				echo '<summary>' . esc_html__( 'How to fix this', 'bobgo-shipping' ) . '</summary>';
				echo '<ol>';
				foreach ( $steps as $step ) {
					echo '<li>' . esc_html( $step ) . '</li>';
				}
				echo '</ol>';
				echo '</details>';
			}

			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Format an ISO-8601 timestamp as a relative phrase.
	 *
	 * @param string $iso Timestamp, or an empty string.
	 * @return string Empty string when the timestamp is missing or unparseable.
	 */
	private static function since_text( string $iso ): string {
		$timestamp = '' !== $iso ? strtotime( $iso ) : false;

		return false === $timestamp ? '' : self::relative_time( $timestamp );
	}

	/**
	 * A sync-log timestamp as a relative phrase, falling back to the stored
	 * string when it cannot be parsed.
	 *
	 * The log stamps rows in UTC (SyncLog::insert()) and WordPress pins PHP's
	 * timezone to UTC, so strtotime() reads them correctly — and a relative
	 * phrase sidesteps showing a raw UTC datetime to a merchant in another
	 * timezone, matching how every other time on this page is shown.
	 *
	 * @param string $stored Timestamp as stored in the log.
	 * @return string
	 */
	private static function log_time_text( string $stored ): string {
		$relative = self::since_text( $stored );

		return '' === $relative ? $stored : $relative;
	}

	/**
	 * Human-readable relative time, in either direction.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function relative_time( int $timestamp ): string {
		$now = time();

		if ( $timestamp > $now ) {
			return sprintf(
				/* translators: %s: human-readable duration, e.g. "20 minutes". */
				__( 'in %s', 'bobgo-shipping' ),
				human_time_diff( $now, $timestamp )
			);
		}

		return sprintf(
			/* translators: %s: human-readable duration, e.g. "20 minutes". */
			__( '%s ago', 'bobgo-shipping' ),
			human_time_diff( $timestamp, $now )
		);
	}

	/**
	 * Build a URL to one of this page's tabs.
	 *
	 * Public because notices rendered outside this class name these tabs — the
	 * Test Connection results in particular — and a tab named in prose the
	 * merchant then has to go and find is a worse instruction than a link.
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the Connection tab.
	 */
	private static function render_connection_tab(): void {
		$is_connected = Api::is_connected();
		$store_url    = Admin::get_api_domain();

		self::render_layout_start();

		// API Key Card.
		self::render_card_start(
			__( 'API Connection', 'bobgo-shipping' ),
			__( 'Enter your Bob Go API key and webhook secret to connect your store.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_connection">

			<?php
			$has_api_key         = '' !== Api::get_api_key();
			$has_webhook_secret  = '' !== Api::get_webhook_secret();
			$saved_placeholder   = __( 'Saved — leave blank to keep current value', 'bobgo-shipping' );
			$api_key_placeholder = __( 'Paste your API key', 'bobgo-shipping' );
			$webhook_placeholder = __( 'Paste your webhook secret', 'bobgo-shipping' );
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bobgo_api_key"><?php esc_html_e( 'API Key', 'bobgo-shipping' ); ?></label>
					</th>
					<td>
						<div class="bobgo-api-key-wrap">
							<input type="password" name="bobgo_api_key" id="bobgo_api_key" class="regular-text" value="" placeholder="<?php echo esc_attr( $has_api_key ? $saved_placeholder : $api_key_placeholder ); ?>" autocomplete="off">
							<?php if ( $has_api_key ) : ?>
								<label class="bobgo-clear-secret">
									<input type="checkbox" name="bobgo_clear_api_key" value="1">
									<?php esc_html_e( 'Clear stored key', 'bobgo-shipping' ); ?>
								</label>
							<?php endif; ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Your Bob Go API key. You can find this in your Bob Go workspace under Sales Channels.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bobgo_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'bobgo-shipping' ); ?></label>
					</th>
					<td>
						<div class="bobgo-api-key-wrap">
							<input type="password" name="bobgo_webhook_secret" id="bobgo_webhook_secret" class="regular-text" value="" placeholder="<?php echo esc_attr( $has_webhook_secret ? $saved_placeholder : $webhook_placeholder ); ?>" autocomplete="off">
							<?php if ( $has_webhook_secret ) : ?>
								<label class="bobgo-clear-secret">
									<input type="checkbox" name="bobgo_clear_webhook_secret" value="1">
									<?php esc_html_e( 'Clear stored secret', 'bobgo-shipping' ); ?>
								</label>
							<?php endif; ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Used to verify that incoming webhooks really come from Bob Go. Generate this in your Bob Go workspace under Settings → Webhook subscriptions. Without it, all webhooks will be rejected.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Store URL', 'bobgo-shipping' ); ?></th>
					<td>
						<input type="text" class="regular-text" value="<?php echo esc_attr( $store_url ); ?>" readonly>
						<p class="description">
							<?php esc_html_e( 'The store URL used to identify your WooCommerce installation. This should be configured on your Bob Go Sales Channel as the Store Identifier.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Connection', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		// Test API Connection Card.
		self::render_card_start(
			__( 'Test API Connection', 'bobgo-shipping' ),
			__( 'Verify your API key is valid and the connection to Bob Go is working.', 'bobgo-shipping' )
		);
		?>
		<div id="bobgo-test-api-connection-result"></div>
		<button type="button" class="button button-secondary"
			id="bobgo-test-api-connection-btn"
			data-bobgo-test="api"
			data-action="bobgo_test_api_connection"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'bobgo_test_api_connection' ) ); ?>"
			data-result-target="#bobgo-test-api-connection-result">
			<?php esc_html_e( 'Test Connection', 'bobgo-shipping' ); ?>
		</button>
		<?php
		self::render_card_end();

		// Webhook Subscriptions Card.
		self::render_card_start(
			__( 'Webhook Subscriptions', 'bobgo-shipping' ),
			__( 'Webhooks allow Bob Go to notify your store of order and shipment updates.', 'bobgo-shipping' )
		);
		if ( $is_connected ) {
			$webhook_status = Webhook::get_subscription_status();
			if ( ! empty( $webhook_status ) && is_array( $webhook_status ) ) {
				echo '<table class="widefat striped">';
				echo '<thead><tr>';
				echo '<th>' . esc_html__( 'Topic', 'bobgo-shipping' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'bobgo-shipping' ) . '</th>';
				echo '</tr></thead>';
				echo '<tbody>';
				foreach ( $webhook_status as $webhook ) {
					echo '<tr>';
					echo '<td>' . esc_html( $webhook['topic'] ?? '' ) . '</td>';
					echo '<td>' . esc_html( $webhook['status'] ?? '' ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'No webhook subscriptions found.', 'bobgo-shipping' ) . '</p>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Connect your API key to manage webhook subscriptions.', 'bobgo-shipping' ) . '</p>';
		}
		?>
		<br>
		<form method="post" action="" style="display:inline;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="reregister_webhooks">
			<?php submit_button( __( 'Re-register Webhooks', 'bobgo-shipping' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		self::render_card_end();

		// Disconnect Card.
		if ( $is_connected ) {
			self::render_card_start( __( 'Disconnect', 'bobgo-shipping' ) );
			?>
			<p class="description">
				<?php esc_html_e( 'Disconnecting will remove your API key, deregister webhooks, and stop order synchronisation. It also removes the link between your WooCommerce and Bob Go orders, so tracking details will no longer show on those orders. Nothing is deleted on Bob Go itself.', 'bobgo-shipping' ); ?>
			</p>
			<form method="post" action="" id="bobgo-disconnect-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="bobgo_action" value="disconnect">
				<button type="submit" class="button button-secondary" id="bobgo-disconnect-btn" data-confirm="<?php echo esc_attr__( 'Are you sure you want to disconnect? This will remove your API key, stop order synchronisation, and remove the link between your WooCommerce and Bob Go orders.', 'bobgo-shipping' ); ?>">
					<?php esc_html_e( 'Disconnect', 'bobgo-shipping' ); ?>
				</button>
			</form>
			<?php
			self::render_card_end();
		}

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Render the Rates at Checkout tab.
	 */
	private static function render_rates_tab(): void {
		$shipping_settings = self::get_shipping_settings();

		self::render_layout_start();
		self::render_card_start(
			__( 'Rates at Checkout Settings', 'bobgo-shipping' ),
			__( 'When enabled, your buyers will receive the shipping rates configured on Bob Go during checkout.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_rates">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_rates[enabled]" value="yes" <?php checked( ! empty( $shipping_settings['enabled'] ) && 'yes' === $shipping_settings['enabled'] ); ?>>
							<?php esc_html_e( 'Enable Bob Go rates at checkout', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, your customers will be presented with shipping rates at checkout as configured on the Bob Go platform.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Hide Other Rates', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_rates[hide]" value="yes" <?php checked( ! empty( $shipping_settings['hide'] ) && 'yes' === $shipping_settings['hide'] ); ?>>
							<?php esc_html_e( 'Hide WooCommerce shipping rates when Bob Go returns rates', 'bobgo-shipping' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Use Site Address URL', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_rates[Site_Address]" value="yes" <?php checked( ! empty( $shipping_settings['Site_Address'] ) && 'yes' === $shipping_settings['Site_Address'] ); ?>>
							<?php esc_html_e( 'Use Site Address (URL) when requesting rates', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php
							$site_address = wp_parse_url( home_url(), PHP_URL_HOST ) . wp_parse_url( home_url(), PHP_URL_PATH );
							$wp_address   = wp_parse_url( site_url(), PHP_URL_HOST );
							printf(
								/* translators: %1$s: Site Address URL, %2$s: WordPress Address URL */
								esc_html__( 'When enabled, %1$s will be used instead of %2$s when making requests for shipping rates.', 'bobgo-shipping' ),
								'<strong>' . esc_html( $site_address ) . '</strong>',
								'<strong>' . esc_html( $wp_address ) . '</strong>'
							);
							?>
							<br>
							<span class="bobgo-status-warn"><?php esc_html_e( 'Only enable this option if your WooCommerce store is accessed via a subfolder, e.g. /shop.', 'bobgo-shipping' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Additional Rate Info', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_rates[Additional_rate_info]" value="yes" <?php checked( ! empty( $shipping_settings['Additional_rate_info'] ) && 'yes' === $shipping_settings['Additional_rate_info'] ); ?>>
							<?php esc_html_e( 'Show additional rate information', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Displays the delivery timeframe and additional service level description below each shipping rate.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		// Test Connection Card.
		$rates_enabled = ! empty( $shipping_settings['enabled'] ) && 'yes' === $shipping_settings['enabled'];
		if ( $rates_enabled ) {
			self::render_test_connection_card( 'rates' );
		}

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Render the Tracking tab.
	 */
	private static function render_tracking_tab(): void {
		$bobgo_options   = self::get_bobgo_options();
		$current_page_id = ! empty( $bobgo_options['shipping_page'] ) ? (int) $bobgo_options['shipping_page'] : 0;

		self::render_layout_start();
		self::render_card_start(
			__( 'Tracking Page Settings', 'bobgo-shipping' ),
			__( 'Select the page where customers can track their orders using the Bob Go tracking widget.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_tracking">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bobgo_shipping_page"><?php esc_html_e( 'Tracking Page', 'bobgo-shipping' ); ?></label>
					</th>
					<td>
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() echoes its own already-escaped output.
						wp_dropdown_pages(
							[
								'name'              => 'bobgo_options[shipping_page]',
								'id'                => 'bobgo_shipping_page',
								'show_option_none'  => __( '&mdash; Select &mdash;', 'bobgo-shipping' ),
								'option_none_value' => '0',
								'selected'          => $current_page_id,
							]
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<p class="description">
							<?php esc_html_e( 'Choose the page that contains the Bob Go tracking block or [bobgo] shortcode.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shortcode', 'bobgo-shipping' ); ?></th>
					<td>
						<div class="bobgo-copy-wrap">
							<code>[bobgo]</code>
							<button type="button" class="button button-small" data-bobgo-copy="[bobgo]">
								<?php esc_html_e( 'Copy', 'bobgo-shipping' ); ?>
							</button>
						</div>
						<p class="description"><?php esc_html_e( 'Add this shortcode to any page to display the tracking widget.', 'bobgo-shipping' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		// Create Tracking Page Card.
		self::render_card_start(
			__( 'Create Tracking Page', 'bobgo-shipping' ),
			__( 'If you don\'t have a tracking page yet, you can create one automatically.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'bobgo_create_page', 'bobgo_create_page_nonce' ); ?>
			<input type="hidden" name="bobgo_action" value="create_tracking_page">
			<?php submit_button( __( 'Create Tracking Page', 'bobgo-shipping' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		self::render_card_end();

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Render the Checkout Fields tab.
	 */
	private static function render_checkout_tab(): void {
		$bobgo_options  = self::get_bobgo_options();
		$suburb_enabled = isset( $bobgo_options['suburb_at_checkout'] ) ? (int) $bobgo_options['suburb_at_checkout'] : 1;

		self::render_layout_start();
		self::render_card_start(
			__( 'Checkout Field Settings', 'bobgo-shipping' ),
			__( 'Configure additional checkout fields for shipping.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_checkout">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Suburb Field', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_options[suburb_at_checkout]" value="1" <?php checked( 1, $suburb_enabled ); ?>>
							<?php esc_html_e( 'Show suburb field at checkout', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, a suburb field will be displayed at checkout for more accurate shipping rates.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Render the Notifications tab.
	 */
	private static function render_notifications_tab(): void {
		$bobgo_options         = self::get_bobgo_options();
		$email_notifications   = $bobgo_options['email_notifications'] ?? 'woocommerce';
		$custom_order_statuses = isset( $bobgo_options['custom_order_statuses'] ) ? (int) $bobgo_options['custom_order_statuses'] : 1;
		$shipped_email_enabled = ! empty( $bobgo_options['shipped_email_enabled'] );

		self::render_layout_start();
		self::render_card_start(
			__( 'Email Notification Settings', 'bobgo-shipping' ),
			__( 'Choose how order notification emails are sent to your customers.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_notifications">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Notification Handler', 'bobgo-shipping' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="bobgo_email_notifications" value="woocommerce" <?php checked( $email_notifications, 'woocommerce' ); ?>>
								<?php esc_html_e( 'Send via WooCommerce', 'bobgo-shipping' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'WooCommerce will handle all order notification emails using its built-in email system.', 'bobgo-shipping' ); ?>
							</p>
							<br>
							<label>
								<input type="radio" name="bobgo_email_notifications" value="bobgo" <?php checked( $email_notifications, 'bobgo' ); ?>>
								<?php esc_html_e( 'Let Bob Go handle notifications', 'bobgo-shipping' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Bob Go will send shipping, tracking, and delivery notification emails. WooCommerce will still send your order confirmation email at checkout.', 'bobgo-shipping' ); ?>
							</p>
						</fieldset>
						<br>
						<div class="bobgo-notice bobgo-notice--warning" role="alert" style="margin:0<?php echo 'bobgo' === $email_notifications ? '' : ';display:none'; ?>" data-bobgo-notifications-warning>
							<span class="bobgo-notice__icon dashicons dashicons-info"></span>
							<p class="bobgo-notice__message">
								<?php esc_html_e( 'To avoid duplicate emails, disable the matching WooCommerce notification emails (for example "Completed order") under WooCommerce → Settings → Emails. Bob Go does not turn these off automatically.', 'bobgo-shipping' ); ?>
							</p>
						</div>
					</td>
				</tr>
			</table>

			<hr>
			<h3><?php esc_html_e( 'Order Status Updates', 'bobgo-shipping' ); ?></h3>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Custom Order Statuses', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_custom_order_statuses" value="1" <?php checked( $custom_order_statuses, 1 ); ?>>
							<?php esc_html_e( "Enable 'Shipped' and 'Partially Shipped' order statuses", 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, Bob Go will automatically update your orders to "Shipped" or "Partially Shipped" as fulfillments are created. When disabled, orders will only move to "Completed" once all shipments are delivered.', 'bobgo-shipping' ); ?>
						</p>
						<br>
						<div class="bobgo-notice bobgo-notice--warning" role="alert" style="margin:0">
							<span class="bobgo-notice__icon dashicons dashicons-info"></span>
							<p class="bobgo-notice__message">
								<?php esc_html_e( 'Disabling this will not change the status of existing orders that are already marked as "Shipped" or "Partially Shipped".', 'bobgo-shipping' ); ?>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shipped Order Email', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_shipped_email_enabled" value="1" <?php checked( $shipped_email_enabled ); ?>>
							<?php esc_html_e( 'Email the customer when their order is shipped or partially shipped', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default. When enabled (and the custom order statuses above are on), WooCommerce sends the customer an email with the courier, tracking link and the items that shipped. The email then also appears under WooCommerce → Settings → Emails, where you can customise its subject and heading. Leave this off if Bob Go handles your shipping notifications, to avoid duplicate emails.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Render the Advanced tab with API configuration and system info.
	 */
	private static function render_advanced_tab(): void {
		$system_info      = self::get_system_info();
		$current_env      = self::get_current_env();
		$current_base_url = self::get_base_url();

		self::render_layout_start();

		// Environment Card.
		self::render_card_start( __( 'Environment', 'bobgo-shipping' ) );
		?>
		<p class="description bobgo-card-description">
			<?php
			/* translators: %s: link to the Bob Go sandbox sign-up page. */
			$sandbox_help = __( 'Production is the default. Select <strong>Sandbox</strong> to test the plugin — this requires a separate account and API key from %s.', 'bobgo-shipping' );
			printf(
				wp_kses(
					$sandbox_help,
					[
						'strong' => [],
						'a'      => [
							'href'   => [],
							'target' => [],
							'rel'    => [],
						],
					]
				),
				'<a href="https://sandbox.bobgo.co.za/" target="_blank" rel="noopener noreferrer">sandbox.bobgo.co.za</a>'
			);
			?>
		</p>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_api_settings">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bobgo_api_env"><?php esc_html_e( 'Environment', 'bobgo-shipping' ); ?></label>
					</th>
					<td>
						<select name="bobgo_api[env]" id="bobgo_api_env">
							<option value="prod" data-url="<?php echo esc_attr( self::ENVIRONMENTS['prod'] ); ?>" <?php selected( $current_env, 'prod' ); ?>>
								<?php esc_html_e( 'Production', 'bobgo-shipping' ); ?>
							</option>
							<option value="stage" data-url="<?php echo esc_attr( self::ENVIRONMENTS['stage'] ); ?>" <?php selected( $current_env, 'stage' ); ?>>
								<?php esc_html_e( 'Sandbox', 'bobgo-shipping' ); ?>
							</option>
							<option value="dev" data-url="<?php echo esc_attr( self::ENVIRONMENTS['dev'] ); ?>" <?php selected( $current_env, 'dev' ); ?>>
								<?php esc_html_e( 'Development', 'bobgo-shipping' ); ?>
							</option>
						</select>
						<p class="description">
							<span class="bobgo-copy-wrap">
								<code id="bobgo_api_env_url"><?php echo esc_html( $current_base_url ); ?></code>
								<button type="button" class="button button-small" data-bobgo-copy-target="#bobgo_api_env_url">
									<?php esc_html_e( 'Copy', 'bobgo-shipping' ); ?>
								</button>
							</span>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		// Vendor Plugin Support Card.
		$options       = self::get_bobgo_options();
		$vendor_plugin = $options['vendor_plugin'] ?? 'none';

		self::render_card_start(
			__( 'Vendor Plugin Support', 'bobgo-shipping' ),
			__( 'Enable multi-vendor support to include vendor data in orders sent to Bob Go.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_vendor_settings">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Vendor Plugin', 'bobgo-shipping' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="bobgo_vendor[vendor_plugin]" value="none" <?php checked( $vendor_plugin, 'none' ); ?>>
								<?php esc_html_e( 'None', 'bobgo-shipping' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="bobgo_vendor[vendor_plugin]" value="dokan" <?php checked( $vendor_plugin, 'dokan' ); ?>>
								<?php esc_html_e( 'Dokan', 'bobgo-shipping' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Select your multi-vendor marketplace plugin. When enabled, vendor information will be included with order items.', 'bobgo-shipping' ); ?>
							</p>
						</fieldset>
						<?php
						$dokan_active   = function_exists( 'dokan_get_vendor_by_product' );
						$dokan_selected = 'dokan' === $vendor_plugin;
						?>
						<br>
						<div class="bobgo-notice bobgo-notice--success" role="status" style="margin:0<?php echo $dokan_selected && $dokan_active ? '' : ';display:none'; ?>" data-bobgo-dokan-status="active">
							<span class="bobgo-notice__icon dashicons dashicons-yes-alt"></span>
							<p class="bobgo-notice__message">
								<?php esc_html_e( 'Dokan detected and active.', 'bobgo-shipping' ); ?>
							</p>
						</div>
						<div class="bobgo-notice bobgo-notice--warning" role="alert" style="margin:0<?php echo $dokan_selected && ! $dokan_active ? '' : ';display:none'; ?>" data-bobgo-dokan-status="inactive">
							<span class="bobgo-notice__icon dashicons dashicons-warning"></span>
							<p class="bobgo-notice__message">
								<?php esc_html_e( 'Dokan plugin not detected. Please install and activate Dokan for vendor support to work.', 'bobgo-shipping' ); ?>
							</p>
						</div>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();

		// Custom Order Fields Card.
		self::render_custom_fields_card();

		// Product Options Card.
		self::render_display_options_card();

		// System Information Card.
		self::render_card_start(
			__( 'System Information', 'bobgo-shipping' ),
			__( 'Technical information about your installation for debugging and support.', 'bobgo-shipping' )
		);
		?>
		<table class="form-table bobgo-system-info" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Plugin Version', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['plugin_version'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress Version', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['wordpress_version'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WooCommerce Version', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['woocommerce_version'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'PHP Version', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['php_version'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Checkout Type', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['checkout_type'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API Domain', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $system_info['api_domain'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API Environment', 'bobgo-shipping' ); ?></th>
				<td><code><?php echo esc_html( $current_base_url ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bob Go Account Code', 'bobgo-shipping' ); ?></th>
				<td>
					<code>
					<?php
					$account_code = Api::get_account_code();
					echo esc_html( '' !== $account_code ? $account_code : __( 'not yet known', 'bobgo-shipping' ) );
					?>
					</code>
					<?php
					// The record outlives a disconnect on purpose (it is what lets a
					// reconnect to another account be recognised as a switch), so say
					// which it is rather than showing a bare code for a store that is
					// not connected to anything.
					if ( '' !== $account_code && ! Api::is_connected() ) {
						echo ' <span class="description">' . esc_html__( 'last connected account', 'bobgo-shipping' ) . '</span>';
					}
					?>
				</td>
			</tr>
		</table>

		<hr class="bobgo-separator">

		<p>
			<?php
			printf(
				/* translators: %s: link to WooCommerce logs */
				esc_html__( 'View Bob Go shipping logs in the %s.', 'bobgo-shipping' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=bobgo-shipping' ) ) . '">' . esc_html__( 'WooCommerce logs', 'bobgo-shipping' ) . '</a>'
			);
			?>
		</p>
		<?php
		self::render_card_end();

		// Sync Log Viewer Card.
		self::render_card_start(
			__( 'Sync Log', 'bobgo-shipping' ),
			__( 'Recent order synchronisation log entries.', 'bobgo-shipping' )
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter parameters for display; no data is processed.
		$filter_order_id   = isset( $_GET['filter_order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_order_id'] ) ) : '';
		$filter_event_type = isset( $_GET['filter_event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_event_type'] ) ) : '';
		$filter_direction  = isset( $_GET['filter_direction'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_direction'] ) ) : '';
		$filter_success    = isset( $_GET['filter_success'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_success'] ) ) : '';
		$log_page          = isset( $_GET['log_page'] ) ? absint( $_GET['log_page'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filters = [];
		if ( $filter_order_id ) {
			$filters['order_id'] = $filter_order_id;
		}
		if ( $filter_event_type ) {
			$filters['event_type'] = $filter_event_type;
		}
		if ( $filter_direction ) {
			$filters['direction'] = $filter_direction;
		}
		if ( '' !== $filter_success ) {
			$filters['success'] = $filter_success;
		}

		$advanced_tab_url = add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => self::TAB_ADVANCED,
			],
			admin_url( 'admin.php' )
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bobgo-sync-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_ADVANCED ); ?>">
			<div class="bobgo-filter-grid">
				<label class="bobgo-filter-field">
					<span class="bobgo-filter-label"><?php esc_html_e( 'Order ID', 'bobgo-shipping' ); ?></span>
					<input type="text" name="filter_order_id" value="<?php echo esc_attr( $filter_order_id ); ?>" placeholder="<?php esc_attr_e( 'e.g. 178014', 'bobgo-shipping' ); ?>">
				</label>
				<label class="bobgo-filter-field">
					<span class="bobgo-filter-label"><?php esc_html_e( 'Event', 'bobgo-shipping' ); ?></span>
					<select name="filter_event_type">
						<option value=""><?php esc_html_e( 'All Events', 'bobgo-shipping' ); ?></option>
						<option value="order_created" <?php selected( $filter_event_type, 'order_created' ); ?>><?php esc_html_e( 'Order Created', 'bobgo-shipping' ); ?></option>
						<option value="order_updated" <?php selected( $filter_event_type, 'order_updated' ); ?>><?php esc_html_e( 'Order Updated', 'bobgo-shipping' ); ?></option>
						<option value="order_deleted" <?php selected( $filter_event_type, 'order_deleted' ); ?>><?php esc_html_e( 'Order Deleted', 'bobgo-shipping' ); ?></option>
						<option value="shipment_update" <?php selected( $filter_event_type, 'shipment_update' ); ?>><?php esc_html_e( 'Shipment Update', 'bobgo-shipping' ); ?></option>
						<option value="reconciliation" <?php selected( $filter_event_type, 'reconciliation' ); ?>><?php esc_html_e( 'Reconciliation', 'bobgo-shipping' ); ?></option>
						<option value="fulfillment_created" <?php selected( $filter_event_type, 'fulfillment_created' ); ?>><?php esc_html_e( 'Fulfillment Created', 'bobgo-shipping' ); ?></option>
						<option value="fulfillment_received" <?php selected( $filter_event_type, 'fulfillment_received' ); ?>><?php esc_html_e( 'Fulfillment Received', 'bobgo-shipping' ); ?></option>
						<option value="tracking_updated" <?php selected( $filter_event_type, 'tracking_updated' ); ?>><?php esc_html_e( 'Tracking Updated', 'bobgo-shipping' ); ?></option>
						<option value="status_updated" <?php selected( $filter_event_type, 'status_updated' ); ?>><?php esc_html_e( 'Status Updated', 'bobgo-shipping' ); ?></option>
						<option value="webhook_rejected" <?php selected( $filter_event_type, 'webhook_rejected' ); ?>><?php esc_html_e( 'Webhook Rejected', 'bobgo-shipping' ); ?></option>
						<option value="webhooks_reregistered" <?php selected( $filter_event_type, 'webhooks_reregistered' ); ?>><?php esc_html_e( 'Webhooks Re-registered', 'bobgo-shipping' ); ?></option>
					</select>
				</label>
				<label class="bobgo-filter-field">
					<span class="bobgo-filter-label"><?php esc_html_e( 'Direction', 'bobgo-shipping' ); ?></span>
					<select name="filter_direction">
						<option value=""><?php esc_html_e( 'All Directions', 'bobgo-shipping' ); ?></option>
						<option value="outbound" <?php selected( $filter_direction, 'outbound' ); ?>><?php esc_html_e( 'Outgoing', 'bobgo-shipping' ); ?></option>
						<option value="inbound" <?php selected( $filter_direction, 'inbound' ); ?>><?php esc_html_e( 'Incoming', 'bobgo-shipping' ); ?></option>
					</select>
				</label>
				<label class="bobgo-filter-field">
					<span class="bobgo-filter-label"><?php esc_html_e( 'Status', 'bobgo-shipping' ); ?></span>
					<select name="filter_success">
						<option value=""><?php esc_html_e( 'All Statuses', 'bobgo-shipping' ); ?></option>
						<option value="1" <?php selected( $filter_success, '1' ); ?>><?php esc_html_e( 'Success', 'bobgo-shipping' ); ?></option>
						<option value="0" <?php selected( $filter_success, '0' ); ?>><?php esc_html_e( 'Failed', 'bobgo-shipping' ); ?></option>
					</select>
				</label>
				<div class="bobgo-filter-actions">
					<?php submit_button( __( 'Filter', 'bobgo-shipping' ), 'secondary', 'submit', false ); ?>
					<?php if ( $filter_order_id || $filter_event_type || $filter_direction || '' !== $filter_success ) : ?>
						<a href="<?php echo esc_url( $advanced_tab_url ); ?>" class="bobgo-filter-clear"><?php esc_html_e( 'Clear filters', 'bobgo-shipping' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</form>
		<?php
		if ( ! self::sync_log_table_exists() ) {
			// The admin_init upgrade routine should have created the table — if
			// we're still here, dbDelta failed (most often: DB user lacks CREATE
			// TABLE). Try once more in case this page loaded before admin_init,
			// then surface a real error rather than a misleading "soon" message.
			SyncLog::create_table();
		}

		if ( ! self::sync_log_table_exists() ) {
			echo '<p>' . esc_html__( 'Sync log table could not be created. Check that the database user has CREATE TABLE privileges, then reload this page.', 'bobgo-shipping' ) . '</p>';
			self::render_card_end();
			self::render_main_end_sidebar_start();
			self::render_help_sidebar();
			self::render_layout_end();
			return;
		}

		$per_page    = 20;
		$total       = SyncLog::count( $filters );
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
		$log_page    = max( 1, min( $log_page, $total_pages ) );
		$offset      = ( $log_page - 1 ) * $per_page;
		$log_entries = SyncLog::get_recent( $filters, $per_page, $offset );

		$direction_labels = [
			'outbound' => __( 'Outgoing', 'bobgo-shipping' ),
			'inbound'  => __( 'Incoming', 'bobgo-shipping' ),
		];

		if ( ! empty( $log_entries ) ) {
			echo '<div class="bobgo-table-wrap">';
			echo '<table class="widefat striped bobgo-sync-log-table">';
			echo '<thead><tr>';
			echo '<th class="bobgo-col-id">' . esc_html__( 'ID', 'bobgo-shipping' ) . '</th>';
			echo '<th class="bobgo-col-order">' . esc_html__( 'Order', 'bobgo-shipping' ) . '</th>';
			echo '<th>' . esc_html__( 'Event', 'bobgo-shipping' ) . '</th>';
			echo '<th>' . esc_html__( 'Direction', 'bobgo-shipping' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'bobgo-shipping' ) . '</th>';
			echo '<th class="bobgo-col-time">' . esc_html__( 'Time', 'bobgo-shipping' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';
			foreach ( $log_entries as $entry ) {
				$is_success    = ! empty( $entry->success );
				$pill_class    = $is_success ? 'bobgo-pill--success' : 'bobgo-pill--error';
				$status_label  = $is_success ? __( 'Success', 'bobgo-shipping' ) : __( 'Failed', 'bobgo-shipping' );
				$direction_key = (string) ( $entry->direction ?? '' );
				$direction     = $direction_labels[ $direction_key ] ?? $direction_key;
				$wc_order_id   = (int) ( $entry->wc_order_id ?? 0 );
				$order_display = $wc_order_id > 0 ? (string) $wc_order_id : '—';
				echo '<tr>';
				echo '<td class="bobgo-col-id"><span class="bobgo-mono">' . esc_html( $entry->id ?? '' ) . '</span></td>';
				echo '<td class="bobgo-col-order"><span class="bobgo-mono">' . esc_html( $order_display ) . '</span></td>';
				echo '<td>' . esc_html( $entry->event_type ?? '' ) . '</td>';
				echo '<td>' . esc_html( $direction ) . '</td>';
				echo '<td><span class="bobgo-pill ' . esc_attr( $pill_class ) . '">' . esc_html( $status_label ) . '</span></td>';
				echo '<td class="bobgo-col-time"><span class="bobgo-mono">' . esc_html( $entry->created_at ?? '' ) . '</span></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';

			// Pagination.
			$range_start = $offset + 1;
			$range_end   = min( $offset + count( $log_entries ), $total );
			$base_args   = [
				'page' => self::PAGE_SLUG,
				'tab'  => self::TAB_ADVANCED,
			];
			if ( '' !== $filter_order_id ) {
				$base_args['filter_order_id'] = $filter_order_id;
			}
			if ( '' !== $filter_event_type ) {
				$base_args['filter_event_type'] = $filter_event_type;
			}
			if ( '' !== $filter_direction ) {
				$base_args['filter_direction'] = $filter_direction;
			}
			if ( '' !== $filter_success ) {
				$base_args['filter_success'] = $filter_success;
			}

			$page_url = static function ( int $page ) use ( $base_args ): string {
				return add_query_arg(
					array_merge( $base_args, [ 'log_page' => $page ] ),
					admin_url( 'admin.php' )
				);
			};

			echo '<div class="bobgo-pagination">';
			echo '<div class="bobgo-pagination__summary">';
			printf(
				/* translators: 1: first row index, 2: last row index, 3: total entries. */
				esc_html__( 'Showing %1$s–%2$s of %3$s entries', 'bobgo-shipping' ),
				esc_html( number_format_i18n( $range_start ) ),
				esc_html( number_format_i18n( $range_end ) ),
				esc_html( number_format_i18n( $total ) )
			);
			echo '</div>';

			echo '<div class="bobgo-pagination__controls">';
			$has_prev = $log_page > 1;
			$has_next = $log_page < $total_pages;

			if ( $has_prev ) {
				echo '<a class="button button-small" href="' . esc_url( $page_url( $log_page - 1 ) ) . '" aria-label="' . esc_attr__( 'Previous page', 'bobgo-shipping' ) . '">&laquo; ' . esc_html__( 'Previous', 'bobgo-shipping' ) . '</a>';
			} else {
				echo '<span class="button button-small disabled" aria-disabled="true">&laquo; ' . esc_html__( 'Previous', 'bobgo-shipping' ) . '</span>';
			}

			echo '<span class="bobgo-pagination__page">';
			printf(
				/* translators: 1: current page, 2: total pages. */
				esc_html__( 'Page %1$s of %2$s', 'bobgo-shipping' ),
				esc_html( number_format_i18n( $log_page ) ),
				esc_html( number_format_i18n( $total_pages ) )
			);
			echo '</span>';

			if ( $has_next ) {
				echo '<a class="button button-small" href="' . esc_url( $page_url( $log_page + 1 ) ) . '" aria-label="' . esc_attr__( 'Next page', 'bobgo-shipping' ) . '">' . esc_html__( 'Next', 'bobgo-shipping' ) . ' &raquo;</a>';
			} else {
				echo '<span class="button button-small disabled" aria-disabled="true">' . esc_html__( 'Next', 'bobgo-shipping' ) . ' &raquo;</span>';
			}
			echo '</div>';
			echo '</div>';
		} else {
			echo '<p class="bobgo-empty">' . esc_html__( 'No sync log entries match the current filters.', 'bobgo-shipping' ) . '</p>';
		}
		self::render_card_end();

		// Force Full Reconciliation Card.
		$is_connected = Api::is_connected();
		self::render_card_start(
			__( 'Force Full Reconciliation', 'bobgo-shipping' ),
			__( 'Manually trigger a full order reconciliation with Bob Go.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="force_reconciliation">
			<button type="submit" class="button button-secondary" <?php disabled( ! $is_connected ); ?>>
				<?php esc_html_e( 'Force Full Reconciliation', 'bobgo-shipping' ); ?>
			</button>
			<?php if ( ! $is_connected ) : ?>
				<p class="description"><?php esc_html_e( 'Connect your API key to enable reconciliation.', 'bobgo-shipping' ); ?></p>
			<?php endif; ?>
		</form>
		<?php
		self::render_card_end();

		self::render_main_end_sidebar_start();
		self::render_help_sidebar();
		self::render_layout_end();
	}

	/**
	 * Handle POST form submissions.
	 */
	public static function handle_post_actions(): void {
		// Only process on our settings page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page guard; nonce verified below before any data processing.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// Verify nonce before reading any POST data — accept either the standard settings nonce or the create-page nonce.
		$has_valid_nonce = false;
		if ( isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$has_valid_nonce = (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
		} elseif ( isset( $_POST['bobgo_create_page_nonce'] ) ) {
			$has_valid_nonce = (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bobgo_create_page_nonce'] ) ), 'bobgo_create_page' );
		}

		if ( ! $has_valid_nonce ) {
			return;
		}

		if ( ! isset( $_POST['bobgo_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['bobgo_action'] ) );

		switch ( $action ) {
			case 'save_rates':
				self::process_save_rates();
				break;
			case 'save_tracking':
				self::process_save_tracking();
				break;
			case 'save_checkout':
				self::process_save_checkout();
				break;
			case 'create_tracking_page':
				self::process_create_tracking_page();
				break;
			case 'save_api_settings':
				self::process_save_api_settings();
				break;
			case 'save_connection':
				self::process_save_connection();
				break;
			case 'disconnect':
				self::process_disconnect();
				break;
			case 'save_notifications':
				self::process_save_notifications();
				break;
			case 'reregister_webhooks':
				self::process_reregister_webhooks();
				break;
			case 'force_reconciliation':
				self::process_force_reconciliation();
				break;
			case 'save_vendor_settings':
				self::process_save_vendor_settings();
				break;
			case 'save_custom_fields':
				self::process_save_custom_fields();
				break;
			case 'save_display_options':
				self::process_save_display_options();
				break;
			case 'refresh_product_option_keys':
				self::process_refresh_product_option_keys();
				break;
		}
	}

	/**
	 * Process saving rates settings.
	 */
	private static function process_save_rates(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$rates = ( isset( $_POST['bobgo_rates'] ) && is_array( $_POST['bobgo_rates'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bobgo_rates'] ) ) : [];

		$settings = [
			'enabled'              => ! empty( $rates['enabled'] ) ? 'yes' : 'no',
			'hide'                 => ! empty( $rates['hide'] ) ? 'yes' : 'no',
			'Site_Address'         => ! empty( $rates['Site_Address'] ) ? 'yes' : 'no',
			'Additional_rate_info' => ! empty( $rates['Additional_rate_info'] ) ? 'yes' : 'no',
		];

		// The option name is WooCommerce core's own key for this shipping method's
		// settings — woocommerce_{method_id}_settings, created by
		// WC_Settings_API::get_option_key() — not a name this plugin chose.
		update_option( 'woocommerce_bobgo_shipping_settings', $settings );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Rates settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving tracking settings.
	 */
	private static function process_save_tracking(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options = self::get_bobgo_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$page_id = isset( $_POST['bobgo_options']['shipping_page'] ) ? absint( $_POST['bobgo_options']['shipping_page'] ) : 0;

		// Validate the page exists.
		if ( $page_id > 0 ) {
			$page = get_post( $page_id );
			if ( is_null( $page ) || 'page' !== $page->post_type ) {
				$page_id = 0;
			}
		}

		$options['shipping_page'] = $page_id;
		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Tracking settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving checkout settings.
	 */
	private static function process_save_checkout(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options = self::get_bobgo_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$options['suburb_at_checkout'] = isset( $_POST['bobgo_options']['suburb_at_checkout'] ) ? 1 : 0;

		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Checkout settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process creating a tracking page.
	 */
	private static function process_create_tracking_page(): void {
		if ( ! self::verify_nonce( 'bobgo_create_page_nonce', 'bobgo_create_page' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options = self::get_bobgo_options();

		// Check if a tracking page already exists.
		if ( ! empty( $options['shipping_page'] ) && get_post_status( $options['shipping_page'] ) ) {
			self::add_settings_message( __( 'A tracking page already exists.', 'bobgo-shipping' ), 'warning' );
			return;
		}

		// Check for existing page with title "Shipping details".
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
			$options['shipping_page'] = $existing_pages[0]->ID;
			update_option( 'bobgo', $options );
			WooCommerce::clear_options_cache();
			self::add_settings_message( __( 'Found an existing "Shipping details" page and configured it as your tracking page.', 'bobgo-shipping' ), 'success' );
			return;
		}

		// Create new page.
		$content = ! use_block_editor_for_post_type( 'page' )
			? '[bobgo bg_color="#000000" text_color="#ffffff"]'
			: '<!-- wp:bobgo/shipping /-->';

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Shipping details', 'bobgo-shipping' ),
				'post_content' => $content,
				'post_type'    => 'page',
				'post_status'  => 'publish',
			]
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$options['shipping_page'] = $page_id;
			update_option( 'bobgo', $options );
			WooCommerce::clear_options_cache();
			self::add_settings_message( __( 'Tracking page created successfully.', 'bobgo-shipping' ), 'success' );
		} else {
			self::add_settings_message( __( 'Failed to create tracking page.', 'bobgo-shipping' ), 'error' );
		}
	}

	/**
	 * Process saving API settings.
	 */
	private static function process_save_api_settings(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options = self::get_bobgo_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$api_data = ( isset( $_POST['bobgo_api'] ) && is_array( $_POST['bobgo_api'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bobgo_api'] ) ) : [];

		// Validate and save environment.
		$env                = isset( $api_data['env'] ) ? sanitize_text_field( $api_data['env'] ) : 'prod';
		$valid_envs         = array_keys( self::ENVIRONMENTS );
		$options['api_env'] = in_array( $env, $valid_envs, true ) ? $env : 'prod';

		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving vendor plugin settings.
	 */
	private static function process_save_vendor_settings(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options = self::get_bobgo_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$vendor_data = ( isset( $_POST['bobgo_vendor'] ) && is_array( $_POST['bobgo_vendor'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bobgo_vendor'] ) ) : [];

		$vendor_plugin            = $vendor_data['vendor_plugin'] ?? 'none';
		$options['vendor_plugin'] = in_array( $vendor_plugin, [ 'none', 'dokan' ], true ) ? $vendor_plugin : 'none';

		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Vendor settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving custom field mappings.
	 */
	private static function process_save_custom_fields(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per-field.
		$raw_fields = isset( $_POST['bobgo_custom_fields'] ) && is_array( $_POST['bobgo_custom_fields'] )
			? wp_unslash( $_POST['bobgo_custom_fields'] )
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$mappings   = [];
		$seen_pairs = [];
		$max_fields = 10;

		foreach ( $raw_fields as $field ) {
			if ( count( $mappings ) >= $max_fields ) {
				break;
			}

			if ( ! is_array( $field ) ) {
				continue;
			}

			$meta_key = isset( $field['field_key'] ) ? sanitize_text_field( $field['field_key'] ) : '';
			if ( empty( $meta_key ) ) {
				continue;
			}

			$destination = isset( $field['destination'] ) ? sanitize_key( $field['destination'] ) : 'tag';
			if ( ! in_array( $destination, [ 'tag', 'note' ], true ) ) {
				$destination = 'tag';
			}

			// Deduplicate by meta key + destination so the same key can route to both tag and note.
			$pair = $meta_key . '|' . $destination;
			if ( in_array( $pair, $seen_pairs, true ) ) {
				continue;
			}
			$seen_pairs[] = $pair;

			$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
			if ( empty( $label ) ) {
				$label = self::meta_key_to_label( $meta_key );
			}

			$mappings[] = [
				'field_key'   => $meta_key,
				'label'       => $label,
				'destination' => $destination,
			];
		}

		$options                          = self::get_bobgo_options();
		$options['custom_field_mappings'] = $mappings;
		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Custom field mappings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving product options (display options) settings.
	 *
	 * The stored setting is a blocklist: only keys that were rendered in the
	 * form AND left unticked are saved as excluded. Keys that never appeared
	 * in the form stay included, so newly added product options are sent
	 * without a settings visit.
	 */
	private static function process_save_display_options(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified at method entry via self::verify_nonce().
		$enabled = isset( $_POST['bobgo_display_options_enabled'] ) ? 1 : 0;

		$rendered = isset( $_POST['bobgo_display_options_rendered'] ) && is_array( $_POST['bobgo_display_options_rendered'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['bobgo_display_options_rendered'] ) )
			: [];
		$included = isset( $_POST['bobgo_display_options_included'] ) && is_array( $_POST['bobgo_display_options_included'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['bobgo_display_options_included'] ) )
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$excluded = array_values( array_diff( array_filter( $rendered ), $included ) );

		$options                                  = self::get_bobgo_options();
		$options['display_options_enabled']       = $enabled;
		$options['display_options_excluded_keys'] = $excluded;
		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Product options settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process refreshing the discovered product option keys (clears the cache).
	 */
	private static function process_refresh_product_option_keys(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		delete_transient( OrderMetaDiscovery::ITEM_CACHE_KEY );

		self::add_settings_message( __( 'Product option keys refreshed.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Convert a meta key to a human-readable label.
	 *
	 * @param string $key The meta key.
	 * @return string
	 */
	private static function meta_key_to_label( string $key ): string {
		// Strip leading underscore.
		$label = ltrim( $key, '_' );
		// Replace underscores and hyphens with spaces.
		$label = str_replace( [ '_', '-' ], ' ', $label );
		return ucwords( $label );
	}

	/**
	 * Render the Custom Order Fields card on the Advanced tab.
	 */
	private static function render_custom_fields_card(): void {
		$options    = self::get_bobgo_options();
		$mappings   = $options['custom_field_mappings'] ?? [];
		$meta_keys  = OrderMetaDiscovery::get_meta_keys();
		$max_fields = 10;

		self::render_card_start(
			__( 'Custom Order Fields', 'bobgo-shipping' ),
			__( 'Map WooCommerce order meta fields to Bob Go. Each mapping can be sent as a tag (visible in the Bob Go workspace) or appended to the order note.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_custom_fields">

			<table class="widefat bobgo-custom-fields-table" id="bobgo-custom-fields-table">
				<thead>
					<tr>
						<th class="bobgo-cf-col-key"><?php esc_html_e( 'Meta Key', 'bobgo-shipping' ); ?></th>
						<th class="bobgo-cf-col-label"><?php esc_html_e( 'Label', 'bobgo-shipping' ); ?></th>
						<th class="bobgo-cf-col-destination"><?php esc_html_e( 'Destination', 'bobgo-shipping' ); ?></th>
						<th class="bobgo-cf-col-sample"><?php esc_html_e( 'Sample', 'bobgo-shipping' ); ?></th>
						<th class="bobgo-cf-col-actions"><?php esc_html_e( 'Actions', 'bobgo-shipping' ); ?></th>
					</tr>
				</thead>
				<tbody id="bobgo-custom-fields-tbody">
					<?php
					if ( ! empty( $mappings ) ) :
						foreach ( $mappings as $index => $mapping ) :
							$meta_key    = $mapping['field_key'] ?? '';
							$label       = $mapping['label'] ?? '';
							$destination = $mapping['destination'] ?? 'tag';
							?>
							<tr class="bobgo-custom-field-row">
								<td>
									<select name="bobgo_custom_fields[<?php echo (int) $index; ?>][field_key]" class="bobgo-cf-key-select">
										<option value=""><?php esc_html_e( '— Select meta key —', 'bobgo-shipping' ); ?></option>
										<?php foreach ( $meta_keys as $key ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta_key, $key ); ?>><?php echo esc_html( $key ); ?></option>
										<?php endforeach; ?>
										<?php if ( ! empty( $meta_key ) && ! in_array( $meta_key, $meta_keys, true ) ) : ?>
											<option value="<?php echo esc_attr( $meta_key ); ?>" selected><?php echo esc_html( $meta_key ); ?></option>
										<?php endif; ?>
									</select>
								</td>
								<td>
									<input type="text" name="bobgo_custom_fields[<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="bobgo-cf-label-input" placeholder="<?php esc_attr_e( 'Auto-generated if empty', 'bobgo-shipping' ); ?>">
								</td>
								<td>
									<select name="bobgo_custom_fields[<?php echo (int) $index; ?>][destination]" class="bobgo-cf-destination-select">
										<option value="tag" <?php selected( $destination, 'tag' ); ?>><?php esc_html_e( 'Tag', 'bobgo-shipping' ); ?></option>
										<option value="note" <?php selected( $destination, 'note' ); ?>><?php esc_html_e( 'Order note', 'bobgo-shipping' ); ?></option>
									</select>
								</td>
								<td>
									<code class="bobgo-sample-value" data-key="<?php echo esc_attr( $meta_key ); ?>">—</code>
								</td>
								<td>
									<button type="button" class="button bobgo-remove-field" title="<?php esc_attr_e( 'Remove', 'bobgo-shipping' ); ?>">
										<span class="dashicons dashicons-trash"></span>
									</button>
								</td>
							</tr>
							<?php
						endforeach;
					endif;
					?>
				</tbody>
			</table>

			<div class="bobgo-custom-fields-toolbar">
				<button type="button" class="button button-secondary bobgo-button--icon" id="bobgo-add-field" <?php disabled( count( $mappings ) >= $max_fields ); ?>>
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Field', 'bobgo-shipping' ); ?>
				</button>
				<button type="button" class="button button-secondary bobgo-button--icon" id="bobgo-refresh-keys">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Keys', 'bobgo-shipping' ); ?>
				</button>
				<span class="bobgo-field-counter" id="bobgo-field-counter">
					<?php
					printf(
						/* translators: 1: current count 2: maximum */
						esc_html__( '%1$d of %2$d fields used', 'bobgo-shipping' ),
						count( $mappings ),
						(int) $max_fields
					);
					?>
				</span>
			</div>

			<template id="bobgo-custom-field-template">
				<tr class="bobgo-custom-field-row">
					<td>
						<select name="bobgo_custom_fields[__INDEX__][field_key]" class="bobgo-cf-key-select">
							<option value=""><?php esc_html_e( '— Select meta key —', 'bobgo-shipping' ); ?></option>
							<?php foreach ( $meta_keys as $key ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<input type="text" name="bobgo_custom_fields[__INDEX__][label]" value="" class="bobgo-cf-label-input" placeholder="<?php esc_attr_e( 'Auto-generated if empty', 'bobgo-shipping' ); ?>">
					</td>
					<td>
						<select name="bobgo_custom_fields[__INDEX__][destination]" class="bobgo-cf-destination-select">
							<option value="tag"><?php esc_html_e( 'Tag', 'bobgo-shipping' ); ?></option>
							<option value="note"><?php esc_html_e( 'Order note', 'bobgo-shipping' ); ?></option>
						</select>
					</td>
					<td>
						<code class="bobgo-sample-value">—</code>
					</td>
					<td>
						<button type="button" class="button bobgo-remove-field" title="<?php esc_attr_e( 'Remove', 'bobgo-shipping' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</td>
				</tr>
			</template>

			<?php submit_button( __( 'Save Custom Fields', 'bobgo-shipping' ) ); ?>
		</form>
		<?php
		self::render_card_end();
	}

	/**
	 * Render the Product Options card on the Advanced tab.
	 *
	 * Controls whether visible line-item meta (product add-on selections,
	 * variation attributes, personalisation fields) is sent to Bob Go as
	 * display_options, and lets the merchant exclude individual keys.
	 */
	private static function render_display_options_card(): void {
		$options  = self::get_bobgo_options();
		$enabled  = ! isset( $options['display_options_enabled'] ) || ! empty( $options['display_options_enabled'] );
		$excluded = isset( $options['display_options_excluded_keys'] ) && is_array( $options['display_options_excluded_keys'] )
			? $options['display_options_excluded_keys']
			: [];

		// Show every discovered key plus any excluded key that is no longer
		// discovered, so a stale exclusion can still be re-enabled.
		$keys = array_values( array_unique( array_merge( OrderMetaDiscovery::get_item_meta_keys(), $excluded ) ) );
		sort( $keys );

		$render_cap = 100;
		$overflow   = count( $keys ) - $render_cap;
		$keys       = array_slice( $keys, 0, $render_cap );

		self::render_card_start(
			__( 'Product Options', 'bobgo-shipping' ),
			__( 'Product options are the choices a customer makes per item at checkout — add-on selections, variation attributes, gift messages and similar fields. They are sent to Bob Go with each order item so they are visible for fulfilment.', 'bobgo-shipping' )
		);
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="save_display_options">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send product options', 'bobgo-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bobgo_display_options_enabled" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'Send product options with order items', 'bobgo-shipping' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When disabled, no product options are sent, regardless of the list below.', 'bobgo-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php if ( empty( $keys ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No product option keys found in existing orders yet. Keys appear here once orders with product options have been placed.', 'bobgo-shipping' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th class="check-column"><?php esc_html_e( 'Send', 'bobgo-shipping' ); ?></th>
							<th><?php esc_html_e( 'Option Key', 'bobgo-shipping' ); ?></th>
							<th><?php esc_html_e( 'Sample', 'bobgo-shipping' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $keys as $key ) : ?>
							<tr>
								<td class="check-column">
									<input type="hidden" name="bobgo_display_options_rendered[]" value="<?php echo esc_attr( $key ); ?>">
									<input type="checkbox" name="bobgo_display_options_included[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! in_array( $key, $excluded, true ) ); ?>>
								</td>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td><code><?php echo esc_html( OrderMetaDiscovery::get_item_sample_value( $key ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $overflow > 0 ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of additional keys not shown */
							esc_html__( '%d more keys are not shown. Keys not listed here are always sent.', 'bobgo-shipping' ),
							(int) $overflow
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Untick a key to stop sending it. Keys are discovered from existing orders; new option keys are sent automatically until unticked here.', 'bobgo-shipping' ); ?>
				</p>
			<?php endif; ?>

			<?php submit_button( __( 'Save Product Options', 'bobgo-shipping' ) ); ?>
		</form>

		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bobgo_action" value="refresh_product_option_keys">
			<button type="submit" class="button button-secondary bobgo-button--icon">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Refresh Keys', 'bobgo-shipping' ); ?>
			</button>
		</form>
		<?php
		self::render_card_end();
	}

	/**
	 * Process saving connection settings.
	 */
	private static function process_save_connection(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		$posted_api_key        = isset( $_POST['bobgo_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bobgo_api_key'] ) ) : '';
		$posted_webhook_secret = isset( $_POST['bobgo_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['bobgo_webhook_secret'] ) ) : '';
		$clear_api_key         = ! empty( $_POST['bobgo_clear_api_key'] );
		$clear_webhook_secret  = ! empty( $_POST['bobgo_clear_webhook_secret'] );

		// Empty submission means "keep existing"; the explicit clear checkbox is the way to remove a stored secret.
		$api_key_changed = false;
		$save_ok         = true;
		if ( $clear_api_key ) {
			// Mirror Disconnect: tear down remote webhooks while the key is still
			// present, then clear the key and the cached health record.
			Webhook::deregister_subscriptions();
			Webhook::mark_deliberately_disconnected( true );
			Api::save_api_key( '' );
			Api::clear_connection_health();
			$api_key_changed = true;
		} elseif ( '' !== $posted_api_key ) {
			$save_ok         = (bool) Api::save_api_key( $posted_api_key );
			$api_key_changed = true;
		}

		if ( $clear_webhook_secret ) {
			Api::save_webhook_secret( '' );
		} elseif ( '' !== $posted_webhook_secret ) {
			Api::save_webhook_secret( $posted_webhook_secret );
		}

		$current_api_key = Api::get_api_key();
		if ( $api_key_changed && $save_ok && '' !== $current_api_key ) {
			// A new key was entered: validate it (the key is kept either way, per
			// product decision) and (re)register webhooks. An unchanged key is not
			// re-validated/re-registered — that only churns the API and could
			// false-alarm on a transient blip; the manual "Re-register" button covers
			// deliberate re-registration. A 401/404 is surfaced as one clear error
			// instead of the old "saved" banner plus a buried webhook warning. Phase 3 #1.
			$validation     = Api::validate_connection();
			$bad_key_status = in_array( (int) ( $validation['status'] ?? 0 ), [ 401, 404 ], true );

			if ( ! $validation['success'] && $bad_key_status ) {
				self::add_settings_message(
					sprintf(
						/* translators: %s: validation error, e.g. "Invalid API key." or "Account not found. Please check your API key." */
						__( 'Connection saved, but it could not be verified: %s Shipping rates, order sync, and tracking will not work until this is resolved.', 'bobgo-shipping' ),
						$validation['message']
					),
					'error'
				);
			} else {
				// A disconnect queues a link reset that runs in the background.
				// Reconnecting is the merchant saying "carry on with this store" —
				// letting that pass continue would strip the links off the rest of their
				// order history against a live connection, with nothing re-queued.
				// Cancel it before the account check, which arms its own reset if the
				// account moved.
				$history_partly_unlinked = LinkAudit::cancel_link_reset( 'connection saved again' );

				// Valid, or temporarily unverifiable (network/transient) — register
				// webhooks and schedule jobs as before. The account check runs first:
				// a key pointing at a different account invalidates every stored order
				// link, and those must be scheduled for removal before the new
				// account's webhooks start delivering against them.
				$account_verdict = LinkAudit::check_for_account_switch();

				// Cancelling a pass that had already unlinked orders leaves the history
				// in two states: the orders it reached have no link, the rest still point
				// at the previous account. Nothing else repairs either — the audit and
				// reconciliation both only look at orders that still carry a link. So
				// run a fresh pass, this time re-syncing, which strips what is stale and
				// re-establishes every open order on the account now connected.
				//
				// Only then. A pass cancelled before its first batch changed nothing, and
				// starting one would take an intact history apart for no reason: an
				// accidental disconnect, undone a few seconds later, would cost every
				// completed order its tracking, which no re-sync brings back. Skipped
				// too when the account check has already armed its own reset.
				if ( $history_partly_unlinked && 'changed' !== $account_verdict ) {
					LinkAudit::schedule_link_reset( 'connection re-saved while a link reset was part-way through', true );
				}

				$webhook_result = Webhook::register_subscriptions();
				Reconciliation::schedule();
				Reconciliation::schedule_purge();
				if ( ! empty( $webhook_result['success'] ) ) {
					self::add_settings_message( __( 'Connection saved successfully.', 'bobgo-shipping' ), 'success' );
				} else {
					self::add_settings_message(
						sprintf(
							/* translators: %s: webhook registration error message */
							__( 'Connection saved, but webhook registration failed: %s. Order and shipment updates from Bob Go will not be received until this is resolved.', 'bobgo-shipping' ),
							$webhook_result['message'] ?? __( 'Unknown error.', 'bobgo-shipping' )
						),
						'warning'
					);
				}
			}
		} elseif ( $api_key_changed && '' === $current_api_key ) {
			self::add_settings_message( __( 'API key cleared.', 'bobgo-shipping' ), 'warning' );
		} elseif ( ! $save_ok ) {
			self::add_settings_message( __( 'Failed to save connection.', 'bobgo-shipping' ), 'error' );
		} else {
			self::add_settings_message( __( 'Settings saved.', 'bobgo-shipping' ), 'success' );
		}
	}

	/**
	 * Process disconnecting from Bob Go.
	 */
	private static function process_disconnect(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Deregister remote webhooks BEFORE clearing the key — the deregister call
		// needs to authenticate against the Bob Go API to list and delete subscriptions.
		Webhook::deregister_subscriptions();
		Reconciliation::unschedule();
		Api::save_api_key( '' );
		Api::save_webhook_secret( '' );
		// Clear the cached connection-health record and force the webhooks marker
		// false so no stale "ok"/"registered" state survives a key removal. The
		// deliberate-disconnect flag (distinct from the observed marker) is what
		// stops the daily health check from resurrecting the subscriptions.
		Api::clear_connection_health();
		Webhook::mark_subscriptions_registered( false );
		Webhook::mark_deliberately_disconnected( true );

		// Strip every stored Bob Go order link. A store that reconnects — to this
		// account or another — must not carry links it can no longer vouch for.
		// Unlike an account switch the unlinked orders are NOT re-queued for sync:
		// stopping that is the point of disconnecting. The pass is batched, so the
		// links clear in the background rather than in this request; how long that
		// takes is a matter of how many orders carry one, so the notice does not
		// promise a time.
		//
		// The recorded account code stays put. Queueing this pass is not the same
		// as finishing it — an immediate reconnect cancels it, and a store whose
		// Action Scheduler queue is not running never starts it — so the code is
		// what lets a reconnect to a different account still be recognised as a
		// switch and clear the links this pass may not have reached. See
		// Api::OPTION_ACCOUNT_CODE.
		LinkAudit::schedule_link_reset( 'store disconnected from Bob Go', false );

		self::add_settings_message( __( 'Disconnected from Bob Go. Stored Bob Go order links are being removed in the background.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process saving notification settings.
	 */
	private static function process_save_notifications(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		$options             = self::get_bobgo_options();
		$email_notifications = isset( $_POST['bobgo_email_notifications'] ) ? sanitize_text_field( wp_unslash( $_POST['bobgo_email_notifications'] ) ) : 'woocommerce';

		$valid_values                   = [ 'woocommerce', 'bobgo' ];
		$options['email_notifications'] = in_array( $email_notifications, $valid_values, true ) ? $email_notifications : 'woocommerce';

		$options['custom_order_statuses'] = isset( $_POST['bobgo_custom_order_statuses'] ) ? 1 : 0;
		$options['shipped_email_enabled'] = isset( $_POST['bobgo_shipped_email_enabled'] ) ? 1 : 0;

		update_option( 'bobgo', $options );
		WooCommerce::clear_options_cache();

		self::add_settings_message( __( 'Notification settings saved.', 'bobgo-shipping' ), 'success' );
	}

	/**
	 * Process re-registering webhook subscriptions.
	 */
	private static function process_reregister_webhooks(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$result = Webhook::register_subscriptions();

		if ( $result['success'] ) {
			self::add_settings_message( $result['message'], 'success' );
		} else {
			self::add_settings_message( $result['message'], 'error' );
		}
	}

	/**
	 * Process forcing a full reconciliation.
	 */
	private static function process_force_reconciliation(): void {
		if ( ! self::verify_nonce( self::NONCE_FIELD, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! Api::is_connected() ) {
			self::add_settings_message( __( 'Cannot run reconciliation: not connected.', 'bobgo-shipping' ), 'error' );
			return;
		}

		$result = Reconciliation::handle_full_reconciliation();

		if ( $result ) {
			self::add_settings_message( __( 'Full reconciliation queued. Progress will appear in the sync log as batches complete.', 'bobgo-shipping' ), 'success' );
		} else {
			self::add_settings_message( __( 'Could not queue reconciliation. Action Scheduler may be unavailable.', 'bobgo-shipping' ), 'error' );
		}
	}

	/**
	 * Handle AJAX test connection request.
	 */
	public static function handle_ajax_test_connection(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bobgo_test_connection' ) ) {
			wp_send_json_error(
				[
					'html'  => self::render_notice_html( __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ), 'error' ),
					'nonce' => wp_create_nonce( 'bobgo_test_connection' ),
				]
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'html' => self::render_notice_html( __( 'Permission denied.', 'bobgo-shipping' ), 'error' ) ] );
		}

		ob_start();
		$shipping = new Shipping();
		$shipping->test_shipping();
		$html = ob_get_clean();

		$response = [ 'html' => $html ];

		// The test records what it proved about the connection, which is exactly
		// what the Overview's banner and Connection card report — so hand back a
		// freshly rendered copy of them for the page to swap in. Read after the
		// test, so it reflects what the test just wrote.
		$status_html = self::render_overview_status_html( (string) sanitize_key( wp_unslash( $_POST['context'] ?? '' ) ) );
		if ( '' !== $status_html ) {
			$response['statusHtml'] = $status_html;
		}

		wp_send_json_success( $response );
	}

	/**
	 * The Overview's connection status region, re-rendered from current state.
	 *
	 * Returns an empty string for any context other than the Overview: the same
	 * Test Connection card sits on the Rates tab, which has no status cards, and
	 * building markup nothing will display would be waste — the snapshot behind it
	 * runs several queue and log queries.
	 *
	 * @param string $context Which page the test was run from.
	 * @return string
	 */
	private static function render_overview_status_html( string $context ): string {
		if ( 'overview' !== $context ) {
			return '';
		}

		ob_start();
		self::render_overview_status(
			Health::snapshot(),
			self::tab_url( self::TAB_CONNECTION ),
			self::tab_url( self::TAB_ADVANCED )
		);

		return (string) ob_get_clean();
	}

	/**
	 * Handle AJAX test API connection request.
	 */
	public static function handle_ajax_test_api_connection(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bobgo_test_api_connection' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ),
					'nonce'   => wp_create_nonce( 'bobgo_test_api_connection' ),
				]
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		$result = Api::validate_connection();

		if ( $result['success'] ) {
			wp_send_json_success( [ 'message' => __( 'Connection successful. Your API key is valid.', 'bobgo-shipping' ) ] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	/**
	 * Handle AJAX force reconciliation request.
	 */
	public static function handle_ajax_force_reconciliation(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bobgo_force_reconciliation' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ),
					'nonce'   => wp_create_nonce( 'bobgo_force_reconciliation' ),
				]
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		if ( ! Api::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Not connected. Please configure your API key first.', 'bobgo-shipping' ) ] );
		}

		$result = Reconciliation::handle_full_reconciliation();

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Full reconciliation queued. Progress will appear in the sync log as batches complete.', 'bobgo-shipping' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not queue reconciliation. Action Scheduler may be unavailable.', 'bobgo-shipping' ) ] );
		}
	}

	/**
	 * Verify nonce for form submissions.
	 *
	 * @param string $nonce_field The nonce field name.
	 * @param string $nonce_action The nonce action.
	 * @return bool
	 */
	private static function verify_nonce( string $nonce_field, string $nonce_action ): bool {
		if ( ! isset( $_POST[ $nonce_field ] ) ) {
			return false;
		}
		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action );
	}

	/**
	 * Add a settings message to be displayed on the next page render.
	 *
	 * @param string $message The message text.
	 * @param string $type    Message type: success, error, warning, info.
	 */
	private static function add_settings_message( string $message, string $type = 'success' ): void {
		add_settings_error( 'bobgo_settings', 'bobgo_message', $message, $type );
	}

	/**
	 * Build a notice HTML string for AJAX responses.
	 *
	 * @param string $message The notice message.
	 * @param string $type    Notice type: 'success', 'error', 'warning', 'info'.
	 * @return string
	 */
	private static function render_notice_html( string $message, string $type = 'info' ): string {
		$icon_map = [
			'success' => 'dashicons-yes-alt',
			'error'   => 'dashicons-warning',
			'warning' => 'dashicons-info',
			'info'    => 'dashicons-info',
		];
		$icon     = $icon_map[ $type ] ?? $icon_map['info'];

		return '<div class="bobgo-notice bobgo-notice--' . esc_attr( $type ) . '" role="alert">'
			. '<span class="bobgo-notice__icon dashicons ' . esc_attr( $icon ) . '"></span>'
			. '<p class="bobgo-notice__message">' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * Render settings notices with Bob Go styling.
	 *
	 * Replaces WordPress's default notice rendering with custom-styled
	 * notifications positioned below the tab navigation.
	 */
	private static function render_notices(): void {
		$errors = get_settings_errors( 'bobgo_settings' );
		if ( empty( $errors ) ) {
			return;
		}

		$icon_map = [
			'success' => 'dashicons-yes-alt',
			'error'   => 'dashicons-warning',
			'warning' => 'dashicons-info',
			'info'    => 'dashicons-info',
		];

		echo '<div class="bobgo-notices">';
		foreach ( $errors as $error ) {
			$type            = $error['type'] ?? 'info';
			$icon            = $icon_map[ $type ] ?? $icon_map['info'];
			$auto_dismiss_ms = ( 'success' === $type ) ? '4000' : '';
			?>
			<div class="bobgo-notice bobgo-notice--<?php echo esc_attr( $type ); ?>"<?php echo '' !== $auto_dismiss_ms ? ' data-auto-dismiss="' . esc_attr( $auto_dismiss_ms ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- conditional attribute pre-escaped above ?> role="alert">
				<span class="bobgo-notice__icon dashicons <?php echo esc_attr( $icon ); ?>"></span>
				<p class="bobgo-notice__message"><?php echo esc_html( $error['message'] ); ?></p>
				<button type="button" class="bobgo-notice__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'bobgo-shipping' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Get bobgo options from database.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_bobgo_options(): array {
		return WooCommerce::get_options();
	}

	/**
	 * Get shipping settings from database.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_shipping_settings(): array {
		return WooCommerce::get_shipping_settings();
	}

	/**
	 * Get the currently configured environment.
	 *
	 * @return string The environment key (dev, stage, prod).
	 */
	public static function get_current_env(): string {
		$options = self::get_bobgo_options();
		$env     = $options['api_env'] ?? 'prod';
		return isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'prod';
	}

	/**
	 * Get the current base URL based on configured environment.
	 *
	 * @return string The base URL.
	 */
	public static function get_base_url(): string {
		return self::ENVIRONMENTS[ self::get_current_env() ] ?? self::ENVIRONMENTS['prod'];
	}

	/**
	 * Build a link into the Bob Go workspace for the configured environment.
	 *
	 * Every merchant-facing link out of the plugin goes through here, so a store
	 * pointed at dev or sandbox is never sent to the production workspace.
	 *
	 * @param string $path Path to append, for example 'sales-channels'.
	 * @return string The absolute workspace URL.
	 */
	public static function get_dashboard_url( string $path = '' ): string {
		$base = self::DASHBOARD_URLS[ self::get_current_env() ] ?? self::DASHBOARD_URLS['prod'];
		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Render the Test Connection card.
	 * Used by both Overview and Rates tabs.
	 *
	 * @param string $context Unique context to prevent duplicate element IDs (e.g., 'overview', 'rates').
	 */
	private static function render_test_connection_card( string $context = 'default' ): void {
		$btn_id    = 'bobgo-test-connection-btn-' . $context;
		$result_id = 'bobgo-test-connection-result-' . $context;

		// Only the Overview carries status cards a test can invalidate, so only it
		// asks for the refresh — on the Rates tab there is nothing to re-render.
		$refresh_target = 'overview' === $context ? '#' . self::OVERVIEW_STATUS_ID : '';

		self::render_card_start( __( 'Test Connection', 'bobgo-shipping' ), __( 'Verify your store can reach the Bob Go rates API.', 'bobgo-shipping' ) );
		?>
		<div id="<?php echo esc_attr( $result_id ); ?>"></div>
		<button type="button" class="button button-secondary"
			id="<?php echo esc_attr( $btn_id ); ?>"
			data-bobgo-test="rates"
			data-action="bobgo_test_connection"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'bobgo_test_connection' ) ); ?>"
			<?php if ( '' !== $refresh_target ) : ?>
				data-refresh-target="<?php echo esc_attr( $refresh_target ); ?>"
			<?php endif; ?>
			data-result-target="#<?php echo esc_attr( $result_id ); ?>">
			<?php esc_html_e( 'Test Connection', 'bobgo-shipping' ); ?>
		</button>
		<?php
		self::render_card_end();
	}

	/**
	 * Get system information for the Advanced tab.
	 *
	 * @return array<string, string>
	 */
	private static function get_system_info(): array {
		$checkout_type = 'Classic';
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			// Check if the checkout page uses blocks.
			$checkout_page_id = wc_get_page_id( 'checkout' );
			if ( $checkout_page_id ) {
				$checkout_page = get_post( $checkout_page_id );
				if ( $checkout_page && has_block( 'woocommerce/checkout', $checkout_page ) ) {
					$checkout_type = 'Blocks';
				}
			}
		}

		return [
			'plugin_version'      => defined( 'BOBGO_SHIPPING_VERSION' ) ? BOBGO_SHIPPING_VERSION : 'Unknown',
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'Not installed',
			'php_version'         => phpversion(),
			'checkout_type'       => $checkout_type,
			'api_domain'          => Admin::get_api_domain(),
		];
	}

	/**
	 * Render two-column layout start.
	 */
	private static function render_layout_start(): void {
		echo '<div class="bobgo-layout">';
		echo '<div class="bobgo-main">';
	}

	/**
	 * Render main column end and sidebar start.
	 */
	private static function render_main_end_sidebar_start(): void {
		echo '</div>'; // .bobgo-main
		echo '<div class="bobgo-sidebar">';
	}

	/**
	 * Render layout end.
	 */
	private static function render_layout_end(): void {
		echo '</div>'; // .bobgo-sidebar
		echo '</div>'; // .bobgo-layout
	}

	/**
	 * Render a card using WP postbox pattern.
	 *
	 * @param string $title       Card title.
	 * @param string $description Optional card description.
	 */
	private static function render_card_start( string $title, string $description = '' ): void {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div>';
		echo '<div class="inside">';
		if ( $description ) {
			echo '<p class="description bobgo-card-description">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Render card end.
	 */
	private static function render_card_end(): void {
		echo '</div></div>'; // .inside, .postbox
	}

	/**
	 * Render help sidebar content.
	 */
	private static function render_help_sidebar(): void {
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Resources', 'bobgo-shipping' ); ?></h2></div>
			<div class="inside">
				<ul class="bobgo-help-links">
					<li>
						<a href="<?php echo esc_url( self::get_dashboard_url() ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-dashboard"></span>
							<?php esc_html_e( 'Bob Go Workspace', 'bobgo-shipping' ); ?>
							<span class="bobgo-help-link-arrow">&#8599;</span>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( self::get_dashboard_url( 'sales-channels' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-store"></span>
							<?php esc_html_e( 'Sales Channels', 'bobgo-shipping' ); ?>
							<span class="bobgo-help-link-arrow">&#8599;</span>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( self::get_dashboard_url( 'rates-at-checkout' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-tag"></span>
							<?php esc_html_e( 'Rates at Checkout', 'bobgo-shipping' ); ?>
							<span class="bobgo-help-link-arrow">&#8599;</span>
						</a>
					</li>
					<li>
						<a href="https://www.bobgo.co.za/help" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-sos"></span>
							<?php esc_html_e( 'Helpdesk', 'bobgo-shipping' ); ?>
							<span class="bobgo-help-link-arrow">&#8599;</span>
						</a>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Check whether the sync log table exists.
	 */
	private static function sync_log_table_exists(): bool {
		return SyncLog::table_exists();
	}

	/**
	 * Render a status item for an overview banner.
	 *
	 * @param string $label    Status label.
	 * @param string $state    One of the Health::STATE_* values.
	 * @param string $text     Status value.
	 * @param string $link     Optional link for the value.
	 * @param string $detail   Optional supporting line under the value.
	 * @param bool   $external Whether the link leaves WordPress.
	 */
	private static function render_status_item( string $label, string $state, string $text, string $link = '', string $detail = '', bool $external = false ): void {
		$icons = [
			Health::STATE_OK      => 'yes-alt',
			Health::STATE_WARNING => 'warning',
			Health::STATE_ERROR   => 'dismiss',
			Health::STATE_NEUTRAL => 'marker',
		];
		$icon  = $icons[ $state ] ?? 'marker';
		$state = isset( $icons[ $state ] ) ? $state : Health::STATE_NEUTRAL;
		?>
		<div class="bobgo-status-item bobgo-status-item--<?php echo esc_attr( $state ); ?>">
			<span class="bobgo-status-icon"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span></span>
			<span class="bobgo-status-text">
				<strong><?php echo esc_html( $label ); ?></strong>
				<span>
					<?php echo esc_html( $text ); ?>
					<?php if ( $link ) : ?>
						<a href="<?php echo esc_url( $link ); ?>" class="bobgo-status-item-link"
							<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>&rarr;</a>
					<?php endif; ?>
				</span>
				<?php if ( '' !== $detail ) : ?>
					<em class="bobgo-status-detail"><?php echo esc_html( $detail ); ?></em>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}
}
