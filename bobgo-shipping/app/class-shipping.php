<?php
/**
 * Bob Go shipping method.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Shipping_Method;
use WP_Error;

/**
 * Class Shipping
 *
 * @package BobGo_Shipping\app
 */
class Shipping extends WC_Shipping_Method {

	use Loggable;

	/**
	 * Static cache for origin data to avoid repeated database queries.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $origin_cache = null;

	/**
	 * Constructor for your shipping class.
	 */
	public function __construct() {
		// phpcs:ignore WordPress.WP.I18n.NoHtmlWrappedStrings
		$description              = __( '<div>When <strong>Bob Go rates at checkout</strong> is enabled, your buyers will receive the rates that you have configured on Bob Go. For this to work you need an active <a href="https://www.bobgo.co.za" target="_new">Bob Go account</a>.</div>', 'bobgo-shipping' );
		$this->id                 = 'bobgo_shipping';
		$this->title              = __( 'Bob Go', 'bobgo-shipping' );
		$this->method_title       = __( 'Bob Go rates at checkout', 'bobgo-shipping' );
		$this->method_description = $description;
		$this->enabled            = 'yes';
		$this->init();
	}

	/**
	 * Init your settings.
	 */
	public function init(): void {
		// Load the settings API.
		$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings.
		$this->init_settings(); // This is part of the settings API. Loads settings you previously init.
		// Save settings in admin if you have any defined.
		// process_admin_options() returns bool from WC_Settings_API, but action callbacks must
		// return void; wrap in a closure that discards the return value.
		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Init the fields for the admin.
	 * Note: Settings are now managed via WooCommerce → Bob Go settings page.
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'              => [
				'title'       => __( 'Enable', 'bobgo-shipping' ),
				'type'        => 'checkbox',
				'description' => __(
					'When this setting is enabled, your customers will be
				presented with shipping rates at checkout, as configured on the Bob Go platform under Rates at checkout',
					'bobgo-shipping'
				),
				'default'     => 'no',
			],
			'hide'                 => [
				'title'       => __( 'Hide WooCommerce shipping rates', 'bobgo-shipping' ),
				'type'        => 'checkbox',
				'description' => __( 'Hide other WooCommerce shipping rates if Bob Go returns rates.', 'bobgo-shipping' ),
				'default'     => 'no',
			],
			'Test_API_Call'        => [
				'title'       => __( 'Makes a test call to the Bob Go API', 'bobgo-shipping' ),
				'type'        => 'custom_display',
				'description' => __( 'Makes a test call to the Bob Go API to ensure that the connection is set up properly.', 'bobgo-shipping' ),
				'value'       => 'Test API',
			],
			'Site_Address'         => [
				'title'       => __( 'Use Site Address (URL) when requesting rates', 'bobgo-shipping' ),
				'type'        => 'checkbox',
				'description' => sprintf(
					/* translators: %1$s: Site Address URL, %2$s: WordPress Address URL */
					__( 'When this setting is enabled %1$s will be used, instead of %2$s, when making requests for shipping rates. Only enable this option if your WooCommerce store is accessed via a subfolder, e.g. /shop.', 'bobgo-shipping' ),
					'<strong>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) . wp_parse_url( home_url(), PHP_URL_PATH ) ) . '</strong>',
					'<strong>' . esc_html( wp_parse_url( site_url(), PHP_URL_HOST ) ) . '</strong>'
				),
				'default'     => 'no',
			],
			'Additional_rate_info' => [
				'title'       => __( 'Show additional rate information', 'bobgo-shipping' ),
				'type'        => 'checkbox',
				'description' => __( 'Displays the delivery timeframe and additional service level description, as configured on Bob Go, below each shipping rate.', 'bobgo-shipping' ),
				'default'     => 'no',
			],
		];
	}

	/**
	 * Override the default admin options to redirect users to the Bob Go settings page.
	 */
	public function admin_options(): void {
		$settings_url = admin_url( 'admin.php?page=bobgo-settings&tab=rates' );
		?>
		<h2><?php echo esc_html( $this->get_method_title() ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag */
				esc_html__( 'This section has been moved to the Bob Go plugin settings page. %1$sConfigure Rates at Checkout%2$s', 'bobgo-shipping' ),
				'<a href="' . esc_url( $settings_url ) . '">',
				'</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Calculate the shipping costs of the Bob Go shipping.
	 * Might return multiple couriers with different rates.
	 * Uses transient caching to reduce API calls for identical cart contents.
	 *
	 * @param array<string, mixed> $package The shopping cart details.
	 */
	public function calculate_shipping( $package = [] ): void {
		// In-request memoization keyed by package hash — stops same-process
		// duplicate calls (multi-package carts, WC re-calcs in one request).
		static $in_request_cache = [];

		$bobgo_shipping_settings = WooCommerce::get_shipping_settings();
		// Is Bob Go shipping enabled?
		if ( empty( $bobgo_shipping_settings['enabled'] ) || 'no' === $bobgo_shipping_settings['enabled'] ) {
			return;
		}

		// Skip the call entirely when no API key is configured — without it
		// every request would just round-trip a 401.
		if ( ! Api::is_connected() ) {
			return;
		}

		// A valid "Allow free shipping" coupon overrides rates-at-checkout: present
		// a single free Bob Go rate and skip the RAC API call entirely. Handled here
		// — before any transient interaction — because the coupon's free-shipping
		// flag is NOT part of the rate cache key: two carts with an identical
		// post-discount order_total_price hash to the same key whether or not a
		// free-shipping coupon is applied. Zeroing a *cached* rate would therefore
		// leak free shipping to carts without the coupon; short-circuiting before the
		// cache avoids that entirely and costs no API call.
		if ( $this->cart_has_free_shipping_coupon() ) {
			$this->add_free_shipping_rate();
			return;
		}

		// Generate cache key based on package contents.
		$api_body  = $this->get_api_formatted_body( $package );
		$cache_key = 'bobgo_rates_' . md5( wp_json_encode( $api_body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- Used for cache key, not security.

		if ( array_key_exists( $cache_key, $in_request_cache ) ) {
			$memoized = $in_request_cache[ $cache_key ];
			if ( self::NO_RATES_SENTINEL !== $memoized ) {
				$this->process_cached_rates( $memoized );
			}
			return;
		}

		// Cart-page (coarse address) requests carry no street/postcode/suburb, so
		// their rates are approximate and display-only — cache them aggressively
		// (default 2 hours) to absorb the high-volume flood. Checkout requests
		// arrive with a complete address and keep the short, fresh TTL so the
		// price actually charged is never stale.
		if ( $this->is_coarse_delivery_address( $api_body['delivery_address'] ?? [] ) ) {
			$cache_duration = apply_filters( 'bobgo_shipping_cart_cache_duration', 2 * HOUR_IN_SECONDS );
		} else {
			$cache_duration = apply_filters( 'bobgo_shipping_cache_duration', 15 * MINUTE_IN_SECONDS );
		}

		$cached_response = get_transient( $cache_key );

		if ( false !== $cached_response ) {
			$in_request_cache[ $cache_key ] = $cached_response;
			if ( self::NO_RATES_SENTINEL !== $cached_response ) {
				$this->process_cached_rates( $cached_response );
			}
			return;
		}

		$url = SettingsPage::get_base_url() . '/v2/rates-at-checkout';

		// Allow timeout to be configured via filter (default 30 seconds, min 5, max 60).
		$timeout = apply_filters( 'bobgo_shipping_api_timeout', 30 );
		$timeout = min( max( (int) $timeout, 5 ), 60 );

		$post_options = [
			'headers'     => Api::default_headers(),
			'body'        => wp_json_encode( $api_body ),
			'redirection' => 0,
			'timeout'     => $timeout,
			'sslverify'   => apply_filters( 'bobgo_shipping_ssl_verify', true ),
		];

		$response = wp_remote_post( $url, $post_options );

		// Check for network/connection errors (Issue #3).
		if ( is_wp_error( $response ) ) {
			$this->log_error( 'API request failed: ' . $response->get_error_message() );
			$this->cache_no_rates( $cache_key, $in_request_cache );
			return;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			// Cache the successful response.
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				set_transient( $cache_key, $body, $cache_duration );
				$in_request_cache[ $cache_key ] = $body;
			}
			$this->process_rates_results( $response );
			Api::record_health( true, 200 );
		} else {
			$this->log_error( "API returned status code: {$response_code}" );
			$this->cache_no_rates( $cache_key, $in_request_cache );
			// Rates-at-checkout is the highest-frequency authenticated call but
			// bypasses Api::request(); feed a 401 into connection health so a key
			// revoked after save is reflected on the dashboard. See Phase 3 #1.
			if ( 401 === $response_code ) {
				Api::record_health( false, 401 );
			}
		}
	}

	/**
	 * Determine whether the current cart carries a coupon that grants free shipping.
	 *
	 * Reads the applied coupons off the live cart and defers the actual decision to
	 * coupons_grant_free_shipping(). Returns false whenever the cart is unavailable
	 * (e.g. admin-side or REST contexts with no cart), so those requests fall
	 * through to the normal rates-at-checkout path unchanged.
	 *
	 * @return bool
	 */
	protected function cart_has_free_shipping_coupon(): bool {
		$cart = ( function_exists( 'WC' ) && WC() ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return false;
		}

		return $this->coupons_grant_free_shipping( $cart->get_coupons() );
	}

	/**
	 * Decide whether any of the given coupons grants free shipping.
	 *
	 * Mirrors the check WooCommerce's own Free Shipping method makes
	 * (WC_Shipping_Free_Shipping::is_available): a coupon counts only when its
	 * "Allow free shipping" flag is set and it is currently valid for the cart, so
	 * an applied-but-no-longer-valid coupon (e.g. its minimum spend is no longer
	 * met) does not trigger free shipping. get_free_shipping() is the cheap gate,
	 * checked before the fuller is_valid() validation runs. Non-coupon entries are
	 * skipped defensively.
	 *
	 * @param array<int|string, mixed> $coupons Applied coupon objects, as returned by WC_Cart::get_coupons().
	 * @return bool
	 */
	protected function coupons_grant_free_shipping( array $coupons ): bool {
		foreach ( $coupons as $coupon ) {
			if ( $coupon instanceof \WC_Coupon && $coupon->get_free_shipping() && $coupon->is_valid() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a single zero-cost Bob Go shipping rate.
	 *
	 * Used when a valid free-shipping coupon is active: the customer sees one
	 * "Free shipping" option and no rates-at-checkout call is made. The rate
	 * deliberately carries no service code, so the order syncs to Bob Go without a
	 * pre-selected service and the merchant chooses the courier there (OrderSync
	 * omits an empty service code from its payload). Reusing the bobgo_shipping:1 id
	 * means the existing hide filter still recognises it as a Bob Go rate and, when
	 * "hide other rates" is on, leaves free shipping as the only option.
	 */
	protected function add_free_shipping_rate(): void {
		$this->add_rate(
			[
				'id'    => "{$this->id}:1",
				'label' => apply_filters( 'bobgo_shipping_free_shipping_label', __( 'Free shipping', 'bobgo-shipping' ) ),
				'cost'  => 0,
			]
		);
	}

	/**
	 * Sentinel value used to negative-cache a "no rates available" outcome.
	 */
	const NO_RATES_SENTINEL = '__bobgo_no_rates__';

	/**
	 * Negative-cache TTL in seconds. Short enough that recovery is fast but
	 * long enough to absorb concurrent retries from the same checkout.
	 */
	const NO_RATES_TTL = 30;

	/**
	 * Negative-cache a failed rates lookup in both the transient and the
	 * in-request memo so concurrent calls within the window short-circuit.
	 *
	 * @param string                $cache_key        The transient key for this package hash.
	 * @param array<string, string> $in_request_cache Reference to the static in-request memo.
	 */
	private function cache_no_rates( string $cache_key, array &$in_request_cache ): void {
		set_transient( $cache_key, self::NO_RATES_SENTINEL, self::NO_RATES_TTL );
		$in_request_cache[ $cache_key ] = self::NO_RATES_SENTINEL;
	}

	/**
	 * Process cached rates from transient.
	 *
	 * @param string $cached_body The cached JSON response body.
	 */
	protected function process_cached_rates( string $cached_body ): void {
		// Create a mock response array for process_rates_results.
		$mock_response = [
			'body'    => $cached_body,
			'headers' => [ 'content-type' => 'application/json' ],
		];
		$this->process_rates_results( $mock_response );
	}

	/**
	 * Process results received from API.
	 *
	 * @param array<string, mixed> $response The API response.
	 */
	protected function process_rates_results( array $response ): void {
		// Validate content-type is JSON (Issue #20).
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! empty( $content_type ) && false === strpos( $content_type, 'application/json' ) ) {
			$this->log_error( 'API returned non-JSON content type: ' . $content_type );
			return;
		}

		$body = wp_remote_retrieve_body( $response );

		// Check for empty response body.
		if ( empty( $body ) ) {
			$this->log_error( 'API returned empty response body' );
			return;
		}

		// Parse JSON with error handling (Issue #1).
		$data = json_decode( $body, false );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->log_error( 'JSON decode error: ' . json_last_error_msg() );
			return;
		}

		// Validate response structure.
		if ( ! is_object( $data ) || empty( $data->rates ) || ! is_array( $data->rates ) ) {
			return; // No rates in response - this is normal, not an error.
		}

		// Limit shipping methods to prevent UI overload.
		$max_rates = apply_filters( 'bobgo_shipping_max_rates', 20 );
		$i         = 1;

		foreach ( $data->rates as $rate_data ) {
			// Enforce rate limit.
			if ( $i > $max_rates ) {
				$this->log_info( "Rate limit of {$max_rates} reached, skipping remaining rates" );
				break;
			}

			// Validate required fields exist and have correct types (Issue #9).
			if ( ! $this->validate_rate_data( $rate_data ) ) {
				$this->log_error( 'Skipping invalid rate data - missing or invalid required fields' );
				continue;
			}

			$rate = [
				'id'        => "{$this->id}:{$i}",
				'label'     => sanitize_text_field( (string) $rate_data->service_name ),
				'cost'      => (string) ( (float) $rate_data->total_price ),
				'meta_data' => [
					'bobgo_service_code' => sanitize_text_field( (string) $rate_data->service_code ),
					'method_description' => wp_kses_post( $rate_data->description ?? '' ),
					'min_delivery_date'  => $this->validate_date( $rate_data->min_delivery_date ?? '' ),
					'max_delivery_date'  => $this->validate_date( $rate_data->max_delivery_date ?? '' ),
				],
			];

			$this->add_rate( $rate );
			++$i;
		}
	}

	/**
	 * Validate rate data has required fields with correct types.
	 *
	 * @param mixed $rate_data The rate data to validate.
	 * @return bool True if valid, false otherwise.
	 */
	protected function validate_rate_data( mixed $rate_data ): bool {
		if ( ! is_object( $rate_data ) ) {
			return false;
		}

		// Required fields.
		if ( ! isset( $rate_data->service_name, $rate_data->total_price, $rate_data->service_code ) ) {
			return false;
		}

		// Type validation.
		if ( ! is_string( $rate_data->service_name ) || empty( $rate_data->service_name ) ) {
			return false;
		}

		if ( ! is_numeric( $rate_data->total_price ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate and sanitize a date string.
	 *
	 * @param mixed $date The date to validate.
	 * @return string Validated date string or empty string if invalid.
	 */
	protected function validate_date( mixed $date ): string {
		if ( ! is_string( $date ) || empty( $date ) ) {
			return '';
		}

		// Try to parse the date.
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return '';
		}

		// Return in consistent format.
		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Log an error message using WooCommerce logger.
	 *
	 * @param string $message The error message to log.
	 */
	protected function log_error( string $message ): void {
		self::log( $message, 'error' );
	}

	/**
	 * Log an info message using WooCommerce logger.
	 *
	 * @param string $message The info message to log.
	 */
	protected function log_info( string $message ): void {
		self::log( $message, 'info' );
	}

	/**
	 * Format package data into a valid format for API call.
	 *
	 * @param array $package The shopping cart details.
	 *
	 * @return array[]
	 */
	protected function get_api_formatted_body( array $package ): array {
		$items = [];
		if ( ! empty( $package['contents'] ) ) {
			$items = $this->format_cart_items( $package['contents'] );
		}

		$declared_value = 0.0;
		foreach ( $items as $item ) {
			$declared_value += ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 1 );
		}

		return [
			'collection_address' => $this->format_collection_address(),
			'delivery_address'   => $this->format_delivery_address( $package ),
			'items'              => $items,
			'declared_value'     => $declared_value,
			'order_total_price'  => $this->calculate_order_total_price( $package ),
			'handling_time'      => 0,
		];
	}

	/**
	 * Calculate the tax-inclusive, post-discount total the customer pays for the
	 * shippable goods in this package — sent to Bob Go as `order_total_price`.
	 *
	 * WooCommerce applies coupon discounts to line items (in
	 * calculate_item_totals()) before it calculates shipping, so by the time this
	 * runs each cart item in $package['contents'] already carries its discounted
	 * `line_total` together with the matching `line_tax`. Summing the two yields
	 * the amount the customer actually pays for the goods: after discounts and
	 * including tax (line_total is always net, line_tax the tax on it, in both
	 * tax-inclusive and tax-exclusive store configurations).
	 *
	 * The package only ever contains items that need shipping, so non-shippable
	 * (virtual/downloadable) items are naturally excluded and multi-package carts
	 * (e.g. Dokan) are totalled per package.
	 *
	 * Contrast with declared_value, which is the pre-discount goods value.
	 *
	 * @param array<string, mixed> $package The shopping cart package.
	 * @return float
	 */
	protected function calculate_order_total_price( array $package ): float {
		if ( empty( $package['contents'] ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $package['contents'] as $item ) {
			$total += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
		}

		return round( $total, 2 );
	}

	/**
	 * Determine whether a formatted delivery address is "coarse" — i.e. it has
	 * no street address, postcode or suburb, leaving only country/province.
	 *
	 * This is the cart-page signature: WooCommerce has nothing but the customer's
	 * geolocated/default region, so the resulting rate is approximate and
	 * display-only. As soon as any of street/postcode/suburb is present the
	 * customer is entering a real checkout address and the rate becomes
	 * order-specific, so it must not be cached aggressively. Detecting this from
	 * the payload (rather than is_cart()/is_checkout()) keeps it reliable for both
	 * classic and block (Store API) checkouts, where those conditional tags are
	 * unreliable.
	 *
	 * @param array<string, mixed> $delivery_address Formatted delivery address (see format_delivery_address()).
	 * @return bool True when only region-level data is present.
	 */
	protected function is_coarse_delivery_address( array $delivery_address ): bool {
		foreach ( [ 'street_address', 'code', 'local_area' ] as $field ) {
			if ( '' !== trim( (string) ( $delivery_address[ $field ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Format the delivery address for the API.
	 *
	 * @param array $package Raw cart data.
	 * @return array
	 */
	protected function format_delivery_address( array $package ): array {
		// When the Dokan plugin is active the address1 field saves in the address field and the address1 field is empty.
		$address = $package['destination']['address_1'] ?? '';
		if ( empty( $address ) && ! empty( $package['destination']['address'] ) ) {
			$address = $package['destination']['address'];
		}

		// Resolve suburb / local area from checkout fields.
		$local_area         = '';
		$is_checkout_blocks = isset( $package['destination']['is_checkout_blocks'] ) && 'true' === $package['destination']['is_checkout_blocks'];
		if ( $is_checkout_blocks && ! empty( $package['destination']['cb_shipping_suburb'] ) ) {
			$local_area = $package['destination']['cb_shipping_suburb'];
		} elseif ( ! empty( $package['destination']['shipping_suburb'] ) ) {
			$local_area = $package['destination']['shipping_suburb'];
		}

		return [
			'company'        => '',
			'street_address' => $address,
			'local_area'     => $local_area,
			'city'           => $package['destination']['city'] ?? '',
			'zone'           => $package['destination']['state'] ?? '',
			'country'        => $package['destination']['country'] ?? '',
			'code'           => $package['destination']['postcode'] ?? '',
		];
	}

	/**
	 * Format the collection (origin) address for the API.
	 * Uses static cache to avoid repeated database queries within the same request.
	 *
	 * @return array
	 */
	protected function format_collection_address(): array {
		if ( null !== self::$origin_cache ) {
			return self::$origin_cache;
		}

		$store_address   = get_option( 'woocommerce_store_address', '' );
		$store_address_2 = get_option( 'woocommerce_store_address_2', '' );
		$store_city      = get_option( 'woocommerce_store_city', '' );
		$store_postcode  = get_option( 'woocommerce_store_postcode', '' );

		$store_raw_country = get_option( 'woocommerce_default_country', '' );
		$split_country     = explode( ':', $store_raw_country );
		$store_country     = $split_country[0] ?? '';
		$store_zone        = $split_country[1] ?? '';

		self::$origin_cache = [
			'company'        => '',
			'street_address' => $store_address,
			'local_area'     => $store_address_2,
			'city'           => $store_city,
			'zone'           => $store_zone,
			'country'        => $store_country,
			'code'           => $store_postcode,
		];

		return self::$origin_cache;
	}

	/**
	 * Format the items in the cart for the API.
	 *
	 * Besides the physical attributes used to price the shipment, each item
	 * carries the identifiers Bob Go matches its rate rules against: `sku`,
	 * `shipping_class` (slug plus id, as the merchant configured it in
	 * WooCommerce) and `vendor` (the marketplace shop the item ships from, empty
	 * unless multi-vendor support is on).
	 *
	 * @param array $cart_items Raw cart items.
	 * @return array
	 */
	protected function format_cart_items( array $cart_items ): array {
		$formatted_cart_items = [];
		foreach ( $cart_items as $item ) {
			/**
			 * Cart item product instance.
			 *
			 * @var \WC_Product $wc_product
			 */
			$wc_product = $item['data'];

			if ( ! $wc_product->needs_shipping() ) {
				continue;
			}

			$formatted_cart_items[] = [
				'description'       => $wc_product->get_name(),
				'sku'               => (string) $wc_product->get_sku(),
				'price'             => (float) $wc_product->get_price(),
				'quantity'          => (int) $item['quantity'],
				'length_cm'         => (float) wc_get_dimension( (float) $wc_product->get_length(), 'cm' ),
				'width_cm'          => (float) wc_get_dimension( (float) $wc_product->get_width(), 'cm' ),
				'height_cm'         => (float) wc_get_dimension( (float) $wc_product->get_height(), 'cm' ),
				'weight_kg'         => (float) wc_get_weight( (float) $wc_product->get_weight(), 'kg' ),
				'shipping_class'    => (string) $wc_product->get_shipping_class(),
				'shipping_class_id' => (int) $wc_product->get_shipping_class_id(),
				'vendor'            => OrderSync::get_item_vendor( (int) $wc_product->get_id() ),
			];
		}

		return $formatted_cart_items;
	}

	/**
	 * Run a test call to Bob Go Rates API to see if the API can be reached and give a
	 * useful error if it can't.
	 */
	public function test_shipping(): void {
		$bobgo_shipping_settings = WooCommerce::get_shipping_settings();
		// Is Bob Go shipping enabled? Say so rather than returning silently: this
		// runs behind the Test Connection button, and a button that prints nothing
		// reads as broken — on precisely the page that tells merchants to press it.
		if ( empty( $bobgo_shipping_settings['enabled'] ) || 'no' === $bobgo_shipping_settings['enabled'] ) {
			$this->print_test_skipped();
			return;
		}

		// No key, no request — matching calculate_shipping(). A keyless call
		// still round-trips a definitive-looking status, and recording that
		// through the write-through below would pass verdicts on a key that
		// does not exist: a keyless 401 reads as "key rejected" and survives a
		// later save whose validation was inconclusive, and a keyless 2xx would
		// mark the store verified — the false green this page exists to remove.
		if ( ! Api::is_connected() ) {
			$this->print_test_not_connected();
			return;
		}

		// Use environment-aware API URL.
		$env = SettingsPage::get_current_env();
		$url = SettingsPage::get_base_url() . '/v2/rates-at-checkout';

		// Use same configurable timeout as main API calls.
		$timeout = apply_filters( 'bobgo_shipping_api_timeout', 30 );
		$timeout = min( max( (int) $timeout, 5 ), 60 );

		$test_package = $this->format_test_package();
		$post_options = [
			'headers'     => Api::default_headers(),
			'body'        => wp_json_encode( $test_package ),
			'redirection' => 0,
			'timeout'     => $timeout,
		];

		$response = wp_remote_post( $url, $post_options );

		// Build debug info for non-production environments.
		$debug = null;
		if ( 'prod' !== $env ) {
			$debug = [
				'env'        => $env,
				'url'        => $url,
				'identifier' => Admin::get_api_domain(),
			];

			if ( is_wp_error( $response ) ) {
				$debug['error'] = $response->get_error_message();
			} else {
				$debug['status']   = (int) $response['response']['code'];
				$debug['response'] = json_decode( wp_remote_retrieve_body( $response ), true );
			}
		}

		if ( ! is_wp_error( $response ) ) {
			$status = (int) wp_remote_retrieve_response_code( $response );

			// Feed definitive auth outcomes into connection health, mirroring
			// calculate_shipping()'s write-through. This button is how the Overview
			// tells a merchant to turn "Not verified" into an answer, so a test
			// that proved the key one way or the other must record it. 401 =>
			// invalid; 2xx => valid; anything else is inconclusive and leaves the
			// last known state as-is.
			if ( 401 === $status ) {
				Api::record_health( false, 401 );
			} elseif ( $status >= 200 && $status < 300 ) {
				Api::record_health( true, $status );
			}

			$this->print_test_result( $status, $debug );
		} else {
			$this->print_test_error( $response, $debug );
		}
	}

	/**
	 * The markup a Test Connection notice may contain.
	 *
	 * These notices name the tab where the fix happens, and a tab named in prose
	 * that the merchant then has to go and find is a worse instruction than a
	 * link — so anchors are permitted, with only the attributes used here.
	 *
	 * @return array<string, array<string, array<int, string>>>
	 */
	protected function notice_allowed_html(): array {
		return [
			'div'  => [
				'class' => [],
				'role'  => [],
			],
			'span' => [ 'class' => [] ],
			'p'    => [ 'class' => [] ],
			'a'    => [
				'href'   => [],
				'target' => [],
				'rel'    => [],
			],
		];
	}

	/**
	 * Print the notice shown when the test cannot run because no API key is
	 * stored. There is no connection to test yet, and saying so beats sending
	 * an unauthenticated request whose rejection would read as a key fault.
	 */
	protected function print_test_not_connected(): void {
		$connection_url = esc_url( SettingsPage::tab_url( SettingsPage::TAB_CONNECTION ) );

		echo wp_kses(
			'<div class="bobgo-notice bobgo-notice--error" role="alert">'
			. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
			. '<p class="bobgo-notice__message">No Bob Go API key is stored, so there is no connection to test. Add your API key on the <a href="' . $connection_url . '">Connection tab</a> first.</p>'
			. '</div>',
			$this->notice_allowed_html()
		);
	}

	/**
	 * Print the notice shown when the test cannot run because the shipping
	 * method is disabled. Points at the other Test Connection, which verifies
	 * the API key without needing rates to be on.
	 */
	protected function print_test_skipped(): void {
		$rates_url      = esc_url( SettingsPage::tab_url( SettingsPage::TAB_RATES ) );
		$connection_url = esc_url( SettingsPage::tab_url( SettingsPage::TAB_CONNECTION ) );

		echo wp_kses(
			'<div class="bobgo-notice bobgo-notice--error" role="alert">'
			. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
			. '<p class="bobgo-notice__message">Rates at Checkout is disabled on this store, so there is no rates connection to test. Enable it on the <a href="' . $rates_url . '">Rates at Checkout tab</a> and test again, or use Test Connection on the <a href="' . $connection_url . '">Connection tab</a> to verify your API key.</p>'
			. '</div>',
			$this->notice_allowed_html()
		);
	}

	/**
	 * Print the test result based on response code.
	 *
	 * @param int        $response The HTTP response code.
	 * @param array|null $debug    Optional debug info for non-production environments.
	 */
	protected function print_test_result( int $response, ?array $debug = null ): void {
		$allowed_html = $this->notice_allowed_html();

		$channels_url   = esc_url( SettingsPage::get_dashboard_url( 'sales-channels' ) );
		$rates_url      = esc_url( SettingsPage::get_dashboard_url( 'rates-at-checkout' ) );
		$connection_url = esc_url( SettingsPage::tab_url( SettingsPage::TAB_CONNECTION ) );

		switch ( $response ) {
			case 200:
				echo wp_kses(
					'<div class="bobgo-notice bobgo-notice--success" role="alert">'
					. '<span class="bobgo-notice__icon dashicons dashicons-yes-alt"></span>'
					. '<p class="bobgo-notice__message">Rates at checkout connected</p>'
					. '</div>',
					$allowed_html
				);
				break;

			case 404:
				echo wp_kses(
					'<div class="bobgo-notice bobgo-notice--error" role="alert">'
					. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
					. '<p class="bobgo-notice__message">Your WooCommerce channel is not installed on Bob Go. Please visit <a href="' . $channels_url . '" target="_blank" rel="noopener noreferrer">Bob Go</a> and install your WooCommerce channel to be able to receive rates.</p>'
					. '</div>',
					$allowed_html
				);
				break;

			// A 401 from the rates endpoint is not proof the API key is wrong, and
			// saying it was sent merchants to regenerate keys that turned out to be
			// fine. Bob Go answers with the same 401 when rates at checkout is
			// switched off for the channel, and when the channel identifier this
			// store sends matches no sales channel — both of which leave the key
			// perfectly valid. This test cannot tell the three apart, so it names
			// them in the order that costs the merchant least to check.
			case 401:
				echo wp_kses(
					'<div class="bobgo-notice bobgo-notice--error" role="alert">'
					. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
					. '<p class="bobgo-notice__message">Bob Go refused this request (HTTP 401). Three things cause that and this test cannot tell them apart, so check them in this order: rates at checkout is switched off for this channel on <a href="' . $rates_url . '" target="_blank" rel="noopener noreferrer">Bob Go</a>; the Channel Identifier this store sends matches no <a href="' . $channels_url . '" target="_blank" rel="noopener noreferrer">Bob Go sales channel</a>; or the API key is no longer valid, which you can replace on the <a href="' . $connection_url . '">Connection tab</a>.</p>'
					. '</div>',
					$allowed_html
				);
				break;

			default:
				echo wp_kses(
					'<div class="bobgo-notice bobgo-notice--error" role="alert">'
					. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
					. '<p class="bobgo-notice__message">Failed to connect to rates at checkout. Please check your internet connection and make sure Rates at checkout is enabled for your channel on Bob Go. Visit <a href="' . $rates_url . '" target="_blank" rel="noopener noreferrer">Bob Go</a> and make sure your WooCommerce channel is enabled to receive rates.</p>'
					. '</div>',
					$allowed_html
				);
		}

		$this->print_debug_details( $debug );
	}

	/**
	 * Print the test error message.
	 *
	 * @param WP_Error   $response The WP_Error response.
	 * @param array|null $debug    Optional debug info for non-production environments.
	 */
	protected function print_test_error( WP_Error $response, ?array $debug = null ): void {
		$allowed_html = [
			'div'  => [
				'class' => [],
				'role'  => [],
			],
			'span' => [ 'class' => [] ],
			'p'    => [ 'class' => [] ],
		];

		echo wp_kses(
			'<div class="bobgo-notice bobgo-notice--error" role="alert">'
			. '<span class="bobgo-notice__icon dashicons dashicons-warning"></span>'
			. '<p class="bobgo-notice__message">Failed to connect to rates at checkout: ' . esc_html( $response->get_error_message() ) . '</p>'
			. '</div>',
			$allowed_html
		);

		$this->print_debug_details( $debug );
	}

	/**
	 * Print debug details for non-production environments.
	 *
	 * @param array|null $debug Debug info array, or null if production.
	 */
	protected function print_debug_details( ?array $debug ): void {
		if ( null === $debug ) {
			return;
		}

		$env_label  = strtoupper( $debug['env'] ?? 'unknown' );
		$url        = esc_html( $debug['url'] ?? '' );
		$identifier = esc_html( $debug['identifier'] ?? '' );
		$status     = $debug['status'] ?? 'N/A';

		// Build formatted response JSON.
		$response_json = '';
		if ( isset( $debug['response'] ) ) {
			$response_json = wp_json_encode( $debug['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		} elseif ( isset( $debug['error'] ) ) {
			$response_json = esc_html( $debug['error'] );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML with all dynamic values pre-escaped via esc_html() above
		echo '<div style="margin-top: 12px; padding: 12px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; font-family: monospace; font-size: 12px;">';
		echo '<strong style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 13px;">Debug Details (' . esc_html( $env_label ) . ')</strong>';
		echo '<table style="margin-top: 8px; border-collapse: collapse; width: 100%;">';
		echo '<tr><td style="padding: 4px 8px 4px 0; vertical-align: top; white-space: nowrap; color: #646970;">URL</td><td style="padding: 4px 0; word-break: break-all;">' . esc_html( $url ) . '</td></tr>';
		echo '<tr><td style="padding: 4px 8px 4px 0; vertical-align: top; white-space: nowrap; color: #646970;">Identifier</td><td style="padding: 4px 0;">' . esc_html( $identifier ) . '</td></tr>';
		echo '<tr><td style="padding: 4px 8px 4px 0; vertical-align: top; white-space: nowrap; color: #646970;">HTTP Status</td><td style="padding: 4px 0;">' . esc_html( $status ) . '</td></tr>';
		echo '</table>';

		if ( ! empty( $response_json ) ) {
			echo '<div style="margin-top: 8px;">';
			echo '<strong style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 12px; color: #646970;">Response Body</strong>';
			echo '<pre style="margin: 4px 0 0; padding: 8px; background: #fff; border: 1px solid #dcdcde; border-radius: 3px; overflow-x: auto; max-height: 300px; white-space: pre-wrap; word-break: break-word;">' . esc_html( $response_json ) . '</pre>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Format test package data into a valid format for API call.
	 *
	 * @return array<string, mixed>
	 */
	protected function format_test_package(): array {
		$collection = $this->format_collection_address();

		return [
			'collection_address' => $collection,
			'delivery_address'   => $collection,
			'items'              => [
				[
					'description'       => 'Test Item',
					'sku'               => '',
					'price'             => 200,
					'quantity'          => 1,
					'length_cm'         => 17,
					'width_cm'          => 8,
					'height_cm'         => 5,
					'weight_kg'         => 1,
					'shipping_class'    => '',
					'shipping_class_id' => 0,
					'vendor'            => '',
				],
			],
			'declared_value'     => 200,
			'order_total_price'  => 200,
			'handling_time'      => 0,
		];
	}
}
