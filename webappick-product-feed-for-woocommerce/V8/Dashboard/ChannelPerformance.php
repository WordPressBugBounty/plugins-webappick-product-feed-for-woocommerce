<?php
/**
 * Channel Performance — Aggregates feed metrics per merchant channel.
 * Powers the per-channel performance grid on the dashboard.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-2.1, DASH-FRD-2.2
 */

namespace CTXFeed\V8\Dashboard;

use CTXFeed\V8\Channel\ChannelRegistry;
use CTXFeed\V8\Feed\FeedManager;

/**
 * Class ChannelPerformance
 *
 * Rolls the stored feed metrics up per merchant channel for the dashboard
 * grid and the per-channel detail view.
 *
 * @since 8.0.0
 */
class ChannelPerformance {

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
	 * Get performance grid for all active channels.
	 *
	 * @return array[] Array of channel performance data:
	 *                 [{slug, name, feed_count, product_count, avg_health_score, last_run, status}]
	 */
	public function getChannelGrid(): array {
		$channel_metrics = $this->storage->getChannelMetrics();
		$registry        = $this->get_registry();
		$grid            = array();

		foreach ( $channel_metrics as $row ) {
			$slug = $row['channel'];
			$info = $registry[ $slug ] ?? null;

			$grid[] = array(
				'slug'             => $slug,
				'name'             => $info ? $info->getName() : ucfirst( $slug ),
				'feed_count'       => (int) $row['feed_count'],
				'product_count'    => (int) $row['total_products'],
				'avg_health_score' => round( (float) $row['avg_health_score'], 1 ),
				'last_run'         => $row['last_run'],
				'status'           => $this->determineStatus( $row ),
			);
		}

		$grid = apply_filters( 'ctxfeed_dashboard_channel_grid', $grid );

		usort(
			$grid,
			function ( $a, $b ) {
				return $b['feed_count'] <=> $a['feed_count'];
			} 
		);

		return $grid;
	}

	/**
	 * Get detailed metrics for a single channel.
	 *
	 * @param string $slug Channel slug (e.g., 'google', 'facebook').
	 *
	 * @return array|null Channel detail with per-feed breakdown, or null if not found.
	 */
	public function getChannelDetail( string $slug ): ?array {
		$feeds     = $this->manager->get_all_feed_names();
		$feed_data = array();

		foreach ( $feeds as $feed_name ) {
			$config = $this->manager->get_config( $feed_name );
			// Feeds store the channel slug under 'provider' (get_provider), not
			// 'channel' — matching on 'channel' excluded every feed, so the
			// channel-detail view was always empty.
			if ( null === $config || $config->get_provider() !== $slug ) {
				continue;
			}

			$progress = $this->manager->get_progress( $feed_name );
			$latest   = $this->storage->queryByFeedAndChannel( $feed_name, $slug, '1970-01-01', gmdate( 'Y-m-d' ) );
			$last     = ! empty( $latest ) ? $latest[0] : array();

			$feed_data[] = array(
				'name'         => $feed_name,
				'products'     => (int) ( $last['products_processed'] ?? $progress['total'] ?? 0 ),
				'health_score' => (int) ( $last['health_score'] ?? 0 ),
				'last_run'     => $last['recorded_at'] ?? null,
				'file_size'    => (int) ( $last['file_size_bytes'] ?? 0 ),
				'status'       => $progress['status'] ?? 'unknown',
				'errors'       => (int) ( $last['errors'] ?? 0 ),
				'warnings'     => (int) ( $last['warnings'] ?? 0 ),
			);
		}

		if ( empty( $feed_data ) ) {
			return null;
		}

		$registry = $this->get_registry();
		$info     = $registry[ $slug ] ?? null;

		return array(
			'slug'   => $slug,
			'name'   => $info ? $info->getName() : ucfirst( $slug ),
			'feeds'  => $feed_data,
			'totals' => array(
				'feed_count'       => count( $feed_data ),
				'total_products'   => array_sum( array_column( $feed_data, 'products' ) ),
				'avg_health_score' => round( array_sum( array_column( $feed_data, 'health_score' ) ) / count( $feed_data ), 1 ),
				'total_errors'     => array_sum( array_column( $feed_data, 'errors' ) ),
			),
		);
	}

	/**
	 * Get all registered channels from the DI container or fallback.
	 *
	 * @return array Channel registry map.
	 */
	private function get_registry(): array {
		$container = \CTXFeed\V8\Core\Container::get_instance();
		if ( $container->has( 'channel.registry' ) ) {
			$registry = $container->resolve( 'channel.registry' );
			return $registry->get_all();
		}
		return array();
	}

	/**
	 * Determine channel status from metrics.
	 *
	 * @param array $row Channel metrics row.
	 *
	 * @return string Status: 'healthy', 'warning', or 'error'.
	 */
	private function determineStatus( array $row ): string {
		if ( (int) ( $row['total_errors'] ?? 0 ) > 0 ) {
			return 'error';
		}

		if ( (float) ( $row['avg_health_score'] ?? 0 ) < 50 ) {
			return 'warning';
		}

		return 'healthy';
	}
}
