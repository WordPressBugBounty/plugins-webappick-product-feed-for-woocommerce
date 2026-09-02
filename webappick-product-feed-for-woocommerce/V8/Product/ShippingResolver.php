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
use CTXFeed\V8\Core\Logger;

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
	 * Package path: keyed by price|shipping_class|zone|method — shipping
	 * cost depends on what the product costs and how it ships, not on its
	 * identity, so an 11K-product catalog collapses to a few hundred
	 * unique computations. Cart path: keyed by product|zone|method.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private static $price_memo = array();

	/**
	 * Per-request memo of shipping method instances.
	 *
	 * WC_Shipping_Zones::get_shipping_method() hits the DB per call;
	 * feed generation asks for the same handful of instances thousands
	 * of times.
	 *
	 * @since 8.0.9
	 * @var array<int,\WC_Shipping_Method|false>
	 */
	private static $method_memo = array();

	/**
	 * Seconds spent computing shipping prices this request.
	 *
	 * Drives the per-batch time budget: once spent, remaining products
	 * emit un-priced entries instead of letting shipping resolution run
	 * the batch into the web-server kill window (BUG: a 14-method store
	 * died silently at batch 2 — Action Scheduler "in-progress ≥300s").
	 *
	 * @since 8.0.9
	 * @var float
	 */
	private static $budget_spent = 0.0;

	/**
	 * Whether the budget-exhausted warning has been logged this request.
	 *
	 * @since 8.0.9
	 * @var bool
	 */
	private static $budget_warned = false;

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
		self::$zones_memo    = null;
		self::$price_memo    = array();
		self::$method_memo   = array();
		self::$budget_spent  = 0.0;
		self::$budget_warned = false;
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
					// price-point in compute_shipping_price() by running the
					// method instance against a synthetic package (cart API
					// only via the ctxfeed_shipping_use_cart_api filter).
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
	 * Default path prices the configured method instance directly against
	 * a synthetic shipping package (see compute_price_via_package()) — no
	 * cart, no session, no third-party cart hooks. The V5-style cart-API
	 * path is kept behind the `ctxfeed_shipping_use_cart_api` filter for
	 * table-rate plugins that genuinely need a real cart.
	 *
	 * @since 8.0.0
	 * @since 8.0.9 Package-based pricing is the default; cart API opt-in.
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-pricing compat hook. Default null.
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_shipping_price( array $zone, \WC_Product $product, ?Config $config = null ): string {
		/**
		 * Opt back into the V5-style cart-API shipping computation.
		 *
		 * The cart path runs a full add-to-cart + calculate_totals cycle
		 * per product × method — every cart-aware plugin (checkout,
		 * marketing, loyalty) fires on each cycle, which on real stores
		 * costs 100-300ms per cycle and can push a batch past the
		 * web-server kill window. Enable only when a shipping plugin
		 * cannot price a bare package.
		 *
		 * @since 8.0.9
		 *
		 * @param bool        $use_cart Default false.
		 * @param \WC_Product $product  Product being priced.
		 * @param array       $zone     Zone struct.
		 */
		if ( apply_filters( 'ctxfeed_shipping_use_cart_api', false, $product, $zone ) ) {
			return $this->compute_price_via_cart( $zone, $product, $config );
		}

		return $this->compute_price_via_package( $zone, $product, $config );
	}

	/**
	 * Price a zone method against a synthetic shipping package.
	 *
	 * Builds the same package structure WC_Shipping hands to
	 * WC_Shipping_Method::get_rates_for_package() and calls the
	 * configured method instance directly. Flat-rate cost expressions
	 * (`[qty]`, `[fee ...]`), per-shipping-class costs, and free-shipping
	 * minimums all resolve from the package/instance settings — without
	 * the cart cycle whose per-call cost made large feeds die mid-batch.
	 *
	 * Memoised on the actual cost drivers (price + shipping class + zone
	 * + method), so products sharing a price point reuse the computation.
	 *
	 * @since 8.0.9
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-pricing compat hook. Default null.
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_price_via_package( array $zone, \WC_Product $product, ?Config $config = null ): string {
		// V5 parity: variations price as themselves (their own price and
		// shipping class — the old cart path added the variable PARENT,
		// which always fails add_to_cart and silently emitted 0.00).
		// Grouped products price as their first child (V5 Shipping.php).
		if ( $product->is_type( 'grouped' ) ) {
			$children = $product->get_children();
			$child    = ! empty( $children ) ? wc_get_product( reset( $children ) ) : false;
			if ( ! $child instanceof \WC_Product ) {
				return '';
			}
			$product = $child;
		}

		// Virtual/downloadable products never ship — the cart path ended
		// at 0.00 for them (no shippable package); keep that contract.
		if ( ! $product->needs_shipping() ) {
			return '';
		}

		$price    = (float) wc_get_price_excluding_tax( $product );
		$class_id = (int) $product->get_shipping_class_id();

		$memo = &self::$price_memo;

		$memo_key = 'pkg|' . $price . '|' . $class_id . '|' . $zone['zone_id'] . '|' . $zone['method_id'] . '|' . $zone['method_instance_id'] . '|' . $zone['country'] . '|' . $zone['region'];

		if ( isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		/**
		 * Filter the shipping-price time budget in seconds per request.
		 *
		 * Once computations have consumed the budget, remaining products
		 * emit un-priced entries instead of risking the batch being
		 * killed by the web server. 0 disables computation entirely.
		 *
		 * @since 8.0.9
		 *
		 * @param float $budget Seconds. Default 20.
		 */
		$budget = (float) apply_filters( 'ctxfeed_shipping_time_budget', 20.0 );

		if ( self::$budget_spent >= $budget ) {
			if ( ! self::$budget_warned ) {
				self::$budget_warned = true;
				Logger::warning(
					'Shipping price time budget exhausted — remaining products in this batch emit unpriced shipping entries.',
					array(
						'budget_sec' => $budget,
						'spent_sec'  => round( self::$budget_spent, 2 ),
					)
				);
			}

			// Deliberately NOT memoised: the next batch gets a fresh
			// budget and should compute real prices for these keys.
			return '';
		}

		$started = microtime( true );

		/**
		 * Fires before a product is priced for shipping.
		 *
		 * V5 6.6.x hook — multi-currency compat plugins switch the
		 * shipping currency context here. Kept on the package path so
		 * the shims keep working. @see compute_price_via_cart().
		 *
		 * @since 8.0.0
		 *
		 * @param Config|null $config Feed configuration.
		 * @param int         $pid    Product ID being priced.
		 */
		do_action( 'woo_feed_before_add_to_cart_for_shipping', $config, (int) $product->get_id() );

		try {
			$result = $this->price_package_for_method( $zone, $product, $price );
		} catch ( \Throwable $e ) {
			$result = '';
		}

		self::$budget_spent += microtime( true ) - $started;

		$memo[ $memo_key ] = $result;

		return $result;
	}

	/**
	 * Run one method instance against one product package.
	 *
	 * @since 8.0.9
	 *
	 * @param array       $zone    Zone struct.
	 * @param \WC_Product $product Product to price (already child/variation-resolved).
	 * @param float       $price   Product price excluding tax.
	 *
	 * @return string Numeric string or empty string on failure.
	 */
	private function price_package_for_method( array $zone, \WC_Product $product, float $price ): string {
		$instance_id = (int) $zone['method_instance_id'];

		if ( ! isset( self::$method_memo[ $instance_id ] ) ) {
			self::$method_memo[ $instance_id ] = $instance_id > 0 && class_exists( '\WC_Shipping_Zones' )
				? \WC_Shipping_Zones::get_shipping_method( $instance_id )
				: false;
		}

		$method = self::$method_memo[ $instance_id ];

		if ( ! $method instanceof \WC_Shipping_Method ) {
			return '';
		}

		$package = $this->build_rate_package( $zone, $product, $price );

		// free_shipping's is_available() reads WC()->cart, which doesn't
		// exist in cron/Action-Scheduler context — evaluate its
		// requirements against the package ourselves.
		if ( 'free_shipping' === $zone['method_id'] ) {
			return $this->free_shipping_applies( $method, $product ) ? '0.00' : '';
		}

		// Shipping tax rates resolve from the customer location when one
		// exists; pin it to the zone so tax matches the destination, and
		// restore afterwards. No cart/session involved — cheap.
		$customer     = function_exists( 'WC' ) && WC() ? WC()->customer : null;
		$prev_country = null;
		$prev_state   = null;

		if ( $customer && ! empty( $zone['country'] ) ) {
			$prev_country = $customer->get_shipping_country();
			$prev_state   = $customer->get_shipping_state();
			$customer->set_shipping_country( $zone['country'] );
			$customer->set_shipping_state( ! empty( $zone['region'] ) ? $zone['region'] : '' );
		}

		try {
			$rates = $method->get_rates_for_package( $package );
		} finally {
			if ( $customer && null !== $prev_country ) {
				$customer->set_shipping_country( $prev_country );
				$customer->set_shipping_state( $prev_state );
			}
		}

		if ( empty( $rates ) || ! is_array( $rates ) ) {
			return '';
		}

		$rate = reset( $rates );

		if ( ! $rate instanceof \WC_Shipping_Rate ) {
			return '';
		}

		$cost = (float) $rate->get_cost();

		$taxes = $rate->get_taxes();
		if ( is_array( $taxes ) ) {
			$cost += (float) array_sum( $taxes );
		}

		return number_format( $cost, 2, '.', '' );
	}

	/**
	 * Build a WC-shaped shipping package for a single product.
	 *
	 * Mirrors the structure WC_Cart::get_shipping_packages() produces so
	 * method instances (core and third-party) can price it: contents with
	 * a real product object, contents_cost/cart_subtotal for `[cost]` and
	 * percentage expressions, and the zone's destination.
	 *
	 * @since 8.0.9
	 *
	 * @param array       $zone    Zone struct.
	 * @param \WC_Product $product Product to price.
	 * @param float       $price   Product price excluding tax.
	 *
	 * @return array Shipping package.
	 */
	private function build_rate_package( array $zone, \WC_Product $product, float $price ): array {
		$country = ! empty( $zone['country'] ) ? $zone['country'] : ( function_exists( 'wc_get_base_location' ) ? (string) ( wc_get_base_location()['country'] ?? '' ) : '' );

		return array(
			'contents'        => array(
				(string) $product->get_id() => array(
					'key'               => (string) $product->get_id(),
					'product_id'        => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
					'variation_id'      => $product->is_type( 'variation' ) ? $product->get_id() : 0,
					'variation'         => array(),
					'quantity'          => 1,
					'data'              => $product,
					'data_hash'         => '',
					'line_total'        => $price,
					'line_tax'          => 0,
					'line_subtotal'     => $price,
					'line_subtotal_tax' => 0,
				),
			),
			'contents_cost'   => $price,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => get_current_user_id() ),
			'destination'     => array(
				'country'   => $country,
				'state'     => ! empty( $zone['region'] ) ? $zone['region'] : '',
				'postcode'  => '',
				'city'      => '',
				'address'   => '',
				'address_1' => '',
				'address_2' => '',
			),
			'cart_subtotal'   => $price,
		);
	}

	/**
	 * Evaluate a free_shipping instance's requirements against a product.
	 *
	 * Core WC_Shipping_Method_Free_Shipping::is_available() consults
	 * WC()->cart (absent in cron context), so the min-amount check is
	 * replicated here against the product's display price — the same
	 * figure the cart's displayed subtotal would carry for qty 1.
	 * Coupon-based requirements can never be met in feed context.
	 *
	 * @since 8.0.9
	 *
	 * @param \WC_Shipping_Method $method  Free shipping instance.
	 * @param \WC_Product         $product Product to check.
	 *
	 * @return bool Whether free shipping would apply.
	 */
	private function free_shipping_applies( \WC_Shipping_Method $method, \WC_Product $product ): bool {
		$requires = (string) $method->get_option( 'requires', '' );

		if ( '' === $requires ) {
			return true;
		}

		// 'coupon' and 'both' (min_amount AND coupon) need a coupon in
		// the cart — impossible in feed context.
		if ( 'coupon' === $requires || 'both' === $requires ) {
			return false;
		}

		// 'min_amount' and 'either' (min_amount OR coupon) reduce to the
		// min-amount check here.
		$min_amount = (float) $method->get_option( 'min_amount', 0 );

		$display_price = 'incl' === get_option( 'woocommerce_tax_display_cart' )
			? (float) wc_get_price_including_tax( $product )
			: (float) wc_get_price_excluding_tax( $product );

		return $display_price >= $min_amount;
	}

	/**
	 * Compute the shipping price via WooCommerce's cart API.
	 *
	 * V5 Shipping::get_shipping_price() parity — kept for shipping
	 * plugins that cannot price a bare package (opt-in via the
	 * `ctxfeed_shipping_use_cart_api` filter). Runs a full add-to-cart +
	 * calculate_totals cycle per product × method; expensive on stores
	 * with cart-aware plugins.
	 *
	 * @since 8.0.0
	 * @since 8.0.9 Renamed from compute_shipping_price(); opt-in only.
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-add-to-cart compat hook. Default null.
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_price_via_cart( array $zone, \WC_Product $product, ?Config $config = null ): string {
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
