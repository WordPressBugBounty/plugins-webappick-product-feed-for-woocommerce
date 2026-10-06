<?php
/**
 * CategoryMappingIdConverter — one-time upgrade sweep turning V5 full-text
 * Google category paths in saved category mappings into Google taxonomy IDs.
 *
 * V5 let merchants map a store category to a Google category as free text
 * ("Arts & Entertainment > Hobbies & Creative Arts > Collectibles > Scale
 * Models"). V8 stores the taxonomy ID ("37"), flags anything else as
 * "Unknown category" and — before CBT-693 — refused every save of a mapping
 * that still held such rows. This sweep rewrites each text value that
 * EXACTLY matches a path in the active Google taxonomy (case, HTML entities,
 * spacing around ">" ignored) to its ID, so old mappings read as Mapped and
 * ship the ID Google recommends.
 *
 * Careful by design:
 *   • Only mappings whose channel searches the GOOGLE list are touched
 *     (Google, Pinterest, Bing, Snapchat, TikTok …). Facebook-family
 *     mappings use Meta's own IDs — a Google path there is left alone.
 *   • A value is only replaced on an exact path match or an "ID - Path"
 *     label with a known ID; numeric IDs, empty values and anything
 *     unmatched stay byte-for-byte as they were.
 *   • `cmapping` and `gcl-cmapping` are converted independently; an empty
 *     value is never filled, so no product gains or loses a category.
 *   • The original option is copied to {@see BACKUP_OPTION} before the
 *     first rewrite, and the stored serialization shape (array or V5's
 *     double-serialized string) is preserved.
 *   • Runs once per site (flag), never during feed generation.
 *
 * @package    CTXFeed
 * @subpackage V8/Migration
 * @since      8.0.31
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\API\CategoryMappingEndpoint;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Utility\Sanitizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CategoryMappingIdConverter
 *
 * @since 8.0.31
 */
class CategoryMappingIdConverter {

	/**
	 * Flag option marking the sweep as done (bump the suffix to force a re-run).
	 *
	 * @var string
	 */
	const FLAG_OPTION = 'ctxfeed_cmapping_ids_converted_v1';

	/**
	 * Option holding the pre-conversion copy of every rewritten mapping,
	 * keyed by option name (autoload off).
	 *
	 * @var string
	 */
	const BACKUP_OPTION = 'ctxfeed_cmapping_v5_backup';

	/**
	 * The two V5 mapping arrays inside a `wf_cmapping_*` option.
	 *
	 * @var string[]
	 */
	const MAPPING_KEYS = array( 'cmapping', 'gcl-cmapping' );

	/**
	 * Normalize a category path for comparison: decode HTML entities (V5
	 * stored values through esc_attr), unify the ">" separator spacing,
	 * collapse whitespace and lowercase.
	 *
	 * @since 8.0.31
	 *
	 * @param string $value Raw path.
	 * @return string Comparison key.
	 */
	public static function normalize_path( string $value ): string {
		// Two passes cover a value escaped twice ("&amp;amp;").
		$value = html_entity_decode( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( "\xC2\xA0", ' ', $value );
		$value = (string) preg_replace( '/\s*>\s*/u', ' > ', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Build the lookup index from a parsed taxonomy list.
	 *
	 * @since 8.0.31
	 *
	 * @param array $categories Rows of [ 'id' => int, 'name' => string ].
	 * @return array{ids: array<string,bool>, paths: array<string,string>}
	 */
	public static function build_index( array $categories ): array {
		$ids   = array();
		$paths = array();

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) || ! isset( $category['name'] ) ) {
				continue;
			}
			$id         = (string) $category['id'];
			$ids[ $id ] = true;

			$key = self::normalize_path( (string) $category['name'] );
			// Google paths are unique; keep the first id if a list ever repeats one.
			if ( '' !== $key && ! isset( $paths[ $key ] ) ) {
				$paths[ $key ] = $id;
			}
		}

		return array(
			'ids'   => $ids,
			'paths' => $paths,
		);
	}

	/**
	 * Convert one stored value. Returns the taxonomy ID for an exact path
	 * match or a known "ID - Path" label; every other value is returned
	 * unchanged.
	 *
	 * @since 8.0.31
	 *
	 * @param string $value Stored mapping value.
	 * @param array  $index {@see build_index()} result.
	 * @return string Converted (or untouched) value.
	 */
	public static function convert_value( string $value, array $index ): string {
		$trimmed = trim( $value );

		// Empty and already-numeric values are never touched.
		if ( '' === $trimmed || ctype_digit( $trimmed ) ) {
			return $value;
		}

		// "37 - Arts & Entertainment > …" — the V8 picker's label form.
		if ( preg_match( '/^(\d+)\s*-\s/', $trimmed, $m ) && isset( $index['ids'][ $m[1] ] ) ) {
			return $m[1];
		}

		$key = self::normalize_path( $trimmed );
		if ( isset( $index['paths'][ $key ] ) ) {
			return $index['paths'][ $key ];
		}

		return $value;
	}

	/**
	 * Convert the text values of one mapping's arrays. Pure — no WordPress calls.
	 *
	 * @since 8.0.31
	 *
	 * @param array $data  Unserialized `wf_cmapping_*` option (V5 shape).
	 * @param array $index {@see build_index()} result.
	 * @return array{data: array, converted: int, unmatched: string[]}
	 */
	public static function convert_mapping( array $data, array $index ): array {
		$converted = 0;
		$unmatched = array();

		$provider = strtolower( trim( (string) ( $data['mappingprovider'] ?? '' ) ) );
		if ( ! in_array( $provider, CategoryMappingEndpoint::GOOGLE_TAXONOMY_TEMPLATES, true ) ) {
			return array(
				'data'      => $data,
				'converted' => 0,
				'unmatched' => array(),
			);
		}

		foreach ( self::MAPPING_KEYS as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}

			foreach ( $data[ $key ] as $term_id => $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}

				$new = self::convert_value( $value, $index );
				if ( $new !== $value ) {
					$data[ $key ][ $term_id ] = $new;
					++$converted;
					continue;
				}

				$trimmed = trim( $value );
				if ( '' !== $trimmed && ! ctype_digit( $trimmed ) ) {
					$unmatched[ $trimmed ] = true;
				}
			}
		}

		return array(
			'data'      => $data,
			'converted' => $converted,
			'unmatched' => array_keys( $unmatched ),
		);
	}

	/**
	 * Sweep every saved category mapping once. Idempotent; guarded by
	 * {@see FLAG_OPTION}.
	 *
	 * @since 8.0.31
	 *
	 * @return int Number of mappings rewritten.
	 */
	public function run(): int {
		if ( get_option( self::FLAG_OPTION ) ) {
			return 0;
		}

		/**
		 * Kill-switch for the V5 text → Google ID conversion (CBT-693).
		 *
		 * @since 8.0.31
		 *
		 * @param bool $convert Default true.
		 */
		if ( ! apply_filters( 'ctxfeed_cmapping_convert_text_to_ids', true ) ) {
			return 0;
		}

		$index = self::build_index( CategoryMappingEndpoint::google_taxonomy_categories() );
		if ( empty( $index['paths'] ) ) {
			// Unreadable list — leave everything and try again on the next update.
			Logger::info( 'CBT-693: Google taxonomy unreadable; category mapping ID conversion skipped.' );
			return 0;
		}

		$backups  = get_option( self::BACKUP_OPTION, array() );
		$backups  = is_array( $backups ) ? $backups : array();
		$changed  = 0;
		$rows     = 0;
		$leftover = 0;

		foreach ( $this->mapping_option_names() as $option_name ) {
			$raw = get_option( $option_name );

			// V5 wrote serialize()d strings through update_option, so the
			// value can arrive still serialized once (or more) after WP's own
			// unserialize. Count the layers to write the same shape back.
			$data   = $raw;
			$layers = 0;
			while ( is_string( $data ) && is_serialized( $data ) && $layers < 3 ) {
				$data = Sanitizer::safe_unserialize( $data );
				++$layers;
			}
			if ( ! is_array( $data ) ) {
				continue;
			}

			$result = self::convert_mapping( $data, $index );
			if ( 0 === $result['converted'] ) {
				continue;
			}

			// Keep the oldest copy — a forced re-run must not overwrite the V5 original.
			if ( ! isset( $backups[ $option_name ] ) ) {
				$backups[ $option_name ] = $raw;
				update_option( self::BACKUP_OPTION, $backups, false );
			}

			$new = $result['data'];
			for ( $i = 0; $i < $layers; $i++ ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Re-wraps the value in the exact serialization layers V5 stored it with, so V5 and V8 readers see the same shape as before.
				$new = serialize( $new );
			}
			update_option( $option_name, $new );

			++$changed;
			$rows     += $result['converted'];
			$leftover += count( $result['unmatched'] );

			if ( ! empty( $result['unmatched'] ) ) {
				Logger::info(
					sprintf( 'CBT-693: %s kept %d value(s) not found in the Google taxonomy.', $option_name, count( $result['unmatched'] ) ),
					array( 'unmatched' => array_slice( $result['unmatched'], 0, 20 ) )
				);
			}
		}

		update_option( self::FLAG_OPTION, time(), false );

		if ( $changed > 0 ) {
			Logger::info(
				sprintf( 'CBT-693: converted %d category mapping value(s) to Google IDs across %d mapping(s); %d text value(s) left as-is.', $rows, $changed, $leftover ),
				array(
					'mappings'  => $changed,
					'converted' => $rows,
					'unmatched' => $leftover,
				)
			);
		}

		return $changed;
	}

	/**
	 * Every saved category mapping option name.
	 *
	 * @since 8.0.31
	 *
	 * @return string[]
	 */
	protected function mapping_option_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Category mappings live one per wp_options row under a shared prefix; get_option() cannot enumerate by prefix. Runs once per update.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( CategoryMappingEndpoint::PREFIX ) . '%'
			)
		);

		return is_array( $names ) ? $names : array();
	}
}
