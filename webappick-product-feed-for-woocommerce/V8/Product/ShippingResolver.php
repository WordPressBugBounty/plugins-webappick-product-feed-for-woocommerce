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
use CTXFeed\V8\Utility\WooContext;

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
	 * Per-request memo of the WC shipping zone list, keyed by feed language.
	 *
	 * The service names are the methods' titles, which a multilingual plugin
	 * translates — one process that builds feeds in two languages must not
	 * reuse the first feed's titles (CBT-820).
	 *
	 * @since 8.0.0
	 * @since 8.0.39 Keyed by feed language.
	 * @var array<string,array>
	 */
	private static $zones_memo = array();

	/**
	 * Per-request memo of computed shipping prices.
	 *
	 * Package path: keyed by price|shipping_class|zone|method — shipping
	 * cost depends on what the product costs and how it ships, not on its
	 * identity, so an 11K-product catalog collapses to a few hundred
	 * unique computations. Cart path: keyed by product|zone|method. Both
	 * keys end with the feed's fingerprint — tax country, shipping tax
	 * class, currency and language — so a second feed in the same process
	 * never reuses the first feed's rate (CBT-820).
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
	 * Seconds spent computing shipping prices in the current batch.
	 *
	 * Drives the per-batch time budget: once spent, the batch stops before
	 * its next product and hands the rest to the next batch, instead of
	 * letting shipping resolution run the batch into the web-server kill
	 * window (BUG: a 14-method store died silently at batch 2 — Action
	 * Scheduler "in-progress ≥300s"). Reset per batch by reset_budget().
	 *
	 * @since 8.0.9
	 * @var float
	 */
	private static $budget_spent = 0.0;

	/**
	 * Whether the budget-exhausted line has been logged this batch.
	 *
	 * @since 8.0.9
	 * @var bool
	 */
	private static $budget_warned = false;

	/**
	 * Per-request memo of the store's shipping tax rates, keyed by tax
	 * location and class (CBT-820).
	 *
	 * @since 8.0.39
	 * @var array<string,array>
	 */
	private static $tax_rates_memo = array();

	/**
	 * The method instance our own get_rates_for_package() call is pricing
	 * right now (untaxed_rate_args() acts only on it); null otherwise.
	 *
	 * @since 8.0.39
	 * @var \WC_Shipping_Method|null
	 */
	private static $untaxed_method = null;

	/**
	 * Rate ids untaxed_rate_args() asked for untaxed during that call.
	 *
	 * @since 8.0.39
	 * @var array<string,bool>
	 */
	private static $untaxed_rates = array();

	/**
	 * Running WooCommerce version, read once; null until read.
	 *
	 * Shipping tax follows the running WooCommerce's own rules, which
	 * changed in 10.1 (no standard-rate fallback) and 10.5 (the
	 * `woocommerce_shipping_tax_class` filter). See wc_version_at_least().
	 *
	 * @since 8.0.39
	 * @var string|null
	 */
	private static $wc_version = null;

	/**
	 * Answers of wc_version_at_least(), keyed by version and threshold.
	 *
	 * @since 8.0.39
	 * @var array<string,bool>
	 */
	private static $wc_version_checks = array();

	/**
	 * The class that runs the opt-in cart path (ShippingCartSandbox).
	 *
	 * A property rather than a literal so a test can point it at a missing
	 * class (an update in progress has the file gone or half-written) and
	 * prove the guard in compute_price_via_cart() (CBT-820 review).
	 *
	 * @since 8.0.39
	 * @var string
	 */
	private static $cart_sandbox = '\CTXFeed\V8\Product\ShippingCartSandbox';

	/**
	 * The batch's budget in seconds, read once from the filter; null until read.
	 *
	 * @since 8.0.39
	 * @var float|null
	 */
	private static $budget_limit = null;

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
		self::$zones_memo     = array();
		self::$price_memo     = array();
		self::$method_memo    = array();
		self::$tax_rates_memo = array();
		self::reset_budget();
	}

	/**
	 * Give the next batch a fresh shipping time budget (CBT-820).
	 *
	 * FeedGenerator calls this at the start of every batch. Only the budget
	 * resets: the price, method and zone memos stay for the whole request,
	 * so a batch never recomputes a rate an earlier batch already priced.
	 * Before 8.0.39 the budget lasted the whole request, so on a runner that
	 * processes several batches per request every batch after the first
	 * shipped `0.00` (false free shipping) for its products.
	 *
	 * @since 8.0.39
	 *
	 * @return void
	 */
	public static function reset_budget(): void {
		self::$budget_spent  = 0.0;
		self::$budget_warned = false;
		self::$budget_limit  = null;
	}

	/**
	 * Whether the current batch has used up its shipping time budget.
	 *
	 * FeedGenerator asks between products, the way it checks its time box:
	 * once this is true the batch stops before the next product and the
	 * remaining ids run in the next batch, which starts with a fresh budget.
	 * A product is never cut off halfway, so the budget can no longer
	 * produce an unpriced `0.00` entry (owner decision, CBT-820).
	 *
	 * @since 8.0.39
	 *
	 * @return bool True when the batch should hand over to the next one.
	 */
	public static function budget_exhausted(): bool {
		$limit = self::budget_limit();

		if ( $limit <= 0.0 || self::$budget_spent < $limit ) {
			return false;
		}

		if ( ! self::$budget_warned ) {
			self::$budget_warned = true;
			Logger::info(
				'Shipping price time budget used up — the batch hands its remaining products to the next batch.',
				array(
					'budget_sec' => $limit,
					'spent_sec'  => round( self::$budget_spent, 2 ),
				)
			);
		}

		return true;
	}

	/**
	 * The shipping time budget in seconds for the current batch.
	 *
	 * @since 8.0.39
	 *
	 * @return float Seconds; 0 or less means no shipping budget.
	 */
	private static function budget_limit(): float {
		if ( null === self::$budget_limit ) {
			/**
			 * Filter the shipping-price time budget in seconds per batch.
			 *
			 * Once shipping computations in a batch have used the budget,
			 * the batch stops before its next product and the remaining
			 * products run in the next batch (fresh budget). 0 or less
			 * turns the budget off; the batch time box still applies.
			 *
			 * @since 8.0.9
			 * @since 8.0.39 Per batch, and spending it hands the rest to the
			 *               next batch instead of emitting unpriced entries;
			 *               0 turns it off instead of skipping every price.
			 *
			 * @param float $budget Seconds. Default 20.
			 */
			self::$budget_limit = (float) apply_filters( 'ctxfeed_shipping_time_budget', 20.0 );
		}

		return self::$budget_limit;
	}

	/**
	 * Whether a feed's Shipping/Tax country scope includes every country.
	 *
	 * V5 parity (GoogleShipping / GoogleTax / CustomTax): 'all' and 'feed'
	 * are explicit; an empty or unknown value falls back to the global
	 * `allow_all_shipping` setting (default 'no'). Implements CBT-728.
	 *
	 * @since 8.0.33
	 *
	 * @param string $scope Feed value: 'all', 'feed' or ''.
	 * @return bool True to include every country's rates.
	 */
	public static function all_countries( string $scope ): bool {
		if ( 'all' === $scope ) {
			return true;
		}
		if ( 'feed' === $scope ) {
			return false;
		}

		$settings = get_option( 'woo_feed_settings', array() );

		return is_array( $settings ) && isset( $settings['allow_all_shipping'] ) && 'yes' === $settings['allow_all_shipping'];
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
		// Shipping rates carry tax, and WooCommerce resolves the tax
		// location through the customer, which calls a cart helper that is
		// not loaded on cron/REST requests (CBT-631). Feed runs already do
		// this once per batch; this covers the preview/REST callers too.
		WooContext::ensure_cart_functions();

		$zones = $this->get_shipping_zones( $config->get_feed_language() );

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
	 * Caches the result per request and feed language to avoid repeated DB
	 * queries.
	 *
	 * @since 8.0.0
	 * @since 8.0.39 Memoised per feed language (CBT-820).
	 *
	 * @param string $language Feed language (Config::get_feed_language()), '' when none.
	 * @return array Array of zone data with locations and methods.
	 */
	private function get_shipping_zones( string $language = '' ): array {
		// Per-request memoization only — avoids persistent-object-cache
		// pitfalls where adding/editing a zone doesn't flush stale data.
		// Static property (not a method-local static) so long-running
		// processes and tests can reset it via flush_runtime_memo(). Keyed
		// by language: the service names are translatable method titles.
		if ( isset( self::$zones_memo[ $language ] ) ) {
			return self::$zones_memo[ $language ];
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

		self::$zones_memo[ $language ] = $zones;

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
	 * @since 8.0.39 Takes the product's pricing context (CBT-820).
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-pricing compat hook.
	 * @param array       $context Pricing context from pricing_context().
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_shipping_price( array $zone, \WC_Product $product, ?Config $config, array $context ): string {
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
			return $this->compute_price_via_cart( $zone, $product, $config, $context );
		}

		return $this->compute_price_via_package( $zone, $product, $config, $context );
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
	 * + method) plus the feed fingerprint, so products sharing a price
	 * point reuse the computation.
	 *
	 * @since 8.0.9
	 * @since 8.0.39 Never cut off by the time budget (the batch hands over
	 *               between products instead); memo key carries the feed
	 *               fingerprint (CBT-820).
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-pricing compat hook.
	 * @param array       $context Pricing context from pricing_context().
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_price_via_package( array $zone, \WC_Product $product, ?Config $config, array $context ): string {
		// V5 parity: variations price as themselves (their own price and
		// shipping class — the old cart path added the variable PARENT,
		// which always fails add_to_cart and silently emitted 0.00).
		// Grouped products price as their first child (V5 Shipping.php),
		// resolved once per product in pricing_context().
		$product = $context['product'];
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		// Virtual/downloadable products never ship — the cart path ended
		// at 0.00 for them (no shippable package); keep that contract.
		if ( ! $product->needs_shipping() ) {
			return '';
		}

		$price    = (float) wc_get_price_excluding_tax( $product );
		$class_id = (int) $product->get_shipping_class_id();

		$memo = &self::$price_memo;

		$memo_key = 'pkg|' . $price . '|' . $class_id . '|' . $zone['zone_id'] . '|' . $zone['method_id'] . '|' . $zone['method_instance_id'] . '|' . $zone['country'] . '|' . $zone['region'] . '|' . $context['fingerprint'];

		if ( isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		// No budget check here: the batch checks budget_exhausted() between
		// products and hands the rest to the next batch, so every product
		// it writes is fully priced (CBT-820). The time spent still counts.
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
			$result = $this->price_package_for_method( $zone, $product, $price, $context );
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
	 * @since 8.0.39 Shipping tax is computed for the feed country, not
	 *               taken from whichever customer the runner has (CBT-820).
	 *
	 * @param array       $zone    Zone struct.
	 * @param \WC_Product $product Product to price (already child/variation-resolved).
	 * @param float       $price   Product price excluding tax.
	 * @param array       $context Pricing context from pricing_context().
	 *
	 * @return string Numeric string or empty string on failure.
	 */
	private function price_package_for_method( array $zone, \WC_Product $product, float $price, array $context ): string {
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

		// Some methods read the customer's address; pin it to the zone and
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

		// WooCommerce taxes a rate only when the request has a customer
		// object, which depends on the runner: WP-Cron and REST batches had
		// none (net), Action Scheduler's admin-ajax runner had the admin's
		// (gross, or a fatal when the cart was missing). Ask the method for
		// its untaxed cost instead (untaxed_rate_args()) and add the feed
		// country's shipping tax below — the same price on every runner
		// (owner decision, CBT-820). With taxes off WooCommerce taxes
		// nothing on any runner, so there is nothing to take over.
		// Registered once and left in place (inert between calls); checked
		// every time so a listener reset can never silently drop it.
		if ( $context['taxes_on'] ) {
			if ( false === has_filter( 'woocommerce_shipping_method_add_rate_args', array( self::class, 'untaxed_rate_args' ) ) ) {
				add_filter( 'woocommerce_shipping_method_add_rate_args', array( self::class, 'untaxed_rate_args' ), PHP_INT_MAX, 2 );
			}
			self::$untaxed_method = $method;
			self::$untaxed_rates  = array();
		}

		try {
			$rates = $method->get_rates_for_package( $package );
		} finally {
			$untaxed              = self::$untaxed_rates;
			self::$untaxed_method = null;
			self::$untaxed_rates  = array();
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

		// add_rate() stores each rate under its unfiltered id — the id the
		// args filter above saw (get_id() runs a filter of its own).
		$rate_key = (string) key( $rates );
		$cost     = (float) $rate->get_cost();

		if ( isset( $untaxed[ $rate_key ] ) ) {
			$cost += $this->feed_shipping_tax( $method, $cost, $context );
		} else {
			$taxes = $rate->get_taxes();
			if ( is_array( $taxes ) ) {
				$cost += (float) array_sum( $taxes );
			}
		}

		return number_format( $cost, 2, '.', '' );
	}

	/**
	 * `woocommerce_shipping_method_add_rate_args` listener: while one of our
	 * own get_rates_for_package() calls runs, ask that method instance for
	 * its untaxed cost (CBT-820).
	 *
	 * Registered on first use and inert outside that call. Only rates
	 * WooCommerce would tax itself are changed (taxes '' → false) and
	 * recorded; a rate the method taxes itself (explicit array) or marks
	 * untaxed (false) is left as the method made it.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $args        add_rate() arguments.
	 * @param mixed $rate_method The method instance adding the rate.
	 * @return mixed The arguments.
	 */
	public static function untaxed_rate_args( $args, $rate_method = null ) {
		if ( null === self::$untaxed_method || $rate_method !== self::$untaxed_method || ! is_array( $args ) ) {
			return $args;
		}

		$taxes = isset( $args['taxes'] ) ? $args['taxes'] : '';

		if ( ! is_array( $taxes ) && false !== $taxes ) {
			$args['taxes'] = false;

			self::$untaxed_rates[ (string) ( isset( $args['id'] ) ? $args['id'] : '' ) ] = true;
		}

		return $args;
	}

	/**
	 * Shipping tax on one rate for the feed's tax country.
	 *
	 * Mirrors what WooCommerce adds at checkout for a shopper in the feed
	 * country (WC_Shipping_Method::add_rate()): taxes on, a taxable method,
	 * a positive cost, and the store's shipping tax rates for that country
	 * and class. A country with no matching rate row (most US/CA stores at
	 * country level) adds nothing, so those feeds stay net.
	 *
	 * @since 8.0.39
	 *
	 * @param \WC_Shipping_Method $method  Method instance that produced the rate.
	 * @param float               $cost    Untaxed rate cost.
	 * @param array               $context Pricing context from pricing_context().
	 *
	 * @return float Tax to add (0 when nothing applies).
	 */
	private function feed_shipping_tax( \WC_Shipping_Method $method, float $cost, array $context ): float {
		if ( $cost <= 0 || null === $context['tax_class'] || 4 !== count( $context['tax_location'] ) ) {
			return 0.0;
		}

		if ( ! isset( $method->tax_status ) || 'taxable' !== $method->tax_status ) {
			return 0.0;
		}

		if ( ! is_callable( array( '\WC_Tax', 'find_shipping_rates' ) ) || ! is_callable( array( '\WC_Tax', 'calc_shipping_tax' ) ) ) {
			return 0.0;
		}

		// One lookup per location and class per request: a feed has one
		// location and a handful of classes, but thousands of rates to tax.
		$rates_key = implode( ':', $context['tax_location'] ) . '|' . $context['tax_class'];

		if ( ! isset( self::$tax_rates_memo[ $rates_key ] ) ) {
			list( $country, $state, $postcode, $city ) = $context['tax_location'];

			$location = array(
				'country'  => $country,
				'state'    => $state,
				'postcode' => $postcode,
				'city'     => $city,
			);

			$found = \WC_Tax::find_shipping_rates( $location + array( 'tax_class' => $context['tax_class'] ) );
			$found = is_array( $found ) ? $found : array();

			// WooCommerce before 10.1 charges the standard rate when the
			// class has no shipping rate row (get_shipping_tax_rates():
			// "Get standard rate if no taxes were found"); 10.1+ charges
			// none. Mirror the running version, as the checkout does.
			if ( empty( $found ) && '' !== $context['tax_class'] && ! self::wc_version_at_least( '10.1.0' ) ) {
				$found = \WC_Tax::find_shipping_rates( $location );
				$found = is_array( $found ) ? $found : array();
			}

			self::$tax_rates_memo[ $rates_key ] = $found;
		}

		$rates = self::$tax_rates_memo[ $rates_key ];

		if ( empty( $rates ) ) {
			return 0.0;
		}

		$taxes = \WC_Tax::calc_shipping_tax( $cost, $rates );

		// WooCommerce 10.6+ can treat entered shipping costs as tax-inclusive;
		// the entered cost is then already the gross price.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read the way WC_Shipping_Method::add_rate() reads it.
		$inclusive = function_exists( 'wc_string_to_bool' ) && wc_string_to_bool( apply_filters( 'woocommerce_shipping_prices_include_tax', false ) );

		if ( $inclusive || ! is_array( $taxes ) ) {
			return 0.0;
		}

		return (float) array_sum( $taxes );
	}

	/**
	 * Everything about a product that stays the same across its zones.
	 *
	 * Computed once per product and resolution:
	 * - `product`: the product that is priced (a grouped product ships as
	 *   its first child, V5 Shipping.php), or null when it has none;
	 * - `taxes_on`: WooCommerce taxes are enabled (wc_tax_enabled());
	 * - `tax_class` / `tax_location`: the shipping tax class and the feed's
	 *   tax location (see shipping_tax_class(), shipping_tax_location());
	 * - `fingerprint`: the feed facts that change a rate without changing
	 *   the product — tax location, tax class, currency and language — so
	 *   two feeds in one process never share a memoised rate (CBT-820).
	 *
	 * @since 8.0.39
	 *
	 * @param \WC_Product $product  Product being resolved.
	 * @param Config      $config   Feed configuration.
	 * @param string      $currency Resolved feed currency.
	 *
	 * @return array{product: \WC_Product|null, taxes_on: bool, tax_class: string|null, tax_location: array, fingerprint: string}
	 */
	private function pricing_context( \WC_Product $product, Config $config, string $currency ): array {
		$priced = $product;

		if ( $product->is_type( 'grouped' ) ) {
			$children = $product->get_children();
			$child    = ! empty( $children ) ? wc_get_product( reset( $children ) ) : false;
			$priced   = $child instanceof \WC_Product ? $child : null;
		}

		$taxes_on  = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
		$tax_class = $taxes_on && null !== $priced ? $this->shipping_tax_class( $priced ) : null;
		$location  = null !== $tax_class ? $this->shipping_tax_location( $config, $tax_class ) : array();
		$tax_class = null !== $tax_class ? self::filter_shipping_tax_class( $tax_class, $location ) : null;

		return array(
			'product'      => $priced,
			'taxes_on'     => $taxes_on,
			'tax_class'    => $tax_class,
			'tax_location' => $location,
			'fingerprint'  => implode( ':', $location ) . '|' . ( null === $tax_class ? '-' : $tax_class ) . '|' . $currency . '|' . $config->get_feed_language(),
		);
	}

	/**
	 * The tax class WooCommerce would tax this product's shipping with.
	 *
	 * Resolved explicitly instead of through WC_Tax::get_shipping_tax_rates(),
	 * which reads the class from WC()->cart (absent on cron/REST; a fatal
	 * there). The store's "Shipping tax class" setting wins; "based on cart
	 * items" uses the product's own class, and a product whose shipping is
	 * not taxable gets none — exactly what the cart does for this product
	 * alone. Called only when WooCommerce taxes are enabled.
	 *
	 * @since 8.0.39
	 *
	 * @param \WC_Product $product Priced product.
	 *
	 * @return string|null Tax class ('' = standard), or null for no shipping tax.
	 */
	private function shipping_tax_class( \WC_Product $product ): ?string {
		$option = get_option( 'woocommerce_shipping_tax_class' );

		if ( 'inherit' !== $option ) {
			return (string) $option;
		}

		if ( ! $product->is_shipping_taxable() ) {
			return null;
		}

		return (string) $product->get_tax_class();
	}

	/**
	 * WooCommerce 10.5+'s `woocommerce_shipping_tax_class` filter, applied
	 * the way WC_Tax::get_shipping_tax_rates() applies it at checkout.
	 *
	 * A feed has no cart and no customer, so both arguments are null — the
	 * filter documents both as nullable. A listener returning null means no
	 * shipping tax. Older WooCommerce has no such filter, so it is not
	 * applied there (a listener would not change the checkout either). A
	 * listener that throws (one that assumes a cart object) keeps the core
	 * class instead of failing the product (CBT-820 review).
	 *
	 * @since 8.0.39
	 *
	 * @param string $tax_class Shipping tax class from shipping_tax_class().
	 * @param array  $location  Tax location [ country, state, postcode, city ] or [].
	 *
	 * @return string|null Tax class, or null for no shipping tax.
	 */
	private static function filter_shipping_tax_class( string $tax_class, array $location ): ?string {
		if ( ! self::wc_version_at_least( '10.5.0' ) ) {
			return $tax_class;
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied the way WC_Tax::get_shipping_tax_rates() applies it.
			$filtered = apply_filters( 'woocommerce_shipping_tax_class', $tax_class, null, null, $location );
		} catch ( \Throwable $e ) {
			return $tax_class;
		}

		if ( null === $filtered ) {
			return null;
		}

		return is_scalar( $filtered ) ? (string) $filtered : $tax_class;
	}

	/**
	 * Whether the running WooCommerce is at least `$version`.
	 *
	 * Read from WC_VERSION once per request. An unknown version counts as
	 * current: the newest rules apply.
	 *
	 * @since 8.0.39
	 *
	 * @param string $version Release, e.g. '10.1.0'.
	 *
	 * @return bool
	 */
	private static function wc_version_at_least( string $version ): bool {
		if ( null === self::$wc_version ) {
			self::$wc_version = defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '';
		}

		// Asked once per product: answer each question once per version.
		$key = self::$wc_version . '>=' . $version;

		if ( ! isset( self::$wc_version_checks[ $key ] ) ) {
			// '-dev' so a 10.1.0 beta or RC counts as 10.1.0.
			self::$wc_version_checks[ $key ] = '' === self::$wc_version || version_compare( self::$wc_version, $version . '-dev', '>=' );
		}

		return self::$wc_version_checks[ $key ];
	}

	/**
	 * The location a feed's shipping tax is computed for.
	 *
	 * The feed country — the same country, and the same
	 * `ctxfeed_feed_tax_country` filter, that FeedTaxLocation uses for the
	 * feed's product prices. With no country (a feed saved before country
	 * was required, or a listener returning ''), what WooCommerce resolves
	 * for a visitor with no address: the shop base when prices include tax
	 * or tax is based on the shop address, otherwise no location (no tax).
	 *
	 * @since 8.0.39
	 *
	 * @param Config $config    Feed configuration.
	 * @param string $tax_class Shipping tax class.
	 *
	 * @return array [ country, state, postcode, city ], or [] for none.
	 */
	private function shipping_tax_location( Config $config, string $tax_class ): array {
		/** This filter is documented in V8/Product/FeedTaxLocation.php */
		$country = (string) apply_filters( 'ctxfeed_feed_tax_country', (string) $config->get( 'feed_country', '' ), $tax_class );

		if ( '' !== $country ) {
			return array( $country, '', '', '' );
		}

		$base = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() )
			|| 'base' === get_option( 'woocommerce_default_customer_address' )
			|| 'base' === get_option( 'woocommerce_tax_based_on' );

		$countries = $base && function_exists( 'WC' ) && WC() && isset( WC()->countries ) ? WC()->countries : null;

		if ( ! is_object( $countries ) || ! is_callable( array( $countries, 'get_base_country' ) ) ) {
			return array();
		}

		return array(
			(string) $countries->get_base_country(),
			is_callable( array( $countries, 'get_base_state' ) ) ? (string) $countries->get_base_state() : '',
			is_callable( array( $countries, 'get_base_postcode' ) ) ? (string) $countries->get_base_postcode() : '',
			is_callable( array( $countries, 'get_base_city' ) ) ? (string) $countries->get_base_city() : '',
		);
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
	 * Since 8.0.39 the cycle runs on a throwaway guest cart, customer and
	 * session (ShippingCartSandbox): the request's own objects — the
	 * admin's live cart on an admin-ajax or REST runner — are never loaded,
	 * emptied or saved, and the result no longer depends on which customer
	 * the runner had. Tax is computed for the feed's tax location, as on
	 * the package path (CBT-820).
	 *
	 * @since 8.0.0
	 * @since 8.0.9 Renamed from compute_shipping_price(); opt-in only.
	 * @since 8.0.39 Isolated guest cart; feed-country tax location.
	 *
	 * @param array       $zone    Zone struct with country/state/method_id/method_instance_id.
	 * @param \WC_Product $product Product to price.
	 * @param Config|null $config  Feed configuration, forwarded to the pre-add-to-cart compat hook.
	 * @param array       $context Pricing context from pricing_context().
	 *
	 * @return string Numeric string (e.g. "5.00") or empty string on failure.
	 */
	private function compute_price_via_cart( array $zone, \WC_Product $product, ?Config $config, array $context ): string {
		$memo = &self::$price_memo;

		// Memo key: product + zone + method + feed fingerprint. Needed because
		// feed generation may resolve shipping for thousands of products.
		$pid      = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$memo_key = $pid . '|' . $zone['zone_id'] . '|' . $zone['method_id'] . '|' . $zone['method_instance_id'] . '|' . $zone['country'] . '|' . $zone['region'] . '|' . $context['fingerprint'];

		if ( isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		if ( ! defined( 'WC_ABSPATH' ) || ! function_exists( 'WC' ) || ! WC() ) {
			$memo[ $memo_key ] = '';
			return '';
		}

		// WooCommerce skips its cart helpers on REST / cron / Action
		// Scheduler requests; the cart and customer call them.
		WooContext::ensure_cart_functions();

		// Every tax lookup inside the cycle resolves to the feed's tax
		// location — what FeedTaxLocation does for a batch, here for every
		// caller — so the cart's shipping tax matches the package path.
		$location     = $context['tax_location'];
		$pin_location = static function () use ( $location ) {
			return $location;
		};

		add_filter( 'woocommerce_get_tax_location', $pin_location, PHP_INT_MAX );

		// Mid-update rule: the sandbox file can be gone or half-written while
		// WordPress replaces the plugin. class_exists() loads it, and a
		// half-written file throws there — both mean "cannot price here".
		$sandbox = self::$cart_sandbox;

		try {
			if ( ! class_exists( $sandbox ) || ! is_callable( array( $sandbox, 'run' ) ) ) {
				$memo[ $memo_key ] = '';
				return '';
			}

			$result = call_user_func(
				array( $sandbox, 'run' ),
				static function ( \WC_Cart $cart, \WC_Customer $customer, \WC_Session $session ) use ( $zone, $pid, $config ): string {
					// A guest at the zone's destination; Rest of the World has
					// no address at all.
					$customer->set_shipping_country( ! empty( $zone['country'] ) ? $zone['country'] : '' );
					$customer->set_shipping_state( ! empty( $zone['region'] ) ? $zone['region'] : '' );

					// Pin the chosen shipping method to this zone+method combination.
					$session->set( 'chosen_shipping_methods', array( $zone['method_id'] . ':' . $zone['method_instance_id'] ) );

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

					$cart->add_to_cart( $pid, 1 );

					// What the cart's own woocommerce_add_to_cart listener does
					// on a real cart (the sandbox has its hooks removed): line
					// totals first, which the shipping packages' contents cost
					// is read from.
					$cart->calculate_totals();

					// Force shipping calculation. Feed generation runs in a
					// background context (Action Scheduler / cron / REST), and
					// there add_to_cart does NOT recompute shipping the way a
					// front-end request does — so get_shipping_total() would
					// stay 0.00 even with a flat rate configured.
					if ( method_exists( $cart, 'calculate_shipping' ) ) {
						$cart->calculate_shipping();
					}
					$cart->calculate_totals();

					$cost  = (float) $cart->get_shipping_total();
					$cost += (float) $cart->get_shipping_tax();

					return number_format( $cost, 2, '.', '' );
				}
			);
		} catch ( \Throwable $e ) {
			$result = '';
		} finally {
			remove_filter( 'woocommerce_get_tax_location', $pin_location, PHP_INT_MAX );
		}

		$result = is_string( $result ) ? $result : '';

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
		$context  = null;

		foreach ( $zones as $zone ) {
			if ( null === $context ) {
				$context = $this->pricing_context( $product, $config, $currency );
			}

			// Price the zone's method for this product (package path by
			// default, cart API when opted in).
			$price = $this->compute_shipping_price( $zone, $product, $config, $context );

			// Keep zones whose price couldn't be computed — emit with "0.00"
			// so merchants can still see the zone in the feed and diagnose.
			// Since 8.0.39 the time budget never lands here: the batch hands
			// over between products instead (CBT-820). What remains is a
			// method that cannot price this product (virtual, grouped with
			// no child, an unmet free_shipping rule, a cart-reading method).
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
	 * Mirrors the feed-currency key order (feed_currency / feedCurrency / currency) so the shipping price currency
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

		// V5 parity (GoogleShipping::get_csv): 'all' → every zone, 'feed' →
		// feed-country zones only, empty → the global `allow_all_shipping`
		// setting decides (CBT-728). V5 feeds were saved with an empty value.
		if ( self::all_countries( (string) $shipping_country ) ) {
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
