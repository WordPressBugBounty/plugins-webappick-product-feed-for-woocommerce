<?php
/**
 * FeedLookup — resolve a feed reference (slug, option name or option_id) to
 * its stored `wf_feed_*` row safely.
 *
 * Feeds live in wp_options as `wf_feed_<slug>`. The option NAME is unique;
 * the numeric option_id is not always: a wp_options table that lost its
 * AUTO_INCREMENT / primary key (a migration or a bad repair) gives every new
 * row option_id 0, so feeds and other plugins' settings share ids
 * (HelpScout #69369: Delete / Regenerate reached Avada's
 * fusion_registration_data, later "Feed not found" — CBT-805). So a feed
 * is looked up by NAME first; a numeric id is a fallback for older clients,
 * honoured only when exactly one feed row carries it and it is not 0.
 *
 * @package    CTXFeed
 * @subpackage V8\Feed
 * @since      8.0.37
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe feed-row lookup.
 *
 * @since 8.0.37
 */
final class FeedLookup {

	/**
	 * Option-name prefix of every feed.
	 */
	const PREFIX = 'wf_feed_';

	/**
	 * Characters a feed reference may carry in a REST URL (the `/feeds/{id}`
	 * route pattern).
	 */
	const ROUTE_SAFE = '/^[A-Za-z0-9_-]+$/';

	/**
	 * The stored feed row for a reference, or null.
	 *
	 * @since 8.0.37
	 *
	 * @param mixed $ref Slug, `wf_feed_<slug>`, or a numeric option_id.
	 * @return array{option_id:string,option_name:string,option_value:string}|null
	 */
	public static function row( $ref ): ?array {
		global $wpdb;

		$ref = is_scalar( $ref ) ? trim( (string) $ref ) : '';
		if ( '' === $ref ) {
			return null;
		}

		// 1. By name — unique, whatever state the table's ids are in.
		$option_name = 0 === strpos( $ref, self::PREFIX ) ? $ref : self::PREFIX . $ref;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under wf_feed_ (shared with V5, no migration) and are non-autoloaded; the row's option_id is needed too, which get_option() does not give. Admin REST only.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			return $row;
		}

		// 2. A numeric option_id (older clients): only a feed row, only a
		// unique one, never id 0 (every row of a broken table has it).
		if ( ! ctype_digit( $ref ) || 0 === (int) $ref ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id = %d AND option_name LIKE %s LIMIT 2",
				(int) $ref,
				$wpdb->esc_like( self::PREFIX ) . '%'
			),
			ARRAY_A
		);

		return is_array( $rows ) && 1 === count( $rows ) ? $rows[0] : null;
	}

	/**
	 * The reference the admin app should use for a feed: its slug when the
	 * REST route accepts it, else its option_id (a very old feed whose slug
	 * carries other characters).
	 *
	 * @since 8.0.37
	 *
	 * @param string     $option_name `wf_feed_<slug>`.
	 * @param int|string $option_id   Its option_id.
	 * @return string
	 */
	public static function ref( string $option_name, $option_id ): string {
		$slug = 0 === strpos( $option_name, self::PREFIX ) ? substr( $option_name, strlen( self::PREFIX ) ) : $option_name;

		return ( '' !== $slug && 1 === preg_match( self::ROUTE_SAFE, $slug ) ) ? $slug : (string) $option_id;
	}
}
