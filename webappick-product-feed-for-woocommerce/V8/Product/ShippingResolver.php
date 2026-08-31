<?php
/**
 * ShippingResolver — Resolves dynamic shipping data from WooCommerce shipping zones.
 *
 * Reads WC_Shipping_Zones to build shipping entries per product. Returns
 * arrays for XML feeds (GroupedAttributeBuilder nests them under g:shipping)
 * or composite strings for CSV feeds.
 *
 * Ported from V5 GoogleShipping.php + Shipping.php (193 lines combined).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements G-01, S-03
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shipping resolver.
 *
 * @since 8.0.0
 */
class ShippingResolver {

	/**
	 * Cache key for shipping zone data.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const CACHE_KEY = 'ctxfeed_shipping_zones';

	/**
	 * Cache group.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const CACHE_GROUP = 'ctxfeed';

	/**
	 * Per-request memo of the WC shipping zone list.
	 *
	 * @since 8.0.0
	 * @var array|null
	 */
	private static $zones_memo = null;

	/**
	 * Per-request memo of computed shipping prices.
	 *
	 * Keyed by product|zone|method — feed generation resolves shipping
	 * for thousands of products against the same handful of zones.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private static $price_memo = array();

	/**
	 * Reset the per-request memos.
	 *
	 * For long-running processes (WP-CLI, tests) where zones or method
	 * settings change mid-process.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public static function flush_runtime_memo(): void {
		self::$zones_memo = null;
		self::$price_memo = array();
	}

	/**
	 * Whether local pickup methods are excluded from shipping entries.
	 *
	 * V5 parity — Settings::get('only_local_pickup_shipping'), default
	 * 'no': pickup methods appear in the feed unless the merchant turns
	 * the setting on.
	 *
	 * @since 8.0.0
	 *
	 * @return bool True to exclude local_pickup methods.
	 */
	private function exclude_local_pickup(): bool {
		$settings = get_option( 'woo_feed_settings', array() );
		$value    = isset( $settings['only_local_pickup_shipping'] ) ? $settings['only_local_pickup_shipping'] : 'no';

		return 'yes' === $value;
	}

	/**
	 * Resolve shipping data for a product.
	 *
	 * Returns an array of shipping entries for XML or a composite string for CSV.
	 * Each entry contains country, region, service, and price fields.
	 *
	 * @since 8.0.0
	 * @implements G-01
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Attribute name (shipping, shipping_country, etc.).
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Array of shipping entries (XML) or composite string (CSV).
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ) {
		$zones = $this->get_shipping_zones();

		// Filter BEFORE computing prices — avoids expensive cart-API calls
		// for zones that would be discarded anyway.
		$zones   = $this->filter_by_config( $zones, $config );
		$entries = $this->build_shipping_entries( $zones, $product, $config );

		/**
		 * Filter resolved shipping entries.
		 *
		 * @since 8.0.0
		 *
		 * @param array       $entries Shipping entries.
		 * @param \WC_Product $product WooCommerce product.
		 * @param Config      $config  Feed configuration.
		 */
		$entries = apply_filters( 'ctxfeed_shipping_entries', $entries, $product, $config );

		// Determine output format based on feed type.
		$feed_type = strtolower( $config->get( 'feedType', 'xml' ) );

		if ( in_array( $feed_type, array( 'csv', 'tsv', 'txt' ), true ) ) {
			return $this->format_for_csv( $entries, $config );
		}

		return $entries;
	}

	/**
	 * Get all WooCommerce shipping zones with their methods.
	 *
	 * Caches the result per request to avoid repeated DB queries.
	 *
	 * @since 8.0.0
	 *
	 * @return array Array of zone data with locations and methods.
	 */
	private function get_shipping_zones(): array {
		// Per-request memoization only — avoids persistent-object-cache
		// pitfalls where adding/editing a zone doesn't flush stale data.
		// Static property (not a method-local static) so long-running
		// processes and tests can reset it via flush_runtime_memo().
		if ( null !== self::$zones_memo ) {
			return self::$zones_memo;
		}

		$zones    = array();
		$wc_zones = \WC_Shipping_Zones::get_zones();

		foreach ( $wc_zones as $zone_data ) {
			$zone_id   = isset( $zone_data['zone_id'] ) ? $zone_data['zone_id'] : 0;
			$zone_name = isset( $zone_data['zone_name'] ) ? $zone_data['zone_name'] : '';
			$locations = isset( $zone_data['zone_locations'] ) ? $zone_data['zone_locations'] : array();
			$methods   = isset( $zone_data['shipping_methods'] ) ? $zone_data['shipping_methods'] : array();

			foreach ( $locations as $location ) {
				$loc_type = isset( $location->type ) ? $location->type : '';
				$loc_code = isset( $location->code ) ? $location->code : '';

				$country = '';
				$state   = '';

				if ( 'country' === $loc_type ) {
					$country = $loc_code;
				} elseif ( 'state' === $loc_type ) {
					$parts   = explode( ':', $loc_code );
					$country = isset( $parts[0] ) ? $parts[0] : '';
					$state   = isset( $parts[1] ) ? $parts[1] : '';
				} elseif ( 'postcode' === $loc_type ) {
					// Postcodes are handled but less common for shipping feeds.
					continue;
				}

				foreach ( $methods as $method ) {
					if ( ! isset( $method->enabled ) || 'yes' !== $method->enabled ) {
						continue;
					}

					$method_id          = isset( $method->id ) ? $method->id : '';
					$method_title       = isset( $method->title ) ? $method->title : '';
					$method_instance_id = isset( $method->instance_id ) ? $method->instance_id : 0;

					// V5 parity: local pickup is skipped only when the
					// `only_local_pickup_shipping` setting says so —
					// by default the pickup method appears in the feed
					// like any other zone method.
					if ( 'local_pickup' === $method_id && $this->exclude_local_pickup() ) {
						continue;
					}

					// NOTE: No price computed here. Prices are computed per
					// product in compute_shipping_price() using WC's cart
					// API so shortcodes ([qty], [fee]), table-rates, and
					// percentage-based rules resolve correctly — matching V5.
					$zones[] = array(
						'zone_id'            => $zone_id,
						'zone_name'          => $zone_name,
						'country'            => $country,
						'region'             => $state,
						'service'            => $method_title,
						'method_id'          => $method_id,
						'method_instance_id' => $method_instance_id,
					);
				}
			}
		}

		// Also add the "Rest of the World" zone (zone ID 0).
		$rest_zone    = new \WC_Shipping_Zone( 0 );
		$rest_methods = $rest_zone->get_shipping_methods( true );

		foreach ( $rest_methods as $method ) {
			$method_id          = isset( $method->id ) ? $method->id : '';
			$method_title       = isset( $method->title ) ? $method->title : '';
			$method_instance_id = isset( $method->instance_id ) ? $method->instance_id : 0;

			if ( 'local_pickup' === $method_id && $this->exclude_local_pickup() ) {
				continue;
			}

			$zones[] = array(
				'zone_id'            => 0,
				'zone_name'          => 'Rest of the World',
				'country'            => '',
				'region'             => '',
				'service'            => $method_title,
				'method_id'          => $method_id,
				'method_instance_id' => $method_instance_id,
			);
		}

		self::$zones_memo = $zones;

		return $zones;
	}

	/**
	 * Compute the actual shipping price for a product under a given zone
	 * + method combination.
	 *
	 * Uses WooCommerce's cart API — identical to V5 Shipping::get_shipping_price()
	 * so shortcode-based rates (`[qty] * 5`, `[fee percent=...]`), table-rate
	 * plugins, weight/quantity rules, and free shipping minimums all resolve
	 * correctly. Results are memoised per request on a cheap key so feeds
	 * with many products don't re-enter the cart workflow redundantly.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-add-to-cart compat hook. Default null.
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_shipping_price( array $zone, \WC_Product $product, ?Config $config = null ): string {
		$memo = &self::$price_memo;

		// Memo key: product + zone + method. Needed because feed generation
		// may resolve shipping for thousands of products.
		$pid      = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$memo_key = $pid . '|' . $zone['zone_id'] . '|' . $zone['method_id'] . '|' . $zone['method_instance_id'] . '|' . $zone['country'] . '|' . $zone['region'];

		if ( isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		if ( ! defined( 'WC_ABSPATH' ) || ! function_exists( 'WC' ) || ! WC() ) {
			$memo[ $memo_key ] = '';
			return '';
		}

		// Ensure cart + session are loaded — they aren't by default in
		// REST / cron / Action-Scheduler context.
		if ( ! WC()->cart ) {
			include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
			include_once WC_ABSPATH . 'includes/class-wc-cart.php';
			if ( function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}
		}
		if ( ! WC()->cart || ! WC()->customer || ! WC()->session ) {
			$memo[ $memo_key ] = '';
			return '';
		}

		// Remember original context so we don't leak state across products.
		$prev_country = WC()->customer->get_shipping_country();
		$prev_state   = WC()->customer->get_shipping_state();
		$prev_chosen  = WC()->session->get( 'chosen_shipping_methods' );

		try {
			WC()->cart->empty_cart();

			if ( ! empty( $zone['country'] ) ) {
				WC()->customer->set_shipping_country( $zone['country'] );
			}
			WC()->customer->set_shipping_state( ! empty( $zone['region'] ) ? $zone['region'] : '' );

			// Pin the chosen shipping method to this zone+method combination.
			$chosen_id = $zone['method_id'] . ':' . $zone['method_instance_id'];
			WC()->session->set( 'chosen_shipping_methods', array( $chosen_id ) );

			/**
			 * Fires before the product is added to the cart for shipping
			 * price calculation.
			 *
			 * V5 6.6.x hook — multi-currency compat plugins switch the
			 * shipping currency context here (ctx-compatibility
			 * WOOMULTI_CURRENCYCompatibility).
			 *
			 * @since 8.0.0
			 *
			 * @param Config|null $config Feed configuration.
			 * @param int         $pid    Product ID being priced.
			 */
			do_action( 'woo_feed_before_add_to_cart_for_shipping', $config, $pid );

			WC()->cart->add_to_cart( $pid, 1 );

			// Force shipping calculation. Feed generation runs in a background
			// context (Action Scheduler / cron / REST), and there add_to_cart
			// does NOT recompute shipping the way a front-end request does —
			// so get_shipping_total() would stay 0.00 even with a flat rate
			// configured. Calculating explicitly against the address + chosen
			// method set above makes the total reflect the real rate.
			if ( method_exists( WC()->cart, 'calculate_shipping' ) ) {
				WC()->cart->calculate_shipping();
			}
			WC()->cart->calculate_totals();

			$cost  = (float) WC()->cart->get_shipping_total();
			$cost += (float) WC()->cart->get_shipping_tax();

			$result = number_format( $cost, 2, '.', '' );
		} catch ( \Throwable $e ) {
			$result = '';
		}

		// Restore prior cart/session state.
		try {
			WC()->cart->empty_cart();
			WC()->session->set( 'chosen_shipping_methods', is_array( $prev_chosen ) ? $prev_chosen : array( '' ) );
			WC()->customer->set_shipping_country( $prev_country );
			WC()->customer->set_shipping_state( $prev_state );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- State restoration is best-effort; a failure here must never break feed generation.
			// Non-fatal — restoration best-effort.
		}

		$memo[ $memo_key ] = $result;

		return $result;
	}

	/**
	 * Build shipping entries from zone data for a specific product.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $zones   Raw shipping zone data.
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array Array of shipping entry arrays.
	 */
	private function build_shipping_entries( array $zones, \WC_Product $product, Config $config ): array {
		$entries  = array();
		$currency = $this->resolve_feed_currency( $config );

		foreach ( $zones as $zone ) {
			// Compute the actual price via WC cart API (V5-style).
			$price = $this->compute_shipping_price( $zone, $product, $config );

			// Keep zones whose price couldn't be computed — emit with "0.00"
			// so merchants can still see the zone in the feed and diagnose.
			if ( '' === $price ) {
				$price = '0.00';
			}

			$price_with_currency = $price . ' ' . $currency;

			$entry = array(
				'country' => $zone['country'],
				'region'  => $zone['region'],
				'service' => $zone['service'],
				'price'   => $price_with_currency,
			);

			/**
			 * Filter individual shipping entry.
			 *
			 * @since 8.0.0
			 *
			 * @param array       $entry   Shipping entry.
			 * @param array       $zone    Raw zone data.
			 * @param \WC_Product $product WooCommerce product.
			 * @param Config      $config  Feed configuration.
			 */
			$entries[] = apply_filters( 'ctxfeed_shipping_entry', $entry, $zone, $product, $config );
		}

		return $entries;
	}

	/**
	 * Resolve the feed's currency code for the shipping price suffix.
	 *
	 * V5 parity (GoogleShipping uses Config::get_feed_currency): the feed's
	 * chosen currency, falling back to the store currency. The stored key is
	 * `feedCurrency` — the previous `$config->get( 'currency' )` never matched
	 * it, so a feed with a currency override showed the store currency instead.
	 * Mirrors AppendsCurrencyTrait's key order so the shipping price currency
	 * always agrees with the product price currency.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string ISO 4217 code (e.g. "USD").
	 */
	private function resolve_feed_currency( Config $config ): string {
		foreach ( array( 'feed_currency', 'feedCurrency', 'currency' ) as $key ) {
			$currency = (string) $config->get( $key, '' );
			if ( '' !== $currency ) {
				return $currency;
			}
		}

		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	/**
	 * Filter shipping entries by feed configuration.
	 *
	 * Applies feed_country, allow_all_shipping, and shipping_country config filters.
	 * Matches V5 GoogleShipping::get_csv() filtering logic.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $entries Raw shipping entries.
	 * @param Config $config  Feed configuration.
	 *
	 * @return array Filtered shipping entries.
	 */
	private function filter_by_config( array $entries, Config $config ): array {
		$feed_country     = $config->get( 'feed_country', '' );
		$shipping_country = $config->get( 'shipping_country', '' );

		// Per-feed value is authoritative. The global `allow_all_shipping`
		// setting is intentionally NOT consulted here — the Filter tab is
		// the single source of truth. Unset / legacy feeds default to
		// `'feed'` (strict match against the feed country).
		//
		// - shipping_country === 'all'  → include all zones
		// - shipping_country === 'feed' → strict match vs feed_country
		// - empty / unknown             → default to 'feed' behaviour.
		if ( 'all' === $shipping_country ) {
			return $entries;
		}

		$entries = array_filter(
			$entries,
			function ( $entry ) use ( $feed_country ) {
				return isset( $entry['country'] ) && $entry['country'] === $feed_country;
			} 
		);

		return array_values( $entries );
	}

	/**
	 * Format shipping entries as CSV composite strings.
	 *
	 * Returns colon-separated composite: "US:CA:Standard:5.99 USD"
	 * Multiple entries are separated by commas.
	 *
	 * @since 8.0.0
	 * @implements S-03
	 *
	 * @param array  $entries Shipping entries.
	 * @param Config $config  Feed configuration.
	 *
	 * @return string CSV composite string.
	 */
	private function format_for_csv( array $entries, Config $config ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config kept for formatter signature parity; reserved for locale/currency hooks.
		if ( empty( $entries ) ) {
			return '';
		}

		$composites = array();

		foreach ( $entries as $entry ) {
			$composites[] = implode(
				':',
				array(
					$entry['country'],
					$entry['region'],
					$entry['service'],
					$entry['price'],
				) 
			);
		}

		return implode( ',', $composites );
	}
}
