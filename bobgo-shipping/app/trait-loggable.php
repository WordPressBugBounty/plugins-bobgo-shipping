<?php
/**
 * Shared logging trait for WooCommerce logger integration.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Loggable
 *
 * Provides a static log() helper that writes to the WooCommerce logger
 * under the 'bobgo-shipping' source. Used by classes that need consistent
 * logging without duplicating the same method.
 */
trait Loggable {

	/**
	 * Log a message using WooCommerce logger.
	 *
	 * @param string $message The message.
	 * @param string $level   Log level (debug, info, notice, warning, error, critical, alert, emergency).
	 */
	private static function log( string $message, string $level = 'debug' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, [ 'source' => 'bobgo-shipping' ] );
		}
	}
}
