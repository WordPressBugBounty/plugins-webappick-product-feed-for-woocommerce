<?php
/**
 * ProductRepository — Central product data access layer.
 *
 * Uses CacheWarmer for bulk loading, then resolves attributes per product.
 * Fires both V8 (ctxfeed_product_attribute_{attr}) and legacy
 * (woo_feed_filter_product_{attr}) hooks for backward compatibility.
 * V5 compat handled entirely via dual-filter chain (AD-PROD-005).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-2.1, PROD-FRD-2.2, PROD-FRD-2.3, PROD-FRD-2.4
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\Transform\CommandProcessor;
use CTXFeed\V8\Transform\OutputTypeTransform;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product repository.
 *
 * @since 8.0.0
 */
class ProductRepository {

	/**
	 * Cache warmer instance.
	 *
	 * @since 8.0.0
	 * @var CacheWarmer
	 */
	private $warmer;

	/**
	 * Attribute resolver instance.
	 *
	 * @since 8.0.0
	 * @var AttributeResolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-2.4
	 *
	 * @param CacheWarmer       $warmer   Cache warmer for bulk priming.
	 * @param AttributeResolver $resolver Attribute resolver for value resolution.
	 */
	public function __construct( CacheWarmer $warmer, AttributeResolver $resolver ) {
		$this->warmer   = $warmer;
		$this->resolver = $resolver;
	}

	/**
	 * Get product data for a batch of IDs.
	 *
	 * Cache-primes all IDs first (3 queries), then resolves each product's
	 * attributes via the resolver chain with dual-filter hooks.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-2.1
	 *
	 * @param int[]  $ids       Product IDs.
	 * @param array  $structure Channel attribute structure/mappings.
	 * @param Config $config    Feed configuration.
	 *
	 * @return ProductDTO[] Array of resolved product DTOs.
	 */
	public function get_products( array $ids, array $structure, Config $config ): array {
		// 1. Prime all caches for this batch (3 queries instead of 30,000).
		$this->warmer->warm( $ids );

		$products = array();

		foreach ( $ids as $id ) {
			$wc_product = wc_get_product( $id );

			// Skip invalid/deleted products.
			if ( ! $wc_product ) {
				continue;
			}

			// Validate product (status, visibility, etc.).
			if ( ! $this->is_valid_product( $wc_product, $config ) ) {
				continue;
			}

			// @hook ctxfeed_before_resolve_product
			do_action( 'ctxfeed_before_resolve_product', $id, $config );

			$dto = new ProductDTO( $id, $wc_product->get_type() );

			foreach ( $structure as $channel_attr => $mapping ) {
				$value = $this->resolve_with_filters( $wc_product, $mapping, $config );
				$dto->set( $channel_attr, $value );
			}

			// @hook ctxfeed_after_resolve_product
			do_action( 'ctxfeed_after_resolve_product', $id, $dto, $config );

			$products[] = $dto;
		}

		return $products;
	}

	/**
	 * Resolve an attribute value and apply both V8 and legacy filters.
	 *
	 * Applies the V8 filter first, then checks for legacy V5 filter.
	 * V5 filter receives config as array for backward compatibility.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-2.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param array       $mapping Attribute mapping configuration.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Final resolved and filtered value.
	 */
	/**
	 * V5 price-attribute hook signature map — now a price-family DETECTION set.
	 *
	 * Only the KEYS of this map are consulted (in resolve_with_filters()), to
	 * recognise the price-family source attributes and SKIP re-firing their
	 * bridge hook (see step 3 there): PriceResolver::apply_price_bridge_filter()
	 * is the single
	 * site that fires the price bridge filters — once, on the raw value, with
	 * V5's exact hook identity and its five-arg signature `($price, $product,
	 * $config, $with_tax, $price_type)`. Firing them again here would double a
	 * multiplicative currency switcher.
	 *
	 * The per-key {with_tax, price_type[, hook]} values are retained purely as
	 * documentation of the V5 signature PriceResolver reproduces (discount
	 * plugins branch on `$price_type`; currency plugins ignore it). The `hook`
	 * key records where a source name diverges from its V5 hook (e.g. source
	 * 'current_price' → the `price` hook, since V5's current_price() fired
	 * woo_feed_filter_product_PRICE — there was never a `_current_price` hook).
	 *
	 * Non-price attributes still fire their legacy hook in step 3 with the
	 * standard 4-arg shape plus `$parent_product` — V5 fired those with 3 args,
	 * but extending to 4 is harmless because compat callbacks declared with
	 * `add_filter(..., 10, 3)` just don't receive arg #4. PROD-FRD-10.7.
	 *
	 * @since 8.0.0
	 * @var array<string, array{with_tax: bool, price_type: string}>
	 */
	private const V5_PRICE_HOOK_SIGNATURE = array(
		'price'                  => array(
			'with_tax'   => false,
			'price_type' => 'price',
		),
		'regular_price'          => array(
			'with_tax'   => false,
			'price_type' => 'regular_price',
		),
		'sale_price'             => array(
			'with_tax'   => false,
			'price_type' => 'sale_price',
		),
		'price_with_tax'         => array(
			'with_tax'   => true,
			'price_type' => 'price',
		),
		'regular_price_with_tax' => array(
			'with_tax'   => true,
			'price_type' => 'regular_price',
		),
		'sale_price_with_tax'    => array(
			'with_tax'   => true,
			'price_type' => 'sale_price',
		),
		// V5 exposed the "active/current" price via ProductInfos::current_price(),
		// which fired the woo_feed_filter_product_PRICE bridge — there was never
		// a `_current_price` hook. The default Google/Facebook/Pinterest/… templates
		// map the sale_price column's SOURCE to 'current_price', so the currency-
		// switcher and discount compat shims (which listen on `_price`) only see it
		// if we fire the V5 `price` hook identity here. The `hook` key routes the
		// source key to that legacy hook name; without it, source 'current_price'
		// would fire an unhooked `_current_price` filter and never get converted.
		'current_price'          => array(
			'hook'       => 'price',
			'with_tax'   => false,
			'price_type' => 'price',
		),
		'current_price_with_tax' => array(
			'hook'       => 'price_with_tax',
			'with_tax'   => true,
			'price_type' => 'price',
		),
	);

	/**
	 * Resolve one attribute value and run it through the V8 and Legacy Bridge filters.
	 *
	 * Fires `ctxfeed_product_attribute_{attr}` first, then the V5-compatible
	 * `woo_feed_filter_product_{attr}` hook so the existing ctx-compatibility/
	 * classes keep working unmodified, then applies the parent-aware output
	 * type codes and output commands that need product context.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product (or variation) being exported.
	 * @param array       $mapping Single feed-rule mapping row (attr, wc_attr, type, …).
	 * @param Config      $config  Feed configuration for the running feed.
	 *
	 * @return mixed Filtered attribute value.
	 */
	private function resolve_with_filters( \WC_Product $product, array $mapping, Config $config ) {
		// 1. Resolve raw value via resolver chain.
		$value   = $this->resolver->resolve( $product, $mapping, $config );
		$wc_attr = isset( $mapping['wc_attr'] ) ? $mapping['wc_attr'] : '';

		// 2. Apply V8 filter (new adapters).
		// @hook ctxfeed_product_attribute_{attr}
		$value = apply_filters( "ctxfeed_product_attribute_{$wc_attr}", $value, $product, $config );

		// 3. Fire the legacy V5 bridge filter (existing ctx-compatibility/ classes).
		// @hook woo_feed_filter_product_{attr}
		//
		// PRICE-FAMILY ATTRIBUTES ARE DELIBERATELY SKIPPED HERE. Their bridge
		// hooks (woo_feed_filter_product_price / _regular_price / _sale_price and
		// the _with_tax twins) are fired ONCE by PriceResolver, on the RAW value
		// BEFORE formatting and before the empty-guard — that is V5's timing (a
		// discount rule can fill an empty slot; a currency switcher converts the
		// bare number, not a formatted "24.00 USD" string) — and PriceResolver
		// reproduces V5's exact hook identity (ProductInfos::price()→_regular_price,
		// current_price()→_price, sale_price()→_sale_price). Firing the same hook
		// a SECOND time here would double-apply a MULTIPLICATIVE currency-switcher
		// shim (WOOCS/CURCY/Aelia/…): 24 → 48 → 96. Discount shims happened to
		// survive only because they recompute from the product and ignore the
		// incoming $value, but currency shims do not. So only NON-price
		// attributes fire their legacy bridge hook in this step.
		$is_price_family = isset( self::V5_PRICE_HOOK_SIGNATURE[ $wc_attr ] );

		if ( ! $is_price_family ) {
			$legacy_filter = "woo_feed_filter_product_{$wc_attr}";

			if ( has_filter( $legacy_filter ) ) {
				// Non-price attributes: 4-arg signature with parent_product
				// for V5 compat classes (e.g. DIVI_GRID_PLUGIN) that look
				// up parent meta on variations.
				$parent_product = null;
				if ( $product->is_type( 'variation' ) ) {
					$parent_id      = $product->get_parent_id();
					$parent_product = $parent_id ? wc_get_product( $parent_id ) : null;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $legacy_filter is built above as "woo_feed_filter_product_{$wc_attr}", so it always carries the registered woo_feed prefix; the sniff cannot resolve the variable.
				$value = apply_filters( $legacy_filter, $value, $product, $config, $parent_product );
			}
		}

		// 4. V5 parent-aware output_type codes (18/19/20/23/24).
		// These need product context + a way to re-resolve attributes,
		// so they live here rather than in OutputTypeTransform (which
		// runs later in the pipeline without product context). The
		// non-parent codes (1-17, 21-22) are still applied by
		// OutputTypeTransform downstream. PROD-FRD-10.8.
		$value = $this->apply_parent_output_type( $value, $product, $mapping, $config );

		// 5. Parent-aware output COMMANDS (only_parent / parent /
		// parent_if_empty from the Config-tab command box). Same
		// product-context constraint as step 4; the string commands in the
		// chain run downstream in CommandTransform. XFRM-FRD-9.5.
		$value = $this->apply_parent_command( $value, $product, $mapping, $config );

		return $value;
	}

	/**
	 * Apply V5 output_type codes that require parent-product re-resolution.
	 *
	 * Codes handled:
	 *   18 (only_parent)        — re-resolve against parent, always.
	 *   19 (parent)             — re-resolve against parent; fall back to
	 *                             current value if parent is empty.
	 *   20 (parent_if_empty)    — if current value is empty, re-resolve
	 *                             against parent. (V8's VariationResolver
	 *                             already does this automatically when the
	 *                             initial resolve returns empty; we still
	 *                             honor an explicit code 20 selection for
	 *                             cases where the resolver returned a
	 *                             non-empty-but-effectively-empty value.)
	 *   23 (parent_lang)        — re-resolve against the WPML/Polylang
	 *                             original-language post, always.
	 *   24 (parent_lang_if_empty) — same but only when current is empty.
	 *
	 * For codes 23/24, we call `apply_filters('woo_feed_original_post_id')`
	 * which V5's `SitePressCompatibility` (now loaded under V8 via the
	 * ctx-compatibility submodule) hooks for WPML. Polylang sites that
	 * don't hook this filter fall through to no-op. PROD-FRD-10.8.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed       $value   Current resolved value.
	 * @param \WC_Product $product Source product.
	 * @param array       $mapping Mapping with `index` parallel-array position.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Possibly-rewritten value.
	 */
	private function apply_parent_output_type( $value, \WC_Product $product, array $mapping, Config $config ) {
		if ( ! isset( $mapping['index'] ) ) {
			return $value;
		}

		$output_types = (array) $config->get( 'output_type', array() );
		if ( empty( $output_types ) ) {
			return $value;
		}

		$index = (int) $mapping['index'];
		if ( ! isset( $output_types[ $index ] ) ) {
			return $value;
		}

		// A row can carry SEVERAL output_type codes (V5 multiselect). Only
		// the parent-aware codes are handled here — codes 1-17, 21-22 belong
		// to OutputTypeTransform downstream. Apply each present parent code in
		// V5's fixed order, threading the value (V5 runs these as sequential
		// in_array() checks inside FormatOutput::get_output()).
		$selected = array_flip( OutputTypeTransform::normalize_codes( $output_types[ $index ] ) );
		if ( empty( $selected ) ) {
			return $value;
		}

		foreach ( array( '18', '19', '20', '23', '24' ) as $code ) {
			if ( isset( $selected[ $code ] ) ) {
				$value = $this->apply_one_parent_code( $value, $product, $mapping, $config, $code );
			}
		}

		return $value;
	}

	/**
	 * Apply ONE parent-aware output_type code (18/19/20/23/24) to a value.
	 *
	 * Extracted from apply_parent_output_type() so several codes selected on
	 * the same row can be threaded in sequence. Each branch re-resolves the
	 * attribute against an alternate product (parent, or parent-language) and
	 * returns the value unchanged when the code doesn't apply (plain product,
	 * no parent, no translation).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed       $value   Current resolved value.
	 * @param \WC_Product $product Source product.
	 * @param array       $mapping Mapping with `index` parallel-array position.
	 * @param Config      $config  Feed configuration.
	 * @param string      $code    Parent output_type code ('18'..'24').
	 *
	 * @return mixed Possibly-rewritten value.
	 */
	private function apply_one_parent_code( $value, \WC_Product $product, array $mapping, Config $config, string $code ) {
		// Codes 18-20: variation → parent product re-resolution.
		if ( in_array( $code, array( '18', '19', '20' ), true ) ) {
			if ( ! $product->is_type( 'variation' ) ) {
				// Codes only apply to variations. Plain products → no-op.
				return $value;
			}

			$parent_id = $product->get_parent_id();
			if ( ! $parent_id ) {
				return $value;
			}

			$parent_product = wc_get_product( $parent_id );
			if ( ! $parent_product instanceof \WC_Product ) {
				return $value;
			}

			return $this->resolve_against_parent( $value, $parent_product, $mapping, $config, $code );
		}

		// Codes 23-24: WPML/Polylang parent-language re-resolution.
		if ( in_array( $code, array( '23', '24' ), true ) ) {
			$product_id = $product->get_id();
			$source_id  = $this->resolve_parent_language_post_id( $product_id );

			// Same ID returned → not a translation, or no translation
			// plugin detected/active. No-op.
			if ( (int) $source_id === (int) $product_id ) {
				return $value;
			}

			$source_product = wc_get_product( $source_id );
			if ( ! $source_product instanceof \WC_Product ) {
				return $value;
			}

			return $this->resolve_against_parent( $value, $source_product, $mapping, $config, $code );
		}

		return $value;
	}

	/**
	 * Apply V5 output COMMANDS that require parent-product re-resolution
	 * (`only_parent`, `parent`, `parent_if_empty` — and their friendly
	 * aliases — from the Config-tab command box, feedrules `limit[index]`).
	 *
	 * ORDERING NOTE (deviation vs V5, deliberate): V5 ran commands AFTER
	 * output_types, all inside `process_output()`. In V8, output_types and
	 * string commands run downstream in the TransformPipeline
	 * (OutputTypeTransform → CommandTransform), which has no WC_Product
	 * context — so the parent trio alone is applied HERE, before
	 * output_types, exactly like the parent-aware output_type codes
	 * (18/19/20) in apply_parent_output_type() above. Since the parent trio
	 * replaces the value with a fresh parent resolution (it does not
	 * transform the accumulated value), hoisting it ahead of the string
	 * stages preserves V5's observable output: later commands/output_types
	 * still apply on top of the parent value. The rest of the chain keeps
	 * exact V5 order (output_types → commands → prefix/suffix) via
	 * CommandTransform's pipeline position. XFRM-FRD-9.5.
	 *
	 * The command→code mapping (only_parent=18, parent=19,
	 * parent_if_empty=20) lets this reuse resolve_against_parent() — the
	 * same machinery as the output_type codes — instead of porting V5
	 * FormatOutput's re-resolution.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.5
	 *
	 * @param mixed       $value   Current resolved value.
	 * @param \WC_Product $product Source product.
	 * @param array       $mapping Mapping with `index` parallel-array position.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Possibly-rewritten value.
	 */
	private function apply_parent_command( $value, \WC_Product $product, array $mapping, Config $config ) {
		if ( ! isset( $mapping['index'] ) ) {
			return $value;
		}

		// Commands are a Pro feature — closed gate is a no-op. XFRM-FRD-9.4.
		if ( ! FeatureGate::has( CommandProcessor::FEATURE ) ) {
			return $value;
		}

		$commands = $config->get_attribute_command( (int) $mapping['index'] );
		if ( '' === $commands ) {
			return $value;
		}

		$code = CommandProcessor::parent_output_code( $commands );
		if ( '' === $code ) {
			return $value;
		}

		// Parent commands only apply to variations (V5 parity).
		if ( ! $product->is_type( 'variation' ) ) {
			return $value;
		}

		$parent_id = $product->get_parent_id();
		if ( ! $parent_id ) {
			return $value;
		}

		$parent_product = wc_get_product( $parent_id );
		if ( ! $parent_product instanceof \WC_Product ) {
			return $value;
		}

		return $this->resolve_against_parent( $value, $parent_product, $mapping, $config, $code );
	}

	/**
	 * Resolve the source-language post ID for translation codes 23/24.
	 *
	 * Two-step lookup:
	 *
	 *   1. WPML — `apply_filters('woo_feed_original_post_id', $id)`.
	 *      `SitePressCompatibility` (free + Pro via the
	 *      `ctx-compatibility/` submodule) hooks this. Returns the
	 *      filter result directly when WPML translates the post.
	 *
	 *   2. Polylang — direct API call, Pro-gated.
	 *      V5's `CommonHelper::woo_feed_pll_get_original_post_id()` used
	 *      `pll_get_post_translations($id)[pll_default_language()]`. We
	 *      mirror that. Polylang integration is a Pro feature in V8
	 *      (matching V5's `CompatibilityFactory::compatible_plugins()`
	 *      gating, where Polylang was in the Pro-only manifest), so this
	 *      path is gated by `FeatureGate::has('polylang_translation')`.
	 *
	 * Returning the original `$product_id` means "no translation found"
	 * — the caller treats that as a no-op.
	 *
	 * @since 8.0.0
	 *
	 * @param int $product_id Current product (translated post) ID.
	 * @return int Source-language post ID, or $product_id when no
	 *             translation was detected.
	 */
	private function resolve_parent_language_post_id( int $product_id ): int {
		// Step 1 — WPML via existing V5 filter.
		$wpml_id = (int) apply_filters( 'woo_feed_original_post_id', $product_id );
		if ( $wpml_id !== $product_id && $wpml_id > 0 ) {
			return $wpml_id;
		}

		// Step 2 — Polylang, Pro-gated.
		if ( ! FeatureGate::has( 'polylang_translation' ) ) {
			return $product_id;
		}

		if ( ! $this->is_polylang_active() ) {
			return $product_id;
		}

		$translations = pll_get_post_translations( $product_id );
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return $product_id;
		}

		$default_language = pll_default_language();
		if ( '' === $default_language || ! isset( $translations[ $default_language ] ) ) {
			return $product_id;
		}

		$source_id = (int) $translations[ $default_language ];
		return $source_id > 0 ? $source_id : $product_id;
	}

	/**
	 * Detect whether Polylang is active and its core API is loaded.
	 *
	 * Default heuristic: V5's `PolylangCompatibility` check —
	 * `defined('POLYLANG_BASENAME') || function_exists('PLL')`.
	 *
	 * The result is run through `ctxfeed_polylang_detected` so admins
	 * with custom Polylang setups (e.g. headless WP using polylang-rest)
	 * can override the detection — and so unit tests can stub it
	 * deterministically without relying on Brain\Monkey's
	 * `function_exists` state, which leaks between tests once any
	 * sibling test stubs a `pll_*` function.
	 *
	 * @since 8.0.0
	 *
	 * @return bool True when Polylang's API is callable.
	 */
	private function is_polylang_active(): bool {
		$default = defined( 'POLYLANG_BASENAME' )
			|| function_exists( 'PLL' )
			|| function_exists( 'pll_get_post_translations' );

		/**
		 * Override Polylang detection. Return true to force the
		 * Polylang code path on; false to force it off.
		 *
		 * @since 8.0.0
		 *
		 * @param bool $detected Default detection result.
		 */
		return (bool) apply_filters( 'ctxfeed_polylang_detected', $default );
	}

	/**
	 * Re-resolve the current attribute against an alternate product
	 * (parent or parent-language) according to the V5 code semantics.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed       $value           Current value.
	 * @param \WC_Product $alternate       Alternate product to resolve against.
	 * @param array       $mapping         Original mapping.
	 * @param Config      $config          Feed configuration.
	 * @param string      $code            Code '18', '19', '20', '23', or '24'.
	 *
	 * @return mixed
	 */
	private function resolve_against_parent( $value, \WC_Product $alternate, array $mapping, Config $config, string $code ) {
		// For "if_empty" codes (20, 24) skip re-resolution when the
		// current value is already populated.
		if ( ( '20' === $code || '24' === $code ) && '' !== $value && null !== $value && array() !== $value ) {
			return $value;
		}

		// Build a mapping without the `index` key so AttributeResolver
		// doesn't re-enter this method via its filter chain.
		$alt_mapping = $mapping;
		unset( $alt_mapping['index'] );

		$alternate_value = $this->resolver->resolve( $alternate, $alt_mapping, $config );

		// Code 18 / 23: always use parent value, even if empty.
		if ( '18' === $code || '23' === $code ) {
			return $alternate_value;
		}

		// Code 19: parent first, fall back to current if parent is empty.
		if ( '19' === $code ) {
			return ( '' === $alternate_value || null === $alternate_value ) ? $value : $alternate_value;
		}

		// Codes 20 / 24: current was empty (checked above), so use the
		// alternate value.
		return $alternate_value;
	}

	/**
	 * Resolve a single product's attributes into a key-value array.
	 *
	 * Builds the attribute mapping structure from Config parallel arrays
	 * (mattributes, attributes, type, default) and resolves each one
	 * through the resolver chain with dual-filter hooks.
	 *
	 * Used by FeedGenerator's streaming pipeline where products are
	 * processed one at a time for memory efficiency.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-2.1
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array Associative array of channel_attr => resolved_value.
	 */
	public function resolve_single( \WC_Product $product, Config $config ): array {
		$merchant_attrs = $config->get_merchant_attributes();
		$wc_attrs       = $config->get_attributes();
		$types          = $config->get_type();
		$defaults       = $config->get_default();

		// @hook ctxfeed_before_resolve_product
		do_action( 'ctxfeed_before_resolve_product', $product->get_id(), $config );

		$data = array();
		// Track occurrences of each merchant attribute name so duplicates
		// (typically product_detail's section_name/attribute_name/attribute_value
		// triplet, which customers legitimately map multiple times) don't
		// collide in the flat $data array. Second occurrence becomes
		// `section_name##2`, third `section_name##3`, etc. The suffix is
		// stripped downstream by GroupedAttributeBuilder (which uses the
		// suffixes to detect repeat groups) and by AttributeNameMapper
		// (which strips them defensively before writing tag names).
		// PROD-FRD-10.11.
		$seen = array();

		foreach ( $merchant_attrs as $index => $channel_attr ) {
			$mapping = array(
				'wc_attr'       => isset( $wc_attrs[ $index ] ) ? $wc_attrs[ $index ] : '',
				'type'          => isset( $types[ $index ] ) ? $types[ $index ] : 'attribute',
				'default'       => isset( $defaults[ $index ] ) ? $defaults[ $index ] : '',
				// V5 hooks (e.g. woo_feed_after_dynamic_attribute_value)
				// expect the channel field name (e.g. "g:price") as a
				// positional arg. Thread it through the mapping so V8
				// resolvers can fire the same V5 signature. PROD-FRD-10.5.
				'merchant_attr' => (string) $channel_attr,
				// Parallel-array position. Used by resolve_with_filters
				// to look up the matching `output_type` code for V5 codes
				// 18/19/20/23/24 (parent-product re-resolution).
				// PROD-FRD-10.8.
				'index'         => (int) $index,
			);

			$value = $this->resolve_with_filters( $product, $mapping, $config );

			$key          = self::dedup_merchant_key( (string) $channel_attr, $seen );
			$data[ $key ] = $value;
		}

		// identifier_exists must reflect the feed's ACTUAL identifier output,
		// not raw product meta: a feed that maps mpn/brand/gtin (even to the
		// SKU or a static value) must report "yes". Recompute the auto-added /
		// attribute-resolved column from the fully resolved row; an explicit
		// static override the merchant set is left untouched. @implements G-05.
		if ( array_key_exists( 'identifier_exists', $data ) ) {
			foreach ( $merchant_attrs as $i => $name ) {
				if ( 'identifier_exists' !== $name ) {
					continue;
				}
				$is_attribute = 'attribute' === ( isset( $types[ $i ] ) ? $types[ $i ] : 'attribute' )
					&& 'identifier_exists' === ( isset( $wc_attrs[ $i ] ) ? $wc_attrs[ $i ] : '' );
				if ( $is_attribute ) {
					$data['identifier_exists'] = $this->resolver->identifier_exists_from_row( $product, $data );
				}
				break;
			}
		}

		// @hook ctxfeed_after_resolve_product
		do_action( 'ctxfeed_after_resolve_product', $product->get_id(), $data, $config );

		return $data;
	}

	/**
	 * Resolve ONLY the given merchant attributes for a product.
	 *
	 * A lean sibling of {@see resolve_single()} that skips every config row
	 * whose merchant name is not in $only_merchant_keys — so a caller can
	 * resolve a handful of fields (e.g. the Skroutz per-variation sub-fields)
	 * against a child variation without paying to resolve the full parent
	 * attribute set for every child. Reuses the same resolver chain + V5
	 * filter hooks + output_type handling as resolve_single (via the shared
	 * resolve_with_filters), so values are identical to a full resolve.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product              Product (typically a child variation).
	 * @param Config      $config               Feed configuration.
	 * @param string[]    $only_merchant_keys   Merchant attribute names to resolve.
	 * @param bool        $no_variation_fallback When true, a variation resolves ONLY
	 *                                           its own value — no fall back to the
	 *                                           parent (each child is authoritative,
	 *                                           e.g. a variation with stock 0 stays 0
	 *                                           instead of inheriting the parent's).
	 *
	 * @return array Associative array of merchant_attr => resolved_value
	 *               (only the requested keys that are actually mapped).
	 */
	public function resolve_attributes_for( \WC_Product $product, Config $config, array $only_merchant_keys, bool $no_variation_fallback = false ): array {
		if ( empty( $only_merchant_keys ) ) {
			return array();
		}

		$merchant_attrs = $config->get_merchant_attributes();
		$wc_attrs       = $config->get_attributes();
		$types          = $config->get_type();
		$defaults       = $config->get_default();
		$wanted         = array_flip( $only_merchant_keys );

		$data = array();
		foreach ( $merchant_attrs as $index => $channel_attr ) {
			if ( ! isset( $wanted[ $channel_attr ] ) ) {
				continue;
			}

			$mapping = array(
				'wc_attr'               => isset( $wc_attrs[ $index ] ) ? $wc_attrs[ $index ] : '',
				'type'                  => isset( $types[ $index ] ) ? $types[ $index ] : 'attribute',
				'default'               => isset( $defaults[ $index ] ) ? $defaults[ $index ] : '',
				'merchant_attr'         => (string) $channel_attr,
				'index'                 => (int) $index,
				'no_variation_fallback' => $no_variation_fallback,
			);

			$data[ (string) $channel_attr ] = $this->resolve_with_filters( $product, $mapping, $config );
		}

		return $data;
	}

	/**
	 * Separator inserted between an attribute name and its per-position
	 * suffix when the same merchant attribute name appears multiple times
	 * in the feed config (e.g. two `section_name` entries for two
	 * product_detail groups).
	 *
	 * `##` is safe because Google's spec forbids `#` in attribute names,
	 * so this separator can never collide with a real attribute the user
	 * might legitimately map.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const DUP_KEY_SEPARATOR = '##';

	/**
	 * Return the flat-array key to use for `$merchant_attr` given a
	 * seen-so-far occurrence counter. First occurrence uses the raw
	 * name; subsequent occurrences get `##2`, `##3`, … PROD-FRD-10.11.
	 *
	 * @since 8.0.0
	 *
	 * @param string $merchant_attr Merchant attribute name (e.g. 'section_name').
	 * @param array  &$seen         Byref counter map — mutated on each call.
	 *
	 * @return string Suffixed key.
	 */
	private static function dedup_merchant_key( string $merchant_attr, array &$seen ): string {
		if ( '' === $merchant_attr ) {
			return $merchant_attr;
		}
		if ( ! isset( $seen[ $merchant_attr ] ) ) {
			$seen[ $merchant_attr ] = 1;
			return $merchant_attr;
		}
		++$seen[ $merchant_attr ];
		return $merchant_attr . self::DUP_KEY_SEPARATOR . $seen[ $merchant_attr ];
	}

	/**
	 * Strip the `##N` duplicate-position suffix from a merchant
	 * attribute key. Used by downstream consumers (GroupedAttributeBuilder,
	 * AttributeNameMapper, transforms) so lookups keyed by the base name
	 * still work for the suffixed variants. PROD-FRD-10.11.
	 *
	 * @since 8.0.0
	 *
	 * @param string $key Possibly-suffixed key.
	 * @return string Base name.
	 */
	public static function strip_dup_suffix( string $key ): string {
		$pos = strpos( $key, self::DUP_KEY_SEPARATOR );
		return false === $pos ? $key : substr( $key, 0, $pos );
	}

	/**
	 * Validate product against feed rules.
	 *
	 * Checks post status and applies the validation filter.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-2.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool Whether the product is valid for feed inclusion.
	 */
	private function is_valid_product( \WC_Product $product, Config $config ): bool {
		// Variations may have non-publish parent; allow them through.
		if ( 'publish' !== get_post_status( $product->get_id() ) && ! $product->is_type( 'variation' ) ) {
			return false;
		}

		// @hook ctxfeed_is_valid_product
		return (bool) apply_filters( 'ctxfeed_is_valid_product', true, $product, $config );
	}
}
