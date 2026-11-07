<?php
/**
 * Datastore-safe order meta queries.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderQuery
 *
 * Note: wc_get_orders() silently ignores the `meta_query` argument on the legacy
 * (CPT) order datastore — the query runs unfiltered and returns whatever
 * orders are newest, which is how imported Bob Go orders' webhooks got glued
 * to unrelated shop orders (Mondvol incident). HPOS supports `meta_query`
 * natively. This helper routes a meta query through whichever mechanism the
 * active datastore actually supports:
 *
 * - HPOS: pass `meta_query` directly.
 * - Legacy CPT: pass it as the custom `bobgo_meta_query` query var, which
 *   translate_cpt_meta_query() maps onto WP_Query's meta_query via the
 *   documented `woocommerce_order_data_store_cpt_get_orders_query` filter.
 *
 * Plain `meta_key`/`meta_value` arguments (including bare-key EXISTS and
 * `orderby => meta_value`) work on both datastores and don't need this
 * helper — verified against WooCommerce 9.x on both storage modes.
 */
class OrderQuery {

	/**
	 * Custom query var carrying a meta_query for the legacy CPT datastore.
	 */
	const QUERY_VAR = 'bobgo_meta_query';

	/**
	 * Query orders with a meta_query that works on both order datastores.
	 *
	 * @param array<string, mixed>     $args       The wc_get_orders() args (without meta_query).
	 * @param array<int|string, mixed> $meta_query WP_Query-style meta_query.
	 * @return array<int, \WC_Order|int> Orders (shape per $args['return']).
	 */
	public static function orders_with_meta( array $args, array $meta_query ): array {
		if ( self::hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $meta_query;
		} else {
			$args[ self::QUERY_VAR ] = $meta_query;
		}

		$orders = wc_get_orders( $args );

		return is_array( $orders ) ? $orders : [];
	}

	/**
	 * Filter callback: translate the custom query var into WP_Query's
	 * meta_query for the legacy CPT order datastore.
	 *
	 * Registered on `woocommerce_order_data_store_cpt_get_orders_query` —
	 * the documented way to add custom-field support to wc_get_orders() on
	 * the CPT datastore. Nested as a single clause so it composes with any
	 * meta_query WooCommerce itself built from internal keys.
	 *
	 * @param array<string, mixed> $query      WP_Query args built by the datastore.
	 * @param array<string, mixed> $query_vars Original wc_get_orders() vars.
	 * @return array<string, mixed>
	 */
	public static function translate_cpt_meta_query( array $query, array $query_vars ): array {
		if ( ! empty( $query_vars[ self::QUERY_VAR ] ) && is_array( $query_vars[ self::QUERY_VAR ] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- this filter is how the CPT datastore supports meta queries at all.
			$query['meta_query']   = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : [];
			$query['meta_query'][] = $query_vars[ self::QUERY_VAR ];
		}

		return $query;
	}

	/**
	 * Whether HPOS (custom orders table) is the authoritative datastore.
	 *
	 * @return bool
	 */
	private static function hpos_enabled(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
