<?php
/**
 * TermCache — cached term lookups for the feed hot loops.
 *
 * @package    CTXFeed
 * @subpackage V8\Product
 * @since      8.0.12
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term reads that consume the object-term cache CacheWarmer already primed.
 *
 * `wp_get_post_terms()` wraps `wp_get_object_terms()`, which is NOT cached —
 * it queries the database on every call, so each product paid one
 * relationship query per taxonomy even though `CacheWarmer::warm()` had
 * already bulk-primed the term cache. `get_the_terms()` reads that cache
 * (and populates it on a miss).
 *
 * Parity: `wp_get_post_terms()` orders by term NAME by default while the
 * cache preserves relationship order, so results are re-sorted
 * case-insensitively by name to keep separator-joined output stable.
 *
 * @since 8.0.12
 */
final class TermCache {

	/**
	 * Cached, name-sorted term objects for a post.
	 *
	 * @since 8.0.12
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return \WP_Term[]|\WP_Error Terms sorted by name, empty array when none,
	 *                              WP_Error for an invalid taxonomy.
	 */
	public static function get_terms( int $post_id, string $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( ! is_array( $terms ) ) {
			return array();
		}

		usort(
			$terms,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a->name, (string) $b->name );
			}
		);

		return $terms;
	}

	/**
	 * Cached term IDs for a post, name-sorted.
	 *
	 * @since 8.0.12
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return int[]|\WP_Error
	 */
	public static function get_ids( int $post_id, string $taxonomy ) {
		$terms = self::get_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_map(
			static function ( $term ) {
				return (int) $term->term_id;
			},
			$terms
		);
	}

	/**
	 * Cached term slugs for a post, name-sorted.
	 *
	 * @since 8.0.12
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function get_slugs( int $post_id, string $taxonomy ) {
		$terms = self::get_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_map(
			static function ( $term ) {
				return (string) $term->slug;
			},
			$terms
		);
	}
}
