<?php
/**
 * TaxResolver — Resolves dynamic tax data from WooCommerce tax rates.
 *
 * Reads WC_Tax to build tax entries per product based on tax class.
 * Returns arrays for XML feeds (GroupedAttributeBuilder nests them
 * under g:tax) or composite strings for CSV feeds.
 *
 * Ported from V5 GoogleTax.php (124 lines).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements G-02, S-03
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tax resolver.
 *
 * @since 8.0.0
 */
class TaxResolver {

	/**
	 * Cache group.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const CACHE_GROUP = 'ctxfeed';

	/**
	 * Per-request memo of WC tax rates per tax class.
	 *
	 * @since 8.0.0
	 * @var array<string,array>
	 */
	private static $rates_memo = array();

	/**
	 * Reset the per-request memo.
	 *
	 * For long-running processes (WP-CLI, tests) where tax rates change
	 * mid-process.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public static function flush_runtime_memo(): void {
		self::$rates_memo = array();
	}

	/**
	 * Resolve tax data for a product.
	 *
	 * Returns an array of tax entries for XML or a composite string for CSV.
	 * Each entry contains country, region, rate, and tax_ship fields.
	 *
	 * @since 8.0.0
	 * @implements G-02
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Attribute name (tax, tax_country, etc.).
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Array of tax entries (XML) or composite string (CSV).
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ) {
		// Get the product's tax class. WC uses empty string for 'standard'.
		$tax_class = $product->get_tax_class();

		if ( '' === $tax_class ) {
			$tax_class = 'standard';
		}

		$rates   = $this->get_tax_rates( $tax_class );
		$entries = $this->build_tax_entries( $rates, $product, $config );
		$entries = $this->filter_by_config( $entries, $config );

		/**
		 * Filter resolved tax entries.
		 *
		 * @since 8.0.0
		 *
		 * @param array       $entries Tax entries.
		 * @param \WC_Product $product WooCommerce product.
		 * @param Config      $config  Feed configuration.
		 */
		$entries = apply_filters( 'ctxfeed_tax_entries', $entries, $product, $config );

		// Determine output format based on feed type.
		$feed_type = strtolower( $config->get( 'feedType', 'xml' ) );

		if ( in_array( $feed_type, array( 'csv', 'tsv', 'txt' ), true ) ) {
			return $this->format_for_csv( $entries, $config );
		}

		return $entries;
	}

	/**
	 * Get WooCommerce tax rates for a given tax class.
	 *
	 * Caches per tax class to avoid repeated DB queries within a batch.
	 *
	 * @since 8.0.0
	 *
	 * @param string $tax_class WC tax class slug.
	 *
	 * @return array Array of tax rate data.
	 */
	private function get_tax_rates( string $tax_class ): array {
		// Per-request memoization only — avoids persistent-object-cache
		// pitfalls where adding a new tax rate doesn't flush stale data.
		// Static property so long-running processes and tests can reset
		// it via flush_runtime_memo().
		$memo = &self::$rates_memo;
		if ( isset( $memo[ $tax_class ] ) ) {
			return $memo[ $tax_class ];
		}

		$rates    = array();
		$wc_class = 'standard' === $tax_class ? '' : $tax_class;
		$wc_rates = \WC_Tax::get_rates_for_tax_class( $wc_class );

		if ( ! empty( $wc_rates ) ) {
			foreach ( $wc_rates as $rate ) {
				$country      = isset( $rate->tax_rate_country ) ? $rate->tax_rate_country : '';
				$state        = isset( $rate->tax_rate_state ) ? $rate->tax_rate_state : '';
				$rate_value   = isset( $rate->tax_rate ) ? $rate->tax_rate : '0';
				$tax_shipping = isset( $rate->tax_rate_shipping ) ? $rate->tax_rate_shipping : '0';

				$rates[] = array(
					'country'  => $country,
					'state'    => $state,
					'rate'     => number_format( (float) $rate_value, 2, '.', '' ),
					'shipping' => ( '1' === $tax_shipping || 1 === (int) $tax_shipping ) ? 'yes' : 'no',
				);
			}
		}

		$memo[ $tax_class ] = $rates;

		return $rates;
	}

	/**
	 * Build tax entries from WC rate data.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $rates   Raw WC tax rates.
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array Array of tax entry arrays.
	 */
	private function build_tax_entries( array $rates, \WC_Product $product, Config $config ): array {
		$entries = array();

		foreach ( $rates as $rate ) {
			$entry = array(
				'country'  => $rate['country'],
				'region'   => $rate['state'],
				'rate'     => $rate['rate'],
				'tax_ship' => $rate['shipping'],
			);

			/**
			 * Filter individual tax entry.
			 *
			 * @since 8.0.0
			 *
			 * @param array       $entry   Tax entry.
			 * @param array       $rate    Raw rate data.
			 * @param \WC_Product $product WooCommerce product.
			 * @param Config      $config  Feed configuration.
			 */
			$entries[] = apply_filters( 'ctxfeed_tax_entry', $entry, $rate, $product, $config );
		}

		return $entries;
	}

	/**
	 * Filter tax entries by feed configuration.
	 *
	 * Applies feed_country and allow_all_tax config filters.
	 * Matches V5 GoogleTax filtering logic.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $entries Raw tax entries.
	 * @param Config $config  Feed configuration.
	 *
	 * @return array Filtered tax entries.
	 */
	private function filter_by_config( array $entries, Config $config ): array {
		$feed_country = $config->get( 'feed_country', '' );
		$tax_country  = $config->get( 'tax_country', '' );

		// Per-feed value is authoritative. The global `allow_all_shipping`
		// setting is intentionally NOT consulted here — the Filter tab is
		// the single source of truth.
		//
		// - tax_country === 'all'  → include all zones
		// - tax_country === 'feed' → strict match vs feed_country
		// - empty / unknown        → default to 'feed' behaviour.
		if ( 'all' === $tax_country ) {
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
	 * Format tax entries as CSV composite strings.
	 *
	 * Returns colon-separated composite: "US:CA:8.50:yes"
	 * Multiple entries are separated by commas.
	 *
	 * @since 8.0.0
	 * @implements S-03
	 *
	 * @param array  $entries Tax entries.
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
					$entry['rate'],
					$entry['tax_ship'],
				) 
			);
		}

		return implode( ',', $composites );
	}
}
