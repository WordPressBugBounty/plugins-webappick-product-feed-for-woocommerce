<?php
/**
 * Metrics Collector — Captures feed generation metrics during and after processing.
 * Hooks into feed finalization to record performance data.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-3.1
 */

namespace CTXFeed\V8\Dashboard;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Feed\FeedManager;

/**
 * Class MetricsCollector
 *
 * Listens for feed generation and export events and persists the resulting
 * performance figures through MetricsStorage.
 *
 * @since 8.0.0
 */
class MetricsCollector {

	/**
	 * Metrics persistence layer.
	 *
	 * @since 8.0.0
	 * @var MetricsStorage
	 */
	private MetricsStorage $storage;

	/**
	 * Feed manager instance for loading configs and progress.
	 *
	 * @since 8.0.0
	 * @var FeedManager
	 */
	private FeedManager $manager;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param MetricsStorage $storage Metrics persistence layer.
	 */
	public function __construct( MetricsStorage $storage ) {
		$this->storage = $storage;
		$this->manager = new FeedManager();
	}

	/**
	 * Register collection hooks.
	 */
	public function boot(): void {
		add_action( 'ctxfeed_feed_finalized', array( $this, 'onFeedFinalized' ), 5, 2 );
		add_action( 'ctxfeed_feed_exported', array( $this, 'onFeedExported' ), 10, 2 );
	}

	/**
	 * Record metrics after a feed finishes generation.
	 *
	 * @param string $feed_name   Feed identifier.
	 * @param int    $health_score Feed health score (0-100).
	 */
	public function onFeedFinalized( string $feed_name, int $health_score ): void {
		// Dashboard metrics must never crash feed generation.
		try {
			$this->collectFinalizationMetrics( $feed_name, $health_score );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate last-resort diagnostic: metrics collection is best-effort and must never surface to the merchant nor abort feed generation.
			error_log( '[CTXFeed V8] MetricsCollector error (non-fatal): ' . $e->getMessage() );
		}
	}

	/**
	 * Internal: collect finalization metrics.
	 *
	 * @param string $feed_name   Feed identifier.
	 * @param int    $health_score Feed health score (0-100).
	 */
	private function collectFinalizationMetrics( string $feed_name, int $health_score ): void {
		$config   = $this->manager->get_config( $feed_name );
		$progress = $this->manager->get_progress( $feed_name );

		if ( null === $config ) {
			return;
		}

		$metric = array(
			'feed_name'          => $feed_name,
			// The channel/provider slug is stored under 'provider' (get_provider),
			// NOT 'channel' — reading 'channel' always returned 'unknown', so the
			// dashboard's per-channel breakdown was empty for every feed.
			'channel'            => '' !== $config->get_provider() ? $config->get_provider() : 'unknown',
			'metric_type'        => 'generation',
			'products_processed' => (int) ( $progress['total'] ?? 0 ),
			'generation_time_ms' => $this->calculateGenerationTime( $progress ),
			'health_score'       => $health_score,
			'file_size_bytes'    => $this->getFileSize( $config ),
			'errors'             => (int) ( $progress['errors'] ?? 0 ),
			'warnings'           => (int) ( $progress['warnings'] ?? 0 ),
			'metadata'           => wp_json_encode(
				array(
					'batch_count' => $progress['batch_count'] ?? 0,
					// Stored under 'feedType' (get_feed_type), not 'feed_type'.
					'feed_type'   => $config->get_feed_type(),
				) 
			),
			'recorded_at'        => current_time( 'mysql' ),
		);

		$metric = apply_filters( 'ctxfeed_metrics_before_save', $metric, $config );

		$this->storage->save( $metric );

		do_action( 'ctxfeed_metrics_recorded', $metric, $config );
	}

	/**
	 * Record export event metrics.
	 *
	 * @param string $feed_name Feed identifier.
	 * @param array  $export_result Export result data (destination, status).
	 */
	public function onFeedExported( string $feed_name, array $export_result ): void {
		try {
			$this->collectExportMetrics( $feed_name, $export_result );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate last-resort diagnostic: metrics collection is best-effort and must never surface to the merchant nor abort the export.
			error_log( '[CTXFeed V8] MetricsCollector export error (non-fatal): ' . $e->getMessage() );
		}
	}

	/**
	 * Internal: collect export metrics.
	 *
	 * @param string $feed_name     Feed identifier.
	 * @param array  $export_result Export result data.
	 */
	private function collectExportMetrics( string $feed_name, array $export_result ): void {
		$config = $this->manager->get_config( $feed_name );

		if ( null === $config ) {
			return;
		}

		$metric = array(
			'feed_name'          => $feed_name,
			'channel'            => '' !== $config->get_provider() ? $config->get_provider() : 'unknown',
			'metric_type'        => 'export',
			'products_processed' => 0,
			'generation_time_ms' => 0,
			'health_score'       => 0,
			'file_size_bytes'    => 0,
			'errors'             => ! empty( $export_result['error'] ) ? 1 : 0,
			'warnings'           => 0,
			'metadata'           => wp_json_encode( $export_result ),
			'recorded_at'        => current_time( 'mysql' ),
		);

		$this->storage->save( $metric );
	}

	/**
	 * Calculate generation time from progress timestamps.
	 *
	 * @param array $progress Feed progress data with start/end times.
	 *
	 * @return int Generation time in milliseconds.
	 */
	private function calculateGenerationTime( array $progress ): int {
		$start = $progress['started_at'] ?? 0;
		$end   = $progress['completed_at'] ?? 0;

		if ( $start > 0 && $end > 0 ) {
			return (int) ( ( $end - $start ) * 1000 );
		}

		return 0;
	}

	/**
	 * Get generated feed file size.
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return int File size in bytes, 0 if not found.
	 */
	private function getFileSize( Config $config ): int {
		$file_path = $config->get( 'file_path', '' );

		if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
			return (int) filesize( $file_path );
		}

		return 0;
	}
}
