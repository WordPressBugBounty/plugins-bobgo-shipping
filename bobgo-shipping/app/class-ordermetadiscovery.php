<?php
/**
 * Order meta key discovery for custom field mapping.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderMetaDiscovery
 *
 * Discovers available order meta keys from WooCommerce orders (HPOS-aware),
 * with caching and blocklist filtering.
 */
class OrderMetaDiscovery {

	use Loggable;

	/**
	 * Transient key for cached meta keys.
	 */
	const CACHE_KEY = 'bobgo_order_meta_keys';

	/**
	 * Transient key for cached order item meta keys (product options).
	 */
	const ITEM_CACHE_KEY = 'bobgo_item_meta_keys';

	/**
	 * Cache TTL in seconds (1 hour).
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Maximum number of distinct meta keys to discover.
	 */
	const DISCOVER_LIMIT = 500;

	/**
	 * Meta key prefix blocklist — keys starting with these are excluded.
	 *
	 * @var string[]
	 */
	const PREFIX_BLOCKLIST = [
		'_billing_',
		'_shipping_',
		'_order_',
		'_payment_',
		'_customer_',
		'_wc_',
		'_wp_',
		'_edit_',
		'_bobgo_',
		'bobgo_',
		'uafrica_',
		'_stripe_',
		'_paypal_',
		'_peach_',
		'_payfast_',
		'_yoco_',
		'_ozow_',
		'_transaction_',
		'_download_',
		'_recorded_',
		'_prices_',
		'_cart_',
		'_date_',
		'_created_',
	];

	/**
	 * Meta keys to exclude by exact match.
	 *
	 * @var string[]
	 */
	const EXACT_BLOCKLIST = [
		'is_vat_exempt',
		'_reduced_stock',
		'_new_order_email_sent',
		'_order_stock_reduced',
		'_order_key',
		'_cart_hash',
		// Suburb keys: either written to customer meta only (so the order value is
		// empty and the mapping silently sends nothing) or already synced natively
		// in the delivery address (local_area). See QA #3.
		'cb_shipping_suburb',
		'shipping_suburb',
		'billing_suburb',
		'legacy_shipping_suburb',
	];

	/**
	 * Item meta keys to exclude by exact match. Item meta discovery scans the
	 * whole order_itemmeta table (no join on item type, to keep the query on
	 * the meta_key index), so visible keys belonging to shipping, tax, and
	 * coupon line items land in the result and are hidden here — they are not
	 * product options.
	 *
	 * @var string[]
	 */
	const ITEM_EXACT_BLOCKLIST = [
		// Shipping items.
		'method_id',
		'instance_id',
		'cost',
		'total_tax',
		'taxes',
		'Items',
		// Tax items.
		'rate_code',
		'rate_id',
		'label',
		'compound',
		'tax_amount',
		'shipping_tax_amount',
		'rate_percent',
		// Coupon items.
		'discount_amount',
		'discount_amount_tax',
		'coupon_data',
		'coupon_info',
	];

	/**
	 * Get cached meta keys, discovering them if necessary.
	 *
	 * @param bool $force Force a fresh discovery (ignore cache).
	 * @return string[]
	 */
	public static function get_meta_keys( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$keys = self::discover_meta_keys();
		set_transient( self::CACHE_KEY, $keys, self::CACHE_TTL );

		return $keys;
	}

	/**
	 * Discover distinct order meta keys from the database.
	 *
	 * @return string[]
	 */
	private static function discover_meta_keys(): array {
		global $wpdb;

		if ( self::is_hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$keys = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe.
				$wpdb->prepare( 'SELECT DISTINCT meta_key FROM ' . esc_sql( $table ) . ' ORDER BY meta_key ASC LIMIT %d', self::DISCOVER_LIMIT )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
					ORDER BY pm.meta_key ASC
					LIMIT %d",
					self::DISCOVER_LIMIT
				)
			);
		}

		if ( ! is_array( $keys ) ) {
			return [];
		}

		return self::filter_meta_keys( $keys );
	}

	/**
	 * Get cached order item meta keys (product options), discovering if necessary.
	 *
	 * @param bool $force Force a fresh discovery (ignore cache).
	 * @return string[]
	 */
	public static function get_item_meta_keys( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::ITEM_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$keys = self::discover_item_meta_keys();
		set_transient( self::ITEM_CACHE_KEY, $keys, self::CACHE_TTL );

		return $keys;
	}

	/**
	 * Discover distinct visible order item meta keys from the database.
	 *
	 * Order items live in woocommerce_order_itemmeta regardless of HPOS.
	 * Hidden (underscore-prefixed) keys are excluded in SQL — they are never
	 * forwarded, so there is nothing to manage for them — and known
	 * shipping/tax/coupon item keys are filtered via ITEM_EXACT_BLOCKLIST.
	 *
	 * @return string[]
	 */
	private static function discover_item_meta_keys(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe.
			$wpdb->prepare(
				'SELECT DISTINCT meta_key FROM ' . esc_sql( $table ) . ' WHERE meta_key NOT LIKE %s ORDER BY meta_key ASC LIMIT %d',
				$wpdb->esc_like( '_' ) . '%',
				self::DISCOVER_LIMIT
			)
		);

		if ( ! is_array( $keys ) ) {
			return [];
		}

		$filtered = array_values( array_diff( $keys, self::ITEM_EXACT_BLOCKLIST ) );
		sort( $filtered );

		return $filtered;
	}

	/**
	 * Check if HPOS (High-Performance Order Storage) is enabled.
	 *
	 * @return bool
	 */
	public static function is_hpos_enabled(): bool {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Filter meta keys through prefix and exact blocklists.
	 *
	 * @param string[] $keys Raw meta keys.
	 * @return string[]
	 */
	public static function filter_meta_keys( array $keys ): array {
		$filtered = [];

		foreach ( $keys as $key ) {
			if ( in_array( $key, self::EXACT_BLOCKLIST, true ) ) {
				continue;
			}

			$blocked = false;
			foreach ( self::PREFIX_BLOCKLIST as $prefix ) {
				if ( strpos( $key, $prefix ) === 0 ) {
					$blocked = true;
					break;
				}
			}

			if ( ! $blocked ) {
				$filtered[] = $key;
			}
		}

		sort( $filtered );
		return $filtered;
	}

	/**
	 * Get a sample value for a given meta key from the most recent order.
	 *
	 * @param string $key The meta key.
	 * @return string
	 */
	public static function get_sample_value( string $key ): string {
		global $wpdb;

		if ( self::is_hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe.
				$wpdb->prepare( 'SELECT meta_value FROM ' . esc_sql( $table ) . " WHERE meta_key = %s AND meta_value != '' ORDER BY id DESC LIMIT 1", $key )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.meta_value
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
					AND pm.meta_key = %s
					AND pm.meta_value != ''
					ORDER BY p.ID DESC
					LIMIT 1",
					$key
				)
			);
		}

		return self::format_sample_value( $value );
	}

	/**
	 * Get a sample value for a given order item meta key.
	 *
	 * @param string $key The item meta key.
	 * @return string
	 */
	public static function get_item_sample_value( string $key ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe.
			$wpdb->prepare( 'SELECT meta_value FROM ' . esc_sql( $table ) . " WHERE meta_key = %s AND meta_value != '' ORDER BY meta_id DESC LIMIT 1", $key )
		);

		return self::format_sample_value( $value );
	}

	/**
	 * Format a raw sample meta value for display in the settings UI.
	 *
	 * @param string|null $value Raw meta value from the database.
	 * @return string
	 */
	private static function format_sample_value( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		// Check for serialized data.
		if ( is_serialized( $value ) ) {
			return '(serialized data)';
		}

		// Truncate long values.
		if ( mb_strlen( $value ) > 100 ) {
			return mb_substr( $value, 0, 100 ) . '…';
		}

		return $value;
	}

	/**
	 * AJAX handler: refresh meta keys (clear cache and return fresh keys).
	 */
	public static function handle_ajax_refresh_keys(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bobgo_custom_fields' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ),
					'nonce'   => wp_create_nonce( 'bobgo_custom_fields' ),
				]
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		delete_transient( self::CACHE_KEY );
		$keys = self::get_meta_keys( true );

		wp_send_json_success(
			[
				'keys'  => $keys,
				'nonce' => wp_create_nonce( 'bobgo_custom_fields' ),
			]
		);
	}

	/**
	 * AJAX handler: get sample value for a meta key.
	 */
	public static function handle_ajax_get_sample(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bobgo_custom_fields' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Your session has expired. Please refresh the page and try again.', 'bobgo-shipping' ),
					'nonce'   => wp_create_nonce( 'bobgo_custom_fields' ),
				]
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bobgo-shipping' ) ] );
		}

		$key = isset( $_POST['meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( [ 'message' => __( 'No meta key provided.', 'bobgo-shipping' ) ] );
		}

		$sample = self::get_sample_value( $key );

		wp_send_json_success(
			[
				'sample' => $sample,
				'nonce'  => wp_create_nonce( 'bobgo_custom_fields' ),
			]
		);
	}
}
