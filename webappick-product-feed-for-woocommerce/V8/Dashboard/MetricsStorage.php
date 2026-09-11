<?php
/**
 * Metrics Storage — Persists and queries feed performance metrics
 * from the custom wp_ctxfeed_metrics database table.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-4.1, DASH-FRD-4.2
 */

namespace CTXFeed\V8\Dashboard;

/**
 * Class MetricsStorage
 *
 * Owns the schema of, and every read/write against, the plugin's custom
 * `{prefix}ctxfeed_metrics` table. WordPress exposes no higher-level API for
 * plugin-owned tables, so all access here goes through $wpdb by design.
 *
 * @since 8.0.0
 */
class MetricsStorage {

	/**
	 * Table name (without prefix).
	 */
	private const TABLE_NAME = 'ctxfeed_metrics';

	/**
	 * Maximum retention days for historical metrics.
	 */
	private const RETENTION_DAYS = 90;

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @return string Full table name.
	 */
	private function getTableName(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the metrics table on plugin activation.
	 * Uses dbDelta for safe schema migrations.
	 */
	public function createTable(): void {
		global $wpdb;

		$table   = $this->getTableName();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feed_name VARCHAR(255) NOT NULL,
			channel VARCHAR(100) NOT NULL,
			metric_type ENUM('generation','health','export') NOT NULL DEFAULT 'generation',
			products_processed INT(11) NOT NULL DEFAULT 0,
			generation_time_ms INT(11) NOT NULL DEFAULT 0,
			health_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			file_size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			errors INT(11) NOT NULL DEFAULT 0,
			warnings INT(11) NOT NULL DEFAULT 0,
			metadata JSON,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_feed_channel (feed_name, channel),
			KEY idx_recorded (recorded_at),
			KEY idx_channel_date (channel, recorded_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Save a metrics record.
	 *
	 * @param array $metric Associative array of metric data.
	 *
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function save( array $metric ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into the plugin-owned ctxfeed_metrics table; WordPress offers no API for custom tables.
		$result = $wpdb->insert( $this->getTableName(), $metric );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Query latest metrics per feed (one row per feed, most recent).
	 *
	 * @param int $limit Maximum number of feeds.
	 *
	 * @return array Latest metric row per feed.
	 */
	public function queryLatest( int $limit = 100 ): array {
		global $wpdb;

		$table = $this->getTableName();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only, LIMIT-bounded, and cached one level up by DashboardService's `ctxfeed_dashboard_overview` transient.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.* FROM %i m
			 INNER JOIN (
			     SELECT feed_name, MAX(recorded_at) as max_date
			     FROM %i
			     WHERE metric_type = 'generation'
			     GROUP BY feed_name
			 ) latest ON m.feed_name = latest.feed_name AND m.recorded_at = latest.max_date
			 ORDER BY m.recorded_at DESC
			 LIMIT %d",
				$table,
				$table,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Query metrics by feed and channel with date range.
	 *
	 * @param string $feed_name Feed identifier.
	 * @param string $channel   Channel slug.
	 * @param string $start     Start date (Y-m-d).
	 * @param string $end       End date (Y-m-d).
	 *
	 * @return array Matching metric rows.
	 */
	public function queryByFeedAndChannel( string $feed_name, string $channel, string $start, string $end ): array {
		global $wpdb;

		$table = $this->getTableName();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only, on-demand and bounded by the caller's date range.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
			 WHERE feed_name = %s AND channel = %s
			 AND recorded_at BETWEEN %s AND %s
			 ORDER BY recorded_at DESC',
				$table,
				$feed_name,
				$channel,
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);
	}

	/**
	 * Query activity log with optional filters.
	 *
	 * @param int    $limit   Max results.
	 * @param int    $offset  Pagination offset.
	 * @param string $channel Filter by channel (empty = all).
	 * @param string $status  Filter by status: 'error', 'warning', 'success' (empty = all).
	 *
	 * @return array Activity records.
	 */
	public function queryActivity( int $limit = 20, int $offset = 0, string $channel = '', string $status = '' ): array {
		global $wpdb;

		$table = $this->getTableName();
		$where = array( '1=1' );
		$args  = array();

		if ( '' !== $channel ) {
			$where[] = 'channel = %s';
			$args[]  = $channel;
		}

		if ( 'error' === $status ) {
			$where[] = 'errors > 0';
		} elseif ( 'warning' === $status ) {
			$where[] = 'warnings > 0 AND errors = 0';
		} elseif ( 'success' === $status ) {
			$where[] = 'errors = 0 AND warnings = 0';
		}

		$where_clause = implode( ' AND ', $where );
		array_unshift( $args, $table );
		$args[] = $limit;
		$args[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_clause is assembled above exclusively from hard-coded fragments and %s placeholders — no user input is interpolated; the table identifier is bound with %i.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- False positive: the replacements are passed by argument unpacking (...$args), which the sniff cannot count; $args is built alongside $where_clause so the counts always match.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only, on-demand activity feed that must not be stale, and bounded by LIMIT/OFFSET.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
			 WHERE {$where_clause}
			 ORDER BY recorded_at DESC
			 LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Query time-series metrics for dashboard charts.
	 *
	 * @param string $start_date  Start date (Y-m-d).
	 * @param string $end_date    End date (Y-m-d).
	 * @param string $channel     Filter by channel (empty = all).
	 * @param string $granularity 'daily' or 'weekly'.
	 *
	 * @return array{labels: string[], datasets: array[]}
	 */
	public function queryTimeseries( string $start_date, string $end_date, string $channel = '', string $granularity = 'daily' ): array {
		global $wpdb;

		$table = $this->getTableName();

		$where = array( 'recorded_at BETWEEN %s AND %s', "metric_type = 'generation'" );
		$args  = array( $table, $start_date . ' 00:00:00', $end_date . ' 23:59:59' );

		if ( '' !== $channel ) {
			$where[] = 'channel = %s';
			$args[]  = $channel;
		}

		$where_clause = implode( ' AND ', $where );

		// The period expression is chosen as a whole-query literal per
		// granularity — never interpolated from a variable — so static
		// analysis (and wp.org's Plugin Check) can see the query text is
		// fixed. Both variants are identical apart from the SELECT's first
		// expression.
		if ( 'daily' === $granularity ) {
			$sql = "SELECT DATE(recorded_at) as period, channel,
			        SUM(products_processed) as total_products,
			        AVG(health_score) as avg_health,
			        AVG(generation_time_ms) as avg_time
			 FROM %i
			 WHERE {$where_clause}
			 GROUP BY period, channel
			 ORDER BY period ASC";
		} else {
			$sql = "SELECT YEARWEEK(recorded_at) as period, channel,
			        SUM(products_processed) as total_products,
			        AVG(health_score) as avg_health,
			        AVG(generation_time_ms) as avg_time
			 FROM %i
			 WHERE {$where_clause}
			 GROUP BY period, channel
			 ORDER BY period ASC";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is one of the two literals above with the table bound as %i; $where_clause is assembled exclusively from hard-coded fragments and %s placeholders — no user input is interpolated.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- False positive: the %s placeholders live inside $where_clause, which the sniff cannot see through, so it reports the query as placeholder-free.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only chart query bounded by the caller's date range and grouped server-side.
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $this->formatTimeseries( $rows );
	}

	/**
	 * Query feeds with health issues.
	 *
	 * @param string $status 'unhealthy' for score < 50 or errors > 0.
	 *
	 * @return array Feeds needing attention.
	 */
	public function queryByStatus( string $status ): array {
		global $wpdb;

		$table = $this->getTableName();

		if ( 'unhealthy' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only alerts list, cached one level up by DashboardService's `ctxfeed_alerts` transient.
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.* FROM %i m
				 INNER JOIN (
				     SELECT feed_name, MAX(recorded_at) as max_date
				     FROM %i WHERE metric_type = 'generation'
				     GROUP BY feed_name
				 ) latest ON m.feed_name = latest.feed_name AND m.recorded_at = latest.max_date
				 WHERE m.health_score < 50 OR m.errors > 0
				 ORDER BY m.errors DESC, m.health_score ASC",
					$table,
					$table
				),
				ARRAY_A
			);
		}

		return array();
	}

	/**
	 * Get aggregated metrics per channel.
	 *
	 * @return array Per-channel aggregated stats.
	 */
	public function getChannelMetrics(): array {
		global $wpdb;

		$table = $this->getTableName();

		// The outer table gets the alias m1 so the correlated subquery can
		// reference it through a plain identifier — %i binds simple
		// identifiers, not qualified {table}.column references.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin-owned ctxfeed_metrics table (no WP API); admin-only channel roll-up, cached one level up by DashboardService's `ctxfeed_dashboard_overview` transient.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel,
			        COUNT(DISTINCT feed_name) as feed_count,
			        SUM(products_processed) as total_products,
			        AVG(health_score) as avg_health_score,
			        MAX(recorded_at) as last_run,
			        SUM(errors) as total_errors
			 FROM %i m1
			 WHERE metric_type = 'generation'
			 AND recorded_at = (
			     SELECT MAX(m2.recorded_at) FROM %i m2
			     WHERE m2.feed_name = m1.feed_name AND m2.metric_type = 'generation'
			 )
			 GROUP BY channel
			 ORDER BY feed_count DESC",
				$table,
				$table
			),
			ARRAY_A
		);
	}

	/**
	 * Prune old metrics beyond retention period.
	 */
	public function prune(): void {
		global $wpdb;

		$table  = $this->getTableName();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_DAYS . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention prune of the plugin-owned ctxfeed_metrics table (no WP API); caching does not apply to a DELETE.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE recorded_at < %s',
				$table,
				$cutoff
			)
		);
	}

	/**
	 * Format raw query rows into chart-ready timeseries structure.
	 *
	 * @param array $rows Raw query results.
	 *
	 * @return array{labels: string[], datasets: array[]}
	 */
	private function formatTimeseries( array $rows ): array {
		$labels   = array();
		$datasets = array();

		foreach ( $rows as $row ) {
			$period  = $row['period'];
			$channel = $row['channel'];

			if ( ! in_array( $period, $labels, true ) ) {
				$labels[] = $period;
			}

			if ( ! isset( $datasets[ $channel ] ) ) {
				$datasets[ $channel ] = array(
					'channel' => $channel,
					'data'    => array(),
				);
			}

			$datasets[ $channel ]['data'][] = array(
				'period'         => $period,
				'total_products' => (int) $row['total_products'],
				'avg_health'     => round( (float) $row['avg_health'], 1 ),
				'avg_time'       => (int) $row['avg_time'],
			);
		}

		return array(
			'labels'   => $labels,
			'datasets' => array_values( $datasets ),
		);
	}
}
