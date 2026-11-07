<?php
/**
 * Inbound webhook handler — REST API endpoint for Bob Go webhooks.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Webhook
 *
 * Registers REST endpoint, routes webhook topics, and processes fulfillment/tracking events.
 */
class Webhook {

	use Loggable;

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'bobgo/v1';

	/**
	 * HMAC algorithm used to sign webhook payloads.
	 */
	const HMAC_ALGO = 'sha256';

	/**
	 * Header name carrying the base64 HMAC-SHA256 signature from Bob Go.
	 */
	const SIGNATURE_HEADER = 'Bobgo-Webhook-Signature';

	/**
	 * Webhook topics.
	 */
	const TOPIC_TRACKING_UPDATED    = 'tracking/updated';
	const TOPIC_FULFILLMENT_CREATED = 'fulfillment/created';
	const TOPIC_ORDER_UPDATED       = 'order/updated';

	/**
	 * Maximum sane length for an inbound delivery-address field. WooCommerce
	 * stores HPOS address columns as varchar(255); anything longer is corrupt
	 * data (typically sync-loop growth), not a real address.
	 */
	const ADDRESS_FIELD_MAX_LENGTH = 255;

	/**
	 * Number of identical comma-separated street-address segments at which the
	 * address is treated as loop-corrupted. Two repeats can occur in odd but
	 * legitimate addresses; three or more is the signature of re-ingestion
	 * growth ("Baronrath Road, 203 Park Corner, 203 Park Corner, …").
	 */
	const ADDRESS_REPEAT_LIMIT = 3;

	/**
	 * Transient key for cached webhook subscription list.
	 */
	const TRANSIENT_SUBSCRIPTIONS = 'bobgo_webhook_subscriptions';

	/**
	 * Cache TTL for the subscription list, in seconds.
	 */
	const SUBSCRIPTIONS_TTL = 300;

	/**
	 * Option recording whether this store's webhook subscriptions are registered,
	 * as far as the plugin knows (true after a successful register, false after a
	 * deregister). Lets the Overview/notice detect the dead-webhook state with no
	 * API call. Null/unset means "unknown" (installs from before this existed) and
	 * is treated as registered to avoid false warnings. See Phase 3 #2.
	 */
	const OPTION_SUBSCRIPTIONS_REGISTERED = 'bobgo_webhooks_registered';

	/**
	 * Option holding the Unix timestamp of the last conclusive webhook
	 * health check (see maybe_check_subscription_health()).
	 */
	const OPTION_LAST_HEALTH_CHECK = 'bobgo_webhooks_last_health_check';

	/**
	 * Option recording that the MERCHANT deliberately disconnected webhooks
	 * (Disconnect button / API-key removal). Deliberate intent, not observed
	 * state: OPTION_SUBSCRIPTIONS_REGISTERED also reads false after a FAILED
	 * registration attempt, and the daily health check once conflated the two —
	 * one transient re-registration failure permanently disabled self-healing.
	 * Set only by the deliberate disconnect flows; cleared by any registration
	 * attempt (which expresses intent to be connected).
	 */
	const OPTION_WEBHOOKS_DISCONNECTED = 'bobgo_webhooks_disconnected';

	/**
	 * Register REST API routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Verify the HMAC signature on an incoming webhook request.
	 *
	 * Computes a base64-encoded HMAC-SHA256 of the raw request body using the
	 * stored webhook secret and compares it against the signature header in
	 * constant time. Rejects when no secret is configured or no signature
	 * header is sent — every webhook must be authenticated.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return bool True when the request is authenticated.
	 */
	public static function verify_signature( WP_REST_Request $request ): bool {
		$secret = Api::get_webhook_secret();
		if ( empty( $secret ) ) {
			return false;
		}

		$signature = $request->get_header( self::SIGNATURE_HEADER );
		if ( empty( $signature ) ) {
			return false;
		}

		// Bob Go signs the raw request body and sends the digest base64-encoded.
		$expected = base64_encode( hash_hmac( self::HMAC_ALGO, $request->get_body(), $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HMAC digests are binary; signature is base64-encoded by the sender.

		return hash_equals( $expected, $signature );
	}

	/**
	 * Handle incoming webhook request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		// Authenticate first — unauthenticated callers must not reach the JSON parser or topic router.
		if ( ! self::verify_signature( $request ) ) {
			self::log( 'Webhook signature verification failed.', 'warning' );
			self::log_webhook( 0, 'webhook_rejected', [], false, '', 'Invalid webhook signature', 403 );
			return new WP_REST_Response( [ 'error' => 'Invalid webhook signature' ], 403 );
		}

		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			self::log_webhook( 0, 'webhook_rejected', [], false, '', 'Invalid payload' );
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		// Extract topic from payload or headers.
		$topic    = $body['topic'] ?? $request->get_header( 'X-Bobgroup-Topic' ) ?? '';
		$event_id = $body['event_id'] ?? $request->get_header( 'Bob-Go-Request-Id' ) ?? $request->get_header( 'X-Request-Id' ) ?? '';

		if ( empty( $topic ) ) {
			self::log_webhook( 0, 'webhook_rejected', $body, false, $event_id, 'Missing topic' );
			return new WP_REST_Response( [ 'error' => 'Missing topic' ], 400 );
		}

		// A payload with no order reference is acknowledged, not rejected.
		// Subscriptions are account-wide, so every event on the account is
		// delivered here — including manual/standalone shipments that aren't
		// linked to any channel order and legitimately carry no reference
		// (all of channel_ref_id, channel_order_number, order_number and
		// custom_order_name empty; shipments linked to a Woo order always
		// carry at least an order number). Not an error: a 4xx counts as a
		// delivery failure on the Bob Go side, and a stretch where manual
		// shipments are the only traffic (quiet weekend) trips the three-day
		// failure window that disables the whole subscription — after which
		// all WooCommerce tracking updates silently stop. Debug-log only:
		// stores shipping mostly outside the Woo channel produce thousands
		// of these, which would drown the sync log.
		$known_topics = [ self::TOPIC_FULFILLMENT_CREATED, self::TOPIC_TRACKING_UPDATED, self::TOPIC_ORDER_UPDATED ];
		if ( in_array( $topic, $known_topics, true ) && '' === self::extract_order_ref( $body ) ) {
			self::log( "Ignored {$topic} webhook with no order reference (event: {$event_id}) — shipment has no WooCommerce counterpart." );
			return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
		}

		// Always process — handlers full-replace local state from the Bob Go API,
		// so retries are safe. Duplicate log rows are suppressed inside log_webhook().
		switch ( $topic ) {
			case self::TOPIC_FULFILLMENT_CREATED:
				return self::handle_fulfillment_created( $body, $event_id );

			case self::TOPIC_TRACKING_UPDATED:
				return self::handle_tracking_updated( $body, $event_id );

			case self::TOPIC_ORDER_UPDATED:
				return self::handle_order_updated( $body, $event_id );

			default:
				self::log( "Unknown webhook topic: {$topic}", 'warning' );
				self::log_webhook( 0, 'webhook_rejected', $body, false, $event_id, 'Unknown topic' );
				return new WP_REST_Response( [ 'error' => 'Unknown topic' ], 400 );
		}
	}

	/**
	 * Handle fulfillment created webhook.
	 *
	 * @param array<string, mixed> $data     Webhook payload.
	 * @param string               $event_id Event ID for idempotency.
	 * @return WP_REST_Response
	 */
	private static function handle_fulfillment_created( array $data, string $event_id ): WP_REST_Response {
		// Logged as fulfillment_created — matching the fulfillment/created topic,
		// the way tracking/updated logs as tracking_updated. This used to share
		// `fulfillment_received` with the order panel's live fetch, which made the
		// Overview unable to tell a delivered webhook from the plugin's own pull —
		// so genuine deliveries never counted as updates from Bob Go.
		return self::handle_fulfillment_event( 'fulfillment_created', $data, $event_id );
	}

	/**
	 * Handle tracking updated webhook.
	 *
	 * @param array<string, mixed> $data     Webhook payload.
	 * @param string               $event_id Event ID.
	 * @return WP_REST_Response
	 */
	private static function handle_tracking_updated( array $data, string $event_id ): WP_REST_Response {
		return self::handle_fulfillment_event( 'tracking_updated', $data, $event_id );
	}

	/**
	 * Handle order updated webhook from Bob Go.
	 *
	 * Resolves the WC order via channel_ref_id (preferred) or fallbacks,
	 * refreshes the stored Bob Go order id, sets the loop guard so the
	 * outbound status hook does not echo the change back, and fires
	 * `bobgo_order_updated_webhook` for consumers to apply field-level sync.
	 *
	 * @param array<string, mixed> $data     Webhook payload.
	 * @param string               $event_id Event ID for idempotency.
	 * @return WP_REST_Response
	 */
	private static function handle_order_updated( array $data, string $event_id ): WP_REST_Response {
		$bobgo_id = (int) ( $data['id'] ?? $data['order_id'] ?? 0 );
		$order    = self::resolve_order( $data, $bobgo_id );

		if ( ! $order ) {
			return self::acknowledge_unmatched( 'order_updated', $data, $event_id );
		}

		self::link_bobgo_order_id( $order, $bobgo_id );

		/**
		 * Fires after an inbound Bob Go `order/updated` webhook is received and
		 * the WC order has been resolved. Use this to apply field-level sync
		 * from the Bob Go payload to the WC order.
		 *
		 * @since 4.0.32
		 *
		 * @param \WC_Order            $order    WC order resolved from the payload.
		 * @param array<string, mixed> $data     Webhook payload from Bob Go.
		 * @param string               $event_id Event ID (for idempotency / correlation).
		 */
		do_action( 'bobgo_order_updated_webhook', $order, $data, $event_id );

		self::log_webhook( $order->get_id(), 'order_updated', $data, true, $event_id );

		return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * Acknowledge a webhook whose reference matched no local order.
	 *
	 * Returned as 200, not 404: subscriptions are account-wide, so imports and
	 * manual Bob Go orders routinely deliver events here that have no
	 * WooCommerce counterpart, and a sustained stretch of 4xx responses (e.g. a
	 * CSV import during quiet trading) counts toward Bob Go's failed-delivery
	 * window that disables the whole subscription. The miss is still recorded
	 * in the sync log; anything genuinely ours is picked up by reconciliation.
	 *
	 * @param string               $event_type Log event type.
	 * @param array<string, mixed> $data       Webhook payload.
	 * @param string               $event_id   Event ID.
	 * @return WP_REST_Response
	 */
	private static function acknowledge_unmatched( string $event_type, array $data, string $event_id ): WP_REST_Response {
		$order_ref = self::extract_order_ref( $data );
		self::log_webhook( 0, $event_type, $data, false, $event_id, 'No matching WooCommerce order for ref: ' . $order_ref, 200 );

		return new WP_REST_Response(
			[
				'status' => 'ignored',
				'reason' => 'No matching WooCommerce order',
			],
			200
		);
	}

	/**
	 * Apply the inbound `delivery_address` from an `order/updated` webhook to the WC order.
	 *
	 * Mirrors the field mapping used by OrderSync::format_delivery_address():
	 * shipping address when WC has one populated, otherwise billing. Resolves the
	 * country name back to ISO-2 via WC's country list. If anything changed, the
	 * stored sync hash is refreshed to the post-change value so the outbound
	 * `on_order_updated` dirty-check matches and the change isn't echoed back.
	 *
	 * @param \WC_Order            $order WC order resolved by the webhook handler.
	 * @param array<string, mixed> $data  Webhook payload from Bob Go.
	 * @return void
	 */
	public static function apply_delivery_address( \WC_Order $order, array $data ): void {
		$address = $data['delivery_address'] ?? null;
		if ( ! is_array( $address ) ) {
			return;
		}

		$has_shipping = ! empty( $order->get_shipping_address_1() )
			|| ! empty( $order->get_shipping_city() )
			|| ! empty( $order->get_shipping_postcode() );

		if ( $has_shipping ) {
			$current         = [
				'company'   => (string) $order->get_shipping_company(),
				'address_1' => (string) $order->get_shipping_address_1(),
				'city'      => (string) $order->get_shipping_city(),
				'state'     => (string) $order->get_shipping_state(),
				'postcode'  => (string) $order->get_shipping_postcode(),
				'country'   => (string) $order->get_shipping_country(),
			];
			$suburb_meta_key = '_shipping_suburb';
		} else {
			$current         = [
				'company'   => (string) $order->get_billing_company(),
				'address_1' => (string) $order->get_billing_address_1(),
				'city'      => (string) $order->get_billing_city(),
				'state'     => (string) $order->get_billing_state(),
				'postcode'  => (string) $order->get_billing_postcode(),
				'country'   => (string) $order->get_billing_country(),
			];
			$suburb_meta_key = '_billing_suburb';
		}
		$current['suburb'] = (string) $order->get_meta( $suburb_meta_key );

		$incoming = [
			'company'   => sanitize_text_field( (string) ( $address['company'] ?? '' ) ),
			'address_1' => sanitize_text_field( (string) ( $address['street_address'] ?? '' ) ),
			'city'      => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
			'state'     => sanitize_text_field( (string) ( $address['zone'] ?? '' ) ),
			'postcode'  => sanitize_text_field( (string) ( $address['code'] ?? '' ) ),
			'country'   => self::resolve_country_iso( (string) ( $address['country'] ?? '' ), $current['country'] ),
			'suburb'    => sanitize_text_field( (string) ( $address['local_area'] ?? '' ) ),
		];

		if ( self::is_corrupt_delivery_address( $incoming ) ) {
			self::log(
				sprintf(
					'Rejected inbound delivery address for order %d: a field exceeds %d characters or the street address repeats the same segment %d+ times (street_address length: %d). Sync-loop corruption suspected; address not applied.',
					$order->get_id(),
					self::ADDRESS_FIELD_MAX_LENGTH,
					self::ADDRESS_REPEAT_LIMIT,
					mb_strlen( $incoming['address_1'] )
				),
				'warning'
			);
			return;
		}

		if ( $current === $incoming ) {
			return;
		}

		if ( $has_shipping ) {
			$order->set_shipping_company( $incoming['company'] );
			$order->set_shipping_address_1( $incoming['address_1'] );
			$order->set_shipping_city( $incoming['city'] );
			$order->set_shipping_state( $incoming['state'] );
			$order->set_shipping_postcode( $incoming['postcode'] );
			$order->set_shipping_country( $incoming['country'] );
		} else {
			$order->set_billing_company( $incoming['company'] );
			$order->set_billing_address_1( $incoming['address_1'] );
			$order->set_billing_city( $incoming['city'] );
			$order->set_billing_state( $incoming['state'] );
			$order->set_billing_postcode( $incoming['postcode'] );
			$order->set_billing_country( $incoming['country'] );
		}
		$order->update_meta_data( $suburb_meta_key, $incoming['suburb'] );

		// Refresh the sync hash so on_order_updated's dirty check sees the new
		// state as already-synced and does not echo the change back to Bob Go.
		$order->update_meta_data( '_bobgo_sync_hash', OrderSync::compute_sync_hash( $order ) );

		$order->add_order_note( __( 'Delivery address updated from Bob Go.', 'bobgo-shipping' ) );

		$order->save();
	}

	/**
	 * Detect a corrupt inbound delivery address (sync-loop growth).
	 *
	 * The 2026-07 incident: legacy v3-era WC webhooks plus the backend's
	 * order echo appended the suburb to the street address on every cycle,
	 * growing it without bound. Applying such data re-fires order webhooks
	 * and keeps the loop alive, so a corrupt address is rejected outright —
	 * never applied, never "repaired".
	 *
	 * @param array<string, string> $incoming Sanitised incoming address fields.
	 * @return bool True when the address must not be applied.
	 */
	private static function is_corrupt_delivery_address( array $incoming ): bool {
		foreach ( $incoming as $value ) {
			if ( mb_strlen( (string) $value ) > self::ADDRESS_FIELD_MAX_LENGTH ) {
				return true;
			}
		}

		return self::has_repeated_segments( (string) $incoming['address_1'] );
	}

	/**
	 * Whether a street address repeats one comma-separated segment often
	 * enough (ADDRESS_REPEAT_LIMIT) to indicate loop corruption. Comparison is
	 * case-insensitive; empty segments are ignored.
	 *
	 * @param string $address Street address.
	 * @return bool
	 */
	private static function has_repeated_segments( string $address ): bool {
		$seen = [];

		foreach ( explode( ',', $address ) as $segment ) {
			$segment = mb_strtolower( trim( $segment ) );
			if ( '' === $segment ) {
				continue;
			}

			$seen[ $segment ] = ( $seen[ $segment ] ?? 0 ) + 1;
			if ( $seen[ $segment ] >= self::ADDRESS_REPEAT_LIMIT ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cancel the WC order when an inbound `order/updated` webhook reports the
	 * Bob Go order as cancelled.
	 *
	 * The cancellation is unconditional — driven entirely by the Bob Go order
	 * status, with no fulfilment or terminal-state guard. This preserves the V3
	 * behaviour (Bob Go cancelled the order via the store's native WC REST API,
	 * short-circuiting straight to "cancelled" without reading the current WC
	 * state) now that the V4 webhook is the single inbound channel, and it stays
	 * consistent with the plugin's existing ungated fulfilment-status sync. Only
	 * the exact `cancelled` status is acted on; any other status is left to the
	 * fulfilment/tracking handlers.
	 *
	 * Idempotent by design: `order/updated` carries the full order object and
	 * re-fires on any field change (bulk paths fire unconditionally), so this can
	 * receive `status: cancelled` on webhooks that are not the cancellation event
	 * itself. Already-cancelled orders return early, and WC no-ops a same-status
	 * transition regardless.
	 *
	 * Two outbound echoes are neutralised here: the status-changed hook is
	 * covered by a loop-guard transient set immediately before the transition,
	 * and the order-saved hook (OrderSync::on_order_updated, which is gated by the
	 * sync hash rather than the loop guard) is covered by refreshing
	 * _bobgo_sync_hash to the post-cancellation payload before saving.
	 *
	 * @since 4.0.0
	 *
	 * @param \WC_Order            $order WC order resolved by the webhook handler.
	 * @param array<string, mixed> $data  Webhook payload from Bob Go.
	 * @return void
	 */
	public static function maybe_cancel_order( \WC_Order $order, array $data ): void {
		$raw_status = $data['status'] ?? '';
		if ( ! is_scalar( $raw_status ) ) {
			return;
		}
		$status = strtolower( trim( (string) $raw_status ) );
		if ( 'cancelled' !== $status ) {
			return;
		}

		// Already cancelled: nothing to do. WC would no-op the same-status
		// transition anyway, but returning here avoids a redundant save/note.
		if ( $order->has_status( 'cancelled' ) ) {
			return;
		}

		// Echo loop guard: cover OrderSync::on_order_status_changed so this
		// transition is not bounced back to Bob Go as an outbound cancel PATCH.
		// Set immediately before the status change — handle_order_updated no
		// longer arms it for every order/updated webhook, which would otherwise
		// swallow a genuine merchant status change made within the TTL after a
		// non-status (e.g. address-only) webhook.
		set_transient( 'bobgo_webhook_status_change_' . $order->get_id(), true, 10 );

		// Stage the cancellation, then refresh the stored sync hash to the
		// post-change payload before saving. Cancelling flips payment_status
		// paid→unpaid (see OrderSync::get_payment_status), so without this the
		// order would look "dirty" to OrderSync::on_order_updated — which fires
		// on save and is gated by the sync hash, not the loop guard — and echo a
		// redundant outbound PATCH back to Bob Go. Mirrors apply_delivery_address().
		$order->set_status( 'cancelled', __( 'Order cancelled on Bob Go.', 'bobgo-shipping' ) );
		$order->update_meta_data( '_bobgo_sync_hash', OrderSync::compute_sync_hash( $order ) );
		$order->save();
	}

	/**
	 * Resolve a Bob Go country name (e.g. "South Africa") to a WC ISO-2 code.
	 *
	 * Returns the input unchanged if it already looks like an ISO-2 code, and
	 * returns the fallback when the name can't be resolved against WC's list.
	 *
	 * @param string $name     Country name from the payload.
	 * @param string $fallback Current WC country to keep when resolution fails.
	 * @return string ISO-2 country code.
	 */
	private static function resolve_country_iso( string $name, string $fallback ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return $fallback;
		}

		if ( 2 === strlen( $name ) && ctype_upper( $name ) ) {
			return $name;
		}

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			$code      = array_search( $name, $countries, true );
			if ( false !== $code ) {
				return (string) $code;
			}
		}

		return $fallback;
	}

	/**
	 * Shared handler for fulfillment/tracking webhooks.
	 *
	 * Thin trigger: uses the webhook as a signal to fetch complete fulfillment
	 * state from the API via refresh_fulfillment_data().
	 *
	 * @param string               $event_type Log event type.
	 * @param array<string, mixed> $data       Webhook payload.
	 * @param string               $event_id   Event ID for idempotency.
	 * @return WP_REST_Response
	 */
	private static function handle_fulfillment_event( string $event_type, array $data, string $event_id ): WP_REST_Response {
		$order_ref = self::extract_order_ref( $data );
		$order     = self::resolve_order( $data, self::extract_fulfillment_bobgo_order_id( $data ) );

		if ( ! $order ) {
			return self::acknowledge_unmatched( $event_type, $data, $event_id );
		}

		$order_id = $order->get_id();

		// Backfill the Bob Go order id link from the payload. Orders this
		// plugin didn't push (legacy-integration ingestion, pre-4.0 history)
		// have no stored _bobgo_order_id, and refresh_fulfillment_data()
		// refuses to query without one — so without this backfill, tracking
		// never lands for those orders. With it, every affected order
		// self-heals on its next fulfillment/tracking webhook, mirroring the
		// backfill handle_order_updated() already does.
		self::link_bobgo_order_id( $order, self::extract_fulfillment_bobgo_order_id( $data ) );

		// Serialize concurrent deliveries for the same order. Bob Go fires sibling
		// topics (fulfillment/created, tracking/updated, order/updated) for one
		// trigger near-simultaneously; each refresh pulls full state from the API,
		// so the in-flight one supersedes this delivery. Acknowledge without
		// reprocessing rather than racing into a duplicate status transition.
		// Anything genuinely missed is picked up by hourly reconciliation. See QA #7.
		if ( ! self::acquire_order_lock( $order_id ) ) {
			self::log_webhook( $order_id, $event_type, $data, true, $event_id );
			return new WP_REST_Response(
				[
					'status'  => 'ok',
					'deduped' => true,
				],
				200
			);
		}

		try {
			// Still unlinked after the payload backfill — true for every
			// tracking/updated delivery, whose payload carries no Bob Go order
			// id in any field. Resolve the link via the orders API (inside the
			// lock so sibling deliveries don't fire duplicate lookups). This
			// is what lets orders ingested by the legacy integration heal:
			// most are already fulfilled and will only ever emit
			// tracking/updated events.
			if ( '' === (string) $order->get_meta( '_bobgo_order_id' ) ) {
				self::link_bobgo_order_id( $order, self::resolve_bobgo_order_id_via_api( $order, $data ) );
			}

			$refreshed = self::refresh_fulfillment_data( $order, $order_ref );
			if ( ! $refreshed ) {
				// Acknowledge the webhook — the refresh can be retried via reconciliation.
				self::log_webhook( $order_id, $event_type, $data, false, $event_id, 'Failed to refresh fulfillment data', 200 );
				return new WP_REST_Response(
					[
						'status'         => 'accepted',
						'refresh_failed' => true,
					],
					200
				);
			}

			self::log_webhook( $order_id, $event_type, $data, true, $event_id );
			return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
		} finally {
			self::release_order_lock( $order_id );
		}
	}

	/**
	 * Register webhook subscriptions with Bob Go API.
	 *
	 * Deregisters all existing webhooks first to prevent duplicates and
	 * remove stale topics, then registers only the desired topics.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function register_subscriptions(): array {
		// Registering expresses intent to be connected — clear any deliberate
		// disconnect so the daily health check resumes watching this store.
		self::mark_deliberately_disconnected( false );

		// Remove all existing webhooks first to prevent duplicates and clean up stale topics.
		$deregister = self::deregister_subscriptions();
		if ( ! $deregister['success'] ) {
			return $deregister;
		}

		$webhook_url = rest_url( self::REST_NAMESPACE . '/webhook' );
		$topics      = [
			self::TOPIC_TRACKING_UPDATED,
			self::TOPIC_FULFILLMENT_CREATED,
			self::TOPIC_ORDER_UPDATED,
		];

		$payload = [
			'webhook_subscriptions' => array_map(
				static fn( string $topic ): array => [
					'delivery_url' => $webhook_url,
					'topic'        => $topic,
					'status'       => 'active',
				],
				$topics
			),
		];

		$result = Api::post( 'v2/webhooks', $payload );

		if ( ! $result['success'] ) {
			// The deregister above already removed the old subscriptions, so
			// giving up on a transient blip leaves the store with none at all
			// until the next daily heal — retry once before accepting that.
			$result = Api::post( 'v2/webhooks', $payload );
		}

		// Drop the cache so the next read reflects the new state.
		delete_transient( self::TRANSIENT_SUBSCRIPTIONS );

		if ( $result['success'] ) {
			self::mark_subscriptions_registered( true );
			return [
				'success' => true,
				'message' => __( 'Webhook subscriptions registered.', 'bobgo-shipping' ),
			];
		}

		// The POST attempt definitively failed — record that webhooks are not
		// registered so the Overview/notice surface it honestly. See Phase 3 #2.
		self::mark_subscriptions_registered( false );

		return [
			'success' => false,
			'message' => sprintf(
				/* translators: %s: error message */
				__( 'Failed to register webhooks: %s', 'bobgo-shipping' ),
				self::api_error_text( $result )
			),
		];
	}

	/**
	 * Explain a failed webhook call in the plugin's own words.
	 *
	 * The Bob Go API answers a rejected request with its own message, and for an
	 * authentication failure that message is written for an API client: "You're
	 * not logged in. Please log in and try again." Passed through verbatim to a
	 * WooCommerce admin — which is what this used to do — it sends the merchant
	 * hunting for a login screen that does not exist, over a stored API key.
	 *
	 * So the statuses whose cause the plugin can name are named here, and only
	 * those. A validation or server error keeps the API's own message, which is
	 * the informative one for anything that is not about credentials.
	 *
	 * The webhook signing secret is deliberately not mentioned: it is only used
	 * to verify deliveries arriving here, and is never sent to Bob Go, so it
	 * cannot be the reason a registration call was refused.
	 *
	 * @param array<string, mixed> $result Failed Api::request() result.
	 * @return string
	 */
	private static function api_error_text( array $result ): string {
		$status = (int) ( $result['status'] ?? 0 );

		switch ( $status ) {
			case 0:
				return __( 'no response came back from Bob Go — this server may be blocking outbound connections.', 'bobgo-shipping' );

			case 401:
				return __( 'Bob Go did not accept this store\'s API key (HTTP 401). Check the API key on the Connection tab, and that the Channel Identifier shown on the Overview matches a sales channel in your Bob Go account.', 'bobgo-shipping' );

			case 403:
				return __( 'Bob Go refused the request (HTTP 403). This API key may not have access to this store\'s sales channel.', 'bobgo-shipping' );

			case 404:
				return __( 'Bob Go has no sales channel matching this store (HTTP 404). Check the Channel Identifier shown on the Overview against your Bob Go sales channels.', 'bobgo-shipping' );
		}

		$error = (string) ( $result['error'] ?? '' );

		return '' !== $error ? $error : sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Bob Go returned HTTP %d.', 'bobgo-shipping' ),
			$status
		);
	}

	/**
	 * Deregister webhook subscriptions for this store.
	 *
	 * Fetches all existing webhooks, filters to this store's delivery URL,
	 * collects their IDs, and sends a single DELETE v2/webhooks with the
	 * IDs array in the request body.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function deregister_subscriptions(): array {
		// force_refresh: the cache is up to 5 minutes old, but we're about to
		// delete by ID — the list must reflect reality on the server, not the
		// transient. Otherwise we either skip live webhooks or send stale IDs.
		$subscriptions = self::get_subscription_status( true );

		if ( empty( $subscriptions ) ) {
			// Do NOT infer "not registered" here: get_subscription_status() returns
			// an empty array on an API/network failure too, so this is inconclusive.
			// The marker is only written on definitive outcomes (POST/DELETE result).
			// See Phase 3 #2.
			return [
				'success' => true,
				'message' => __( 'No webhook subscriptions to remove.', 'bobgo-shipping' ),
			];
		}

		$ids = [];
		foreach ( $subscriptions as $webhook ) {
			$id = $webhook['id'] ?? 0;
			if ( ! empty( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		if ( empty( $ids ) ) {
			return [
				'success' => false,
				'message' => __( 'Could not delete webhooks: no IDs found in API response.', 'bobgo-shipping' ),
			];
		}

		$result = Api::delete(
			'v2/webhooks',
			[
				'body' => wp_json_encode( [ 'ids' => $ids ] ),
			]
		);

		// Drop the cache so the next read reflects the deletion.
		delete_transient( self::TRANSIENT_SUBSCRIPTIONS );

		if ( $result['success'] ) {
			self::mark_subscriptions_registered( false );
			return [
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number deleted */
					__( '%d webhook subscriptions removed.', 'bobgo-shipping' ),
					count( $ids )
				),
			];
		}

		return [
			'success' => false,
			'message' => sprintf(
				/* translators: %s: error message */
				__( 'Failed to remove webhooks: %s', 'bobgo-shipping' ),
				self::api_error_text( $result )
			),
		];
	}

	/**
	 * Get current webhook subscription status from Bob Go.
	 *
	 * Caches the result in a short-lived transient so repeated reads (page
	 * renders + the deregister-list step in register_subscriptions) collapse
	 * into one network call. Pass true to force a refresh — required when
	 * accuracy matters more than latency (e.g. a manual reconciliation).
	 *
	 * @param bool $force_refresh Bypass the cache and fetch fresh from the API.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_subscription_status( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_SUBSCRIPTIONS );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$subscriptions = self::fetch_subscriptions();
		if ( null === $subscriptions ) {
			return [];
		}

		set_transient( self::TRANSIENT_SUBSCRIPTIONS, $subscriptions, self::SUBSCRIPTIONS_TTL );

		return $subscriptions;
	}

	/**
	 * Fetch this store's webhook subscriptions from the API, uncached.
	 *
	 * Unlike get_subscription_status(), a failed API call is distinguishable
	 * from a store with no subscriptions: null means "unknown", an empty
	 * array means "the API answered and none exist for this delivery URL".
	 *
	 * @return array<int, array<string, mixed>>|null Subscriptions, or null when the API call failed.
	 */
	private static function fetch_subscriptions(): ?array {
		$result = Api::get( 'v2/webhooks' );

		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			return null;
		}

		$subscriptions = $result['body']['webhook_subscriptions'] ?? $result['body'];
		if ( ! is_array( $subscriptions ) ) {
			return null;
		}

		// Filter to only show webhooks for this store's delivery URL.
		$our_url = rest_url( self::REST_NAMESPACE . '/webhook' );

		return array_values(
			array_filter(
				$subscriptions,
				function ( $webhook ) use ( $our_url ) {
					$delivery_url = $webhook['delivery_url'] ?? '';
					return str_starts_with( $delivery_url, $our_url );
				}
			)
		);
	}

	/**
	 * Daily health check: verify this store's subscriptions still exist and
	 * are active on Bob Go, and re-register them when they are not.
	 *
	 * Bob Go disables a subscription after a sustained stretch of failed
	 * deliveries (e.g. the store was unreachable for a few days) and only
	 * emails the merchant — the plugin is never told, and the local
	 * registered-marker still reads healthy. Without this check the store
	 * silently stops receiving tracking updates. Runs from the hourly
	 * reconciliation job, rate-limited to one conclusive check per day; an
	 * unreachable API doesn't advance the timestamp, so the next hourly run
	 * retries instead of waiting out the day.
	 *
	 * @return void
	 */
	public static function maybe_check_subscription_health(): void {
		// A store the merchant deliberately disconnected must not have its
		// webhooks resurrected. Checked via the explicit intent flag — NOT the
		// registered-marker, which also reads false after a FAILED registration
		// attempt and would permanently disable self-healing (the marker's only
		// reachable effect here, since deliberate disconnects also clear the
		// API key and stop the reconciliation job that runs this check).
		if ( self::is_deliberately_disconnected() ) {
			return;
		}

		$last_check = (int) get_option( self::OPTION_LAST_HEALTH_CHECK, 0 );
		if ( time() - $last_check < DAY_IN_SECONDS ) {
			return;
		}

		$subscriptions = self::fetch_subscriptions();
		if ( null === $subscriptions ) {
			self::log( 'Webhook health check inconclusive: subscription list unavailable; will retry on the next reconciliation run.', 'info' );
			return;
		}

		update_option( self::OPTION_LAST_HEALTH_CHECK, time(), false );

		// Piggyback the daily account check: re-registering subscriptions onto an
		// account the stored links no longer belong to would keep the store wired
		// to a namespace it cannot reach.
		LinkAudit::check_for_account_switch();

		$unhealthy = self::missing_or_inactive_topics( $subscriptions );
		if ( empty( $unhealthy ) ) {
			return;
		}

		self::log( 'Webhook health check: ' . implode( ', ', $unhealthy ) . ' missing or inactive on Bob Go; re-registering subscriptions.', 'warning' );

		// register_subscriptions() maintains the registered-marker itself
		// (true on success, false on a definitive failure), so the Overview
		// banner reflects the outcome either way.
		$result = self::register_subscriptions();

		SyncLog::insert(
			[
				'event_type' => 'webhooks_reregistered',
				'direction'  => 'outbound',
				'payload'    => wp_json_encode(
					[
						'unhealthy_topics' => $unhealthy,
						'result'           => $result['message'],
					]
				),
				'success'    => $result['success'],
			]
		);
	}

	/**
	 * Required topics that are missing or not active in the given list.
	 *
	 * A row without a status field counts as active — only an explicit
	 * non-active status or an absent topic is unhealthy, so an API response
	 * omitting the field can never cause a re-register churn.
	 *
	 * @param array<int, array<string, mixed>> $subscriptions This store's subscriptions.
	 * @return array<int, string> Unhealthy topic names; empty when all is well.
	 */
	public static function missing_or_inactive_topics( array $subscriptions ): array {
		$required = [
			self::TOPIC_TRACKING_UPDATED,
			self::TOPIC_FULFILLMENT_CREATED,
			self::TOPIC_ORDER_UPDATED,
		];

		$active = [];
		foreach ( $subscriptions as $subscription ) {
			$status = strtolower( trim( (string) ( $subscription['status'] ?? '' ) ) );
			if ( '' === $status || 'active' === $status ) {
				$active[] = (string) ( $subscription['topic'] ?? '' );
			}
		}

		return array_values( array_diff( $required, $active ) );
	}

	/**
	 * Record whether this store's webhook subscriptions are registered.
	 *
	 * @param bool $registered True after a successful register, false after deregister.
	 * @return void
	 */
	public static function mark_subscriptions_registered( bool $registered ): void {
		update_option( self::OPTION_SUBSCRIPTIONS_REGISTERED, $registered ? 1 : 0, true );
	}

	/**
	 * Whether webhook subscriptions are registered, per the local marker.
	 *
	 * Returns null when unknown (the marker was never written — e.g. installs from
	 * before this option existed), so callers can avoid showing a false
	 * "not registered" warning. See Phase 3 #2.
	 *
	 * @return bool|null
	 */
	public static function has_registered_subscriptions(): ?bool {
		$value = get_option( self::OPTION_SUBSCRIPTIONS_REGISTERED, null );
		if ( null === $value ) {
			return null;
		}
		return (bool) $value;
	}

	/**
	 * Record (or clear) the merchant's deliberate webhook disconnect.
	 *
	 * @param bool $disconnected True when the merchant disconnected on purpose.
	 * @return void
	 */
	public static function mark_deliberately_disconnected( bool $disconnected ): void {
		if ( $disconnected ) {
			update_option( self::OPTION_WEBHOOKS_DISCONNECTED, 1, true );
		} else {
			delete_option( self::OPTION_WEBHOOKS_DISCONNECTED );
		}
	}

	/**
	 * Whether the merchant deliberately disconnected webhooks.
	 *
	 * @return bool
	 */
	public static function is_deliberately_disconnected(): bool {
		return (bool) get_option( self::OPTION_WEBHOOKS_DISCONNECTED, false );
	}

	/**
	 * Extract the order reference from webhook payload, trying multiple fields.
	 *
	 * @param array<string, mixed> $data Webhook payload.
	 * @return string
	 */
	public static function extract_order_ref( array $data ): string {
		// Try fields in order of specificity. channel_ref_id is the immutable WC
		// order DB id and is preferred when present; the rest are fallbacks for
		// older payload shapes.
		$candidates = [
			$data['channel_ref_id'] ?? '',
			$data['order_ref'] ?? '',
			$data['channel_order_number'] ?? '',
			$data['order_number'] ?? '',
			$data['custom_order_name'] ?? '',
		];

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Extract Bob Go's own order id from a fulfillment/tracking webhook payload.
	 *
	 * Fulfillment payloads reference their parent order via `order_id`. Unlike
	 * order/updated payloads — where the top-level `id` IS the Bob Go order
	 * id — `id` here identifies the fulfillment/shipment itself and must never
	 * be stored as the order link.
	 *
	 * @param array<string, mixed> $data Webhook payload.
	 * @return int The Bob Go order id, or 0 when the payload carries none.
	 */
	public static function extract_fulfillment_bobgo_order_id( array $data ): int {
		$order_id = $data['order_id'] ?? 0;

		return is_numeric( $order_id ) ? max( 0, (int) $order_id ) : 0;
	}

	/**
	 * Resolve Bob Go's order id for an unlinked order via the orders API.
	 *
	 * Payloads for tracking/updated carry no Bob Go order id in any field
	 * (verified against a live delivery and the backend payload builders), so
	 * orders never pushed by this plugin — everything ingested by the legacy
	 * integration — cannot be linked from the payload alone. Lookup order:
	 *
	 * 1. tracking_reference — deterministic: the API resolves the shipment's
	 *    unique tracking reference to its parent order server-side.
	 * 2. channel_order_number — exact (case-insensitive) match.
	 *
	 * Both lookups are channel-scoped server-side (the API key's claims
	 * overwrite the account/channel filters), so a numeric Woo order number
	 * can never match another channel's order.
	 *
	 * @param \WC_Order            $order              The WooCommerce order being linked.
	 * @param array<string, mixed> $data               Webhook payload.
	 * @param bool                 $require_round_trip Require the matched Bob Go order to carry a
	 *                                                 channel_ref_id equal to this WC order's id.
	 *                                                 Without it a match with no channel_ref_id is
	 *                                                 accepted (legacy-ingestion backfill); with it
	 *                                                 such a match is refused — used when the WC
	 *                                                 order itself was found by an unverified
	 *                                                 numeric ref, where the payload's own tracking
	 *                                                 reference resolving to itself proves nothing.
	 * @return int The Bob Go order id, or 0 when it could not be resolved safely.
	 */
	public static function resolve_bobgo_order_id_via_api( \WC_Order $order, array $data, bool $require_round_trip = false ): int {
		// Never fall back to the payload's top-level `id` here: for
		// fulfillment/created it is the fulfillment id, not a tracking ref.
		$tracking_ref = trim( (string) ( $data['shipment_tracking_reference'] ?? '' ) );
		if ( '' !== $tracking_ref ) {
			$bobgo_id = self::lookup_order_id( 'tracking_reference=' . rawurlencode( $tracking_ref ), $order, $require_round_trip );
			if ( $bobgo_id > 0 ) {
				return $bobgo_id;
			}
		}

		$order_number = trim( (string) ( $data['channel_order_number'] ?? $data['order_number'] ?? '' ) );
		if ( '' === $order_number ) {
			return 0;
		}

		$bobgo_id = self::lookup_order_id( 'channel_order_number=' . rawurlencode( $order_number ), $order, $require_round_trip );

		return $bobgo_id;
	}

	/**
	 * Query GET v2/orders with the given filter and return the single match's id.
	 *
	 * Requires exactly one result: with dual ingestion (legacy + v4 channels)
	 * the same channel_order_number can exist on more than one Bob Go order,
	 * and linking the wrong one would apply another order's fulfillments
	 * locally. When the matched order carries a channel_ref_id it must
	 * round-trip to this WC order's id.
	 *
	 * @param string    $query              URL-encoded query string for v2/orders.
	 * @param \WC_Order $order              The WooCommerce order being linked.
	 * @param bool      $require_round_trip Refuse a match whose channel_ref_id is absent
	 *                                      instead of accepting it (see resolve_bobgo_order_id_via_api).
	 * @return int The Bob Go order id, or 0.
	 */
	private static function lookup_order_id( string $query, \WC_Order $order, bool $require_round_trip = false ): int {
		$result = Api::get( 'v2/orders?' . $query );
		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			return 0;
		}

		$orders = $result['body']['orders'] ?? [];
		if ( ! is_array( $orders ) || 1 !== count( $orders ) || ! is_array( $orders[0] ?? null ) ) {
			if ( is_array( $orders ) && count( $orders ) > 1 ) {
				self::log( "Order-link lookup '{$query}' for order {$order->get_id()} matched " . count( $orders ) . ' Bob Go orders; refusing to guess.', 'warning' );
			}
			return 0;
		}

		$match       = $orders[0];
		$channel_ref = trim( (string) ( $match['channel_ref_id'] ?? '' ) );
		if ( '' !== $channel_ref && $channel_ref !== (string) $order->get_id() ) {
			self::log( "Order-link lookup '{$query}' for order {$order->get_id()} returned channel_ref_id '{$channel_ref}'; refusing mismatched link.", 'warning' );
			return 0;
		}
		if ( $require_round_trip && '' === $channel_ref ) {
			self::log( "Order-link lookup '{$query}' for order {$order->get_id()} matched a Bob Go order with no channel_ref_id; refusing unverified match.", 'info' );
			return 0;
		}

		$id = $match['id'] ?? 0;

		return is_numeric( $id ) ? max( 0, (int) $id ) : 0;
	}

	/**
	 * Store the Bob Go order id on the WC order when it is missing or stale.
	 *
	 * This link is what allows refresh_fulfillment_data() to query the API —
	 * it refuses to guess when no id is stored (no-link-no-request guard).
	 *
	 * @param \WC_Order $order    The WooCommerce order.
	 * @param int       $bobgo_id Bob Go order id from a webhook payload; 0 skips.
	 * @return bool True when the meta was written.
	 */
	public static function link_bobgo_order_id( \WC_Order $order, int $bobgo_id ): bool {
		if ( $bobgo_id <= 0 || (int) $order->get_meta( '_bobgo_order_id' ) === $bobgo_id ) {
			return false;
		}

		// Never silently replace an existing, different link: a webhook that
		// resolved to this order while claiming another Bob Go id is exactly
		// how a mis-matched event hijacks a correctly-linked order. Genuine
		// mismatches are corrected by the link audit, which verifies ownership
		// against the API before rewriting.
		$existing = trim( (string) $order->get_meta( '_bobgo_order_id' ) );
		if ( '' !== $existing ) {
			self::log( "Refusing to overwrite Bob Go order link on order {$order->get_id()}: stored id {$existing}, webhook claims {$bobgo_id}.", 'warning' );
			return false;
		}

		$order->update_meta_data( '_bobgo_order_id', (string) $bobgo_id );
		// This order has never been through the create POST, so nothing has
		// recorded what items Bob Go holds. Without that record its next update
		// would drop the item edit it was carrying. See OrderSync::ensure_items_fingerprint().
		OrderSync::ensure_items_fingerprint( $order );
		$order->save();

		return true;
	}

	/**
	 * Resolve the WooCommerce order an inbound webhook refers to.
	 *
	 * Webhook subscriptions are account-wide, so this endpoint also receives
	 * events for orders that have no WooCommerce counterpart — CSV imports and
	 * manual orders created directly on Bob Go. Every step below must therefore
	 * genuinely match or resolve nothing: a lookup that falls back to "some
	 * order" attaches a stranger's shipment to a real customer order, marks it
	 * synced (so it is never sent to Bob Go), and overwrites its tracking and
	 * delivery address (Mondvol incident, 2026-07-08).
	 *
	 * Resolution order:
	 * 1. channel_ref_id — authoritative for THIS store: this plugin wrote it
	 *    into Bob Go at sync time as the WC order DB id. Payloads for orders
	 *    this store did not push never carry it, and a populated value that
	 *    doesn't resolve locally must not fall through to number-based
	 *    guessing. A WC order id is only unique per store, though — not per
	 *    Bob Go account — and subscriptions are account-wide, so a match is
	 *    accepted only after the ownership cross-check in
	 *    verify_channel_ref_match() (see there for the two-channel collision
	 *    this closes).
	 * 2. The payload's Bob Go order id against _bobgo_order_id meta.
	 * 3. order_ref against _bobgo_order_id and custom order-number meta —
	 *    older payload shapes carried either in this field.
	 * 4. The order-number fields (channel_order_number, order_number,
	 *    custom_order_name) against custom order-number meta only
	 *    (sequential-order-number plugins). An order number is never matched
	 *    against stored Bob Go ids — the namespaces are unrelated.
	 * 5. A numeric ref as a direct WC order id — the resolution path for orders
	 *    ingested by the legacy integration, whose payloads carry only the Woo
	 *    order number. A bare id match is NOT proof (an imported order's number
	 *    can collide with a shop order id), so the candidate is only accepted
	 *    when the orders API round-trips its channel_ref_id to the candidate.
	 *
	 * @param array<string, mixed> $data             Webhook payload.
	 * @param int                  $payload_bobgo_id Bob Go's own order id from the
	 *                                               payload; 0 when it carries none.
	 * @return \WC_Order|null
	 */
	public static function resolve_order( array $data, int $payload_bobgo_id = 0 ): ?\WC_Order {
		$channel_ref_id = trim( (string) ( $data['channel_ref_id'] ?? '' ) );
		if ( '' !== $channel_ref_id ) {
			if ( is_numeric( $channel_ref_id ) ) {
				$order = wc_get_order( (int) $channel_ref_id );
				if ( $order instanceof \WC_Order ) {
					return self::verify_channel_ref_match( $order, $data, $payload_bobgo_id );
				}
			}
			self::log( "Webhook channel_ref_id '{$channel_ref_id}' does not resolve to a local order; refusing fallback lookups for an authoritative ref.", 'warning' );
			return null;
		}

		if ( $payload_bobgo_id > 0 ) {
			$order = self::find_order_by_bobgo_id( (string) $payload_bobgo_id );
			if ( $order ) {
				return $order;
			}
		}

		// Resolve field-aware, not through the flattened extract_order_ref():
		// order_ref (older payload shapes) may carry a Bob Go id, but the
		// number fields never do — matching an order NUMBER against stored Bob
		// Go IDs crosses namespaces and can hit an unrelated order whose Bob
		// Go id happens to equal this order's number.
		$legacy_ref = trim( (string) ( $data['order_ref'] ?? '' ) );
		$number_ref = '';
		foreach ( [ 'channel_order_number', 'order_number', 'custom_order_name' ] as $field ) {
			$number_ref = trim( (string) ( $data[ $field ] ?? '' ) );
			if ( '' !== $number_ref ) {
				break;
			}
		}

		// order_ref may be either namespace: try both lookups.
		if ( '' !== $legacy_ref ) {
			$order = self::find_order_by_ref( $legacy_ref );
			if ( $order ) {
				return $order;
			}
		}

		// Number fields: order-number lookups only.
		if ( '' !== $number_ref && $number_ref !== $legacy_ref ) {
			$order = self::find_order_by_order_number( $number_ref );
			if ( $order ) {
				return $order;
			}
		}

		$order_ref = '' !== $legacy_ref ? $legacy_ref : $number_ref;
		if ( '' === $order_ref || ! is_numeric( $order_ref ) ) {
			return null;
		}

		$candidate = wc_get_order( (int) $order_ref );
		if ( ! $candidate instanceof \WC_Order ) {
			return null;
		}

		$stored = trim( (string) $candidate->get_meta( '_bobgo_order_id' ) );

		// Require an affirmative, channel-verified tie between the payload and
		// the candidate. The soft variant (used for link backfill) accepts a
		// match whose channel_ref_id is absent — circular here, because an
		// imported order's own tracking reference resolves to itself.
		$api_id = self::resolve_bobgo_order_id_via_api( $candidate, $data, true );

		if ( $api_id > 0
			&& ( '' === $stored || (int) $stored === $api_id )
			&& ( $payload_bobgo_id <= 0 || $payload_bobgo_id === $api_id ) ) {
			self::log( "Webhook ref '{$order_ref}' resolved to order {$candidate->get_id()} via channel-verified API lookup (Bob Go order {$api_id}).", 'info' );
			return $candidate;
		}

		self::log( "Webhook ref '{$order_ref}' matches WC order id {$candidate->get_id()} numerically, but the orders API could not verify ownership — refusing the match (likely an import/manual Bob Go order).", 'info' );

		return null;
	}

	/**
	 * Verify that an order matched via channel_ref_id actually belongs to the
	 * event, not to a colliding order id from another channel.
	 *
	 * A channel_ref_id is a WC order DB id — unique per store, not per Bob Go
	 * account. Webhook subscriptions (and their delivery signatures) are
	 * account-wide, so with two WooCommerce channels on one account — e.g. a
	 * staging clone of a prod store, which inherits API credentials via the
	 * copied database and re-registers its own subscription — another store's
	 * event resolves a colliding local order here. Accepting it would apply a
	 * stranger's delivery address, cancellation, or fulfillment link to a real
	 * customer order (the Mondvol damage class). The numeric fallback in
	 * resolve_order() already refuses such matches; this applies the same
	 * cross-checks to the authoritative step:
	 *
	 * - Linked order: the payload's Bob Go id must equal the stored link.
	 *   Payloads that carry no Bob Go id (tracking/updated) are accepted —
	 *   every applied refresh queries the STORED link, so a foreign event
	 *   cannot inject foreign data through them.
	 * - Unlinked order: a bare id collision is not proof. The orders API must
	 *   round-trip the payload's own references (tracking reference / order
	 *   number) back to this order before the match is trusted — the same
	 *   gate the numeric fallback uses.
	 *
	 * @param \WC_Order            $order            Order resolved from channel_ref_id.
	 * @param array<string, mixed> $data             Webhook payload.
	 * @param int                  $payload_bobgo_id Bob Go order id from the payload; 0 when it carries none.
	 * @return \WC_Order|null The order when ownership is verified, null otherwise.
	 */
	private static function verify_channel_ref_match( \WC_Order $order, array $data, int $payload_bobgo_id ): ?\WC_Order {
		$stored = (int) $order->get_meta( '_bobgo_order_id' );

		if ( $stored > 0 ) {
			if ( $payload_bobgo_id > 0 && $payload_bobgo_id !== $stored ) {
				self::log( "Webhook channel_ref_id matched order {$order->get_id()}, but the payload's Bob Go id {$payload_bobgo_id} contradicts the stored link {$stored} — refusing (order-id collision with another channel on this account?).", 'warning' );
				return null;
			}

			return $order;
		}

		$api_id = self::resolve_bobgo_order_id_via_api( $order, $data, true );
		if ( $api_id > 0 && ( $payload_bobgo_id <= 0 || $payload_bobgo_id === $api_id ) ) {
			return $order;
		}

		self::log( "Webhook channel_ref_id matched unlinked order {$order->get_id()}, but the orders API could not verify ownership — refusing the match.", 'warning' );

		return null;
	}

	/**
	 * Find a WooCommerce order by Bob Go order reference.
	 *
	 * Exact-match lookups only — a ref that matches nothing returns null.
	 * The old fallbacks (bare numeric id, and a `meta_query` the legacy CPT
	 * datastore silently ignored, degrading into "newest order") let webhooks
	 * for imported Bob Go orders attach to unrelated shop orders; webhook
	 * handlers now resolve through resolve_order(), which gates the numeric
	 * fallback behind API verification.
	 *
	 * @param string $order_ref The order reference (Bob Go id or WC order number).
	 * @return \WC_Order|null
	 */
	public static function find_order_by_ref( string $order_ref ): ?\WC_Order {
		if ( empty( $order_ref ) ) {
			return null;
		}

		$order = self::find_order_by_bobgo_id( $order_ref );
		if ( $order ) {
			return $order;
		}

		return self::find_order_by_order_number( $order_ref );
	}

	/**
	 * Find an order by its stored _bobgo_order_id link meta.
	 *
	 * Uses meta_key/meta_value args, which both order datastores support
	 * (unlike meta_query, which legacy CPT storage silently drops).
	 *
	 * @param string $bobgo_id Bob Go order id.
	 * @return \WC_Order|null
	 */
	private static function find_order_by_bobgo_id( string $bobgo_id ): ?\WC_Order {
		$orders = wc_get_orders(
			[
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => '_bobgo_order_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $bobgo_id,
				'limit'      => 1,
				'return'     => 'objects',
			]
		);

		return ! empty( $orders ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}

	/**
	 * Find an order by custom order-number meta written by
	 * sequential-order-number plugins.
	 *
	 * @param string $order_number The customer-facing order number.
	 * @return \WC_Order|null
	 */
	private static function find_order_by_order_number( string $order_number ): ?\WC_Order {
		foreach ( [ '_order_number', '_order_number_formatted' ] as $meta_key ) {
			$orders = wc_get_orders(
				[
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => $meta_key,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => $order_number,
					'limit'      => 1,
					'return'     => 'objects',
				]
			);

			if ( ! empty( $orders ) && $orders[0] instanceof \WC_Order ) {
				return $orders[0];
			}
		}

		return null;
	}

	/**
	 * Build an environment-aware tracking URL for a tracking reference.
	 *
	 * @param string $tracking_reference The tracking reference.
	 * @return string The tracking URL, or empty string if no reference.
	 */
	private static function build_tracking_url( string $tracking_reference ): string {
		if ( empty( $tracking_reference ) ) {
			return '';
		}

		$env    = SettingsPage::get_current_env();
		$domain = ( 'prod' === $env ) ? 'track.bobgo.co.za' : 'track.dev.bobgo.co.za';

		return 'https://' . $domain . '/' . rawurlencode( $tracking_reference );
	}

	/**
	 * Map a fulfillment item to a WooCommerce line item ID.
	 *
	 * Uses three-tier matching:
	 * 1. channel_ref_id > 0 — direct WC item ID (plugin-synced orders)
	 * 2. _bobgo_item_id meta — matches order_item_id from outbound sync
	 * 3. Description/name fallback — matches against WC item names
	 *
	 * @param \WC_Order            $order           The WooCommerce order.
	 * @param array<string, mixed> $fulfillment_item The fulfillment item data.
	 * @return int WC line item ID, or 0 if no match.
	 */
	private static function find_wc_item_id( \WC_Order $order, array $fulfillment_item ): int {
		// Tier 1: channel_ref_id is the WC line item ID for plugin-synced orders.
		$channel_ref_id = (int) ( $fulfillment_item['channel_ref_id'] ?? 0 );
		if ( $channel_ref_id > 0 ) {
			// Verify it belongs to this order.
			foreach ( $order->get_items() as $item ) {
				if ( $item->get_id() === $channel_ref_id ) {
					return $channel_ref_id;
				}
			}
		}

		// Tier 2: Match by _bobgo_item_id meta (stored during outbound sync).
		$bobgo_item_id = (string) ( $fulfillment_item['order_item_id'] ?? '' );
		if ( ! empty( $bobgo_item_id ) ) {
			foreach ( $order->get_items() as $item ) {
				$stored_id = wc_get_order_item_meta( $item->get_id(), '_bobgo_item_id', true );
				if ( (string) $stored_id === $bobgo_item_id ) {
					return $item->get_id();
				}
			}
		}

		// Tier 3: Fallback — match by description/name.
		$description = $fulfillment_item['description'] ?? '';
		if ( ! empty( $description ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( strcasecmp( $item->get_name(), $description ) === 0 ) {
					return $item->get_id();
				}
			}
		}

		return 0;
	}

	/**
	 * Build a stable fingerprint of an order's shipments for change detection.
	 *
	 * Excludes volatile fields that differ on every refresh even when nothing
	 * meaningful changed — notably status_updated_at, which falls back to the
	 * current time when the Bob Go payload omits time_modified. See QA #7.
	 *
	 * @param array<int, mixed> $shipments Shipments array. Typically a list of
	 *                                     shipment arrays, but it is read back
	 *                                     from stored order meta which can be
	 *                                     malformed — entries are validated at
	 *                                     runtime rather than trusted.
	 * @return string SHA-256 fingerprint.
	 */
	private static function shipments_fingerprint( array $shipments ): string {
		$normalized = array_map(
			static function ( $shipment ) {
				if ( ! is_array( $shipment ) ) {
					return $shipment;
				}

				// status_updated_at falls back to the current time when the payload
				// omits time_modified, so it changes every run — drop it.
				unset( $shipment['status_updated_at'] );

				// Sort the nested lists so a pure re-ordering by the API (couriers
				// often return tracking events newest- vs oldest-first) isn't
				// mistaken for a real change.
				foreach ( [ 'fulfillment_ids', 'tracking_events', 'parcels', 'items' ] as $list_key ) {
					if ( isset( $shipment[ $list_key ] ) && is_array( $shipment[ $list_key ] ) ) {
						$list = $shipment[ $list_key ];
						usort(
							$list,
							static function ( $a, $b ) {
								return strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) );
							}
						);
						$shipment[ $list_key ] = $list;
					}
				}

				ksort( $shipment );
				return $shipment;
			},
			$shipments
		);

		// Sort the shipments themselves by id so shipment ordering doesn't matter.
		usort(
			$normalized,
			static function ( $a, $b ) {
				$a_id = is_array( $a ) ? (string) ( $a['shipment_id'] ?? '' ) : '';
				$b_id = is_array( $b ) ? (string) ( $b['shipment_id'] ?? '' ) : '';
				return strcmp( $a_id, $b_id );
			}
		);

		return hash( 'sha256', (string) wp_json_encode( $normalized ) );
	}

	/**
	 * Acquire a short-lived per-order processing lock.
	 *
	 * Guards against concurrent webhook deliveries for the same order both
	 * refreshing full state and applying the same status transition, which
	 * produced duplicate order notes. Acquisition is atomic so two truly
	 * simultaneous deliveries cannot both win: wp_cache_add on a persistent object
	 * cache, otherwise add_option, which the options table's UNIQUE(option_name)
	 * makes single-winner. The lock is released as soon as processing finishes; the
	 * TTL is only a crash-safety net.
	 *
	 * Scope: this serialises the webhook handler against other webhook deliveries —
	 * the reported concurrency source. Reconciliation and the manual metabox
	 * re-sync deliberately do not take the lock (they are low-concurrency); the
	 * shipments fingerprint is what stops them appending duplicate notes. See QA #7.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return bool True if the lock was acquired, false if already held.
	 */
	private static function acquire_order_lock( int $order_id ): bool {
		$key = "bobgo_webhook_lock_{$order_id}";
		$ttl = 30;

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, time(), 'bobgo', $ttl );
		}

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		// Lock already held. If it predates the TTL the holder likely crashed
		// before releasing, so reclaim it — still single-winner via the UNIQUE key.
		$held = (int) get_option( $key, 0 );
		if ( $held > 0 && ( time() - $held ) > $ttl ) {
			delete_option( $key );
			return (bool) add_option( $key, time(), '', false );
		}

		return false;
	}

	/**
	 * Release the per-order processing lock.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return void
	 */
	private static function release_order_lock( int $order_id ): void {
		$key = "bobgo_webhook_lock_{$order_id}";

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, 'bobgo' );
			return;
		}

		delete_option( $key );
	}

	/**
	 * Fetch complete fulfillment data from the Bob Go API and apply it to the order.
	 *
	 * Calls GET v2/order-fulfillments, groups fulfillments by shipment, builds enriched
	 * local records, full-replaces _bobgo_shipments meta (API is source of truth),
	 * determines fulfillment status, and updates the order accordingly.
	 *
	 * @param \WC_Order $order     The WooCommerce order.
	 * @param string    $order_ref The Bob Go order reference.
	 * @param bool      $changed   Out-param set to true only when the shipment data
	 *                             actually changed (vs a no-op refresh). Callers that
	 *                             only care about success can ignore it.
	 * @return bool True if fulfillment data was successfully refreshed (API call
	 *              succeeded and was applied), independent of whether it changed.
	 */
	public static function refresh_fulfillment_data( \WC_Order $order, string $order_ref, bool &$changed = false ): bool {
		$changed = false;

		// Only the stored Bob Go numeric ID may be queried — the API's order_id
		// parameter is Bob Go's own order id, while $order_ref is usually the
		// Woo channel ref. Sending the channel ref here 404s at best; at worst
		// the number collides with a different Bob Go order in the same account
		// and we would apply someone else's fulfillments to this order. No
		// stored link means no request.
		$bobgo_id = $order->get_meta( '_bobgo_order_id' );
		if ( empty( $bobgo_id ) ) {
			self::log( "Fulfillment refresh skipped for order {$order->get_id()}: no Bob Go order id stored (ref '{$order_ref}'). The link is created by the outbound order sync, backfilled from webhook payloads, or resolved via the orders API.", 'info' );
			return false;
		}

		$result = Api::get( 'v2/order-fulfillments?order_id=' . rawurlencode( $bobgo_id ) );

		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			self::log( "Failed to fetch fulfillments for order ref {$order_ref}: " . ( $result['error'] ?? 'Unknown error' ), 'warning' );
			return false;
		}

		$fulfillment_list = $result['body']['order_fulfillments'] ?? [];
		if ( ! is_array( $fulfillment_list ) || empty( $fulfillment_list ) ) {
			self::log( "No fulfillments returned for order ref {$order_ref}.", 'info' );
			return false;
		}

		// Group fulfillments by shipment ID.
		$shipment_groups = [];
		foreach ( $fulfillment_list as $entry ) {
			$of       = $entry['order_fulfillment'] ?? $entry;
			$shipment = $entry['shipment'] ?? [];

			$tracking_ref = $shipment['tracking_reference'] ?? $of['method_reference'] ?? '';
			$sid          = ! empty( $shipment['id'] ) ? (string) $shipment['id'] : $tracking_ref;

			if ( empty( $sid ) ) {
				continue;
			}

			if ( ! isset( $shipment_groups[ $sid ] ) ) {
				$courier      = sanitize_text_field( $shipment['provider']['name'] ?? $of['method'] ?? '' );
				$courier_slug = sanitize_text_field( $shipment['provider']['slug'] ?? '' );
				$tracking_url = self::build_tracking_url( $tracking_ref );

				// Extract tracking events from shipment.
				$tracking_events = [];
				$raw_events      = $shipment['tracking_events'] ?? $shipment['checkpoints'] ?? [];
				if ( is_array( $raw_events ) ) {
					foreach ( $raw_events as $evt ) {
						$tracking_events[] = [
							'timestamp' => sanitize_text_field( $evt['time'] ?? $evt['timestamp'] ?? '' ),
							'status'    => sanitize_text_field( $evt['status'] ?? '' ),
							'message'   => sanitize_text_field( $evt['message'] ?? $evt['description'] ?? '' ),
							'location'  => sanitize_text_field( $evt['location'] ?? '' ),
						];
					}
				}

				// Extract parcels.
				$parcels     = [];
				$raw_parcels = $shipment['parcels'] ?? [];
				if ( is_array( $raw_parcels ) ) {
					foreach ( $raw_parcels as $parcel ) {
						$parcels[] = [
							'tracking_number' => sanitize_text_field( $parcel['tracking_number'] ?? '' ),
							'weight'          => (float) ( $parcel['weight'] ?? 0 ),
							'dimensions'      => sanitize_text_field( $parcel['dimensions'] ?? '' ),
						];
					}
				}

				$shipment_groups[ $sid ] = [
					'shipment_id'              => sanitize_text_field( $sid ),
					'fulfillment_ids'          => [],
					'courier'                  => $courier,
					'courier_slug'             => $courier_slug,
					'tracking_number'          => sanitize_text_field( $tracking_ref ),
					'tracking_url'             => esc_url_raw( $tracking_url ),
					'status'                   => sanitize_text_field( $shipment['status'] ?? $of['method_status'] ?? $of['status'] ?? '' ),
					'submission_status'        => sanitize_text_field( $shipment['submission_status'] ?? '' ),
					'service_level'            => sanitize_text_field( $shipment['service_level'] ?? $of['service_level'] ?? '' ),
					'service_level_code'       => sanitize_text_field( $shipment['service_level_code'] ?? $of['service_level_code'] ?? '' ),
					'collection_location_type' => sanitize_text_field( $shipment['collection_location_type'] ?? $of['collection_location_type'] ?? '' ),
					'delivery_location_type'   => sanitize_text_field( $shipment['delivery_location_type'] ?? $of['delivery_location_type'] ?? '' ),
					'estimated_delivery'       => sanitize_text_field( $shipment['estimated_delivery'] ?? '' ),
					'parcels'                  => $parcels,
					'tracking_events'          => $tracking_events,
					'fulfilled_at'             => sanitize_text_field( $of['time_created'] ?? $shipment['time_created'] ?? '' ),
					'status_updated_at'        => sanitize_text_field( $shipment['time_modified'] ?? $of['time_modified'] ?? gmdate( 'c' ) ),
					'items'                    => [],
				];
			}

			// Add fulfillment ID.
			$fulfillment_id = (string) ( $of['id'] ?? '' );
			if ( ! empty( $fulfillment_id ) ) {
				$shipment_groups[ $sid ]['fulfillment_ids'][] = $fulfillment_id;
			}

			// Map fulfillment items to WC line items.
			$fulfillment_items = $of['items'] ?? [];
			if ( is_array( $fulfillment_items ) ) {
				foreach ( $fulfillment_items as $fi ) {
					$order_item = $fi['order_item'] ?? [];
					$wc_item_id = self::find_wc_item_id( $order, array_merge( $fi, $order_item ) );

					$shipment_groups[ $sid ]['items'][] = [
						'bobgo_item_id' => (string) ( $fi['order_item_id'] ?? '' ),
						'wc_item_id'    => $wc_item_id,
						'name'          => sanitize_text_field( $order_item['description'] ?? '' ),
						'quantity'      => (int) ( $fi['qty'] ?? $fi['quantity'] ?? 0 ),
					];
				}
			}
		}

		// Full-replace _bobgo_shipments — API is source of truth.
		$shipments = array_values( $shipment_groups );
		$order_id  = $order->get_id();

		// Detect whether the shipment data meaningfully changed before overwriting,
		// so the order note below (and reconciliation's counter via $changed) reflect
		// real updates rather than every no-op refresh. See QA #7.
		$stored  = $order->get_meta( '_bobgo_shipments' );
		$stored  = is_array( $stored ) ? $stored : [];
		$changed = self::shipments_fingerprint( $shipments ) !== self::shipments_fingerprint( $stored );

		$order->update_meta_data( '_bobgo_shipments', $shipments );
		$order->update_meta_data( '_bobgo_last_webhook', gmdate( 'c' ) );

		// Determine fulfillment status (only if custom statuses are enabled).
		$new_status = null;
		if ( OrderStatus::is_enabled() ) {
			$new_status = self::determine_fulfillment_status( $order, $shipments );
		}

		// Check if all shipments are delivered.
		if ( self::all_shipments_delivered( $shipments ) ) {
			$new_status = 'completed';
		}

		// Never let an inbound fulfillment refresh transition a terminal order
		// (cancelled/refunded/failed) — Bob Go keeps shipment data after a
		// cancellation, so a late or re-delivered webhook would otherwise silently
		// un-cancel it. The fulfillment ladder is forward-only so a transient/partial
		// shipment set can't flap a shipped/completed order backwards — unless a
		// cancelled/failed shipment is present in the refreshed set, which is
		// authoritative evidence that fulfillment decreased and lets the order move
		// back down to mirror Bob Go (order #1251).
		$allow_regression = self::has_inactive_shipment( $shipments );
		if ( $new_status && ! self::can_advance_to_status( $order, $new_status, $allow_regression ) ) {
			$new_status = null;
		}

		// Set echo loop guard BEFORE any status change.
		if ( $new_status ) {
			set_transient( "bobgo_webhook_status_change_{$order_id}", true, 10 );
			$order->set_status( $new_status );
			// Refresh the sync hash so the save below (woocommerce_update_order →
			// OrderSync::on_order_updated, gated by the hash, not the loop guard)
			// does not echo this Bob-Go-driven change back as an outbound PATCH.
			$order->update_meta_data( '_bobgo_sync_hash', OrderSync::compute_sync_hash( $order ) );
		}

		$order->save();

		// Only note when the shipment data actually changed — otherwise every webhook
		// delivery and hourly reconciliation pass would append an identical
		// "Fulfillment data refreshed" note (QA #7).
		if ( $changed ) {
			$shipment_count = count( $shipments );
			$note           = sprintf(
				/* translators: %d: number of shipments */
				_n(
					'Fulfillment data refreshed from Bob Go: %d shipment.',
					'Fulfillment data refreshed from Bob Go: %d shipments.',
					$shipment_count,
					'bobgo-shipping'
				),
				$shipment_count
			);
			$order->add_order_note( $note );
		}

		return true;
	}

	/**
	 * Determine what order status to set based on fulfillment state.
	 *
	 * @param \WC_Order                        $order     The order.
	 * @param array<int, array<string, mixed>> $shipments All shipments.
	 * @return string The fulfillment-derived status (without wc- prefix): 'shipped',
	 *                'partially-shipped' or 'processing'. Whether it is actually
	 *                applied is decided by can_advance_to_status().
	 */
	public static function determine_fulfillment_status( \WC_Order $order, array $shipments ): ?string {
		// Calculate total items in order.
		$order_qty = [];
		foreach ( $order->get_items() as $item ) {
			$order_qty[ $item->get_id() ] = $item->get_quantity();
		}

		// Calculate fulfilled quantities across all shipments.
		$fulfilled_qty = self::calculate_fulfilled_qty( $shipments );

		// Compare: are all items fully fulfilled?
		$all_fulfilled = true;
		$any_fulfilled = false;

		foreach ( $order_qty as $item_id => $qty ) {
			$fulfilled = $fulfilled_qty[ $item_id ] ?? 0;
			if ( $fulfilled > 0 ) {
				$any_fulfilled = true;
			}
			if ( $fulfilled < $qty ) {
				$all_fulfilled = false;
			}
		}

		if ( $all_fulfilled ) {
			return 'shipped';
		}

		if ( $any_fulfilled ) {
			return 'partially-shipped';
		}

		// Nothing is fulfilled (e.g. the only shipment was cancelled). Return the
		// pre-shipment status so a cancellation can regress the order; the caller's
		// can_advance_to_status() guard decides whether the move is actually applied.
		return 'processing';
	}

	/**
	 * Whether an inbound fulfillment refresh may transition $order to $new_status.
	 *
	 * Blocks transitioning a terminal order (cancelled/refunded/failed) — Bob Go
	 * retains shipment data after a cancellation, so a late or re-delivered
	 * fulfillment webhook must not un-cancel it.
	 *
	 * Movement along the fulfillment ladder (processing → partially-shipped →
	 * shipped → completed) is forward-only by default, so a transient or partial
	 * shipment set can't flap a shipped/completed order backwards. When
	 * $allow_regression is set — i.e. the refreshed set contains a cancelled/failed
	 * shipment, which is authoritative evidence that fulfillment decreased — a
	 * backward move is permitted so the order mirrors Bob Go. A 'completed' order is
	 * still never regressed: completion is treated as terminal for the store.
	 * Unknown non-terminal statuses are treated as pre-shipment (rank 0) so the
	 * normal forward flow is preserved.
	 *
	 * @param \WC_Order $order            The order being refreshed.
	 * @param string    $new_status       The fulfillment-derived target status.
	 * @param bool      $allow_regression Whether a cancellation permits moving back.
	 * @return bool
	 */
	private static function can_advance_to_status( \WC_Order $order, string $new_status, bool $allow_regression = false ): bool {
		if ( $order->has_status( [ 'cancelled', 'refunded', 'failed' ] ) ) {
			return false;
		}

		$rank = [
			'pending'           => 0,
			'on-hold'           => 0,
			'processing'        => 0,
			'partially-shipped' => 1,
			'shipped'           => 2,
			'completed'         => 3,
		];

		$current_rank = $rank[ $order->get_status() ] ?? 0;
		$target_rank  = $rank[ $new_status ] ?? 0;

		if ( $allow_regression && 'completed' !== $order->get_status() ) {
			return $target_rank !== $current_rank;
		}

		return $target_rank > $current_rank;
	}

	/**
	 * Shipment statuses that no longer contribute to fulfillment. Bob Go keeps the
	 * item rows of a cancelled or failed shipment but drops them from the order's
	 * fulfilled quantity, so the plugin must ignore them too — otherwise a cancelled
	 * shipment keeps the order looking shipped (order #1251). Compared lower-cased.
	 *
	 * @var string[]
	 */
	private const INACTIVE_SHIPMENT_STATUSES = [ 'cancelled', 'canceled', 'failed' ];

	/**
	 * Whether a shipment still counts toward fulfillment (i.e. is not cancelled or
	 * failed). Comparison is case-insensitive to tolerate API casing variations.
	 *
	 * @param array<string, mixed> $shipment Shipment record.
	 * @return bool
	 */
	public static function is_active_shipment( array $shipment ): bool {
		$status = strtolower( (string) ( $shipment['status'] ?? '' ) );
		return ! in_array( $status, self::INACTIVE_SHIPMENT_STATUSES, true );
	}

	/**
	 * Whether the shipment set contains at least one active (non-cancelled,
	 * non-failed) shipment.
	 *
	 * @param array<int, array<string, mixed>> $shipments Shipments data.
	 * @return bool
	 */
	public static function has_active_shipment( array $shipments ): bool {
		foreach ( $shipments as $shipment ) {
			if ( is_array( $shipment ) && self::is_active_shipment( $shipment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the shipment set contains at least one cancelled or failed shipment —
	 * authoritative evidence that fulfillment decreased, used to permit a backward
	 * status transition.
	 *
	 * @param array<int, array<string, mixed>> $shipments Shipments data.
	 * @return bool
	 */
	public static function has_inactive_shipment( array $shipments ): bool {
		foreach ( $shipments as $shipment ) {
			if ( is_array( $shipment ) && ! self::is_active_shipment( $shipment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Calculate fulfilled quantities per WC line item across all shipments.
	 *
	 * Cancelled/failed shipments are skipped: their item rows persist on Bob Go's
	 * side but no longer count as fulfilled.
	 *
	 * @param array<int, array<string, mixed>> $shipments Shipments data.
	 * @return array<int, int> Map of wc_item_id => fulfilled quantity.
	 */
	public static function calculate_fulfilled_qty( array $shipments ): array {
		$fulfilled_qty = [];
		foreach ( $shipments as $shipment ) {
			if ( ! self::is_active_shipment( $shipment ) ) {
				continue;
			}
			if ( empty( $shipment['items'] ) || ! is_array( $shipment['items'] ) ) {
				continue;
			}
			foreach ( $shipment['items'] as $item ) {
				$wc_item_id = $item['wc_item_id'] ?? 0;
				if ( $wc_item_id > 0 ) {
					$fulfilled_qty[ $wc_item_id ] = ( $fulfilled_qty[ $wc_item_id ] ?? 0 ) + ( $item['quantity'] ?? 0 );
				}
			}
		}
		return $fulfilled_qty;
	}

	/**
	 * Check if all shipments have "delivered" status.
	 *
	 * @param array<int, array<string, mixed>> $shipments Shipments data.
	 * @return bool
	 */
	public static function all_shipments_delivered( array $shipments ): bool {
		if ( empty( $shipments ) ) {
			return false;
		}

		// Cancelled/failed shipments don't gate completion — only the shipments
		// still in play need to be delivered. An all-cancelled set has no active
		// shipment to be "delivered", so it is not considered delivered.
		$active_seen = false;
		foreach ( $shipments as $shipment ) {
			if ( ! self::is_active_shipment( $shipment ) ) {
				continue;
			}
			$active_seen = true;
			if ( 'delivered' !== ( $shipment['status'] ?? '' ) ) {
				return false;
			}
		}

		return $active_seen;
	}

	/**
	 * Log a webhook event to the sync log.
	 *
	 * @param int    $order_id   The order ID.
	 * @param string $event_type Event type.
	 * @param array  $data       Payload data.
	 * @param bool   $success    Whether processing succeeded.
	 * @param string $event_id   Event ID.
	 * @param string $error      Error message (if any).
	 * @param int    $http_status HTTP response status returned to the caller. Defaults to 200/400 by success flag.
	 */
	private static function log_webhook( int $order_id, string $event_type, array $data, bool $success, string $event_id, string $error = '', int $http_status = 0 ): void {
		// Suppress duplicate rows for retries: if a successful row with this
		// (event_id + event_type) pair already exists, don't append a near-identical
		// entry. Scoping on event_type matters because Bob Go reuses one request ID
		// across the distinct topics in a single trigger (see QA #10).
		if ( $success && ! empty( $event_id ) && SyncLog::find_by_event_id( $event_id, $event_type ) ) {
			return;
		}

		$payload = $data;
		if ( ! empty( $error ) ) {
			$payload['_error'] = $error;
		}

		if ( 0 === $http_status ) {
			$http_status = $success ? 200 : 400;
		}

		SyncLog::insert(
			[
				'wc_order_id' => $order_id,
				'event_type'  => $event_type,
				'direction'   => 'inbound',
				'payload'     => wp_json_encode( $payload ),
				'http_status' => $http_status,
				'success'     => $success,
				'event_id'    => $event_id,
			]
		);
	}
}
