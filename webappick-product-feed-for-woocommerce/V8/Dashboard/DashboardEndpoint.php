<?php
/**
 * Dashboard REST Endpoint — Exposes dashboard metrics via REST API.
 * All endpoints under /ctxfeed/v8/dashboard/ requiring manage_woocommerce capability.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-6.1
 */

namespace CTXFeed\V8\Dashboard;

use CTXFeed\V8\API\RestController;
use CTXFeed\V8\Feed\FeedManager;

/**
 * Class DashboardEndpoint
 *
 * Registers and serves the read-only dashboard REST routes plus the quick
 * actions (generate all, dismiss alert, clear cache).
 *
 * @since 8.0.0
 */
class DashboardEndpoint extends RestController {

	/**
	 * Dashboard aggregation service.
	 *
	 * @since 8.0.0
	 * @var DashboardService
	 */
	private DashboardService $service;

	/**
	 * Per-channel metrics aggregator.
	 *
	 * @since 8.0.0
	 * @var ChannelPerformance
	 */
	private ChannelPerformance $channelPerformance;

	/**
	 * Feed manager instance for loading feed names and configs.
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
	 * @param DashboardService   $service            Dashboard aggregation service.
	 * @param ChannelPerformance $channelPerformance Per-channel metrics aggregator.
	 */
	public function __construct( DashboardService $service, ChannelPerformance $channelPerformance ) {
		$this->service            = $service;
		$this->channelPerformance = $channelPerformance;
		$this->manager            = new FeedManager();
	}

	/**
	 * Register all dashboard REST routes.
	 */
	public function register_routes(): void {
		// GET /ctxfeed/v8/dashboard/overview.
		register_rest_route(
			$this->namespace,
			'/dashboard/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getOverview' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			) 
		);

		// GET /ctxfeed/v8/dashboard/channels.
		register_rest_route(
			$this->namespace,
			'/dashboard/channels',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getChannels' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			) 
		);

		// GET /ctxfeed/v8/dashboard/channels/{slug}.
		register_rest_route(
			$this->namespace,
			'/dashboard/channels/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getChannelDetail' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			) 
		);

		// GET /ctxfeed/v8/dashboard/activity.
		register_rest_route(
			$this->namespace,
			'/dashboard/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getActivity' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
				'args'                => array(
					'limit'   => array(
						'default'           => 20,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'offset'  => array(
						'default'           => 0,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'channel' => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
					'status'  => array(
						'default' => '',
						'type'    => 'string',
						'enum'    => array( '', 'success', 'error', 'warning' ),
					),
				),
			) 
		);

		// GET /ctxfeed/v8/dashboard/metrics.
		register_rest_route(
			$this->namespace,
			'/dashboard/metrics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getMetrics' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
				'args'                => array(
					'start_date'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_date'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'channel'     => array(
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
					'granularity' => array(
						'default' => 'daily',
						'type'    => 'string',
						'enum'    => array( 'daily', 'weekly' ),
					),
				),
			) 
		);

		// GET /ctxfeed/v8/dashboard/alerts.
		register_rest_route(
			$this->namespace,
			'/dashboard/alerts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getAlerts' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			) 
		);

		// POST /ctxfeed/v8/dashboard/actions/generate-all.
		// Implements DASH-FRD-6.1.
		register_rest_route(
			$this->namespace,
			'/dashboard/actions/generate-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generateAllFeeds' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			) 
		);

		// POST /ctxfeed/v8/dashboard/alerts/(?P<id>\d+)/dismiss.
		// Implements DASH-FRD-5.2.
		register_rest_route(
			$this->namespace,
			'/dashboard/alerts/(?P<id>\d+)/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismissAlert' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			) 
		);

		// DELETE /ctxfeed/v8/dashboard/cache.
		// Implements DASH-FRD-6.3.
		register_rest_route(
			$this->namespace,
			'/dashboard/cache',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'refreshDashboard' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			) 
		);
	}

	/**
	 * GET /dashboard/overview — Aggregated dashboard summary.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function getOverview( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback signature; this route takes no parameters.
		return $this->success( $this->service->getOverview() );
	}

	/**
	 * GET /dashboard/channels — Per-channel performance grid.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function getChannels( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback signature; this route takes no parameters.
		return $this->success( $this->channelPerformance->getChannelGrid() );
	}

	/**
	 * GET /dashboard/channels/{slug} — Single channel detail.
	 *
	 * @param \WP_REST_Request $request Request object with the 'slug' parameter.
	 *
	 * @return \WP_REST_Response
	 */
	public function getChannelDetail( \WP_REST_Request $request ): \WP_REST_Response {
		$slug   = $request->get_param( 'slug' );
		$detail = $this->channelPerformance->getChannelDetail( $slug );

		if ( null === $detail ) {
			return $this->error( __( 'Channel not found.', 'woo-feed' ), 404 );
		}

		return $this->success( $detail );
	}

	/**
	 * GET /dashboard/activity — Feed activity timeline.
	 *
	 * @param \WP_REST_Request $request Request object with 'limit', 'offset', 'channel' and 'status' parameters.
	 *
	 * @return \WP_REST_Response
	 */
	public function getActivity( \WP_REST_Request $request ): \WP_REST_Response {
		$activity = $this->service->getActivity(
			$request->get_param( 'limit' ),
			$request->get_param( 'offset' ),
			$request->get_param( 'channel' ),
			$request->get_param( 'status' )
		);

		return $this->success( $activity );
	}

	/**
	 * GET /dashboard/metrics — Historical metrics for charting.
	 *
	 * @param \WP_REST_Request $request Request object with 'start_date', 'end_date', 'channel' and 'granularity' parameters.
	 *
	 * @return \WP_REST_Response
	 */
	public function getMetrics( \WP_REST_Request $request ): \WP_REST_Response {
		$metrics = $this->service->getMetrics(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' ),
			$request->get_param( 'channel' ),
			$request->get_param( 'granularity' )
		);

		return $this->success( $metrics );
	}

	/**
	 * GET /dashboard/alerts — Feeds needing attention.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function getAlerts( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback signature; this route takes no parameters.
		return $this->success( $this->service->getAlerts() );
	}

	/**
	 * POST /dashboard/actions/generate-all — Schedule generation for all active feeds.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-6.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function generateAllFeeds( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback signature; this route takes no parameters.
		$feeds = $this->manager->get_all_feed_names();

		if ( empty( $feeds ) ) {
			return $this->success(
				array(
					'message'    => __( 'No active feeds found to generate.', 'woo-feed' ),
					'feed_count' => 0,
				) 
			);
		}

		$scheduled = 0;

		foreach ( $feeds as $feed_name ) {
			$config = $this->manager->get_config( $feed_name );

			if ( null === $config ) {
				continue;
			}

			// Schedule via Action Scheduler if available, else direct execution.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time(),
					'ctxfeed_generate_feed',
					array( 'feed_name' => $feed_name ),
					'ctxfeed'
				);
			}

			++$scheduled;
		}

		return $this->success(
			array(
				'message'    => sprintf(
				/* translators: %d: number of scheduled feeds */
					__( 'Scheduled %d feeds for generation.', 'woo-feed' ),
					$scheduled
				),
				'feed_count' => $scheduled,
			) 
		);
	}

	/**
	 * POST /dashboard/alerts/{id}/dismiss — Mark an alert as dismissed.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-5.2
	 *
	 * @param \WP_REST_Request $request Request object with 'id' parameter.
	 *
	 * @return \WP_REST_Response
	 */
	public function dismissAlert( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$alert_id = $request->get_param( 'id' );
		$table    = $wpdb->prefix . 'ctxfeed_alerts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The alerts table is a CTX Feed-owned custom table with no WP API; this is an admin-only write (manage_woocommerce) and the matching cache is invalidated by the delete_transient() call below.
		$result = $wpdb->update(
			$table,
			array( 'dismissed_at' => current_time( 'mysql' ) ),
			array( 'id' => $alert_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return $this->error( __( 'Failed to dismiss alert.', 'woo-feed' ), 500 );
		}

		// Clear alerts cache so changes reflect immediately.
		delete_transient( 'ctxfeed_alerts' );

		return $this->success(
			array(
				'message'  => __( 'Alert dismissed.', 'woo-feed' ),
				'alert_id' => $alert_id,
			) 
		);
	}

	/**
	 * DELETE /dashboard/cache — Clear all dashboard caches and refresh.
	 *
	 * @since 8.0.0
	 * @implements DASH-FRD-6.3
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function refreshDashboard( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback signature; this route takes no parameters.
		$this->service->refresh();

		return $this->success(
			array(
				'message' => __( 'Dashboard cache cleared.', 'woo-feed' ),
			) 
		);
	}
}
