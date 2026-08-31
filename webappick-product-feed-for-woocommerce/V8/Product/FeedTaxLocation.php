<?php
/**
 * FeedTaxLocation — feed-country tax location for feed generation.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces the WooCommerce tax location to the feed's target country while a
 * feed's product loop is running.
 *
 * The "Price with Tax" attributes call `wc_get_price_including_tax()`, whose
 * rate depends on `WC_Tax::get_tax_location()`. On the storefront that
 * location comes from the shopper's session address. A feed has no single
 * shopper — and every feed targets exactly ONE country (the required
 * `feed_country`), so tax must reflect THAT market. During a background
 * (cron) run WooCommerce would otherwise resolve an EMPTY location and drop
 * the tax to 0.00; V5 asked merchants to set a default store location by
 * hand to work around it.
 *
 * This overrides the tax location with the feed's country for the duration
 * of the product loop, so tax-inclusive feed prices are computed as a
 * shopper in the destination country would see them — automatically, whether
 * the run is via cron or an admin "Generate now" (whose ambient location
 * would otherwise be the admin's own address). When the store has no tax
 * rate configured for the feed country, WooCommerce simply matches nothing
 * and the price is emitted WITHOUT tax (product decision — we never invent a
 * tax the merchant did not configure). Inert outside feed generation, so
 * cart and checkout are untouched.
 *
 * @since 8.0.0
 */
class FeedTaxLocation {

	/**
	 * Whether a feed product loop is currently running.
	 *
	 * @since 8.0.0
	 * @var bool
	 */
	private $active = false;

	/**
	 * The current feed's target country (ISO code), captured on activate().
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $feed_country = '';

	/**
	 * Register the WooCommerce filter and the feed-loop activation hooks.
	 *
	 * The filter stays registered for the request but is inert unless a feed
	 * loop is active, so it can never affect storefront tax calculation.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_tax_location', array( $this, 'ensure_feed_location' ), 20, 2 );
		// Fired with ( $ids, $feed_rules, $config ) — we read feed_country from $config.
		add_action( 'woo_feed_before_product_loop', array( $this, 'activate' ), 10, 3 );
		// FeedGenerator fires this from a finally, so it runs on every exit
		// path (including a thrown row) — the flag can never leak past a batch.
		add_action( 'woo_feed_after_product_loop', array( $this, 'deactivate' ) );
		// Redundant safety net at batch end.
		add_action( 'ctxfeed_after_generate_batch', array( $this, 'deactivate' ) );
	}

	/**
	 * Mark the feed product loop as running and capture its target country.
	 *
	 * @since 8.0.0
	 *
	 * @param array $ids        Product IDs in the batch (unused).
	 * @param array $feed_rules Raw feedrules array (unused).
	 * @param mixed $config     Feed Config object; source of `feed_country`.
	 *
	 * @return void
	 */
	public function activate( $ids = array(), $feed_rules = array(), $config = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $ids/$feed_rules are part of the woo_feed_before_product_loop signature.
		$this->feed_country = ( $config && method_exists( $config, 'get' ) )
			? (string) $config->get( 'feed_country', '' )
			: '';
		$this->active       = true;
	}

	/**
	 * Mark the feed product loop as finished.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->active       = false;
		$this->feed_country = '';
	}

	/**
	 * Override the tax location with the feed's target country during a run.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed  $location  [ country, state, postcode, city ] from WooCommerce.
	 * @param string $tax_class Tax class being resolved.
	 *
	 * @return array The feed-country location during a feed run, else unchanged.
	 */
	public function ensure_feed_location( $location, $tax_class = '' ): array {
		$location = (array) $location;

		// Inert outside feed generation → storefront tax is never touched.
		if ( ! $this->active ) {
			return $location;
		}

		/**
		 * Filter the country whose tax rate a feed's prices use.
		 *
		 * Defaults to the feed's `feed_country`. Return '' to disable the
		 * override entirely (fall back to WooCommerce's own resolution).
		 *
		 * @since 8.0.0
		 *
		 * @param string $feed_country The feed's target country ISO code.
		 * @param string $tax_class    The tax class being resolved.
		 */
		$country = (string) apply_filters( 'ctxfeed_feed_tax_country', $this->feed_country, $tax_class );

		if ( '' === $country ) {
			return $location;
		}

		// Country-level only (no state/postcode/city). Correct for national
		// VAT markets; US state-specific rates aren't resolvable from a
		// country code, but US Google feeds use tax-exclusive prices anyway.
		return array( $country, '', '', '' );
	}
}
