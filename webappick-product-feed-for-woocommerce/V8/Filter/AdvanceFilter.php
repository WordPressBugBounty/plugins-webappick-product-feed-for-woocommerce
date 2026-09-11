<?php
/**
 * AdvanceFilter — Pro-gated condition-based product filter.
 *
 * Direct port of V5 `AdvanceFilter::filter_product()` semantics. Reads the
 * existing V5 storage keys (`fattribute`, `condition`, `filterCompare`,
 * `concatType`, `filterType`) so 70K+ Pro-customer feed configurations work
 * unchanged after V5→V8 migration.
 *
 * Storage shape (V5-compat — DO NOT change):
 *   fattribute    : ['title', 'pa_color', 'price', ...]
 *   condition     : ['contains', '==', '>=', ...]   // operator code
 *   filterCompare : ['Sneakers', 'red', '50', ...]  // value to compare against
 *   concatType    : ['AND', 'OR', 'AND', ...]       // joiner with previous row
 *   filterType    : 1 | 2                           // global default (1=OR, 2=AND)
 *
 * Evaluation semantics (V5-verbatim):
 *   - AND rows: any failing row immediately rejects the product.
 *   - OR rows: at least one OR row must pass for the product to pass.
 *   - Pre-v5.2.25 rows have no `concatType` → fall back to global filterType.
 *   - Empty config → pass-through (no-op).
 *
 * Operator vocabulary (V5-verbatim):
 *   == != >= > <= <  contains  nContains  between
 *
 * The "more logical" V8 UI presents friendlier labels on top of these codes.
 * The codes themselves stay V5-compat so docs, support history, and the
 * `ctxfeed_dropdown_conditions` filter all keep working.
 *
 * Pro is enforced in the admin UI (Free users can't store advanced rules). At
 * runtime the stored rules ALWAYS apply — even if the license later lapses — so
 * a live feed never silently stops excluding products (BUG-0044). Mirrors
 * Attribute Mapping / Dynamic Attribute, which also resolve regardless of gate.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-9.1, FLTR-FRD-9.2, FLTR-FRD-9.3, FLTR-FRD-9.4
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\AttributeResolver;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced (condition-based) filter — Pro feature, V5-compatible storage.
 *
 * @since 8.0.0
 */
class AdvanceFilter implements FilterInterface {

	/**
	 * Attribute resolver — used to fetch the product's value for a given
	 * V5 attribute key (title, pa_*, wf_attr_*, wf_cattr_*, etc.).
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver;

	/**
	 * Constructor.
	 *
	 * AttributeResolver is optional so the filter can be instantiated
	 * standalone (e.g. for tests). When null, value resolution falls
	 * back to direct WC product getters for the most common V5 keys.
	 *
	 * @since 8.0.0
	 *
	 * @param AttributeResolver|null $attribute_resolver Attribute resolver.
	 */
	public function __construct( ?AttributeResolver $attribute_resolver = null ) {
		$this->attribute_resolver = $attribute_resolver;
	}

	/**
	 * Check if a product passes the advanced (Pro) filter.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-9.1, FLTR-FRD-9.2, FLTR-FRD-9.3, FLTR-FRD-9.4
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True to include the product, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// No runtime license gate: a feed already configured with Advanced
		// Filters keeps filtering even after the Pro license lapses, so
		// deliberately-excluded products don't silently reappear in a live feed
		// (BUG-0044). Pro is enforced in the admin UI — Free users can't store
		// rules, so `fattribute` is empty for them and the filter passes through
		// below. Mirrors Attribute Mapping / Dynamic Attribute, which likewise
		// resolve regardless of gate state.
		$attrs = (array) $config->get( 'fattribute', array() );

		// No advanced rules configured → pass-through.
		if ( empty( $attrs ) ) {
			return true;
		}

		$conditions = (array) $config->get( 'condition', array() );
		$compares   = (array) $config->get( 'filterCompare', array() );
		$concats    = (array) $config->get( 'concatType', array() );

		// CBT-574: V8-saved configs carry the user's visual grouping in
		// `_filter_groups`. The flat V5 chain cannot express
		// (A AND B) OR (C AND D) — with that shape it excluded EVERY
		// product (the AND rows fail-fast one branch, the lone OR row's
		// must-pass accumulator kills the other). When the metadata is
		// present and consistent with the row count, evaluate group-aware;
		// V5-era configs (no metadata) and any V5-downgrade round-trip that
		// desynced it keep the exact legacy flat semantics.
		$groups = $this->consistent_groups( $config, count( $attrs ) );
		if ( null !== $groups ) {
			return $this->passes_grouped( $product, $config, $attrs, $conditions, $compares, $concats, $groups );
		}

		return $this->passes_flat( $product, $config, $attrs, $conditions, $compares, $concats );
	}

	/**
	 * `_filter_groups` metadata when usable: sizes sum to the row count and
	 * every entry is well-formed. Null → use the legacy flat path.
	 *
	 * @since 8.0.19
	 *
	 * @param Config $config    Feed configuration.
	 * @param int    $row_count Flat rule-row count.
	 *
	 * @return array<int,array{size:int,base_operator:string}>|null
	 */
	private function consistent_groups( Config $config, int $row_count ): ?array {
		$raw = $config->get( '_filter_groups', array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		$groups = array();
		$sum    = 0;
		foreach ( $raw as $entry ) {
			$size = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			if ( $size < 1 ) {
				return null;
			}
			$op       = isset( $entry['base_operator'] ) ? strtoupper( (string) $entry['base_operator'] ) : 'AND';
			$groups[] = array(
				'size'          => $size,
				'base_operator' => in_array( $op, array( 'AND', 'OR' ), true ) ? $op : 'AND',
			);
			$sum     += $size;
		}

		return $sum === $row_count ? $groups : null;
	}

	/**
	 * Group-aware evaluation (CBT-574).
	 *
	 * Within a group the rows fold LEFT-TO-RIGHT: the first row seeds the
	 * verdict (its stored concatType is ignored — the UI renders it as the
	 * connector-less "IF" row, and for non-first groups the flattener
	 * overwrites it with the between-group operator), each following row
	 * combines with its own AND/OR. Group verdicts then fold left-to-right
	 * with each group's base_operator — matching exactly what the linear
	 * UI reads as.
	 *
	 * @since 8.0.19
	 *
	 * @param \WC_Product $product    WooCommerce product.
	 * @param Config      $config     Feed configuration.
	 * @param array       $attrs      Flat fattribute rows.
	 * @param array       $conditions Flat condition rows.
	 * @param array       $compares   Flat filterCompare rows.
	 * @param array       $concats    Flat concatType rows.
	 * @param array       $groups     Validated `_filter_groups` entries.
	 *
	 * @return bool
	 */
	private function passes_grouped( \WC_Product $product, Config $config, array $attrs, array $conditions, array $compares, array $concats, array $groups ): bool {
		$offset = 0;
		$result = null;

		foreach ( $groups as $group ) {
			$group_result = null;

			for ( $i = $offset; $i < $offset + $group['size']; $i++ ) {
				$value   = $this->resolve_value( $product, (string) $attrs[ $i ], $config );
				$compare = isset( $compares[ $i ] ) ? stripslashes( (string) $compares[ $i ] ) : '';
				$passed  = $this->evaluate( $value, isset( $conditions[ $i ] ) ? (string) $conditions[ $i ] : '', $compare );

				if ( null === $group_result ) {
					$group_result = $passed;
					continue;
				}

				$row_concat   = ( ! empty( $concats[ $i ] ) && 'OR' === strtoupper( (string) $concats[ $i ] ) ) ? 'OR' : 'AND';
				$group_result = 'OR' === $row_concat
					? ( $group_result || $passed )
					: ( $group_result && $passed );
			}
			$offset += $group['size'];

			if ( null === $result ) {
				$result = (bool) $group_result;
				continue;
			}

			$result = 'OR' === $group['base_operator']
				? ( $result || $group_result )
				: ( $result && $group_result );
		}

		return (bool) $result;
	}

	/**
	 * V5-verbatim flat evaluation — the pre-CBT-574 behavior, kept
	 * byte-for-byte for configs without usable `_filter_groups` metadata
	 * (every V5-era feed, and V5-downgrade round-trips).
	 *
	 * @since 8.0.19 (extracted unchanged from passes())
	 *
	 * @param \WC_Product $product    WooCommerce product.
	 * @param Config      $config     Feed configuration.
	 * @param array       $attrs      fattribute rows.
	 * @param array       $conditions condition rows.
	 * @param array       $compares   filterCompare rows.
	 * @param array       $concats    concatType rows.
	 *
	 * @return bool
	 */
	private function passes_flat( \WC_Product $product, Config $config, array $attrs, array $conditions, array $compares, array $concats ): bool {
		$global = $this->resolve_global_default( $config );

		// V5-verbatim accumulator: count OR rows + how many of them passed.
		// AND rows short-circuit on failure inside the loop.
		$total_or           = 0;
		$effective_or_count = 0;

		foreach ( $attrs as $i => $attr ) {
			$value           = $this->resolve_value( $product, (string) $attr, $config );
			$operator        = isset( $conditions[ $i ] ) ? (string) $conditions[ $i ] : '';
			$compare         = isset( $compares[ $i ] ) ? stripslashes( (string) $compares[ $i ] ) : '';
			$concat_operator = ( ! empty( $concats[ $i ] ) ) ? (string) $concats[ $i ] : $global;

			if ( 'OR' === $concat_operator ) {
				++$total_or;
			}

			$passed = $this->evaluate( $value, $operator, $compare );

			if ( 'OR' === $concat_operator && $passed ) {
				++$effective_or_count;
			}

			// FLTR-FRD-9.3: AND rows fail-fast.
			if ( 'AND' === $concat_operator && ! $passed ) {
				return false;
			}
		}

		// FLTR-FRD-9.2: if any OR rows existed but none passed, reject.
		if ( $total_or > 0 && 0 === $effective_or_count ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the global default join operator from config.
	 *
	 * Reads `filterType` (V5 stores `1` for OR, `2` for AND). Empty / missing
	 * defaults to AND, matching V5's `isset(...) && ! empty(...)` guard.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string 'AND' or 'OR'.
	 */
	private function resolve_global_default( Config $config ): string {
		$raw = $config->get( 'filterType', 2 );
		if ( '' === $raw || null === $raw ) {
			$raw = 2;
		}
		return ( 1 === (int) $raw ) ? 'OR' : 'AND';
	}

	/**
	 * Resolve a product attribute value for the given V5 attribute key.
	 *
	 * Price-family keys (price, sale_price, *_with_tax, etc.) are intercepted
	 * BEFORE delegating to AttributeResolver, because AttributeResolver routes
	 * them through PriceResolver which returns currency-formatted strings
	 * (e.g. "30.00 USD"). The advanced filter compares values numerically via
	 * PHP loose-compare on numeric strings — currency suffixes break that and
	 * fall back to lexicographic compare ("100.00 USD" < "45 USD" because
	 * '1' < '4'). V5 returned raw numeric strings here, so we restore that
	 * behavior. Mapping matches V5 ProductInfo:
	 *   price            → get_regular_price()
	 *   regular_price    → get_regular_price()  (V8 alias)
	 *   current_price    → get_price()
	 *   sale_price       → get_sale_price()
	 *   *_with_tax       → wc_get_price_including_tax(product, ['price' => raw])
	 *   price_excluding_tax → wc_get_price_excluding_tax(product)
	 *
	 * Non-price keys route through V8's AttributeResolver when available so
	 * all V5 attribute key prefixes still work — title, pa_*, wf_attr_*,
	 * wf_cattr_*, wf_taxo_*, etc.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    V5 attribute key.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Resolved value (cast to string for comparison).
	 */
	private function resolve_value( \WC_Product $product, string $attr, Config $config ): string {
		// Intercept price-family attributes — V5 parity for filter comparisons.
		$price_value = $this->resolve_price_value( $product, $attr );
		if ( null !== $price_value ) {
			return $price_value;
		}

		if ( null !== $this->attribute_resolver ) {
			$value = $this->attribute_resolver->resolve(
				$product,
				array(
					'type'    => 'attribute',
					'wc_attr' => $attr,
				),
				$config
			);
			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}
			return (string) $value;
		}

		// Standalone fallback for the common V5 keys. Used by Layer 1 tests
		// that don't want to wire up the full AttributeResolver dependency tree.
		switch ( $attr ) {
			case 'title':
			case 'name':
				return (string) $product->get_name();
			case 'sku':
				return (string) $product->get_sku();
			case 'description':
				return (string) $product->get_description();
			default:
				return '';
		}
	}

	/**
	 * Return the raw numeric price string for a price-family attribute key,
	 * or null if `$attr` is not a price-family key.
	 *
	 * Bypasses V8's PriceResolver currency formatting so the advanced filter's
	 * numeric comparison operators (>, <, >=, <=, between) work correctly. V5
	 * returned raw values here and customers depend on that behavior.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    V5 attribute key.
	 *
	 * @return string|null Raw numeric string, or null when `$attr` isn't a price key.
	 */
	private function resolve_price_value( \WC_Product $product, string $attr ) {
		switch ( $attr ) {
			case 'price':
			case 'regular_price':
				return (string) $product->get_regular_price();

			case 'current_price':
				return (string) $product->get_price();

			case 'sale_price':
				return (string) $product->get_sale_price();

			case 'price_with_tax':
			case 'regular_price_with_tax':
				return (string) $this->price_with_tax( $product, (string) $product->get_regular_price() );

			case 'current_price_with_tax':
				return (string) $this->price_with_tax( $product, (string) $product->get_price() );

			case 'sale_price_with_tax':
				return (string) $this->price_with_tax( $product, (string) $product->get_sale_price() );

			case 'price_excluding_tax':
				return (string) $this->price_excluding_tax( $product );

			default:
				return null;
		}
	}

	/**
	 * Compute price with tax for a given base price.
	 *
	 * Wraps `wc_get_price_including_tax()` with a function_exists guard so the
	 * filter can run in Layer 1 unit tests without a full WC bootstrap. When
	 * WC isn't loaded, returns the raw base price unchanged (best-effort
	 * degradation — Layer 2 integration tests exercise the real path).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $price   Base price.
	 *
	 * @return string Price including tax (raw numeric string).
	 */
	private function price_with_tax( \WC_Product $product, string $price ): string {
		if ( '' === $price ) {
			return '';
		}
		if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
			return $price;
		}
		return (string) wc_get_price_including_tax( $product, array( 'price' => $price ) );
	}

	/**
	 * Compute price excluding tax.
	 *
	 * Wraps `wc_get_price_excluding_tax()` with a function_exists guard for
	 * the same reason as `price_with_tax()` above.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string Price excluding tax (raw numeric string).
	 */
	private function price_excluding_tax( \WC_Product $product ): string {
		if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
			return (string) $product->get_price();
		}
		return (string) wc_get_price_excluding_tax( $product );
	}

	/**
	 * Evaluate one row's condition against the resolved product value.
	 *
	 * Operator semantics are a verbatim port of V5's switch in
	 * Filter\AdvanceFilter — including:
	 *   - Case-insensitive comparison via strtolower() for ==/!= /</>/>=/<=
	 *   - Case-insensitive substring via stripos() for contains/nContains
	 *   - Range parse `low-high` for between (no negative-number support;
	 *     V5 didn't either)
	 *   - Unknown operators silently fall through to false (V5 default branch)
	 *
	 * @since 8.0.0
	 *
	 * @param string $value    Resolved product value.
	 * @param string $operator Operator code (==, !=, contains, etc.).
	 * @param string $compare  Compare value from feedrules.
	 *
	 * @return bool True if the row's condition is satisfied.
	 */
	private function evaluate( string $value, string $operator, string $compare ): bool {
		switch ( $operator ) {
			case '==':
				return strtolower( $value ) === strtolower( $compare );

			case '!=':
				return strtolower( $value ) !== strtolower( $compare );

			case '>=':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- V5-verbatim string-compare; numeric strings PHP-coerce to numbers.
				return strtolower( $value ) >= strtolower( $compare );

			case '<=':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- V5 parity: operands are user-entered filter text vs mixed-type product values; strict comparison would drop matches V5 kept.
				return strtolower( $value ) <= strtolower( $compare );

			case '>':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- V5 parity: operands are user-entered filter text vs mixed-type product values; strict comparison would drop matches V5 kept.
				return strtolower( $value ) > strtolower( $compare );

			case '<':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- V5 parity: operands are user-entered filter text vs mixed-type product values; strict comparison would drop matches V5 kept.
				return strtolower( $value ) < strtolower( $compare );

			case 'contains':
				return false !== stripos( $value, $compare );

			case 'nContains':
				return false === stripos( $value, $compare );

			case 'between':
				// V5 splits on '-' which can't represent negative bounds.
				// Customers haven't reported issues so we preserve verbatim.
				$parts = explode( '-', $compare );
				if ( ! isset( $parts[0], $parts[1] ) ) {
					return false;
				}
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- V5 parity: operands are user-entered filter text vs mixed-type product values; strict comparison would drop matches V5 kept.
				return ( $value >= $parts[0] ) && ( $value <= $parts[1] );

			default:
				return false;
		}
	}
}
