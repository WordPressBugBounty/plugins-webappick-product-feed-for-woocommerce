<?php
/**
 * Config Migrator — Converts V5 feedrules format to V8 Config objects.
 * Preserves all existing feed configurations during engine switch.
 *
 * @package CTXFeed\V8\Migration
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Core\Logger;

/**
 * Migrates stored V5 feed configurations (`wf_feed_*` feedrules) so the
 * V8 engine can read them, validating and tagging each config in place.
 */
class ConfigMigrator {

	/**
	 * Migrate all V5 feed configs.
	 *
	 * @return array Migration results per feed.
	 */
	public function migrateAll(): array {
		$feeds   = $this->getV5Feeds();
		$results = array();

		foreach ( $feeds as $feed_name ) {
			$results[ $feed_name ] = $this->migrateFeed( $feed_name );
		}

		return $results;
	}

	/**
	 * Migrate a single feed config.
	 *
	 * @param string $feed_name Feed name (the `wf_feed_` option suffix).
	 * @return array Migration result with `status` and optional `reason`.
	 */
	public function migrateFeed( string $feed_name ): array {
		$data = get_option( 'wf_feed_' . $feed_name, array() );

		if ( empty( $data ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'empty config',
			);
		}

		// V5 config is already stored in wp_options as feedrules.
		// V8 reads the same format via Config::fromFeedName().
		// No structural changes needed — just validate.
		$feedrules = maybe_unserialize( $data );

		if ( ! is_array( $feedrules ) || empty( $feedrules['feedrules'] ?? $feedrules ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'invalid format',
			);
		}

		// Mark as V8-compatible.
		$feedrules['_engine'] = 'v8';
		update_option( 'wf_feed_' . $feed_name, $feedrules );

		Logger::debug( "Migrated feed config: {$feed_name}" );

		return array( 'status' => 'migrated' );
	}

	/**
	 * List every stored V5 feed name (`wf_feed_*` option suffix).
	 *
	 * @return array Feed names.
	 */
	private function getV5Feeds(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating options by LIKE pattern has no WP API equivalent; one-off migration scan, caching would risk a stale feed list.
		$results = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wf_feed_%' AND option_name NOT LIKE '%_status'"
		);
		return array_map(
			function ( $name ) {
				return str_replace( 'wf_feed_', '', $name );
			},
			$results 
		);
	}
}
