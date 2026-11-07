<?php
/**
 * Sync Log — Custom table CRUD for sync event logging.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SyncLog
 *
 * Manages the {prefix}bobgo_sync_log custom table.
 */
class SyncLog {

	/**
	 * Get the full table name including prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'bobgo_sync_log';
	}

	/**
	 * Memoised result of table_exists() for this request, reset by create_table().
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Whether the log table exists.
	 *
	 * Callers that read the log on a page load (the Overview health signals, the
	 * Advanced log viewer) check this first: querying a missing table surfaces a
	 * database error to the merchant instead of an empty result. Several of them
	 * ask more than once per request, and the answer cannot change under a request
	 * except by create_table(), which clears the memo.
	 *
	 * The name is escaped for LIKE because the table prefix is site-configurable
	 * and an underscore is a single-character wildcard: unescaped, a store with an
	 * unlucky pair of prefixes could have this answer yes on another site's table.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		self::$table_exists = ! empty( $result );

		return self::$table_exists;
	}

	/**
	 * Create the sync log table via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		// The Advanced tab asks whether the table exists, creates it when it does
		// not, and asks again to tell "created" from "the host will not allow it".
		self::$table_exists = null;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(50) NOT NULL DEFAULT '',
			direction varchar(10) NOT NULL DEFAULT '',
			payload longtext,
			http_status smallint(5) unsigned DEFAULT NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
			event_id varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wc_order_id (wc_order_id),
			KEY event_id (event_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the sync log table.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
	}

	/**
	 * PII field names redacted from every persisted payload. Keep diagnostic
	 * IDs (channel_ref_id, channel_order_number, ids, statuses, item
	 * descriptions) intact so support can still correlate logs to orders.
	 */
	const REDACTED_PAYLOAD_KEYS = [
		'customer_name',
		'customer_surname',
		'customer_email',
		'customer_phone',
		'first_name',
		'last_name',
		'email',
		'phone',
		'phone_number',
		'mobile',
		'note',
		'notes',
		'customer_note',
		'instructions',
		'special_instructions',
		'company',
		'street_address',
		'address_1',
		'address_2',
		'address',
		'local_area',
		'suburb',
		'city',
		'code',
		'postal_code',
		'postcode',
		'zip',
	];

	/**
	 * Recursively scrub PII fields from a payload value. Operates on a copy;
	 * never mutates the caller's data.
	 *
	 * @param mixed $value Payload value (typically an associative array).
	 * @return mixed Scrubbed value of the same shape.
	 */
	public static function scrub_payload( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $child ) {
				if ( is_string( $key ) && in_array( $key, self::REDACTED_PAYLOAD_KEYS, true ) ) {
					$out[ $key ] = '[redacted]';
				} else {
					$out[ $key ] = self::scrub_payload( $child );
				}
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Take any payload (JSON string, array, or scalar) and return a scrubbed
	 * JSON string safe to persist. Used by insert() so every caller's payload
	 * is centrally redacted before it hits the database.
	 *
	 * @param mixed $payload Caller-provided payload.
	 * @return string Scrubbed JSON, or empty string for null/empty input.
	 */
	private static function scrub_payload_to_json( $payload ): string {
		if ( null === $payload || '' === $payload ) {
			return '';
		}

		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				// Non-JSON string — keep as-is; nothing structured to redact.
				return $payload;
			}
			$payload = $decoded;
		}

		$encoded = wp_json_encode( self::scrub_payload( $payload ) );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Insert a new log entry. All payloads are run through the central PII
	 * scrubber before persisting — callers cannot bypass redaction.
	 *
	 * @param array<string, mixed> $data Log entry fields: wc_order_id, event_type, direction,
	 *                                   payload, http_status, success, retry_count, event_id.
	 * @return int|false The inserted row ID or false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$defaults = [
			'wc_order_id' => 0,
			'event_type'  => '',
			'direction'   => '',
			'payload'     => '',
			'http_status' => null,
			'success'     => 0,
			'retry_count' => 0,
			'event_id'    => null,
			'created_at'  => current_time( 'mysql', true ),
		];

		$data = wp_parse_args( $data, $defaults );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			self::get_table_name(),
			[
				'wc_order_id' => (int) $data['wc_order_id'],
				'event_type'  => sanitize_text_field( $data['event_type'] ),
				'direction'   => sanitize_text_field( $data['direction'] ),
				'payload'     => self::scrub_payload_to_json( $data['payload'] ),
				'http_status' => null !== $data['http_status'] ? (int) $data['http_status'] : null,
				'success'     => (int) (bool) $data['success'],
				'retry_count' => (int) $data['retry_count'],
				'event_id'    => null !== $data['event_id'] ? sanitize_text_field( $data['event_id'] ) : null,
				'created_at'  => $data['created_at'],
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $result ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'SyncLog insert failed: ' . $wpdb->last_error,
					[ 'source' => 'bobgo-shipping' ]
				);
			}
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Find a log entry by event ID (for idempotency checks).
	 *
	 * Bob Go reuses a single request ID across the distinct topics fired in one
	 * trigger (e.g. order/updated, fulfillment/created and tracking/updated all
	 * share a Bob-Go-Request-Id). Pass $event_type so the lookup keys on the
	 * (event_id + event_type) pair and each topic is logged once, rather than the
	 * first topic suppressing the rest. See QA #10.
	 *
	 * @param string $event_id   The Bob Go event ID.
	 * @param string $event_type Optional topic to scope the match to.
	 * @return object|null The log entry or null if not found.
	 */
	public static function find_by_event_id( string $event_id, string $event_type = '' ): ?object {
		global $wpdb;

		$table = self::get_table_name();

		if ( '' !== $event_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %s AND event_type = %s AND success = 1 ORDER BY id DESC LIMIT 1', $table, $event_id, $event_type ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %s AND success = 1 ORDER BY id DESC LIMIT 1', $table, $event_id ) );
		}

		return $row ? $row : null;
	}

	/**
	 * Get log entries for a specific order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @param int $limit    Max entries to return.
	 * @return array<object>
	 */
	public static function get_by_order( int $order_id, int $limit = 50 ): array {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE wc_order_id = %d ORDER BY created_at DESC LIMIT %d', $table, $order_id, $limit ) );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get recent log entries with optional filters.
	 *
	 * @param array<string, mixed> $filters Optional filter map keyed by order_id, event_type,
	 *                                      direction, success.
	 * @param int                  $limit  Max entries.
	 * @param int                  $offset Pagination offset.
	 * @return array<object>
	 */
	public static function get_recent( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table                   = self::get_table_name();
		[ $where_clause, $args ] = self::build_where_clause( $filters );
		$args[]                  = $limit;
		$args[]                  = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d", $table, ...$args ) );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count log entries with optional filters (for pagination).
	 *
	 * @param array<string, mixed> $filters Same as get_recent().
	 * @return int
	 */
	public static function count( array $filters = [] ): int {
		global $wpdb;

		$table                   = self::get_table_name();
		[ $where_clause, $args ] = self::build_where_clause( $filters );

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_clause}", $table, ...$args ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Build a WHERE clause and arguments array from filters.
	 *
	 * @param array<string, mixed> $filters Filter values.
	 * @return array{0: string, 1: array<int, mixed>} [ $where_clause, $args ]
	 */
	private static function build_where_clause( array $filters ): array {
		$where = [];
		$args  = [];

		if ( ! empty( $filters['order_id'] ) ) {
			$where[] = 'wc_order_id = %d';
			$args[]  = (int) $filters['order_id'];
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$args[]  = sanitize_text_field( $filters['event_type'] );
		}

		// Set membership, for readers that mean "any of these kinds of event" or
		// "anything except these". A push is several event types, and an inbound
		// delivery from Bob Go is anything inbound that the plugin did not write
		// itself — neither can be expressed by the single-type filter above, and
		// scanning rows in PHP to make up the difference reads far more of this
		// table than a LIMIT 1 needs to.
		$set_filters = [
			'event_types'         => 'IN',
			'exclude_event_types' => 'NOT IN',
		];

		foreach ( $set_filters as $filter_key => $operator ) {
			if ( empty( $filters[ $filter_key ] ) || ! is_array( $filters[ $filter_key ] ) ) {
				continue;
			}

			$types   = array_values( array_map( 'sanitize_text_field', $filters[ $filter_key ] ) );
			$where[] = 'event_type ' . $operator . ' (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')';
			foreach ( $types as $type ) {
				$args[] = $type;
			}
		}

		if ( ! empty( $filters['direction'] ) ) {
			$where[] = 'direction = %s';
			$args[]  = sanitize_text_field( $filters['direction'] );
		}

		if ( isset( $filters['success'] ) ) {
			$where[] = 'success = %d';
			$args[]  = (int) (bool) $filters['success'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		return [ $where_clause, $args ];
	}

	/**
	 * Purge entries older than a given number of days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of rows deleted.
	 */
	public static function purge_older_than( int $days = 30 ): int {
		global $wpdb;

		$table  = self::get_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );

		return max( 0, (int) $deleted );
	}
}
