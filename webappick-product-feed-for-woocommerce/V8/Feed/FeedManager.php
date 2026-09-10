<?php
/**
 * FeedManager — CRUD operations for feed configurations.
 *
 * Reads/writes feed configs from wp_options, compatible with V5 format.
 * Uses `wp_load_alloptions()` instead of raw SQL per AD-FEED-002.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-1.1, FEED-FRD-1.2, FEED-FRD-1.3, FEED-FRD-1.4
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed configuration manager.
 *
 * @since 8.0.0
 */
class FeedManager {

	/**
	 * Per-batch counters persisted alongside the core progress fields, read
	 * by the Manage Feeds live generation console.
	 *
	 * @since 8.0.10
	 */
	const BATCH_COUNTERS = array(
		'last_batch_written',
		'last_batch_skipped',
		'last_batch_excluded',
		'skipped_total',
		'written_total',
	);

	/**
	 * Get feed configuration by name.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.1
	 *
	 * @param string $feed_id Feed slug identifier.
	 *
	 * @return Config|null Config object or null if not found.
	 */
	public function get_config( string $feed_id ): ?Config {
		return Config::from_feed_name( $feed_id );
	}

	/**
	 * Save feed configuration.
	 *
	 * Applies the `ctxfeed_feed_config` filter before persisting.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.1
	 * @hook ctxfeed_feed_config Filter to modify feed config before save.
	 *
	 * @param string $feed_id Feed slug identifier.
	 * @param Config $config  Feed configuration object.
	 * @param string $url     Generated feed file URL.
	 *
	 * @return bool True on success.
	 */
	public function save_config( string $feed_id, Config $config, string $url = '' ): bool {
		$data = array(
			'feedrules'    => $config->to_array(),
			'url'          => $url,
			'last_updated' => current_time( 'Y-m-d H:i:s' ),
			'status'       => 1,
		);

		/**
		 * Filter feed configuration data before saving.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $data    Feed config data to be saved.
		 * @param string $feed_id Feed slug identifier.
		 */
		$data = apply_filters( 'ctxfeed_feed_config', $data, $feed_id );

		return update_option( 'wf_feed_' . $feed_id, $data, false );
	}

	/**
	 * Get all feed names from wp_options.
	 *
	 * Uses `wp_load_alloptions()` + `array_filter()` instead of raw SQL.
	 * This eliminates the `$wpdb->get_col()` call per AD-FEED-002.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.2
	 *
	 * @return string[] Array of feed slug strings.
	 */
	public function get_all_feed_names(): array {
		global $wpdb;

		// Query the options table directly, NOT wp_load_alloptions(): feed
		// rows are deliberately non-autoloaded (large serialized configs),
		// so the alloptions cache doesn't contain them on such sites — and
		// every enumeration-based maintenance pass (upgrade reconcile,
		// disabled-queue purge, the CBT-569 schedule self-heal) silently
		// skipped every feed. Same access pattern as FeedEndpoint::get_feeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- get_option() cannot enumerate by prefix and the rows are non-autoloaded; admin/maintenance paths only, never per-product.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'wf_feed_%'
			)
		);

		$feed_keys = array_filter(
			(array) $names,
			function ( $key ) {
				return 0 !== strpos( $key, 'wf_feed_version' );
			}
		);

		return array_map(
			function ( $key ) {
				return substr( $key, 8 ); // Remove 'wf_feed_' prefix (8 chars).
			},
			array_values( $feed_keys )
		);
	}

	/**
	 * Delete a feed and its generated file.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.3
	 *
	 * @param string $feed_id Feed slug identifier.
	 *
	 * @return bool True on success.
	 */
	public function delete_feed( string $feed_id ): bool {
		return delete_option( 'wf_feed_' . $feed_id );
	}

	/**
	 * Promote feed after generation: update wf_feed_{slug} with URL and timestamp.
	 *
	 * After feed file is generated, this method updates the stored feed option
	 * with the generated file URL and timestamp — matching the V5 promotion
	 * pattern (wf_config → wf_feed_ with metadata). Auto-update status is
	 * activated only on the first generation; later regenerations preserve
	 * whatever the user last set, so regenerating never re-enables a feed the
	 * user turned off.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.1
	 *
	 * @param string $feed_id   Feed slug identifier.
	 * @param string $file_path Absolute path to the generated feed file.
	 *
	 * @return bool True on success.
	 */
	public function promote_feed( string $feed_id, string $file_path ): bool {
		// Build the public URL from the file path.
		$upload_dir = wp_upload_dir();
		$url        = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );

		// Read existing wf_feed_ data (created during create_feed).
		$existing = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( get_option( 'wf_feed_' . $feed_id ) );

		if ( is_array( $existing ) && isset( $existing['feedrules'] ) ) {
			// Only the FIRST successful generation activates auto-update
			// (status 0→1, matching V5 promotion). An empty last_updated is
			// the "never generated" signal. Every regeneration after that
			// PRESERVES the stored status — regenerating a feed whose
			// auto-update the user turned off must not silently switch it
			// back on.
			$is_first_generation = empty( $existing['last_updated'] );
			$prior_status        = isset( $existing['status'] ) ? (int) $existing['status'] : 0;

			// Update existing entry with URL and timestamp; keep the user's
			// auto-update choice on regeneration.
			$existing['url']          = $url;
			$existing['last_updated'] = current_time( 'Y-m-d H:i:s' );
			$existing['status']       = $is_first_generation ? 1 : $prior_status;

			$saved = update_option( 'wf_feed_' . $feed_id, $existing, false );

			// Keep the recurring (auto-update) schedule consistent with the
			// resolved status: registered on first-gen activation, cancelled
			// when regenerating a feed whose auto-update is off — but NEVER
			// re-anchored: the interval counts from the run's start, and an
			// existing schedule is left exactly where it is.
			$this->sync_recurring_schedule( $feed_id, $existing, true );

			return $saved;
		}

		// Fallback: read feedrules from wf_config and create wf_feed_.
		$config_data = maybe_unserialize( get_option( 'wf_config' . $feed_id ) );
		if ( empty( $config_data ) ) {
			return false;
		}

		$feedrules = is_array( $config_data ) && isset( $config_data['feedrules'] )
			? $config_data['feedrules']
			: $config_data;

		$feed_data = array(
			'feedrules'    => $feedrules,
			'url'          => $url,
			'last_updated' => current_time( 'Y-m-d H:i:s' ),
			'status'       => 1,
		);

		$saved = update_option( 'wf_feed_' . $feed_id, $feed_data, false );

		$this->sync_recurring_schedule( $feed_id, $feed_data, true );

		return $saved;
	}

	/**
	 * Reconcile a feed's recurring schedule with its stored state.
	 *
	 * Pulls FeedScheduler from the DI container and delegates. Wrapped
	 * here as a private method so promote_feed (and any future writer
	 * inside FeedManager) doesn't have to repeat the container resolution.
	 *
	 * Failure to sync must NEVER break the feed save itself, so all
	 * exceptions are swallowed with an error_log() call.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id   Feed slug identifier.
	 * @param array  $feed_data Stored feed data array.
	 *
	 * @return void
	 * @param bool   $after_run True after a completed run: keep an existing schedule (never re-anchor), create only when missing, cancel when auto-update is off.
	 */
	private function sync_recurring_schedule( string $feed_id, array $feed_data, bool $after_run = false ): void {
		if ( ! defined( 'CTXFEED_V8_ACTIVE' ) || ! CTXFEED_V8_ACTIVE ) {
			return;
		}

		try {
			$container = \CTXFeed\V8\Core\Container::get_instance();
			if ( ! $container->has( 'feed.scheduler' ) ) {
				return;
			}
			/** @var FeedScheduler $scheduler */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
			$scheduler = $container->resolve( 'feed.scheduler' );
			if ( $after_run && method_exists( $scheduler, 'ensure_recurring_schedule' ) ) {
				$scheduler->ensure_recurring_schedule( $feed_id, $feed_data );
			} else {
				$scheduler->sync_recurring_schedule( $feed_id, $feed_data );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort diagnostics in a catch-all guard; the container/Logger may be the very thing that failed, and failing silently would hide schedule-sync breakage.
			error_log( '[CTXFeed V8] FeedManager::sync_recurring_schedule failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Update feed generation progress.
	 *
	 * Stores enriched progress data in a transient for UI polling via REST API.
	 * Supports both legacy 4-param signature and new array-based signature.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.4
	 *
	 * @param string    $feed_id Feed slug identifier.
	 * @param array|int $data    Progress data array, or legacy: products processed so far.
	 * @param int       $total   (Legacy) Total number of products.
	 * @param string    $status  (Legacy) Status enum: pending, generating, finalizing, completed, failed.
	 *
	 * @return void
	 */
	public function update_progress( string $feed_id, $data = array(), int $total = 0, string $status = 'generating' ): void {
		// Support legacy 4-param call: update_progress( $id, $current, $total, $status ).
		if ( is_int( $data ) ) {
			$current = $data;
			$data    = array(
				'current' => $current,
				'total'   => $total,
				'status'  => $status,
			);
		}

		// Merge with existing progress to preserve fields not being updated.
		$existing = $this->get_progress( $feed_id );

		$now = current_time( 'Y-m-d H:i:s' );

		$progress = array(
			'current'        => isset( $data['current'] ) ? (int) $data['current'] : $existing['current'],
			'total'          => isset( $data['total'] ) ? (int) $data['total'] : $existing['total'],
			'percent'        => 0,
			'status'         => isset( $data['status'] ) ? $data['status'] : $existing['status'],
			'trigger'        => isset( $data['trigger'] ) ? (string) $data['trigger'] : ( $existing['trigger'] ?? '' ),
			'batch_size'     => isset( $data['batch_size'] ) ? (int) $data['batch_size'] : $existing['batch_size'],
			'batches_done'   => isset( $data['batches_done'] ) ? (int) $data['batches_done'] : $existing['batches_done'],
			'batches_total'  => isset( $data['batches_total'] ) ? (int) $data['batches_total'] : $existing['batches_total'],
			'started_at'     => $existing['started_at'] ? $existing['started_at'] : $now,
			'updated_at'     => $now,
			'eta_seconds'    => isset( $data['eta_seconds'] ) ? (int) $data['eta_seconds'] : $existing['eta_seconds'],
			'avg_batch_time' => isset( $data['avg_batch_time'] ) ? (float) $data['avg_batch_time'] : $existing['avg_batch_time'],
			// End-of-batch stamp (microtime float) — the next batch reports
			// the scheduler gap from it. Must be listed here: the fixed key
			// list DROPS unknown fields (see the BATCH_COUNTERS note below).
			'batch_ended_at' => isset( $data['batch_ended_at'] ) ? (float) $data['batch_ended_at'] : (float) ( $existing['batch_ended_at'] ?? 0 ),
		);

		// Per-batch counters for the live generation console. These were
		// silently dropped by the fixed key list above, so the console always
		// read "0 products" — they now persist like every other field.
		foreach ( self::BATCH_COUNTERS as $key ) {
			$progress[ $key ] = isset( $data[ $key ] ) ? (int) $data[ $key ] : (int) ( $existing[ $key ] ?? 0 );
		}

		// Compute percent.
		if ( $progress['total'] > 0 ) {
			$progress['percent'] = (int) round( ( $progress['current'] / $progress['total'] ) * 100 );
		}

		set_transient( "ctxfeed_progress_{$feed_id}", $progress, HOUR_IN_SECONDS );
	}

	/**
	 * Touch a running feed's progress inside a batch: bump the processed count
	 * and the timestamp, nothing else. Called every few seconds from the batch
	 * loop so the admin sees products ticking up during a long batch and the
	 * stall detector knows the run is alive.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_id Feed slug.
	 * @param int    $current Products processed so far (cumulative).
	 * @return void
	 */
	public function heartbeat( string $feed_id, int $current ): void {
		$existing = $this->get_progress( $feed_id );
		if ( 'generating' !== ( $existing['status'] ?? '' ) ) {
			return;
		}
		$this->update_progress( $feed_id, array( 'current' => max( (int) $existing['current'], $current ) ) );
	}

	/**
	 * Get feed generation progress.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-1.4
	 *
	 * @param string $feed_id Feed slug identifier.
	 *
	 * @return array Progress data array with all enriched fields.
	 */
	public function get_progress( string $feed_id ): array {
		$progress = get_transient( "ctxfeed_progress_{$feed_id}" );

		if ( false === $progress ) {
			return array(
				'current'        => 0,
				'total'          => 0,
				'percent'        => 0,
				'status'         => 'pending',
				'trigger'        => '',
				'batch_size'     => 0,
				'batches_done'   => 0,
				'batches_total'  => 0,
				'started_at'     => '',
				'updated_at'     => '',
				'eta_seconds'    => 0,
				'avg_batch_time' => 0.0,
			) + array_fill_keys( self::BATCH_COUNTERS, 0 );
		}

		// Backfill any missing keys for backwards compatibility.
		return array_merge(
			array(
				'current'        => 0,
				'total'          => 0,
				'percent'        => 0,
				'status'         => 'pending',
				'trigger'        => '',
				'batch_size'     => 0,
				'batches_done'   => 0,
				'batches_total'  => 0,
				'started_at'     => '',
				'updated_at'     => '',
				'eta_seconds'    => 0,
				'avg_batch_time' => 0.0,
			) + array_fill_keys( self::BATCH_COUNTERS, 0 ),
			$progress
		);
	}
}
