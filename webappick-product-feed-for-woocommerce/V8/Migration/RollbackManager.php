<?php
/**
 * Rollback Manager — Handles backup and restore for safe migration.
 * Creates snapshots of V5 config so users can safely revert.
 *
 * @package CTXFeed\V8\Migration
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Core\Logger;

/**
 * Creates and restores snapshots of the V5 feed configuration so a
 * V5→V8 migration can be safely rolled back.
 */
class RollbackManager {

	const BACKUP_KEY = 'ctxfeed_v5_backup';

	/**
	 * Create a backup of current V5 state.
	 */
	public function createBackup(): void {
		$backup = array(
			'engine'    => get_option( 'ctxfeed_engine', 'v5' ),
			'timestamp' => current_time( 'mysql' ),
			'feeds'     => $this->backupFeeds(),
			'settings'  => get_option( 'woo_feed_settings', array() ),
		);

		update_option( self::BACKUP_KEY, $backup, false );
		Logger::info( 'V5 backup created.' );
	}

	/**
	 * Restore from backup (rollback to V5).
	 */
	public function restore(): bool {
		$backup = get_option( self::BACKUP_KEY );

		if ( empty( $backup ) ) {
			Logger::error( 'No backup found for rollback.' );
			return false;
		}

		// Restore settings.
		update_option( 'woo_feed_settings', $backup['settings'] );

		// Restore feed configs.
		foreach ( $backup['feeds'] as $feed_name => $config ) {
			update_option( 'wf_feed_' . $feed_name, $config );
		}

		Logger::info( 'V5 backup restored.' );
		return true;
	}

	/**
	 * Check if a backup exists.
	 */
	public function hasBackup(): bool {
		return ! empty( get_option( self::BACKUP_KEY ) );
	}

	/**
	 * Collect every stored feed config (`wf_feed_*` option) for the backup snapshot.
	 *
	 * @return array Map of feed name => unserialized feed config.
	 */
	private function backupFeeds(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating options by LIKE pattern has no WP API equivalent; one-off migration backup, caching would risk a stale snapshot.
		$results = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wf_feed_%'",
			ARRAY_A
		);

		$feeds = array();
		foreach ( $results as $row ) {
			$feed_name           = str_replace( 'wf_feed_', '', $row['option_name'] );
			$feeds[ $feed_name ] = maybe_unserialize( $row['option_value'] );
		}

		return $feeds;
	}
}
