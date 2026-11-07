<?php
/**
 * Centralised HTTP client for all outbound Bob Go API calls.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Api
 *
 * Provides static methods for GET, POST, PATCH, DELETE requests to the Bob Go API.
 * Injects Authorization Bearer token on every request.
 */
class Api {

	use Loggable;

	/**
	 * Option key for the encrypted API key.
	 */
	const OPTION_API_KEY = 'bobgo_api_key';

	/**
	 * Option key for the encrypted webhook signing secret.
	 */
	const OPTION_WEBHOOK_SECRET = 'bobgo_webhook_secret';

	/**
	 * Option key for the cached connection-health record (does the key actually
	 * authenticate, vs merely being present). See Phase 3 #1.
	 */
	const OPTION_CONNECTION_HEALTH = 'bobgo_connection_health';

	/**
	 * Option key for the Bob Go account code the stored credentials belong to.
	 *
	 * Deliberately survives a disconnect. A disconnect queues the link reset, but
	 * queued is not done: the pass can be cancelled by an immediate reconnect, and
	 * on a store whose Action Scheduler queue is not running it may never start at
	 * all. Clearing the code in those cases left a reconnect to a *different*
	 * account with nothing to compare against, so it recorded the new code as a
	 * first observation, armed no reset, and left every order pointing at an
	 * account the store can no longer reach — with no later check able to notice,
	 * since the code then matched.
	 *
	 * Kept, the comparison still works: reconnecting to the same account reads
	 * 'unchanged' and leaves any links alone, while a different account reads
	 * 'changed' and arms the reset that clears them. Only a genuine rollback
	 * (clear_account_code(), when a detected switch could not be queued) forgets
	 * it, and uninstall removes it with the rest of the plugin's options.
	 */
	const OPTION_ACCOUNT_CODE = 'bobgo_account_code';

	/**
	 * Encryption method.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Perform a GET request.
	 *
	 * @param string               $path    API path (appended to base URL).
	 * @param array<string, mixed> $args    Additional wp_remote_get args.
	 * @return array{body: mixed, status: int, success: bool, error: string}
	 */
	public static function get( string $path, array $args = [] ): array {
		return self::request( 'GET', $path, $args );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string               $path    API path.
	 * @param array<string, mixed> $body    Request body (will be JSON-encoded).
	 * @param array<string, mixed> $args    Additional args.
	 * @return array{body: mixed, status: int, success: bool, error: string}
	 */
	public static function post( string $path, array $body = [], array $args = [] ): array {
		$args['body'] = wp_json_encode( $body );
		return self::request( 'POST', $path, $args );
	}

	/**
	 * Perform a PATCH request.
	 *
	 * @param string               $path    API path.
	 * @param array<string, mixed> $body    Request body (will be JSON-encoded).
	 * @param array<string, mixed> $args    Additional args.
	 * @return array{body: mixed, status: int, success: bool, error: string}
	 */
	public static function patch( string $path, array $body = [], array $args = [] ): array {
		$args['body'] = wp_json_encode( $body );
		return self::request( 'PATCH', $path, $args );
	}

	/**
	 * Perform a DELETE request.
	 *
	 * @param string               $path    API path.
	 * @param array<string, mixed> $args    Additional args.
	 * @return array{body: mixed, status: int, success: bool, error: string}
	 */
	public static function delete( string $path, array $args = [] ): array {
		return self::request( 'DELETE', $path, $args );
	}

	/**
	 * Perform an HTTP request to the Bob Go API.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    API path.
	 * @param array<string, mixed> $args    wp_remote_request args.
	 * @return array{body: mixed, status: int, success: bool, error: string}
	 */
	private static function request( string $method, string $path, array $args = [] ): array {
		$url     = SettingsPage::get_base_url() . '/' . ltrim( $path, '/' );
		$api_key = self::get_api_key();

		$timeout = apply_filters( 'bobgo_shipping_api_timeout', 30 );
		$timeout = min( max( (int) $timeout, 5 ), 60 );

		$defaults = [
			'method'    => $method,
			'headers'   => self::default_headers(),
			'timeout'   => $timeout,
			'sslverify' => apply_filters( 'bobgo_shipping_ssl_verify', true ),
		];

		$args = wp_parse_args( $args, $defaults );

		// Merge headers rather than overwriting.
		if ( isset( $args['headers'] ) && isset( $defaults['headers'] ) ) {
			$args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log( "API {$method} {$path} failed: " . $response->get_error_message(), 'error' );
			return [
				'body'    => null,
				'status'  => 0,
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$parsed = $body;
		}

		$success = $status >= 200 && $status < 300;

		if ( ! $success ) {
			self::log( "API {$method} {$path} returned {$status}", 'warning' );
		}

		// Record connection health from definitive auth signals so the dashboard
		// reflects whether the credentials actually work, not merely that a key is
		// stored (Phase 3 #1). 401 => refused; any 2xx => accepted; other statuses
		// (network errors, 404, 5xx) are inconclusive and leave the last known
		// state as-is.
		//
		// "Refused", not "the key is invalid": the channel identifier travels on
		// every request (see default_headers()) and Bob Go rejects a mismatch at
		// authentication too, so a 401 narrows the fault to the credentials as a
		// pair and no further. What the merchant is told about it lives in
		// SettingsPage's connection.rejected copy, which names both.
		if ( 401 === $status ) {
			self::record_health( false, 401 );
		} elseif ( $success ) {
			self::record_health( true, $status );
		}

		return [
			'body'    => $parsed,
			'status'  => $status,
			'success' => $success,
			'error'   => $success ? '' : ( is_array( $parsed ) && isset( $parsed['message'] ) ? $parsed['message'] : "HTTP {$status}" ),
		];
	}

	/**
	 * Build the standard request headers for every Bob Go API call.
	 *
	 * Single source of truth — used by Api::request() and by direct callers
	 * (e.g. the rates-at-checkout shipping calls) so the channel identifier,
	 * auth header and plugin version are guaranteed to be present everywhere.
	 *
	 * bobgo-plugin-version lets Bob Go tell which plugin release a request came
	 * from, so support can spot a store running a version with a known fault
	 * without asking the merchant, and a payload change can be attributed to the
	 * release that introduced it.
	 *
	 * @return array<string, string>
	 */
	public static function default_headers(): array {
		return [
			'Content-Type'             => 'application/json; charset=utf-8',
			'Accept'                   => 'application/json',
			'Authorization'            => 'Bearer ' . self::get_api_key(),
			'bobgo-channel-identifier' => self::get_store_url(),
			'bobgo-plugin-version'     => self::get_plugin_version(),
		];
	}

	/**
	 * The running plugin version, as sent on every outbound request.
	 *
	 * @return string The version, or '' when the constant is unavailable.
	 */
	public static function get_plugin_version(): string {
		return defined( 'BOBGO_SHIPPING_VERSION' ) ? (string) constant( 'BOBGO_SHIPPING_VERSION' ) : '';
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string The API key, or empty string if not set or decryption fails.
	 */
	public static function get_api_key(): string {
		$encrypted = get_option( self::OPTION_API_KEY, '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * Save the API key (encrypted).
	 *
	 * @param string $api_key The plain-text API key.
	 * @return bool True on success (or when clearing an already-empty value), false on failure.
	 */
	public static function save_api_key( string $api_key ): bool {
		if ( empty( $api_key ) ) {
			// delete_option returns false when the option does not exist; treat that as success.
			return delete_option( self::OPTION_API_KEY ) || ! get_option( self::OPTION_API_KEY, false );
		}

		$encrypted = self::encrypt( $api_key );
		return (bool) update_option( self::OPTION_API_KEY, $encrypted, false );
	}

	/**
	 * Get the decrypted webhook signing secret.
	 *
	 * @return string The webhook secret, or empty string if not set or decryption fails.
	 */
	public static function get_webhook_secret(): string {
		$encrypted = get_option( self::OPTION_WEBHOOK_SECRET, '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * Save the webhook signing secret (encrypted).
	 *
	 * @param string $secret The plain-text webhook secret.
	 * @return bool True on success (or when clearing an already-empty value), false on failure.
	 */
	public static function save_webhook_secret( string $secret ): bool {
		if ( empty( $secret ) ) {
			return delete_option( self::OPTION_WEBHOOK_SECRET ) || ! get_option( self::OPTION_WEBHOOK_SECRET, false );
		}

		$encrypted = self::encrypt( $secret );
		return (bool) update_option( self::OPTION_WEBHOOK_SECRET, $encrypted, false );
	}

	/**
	 * Get the Bob Go account code the stored credentials were last seen to belong to.
	 *
	 * @return string The account code (e.g. "AAA001"), or '' when none has been observed yet.
	 */
	public static function get_account_code(): string {
		return (string) get_option( self::OPTION_ACCOUNT_CODE, '' );
	}

	/**
	 * Forget which account the credentials belonged to.
	 *
	 * @return void
	 */
	public static function clear_account_code(): void {
		delete_option( self::OPTION_ACCOUNT_CODE );
	}

	/**
	 * Fetch the account the stored API key belongs to.
	 *
	 * An API key is scoped to a single account, so the list this endpoint
	 * returns holds exactly one entry.
	 *
	 * @return array<string, mixed>|null The account, or null when the call failed or returned none.
	 */
	private static function fetch_account(): ?array {
		$result = self::get( 'v2/accounts' );

		if ( ! $result['success'] || ! is_array( $result['body'] ) ) {
			return null;
		}

		$accounts = $result['body']['accounts'] ?? [];
		if ( ! is_array( $accounts ) || empty( $accounts[0] ) || ! is_array( $accounts[0] ) ) {
			return null;
		}

		return $accounts[0];
	}

	/**
	 * Ask Bob Go which account the stored key belongs to, and say whether it moved.
	 *
	 * @return string recorded | unchanged | changed | unknown (the API could not be reached)
	 */
	public static function refresh_account_identity(): string {
		$account = self::fetch_account();

		if ( null === $account ) {
			return 'unknown';
		}

		return self::observe_account( (string) ( $account['account_code'] ?? '' ) );
	}

	/**
	 * Record the account an API response reported, and say whether it moved.
	 *
	 * The account — not the API key — identifies the tenant. A key rotated on
	 * the same account must leave stored order links alone, while a store
	 * reconnected to a different account holds links into a namespace it can
	 * no longer reach (every call 404s with "does not exist for account_id").
	 *
	 * A first observation is only recorded, never treated as a switch: an
	 * install that predates this record has nothing to compare against, and
	 * guessing from that absence would re-send every order as a duplicate. That
	 * is also what stores upgrading from the numeric-account-id version get —
	 * that record lived under a different option key and is simply not read, so
	 * the first check after upgrading finds nothing stored and records the code
	 * silently rather than mistaking the upgrade for a switch.
	 *
	 * @param string $account_code The account code reported by the API.
	 * @return string recorded | unchanged | changed
	 */
	public static function observe_account( string $account_code ): string {
		if ( '' === $account_code ) {
			return 'unchanged';
		}

		$known = self::get_account_code();

		if ( $known === $account_code ) {
			return 'unchanged';
		}

		update_option( self::OPTION_ACCOUNT_CODE, $account_code, false );

		if ( '' === $known ) {
			return 'recorded';
		}

		self::log( "Bob Go account changed from {$known} to {$account_code}: stored order links belong to the previous account.", 'warning' );

		return 'changed';
	}

	/**
	 * Get the store URL used as identifier.
	 *
	 * @return string
	 */
	public static function get_store_url(): string {
		return Admin::get_api_domain();
	}

	/**
	 * Check if the plugin is connected (has a valid API key).
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return ! empty( self::get_api_key() );
	}

	/**
	 * Validate the connection by calling the Bob Go API.
	 *
	 * Checks against v2/accounts rather than v2/webhooks: an account always has
	 * exactly one account record, whereas it may legitimately have no webhook
	 * subscriptions at the moment of the check (a fresh account, or one whose
	 * subscriptions were just torn down), which left the answer indistinguishable
	 * from an account the key could not see.
	 *
	 * @return array{success: bool, message: string, data: mixed, status: int}
	 */
	public static function validate_connection(): array {
		if ( ! self::is_connected() ) {
			return [
				'success' => false,
				'message' => __( 'No API key configured.', 'bobgo-shipping' ),
				'data'    => null,
				'status'  => 0,
			];
		}

		$result = self::get( 'v2/accounts' );

		if ( $result['success'] ) {
			return [
				'success' => true,
				'message' => __( 'Connected successfully.', 'bobgo-shipping' ),
				'data'    => $result['body'],
				'status'  => $result['status'],
			];
		}

		$message = __( 'Connection failed.', 'bobgo-shipping' );
		if ( 401 === $result['status'] ) {
			// Not "Invalid API key." A 401 is everything Bob Go refuses at
			// authentication, and the channel identifier sent on every request is
			// part of that — so a valid key on a store whose identifier matches no
			// sales channel was being reported as a bad key. Both are named.
			$message = __( 'Bob Go did not accept these credentials (HTTP 401). The API key may have been revoked, or the channel identifier this store sends may not match one of your Bob Go sales channels.', 'bobgo-shipping' );
		} elseif ( 404 === $result['status'] ) {
			$message = __( 'Account not found. Please check your API key.', 'bobgo-shipping' );
		} elseif ( ! empty( $result['error'] ) ) {
			$message = $result['error'];
		}

		return [
			'success' => false,
			'message' => $message,
			'data'    => $result['body'],
			'status'  => $result['status'],
		];
	}

	/**
	 * Record the connection-health record from a definitive API auth signal.
	 *
	 * Writes only on a state transition (the ok flag flips) so it stays cheap on
	 * the hot path; the option is autoloaded so reads are free. Called via the
	 * write-through in request() for all routed calls, and directly from the
	 * rates-at-checkout path (which uses wp_remote_post + default_headers, not
	 * request()). So a key that is invalid on entry — or revoked later — is
	 * reflected from normal traffic without any extra request. Pass only definitive
	 * auth outcomes: 401 => invalid, 2xx => valid. See Phase 3 #1.
	 *
	 * @param bool $ok     Whether the key authenticated successfully.
	 * @param int  $status The HTTP status that produced this outcome.
	 * @return void
	 */
	public static function record_health( bool $ok, int $status ): void {
		$current = get_option( self::OPTION_CONNECTION_HEALTH, null );
		$prev_ok = is_array( $current ) && isset( $current['ok'] ) ? (bool) $current['ok'] : null;

		if ( $prev_ok === $ok ) {
			return;
		}

		update_option(
			self::OPTION_CONNECTION_HEALTH,
			[
				'ok'         => $ok,
				'status'     => $status,
				'checked_at' => gmdate( 'c' ),
			],
			true
		);
	}

	/**
	 * Get the stored connection-health record, or null if never recorded.
	 *
	 * @return array{ok: bool, status: int, checked_at: string}|null
	 */
	public static function get_connection_health(): ?array {
		$health = get_option( self::OPTION_CONNECTION_HEALTH, null );
		return is_array( $health ) ? $health : null;
	}

	/**
	 * Clear the connection-health record (e.g. on disconnect).
	 *
	 * @return void
	 */
	public static function clear_connection_health(): void {
		delete_option( self::OPTION_CONNECTION_HEALTH );
	}

	/**
	 * Encrypt a value using AES-256-CBC with WordPress AUTH_KEY.
	 *
	 * @param string $value The value to encrypt.
	 * @return string Base64-encoded encrypted value.
	 */
	private static function encrypt( string $value ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			self::log( 'OpenSSL not available — API key stored with base64 encoding only.', 'warning' );
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- intentional encoding for storage
		}

		$key = self::get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, 0, $iv );
		if ( false === $encrypted ) {
			self::log( 'OpenSSL encryption failed — API key stored with base64 encoding only.', 'warning' );
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- intentional fallback
		}

		// Hex-encode the IV before joining with '::' so the delimiter can never
		// collide with a raw IV byte. A binary IV contains the pair 0x3A3A ('::')
		// roughly 1 time in ~4000, which previously split inside the IV and
		// permanently corrupted decryption of the stored key/secret.
		return base64_encode( bin2hex( $iv ) . '::' . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- intentional encoding for binary-safe storage
	}

	/**
	 * Decrypt a value encrypted with encrypt().
	 *
	 * @param string $value The encrypted value.
	 * @return string The decrypted value, or empty string on failure.
	 */
	private static function decrypt( string $value ): string {
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $decoded;
		}

		// Check if it's an encrypted value (contains ::).
		if ( strpos( $decoded, '::' ) === false ) {
			// Not encrypted, just base64 encoded.
			return $decoded;
		}

		$parts = explode( '::', $decoded, 2 );
		if ( count( $parts ) !== 2 ) {
			return '';
		}

		$key       = self::get_encryption_key();
		$encrypted = $parts[1];

		// IVs are stored hex-encoded (see encrypt()) so the '::' delimiter can
		// never collide with a raw IV byte. Fall back to treating the IV part as
		// raw binary for values written by the previous (binary-IV) format so
		// existing installs continue to decrypt.
		$iv_length = (int) openssl_cipher_iv_length( self::CIPHER );
		$iv        = ( ctype_xdigit( $parts[0] ) && strlen( $parts[0] ) === $iv_length * 2 )
			? hex2bin( $parts[0] )
			: $parts[0];

		$decrypted = openssl_decrypt( $encrypted, self::CIPHER, $key, 0, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Get the encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_encryption_key(): string {
		if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
			$salt = AUTH_KEY;
		} else {
			// No AUTH_KEY: fall back to WordPress's per-site auth salt (core
			// generates and persists one in the DB when the constants are
			// missing) instead of a shared, source-visible constant.
			self::log( 'WordPress AUTH_KEY is not defined — falling back to the site auth salt for encryption. Define AUTH_KEY in wp-config.php for a stronger key.', 'warning' );
			$salt = wp_salt( 'auth' );
		}
		return hash( 'sha256', $salt, true );
	}
}
