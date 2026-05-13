<?php

namespace CTXFeed\V5\API\V1;

use CTXFeed\V5\API\RestConstants;
use CTXFeed\V5\API\RestController;
use CTXFeed\V5\Common\DisplayBanners;
use CTXFeed\V5\Common\Helper;
use CTXFeed\V5\Utility\Cache;
use CTXFeed\V5\Utility\Status;
use WP_REST_Server;

class WPStatus extends RestController {

	/**
	 * The single instance of the class
	 *
	 * @var WPOptions
	 */
	protected static $_instance = null;

	private function __construct() {
		parent::__construct();
		$this->rest_base = RestConstants::STATUS_REST_BASE;
	}

	/**
	 * Main Status Instance.
	 *
	 * Ensures only one instance of Status is loaded or can be loaded.
	 *
	 * @return WPStatus Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Register routes.
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,[
				/**
				 * @endpoint wp-json/ctxfeed/v1/wp-status
				 * @method GET
				 * @descripton Get status
				 *
				 * @param $name String
				 */
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_wp_status' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [],
				]

			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/logs',[
				/**
				 * @endpoint wp-json/ctxfeed/v1/wp-status/logs
				 * @method GET
				 * @descripton Get logs
				 *
				 * @param $name String
				 */
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_wp_logs' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [],
				]

			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/delete-cache',[
				/**
				 * @endpoint wp-json/ctxfeed/v1/wp-status/delete-cache
				 * @method GET
				 * @descripton Delete status cache
				 *
				 * @param $name String
				 */
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'delete_status_cache' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [],
				]

			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/lifetime-banner',[
				/**
				 * @endpoint wp-json/ctxfeed/v1/wp-status/lifetime-banner
				 * @method GET
				 * @description Get lifetime banner visibility status for the current user
				 */
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_lifetime_banner_status' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [],
				],
				/**
				 * @endpoint wp-json/ctxfeed/v1/wp-status/lifetime-banner
				 * @method POST
				 * @description Dismiss lifetime banner for the current user for 15 days
				 */
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'dismiss_lifetime_banner' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [],
				]
			]
		);
	}

	public function get_wp_status(){
		$get_status = new Status();
		$wp_status = $get_status->get_status();
		$status_text = $get_status->get_status_logs_error( $wp_status );
		return $this->success( [
			'wp_status' => $wp_status,
			'status_text' => $status_text,
		]);
	}

	public function get_wp_logs(){
		$get_status = new Status();
		$get_logs = $get_status->get_logs();

		return $this->success( [
			'logs'=>$get_logs
		]);
	}
	public function delete_status_cache(){
		$get_cache = Cache::get( 'woo_feed_status_page_info' );

		if($get_cache)
			$delete_cache = Cache::delete( 'woo_feed_status_page_info' );
		else
			$delete_cache = true;

		Helper::clear_cache_data();

		return $this->success( [
			'result'=>$delete_cache
		]);
	}

	/**
	 * Get lifetime banner visibility status for the current user.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_lifetime_banner_status() {
		$status = DisplayBanners::get_lifetime_banner_status();

		return $this->success( $status );
	}

	/**
	 * Dismiss lifetime banner for the current user.
	 *
	 * @return \WP_REST_Response
	 */
	public function dismiss_lifetime_banner() {
		$result = DisplayBanners::dismiss_lifetime_banner();

		if ( $result ) {
			$status = DisplayBanners::get_lifetime_banner_status();

			return $this->success( array(
				'dismissed' => true,
				'status'    => $status,
			) );
		}

		return $this->error(
			__( 'Failed to dismiss banner.', 'woo-feed' ),
			'banner_dismiss_failed',
			500
		);
	}

}
