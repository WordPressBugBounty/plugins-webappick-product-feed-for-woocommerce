<?php
/**
 * Dashboard Service — Aggregates metrics from all feeds and channels
 * into a unified overview for the admin dashboard.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-1.1, DASH-FRD-5.1
 */

namespace CTXFeed\V8\Dashboard;

use CTXFeed\V8\Feed\FeedManager;
use CTXFeed\V8\Channel\ChannelRegistry;

/**
 * Class DashboardService
 *
 * Aggregates stored metrics, channel performance and widget definitions into
 * the payloads consumed by the dashboard REST endpoints, with transient caching.
 *
 * @since 8.0.0
 */
class DashboardService {

	/**
	 * Metrics persistence layer.
	 *
	 * @since 8.0.0
	 * @var MetricsStorage
	 */
	private MetricsStorage $storage;

	/**
	 * Per-channel metrics aggregator.
	 *
	 * @since 8.0.0
	 * @var ChannelPerformance
	 */
	private ChannelPerformance $channelPerformance;

	/**
	 * Dashboard widget definitions.
	 *
	 * @since 8.0.0
	 * @var WidgetRegistry
	 */
	private WidgetRegistry $widgetRegistry;

	/**
	 * Feed manager instance for loading feed names and configs.
	 *
	 * @since 8.0.0
	 * @var FeedManager
	 */
	private FeedManager $manager;

	/**
	 * Cache key for dashboard overview transient.
	 */
	private const CACHE_KEY = 'ctxfeed_dashboard_overview';

	/**
	 * Cache TTL in seconds (5 minutes).
	 */
	private const CACHE_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param MetricsStorage     $storage            Metrics persistence layer.
	 * @param ChannelPerformance $channelPerformance Per-channel metrics aggregator.
	 * @param WidgetRegistry     $widgetRegistry     Dashboard widget definitions.
	 */
	public function __construct(
		MetricsStorage $storage,
		ChannelPerformance $channelPerformance,
		WidgetRegistry $widgetRegistry
	) {
		$this->storage            = $storage;
		$this->channelPerformance = $channelPerformance;
		$this->widgetRegistry     = $widgetRegistry;
		$this->manager            = new FeedManager();
	}

	/**
	 * Register hooks for dashboard functionality.
	 */
	public function boot(): void {
		add_action( 'ctxfeed_feed_finalized', array( $this, 'onFeedFinalized' ), 10, 2 );
		add_action( 'ctxfeed_dashboard_cache_clear', array( $this, 'clearCache' ) );
	}

	/**
	 * Get aggregated dashboard overview.
	 *
	 * @return array{total_feeds: int, total_products: int, avg_health_score: float,
	 *               last_generation: string|null, active_channels: int, feeds_with_errors: int,
	 *               widgets: array}
	 */
	public function getOverview(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$feeds    = $this->manager->get_all_feed_names();
		$metrics  = $this->storage->queryLatest( count( $feeds ) );
		$channels = $this->channelPerformance->getChannelGrid();

		$overview = array(
			'total_feeds'       => count( $feeds ),
			'total_products'    => $this->sumField( $metrics, 'products_processed' ),
			'avg_health_score'  => $this->avgField( $metrics, 'health_score' ),
			'last_generation'   => $this->latestTimestamp( $metrics ),
			'active_channels'   => count( $channels ),
			'feeds_with_errors' => $this->countWithErrors( $metrics ),
			'widgets'           => $this->widgetRegistry->getWidgets(),
		);

		$overview = apply_filters( 'ctxfeed_dashboard_overview', $overview );

		set_transient( self::CACHE_KEY, $overview, self::CACHE_TTL );

		return $overview;
	}

	/**
	 * Get recent feed activity timeline.
	 *
	 * @param int    $limit   Maximum events to return.
	 * @param int    $offset  Pagination offset.
	 * @param string $channel Filter by channel slug (empty for all).
	 * @param string $status  Filter by status (empty for all).
	 *
	 * @return array Activity events sorted by recorded_at DESC.
	 */
	public function getActivity( int $limit = 20, int $offset = 0, string $channel = '', string $status = '' ): array {
		return $this->storage->queryActivity( $limit, $offset, $channel, $status );
	}

	/**
	 * Get historical metrics for charting.
	 *
	 * @param string $start_date  Start date (Y-m-d).
	 * @param string $end_date    End date (Y-m-d).
	 * @param string $channel     Filter by channel (empty for all).
	 * @param string $granularity Aggregation: 'daily' or 'weekly'.
	 *
	 * @return array{labels: string[], datasets: array[]}
	 */
	public function getMetrics( string $start_date, string $end_date, string $channel = '', string $granularity = 'daily' ): array {
		return $this->storage->queryTimeseries( $start_date, $end_date, $channel, $granularity );
	}

	/**
	 * Get active alerts for feeds with health issues.
	 *
	 * @return array Feeds with health_score < 50 or errors > 0.
	 */
	public function getAlerts(): array {
		$alerts = $this->storage->queryByStatus( 'unhealthy' );

		return apply_filters( 'ctxfeed_dashboard_alerts', $alerts );
	}

	/**
	 * Handle feed finalization — clear dashboard cache so fresh data appears.
	 *
	 * @param string $feed_name    Feed identifier.
	 * @param int    $health_score Feed health score (0-100).
	 */
	public function onFeedFinalized( string $feed_name, int $health_score ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Both parameters are supplied by the `ctxfeed_feed_finalized` action (accepted_args 2); this callback only needs the event itself to invalidate the cache.
		$this->clearCache();
	}

	/**
	 * Clear all dashboard transient caches.
	 *
	 * Called when new metrics are recorded (via ctxfeed_metrics_recorded action)
	 * to ensure the dashboard displays fresh data on next load.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-6.3
	 *
	 * @return void
	 */
	public function clearCache(): void {
		// Overview summary card cache.
		delete_transient( self::CACHE_KEY );

		// Activity timeline caches (may have filter-specific variants).
		delete_transient( 'ctxfeed_activity' );

		// Alerts cache.
		delete_transient( 'ctxfeed_alerts' );

		// Per-channel detail caches.
		$channels = $this->channelPerformance->getChannelGrid();
		foreach ( $channels as $channel ) {
			if ( ! empty( $channel['slug'] ) ) {
				delete_transient( 'ctxfeed_channel_detail_' . $channel['slug'] );
			}
		}

		// Metrics chart caches (common presets).
		foreach ( array( '7d', '30d', '90d' ) as $range ) {
			delete_transient( 'ctxfeed_metrics_throughput_' . $range );
			delete_transient( 'ctxfeed_metrics_health_trend_' . $range );
			delete_transient( 'ctxfeed_metrics_duration_trend_' . $range );
		}

		/**
		 * Fires after all dashboard caches have been cleared.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_dashboard_cache_cleared' );
	}

	/**
	 * Refresh dashboard — alias for clearCache() used by quick actions.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-6.3
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->clearCache();
	}

	/**
	 * Sum a numeric field across metrics rows.
	 *
	 * @param array  $metrics Metric rows keyed by column name.
	 * @param string $field   Column to sum.
	 *
	 * @return int Sum of the column across all rows.
	 */
	private function sumField( array $metrics, string $field ): int {
		return array_sum( array_column( $metrics, $field ) );
	}

	/**
	 * Average a numeric field across metrics rows.
	 *
	 * @param array  $metrics Metric rows keyed by column name.
	 * @param string $field   Column to average.
	 *
	 * @return float Mean of the column, rounded to one decimal, or 0.0 when empty.
	 */
	private function avgField( array $metrics, string $field ): float {
		if ( empty( $metrics ) ) {
			return 0.0;
		}
		return round( $this->sumField( $metrics, $field ) / count( $metrics ), 1 );
	}

	/**
	 * Get the most recent recorded_at timestamp.
	 *
	 * @param array $metrics Metric rows keyed by column name.
	 *
	 * @return string|null Latest `recorded_at` value, or null when empty.
	 */
	private function latestTimestamp( array $metrics ): ?string {
		if ( empty( $metrics ) ) {
			return null;
		}
		$dates = array_column( $metrics, 'recorded_at' );
		rsort( $dates );
		return $dates[0] ?? null;
	}

	/**
	 * Count feeds with error count > 0.
	 *
	 * @param array $metrics Metric rows keyed by column name.
	 *
	 * @return int Number of rows whose `errors` column is greater than zero.
	 */
	private function countWithErrors( array $metrics ): int {
		return count(
			array_filter(
				$metrics,
				function ( $row ) {
					return ( $row['errors'] ?? 0 ) > 0;
				} 
			) 
		);
	}
}
