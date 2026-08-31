<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * TaxonomyRegistry — Central catalog of all WooCommerce product taxonomies.
 *
 * Discovers and organizes all taxonomies registered for the 'product'
 * post type: built-in (product_cat, product_tag), global attributes
 * (pa_*), and any custom taxonomies added by plugins. Used by the
 * admin UI for category mapping, product filtering, and taxonomy
 * dropdowns.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-13.2
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy registry.
 *
 * @since 8.0.0
 */
class TaxonomyRegistry {

	/**
	 * Built-in taxonomies that get special treatment.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private const CORE_TAXONOMIES = array(
		'product_cat',
		'product_tag',
		'product_shipping_class',
	);

	/**
	 * Internal WooCommerce taxonomies excluded from public listings.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private const HIDDEN_TAXONOMIES = array(
		'product_type',
		'product_visibility',
		'translation_priority',
	);

	/**
	 * Get all product taxonomies organized by group.
	 *
	 * Returns a filterable array with four groups:
	 *   - core:       product_cat, product_tag, product_shipping_class
	 *   - attributes: WooCommerce global attributes (pa_*)
	 *   - custom:     Third-party taxonomies (not pa_*, not hidden)
	 *   - all:        Flat list of every taxonomy
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-13.2
	 *
	 * @return array Grouped taxonomy catalog.
	 */
	public function get_all(): array {
		$product_taxonomies = get_object_taxonomies( 'product', 'objects' );
		$grouped            = array(
			'core'       => array(),
			'attributes' => array(),
			'custom'     => array(),
			'all'        => array(),
		);

		foreach ( $product_taxonomies as $slug => $taxonomy_obj ) {
			// Skip hidden taxonomies.
			if ( in_array( $slug, self::HIDDEN_TAXONOMIES, true ) ) {
				continue;
			}

			$entry = array(
				'slug'         => $slug,
				'label'        => $taxonomy_obj->label,
				'hierarchical' => $taxonomy_obj->hierarchical,
				'public'       => $taxonomy_obj->public,
			);

			if ( in_array( $slug, self::CORE_TAXONOMIES, true ) ) {
				$grouped['core'][] = $entry;
			} elseif ( 0 === strpos( $slug, 'pa_' ) ) {
				$grouped['attributes'][] = $entry;
			} else {
				$grouped['custom'][] = $entry;
			}

			$grouped['all'][] = $entry;
		}

		/**
		 * Filter the taxonomy registry catalog.
		 *
		 * Allows compat plugins and extensions to add custom taxonomy groups.
		 *
		 * @since 8.0.0
		 *
		 * @param array $grouped Grouped taxonomy catalog.
		 */
		return apply_filters( 'ctxfeed_taxonomy_registry', $grouped );
	}

	/**
	 * Get a single taxonomy with its terms.
	 *
	 * Returns taxonomy metadata plus a flat list of terms with
	 * parent references for hierarchical reconstruction.
	 *
	 * @since 8.0.0
	 *
	 * @param string $taxonomy_slug Taxonomy slug.
	 * @param bool   $hide_empty    Whether to hide terms with no products.
	 * @return array|null Taxonomy data or null if not found.
	 */
	public function get_taxonomy( string $taxonomy_slug, bool $hide_empty = false ): ?array {
		if ( ! taxonomy_exists( $taxonomy_slug ) ) {
			return null;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy_slug );

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_slug,
				'hide_empty' => $hide_empty,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) 
		);

		$term_list = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_list[] = array(
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'parent'  => $term->parent,
					'count'   => $term->count,
				);
			}
		}

		return array(
			'slug'         => $taxonomy_slug,
			'label'        => $taxonomy_obj->label,
			'hierarchical' => $taxonomy_obj->hierarchical,
			'public'       => $taxonomy_obj->public,
			'terms'        => $term_list,
			'total_terms'  => count( $term_list ),
		);
	}

	/**
	 * Get all product taxonomies with terms included.
	 *
	 * Heavier call than get_all() — includes term data for every taxonomy.
	 * Used when the admin UI needs the full taxonomy + term tree in one request.
	 *
	 * @since 8.0.0
	 *
	 * @param bool $hide_empty Whether to hide terms with no products.
	 * @return array Array of taxonomy data (each with terms).
	 */
	public function get_all_with_terms( bool $hide_empty = false ): array {
		$product_taxonomies = get_object_taxonomies( 'product', 'objects' );
		$result             = array();

		foreach ( $product_taxonomies as $slug => $taxonomy_obj ) {
			if ( in_array( $slug, self::HIDDEN_TAXONOMIES, true ) ) {
				continue;
			}

			$data = $this->get_taxonomy( $slug, $hide_empty );
			if ( null !== $data ) {
				$result[] = $data;
			}
		}

		/**
		 * Filter the full taxonomy + terms list.
		 *
		 * @since 8.0.0
		 *
		 * @param array $result     Array of taxonomy data with terms.
		 * @param bool  $hide_empty Whether empty terms were hidden.
		 */
		return apply_filters( 'ctxfeed_product_taxonomies', $result, $hide_empty );
	}

	/**
	 * Get term hierarchy as a flat list with full paths.
	 *
	 * Useful for category mapping where the admin UI needs to show
	 * full breadcrumb paths like "Clothing > Men > Shirts".
	 *
	 * @since 8.0.0
	 *
	 * @param string $taxonomy_slug Taxonomy slug (must be hierarchical).
	 * @param string $separator     Path separator.
	 * @return array Array of term_id => full_path pairs.
	 */
	public function get_term_paths( string $taxonomy_slug, string $separator = ' > ' ): array {
		if ( ! taxonomy_exists( $taxonomy_slug ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_slug,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) 
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Index terms by ID for parent lookups.
		$indexed = array();
		foreach ( $terms as $term ) {
			$indexed[ $term->term_id ] = $term;
		}

		$paths = array();
		foreach ( $terms as $term ) {
			$parts   = array();
			$current = $term;

			// Walk up the hierarchy.
			while ( $current ) {
				array_unshift( $parts, $current->name );
				$current = ( $current->parent > 0 && isset( $indexed[ $current->parent ] ) )
					? $indexed[ $current->parent ]
					: null;
			}

			$paths[ $term->term_id ] = implode( $separator, $parts );
		}

		/**
		 * Filter the term path list.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $paths         term_id => full_path pairs.
		 * @param string $taxonomy_slug Taxonomy slug.
		 * @param string $separator     Path separator used.
		 */
		return apply_filters( 'ctxfeed_term_paths', $paths, $taxonomy_slug, $separator );
	}

	/**
	 * Get only taxonomy slugs for the attribute mapping dropdown.
	 *
	 * Returns a simple slug => label map (matching the format used by
	 * ProductEndpoint::get_all_taxonomies() for the mapping dropdown).
	 * Uses the wf_taxo_ prefix expected by the V5 compatibility layer.
	 *
	 * @since 8.0.0
	 *
	 * @param string $prefix Value prefix for dropdown keys.
	 * @return array slug => label pairs.
	 */
	public function get_for_dropdown( string $prefix = 'wf_taxo_' ): array {
		global $wp_taxonomies;

		$product_taxonomies = get_object_taxonomies( 'product' );
		$exclude            = array_merge( self::HIDDEN_TAXONOMIES, array( 'product_cat', 'product_tag', 'product_shipping_class' ) );

		/**
		 * Filter additional taxonomies to exclude from the dropdown.
		 *
		 * @since 8.0.0
		 *
		 * @param array $user_excludes    Additional taxonomy slugs to exclude.
		 * @param array $default_excludes Default excluded slugs.
		 */
		$user_excludes = apply_filters( 'ctxfeed_dropdown_exclude_taxonomy', array(), $exclude );
		if ( ! empty( $user_excludes ) ) {
			$exclude = array_merge( $exclude, $user_excludes );
		}

		// V5 legacy filter for backward compatibility with existing extensions.
		$legacy_excludes = apply_filters( 'woo_feed_dropdown_exclude_taxonomy', null, $exclude );
		if ( is_array( $legacy_excludes ) && ! empty( $legacy_excludes ) ) {
			$exclude = array_merge( $exclude, $legacy_excludes );
		}

		$options = array();
		foreach ( $product_taxonomies as $taxonomy ) {
			$taxonomy = trim( $taxonomy );

			if ( in_array( $taxonomy, $exclude, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
				continue;
			}

			$label = isset( $wp_taxonomies[ $taxonomy ] )
				? $wp_taxonomies[ $taxonomy ]->label . " [{$taxonomy}]"
				: $taxonomy;

			$options[ $prefix . $taxonomy ] = $label;
		}

		return $options;
	}
}
