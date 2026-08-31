<?php
/**
 * DashboardServiceProvider — Registers dashboard module services.
 *
 * Eleventh ServiceProvider in the Bootstrap registration order.
 * Binds 6 services: DashboardService, MetricsCollector, MetricsStorage,
 * ChannelPerformance, WidgetRegistry, and DashboardEndpoint. During boot(),
 * resolves cross-module dependencies and wires WordPress hooks for metrics
 * collection, REST endpoints, cache invalidation, and database migration.
 *
 * @package    CTXFeed
 * @subpackage V8/Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-1.1, DASH-FRD-3.1, DASH-FRD-6.1
 */

namespace CTXFeed\V8\Dashboard;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard module service provider.
 *
 * Orchestrates the registration and bootstrapping of all dashboard-related
 * services including metrics collection, REST API endpoints, cache management,
 * and the extensible widget system.
 *
 * @since 8.0.0
 */
class DashboardServiceProvider extends ServiceProvider {

	/**
	 * Schema version for database migration tracking.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Register dashboard services into the container.
	 *
	 * Phase 1: Binds factories — no cross-module resolution here.
	 *
	 * Services registered:
	 * - dashboard.metrics_storage:     Database access layer for metrics table.
	 * - dashboard.channel_performance: Aggregated per-channel metric calculations.
	 * - dashboard.widget_registry:     Extensible widget management.
	 * - dashboard.service:             Orchestrator for all dashboard queries.
	 * - dashboard.metrics_collector:   Hook listener for feed events.
	 * - dashboard.endpoint:            REST API controller for dashboard routes.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-1.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {

		// MetricsStorage has no dependencies — safe to register immediately.
		$container->register(
			'dashboard.metrics_storage',
			function () {
				return new MetricsStorage();
			} 
		);

		// ChannelPerformance depends on MetricsStorage — resolved in boot().
		$container->register(
			'dashboard.channel_performance',
			function () {
				return new ChannelPerformance(
					new MetricsStorage() // Placeholder — re-registered in boot().
				);
			} 
		);

		// WidgetRegistry has no constructor dependencies.
		$container->register(
			'dashboard.widget_registry',
			function () {
				return new WidgetRegistry();
			} 
		);

		// DashboardService depends on MetricsStorage, ChannelPerformance, WidgetRegistry.
		// Placeholder — re-registered in boot() with resolved dependencies.
		$container->register(
			'dashboard.service',
			function () {
				return new DashboardService(
					new MetricsStorage(),       // Placeholder.
					new ChannelPerformance(     // Placeholder.
						new MetricsStorage()
					),
					new WidgetRegistry()        // Placeholder.
				);
			} 
		);

		// MetricsCollector depends on MetricsStorage — re-registered in boot().
		$container->register(
			'dashboard.metrics_collector',
			function () {
				return new MetricsCollector(
					new MetricsStorage() // Placeholder.
				);
			} 
		);

		// DashboardEndpoint depends on DashboardService, ChannelPerformance.
		// Placeholder — re-registered in boot() with resolved dependencies.
		$container->register(
			'dashboard.endpoint',
			function () {
				return new DashboardEndpoint(
					new DashboardService(       // Placeholder.
						new MetricsStorage(),
						new ChannelPerformance( new MetricsStorage() ),
						new WidgetRegistry()
					),
					new ChannelPerformance(     // Placeholder.
						new MetricsStorage()
					)
				);
			} 
		);
	}

	/**
	 * Boot dashboard services.
	 *
	 * Phase 3: Resolves shared singletons, re-registers services with proper
	 * dependency injection, wires WordPress hooks for metrics collection,
	 * REST API endpoints, cache invalidation, widget extensibility, and
	 * database schema migration.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-3.1, DASH-FRD-6.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 *
	 * @hook action ctxfeed_feed_finalized         → MetricsCollector::onFeedFinalized()
	 * @hook action ctxfeed_feed_exported           → MetricsCollector::onFeedExported()
	 * @hook action rest_api_init                   → DashboardEndpoint::register_routes()
	 * @hook action ctxfeed_metrics_recorded        → DashboardService::clearCache()
	 * @hook action ctxfeed_dashboard_widgets_registered (fired)
	 */
	public function boot( Container $container ): void {

		// ── Resolve shared singletons ──────────────────────────────────
		$storage         = $container->resolve( 'dashboard.metrics_storage' );
		$widget_registry = $container->resolve( 'dashboard.widget_registry' );

		// ── Re-register with proper shared instances ───────────────────
		$container->register(
			'dashboard.channel_performance',
			function () use ( $storage ) {
				return new ChannelPerformance( $storage );
			} 
		);

		$channel_performance = $container->resolve( 'dashboard.channel_performance' );

		$container->register(
			'dashboard.service',
			function () use ( $storage, $channel_performance, $widget_registry ) {
				return new DashboardService( $storage, $channel_performance, $widget_registry );
			} 
		);

		$container->register(
			'dashboard.metrics_collector',
			function () use ( $storage ) {
				return new MetricsCollector( $storage );
			} 
		);

		$service   = $container->resolve( 'dashboard.service' );
		$collector = $container->resolve( 'dashboard.metrics_collector' );

		$container->register(
			'dashboard.endpoint',
			function () use ( $service, $channel_performance ) {
				return new DashboardEndpoint( $service, $channel_performance );
			} 
		);

		$endpoint = $container->resolve( 'dashboard.endpoint' );

		// ── Wire MetricsCollector hooks ─────────────────────────────────
		// Priority 5: Ensures metrics are captured before downstream handlers.
		// @implements DASH-FRD-3.1
		add_action( 'ctxfeed_feed_finalized', array( $collector, 'onFeedFinalized' ), 5, 2 );
		add_action( 'ctxfeed_feed_exported', array( $collector, 'onFeedExported' ), 10, 2 );

		// ── Wire REST API endpoints ─────────────────────────────────────
		// @implements DASH-FRD-6.1
		add_action( 'rest_api_init', array( $endpoint, 'register_routes' ) );

		// ── Wire cache invalidation on new metrics ─────────────────────
		// When MetricsCollector fires ctxfeed_metrics_recorded, clear dashboard caches
		// so the next request gets fresh data.
		add_action( 'ctxfeed_metrics_recorded', array( $service, 'clearCache' ), 10, 2 );

		// ── Wire database migration ─────────────────────────────────────
		// Only run on admin requests to avoid frontend overhead.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_migrate_schema' ) );
		}

		// ── Wire weekly metrics pruning via WP-Cron ─────────────────────
		if ( ! wp_next_scheduled( 'ctxfeed_metrics_prune' ) ) {
			wp_schedule_event( time(), 'weekly', 'ctxfeed_metrics_prune' );
		}
		add_action( 'ctxfeed_metrics_prune', array( $storage, 'prune' ) );

		/**
		 * Fires after all dashboard widgets have been registered.
		 *
		 * Allows Pro and third-party plugins to register custom widgets
		 * via WidgetRegistry::register().
		 *
		 * @since 8.0.0
		 *
		 * @param WidgetRegistry $widget_registry The widget registry instance.
		 */
		do_action( 'ctxfeed_dashboard_widgets_registered', $widget_registry );

		/**
		 * Fires after all dashboard services have been booted and hooked.
		 *
		 * @since 8.0.0
		 *
		 * @param DashboardService     $service   The dashboard service.
		 * @param DashboardEndpoint    $endpoint  The REST endpoint.
		 * @param MetricsCollector     $collector The metrics collector.
		 */
		do_action( 'ctxfeed_dashboard_booted', $service, $endpoint, $collector );
	}

	/**
	 * Run database schema migration if needed.
	 *
	 * Checks a stored schema version option and runs dbDelta() for the
	 * metrics table if the version is outdated or missing.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function maybe_migrate_schema(): void {
		$current_version = get_option( 'ctxfeed_dashboard_schema_version', '' );

		if ( self::SCHEMA_VERSION === $current_version ) {
			return;
		}

		$storage = Container::get_instance()->resolve( 'dashboard.metrics_storage' );
		$storage->createTable();

		// Create alerts table.
		$this->create_alerts_table();

		update_option( 'ctxfeed_dashboard_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * Create the alerts table for health alert tracking.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-5.1
	 *
	 * @return void
	 */
	private function create_alerts_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'ctxfeed_alerts';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feed_name VARCHAR(255) NOT NULL,
			channel VARCHAR(100) NOT NULL,
			alert_type VARCHAR(50) NOT NULL DEFAULT 'health',
			severity VARCHAR(20) NOT NULL DEFAULT 'warning',
			message TEXT NOT NULL,
			recommended_action TEXT,
			health_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			error_count INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			dismissed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_feed_channel (feed_name, channel),
			KEY idx_severity (severity),
			KEY idx_dismissed (dismissed_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
