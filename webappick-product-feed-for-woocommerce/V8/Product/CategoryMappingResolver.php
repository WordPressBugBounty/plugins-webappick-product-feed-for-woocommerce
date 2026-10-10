<?php
/**
 * CategoryMappingResolver — V5-compat resolver for `wf_cmapping_*` attributes.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-10.4
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V5-compat category-mapping resolver.
 *
 * Verbatim port of `V5\Output\CategoryMapping::getCategoryMappingValue()`
 * (V5 line 27). Used when a feed config row references a saved category
 * mapping by attribute key — e.g. attribute=`wf_cmapping_my_google_map`
 * resolves to the stored merchant-category string for the product's
 * deepest matching `product_cat` term.
 *
 * Without this resolver, V8's `AttributeResolver` falls through to bare
 * `get_post_meta($pid, 'wf_cmapping_*')` which always returns empty —
 * silent data-loss regression for the 70K+ V5 install base. This file
 * fixes that.
 *
 * Note: `V8/Transform/CategoryMapping.php` is a SEPARATE pipeline that
 * runs only when `custom_category_mapping` is set in feed config and
 * writes to a single hardcoded target attribute. It does NOT replace
 * the per-attribute resolution V5 supported and 70K customers depend
 * on. Both code paths coexist — they serve different config shapes.
 *
 * Storage shape (V5-verbatim — DO NOT change):
 *   wp_options['wf_cmapping_<slug>'] = serialize({
 *     mappingname:     string,
 *     mappingprovider: string,                       // merchant code
 *     cmapping:        { term_id: string },          // generic mapping
 *     gcl-cmapping:    { term_id: string },          // suggestive list
 *   })
 *
 * Resolution rules (V5-verbatim):
 *   1. `maybe_unserialize` the option.
 *   2. If neither `cmapping` nor `gcl-cmapping` exists → return ''.
 *   3. If `gcl-cmapping` exists AND mappingprovider is in the
 *      "suggestive list" merchant set (google, facebook, pinterest,
 *      bing, bing_local_inventory, snapchat) → use `gcl-cmapping`.
 *      Otherwise use `cmapping`.
 *   4. Take the product's `product_cat` term IDs; pick the deepest
 *      MAPPED term by real hierarchy depth (get_ancestors count; depth
 *      tie -> higher id). V5 used `max($term_ids)` — wrong whenever an
 *      old low-id category was re-parented under a newer high-id one
 *      (#68990); the `ctxfeed_cmapping_v5_max_id` filter restores it.
 *   5. Return the picked term's mapping value, or '' when no assigned
 *      term carries a non-empty mapping.
 *   6. For variations, the caller passes the parent product (V5
 *      `AttributeValueByType.php:281-284` enforces this; we do the
 *      same in `AttributeResolver`).
 *
 * @since 8.0.0
 */
class CategoryMappingResolver {

	/**
	 * V5 PRODUCT_CATEGORY_MAPPING_PREFIX — admin saves mappings as wp_options
	 * keyed `wf_cmapping_<slug>`. Same value V5 used.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PREFIX = 'wf_cmapping_';

	/**
	 * Merchants that support a Google-Category-List-style "suggestive"
	 * mapping. When the saved mapping has a `gcl-cmapping` array AND the
	 * mappingprovider is one of these, that array wins. Verbatim from
	 * V5 line 31-38.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private const SUGGESTIVE_LIST_MERCHANTS = array(
		'google',
		'facebook',
		'pinterest',
		'bing',
		'bing_local_inventory',
		'snapchat',
	);

	/**
	 * Whether the given attribute key routes through this resolver.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute key.
	 * @return bool
	 */
	public static function handles( string $attr ): bool {
		return 0 === strpos( $attr, self::PREFIX );
	}

	/**
	 * Resolve a category-mapping value for a product.
	 *
	 * Drop-in replacement for V5's `getCategoryMappingValue()`. Caller
	 * is responsible for passing the parent product when `$product`
	 * is a variation — `AttributeResolver` handles that swap before
	 * calling here.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product (NOT a variation).
	 * @param string      $attr    Full V5 attribute key (e.g. `wf_cmapping_my_google_map`).
	 * @param Config      $config  Feed configuration (forwarded; reserved for future hooks).
	 *
	 * @return string Mapped merchant category string, or '' if no match.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config is part of the shared resolver signature (documented above as reserved for future hooks).
		// The attribute key IS the option name — V5 stores mappings in
		// wp_options under the prefixed key directly (V5 line 29).
		$raw = get_option( $attr );
		if ( false === $raw || '' === $raw ) {
			return '';
		}

		$data = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $raw );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$has_cmapping     = isset( $data['cmapping'] );
		$has_gcl_cmapping = isset( $data['gcl-cmapping'] );

		if ( ! $has_cmapping && ! $has_gcl_cmapping ) {
			return '';
		}

		// Pick which mapping array to use — gcl-cmapping for suggestive
		// merchants if present, else cmapping.
		$provider = isset( $data['mappingprovider'] ) ? (string) $data['mappingprovider'] : '';
		$use_gcl  = $has_gcl_cmapping && in_array( $provider, self::SUGGESTIVE_LIST_MERCHANTS, true );

		$mapping = $use_gcl ? $data['gcl-cmapping'] : $data['cmapping'];

		if ( is_array( $mapping ) ) {
			// V5 reverses the mapping array (line 50/52). The reverse is
			// observable when iterating, but we use direct key lookup
			// below — array_reverse is a no-op for assoc-key access. Skip
			// it to avoid the allocation.
			$mapping_assoc = $mapping;
		} else {
			// Non-array mapping → nothing to look up.
			return '';
		}

		// Get product's `product_cat` terms.
		$categories = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $categories ) || is_wp_error( $categories ) || ! is_array( $categories ) ) {
			return '';
		}

		$term_ids = array();
		foreach ( $categories as $category ) {
			if ( isset( $category->term_id ) ) {
				$term_ids[] = (int) $category->term_id;
			}
		}

		if ( empty( $term_ids ) ) {
			return '';
		}

		// Mapped value per product term — its own row, or the row of one of
		// its translations (CBT-744). A multilingual store maps its categories
		// once, in the default language; a translated product carries the
		// translated term ids, which have no row of their own.
		$values = array();
		foreach ( $term_ids as $term_id ) {
			$value = $this->mapped_value( $mapping_assoc, $term_id );
			if ( '' !== $value ) {
				$values[ $term_id ] = $value;
			}
		}

		/**
		 * Restore V5's literal `max($term_ids)` term pick.
		 *
		 * V5 picked the "deepest" category as the HIGHEST TERM ID — true
		 * only when children were created after their parents. On stores
		 * that re-parented old categories under newer ones, max() lands on
		 * the (often unmapped, or generically mapped) PARENT: mapped leaf
		 * categories shipped an empty or parent-level value (#68990,
		 * 809/1154 products). 8.0.15 picks the deepest MAPPED term by real
		 * hierarchy instead. This filter is the escape hatch for a site
		 * that depended on the exact V5 pick.
		 *
		 * @since 8.0.15
		 *
		 * @param bool $use_v5 Default false.
		 */
		if ( apply_filters( 'ctxfeed_cmapping_v5_max_id', false ) ) {
			$picked = max( $term_ids );

			return $values[ $picked ] ?? '';
		}

		// Pick the deepest MAPPED term by real hierarchy: only terms that
		// carry a non-empty mapping value are candidates, depth comes from
		// get_ancestors() (cached), and a depth tie keeps max-id (the old
		// bias) so the pick stays deterministic. A product in
		// "Men (mapped) > T-Shirts (mapped)" gets the T-Shirts value, not
		// Men's, regardless of which term id is larger.
		$picked       = 0;
		$picked_depth = -1;
		foreach ( $term_ids as $term_id ) {
			if ( ! isset( $values[ $term_id ] ) ) {
				continue;
			}

			$depth = count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );

			if ( $depth > $picked_depth || ( $depth === $picked_depth && $term_id > $picked ) ) {
				$picked       = $term_id;
				$picked_depth = $depth;
			}
		}

		if ( -1 === $picked_depth ) {
			return '';
		}

		return $values[ $picked ];
	}

	/**
	 * Per-request memo of term translations (term id => translated ids).
	 *
	 * @var array<int, int[]>
	 */
	private $translations = array();

	/**
	 * The mapping value for a term: its own row, else the row of the first
	 * translation that has one (default language first). '' when none.
	 *
	 * @since 8.0.35
	 *
	 * @param array $mapping Mapping rows keyed by term id.
	 * @param int   $term_id Product term id.
	 * @return string
	 */
	private function mapped_value( array $mapping, int $term_id ): string {
		if ( isset( $mapping[ $term_id ] ) && '' !== $mapping[ $term_id ] && null !== $mapping[ $term_id ] ) {
			return (string) $mapping[ $term_id ];
		}

		if ( ! array_key_exists( $term_id, $this->translations ) ) {
			/**
			 * Translations of a product category term, for category mapping.
			 *
			 * Multilingual shims (WPML, Polylang in CTX Feed Pro) return the
			 * term ids of the same category in the other languages, default
			 * language first. Only asked for a term that has no mapping row.
			 *
			 * @since 8.0.35
			 *
			 * @param int[] $translations Translated term ids.
			 * @param int   $term_id      Product term id.
			 */
			$ids = apply_filters( 'ctxfeed_category_mapping_term_translations', array(), $term_id );

			$this->translations[ $term_id ] = array_values(
				array_filter(
					array_map( 'intval', is_array( $ids ) ? $ids : array() ),
					static function ( $id ) use ( $term_id ) {
						return $id > 0 && $id !== $term_id;
					}
				)
			);
		}

		foreach ( $this->translations[ $term_id ] as $translated_id ) {
			if ( isset( $mapping[ $translated_id ] ) && '' !== $mapping[ $translated_id ] && null !== $mapping[ $translated_id ] ) {
				return (string) $mapping[ $translated_id ];
			}
		}

		return '';
	}
}
