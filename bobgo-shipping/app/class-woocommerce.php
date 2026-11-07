<?php
/**
 * WooCommerce integration hooks.
 *
 * @package BobGo_Shipping
 */

namespace BobGo_Shipping\app;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use WC_Order;
use WC_Customer;

/**
 * WooCommerce integration: order meta, suburb fields, shipping descriptions.
 */
class WooCommerce {

	use Loggable;

	/**
	 * Static cache for bobgo options to avoid repeated database queries.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $options_cache = null;

	/**
	 * Static cache for shipping settings to avoid repeated database queries.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $shipping_settings_cache = null;

	/**
	 * Session key for classic checkout suburb.
	 */
	private const SESSION_KEY_SUBURB = 'shipping_suburb';

	/**
	 * Session key for checkout blocks suburb.
	 */
	private const SESSION_KEY_BLOCKS_SUBURB = 'cb_shipping_suburb';

	/**
	 * Session key for checkout blocks detection.
	 */
	private const SESSION_KEY_IS_BLOCKS = 'is_checkout_blocks';

	/**
	 * Field ID for checkout blocks suburb field.
	 */
	private const BLOCKS_FIELD_ID = 'namespace/cb_shipping_suburb';

	/**
	 * Maximum length for suburb field input.
	 */
	private const SUBURB_MAX_LENGTH = 100;

	/**
	 * Static flag to prevent re-entrancy in save_order_meta.
	 * Used instead of remove_action/add_action pattern for thread safety.
	 *
	 * @var bool
	 */
	private static bool $is_saving_order_meta = false;

	/**
	 * Save order meta.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    Posted order data, or the existing order.
	 */
	public static function save_order_meta( int $order_id, WC_Order|array|null $order = null ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Prevent re-entrancy using static flag (thread-safe alternative to remove_action/add_action).
		if ( self::$is_saving_order_meta ) {
			return;
		}

		// Get order if not sent along to hook (Happens when hook fires too fast).
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if it's a subscription or a renewal.
		if ( self::is_subscription_order( $order ) ) {
			return;
		}

		// Set flag to prevent re-entrancy.
		self::$is_saving_order_meta = true;

		try {
			// Total grams.
			self::include_total_grams_in_order( $order_id, $order );

			// Service code.
			self::include_service_code_in_order( $order_id, $order );

			// Items meta.
			self::include_items_meta( $order_id, $order );

			// Plugin version.
			self::save_plugin_version( $order_id, $order );

			// Save the order meta.
			$order->save();
		} finally {
			// Always reset flag, even if an exception occurs.
			self::$is_saving_order_meta = false;
		}
	}

	/**
	 * Save order meta during checkout.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    Posted order data, or the existing order.
	 */
	public static function save_order_meta_checkout( int $order_id, WC_Order|array|null $order = null ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Get order if not sent along to hook (Happens when hook fires too fast).
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if it's a subscription or a renewal.
		if ( self::is_subscription_order( $order ) ) {
			return;
		}

		// Total grams.
		self::include_total_grams_in_order( $order_id, $order );

		// Save meta.
		$order->save();
	}

	/**
	 * Save the total weight of the items in the order as part of order meta so that it is included in order webhooks.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    Posted order data, or the existing order.
	 */
	public static function include_total_grams_in_order( int $order_id, WC_Order|array|null $order = null ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Ensure we have a valid order object upfront.
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		$weight = 0;
		$cart   = WC()->cart;

		if ( $cart ) {
			// Grab total weight from cart.
			$weight = $cart->get_cart_contents_weight();
		} else {
			// Order was likely created/edited on admin or via REST API. Determine total weight from product data.
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				if ( ! empty( trim( $product->get_weight() ) ) ) {
					$weight += (float) $product->get_weight() * $item->get_quantity();
				}
			}
		}

		// Save the total weight (in grams) in order meta.
		$weight = wc_get_weight( $weight, 'g' );
		$order->update_meta_data( 'bobgo_total_grams', (string) $weight );
	}

	/**
	 * Save the weight and dimensions of the items in the order as part of order meta so that it is included in order webhooks.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    Posted order data, or the existing order.
	 */
	public static function include_items_meta( int $order_id, WC_Order|array|null $order = null ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Get order if not sent along to hook (Happens when hook fires too fast).
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		$formatted_order_items = [];

		// Loop through the items.
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			// Get the product.
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$formatted_item = [];

			// Save the order item ID.
			$formatted_item['id'] = $item->get_id();

			// Save the product/variant dimensions.
			if ( ! empty( trim( $product->get_height() ) ) ) {
				$formatted_item['height_cm'] = (int) wc_get_dimension( (float) $product->get_height(), 'cm' );
			}
			if ( ! empty( trim( $product->get_length() ) ) ) {
				$formatted_item['length_cm'] = (int) wc_get_dimension( (float) $product->get_length(), 'cm' );
			}
			if ( ! empty( trim( $product->get_width() ) ) ) {
				$formatted_item['width_cm'] = (int) wc_get_dimension( (float) $product->get_width(), 'cm' );
			}

			// Save the product/variant weight.
			if ( ! empty( trim( $product->get_weight() ) ) ) {
				$formatted_item['grams'] = (int) wc_get_weight( (float) $product->get_weight(), 'g' );
			}

			// Save the product/variant shipping class info.
			$formatted_item['shipping_class']    = $product->get_shipping_class();
			$formatted_item['shipping_class_id'] = $product->get_shipping_class_id();

			// Save the item to the array.
			$formatted_order_items[] = $formatted_item;

		}

		// Update the meta for the items (HPOS compatible).
		if ( ! empty( $formatted_order_items ) ) {
			$order->update_meta_data( 'bobgo_items_meta', $formatted_order_items );
		}
	}

	/**
	 * Saves the service_code returned from shipment rates in the order as part of order meta so that it is included in order webhooks.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    Posted order data, or the existing order.
	 */
	public static function include_service_code_in_order( int $order_id, WC_Order|array|null $order = null ): void {
		// Get order if not sent along to hook (Happens when hook fires too fast).
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		// Loop inside shipping methods of order for service_code (HPOS compatible).
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$service_code = $shipping_item->get_meta( 'bobgo_service_code' ) ?? '';
			if ( ! empty( $service_code ) ) {
				$order->update_meta_data( 'bobgo_service_code', $service_code );
			}
		}
	}

	/**
	 * Gets the number of working days between the two dates.
	 * Uses O(1) mathematical formula instead of day-by-day iteration.
	 *
	 * @param string $start_date Start date string.
	 * @param string $end_date   End date string.
	 */
	public static function get_working_days( string $start_date, string $end_date ): int {
		static $cache = [];
		$cache_key    = $start_date . '_' . $end_date;

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$begin = strtotime( $start_date );
		$end   = strtotime( $end_date );

		if ( false === $begin || false === $end || $begin > $end ) {
			return 0;
		}

		// Calculate total days in the range (inclusive).
		$total_days = (int) floor( ( $end - $begin ) / 86400 ) + 1;

		// Calculate complete weeks and remaining days.
		$complete_weeks = (int) floor( $total_days / 7 );
		$remaining_days = $total_days % 7;

		// Weekend days from complete weeks (2 per week).
		$weekend_days = $complete_weeks * 2;

		// Check remaining days for weekend overlap.
		// date('N') returns 1 (Mon) to 7 (Sun).
		$start_day_of_week = (int) gmdate( 'N', $begin );
		for ( $i = 0; $i < $remaining_days; $i++ ) {
			$day = ( ( $start_day_of_week + $i - 1 ) % 7 ) + 1;
			if ( $day > 5 ) {
				++$weekend_days;
			}
		}

		$result              = $total_days - $weekend_days;
		$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Display shipping method description and estimated delivery dates for block checkout.
	 *
	 * @return array<string, string>
	 */
	public static function get_shipping_descriptions(): array {
		try {
			$descriptions            = [];
			$bobgo_shipping_settings = self::get_shipping_settings();

			if ( ! empty( $bobgo_shipping_settings['Additional_rate_info'] ) && 'yes' === $bobgo_shipping_settings['Additional_rate_info'] ) {
				// Ensure WC and session are available (Issue #8 - null safety).
				if ( ! function_exists( 'WC' ) || ! WC()->session ) {
					return $descriptions;
				}

				// Get shipping package information from the session.
				$shipping_package = WC()->session->get( 'shipping_for_package_0' );

				// Check if the shipping package and rates exist before accessing.
				if ( isset( $shipping_package['rates'] ) && is_array( $shipping_package['rates'] ) ) {
					$available_shipping_methods = $shipping_package['rates'];

					foreach ( $available_shipping_methods as $rate_id => $rate ) {
						$meta_data = $rate->get_meta_data();

						$delivery_time_frame = self::calculate_delivery_timeframe( $meta_data );
						$description         = ! empty( $meta_data['method_description'] ) ? $meta_data['method_description'] : '';

						// Construct the description output.
						$descriptions[ $rate_id ] = self::format_description_output( $delivery_time_frame, $description );
					}
				}
			}

			return $descriptions;

		} catch ( Exception $e ) {
			self::log( 'Failed to set shipping descriptions: ' . $e->getMessage(), 'error' );
			return [];
		}
	}

	/**
	 * Display shipping method description and estimated delivery dates for classic checkout.
	 *
	 * @param mixed $method The shipping method.
	 */
	public static function shipping_methods_description( $method ): void {
		// WooCommerce fires woocommerce_after_shipping_rate once per rendered rate,
		// including rates contributed by other shipping plugins. Only annotate Bob
		// Go's own rate so the description block is never emitted under a foreign
		// rate (the rate's method_id is always 'bobgo_shipping', even via the
		// legacy uafrica_shipping alias, which instantiates the same method).
		if ( ! is_a( $method, 'WC_Shipping_Rate' ) || 'bobgo_shipping' !== $method->get_method_id() ) {
			return;
		}

		$bobgo_shipping_settings = self::get_shipping_settings();

		if ( ! empty( $bobgo_shipping_settings['Additional_rate_info'] ) && 'yes' === $bobgo_shipping_settings['Additional_rate_info'] ) {
			$meta_data = $method->get_meta_data();

			$delivery_time_frame = self::calculate_delivery_timeframe( $meta_data );
			$description         = ! empty( $meta_data['method_description'] ) ? $meta_data['method_description'] : '';

			echo wp_kses_post( self::format_description_output( $delivery_time_frame, $description ) );
		}
	}

	/**
	 * Helper function to calculate the delivery time frame based on min and max delivery dates.
	 *
	 * @param array<string, mixed> $meta_data The shipping method metadata.
	 * @return string
	 */
	protected static function calculate_delivery_timeframe( array $meta_data ): string {
		$delivery_time_frame = '';
		$min_delivery_date   = ! empty( $meta_data['min_delivery_date'] ) ? $meta_data['min_delivery_date'] : '';
		$max_delivery_date   = ! empty( $meta_data['max_delivery_date'] ) ? $meta_data['max_delivery_date'] : '';

		if ( ! empty( $min_delivery_date ) && ! empty( $max_delivery_date ) ) {
			$min_work_days = self::get_working_days( gmdate( 'Y-m-d' ), $min_delivery_date );
			$max_work_days = self::get_working_days( gmdate( 'Y-m-d' ), $max_delivery_date );

			$delivery_time_frame = ( $min_work_days !== $max_work_days )
				? $min_work_days . ' to ' . $max_work_days . ' business days'
				: $min_work_days . ' business day' . ( $min_work_days > 1 ? 's' : '' );
		}

		return $delivery_time_frame;
	}

	/**
	 * Helper function to format the shipping description output.
	 *
	 * Splits the pipe-separated API description into structured rows: an
	 * optional venue note, the address, and trading hours. Stylistic choices
	 * live in `assets/src/bobgo-shipping.scss` (`.bobgo-rate-meta`) so themes
	 * can override them.
	 *
	 * @param string $delivery_time_frame The delivery time frame string.
	 * @param string $description         The shipping description.
	 * @return string Escaped HTML output safe for display.
	 */
	protected static function format_description_output( string $delivery_time_frame, string $description ): string {
		$segments = [];
		foreach ( explode( '|', $description ) as $segment ) {
			$segment = trim( $segment );
			if ( '' !== $segment ) {
				$segments[] = $segment;
			}
		}

		$hours      = '';
		$info_lines = [];
		foreach ( $segments as $segment ) {
			// Detect "Trading Hours:" prefix (case-insensitive) and pull it out
			// so it can be rendered separately with secondary styling.
			if ( 0 === stripos( $segment, 'Trading Hours:' ) ) {
				$hours = trim( substr( $segment, strlen( 'Trading Hours:' ) ) );
				continue;
			}
			$info_lines[] = $segment;
		}

		$html = '<div class="bobgo-rate-meta">';

		if ( '' !== $delivery_time_frame ) {
			$html .= '<span class="bobgo-rate-meta__time">' . esc_html( $delivery_time_frame ) . '</span>';
		}

		if ( ! empty( $info_lines ) || '' !== $hours ) {
			$html .= '<div class="bobgo-rate-meta__body">';

			$line_count = count( $info_lines );
			foreach ( $info_lines as $i => $line ) {
				$class = 'bobgo-rate-meta__line';
				// Treat all but the first info segment as secondary (the
				// address typically follows a venue/note in segment 0).
				if ( $line_count > 1 && $i > 0 ) {
					$class .= ' bobgo-rate-meta__line--address';
				}
				$html .= '<p class="' . esc_attr( $class ) . '">' . wp_kses_post( $line ) . '</p>';
			}

			if ( '' !== $hours ) {
				$hour_lines = preg_split( '/,\s+/', $hours );
				$hour_lines = is_array( $hour_lines ) ? array_filter( array_map( 'trim', $hour_lines ) ) : [];

				if ( count( $hour_lines ) > 1 ) {
					$html .= '<ul class="bobgo-rate-meta__hours">';
					foreach ( $hour_lines as $hour ) {
						$html .= '<li>' . wp_kses_post( $hour ) . '</li>';
					}
					$html .= '</ul>';
				} else {
					$html .= '<p class="bobgo-rate-meta__line bobgo-rate-meta__line--hours">' . wp_kses_post( $hours ) . '</p>';
				}
			}

			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Determine whether or not the suburb_at_checkout setting is active.
	 *
	 * @return bool
	 */
	private static function has_suburb_at_checkout(): bool {
		$options            = self::get_options();
		$suburb_at_checkout = $options['suburb_at_checkout'] ?? 1;

		return (bool) $suburb_at_checkout;
	}

	/**
	 * Add a suburb field to admin address fields so that it can be edited per order.
	 *
	 * Keys may be integers: WooCommerce injects block additional fields via
	 * array_splice(), which renumbers the inserted elements (see
	 * has_block_suburb_field()).
	 *
	 * @param array<string|int, mixed> $fields Admin address edit fields.
	 * @return array<string|int, mixed>
	 */
	public static function add_suburb_to_admin_address_fields( array $fields ): array {
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return $fields;
		}

		if ( ! self::has_suburb_at_checkout() ) {
			return $fields;
		}

		// Block-checkout orders already get a "Suburb" row from WooCommerce's own
		// additional-checkout-field display: CheckoutFieldsAdmin injects our
		// registered BLOCKS_FIELD_ID into this same array at priority 10, before
		// us at 20. Adding the classic field on top would show "Suburb" twice, so
		// skip it whenever that block field is already present.
		if ( self::has_block_suburb_field( $fields ) ) {
			return $fields;
		}

		$out = [];
		foreach ( $fields as $key => $field ) {
			if ( 'city' === $key ) {
				$out['suburb'] = [
					'label' => __( 'Suburb', 'bobgo-shipping' ),
					'show'  => true,
					'style' => '',
				];
			}
			$out[ $key ] = $field;
		}

		return $out;
	}

	/**
	 * Detect whether WooCommerce has already injected our block-checkout suburb
	 * field into an admin address-fields array.
	 *
	 * WooCommerce's CheckoutFieldsAdmin injects registered additional fields at
	 * priority 10 (before our priority 20) using array_splice(), which renumbers
	 * the inserted element's string key to an integer. The block field's
	 * identifier therefore survives in its `id` value (e.g.
	 * "_wc_shipping/namespace/cb_shipping_suburb"), not in the array key — so a
	 * key-only scan never matches and the classic field gets appended, which was
	 * the cause of the duplicate "Suburb" row. We match on both the array key
	 * (forward-compatible: only meaningful if a future WooCommerce preserves the
	 * registered key) and each field's `id` value (the reliable signal in current
	 * WooCommerce). Both end with BLOCKS_FIELD_ID, so we anchor the match to the
	 * end rather than matching the substring anywhere.
	 *
	 * @param array<string|int, mixed> $fields Admin address edit fields.
	 * @return bool
	 */
	private static function has_block_suburb_field( array $fields ): bool {
		foreach ( $fields as $key => $field ) {
			if ( is_string( $key ) && str_ends_with( $key, self::BLOCKS_FIELD_ID ) ) {
				return true;
			}

			if ( is_array( $field ) && isset( $field['id'] ) && is_string( $field['id'] ) && str_ends_with( $field['id'], self::BLOCKS_FIELD_ID ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add suburb fields to checkout.
	 *
	 * @param array<string, mixed> $fields Checkout fields.
	 * @return array<string, mixed>
	 */
	public static function add_suburb_to_checkout_fields( array $fields ): array {
		if ( ! is_array( $fields ) || empty( $fields ) || ! function_exists( 'WC' ) ) {
			return $fields;
		}

		if ( ! self::has_suburb_at_checkout() ) {
			return $fields;
		}

		$cart = WC()->cart;
		if ( $cart && ! $cart->needs_shipping() ) {
			return $fields;
		}

		// Define suburb field for shipping address.
		$shipping_suburb = [
			'id'                => 'shipping_suburb',
			'name'              => 'shipping_suburb',
			'type'              => 'text',
			'label'             => __( 'Suburb', 'bobgo-shipping' ),
			'placeholder'       => __( 'Enter your suburb', 'bobgo-shipping' ),
			'required'          => false,
			'class'             => [ 'form-row-wide', 'address-field' ],
			'priority'          => 65,
			'maxlength'         => self::SUBURB_MAX_LENGTH,
			'custom_attributes' => [
				'maxlength' => self::SUBURB_MAX_LENGTH,
			],
			'sanitize_callback' => [ self::class, 'sanitize_suburb' ],
		];

		// Define suburb field for billing address.
		$billing_suburb = [
			'id'                => 'billing_suburb',
			'name'              => 'billing_suburb',
			'type'              => 'text',
			'label'             => __( 'Suburb', 'bobgo-shipping' ),
			'placeholder'       => __( 'Enter your suburb', 'bobgo-shipping' ),
			'required'          => false,
			'class'             => [ 'form-row-wide', 'address-field' ],
			'priority'          => 65,
			'maxlength'         => self::SUBURB_MAX_LENGTH,
			'custom_attributes' => [
				'maxlength' => self::SUBURB_MAX_LENGTH,
			],
			'sanitize_callback' => [ self::class, 'sanitize_suburb' ],
		];

		// Add to shipping fields.
		$fields['shipping']['shipping_suburb'] = $shipping_suburb;

		// Add to billing fields.
		$fields['billing']['billing_suburb'] = $billing_suburb;

		return $fields;
	}

	/**
	 * Save suburb in session during order review.
	 *
	 * @param string $posted_data The posted data string.
	 */
	public static function save_suburb_in_session_during_order_review( string $posted_data ): void {
		try {
			// If the setting is off, clear session values.
			if ( ! self::has_suburb_at_checkout() ) {
				self::clear_suburb_session();
				return;
			}

			// Ensure WC session is available.
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return;
			}

			wp_parse_str( $posted_data, $output );

			// If this function is triggered, it means we're using classic checkout.
			WC()->session->set( self::SESSION_KEY_IS_BLOCKS, false );

			// Determine which field the buyer actually filled in.
			$suburb = self::resolve_classic_checkout_suburb( $output );

			// Save the shipping suburb to session.
			if ( '' !== $suburb ) {
				WC()->session->set( self::SESSION_KEY_SUBURB, $suburb );
			} else {
				WC()->session->__unset( self::SESSION_KEY_SUBURB );
			}
		} catch ( Exception $e ) {
			self::log( 'Failed to save suburb in session during order review: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Resolve the suburb for a classic-checkout rates request from the posted
	 * checkout form data.
	 *
	 * WooCommerce posts the ENTIRE checkout form on every rate refresh
	 * (checkout.js sends `post_data: $( 'form.checkout' ).serialize()`), and
	 * toggling "Ship to a different address" only hides the shipping fieldset
	 * with CSS — the inputs are never disabled, so `shipping_suburb` is always
	 * present and is an empty string while the box is off. Presence therefore
	 * says nothing about what the buyer typed; the checkbox is the only reliable
	 * signal:
	 *
	 *  - ticked   -> the shipping fieldset is the delivery address; use shipping_suburb.
	 *  - unticked -> WooCommerce mirrors every billing field onto its shipping
	 *                counterpart when the order is created, so the billing
	 *                suburb IS the delivery suburb.
	 *
	 * The store's `woocommerce_ship_to_destination` setting only decides whether
	 * that box STARTS ticked; once the buyer changes it their choice wins, so the
	 * setting must not be consulted here. Reading it is what made a store set to
	 * "Default to customer shipping address" quote against an empty local_area
	 * whenever a buyer unticked the box: the code took the hidden, empty
	 * shipping_suburb over the visible billing_suburb the buyer had filled in.
	 *
	 * There is deliberately no cross-fallback between the two groups. When the
	 * buyer has chosen a different delivery address, borrowing the billing suburb
	 * would quote a suburb from another city; an empty local_area is the honest
	 * answer.
	 *
	 * @param array<string, mixed> $output Parsed checkout form data.
	 * @return string The suburb, or '' when the buyer has not supplied one.
	 */
	private static function resolve_classic_checkout_suburb( array $output ): string {
		// ship_to_different_address is only posted as "1" while the box is ticked.
		$ship_to_different = isset( $output['ship_to_different_address'] )
			&& is_string( $output['ship_to_different_address'] )
			&& '1' === sanitize_text_field( $output['ship_to_different_address'] );

		$key = $ship_to_different ? 'shipping_suburb' : 'billing_suburb';

		if ( ! isset( $output[ $key ] ) || ! is_string( $output[ $key ] ) ) {
			return '';
		}

		return self::sanitize_suburb( $output[ $key ] );
	}

	/**
	 * Saves the plugin version in order meta.
	 *
	 * @param int                 $order_id ID of the order.
	 * @param WC_Order|array|null $order    The order object.
	 */
	public static function save_plugin_version( int $order_id, WC_Order|array|null $order = null ): void {
		// Get order if not sent along to hook (Happens when hook fires too fast).
		$order = self::ensure_order( $order, $order_id );
		if ( ! $order ) {
			return;
		}

		// Save the current plugin version in meta (HPOS compatible).
		if ( defined( 'BOBGO_SHIPPING_VERSION' ) ) {
			$order->update_meta_data( 'bobgo_plugin_version', BOBGO_SHIPPING_VERSION );
		}
	}

	/**
	 * Saves the shipping suburb and product shipping class in the packages payload to be used for rates at checkout.
	 *
	 * @param array<int, array<string, mixed>> $packages The packages in the cart.
	 * @return array<int, array<string, mixed>>
	 */
	public static function modify_shipping_packages( array $packages ): array {
		if ( empty( $packages ) ) {
			return [];
		}

		try {
			// Ensure WC is available.
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return $packages;
			}

			// Dynamically check if Checkout Blocks are used.
			$is_checkout_blocks = WC()->session->get( self::SESSION_KEY_IS_BLOCKS ) ?? false;

			foreach ( $packages as $index => $package ) {
				// Let's start with clean values.
				unset( $packages[ $index ]['destination'][ self::SESSION_KEY_SUBURB ] );
				unset( $packages[ $index ]['destination'][ self::SESSION_KEY_BLOCKS_SUBURB ] );
				unset( $packages[ $index ]['destination'][ self::SESSION_KEY_IS_BLOCKS ] );

				// Add billing/shipping suburb to destination address for new checkout blocks and legacy checkout.
				// Don't add it on the cart pages.
				if ( self::has_suburb_at_checkout() && ! is_cart() ) {
					// Get suburb using unified accessor.
					$suburb_value = self::get_current_suburb();

					if ( $is_checkout_blocks ) {
						$packages[ $index ]['destination'][ self::SESSION_KEY_BLOCKS_SUBURB ] = $suburb_value;
						$packages[ $index ]['destination'][ self::SESSION_KEY_IS_BLOCKS ]     = 'true';
					} elseif ( is_checkout() && ! is_wc_endpoint_url() ) {
						$packages[ $index ]['destination'][ self::SESSION_KEY_SUBURB ] = $suburb_value;
					}
				}

				// Add product shipping class to the items.
				$cart_items = $packages[ $index ]['contents'];
				foreach ( $cart_items as $key => $item ) {
					/**
					 * Holds the product.
					 *
					 * @var \WC_Product $wc_product
					 */
					$wc_product                              = $item['data'];
					$cart_items[ $key ]['shipping_class']    = $wc_product->get_shipping_class();
					$cart_items[ $key ]['shipping_class_id'] = $wc_product->get_shipping_class_id();
				}
				$packages[ $index ]['contents'] = $cart_items;
			}
		} catch ( Exception $e ) {
			self::log( 'Failed to determine if checkout blocks are being used: ' . $e->getMessage(), 'error' );
		}

		return $packages;
	}

	/**
	 * Register an additional checkout field for the suburb for WooCommerce checkout blocks.
	 */
	public static function register_checkout_field(): void {
		try {
			// Check if WooCommerce version is 9.0.0 or higher.
			if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.0.0', '<' ) ) {
				return;
			}

			// Proceed if the suburb field is required at checkout.
			if ( ! self::has_suburb_at_checkout() ) {
				return;
			}

			// Register the additional checkout field.
			woocommerce_register_additional_checkout_field(
				[
					'id'            => self::BLOCKS_FIELD_ID,
					'label'         => __( 'Suburb', 'bobgo-shipping' ),
					'optionalLabel' => __( 'Suburb (optional)', 'bobgo-shipping' ),
					'location'      => 'address',
					'required'      => false,
					'attributes'    => [
						'autocomplete' => 'suburb',
						'title'        => __( 'Suburb', 'bobgo-shipping' ),
					],
				]
			);
		} catch ( Exception $e ) {
			self::log( 'Failed to add suburb field for checkout blocks: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Save the checkout block shipping_suburb value.
	 *
	 * @param string               $key       The key of the field being saved.
	 * @param mixed                $value     The value of the field being saved.
	 * @param string               $group     The group of this location (shipping|billing|other).
	 * @param WC_Customer|WC_Order $wc_object The object to set the field value for.
	 */
	public static function set_additional_field_value( string $key, mixed $value, string $group, WC_Customer|WC_Order $wc_object ): void {
		try {
			if ( self::BLOCKS_FIELD_ID !== $key ) {
				return;
			}

			// We only care about the shipping group.
			if ( 'shipping' !== $group ) {
				return;
			}

			// If the setting is off, clear session values.
			if ( ! self::has_suburb_at_checkout() ) {
				self::clear_suburb_session();
				return;
			}

			// Sanitize the value.
			$sanitized_value = is_string( $value ) ? self::sanitize_suburb( $value ) : '';

			// Update the metadata with the checkout block cb_shipping_suburb value for logged-in users.
			// WC_Data::update_meta_data signature is ($key, $value, $meta_id = 0); we don't target a
			// specific meta row, so omit the third argument (previously passed `true`, which coerced
			// to meta_id = 1 and effectively only updated the first meta record).
			if ( is_a( $wc_object, 'WC_Customer' ) ) {
				$wc_object->update_meta_data( self::SESSION_KEY_BLOCKS_SUBURB, $sanitized_value );
			}

			// Persist suburb to order meta so outbound sync can read it.
			if ( is_a( $wc_object, 'WC_Order' ) ) {
				$wc_object->update_meta_data( '_shipping_suburb', $sanitized_value );
			}

			// Save to session for guest users or during the checkout session.
			if ( function_exists( 'WC' ) && WC()->session ) {
				// Set both values together to ensure consistency.
				WC()->session->set( self::SESSION_KEY_IS_BLOCKS, true );
				WC()->session->set( self::SESSION_KEY_BLOCKS_SUBURB, $sanitized_value );
			}
		} catch ( Exception $e ) {
			self::log( 'Failed to set additional field value: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Get cached bobgo options.
	 * Uses static cache to avoid repeated database queries within the same request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_options(): array {
		if ( null === self::$options_cache ) {
			self::$options_cache = get_option( 'bobgo', [] );
			if ( ! is_array( self::$options_cache ) ) {
				self::$options_cache = [];
			}
		}
		return self::$options_cache;
	}

	/**
	 * Get cached WooCommerce shipping settings.
	 * Uses static cache to avoid repeated database queries within the same request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_shipping_settings(): array {
		if ( null === self::$shipping_settings_cache ) {
			self::$shipping_settings_cache = get_option( 'woocommerce_bobgo_shipping_settings', [] );
			if ( ! is_array( self::$shipping_settings_cache ) ) {
				self::$shipping_settings_cache = [];
			}
		}
		return self::$shipping_settings_cache;
	}

	/**
	 * Clear the static options cache.
	 * Call this if options are updated during the request.
	 */
	public static function clear_options_cache(): void {
		self::$options_cache           = null;
		self::$shipping_settings_cache = null;
	}

	/**
	 * Ensure we have a valid WC_Order object.
	 *
	 * @param mixed $order    The order object or null.
	 * @param int   $order_id The order ID.
	 * @return WC_Order|null The order object or null if not found.
	 */
	private static function ensure_order( mixed $order, int $order_id ): ?WC_Order {
		if ( is_a( $order, 'WC_Order' ) ) {
			return $order;
		}

		$fetched_order = wc_get_order( $order_id );
		return $fetched_order instanceof WC_Order ? $fetched_order : null;
	}

	/**
	 * Sanitize suburb input value.
	 *
	 * @param string $suburb The suburb value to sanitize.
	 * @return string Sanitized suburb value.
	 */
	private static function sanitize_suburb( string $suburb ): string {
		$suburb = sanitize_text_field( $suburb );
		$suburb = mb_substr( $suburb, 0, self::SUBURB_MAX_LENGTH );
		return $suburb;
	}

	/**
	 * Get the current suburb value from session or customer data.
	 * Unified accessor for both classic checkout and checkout blocks.
	 * Optimized to check fast sources (session) before expensive ones (Checkout Fields API).
	 *
	 * @return string|null The suburb value or null if not set.
	 */
	private static function get_current_suburb(): ?string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$is_checkout_blocks = WC()->session->get( self::SESSION_KEY_IS_BLOCKS );

		if ( $is_checkout_blocks ) {
			// Check session first (fastest, most common case).
			$suburb = WC()->session->get( self::SESSION_KEY_BLOCKS_SUBURB );
			if ( ! empty( $suburb ) ) {
				return self::sanitize_suburb( $suburb );
			}

			// Fallback to customer meta.
			if ( WC()->customer ) {
				$suburb = WC()->customer->get_meta( self::SESSION_KEY_BLOCKS_SUBURB );
				if ( ! empty( $suburb ) ) {
					return self::sanitize_suburb( $suburb );
				}
			}

			// Last resort: Checkout Fields API (expensive).
			if ( WC()->customer && class_exists( '\Automattic\WooCommerce\Blocks\Package' ) &&
				class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
				try {
					$checkout_fields = \Automattic\WooCommerce\Blocks\Package::container()
						->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
					$suburb_value    = $checkout_fields->get_field_from_object(
						self::BLOCKS_FIELD_ID,
						WC()->customer,
						'shipping'
					);
					if ( ! empty( $suburb_value ) ) {
						return self::sanitize_suburb( $suburb_value );
					}
				} catch ( Exception $e ) {
					self::log( 'Checkout Fields API unavailable: ' . $e->getMessage(), 'debug' );
				}
			}

			return null;
		}

		// Classic checkout - get from session.
		$suburb = WC()->session->get( self::SESSION_KEY_SUBURB );
		if ( ! empty( $suburb ) ) {
			return self::sanitize_suburb( $suburb );
		}

		return null;
	}

	/**
	 * Clear all suburb-related session data.
	 */
	private static function clear_suburb_session(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->__unset( self::SESSION_KEY_SUBURB );
		WC()->session->__unset( self::SESSION_KEY_BLOCKS_SUBURB );
		WC()->session->__unset( self::SESSION_KEY_IS_BLOCKS );
	}

	/**
	 * Checks whether an order is a subscription order.
	 *
	 * @param WC_Order|mixed $order The order.
	 * @return bool
	 */
	private static function is_subscription_order( mixed $order ): bool {
		if ( empty( $order ) || false === $order ) {
			return false;
		}

		if ( method_exists( $order, 'get_type' ) && 'shop_subscription' === $order->get_type() ) {
			return true;
		}
		if ( $order->get_meta( '_subscription_renewal', true ) ) {
			return true;
		}

		return false;
	}
}
