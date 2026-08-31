<?php
/**
 * TaxonomyResolver — Resolves product taxonomy values.
 *
 * Handles the 7 core category attributes with V5 semantic parity:
 *   • categories           — directly-assigned terms, parent-ASC sort,
 *                            joined with the " > " separator.
 *   • product_full_cat     — walk ancestors for each assigned term,
 *                            pick the LONGEST joined path.
 *   • primary_category     — lowest term_id (earliest-created) name.
 *   • primary_category_id  — lowest term_id (as string).
 *   • child_category       — highest term_id (latest-created) name.
 *   • child_category_id    — highest term_id (as string).
 *   • tags / product_tag   — flat join of tag names.
 *   • custom taxonomies    — hierarchical → ancestor path per term;
 *                            flat → term names joined.
 *
 * V5 parity notes:
 *   1. `primary_category` picks the LOWEST term_id, `child_category`
 *      picks the HIGHEST — V5's ProductInfo.php:254-473. Docblocks
 *      there claim "root" vs "leaf" but the actual selection is
 *      term_id-based, not hierarchy-based.
 *   2. V5 has a bug where `primary_category_id` / `child_category_id`
 *      skip the sort AND the variation-parent fallback that their
 *      name-variant siblings apply. V8 applies both consistently to
 *      all four to avoid variations returning 0 for the ID variants —
 *      that break is louder than the parity divergence, and customer
 *      shims can restore V5's exact behavior via the fired filters.
 *   3. Every V5 filter is fired here so ctx-compatibility shims and
 *      third-party integrations continue to work. Filter signatures
 *      match V5 exactly: `($value, $product, $config)` for the
 *      six per-attribute filters, `($separator, $config, $product)`
 *      for `woo_feed_product_type_separator` (V5's argument order is
 *      genuinely inconsistent here — preserved for parity).
 *   4. Yoast SEO / RankMath primary-category detection has been
 *      removed from the automatic path. Customers who want that
 *      behavior can hook `woo_feed_filter_product_primary_category`
 *      and re-implement in 5 lines. Automatic detection was a V8
 *      divergence that made "primary vs child" collapse to the same
 *      term whenever Yoast was installed.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-5.1, PROD-FRD-5.2, PROD-FRD-10.13
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy resolver.
 *
 * @since 8.0.0
 */
class TaxonomyResolver {

	/**
	 * V5 default fallback string when a product has no product_cat terms
	 * assigned. Matches V5 ProductInfo primary_category / child_category.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const V5_UNCATEGORIZED = 'Uncategorized';

	/**
	 * Resolve taxonomy terms for a product.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-5.1
	 *
	 * @param \WC_Product $product  WooCommerce product.
	 * @param string      $taxonomy Taxonomy name or category attribute key.
	 * @param Config      $config   Feed configuration.
	 *
	 * @return string
	 */
	public function resolve( \WC_Product $product, string $taxonomy, Config $config ): string {
		switch ( $taxonomy ) {
			case 'categories':
				return $this->resolve_categories( $product, $config );

			case 'product_full_cat':
				return $this->resolve_product_full_cat( $product, $config );

			case 'primary_category':
				return $this->resolve_primary_category( $product, $config );

			case 'primary_category_id':
				return $this->resolve_primary_category_id( $product, $config );

			case 'child_category':
				return $this->resolve_child_category( $product, $config );

			case 'child_category_id':
				return $this->resolve_child_category_id( $product, $config );
		}

		return $this->resolve_generic_taxonomy( $product, $taxonomy, $config );
	}

	/**
	 * `categories` — directly-assigned product_cat terms, sorted by
	 * `parent` ASC, joined with the configurable ` > ` separator.
	 *
	 * V5 reference: ProductInfo.php:301-326.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_categories( \WC_Product $product, Config $config ): string {
		$product_id = $this->effective_product_id( $product );
		$term_list  = get_the_terms( $product_id, 'product_cat' );

		$categories = '';

		if ( ! is_wp_error( $term_list ) && ! empty( $term_list ) ) {
			// Parent-ASC sort → root categories appear before children
			// when a product is assigned to both.
			$parents = array_column( $term_list, 'parent' );
			array_multisort( $parents, SORT_ASC, $term_list );

			$names = array_column( $term_list, 'name' );

			$separator = apply_filters(
				'woo_feed_product_type_separator',
				' > ',
				$config,
				$product
			);

			$categories = implode( $separator, $names );
		}

		return (string) apply_filters(
			'woo_feed_filter_product_categories',
			$categories,
			$product,
			$config
		);
	}

	/**
	 * `product_full_cat` — for every directly-assigned term, walks its
	 * full ancestor chain and joins root→leaf. Emits the LONGEST such
	 * path across all assigned terms.
	 *
	 * V5 reference: ProductInfo.php:508-530 + format_term_ids at 337-391.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_product_full_cat( \WC_Product $product, Config $config ): string {
		$product_id   = $this->effective_product_id( $product );
		$category_ids = get_the_terms( $product_id, 'product_cat' );

		$formatted_value = '';

		if ( ! is_wp_error( $category_ids ) && ! empty( $category_ids ) ) {
			$separator = apply_filters(
				'woo_feed_product_type_separator',
				' > ',
				$config,
				$product
			);

			foreach ( $category_ids as $term ) {
				$term_id      = is_object( $term ) ? $term->term_id : $term;
				$ancestor_ids = array_reverse( get_ancestors( $term_id, 'product_cat' ) );

				$formatted_term = array();
				foreach ( $ancestor_ids as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'product_cat' );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$formatted_term[] = $ancestor->name;
					}
				}
				$leaf_term = is_object( $term ) ? $term : get_term( $term_id, 'product_cat' );
				if ( $leaf_term && ! is_wp_error( $leaf_term ) ) {
					$formatted_term[] = $leaf_term->name;
				}

				$path = implode( $separator, $formatted_term );

				// V5 quirk: emit whichever assigned term produces the
				// LONGEST formatted string. Ties fall to first-seen.
				if ( strlen( $path ) > strlen( $formatted_value ) ) {
					$formatted_value = $path;
				}
			}
		}

		return (string) apply_filters(
			'woo_feed_filter_product_local_category',
			htmlspecialchars_decode( $formatted_value ),
			$product,
			$config
		);
	}

	/**
	 * `primary_category` — lowest term_id's name. V5 ProductInfo.php:254-277.
	 * Fallback: "Uncategorized".
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_primary_category( \WC_Product $product, Config $config ): string {
		$term_id = $this->pick_extremum_term_id( $product, 'lowest' );

		$primary = self::V5_UNCATEGORIZED;
		if ( $term_id > 0 ) {
			$term = get_term_by( 'id', $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$primary = $term->name;
			}
		}

		return (string) apply_filters(
			'woo_feed_filter_product_primary_category',
			$primary,
			$product,
			$config
		);
	}

	/**
	 * `primary_category_id` — lowest term_id (as string).
	 * V5 ProductInfo.php:400-415.
	 *
	 * V8 deliberately fixes V5's bug of skipping the sort + variation
	 * fallback here — see class docblock note 2.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_primary_category_id( \WC_Product $product, Config $config ): string {
		$term_id = $this->pick_extremum_term_id( $product, 'lowest' );

		$value = $term_id > 0 ? (string) $term_id : self::V5_UNCATEGORIZED;

		return (string) apply_filters(
			'woo_feed_filter_product_primary_category_id',
			$value,
			$product,
			$config
		);
	}

	/**
	 * `child_category` — highest term_id's name.
	 * V5 ProductInfo.php:424-448.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_child_category( \WC_Product $product, Config $config ): string {
		$term_id = $this->pick_extremum_term_id( $product, 'highest' );

		$child = self::V5_UNCATEGORIZED;
		if ( $term_id > 0 ) {
			$term = get_term_by( 'id', $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$child = $term->name;
			}
		}

		return (string) apply_filters(
			'woo_feed_filter_product_child_category',
			$child,
			$product,
			$config
		);
	}

	/**
	 * `child_category_id` — highest term_id (as string).
	 * V5 ProductInfo.php:457-473.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_child_category_id( \WC_Product $product, Config $config ): string {
		$term_id = $this->pick_extremum_term_id( $product, 'highest' );

		$value = $term_id > 0 ? (string) $term_id : self::V5_UNCATEGORIZED;

		return (string) apply_filters(
			'woo_feed_filter_product_child_category_id',
			$value,
			$product,
			$config
		);
	}

	/**
	 * Return the product ID to read categories from — the parent for
	 * variations (so a variation inherits its parent's category set),
	 * otherwise the product's own ID.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product (or variation) being resolved.
	 * @return int
	 */
	private function effective_product_id( \WC_Product $product ): int {
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 ) {
				return $parent_id;
			}
		}
		return (int) $product->get_id();
	}

	/**
	 * Pick either the lowest or highest term_id from the product's
	 * assigned product_cat terms. Shared implementation for the four
	 * primary/child variants so their selection rule stays consistent.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product   WooCommerce product.
	 * @param string      $extremum  'lowest' | 'highest'.
	 * @return int Term ID or 0 if the product has no categories.
	 */
	private function pick_extremum_term_id( \WC_Product $product, string $extremum ): int {
		$source = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$source = $parent;
			}
		}

		$category_ids = method_exists( $source, 'get_category_ids' )
			? (array) $source->get_category_ids()
			: array();

		if ( empty( $category_ids ) ) {
			return 0;
		}

		sort( $category_ids );

		if ( 'highest' === $extremum ) {
			$category_ids = array_reverse( $category_ids );
		}

		return (int) $category_ids[0];
	}

	/**
	 * Fallback for tags and custom taxonomies. Hierarchical taxonomies
	 * emit ancestor paths (V8 behavior — kept because V5 tags/custom
	 * taxonomies have no equivalent semantics to preserve); flat
	 * taxonomies join term names with the configured separator.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product  WooCommerce product.
	 * @param string      $taxonomy Taxonomy name (e.g., product_tag).
	 * @param Config      $config   Feed configuration.
	 * @return string
	 */
	private function resolve_generic_taxonomy( \WC_Product $product, string $taxonomy, Config $config ): string {
		$terms = wp_get_post_terms(
			$product->get_id(),
			$taxonomy,
			array( 'fields' => 'all' )
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$separator = $config->get( 'separator', ', ' );

		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$paths = array();
			foreach ( $terms as $term ) {
				$paths[] = $this->build_ancestor_path( $term );
			}
			return implode( $separator, $paths );
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $term->name;
		}
		return implode( $separator, $names );
	}

	/**
	 * Build full ancestor path for a hierarchical term. Used by
	 * resolve_generic_taxonomy — the six per-attribute methods above
	 * have their own path logic tuned to V5 semantics.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_Term $term WordPress term object.
	 * @return string Full category path.
	 */
	private function build_ancestor_path( \WP_Term $term ): string {
		$ancestors = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );

		if ( empty( $ancestors ) ) {
			return $term->name;
		}

		$ancestors  = array_reverse( $ancestors );
		$path_parts = array();

		foreach ( $ancestors as $ancestor_id ) {
			$ancestor_term = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor_term && ! is_wp_error( $ancestor_term ) ) {
				$path_parts[] = $ancestor_term->name;
			}
		}

		$path_parts[] = $term->name;

		return implode( ' > ', $path_parts );
	}
}
