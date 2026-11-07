<?php
/**
 * Uninstall handler for the Bob Go Smart Shipping plugin.
 *
 * Removes plugin options, transients, the sync log table, and any scheduled
 * Action Scheduler jobs. Runs once when WordPress deletes the plugin.
 *
 * @package BobGo_Shipping
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data for the current site.
 */
function bobgo_shipping_uninstall_site() {
	global $wpdb;

	$bobgo_options = [
		'bobgo',
		'woocommerce_bobgo_shipping_settings',
		'bobgo_account_code',
		'bobgo_api_key',
		'bobgo_connection_health',
		'bobgo_db_version',
		'bobgo_items_fingerprint_backfilled',
		'bobgo_last_reconciliation',
		'bobgo_legacy_markup_migrated',
		'bobgo_link_audit_done',
		'bobgo_link_audit_stats',
		'bobgo_link_reset_cursor',
		'bobgo_link_reset_queue',
		'bobgo_link_reset_reason',
		'bobgo_link_reset_resync',
		'bobgo_migration_checked',
		'bobgo_order_meta_migrated',
		'bobgo_setup_completed',
		'bobgo_webhook_secret',
		'bobgo_webhooks_disconnected',
		'bobgo_webhooks_last_health_check',
		'bobgo_webhooks_registered',
		'bobgo_zone_methods_migrated',
	];
	foreach ( $bobgo_options as $bobgo_option ) {
		delete_option( $bobgo_option );
	}

	// Delete all plugin transients, including dynamically named ones
	// (rates cache, webhook status notices, reconciliation queue).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bobgo_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bobgo_' ) . '%'
		)
	);

	// Drop sync log table.
	$bobgo_table = $wpdb->prefix . 'bobgo_sync_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $bobgo_table ) );
}

// Unschedule Action Scheduler jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'bobgo_reconciliation' );
	as_unschedule_all_actions( 'bobgo_purge_sync_log' );
	as_unschedule_all_actions( 'bobgo_sync_order' );
	as_unschedule_all_actions( 'bobgo_update_order' );
	as_unschedule_all_actions( 'bobgo_update_order_status' );
	as_unschedule_all_actions( 'bobgo_register_webhooks' );
	as_unschedule_all_actions( 'bobgo_full_reconciliation_batch' );
	as_unschedule_all_actions( 'bobgo_link_audit_batch' );
	as_unschedule_all_actions( 'bobgo_backfill_items_fingerprints' );
}

if ( is_multisite() ) {
	$bobgo_blog_ids = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $bobgo_blog_ids as $bobgo_blog_id ) {
		switch_to_blog( $bobgo_blog_id );
		bobgo_shipping_uninstall_site();
		restore_current_blog();
	}
} else {
	bobgo_shipping_uninstall_site();
}
