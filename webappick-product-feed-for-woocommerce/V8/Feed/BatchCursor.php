<?php
/**
 * BatchCursor — one owner per batch of a generation run (CBT-735).
 *
 * Action Scheduler checks an action's status and then marks it running in
 * two separate queries, so two queue runners (server cron, the async
 * request runner, the browser-driven runner, the synchronous dispatch run
 * under DISABLE_WP_CRON) can BOTH execute the same pending batch. Each copy
 * schedules its own next batch, and from then on two chains walk the whole
 * catalogue into the same working file: every product is written twice
 * (HelpScout #69480 — 1,204 rows, 975 unique ids).
 *
 * The cursor is one option row per feed holding the position the run is
 * waiting for: "{run}|{position}", where position is a batch offset or
 * "final". A batch may only start by flipping it to "{run}|{position}|busy"
 * in ONE conditional UPDATE — the database lets exactly one process win.
 * The winner hands the cursor on to the next position the same way before
 * it queues the next action. A second copy of the batch, a stray action
 * from an older run, or a run superseded by a newer one finds a value it
 * does not expect and stops without writing.
 *
 * Reads and writes go straight to the options table: get_option() /
 * update_option() go through the object cache, where a compare-and-swap is
 * impossible and update_option() skips the write when the cached value
 * matches. The row is never autoloaded.
 *
 * Fails safe toward generating: when the database is unavailable, begin()
 * returns '' and the run's actions carry no token, which is the pre-8.0.34
 * behaviour (no claim, no drop).
 *
 * @package    CTXFeed\V8
 * @subpackage Feed
 * @since      8.0.34
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic per-feed batch cursor.
 *
 * @since 8.0.34
 */
class BatchCursor {

	/**
	 * Position name of the finalize step.
	 *
	 * @var string
	 */
	const FINALIZE = 'final';

	/**
	 * Option-name prefix.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'ctxfeed_batch_cursor_';

	/**
	 * Start a run: create a fresh token and point the cursor at offset 0.
	 *
	 * Unconditional on purpose — a new run supersedes any older one, whose
	 * remaining actions then lose every claim.
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name Feed slug.
	 * @return string Run token, or '' when the cursor could not be written.
	 */
	public function begin( string $feed_name ): string {
		try {
			$run = bin2hex( random_bytes( 6 ) );
		} catch ( \Exception $e ) {
			$run = substr( md5( uniqid( '', true ) ), 0, 12 );
		}

		return $this->write( $feed_name, $run . '|0' ) ? $run : '';
	}

	/**
	 * Claim a position: "{run}|{position}" → "{run}|{position}|busy".
	 *
	 * @since 8.0.34
	 *
	 * @param string     $feed_name Feed slug.
	 * @param string     $run       Run token.
	 * @param int|string $position  Batch offset or self::FINALIZE.
	 * @return bool True when THIS process owns the position.
	 */
	public function claim( string $feed_name, string $run, $position ): bool {
		return $this->swap( $feed_name, $run . '|' . $position, $run . '|' . $position . '|busy' );
	}

	/**
	 * Hand a claimed position on: "{run}|{from}|busy" → "{run}|{to}".
	 *
	 * Used to move to the next batch (or finalize), and with $to === $from
	 * to re-open the same offset for a smaller-batch retry.
	 *
	 * @since 8.0.34
	 *
	 * @param string     $feed_name Feed slug.
	 * @param string     $run       Run token.
	 * @param int|string $from      Position this process claimed.
	 * @param int|string $to        Position the run waits for next.
	 * @return bool False when the run was superseded or the claim is not ours.
	 */
	public function hand_off( string $feed_name, string $run, $from, $to ): bool {
		return $this->swap( $feed_name, $run . '|' . $from . '|busy', $run . '|' . $to );
	}

	/**
	 * Re-point the cursor of a stalled run (dead-chain revive).
	 *
	 * Conditional on the value just read, so a revive racing a live batch's
	 * hand-off loses instead of forking the chain.
	 *
	 * @since 8.0.34
	 *
	 * @param string     $feed_name Feed slug.
	 * @param string     $seen      Raw cursor value read by the caller.
	 * @param string     $run       Run token.
	 * @param int|string $to        Position to resume at.
	 * @return bool
	 */
	public function reopen( string $feed_name, string $seen, string $run, $to ): bool {
		return $this->swap( $feed_name, $seen, $run . '|' . $to );
	}

	/**
	 * Read the cursor.
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name Feed slug.
	 * @return array{raw:string,run:string,position:string,busy:bool}|null Null when absent or unreadable.
	 */
	public function read( string $feed_name ): ?array {
		$db = $this->db();
		if ( ! $db ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cursor must bypass the object cache (see class doc).
		$raw = $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name = %s LIMIT 1", $this->option_name( $feed_name ) ) );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw );

		return array(
			'raw'      => $raw,
			'run'      => (string) ( $parts[0] ?? '' ),
			'position' => (string) ( $parts[1] ?? '' ),
			'busy'     => 'busy' === ( $parts[2] ?? '' ),
		);
	}

	/**
	 * Delete the cursor (cancel / feed delete). Every queued action of the
	 * run then loses its claim.
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	public function clear( string $feed_name ): void {
		$db = $this->db();
		if ( ! $db ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cursor must bypass the object cache (see class doc).
		$db->query( $db->prepare( "DELETE FROM {$db->options} WHERE option_name = %s", $this->option_name( $feed_name ) ) );
		$this->forget_cached( $feed_name );
	}

	/**
	 * Option name for a feed (hashed when the slug would overflow the
	 * 191-character option_name column).
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name Feed slug.
	 * @return string
	 */
	public function option_name( string $feed_name ): string {
		$name = self::OPTION_PREFIX . $feed_name;

		return strlen( $name ) <= 191 ? $name : self::OPTION_PREFIX . md5( $feed_name );
	}

	/**
	 * Unconditional upsert.
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $value     New value.
	 * @return bool
	 */
	private function write( string $feed_name, string $value ): bool {
		$db = $this->db();
		if ( ! $db ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cursor must bypass the object cache (see class doc).
		$result = $db->query(
			$db->prepare(
				"INSERT INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$this->option_name( $feed_name ),
				$value
			)
		);
		$this->forget_cached( $feed_name );

		return false !== $result;
	}

	/**
	 * Atomic compare-and-swap: exactly one concurrent caller sees 1 row changed.
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $expected  Value the caller requires.
	 * @param string $value     Value to set.
	 * @return bool
	 */
	private function swap( string $feed_name, string $expected, string $value ): bool {
		$db = $this->db();
		if ( ! $db ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cursor must bypass the object cache (see class doc).
		$changed = $db->query(
			$db->prepare(
				"UPDATE {$db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$this->option_name( $feed_name ),
				$expected
			)
		);
		$this->forget_cached( $feed_name );

		return 1 === (int) $changed;
	}

	/**
	 * Drop any object-cache copy so get_option() readers never see a stale value.
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	private function forget_cached( string $feed_name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $this->option_name( $feed_name ), 'options' );
		}
	}

	/**
	 * The WordPress database object, when usable.
	 *
	 * @return \wpdb|null
	 */
	private function db() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}

		return $wpdb;
	}
}
