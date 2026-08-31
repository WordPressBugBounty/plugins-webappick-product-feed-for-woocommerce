<?php
/**
 * PriceResolver — Resolves all price types with tax and currency formatting.
 *
 * Handles regular, sale, tax-inclusive, and tax-exclusive prices
 * using WooCommerce built-in tax functions. Currency formatting
 * uses WC decimal/thousand separator settings.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-7.1, PROD-FRD-7.2
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Price resolver.
 *
 * @since 8.0.0
 */
class PriceResolver {

	/**
	 * Resolve a price attribute for a product.
	 *
	 * Routes to the appropriate WC price getter based on attribute name.
	 * Returns formatted price string with currency code appended.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-7.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Price attribute name.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Formatted price or empty string.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string {
		$price = $this->get_raw_price( $product, $attr, $config );

		// V5 parity — fire the documented price bridge filters BEFORE the empty
		// guard so discount / dynamic-pricing plugin shims (and any third-party
		// or user code) can adjust the feed price. This runs before format_price
		// so hooks receive/return a RAW numeric value, exactly as V5 did. When
		// no such filter is hooked (the common case) apply_filters returns the
		// value unchanged, so it is a no-op for stores without a discount plugin.
		// Firing before the guard lets a rule fill a slot WooCommerce left empty
		// (e.g. a rule-based sale on a product with no native sale price).
		$price = $this->apply_price_bridge_filter( $price, $product, $attr, $config );

		if ( '' === $price || false === $price || null === $price ) {
			return '';
		}

		return $this->format_price( $price, $config );
	}

	/**
	 * Fire the legacy `woo_feed_filter_product_{attr}` price bridge filter.
	 *
	 * V5 fired a per-price-type filter — woo_feed_filter_product_price,
	 * _regular_price, _sale_price and their _with_tax twins — with the
	 * five-argument signature ($price, $product, $config, $with_tax,
	 * $price_type). The CTX Feed discount / dynamic-pricing compatibility
	 * shims (Flycart Woo Discount Rules, WooCommerce Advanced Discounts,
	 * RightPress Dynamic Pricing & Discounts, AWDP, …) hook these filters to
	 * inject the discounted price into the feed. Replicate the exact contract
	 * here so those shims — and any customer code relying on the documented
	 * hooks — keep working under the V8 engine.
	 *
	 * Only the recognised price attributes participate; anything else is
	 * returned untouched.
	 *
	 * @since 8.0.0
	 * @implements COMPAT — discount plugin price bridge (V5 ProductInfo parity).
	 *
	 * @param mixed       $price   Raw price value (may be '' for e.g. an
	 *                             absent sale price).
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Price attribute name.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Filtered raw price value.
	 */
	private function apply_price_bridge_filter( $price, \WC_Product $product, string $attr, Config $config ) {
		$price_attrs = array(
			'price',
			'regular_price',
			'sale_price',
			'price_with_tax',
			'regular_price_with_tax',
			'sale_price_with_tax',
			'price_excluding_tax',
		);

		if ( ! in_array( $attr, $price_attrs, true ) ) {
			return $price;
		}

		// $with_tax mirrors the V5 boolean flag; $price_type collapses the
		// _with_tax twins back onto their base type (price | regular_price |
		// sale_price) so the shim callbacks receive the same value V5 passed.
		$with_tax = ( false !== strpos( $attr, '_with_tax' ) );

		if ( false !== strpos( $attr, 'regular_price' ) ) {
			$price_type = 'regular_price';
		} elseif ( false !== strpos( $attr, 'sale_price' ) ) {
			$price_type = 'sale_price';
		} else {
			$price_type = 'price';
		}

		/**
		 * Filter a resolved product price before formatting.
		 *
		 * @since 8.0.0
		 *
		 * @param mixed       $price      Raw price value.
		 * @param \WC_Product $product    WooCommerce product.
		 * @param Config      $config     Feed configuration.
		 * @param bool        $with_tax   Whether this is a tax-inclusive price.
		 * @param string      $price_type Base price type: price|regular_price|sale_price.
		 */
		return apply_filters( "woo_feed_filter_product_{$attr}", $price, $product, $config, $with_tax, $price_type );
	}

	/**
	 * Get raw price value from WC product methods.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-7.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Price attribute name.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Raw price value.
	 */
	private function get_raw_price( \WC_Product $product, string $attr, Config $config ) {
		// @implements G-04 — Product-type-aware pricing for variable/grouped.
		if ( $product->is_type( 'variable' ) ) {
			return $this->get_variable_price( $product, $attr, $config );
		}

		if ( $product->is_type( 'grouped' ) ) {
			return $this->get_grouped_price( $product, $attr );
		}

		return $this->get_simple_price( $product, $attr );
	}

	/**
	 * Get price for simple/external products using direct WC getters.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Price attribute name.
	 *
	 * @return mixed Raw price value.
	 */
	private function get_simple_price( \WC_Product $product, string $attr ) {
		switch ( $attr ) {
			case 'price':
				return $product->get_price();

			case 'regular_price':
				return $product->get_regular_price();

			case 'sale_price':
				// CBT-561 parity: a scheduled sale outside its window
				// must not leak the stored sale-price meta into the
				// feed — is_on_sale() is schedule-aware.
				if ( ! $product->is_on_sale() ) {
					return '';
				}
				return $product->get_sale_price();

			case 'price_with_tax':
				return wc_get_price_including_tax( $product );

			case 'regular_price_with_tax':
				$regular = $product->get_regular_price();
				if ( '' === $regular || false === $regular ) {
					return '';
				}
				return wc_get_price_including_tax(
					$product,
					array( 'price' => $regular )
				);

			case 'sale_price_with_tax':
				// Same CBT-561 schedule gate as bare sale_price.
				if ( ! $product->is_on_sale() ) {
					return '';
				}
				$sale = $product->get_sale_price();
				if ( '' === $sale || false === $sale ) {
					return '';
				}
				return wc_get_price_including_tax(
					$product,
					array( 'price' => $sale )
				);

			case 'price_excluding_tax':
				return wc_get_price_excluding_tax( $product );

			default:
				return '';
		}
	}

	/**
	 * Get price for variable products.
	 *
	 * Uses get_variation_prices(true) and returns min, max, or first
	 * variation price based on feed config `variable_price` setting.
	 *
	 * @since 8.0.0
	 * @implements G-04
	 *
	 * @param \WC_Product $product Variable product.
	 * @param string      $attr    Price attribute name.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Raw price value.
	 */
	private function get_variable_price( \WC_Product $product, string $attr, Config $config ) {
		$variation_prices = $product->get_variation_prices( true );

		if ( empty( $variation_prices ) ) {
			return '';
		}

		// Determine price type key for variation_prices array.
		$price_key = 'price';
		if ( in_array( $attr, array( 'regular_price', 'regular_price_with_tax' ), true ) ) {
			$price_key = 'regular_price';
		} elseif ( in_array( $attr, array( 'sale_price', 'sale_price_with_tax' ), true ) ) {
			$price_key = 'sale_price';
		}

		// V5 parity: for variable products, if NO variation is actually on
		// sale (i.e. sale_price array equals regular_price array), the
		// sale_price attribute must be empty. Without this check, Google
		// Merchant flags feeds with sale_price == regular_price as
		// misleading pricing, and V5 customers rely on the empty-string
		// behavior to skip <g:sale_price> altogether when nothing is
		// discounted. V5 VariableProductPrice.php:67-69.
		if ( 'sale_price' === $price_key
			&& isset( $variation_prices['regular_price'] )
			&& $variation_prices['sale_price'] === $variation_prices['regular_price']
		) {
			return '';
		}

		// CBT-561 parity: the sale_price entries in variation_prices are
		// RAW metas, not schedule-aware — an expired/future scheduled
		// sale would leak. WC_Product_Variable::is_on_sale() compares
		// the effective price array against regular_price, which IS
		// schedule-aware (V5 VariableProductPrice parity).
		if ( 'sale_price' === $price_key && ! $product->is_on_sale() ) {
			return '';
		}

		if ( empty( $variation_prices[ $price_key ] ) ) {
			return '';
		}

		$prices = $variation_prices[ $price_key ];

		// Get the base price based on variable_price config (min/max/first).
		$raw = $this->pick_variable_price( $prices, $config );

		if ( '' === $raw || null === $raw ) {
			return '';
		}

		// Apply tax if needed.
		if ( in_array( $attr, array( 'price_with_tax', 'regular_price_with_tax', 'sale_price_with_tax' ), true ) ) {
			return wc_get_price_including_tax( $product, array( 'price' => $raw ) );
		}

		if ( 'price_excluding_tax' === $attr ) {
			return wc_get_price_excluding_tax( $product, array( 'price' => $raw ) );
		}

		return $raw;
	}

	/**
	 * Pick the appropriate price from variation prices array.
	 *
	 * Reads `variable_price` config: 'min' (default), 'max', or 'first'.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $prices Variation prices keyed by variation ID.
	 * @param Config $config Feed configuration.
	 *
	 * @return string Selected price value.
	 */
	private function pick_variable_price( array $prices, Config $config ): string {
		if ( empty( $prices ) ) {
			return '';
		}

		// V5 parity: read the per-feed `variable_price` setting from the
		// config (min | max | first). V5 stored this at
		// $this->config->variable_price and every V5 customer's feed
		// carries it. Without this the V8 default falls back to 'min'
		// regardless of the customer's UI selection — a silent regression.
		// V5 VariableProductPrice.php:64.
		$strategy = (string) $config->get( 'variable_price', 'min' );

		/**
		 * Filter the variable-product price selection strategy. Runs on
		 * top of the config value so extensions can override per-request.
		 *
		 * @since 8.0.0
		 *
		 * @param string  $strategy Price strategy: 'min', 'max', 'first'.
		 * @param Config  $config   Feed configuration.
		 */
		$strategy = apply_filters( 'ctxfeed_variable_price_strategy', $strategy, $config );

		switch ( $strategy ) {
			case 'max':
				return (string) max( $prices );

			case 'first':
				return (string) reset( $prices );

			case 'min':
			default:
				return (string) min( $prices );
		}
	}

	/**
	 * Get price for grouped products.
	 *
	 * Sums prices of visible child products.
	 *
	 * @since 8.0.0
	 * @implements G-04
	 *
	 * @param \WC_Product $product Grouped product.
	 * @param string      $attr    Price attribute name.
	 *
	 * @return mixed Raw price value.
	 */
	private function get_grouped_price( \WC_Product $product, string $attr ) {
		$children = $product->get_children();

		if ( empty( $children ) ) {
			return '';
		}

		$price_key = 'get_price';
		if ( in_array( $attr, array( 'regular_price', 'regular_price_with_tax' ), true ) ) {
			$price_key = 'get_regular_price';
		} elseif ( in_array( $attr, array( 'sale_price', 'sale_price_with_tax' ), true ) ) {
			$price_key = 'get_sale_price';
		}

		$total = 0.0;
		$found = false;

		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child || ! $child->is_visible() ) {
				continue;
			}

			$child_price = $child->$price_key();

			if ( '' !== $child_price && null !== $child_price ) {
				$total += (float) $child_price;
				$found  = true;
			}
		}

		if ( ! $found ) {
			return '';
		}

		// Apply tax if needed.
		if ( in_array( $attr, array( 'price_with_tax', 'regular_price_with_tax', 'sale_price_with_tax' ), true ) ) {
			return wc_get_price_including_tax( $product, array( 'price' => $total ) );
		}

		if ( 'price_excluding_tax' === $attr ) {
			return wc_get_price_excluding_tax( $product, array( 'price' => $total ) );
		}

		return (string) $total;
	}

	/**
	 * Format a raw price with WC decimal/thousand separators.
	 *
	 * Returns the NUMBER ONLY — no currency code. Resolvers are
	 * channel-neutral: currency formatting differs per channel
	 * ("29.99 USD" for Google/Facebook, bare numbers for others), so
	 * it is applied by the channel transforms (GoogleTransform etc.)
	 * via AppendsCurrencyTrait, or by a user-configured suffix.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-7.2
	 *
	 * @param mixed  $price  Raw price value.
	 * @param Config $config Feed configuration.
	 *
	 * @return string Formatted price string (e.g., "29.99").
	 */
	private function format_price( $price, Config $config ): string {
		// V5-compatible keys — user-entered values override WC defaults.
		// Empty strings mean "use WooCommerce default".
		$decimals_raw = $config->get( 'decimals', '' );
		$dec_sep_raw  = $config->get( 'decimal_separator', '' );
		$thou_sep_raw = $config->get( 'thousand_separator', '' );

		$decimals     = ( '' === $decimals_raw || null === $decimals_raw )
			? wc_get_price_decimals()
			: (int) $decimals_raw;
		$decimal_sep  = ( '' === $dec_sep_raw || null === $dec_sep_raw )
			? wc_get_price_decimal_separator()
			: wp_specialchars_decode( wp_unslash( $dec_sep_raw ) );
		$thousand_sep = ( '' === $thou_sep_raw || null === $thou_sep_raw )
			? wc_get_price_thousand_separator()
			: wp_specialchars_decode( wp_unslash( $thou_sep_raw ) );

		return number_format(
			(float) $price,
			$decimals,
			$decimal_sep,
			$thousand_sep
		);
	}
}
