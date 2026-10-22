<?php
/**
 * SharedFeedFile — feeds that write the SAME feed file (CBT-835).
 *
 * A feed's file name is `sanitize_file_name( slug )`, which drops `%`. A
 * named duplicate or import made before 8.0.39 kept a `%HH` slug
 * (`%d1%84%d0%b8%d0%b4-google`) whose file is the original's
 * (`d184d0b8d0b4-google.xml`): each generation overwrites the other's output
 * and deleting either deleted the shared file. 8.0.39 stopped new pairs
 * (CBT-812); this class finds the existing ones, keeps a delete from taking
 * a file another feed still writes, and gives the copy its own slug when the
 * user asks.
 *
 * Cost: only `wf_feed_` names containing `%` can share a file (one indexed
 * LIKE on option_name, almost always zero rows); the admin-load scan runs
 * once per plugin version and on demand after a delete or split.
 *
 * @package    CTXFeed
 * @subpackage V8\Feed
 * @since      8.0.40
 */

namespace CTXFeed\V8\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\V8\Utility\Filesystem;
use CTXFeed\V8\Utility\Sanitizer;

/**
 * Detects and guards feeds that share one feed file.
 *
 * @since 8.0.40
 */
class SharedFeedFile {

	/**
	 * Option caching the last scan: { version, pairs }.
	 *
	 * @var string
	 */
	const SCAN_OPTION = 'ctxfeed_shared_feed_files';

	/**
	 * Feed slugs whose stored feed writes the same file as this one, this one
	 * excluded.
	 *
	 * @since 8.0.40
	 *
	 * @param string        $slug    Feed slug.
	 * @param array         $rules   Its feedrules (provider + feedType).
	 * @param string[]|null $percent `%HH` feed slugs when the caller already read them.
	 * @return string[]
	 */
	public static function other_owners( string $slug, array $rules, ?array $percent = null ): array {
		$ext = (string) ( $rules['feedType'] ?? '' );
		if ( '' === $slug || '' === $ext ) {
			return array();
		}
		$fs   = new Filesystem();
		$path = $fs->get_feed_path( $slug, $ext, (string) ( $rules['provider'] ?? '' ) );

		$owners = array();
		foreach ( self::candidates( $slug, null === $percent ? self::percent_slugs() : $percent ) as $other ) {
			$other_rules = self::rules_of( $other );
			if ( null === $other_rules ) {
				continue;
			}
			$other_ext = (string) ( $other_rules['feedType'] ?? '' );
			if ( '' !== $other_ext && $fs->get_feed_path( $other, $other_ext, (string) ( $other_rules['provider'] ?? '' ) ) === $path ) {
				$owners[] = $other;
			}
		}

		return $owners;
	}

	/**
	 * Every pair that shares a file now, as `[ copy, original ]` — the copy is
	 * the `%HH` slug (the one the split renames), the original the slug whose
	 * stored name equals the file name (or another `%HH` slug).
	 *
	 * @since 8.0.40
	 *
	 * @return array<int,array{copy:string,original:string}>
	 */
	public static function detect(): array {
		$pairs   = array();
		$seen    = array();
		$percent = self::percent_slugs();
		foreach ( $percent as $slug ) {
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$rules = self::rules_of( $slug );
			if ( null === $rules ) {
				continue;
			}
			foreach ( self::other_owners( $slug, $rules, $percent ) as $other ) {
				if ( isset( $seen[ $other ] ) ) {
					continue;
				}
				$pairs[]       = array(
					'copy'     => $slug,
					'original' => $other,
				);
				$seen[ $slug ] = true;
				break;
			}
		}

		return $pairs;
	}

	/**
	 * The cached pairs — scanned again when the plugin version changed since
	 * the last scan (or after {@see forget()}).
	 *
	 * @since 8.0.40
	 *
	 * @param string $version Current plugin version.
	 * @return array<int,array{copy:string,original:string}>
	 */
	public static function pairs( string $version ): array {
		$cached = get_option( self::SCAN_OPTION, array() );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['pairs'] ) && $version === $cached['version'] && is_array( $cached['pairs'] ) ) {
			return $cached['pairs'];
		}

		$pairs = self::detect();
		update_option(
			self::SCAN_OPTION,
			array(
				'version' => $version,
				'pairs'   => $pairs,
			),
			false
		);

		return $pairs;
	}

	/**
	 * Drop the cached scan (a feed was deleted or split).
	 *
	 * @since 8.0.40
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::SCAN_OPTION );
	}

	/**
	 * Slugs that may write the same file as this one: its `%`-free form, and
	 * every `%HH` slug whose file name is this slug's file name.
	 *
	 * @param string   $slug    Feed slug.
	 * @param string[] $percent `%HH` feed slugs.
	 * @return string[]
	 */
	private static function candidates( string $slug, array $percent ): array {
		$file_name  = sanitize_file_name( $slug );
		$candidates = array();
		if ( $file_name !== $slug && '' !== $file_name ) {
			$candidates[] = $file_name;
		}
		foreach ( $percent as $other ) {
			if ( $other !== $slug && sanitize_file_name( $other ) === $file_name ) {
				$candidates[] = $other;
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Feed slugs containing `%` (one indexed LIKE on option_name).
	 *
	 * @return string[]
	 */
	private static function percent_slugs(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under wf_feed_ (shared with V5, no migration); admin-only, once per version / on delete — never during generation, never cached so a just-saved feed counts.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'wf_feed_' ) . '%' . $wpdb->esc_like( '%' ) . '%'
			)
		);

		if ( empty( $names ) || ! is_array( $names ) ) {
			return array();
		}

		return array_map(
			static function ( $name ) {
				return substr( (string) $name, strlen( 'wf_feed_' ) );
			},
			$names
		);
	}

	/**
	 * A stored feed's feedrules, or null when there is no such feed.
	 *
	 * @param string $slug Feed slug.
	 * @return array|null
	 */
	private static function rules_of( string $slug ): ?array {
		$stored = get_option( 'wf_feed_' . $slug );
		if ( false === $stored ) {
			return null;
		}
		$feed = Sanitizer::safe_unserialize( Sanitizer::safe_unserialize( $stored ) );

		return is_array( $feed ) && isset( $feed['feedrules'] ) && is_array( $feed['feedrules'] ) ? $feed['feedrules'] : null;
	}
}
