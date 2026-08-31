<?php
/**
 * FeedEndpoint — REST API for feed CRUD, generation, and status.
 *
 * Reads feeds from the V5 wp_options storage (wf_feed_*) so that
 * the V8 Manage Feed UI can display and manage existing feeds.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-2.1, API-FRD-2.2
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Common\DropdownRegistry;
use CTXFeed\V8\Channel\TemplateInfo;
use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Feed\FeedScheduler;
use CTXFeed\V8\Utility\Sanitizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed REST endpoint.
 *
 * @since 8.0.0
 */
class FeedEndpoint extends RestController {

	/**
	 * Register feed routes.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.1, API-FRD-2.2
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/feeds',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feeds' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_feed' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);

		// Import feed from .wpf file.
		register_rest_route(
			$this->namespace,
			'/feeds/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_feed' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Bulk actions (delete, duplicate, auto_update_on/off).
		register_rest_route(
			$this->namespace,
			'/feeds/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_action' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Feed templates / channel list — registered BEFORE {id} route
		// to avoid WordPress matching "templates" as a feed ID.
		register_rest_route(
			$this->namespace,
			'/feeds/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_templates' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Template info (supported file types, docs, video) for a given template.
		register_rest_route(
			$this->namespace,
			'/feeds/template-info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template_info' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'template' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Template/merchant key (e.g., google, facebook).', 'woo-feed' ),
					),
				),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feed' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_feed' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_feed' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_feed' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_feed' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_feed_config' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_feed_log' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed_report' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/report/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_feed_report' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/feeds/(?P<id>[a-zA-Z0-9_-]+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	// =====================================================================
	// Feed CRUD.
	// =====================================================================

	/**
	 * List all feeds.
	 *
	 * Reads from wp_options where option_name LIKE 'wf_feed_%'.
	 * Returns a normalized array the React UI expects.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_feeds( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$channel_filter = $request->get_param( 'channel' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under the wf_feed_ prefix (shared with V5 — no migration). get_option() cannot enumerate by prefix and these rows are deliberately non-autoloaded, so a direct prepared query is the only way to list them. Admin REST route only; never on the feed-generation path.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC",
				'wf_feed_%'
			),
			ARRAY_A
		);

		$feeds    = array();
		$channels = array();

		foreach ( $rows as $row ) {
			$config = maybe_unserialize( maybe_unserialize( $row['option_value'] ) );

			if ( ! is_array( $config ) || ! isset( $config['feedrules'] ) ) {
				continue;
			}

			$rules       = $config['feedrules'];
			$provider    = isset( $rules['provider'] ) ? $rules['provider'] : '';
			$option_name = $row['option_name'];
			$feed_name   = str_replace( 'wf_feed_', '', $option_name );

			// Collect unique channels for the filter dropdown (before filtering).
			if ( ! empty( $provider ) && ! isset( $channels[ $provider ] ) ) {
				$channels[ $provider ] = ucwords( str_replace( '_', ' ', $provider ) );
			}

			// Apply channel filter if provided.
			if ( $channel_filter && strtolower( $provider ) !== strtolower( $channel_filter ) ) {
				continue;
			}

			$feeds[] = array(
				'id'          => $row['option_id'],
				'option_name' => $option_name,
				'feed_name'   => isset( $rules['filename'] ) ? $rules['filename'] : $feed_name,
				'slug'        => $feed_name,
				'channel'     => ucwords( str_replace( '_', ' ', $provider ) ),
				'provider'    => $provider,
				'logo'        => TemplateInfo::logo_url( $provider ),
				'file_type'   => isset( $rules['feedType'] ) ? strtoupper( $rules['feedType'] ) : 'XML',
				'feed_url'    => isset( $config['url'] ) ? $config['url'] : '',
				'auto_update' => isset( $config['status'] ) ? 1 === (int) $config['status'] : true,
				'interval'    => isset( $rules['cron'] ) ? $this->format_interval( $rules['cron'] ) : '—',
				'last_update' => $this->to_iso8601_utc( isset( $config['last_updated'] ) ? (string) $config['last_updated'] : '' ),
			);
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'data'     => $feeds,
				'channels' => $channels,
			),
			200 
		);
	}

	/**
	 * Normalise a stored site-local 'Y-m-d H:i:s' timestamp into an
	 * unambiguous UTC ISO-8601 instant (e.g. '2026-08-21T06:00:00+00:00').
	 *
	 * The feed's `last_updated` is written with current_time() (WP site-local
	 * wall clock, NO timezone marker) and the shared wf_feed_* option is left
	 * that way for V5 parity. But a bare local string handed to the browser is
	 * parsed by `new Date()` as the VIEWER's local time, so the Manage Feeds
	 * "Last Generated" relative label was wrong by the (browser − site) offset
	 * — e.g. "6 Hours Ago" the instant a feed finished, for a UTC site viewed
	 * from UTC+6 (BUG-0057). Emitting an explicit-offset ISO instant makes
	 * `new Date()` / `Date.parse()` resolve the same moment on every client,
	 * regardless of the viewer's timezone.
	 *
	 * @since 8.0.0
	 *
	 * @param string $local Stored site-local datetime, or '' when never generated.
	 * @return string ISO-8601 UTC string with offset, or '' when empty/unparseable.
	 */
	private function to_iso8601_utc( string $local ): string {
		if ( '' === $local || false === strtotime( $local ) ) {
			return '';
		}

		// get_gmt_from_date() reads $local in the site timezone and returns the
		// GMT equivalent; the 'c' format yields ISO-8601 with a +00:00 offset.
		return get_gmt_from_date( $local, 'c' );
	}

	/**
	 * Get a single feed by option_id or slug.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$config = $this->find_feed( $id );

		if ( ! $config ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		return $this->success( $config );
	}

	/**
	 * Create a new feed.
	 *
	 * Accepts the Make Feed form data from the React UI, converts
	 * row-based feedRules to parallel arrays, and persists to
	 * wp_options in the V5-compatible format (wf_config{slug} + wf_feed_{slug}).
	 *
	 * Request body:
	 *   - makeFeedForm  {object} Country, template, fileName, fileType, etc.
	 *   - feedRules     {array}  Row objects with mattribute, attribute, etc.
	 *   - filter        {object} Optional filter settings.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function create_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$form                = $request->get_param( 'makeFeedForm' );
		$rules               = $request->get_param( 'feedRules' );
		$filter_options      = $request->get_param( 'filterOptions' );
		$post_status         = $request->get_param( 'postStatus' );
		$categories          = $request->get_param( 'categories' );
		$product_ids         = $request->get_param( 'productIds' );
		$filter_mode         = $request->get_param( 'filterMode' );
		$number_format       = $request->get_param( 'numberFormat' );
		$campaign_parameters = $request->get_param( 'campaignParameters' );
		$string_replace      = $request->get_param( 'stringReplace' );
		$shipping_country    = $request->get_param( 'shippingCountry' );
		$tax_country         = $request->get_param( 'taxCountry' );
		$ftp                 = $request->get_param( 'ftp' );
		$advanced_filter     = $request->get_param( 'advancedFilter' );

		if ( empty( $form ) || ! is_array( $form ) ) {
			return $this->error( __( 'Missing feed form data.', 'woo-feed' ), 400 );
		}

		if ( empty( $form['template'] ) || empty( $form['fileName'] ) || empty( $form['fileType'] ) ) {
			return $this->error( __( 'Template, file name, and file type are required.', 'woo-feed' ), 400 );
		}

		// Country sets the market whose tax rate and shipping zones the feed
		// uses. Tax-inclusive price attributes (price_with_tax, …) resolve to
		// ZERO tax when it is empty (FeedTaxLocation cannot override the tax
		// location), so an omitted country silently mis-prices the feed.
		if ( empty( $form['country'] ) ) {
			return $this->error( __( 'Feed country is required. It sets the market whose tax rate and shipping zones the feed uses — tax-inclusive prices resolve to zero tax without it.', 'woo-feed' ), 400 );
		}

		// Convert React row-based rules to parallel-array format.
		$feedrules = $this->build_feedrules_from_form(
			$form,
			$rules ? $rules : array(),
			is_array( $filter_options ) ? $filter_options : array(),
			is_array( $post_status ) ? $post_status : array(),
			is_array( $categories ) ? $categories : array(),
			is_array( $product_ids ) ? $product_ids : array(),
			is_array( $filter_mode ) ? $filter_mode : array(),
			is_array( $number_format ) ? $number_format : array(),
			is_array( $campaign_parameters ) ? $campaign_parameters : array(),
			is_array( $string_replace ) ? $string_replace : array(),
			is_string( $shipping_country ) ? $shipping_country : '',
			is_string( $tax_country ) ? $tax_country : '',
			is_array( $ftp ) ? $ftp : array(),
			array(), // No existing feedrules on create.
			is_array( $advanced_filter ) ? $advanced_filter : array()
		);

		// Generate unique slug from file name.
		$slug = sanitize_title( $form['fileName'], '', 'save' );
		$slug = sanitize_file_name( $slug );

		// Ensure unique option name.
		$base_slug = $slug;
		$counter   = 1;
		while ( get_option( 'wf_feed_' . $slug ) !== false ) {
			$slug = $base_slug . '-' . $counter;
			++$counter;
		}

		$feed_data = array(
			'feedrules'    => $feedrules,
			'url'          => '',
			'last_updated' => '',
			'status'       => 0,
		);

		update_option( 'wf_config' . $slug, $feedrules, false );
		update_option( 'wf_feed_' . $slug, $feed_data, false );

		// status=0 on create, so this is a no-op today; calling it anyway
		// keeps the contract uniform — every code path that writes
		// `wf_feed_*` reconciles the recurring entry.
		$this->sync_feed_schedule( 'wf_feed_' . $slug, $feed_data );

		// Build the feed now (one-time), like V5 did on save — unless the
		// caller opts out. The React admin's "Save & generate" sends
		// autoGenerate=false and drives generation itself from the feed list
		// (with a progress bar), so the save must not ALSO start a background
		// run — a double run double-schedules and clobbers a large feed's batches.
		if ( $this->request_wants_auto_generate( $request ) ) {
			$this->maybe_auto_generate( $slug, 'create' );
		}

		$feed = $this->find_feed( $slug );

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'data'     => $feed,
				'warnings' => $this->save_warnings(),
			),
			201
		);
	}

	/**
	 * Update an existing feed.
	 *
	 * Supports two update modes:
	 * 1. Simple toggle: { auto_update: true/false } for feed list toggle.
	 * 2. Full update: { makeFeedForm, feedRules } for Make Feed save.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );

		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$option_name = $feed_data['option_name'];
		$config      = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );

		if ( ! is_array( $config ) ) {
			return $this->error( __( 'Invalid feed data.', 'woo-feed' ), 500 );
		}

		// --- Mode 1: Simple auto_update toggle ---
		$auto_update = $request->get_param( 'auto_update' );
		if ( null !== $auto_update && null === $request->get_param( 'makeFeedForm' ) ) {
			$config['status'] = $auto_update ? 1 : 0;
			update_option( $option_name, $config, false );

			// Reconcile the Action Scheduler recurring entry with the new
			// status. Without this, the toggle was previously a UI-only
			// flag — wp_options changed but no recurring action was ever
			// registered (or cancelled).
			$this->sync_feed_schedule( $option_name, $config );

			return $this->success(
				array(
					'id'          => $feed_data['option_id'],
					'auto_update' => 1 === (int) $config['status'],
				) 
			);
		}

		// --- Mode 2: Full feed config update from Make Feed UI ---
		$form                = $request->get_param( 'makeFeedForm' );
		$rules               = $request->get_param( 'feedRules' );
		$filter_options      = $request->get_param( 'filterOptions' );
		$post_status         = $request->get_param( 'postStatus' );
		$categories          = $request->get_param( 'categories' );
		$product_ids         = $request->get_param( 'productIds' );
		$filter_mode         = $request->get_param( 'filterMode' );
		$number_format       = $request->get_param( 'numberFormat' );
		$campaign_parameters = $request->get_param( 'campaignParameters' );
		$string_replace      = $request->get_param( 'stringReplace' );
		$shipping_country    = $request->get_param( 'shippingCountry' );
		$tax_country         = $request->get_param( 'taxCountry' );
		$ftp                 = $request->get_param( 'ftp' );
		$advanced_filter     = $request->get_param( 'advancedFilter' );

		if ( empty( $form ) || ! is_array( $form ) ) {
			return $this->error( __( 'Missing feed form data.', 'woo-feed' ), 400 );
		}

		// Country is required on a full save for the same tax/shipping-market
		// reason as create (see create_feed). Editing a legacy country-less
		// feed must set it before the config can be re-saved.
		if ( empty( $form['country'] ) ) {
			return $this->error( __( 'Feed country is required. It sets the market whose tax rate and shipping zones the feed uses — tax-inclusive prices resolve to zero tax without it.', 'woo-feed' ), 400 );
		}

		$feed_slug = str_replace( 'wf_feed_', '', $option_name );

		// Existing feedrules are needed so an empty password from the UI
		// preserves the previously-saved encrypted password (users editing
		// a feed don't have to re-enter the password every save).
		$existing_feedrules = isset( $config['feedrules'] ) && is_array( $config['feedrules'] )
			? $config['feedrules']
			: array();

		$feedrules = $this->build_feedrules_from_form(
			$form,
			$rules ? $rules : array(),
			is_array( $filter_options ) ? $filter_options : array(),
			is_array( $post_status ) ? $post_status : array(),
			is_array( $categories ) ? $categories : array(),
			is_array( $product_ids ) ? $product_ids : array(),
			is_array( $filter_mode ) ? $filter_mode : array(),
			is_array( $number_format ) ? $number_format : array(),
			is_array( $campaign_parameters ) ? $campaign_parameters : array(),
			is_array( $string_replace ) ? $string_replace : array(),
			is_string( $shipping_country ) ? $shipping_country : '',
			is_string( $tax_country ) ? $tax_country : '',
			is_array( $ftp ) ? $ftp : array(),
			$existing_feedrules,
			is_array( $advanced_filter ) ? $advanced_filter : array()
		);

		// Direct update — V8 handles its own persistence.
		$config['feedrules']    = $feedrules;
		$config['last_updated'] = current_time( 'mysql' );

		update_option( 'wf_config' . $feed_slug, $feedrules, false );
		update_option( $option_name, $config, false );

		// If the user changed `update_interval` (or anything that affects
		// recurring) the Action Scheduler entry needs to pick up the new
		// interval. Re-syncing is cheap (idempotent) so we always do it.
		$this->sync_feed_schedule( $option_name, $config );

		// Rebuild the feed now that its configuration changed (V5 parity) —
		// unless the caller opts out (autoGenerate=false; see create_feed).
		if ( $this->request_wants_auto_generate( $request ) ) {
			$this->maybe_auto_generate( $feed_slug, 'update' );
		}

		$feed = $this->find_feed( $id );

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'data'     => $feed,
				'warnings' => $this->save_warnings(),
			),
			200
		);
	}

	/**
	 * Delete a feed.
	 *
	 * Deletes both wp_options entries (wf_feed_{slug} and wf_config{slug})
	 * and removes the generated feed file from disk.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function delete_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$option_name = $feed_data['option_name'];
		$feed_slug   = str_replace( 'wf_feed_', '', $option_name );

		// Delete generated feed file if it exists.
		$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
		if ( is_array( $config ) && ! empty( $config['url'] ) ) {
			$upload_dir = wp_upload_dir();
			$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $config['url'] );
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
		}

		// Cancel any pending generation/recurring before destroying the
		// option — otherwise Action Scheduler will fire `handle_recurring`
		// for a feed that no longer exists, hit Guard 3 (config not
		// found), and log a stream of errors every interval.
		$this->sync_feed_schedule( $option_name, array( 'status' => 0 ) );

		// Delete both option keys.
		delete_option( $option_name );
		delete_option( 'wf_config' . $feed_slug );

		return $this->success( array( 'deleted' => true ) );
	}

	/**
	 * Trigger feed generation via V8 FeedScheduler.
	 *
	 * Resolves FeedScheduler from DI Container, creates Config from
	 * feed name, and calls schedule_generation(). Returns 409 with
	 * user-friendly message if another feed holds the generation lock.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.2, FEED-FRD-3.6
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function generate_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$option_name = $feed_data['option_name'];
		$feed_name   = str_replace( 'wf_feed_', '', $option_name );

		return $this->generate_feed_v8( $feed_name );
	}

	/**
	 * V8 feed generation via FeedScheduler.
	 *
	 * Resolves FeedScheduler from the DI Container, creates a Config
	 * object from the feed name, and schedules generation. Handles
	 * lock contention by returning a 409 error with the lock holder name.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.1, FEED-FRD-3.6
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @return \WP_REST_Response
	 */
	private function generate_feed_v8( string $feed_name ): \WP_REST_Response {
		$container = \CTXFeed\V8\Core\Container::get_instance();

		// Create Config from stored feed data.
		$config = \CTXFeed\V8\Core\Config::from_feed_name( $feed_name );
		if ( ! $config ) {
			return $this->error(
				sprintf( 'Feed configuration not found for: %s', $feed_name ),
				404
			);
		}

		// Pre-flight validation (FEED-FRD-4.1). FeedValidator was implemented and
		// DI-registered but never actually called, so a fundamentally invalid
		// config (no channel selected, an unsupported file format, or zero
		// attribute mappings — and, when a channel declares them, missing
		// required attributes) would generate an empty/broken feed with no
		// warning. Run it here and surface the reasons (422) instead. This does
		// not disturb working feeds: any config that generates today already
		// has a provider, a valid format and at least one mapping.
		if ( $container->has( 'feed.validator' ) ) {
			/** @var \CTXFeed\V8\Feed\FeedValidator $validator */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
			$validator  = $container->resolve( 'feed.validator' );
			$validation = $validator->validate( $config );
			if ( empty( $validation['valid'] ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'ctxfeed_feed_invalid',
						'message' => __( 'This feed cannot be generated yet — please fix its configuration.', 'woo-feed' ),
						'data'    => array(
							'status' => 422,
							'errors' => $validation['errors'],
						),
					),
					422
				);
			}
		}

		// Resolve FeedScheduler from container.
		if ( ! $container->has( 'feed.scheduler' ) ) {
			return $this->error( __( 'Feed scheduler not available.', 'woo-feed' ), 500 );
		}

		/** @var \CTXFeed\V8\Feed\FeedScheduler $scheduler */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$scheduler = $container->resolve( 'feed.scheduler' );

		try {
			// Schedule (or run synchronously for small feeds).
			$result = $scheduler->schedule_generation( $feed_name, $config );
		} catch ( \Throwable $e ) {
			// Catch ANY error in the generation pipeline — fatal, type, etc.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Not debug code: this is the only record of a swallowed \Throwable. The REST response carries the message to the admin UI, but the file/line context needed to support a merchant is lost unless it reaches the PHP error log.
			error_log( '[CTXFeed V8] generate_feed_v8 error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

			return $this->error(
				sprintf( 'Generation error: %s', $e->getMessage() ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = isset( $error_data['status'] ) ? (int) $error_data['status'] : 409;

			return new \WP_REST_Response(
				array(
					'success' => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $error_data,
				),
				$status 
			);
		}

		// Check if generation already completed (synchronous fast-path).
		$manager  = $container->has( 'feed.manager' ) ? $container->resolve( 'feed.manager' ) : new \CTXFeed\V8\Feed\FeedManager();
		$progress = $manager->get_progress( $feed_name );

		return $this->success(
			array(
				'status'    => $progress['status'] ?? 'scheduled',
				'feed_name' => $feed_name,
				'percent'   => $progress['percent'] ?? 0,
			) 
		);
	}

	/**
	 * Import a feed from a .wpf config file.
	 *
	 * Accepts a multipart/form-data upload with:
	 * - wpf_file: The .wpf binary file (required).
	 * - feed_name: Custom feed name (optional).
	 *
	 * Decompresses, unpacks, verifies MD5 hash, saves config to wp_options,
	 * and returns the new feed data so the frontend can trigger regeneration.
	 *
	 * V5-compatible: accepts files exported by both V5 and V8.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function import_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$files = $request->get_file_params();
		$file  = isset( $files['wpf_file'] ) ? $files['wpf_file'] : null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return $this->error( __( 'No file uploaded.', 'woo-feed' ), 400 );
		}

		// Validate extension.
		$ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
		if ( 'wpf' !== strtolower( $ext ) ) {
			return $this->error( __( 'Invalid file type. Only .wpf files are accepted.', 'woo-feed' ), 400 );
		}

		// Read and decompress.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local read only: $file['tmp_name'] is the PHP upload tmp path, already validated as a .wpf above. It holds raw gzdeflate bytes that must be read verbatim, so no remote fetch is possible and WP_Filesystem would add nothing.
		$data = file_get_contents( $file['tmp_name'] );
		if ( empty( $data ) ) {
			return $this->error( __( 'Empty file uploaded.', 'woo-feed' ), 400 );
		}

		// Suppress the warning gzinflate emits on malformed input — a bad
		// upload is expected user error and is handled via the false return.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- A malformed .wpf upload is expected user error, not a fault: gzinflate() reports it through both an E_WARNING and a false return, and the false return is checked on the next line to produce a friendly 400.
		$inflated = @gzinflate( $data );
		if ( false === $inflated ) {
			return $this->error( __( 'Unable to decompress file. Is it a valid .wpf export?', 'woo-feed' ), 400 );
		}

		// Unpack metadata (V5 binary format: pack('VA*VA*')).
		$meta_length = unpack( 'V', $inflated );
		if ( false === $meta_length ) {
			return $this->error( __( 'Unable to read file metadata.', 'woo-feed' ), 400 );
		}

		$meta_json = substr( $inflated, 4, $meta_length[1] );
		if ( 0 !== strpos( $meta_json, '{' ) ) {
			return $this->error( __( 'Invalid metadata in file.', 'woo-feed' ), 400 );
		}
		$meta = json_decode( $meta_json, true );

		// Unpack feed data (skip 4 bytes meta length + meta string + 4 bytes feed length).
		$feed_raw           = substr( $inflated, $meta_length[1] + 8 );
		$feed_json_unpacked = unpack( 'A*', $feed_raw );
		$feed_json          = $feed_json_unpacked[1];

		if ( 0 !== strpos( $feed_json, '{' ) ) {
			return $this->error( __( 'Invalid feed data in file.', 'woo-feed' ), 400 );
		}

		// Verify MD5 hash integrity.
		if ( isset( $meta['hash'] ) && md5( $feed_json ) !== $meta['hash'] ) {
			return $this->error( __( 'File integrity check failed. The file may be corrupted.', 'woo-feed' ), 400 );
		}

		$feed_rules = json_decode( $feed_json, true );
		if ( ! is_array( $feed_rules ) ) {
			return $this->error( __( 'Invalid or corrupted feed configuration.', 'woo-feed' ), 400 );
		}

		// Determine feed name.
		$custom_name = sanitize_text_field( $request->get_param( 'feed_name' ) );
		$custom_name = trim( $custom_name );

		if ( ! empty( $custom_name ) ) {
			$opt_name               = $custom_name;
			$feed_rules['filename'] = $custom_name;
		} else {
			$opt_name               = isset( $feed_rules['filename'] ) ? $feed_rules['filename'] : 'imported-feed';
			$feed_rules['filename'] = sprintf(
				'%s: %s',
				__( 'Imported', 'woo-feed' ),
				ucwords( str_replace( array( '-', '_' ), ' ', $opt_name ) )
			);
		}

		// Generate unique slug.
		$feed_type = isset( $feed_rules['feedType'] ) ? $feed_rules['feedType'] : 'xml';
		$provider  = isset( $feed_rules['provider'] ) ? $feed_rules['provider'] : 'custom';

		if ( function_exists( 'generate_unique_feed_file_name' ) ) {
			$new_slug = generate_unique_feed_file_name( $opt_name, $feed_type, $provider );
		} else {
			// Fallback: manual unique slug generation.
			$new_slug = sanitize_title( $opt_name, '', 'save' );
			$counter  = 1;
			while ( get_option( 'wf_feed_' . $new_slug ) !== false ) {
				$new_slug = sanitize_title( $opt_name, '', 'save' ) . '-' . $counter;
				++$counter;
			}
		}

		if ( empty( $new_slug ) ) {
			return $this->error( __( 'Could not generate a valid feed name.', 'woo-feed' ), 500 );
		}

		// Save config to wp_options.
		$config = array(
			'feedrules'    => $feed_rules,
			'url'          => '',
			'status'       => 1,
			'last_updated' => '',
		);

		update_option( 'wf_feed_' . $new_slug, $config, false );
		update_option( 'wf_config' . $new_slug, $feed_rules, false );

		// Retrieve the new feed's full data.
		$new_feed = $this->find_feed( $new_slug );

		return $this->success(
			array(
				'feed' => $new_feed,
			) 
		);
	}

	/**
	 * Duplicate a feed.
	 *
	 * Clones the feed config with a unique slug, copies the generated
	 * feed file, and returns the new feed data (including its option_id
	 * so the frontend can trigger regeneration on it).
	 *
	 * Mirrors V5 woo_feed_duplicate_feed() logic natively.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function duplicate_feed( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
		if ( ! is_array( $config ) || ! isset( $config['feedrules'] ) ) {
			return $this->error( __( 'Invalid feed configuration.', 'woo-feed' ), 400 );
		}

		$orig_option = $feed_data['option_name'];
		$orig_slug   = str_replace( 'wf_feed_', '', $orig_option );
		$rules       = $config['feedrules'];

		// Resolve the new slug (option key) + display name (feedrules
		// `filename`). When the Duplicate dialog supplies a Feed Name we use
		// it verbatim as the display name and derive the slug from it;
		// otherwise fall back to the V5 "orig-copy(-N)" scheme.
		$custom_name = sanitize_text_field( (string) $request->get_param( 'feed_name' ) );
		$identity    = self::build_duplicate_identity(
			$custom_name,
			$orig_slug,
			(string) ( $rules['filename'] ?? '' ),
			static function ( $slug ) {
				return get_option( 'wf_feed_' . $slug ) !== false;
			}
		);
		$new_slug    = $identity['slug'];

		// Build the new config.
		$new_config                          = $config;
		$new_config['feedrules']['filename'] = $identity['filename'];
		$new_config['url']                   = '';
		$new_config['last_updated']          = '';

		// Save both option keys.
		update_option( 'wf_feed_' . $new_slug, $new_config, false );
		update_option( 'wf_config' . $new_slug, $new_config['feedrules'], false );

		// Copy the generated feed file if it exists. V8 writes feeds in the V5
		// nested layout {uploads}/woo-feed/{provider}/{feedType}/{slug}.{ext}
		// (Utility\Filesystem) so a feed's public URL is unchanged after a
		// V5→V8 upgrade — resolve source and target there with the provider.
		$file_copied = false;
		if ( ! empty( $rules['feedType'] ) ) {
			$ext       = (string) $rules['feedType'];
			$provider  = (string) ( $rules['provider'] ?? '' );
			$fs        = new \CTXFeed\V8\Utility\Filesystem();
			$orig_file = $fs->get_feed_path( $orig_slug, $ext, $provider );
			$new_file  = $fs->get_feed_path( $new_slug, $ext, $provider );

			if ( file_exists( $orig_file ) ) {
				// Ensure target directory exists.
				$dir = dirname( $new_file );
				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}
				$file_copied = copy( $orig_file, $new_file );

				// Point the stored URL at the duplicated file.
				if ( $file_copied ) {
					$new_config['url'] = $fs->get_feed_url( $new_slug, $ext, $provider );
					update_option( 'wf_feed_' . $new_slug, $new_config, false );
				}
			}
		}

		// Build the duplicated feed now (V5 parity), independent of the copied
		// file above.
		$this->maybe_auto_generate( $new_slug, 'duplicate' );

		// Retrieve the new feed's full data (includes option_id).
		$new_feed = $this->find_feed( $new_slug );

		return $this->success(
			array(
				'feed'        => $new_feed,
				'file_copied' => $file_copied,
			)
		);
	}

	/**
	 * Decide the option slug + display name for a duplicated feed.
	 *
	 * A feed's display name in the list is its feedrules `filename`, and the
	 * option key is `wf_feed_{slug}`. When the Duplicate dialog supplies a
	 * name we honour it exactly (display name) and derive a unique slug from
	 * it via sanitize_title(). Without a name we preserve V5's behaviour: the
	 * slug becomes `orig-copy` (then `-2`, `-3`, …) and the display name
	 * `{orig filename}-copy(-N)`.
	 *
	 * Pure aside from sanitize_title() — uniqueness is delegated to the
	 * $slug_exists predicate so the decision is unit-testable without WP.
	 *
	 * @since 8.0.0
	 *
	 * @param string   $custom_name   Name from the dialog (may be '').
	 * @param string   $orig_slug     Source slug (option key minus wf_feed_).
	 * @param string   $orig_filename Source feedrules['filename'] (display name).
	 * @param callable $slug_exists   fn(string $slug):bool — true when taken.
	 *
	 * @return array{slug:string, filename:string}
	 */
	public static function build_duplicate_identity( string $custom_name, string $orig_slug, string $orig_filename, callable $slug_exists ): array {
		$custom_name = trim( $custom_name );

		if ( '' !== $custom_name ) {
			// Named duplicate — the display name is the entered name; the slug
			// is a URL-safe form of it, made unique with a numeric suffix.
			$base = sanitize_title( $custom_name, '', 'save' );
			if ( '' === $base ) {
				$base = $orig_slug . '-copy';
			}
			$slug    = $base;
			$counter = 1;
			while ( $slug_exists( $slug ) ) {
				++$counter;
				$slug = $base . '-' . $counter;
			}
			return array(
				'slug'     => $slug,
				'filename' => $custom_name,
			);
		}

		// Default (no name) — V5 parity: orig-copy, orig-copy-2, … with a
		// matching "-copy(-N)" display name.
		$slug    = $orig_slug . '-copy';
		$counter = 1;
		while ( $slug_exists( $slug ) ) {
			++$counter;
			$slug = $orig_slug . '-copy-' . $counter;
		}
		$base_name = '' !== $orig_filename ? $orig_filename : $orig_slug;
		$filename  = $base_name . '-copy' . ( $counter > 1 ? '-' . $counter : '' );

		return array(
			'slug'     => $slug,
			'filename' => $filename,
		);
	}

	/**
	 * Export feed configuration as a .wpf binary file.
	 *
	 * Produces a V5-compatible .wpf file containing the feed rules
	 * packed with metadata (version, filename, MD5 hash), compressed
	 * with gzdeflate. This format is importable by both V5 and V8.
	 *
	 * Sends raw binary response with download headers — bypasses
	 * the normal JSON REST response.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|void Downloads file or returns error.
	 */
	public function export_feed_config( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
		if ( ! is_array( $config ) || ! isset( $config['feedrules'] ) ) {
			return $this->error( __( 'Invalid feed configuration.', 'woo-feed' ), 400 );
		}

		$option_name = $feed_data['option_name'];
		$feed_slug   = str_replace( 'wf_feed_', '', $option_name );

		// Build .wpf binary — V5-compatible format.
		$file_name  = sprintf( '%s-%s.wpf', $feed_slug, time() );
		$compressed = $this->build_wpf_binary( $config['feedrules'], $file_name );

		// Send raw binary download — bypass REST JSON encoding.
		header( 'Pragma: public' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Cache-Control: private', false );
		header( 'Content-Type: application/force-download; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '";' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . strlen( $compressed ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download, not HTML: $compressed is the gzdeflate()d .wpf export body streamed under Content-Type: application/force-download. Escaping would corrupt the exported file byte-for-byte.
		echo $compressed;
		exit;
	}

	/**
	 * Build the V5-compatible .wpf binary for a feed's rules.
	 *
	 * Packs metadata (version, file name, MD5 of the feed JSON) followed by
	 * the feed rules into `pack('VA*VA*')` layout, then compresses with
	 * gzdeflate. This is the exact inverse of import_feed()'s decode path.
	 *
	 * Extracted from export_feed_config() (which ends in exit(), so it
	 * can't be exercised in-process) so the binary format is independently
	 * testable and the import/export round-trip can be verified.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $feedrules Feed rules to serialize.
	 * @param string $file_name File name recorded in the metadata block.
	 * @return string Raw gzdeflate-compressed .wpf bytes.
	 */
	protected function build_wpf_binary( array $feedrules, string $file_name ): string {
		$feed_json = wp_json_encode( $feedrules );
		$meta_json = wp_json_encode(
			array(
				'version'   => defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : '8.0.0',
				'file_name' => $file_name,
				'hash'      => md5( $feed_json ),
			) 
		);

		$bin = pack( 'VA*VA*', strlen( $meta_json ), $meta_json, strlen( $feed_json ), $feed_json );

		return gzdeflate( $bin, 9 );
	}

	/**
	 * Download the feed generation log file.
	 *
	 * Locates the log file via V5 CTX_WC_Log_Handler and sends it
	 * as a browser download. Falls back to scanning the log directory
	 * if the handler class is not available.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|void Downloads file or returns error.
	 */
	public function download_feed_log( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$feed_slug = str_replace( 'wf_feed_', '', $feed_data['option_name'] );

		$upload_dir = wp_get_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/woo-feed/logs/';

		// 1. Try V8 per-feed log: {slug}.log (written by FeedLogger).
		$log_file_path = $log_dir . sanitize_file_name( $feed_slug ) . '.log';

		// 2. Fallback: glob scan for {slug}-*.log (legacy dated log format).
		if ( empty( $log_file_path ) || ! file_exists( $log_file_path ) ) {
			if ( is_dir( $log_dir ) ) {
				$pattern = $log_dir . $feed_slug . '-*.log';
				$files   = glob( $pattern );
				if ( ! empty( $files ) ) {
					usort(
						$files,
						function ( $a, $b ) {
							return filemtime( $b ) - filemtime( $a );
						} 
					);
					$log_file_path = $files[0];
				}
			}
		}

		if ( empty( $log_file_path ) || ! file_exists( $log_file_path ) ) {
			return $this->error( __( 'No log file found for this feed.', 'woo-feed' ), 404 );
		}

		$file_name = sprintf( '%s-%s-%s.log', sanitize_title( $feed_slug ), gmdate( 'Y-m-d' ), time() );

		// Send raw file download.
		header( 'Pragma: public' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Cache-Control: private', false );
		header( 'Content-Type: application/octet-stream; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '";' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . filesize( $log_file_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Streams the plugin's own log file straight to the browser as an octet-stream download; reading it into a PHP string first (WP_Filesystem) would double the memory for no benefit.
		readfile( $log_file_path );
		exit;
	}

	/**
	 * Generate and return a feed report (JSON for modal).
	 *
	 * Creates a FeedReporter, generates the report (which also saves
	 * the .txt file), and returns structured JSON for the frontend modal.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_feed_report( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$feed = $this->find_feed( $id );
		if ( ! $feed ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		try {
			$reporter = new \CTXFeed\V8\Feed\FeedReporter();
			$report   = $reporter->generate( $feed['slug'], $feed );

			return $this->success( $report );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Not debug code: this is the only record of a swallowed \Throwable. The REST response carries the message to the admin UI, but the file/line context needed to support a merchant is lost unless it reaches the PHP error log.
			error_log( '[CTXFeed V8] Report generation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

			return $this->error(
				sprintf( 'Report generation failed: %s', $e->getMessage() ),
				500
			);
		}
	}

	/**
	 * Download the feed report .txt file.
	 *
	 * If the report file doesn't exist yet, generates it first.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|void Downloads file or returns error.
	 */
	public function download_feed_report( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$feed = $this->find_feed( $id );
		if ( ! $feed ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$reporter  = new \CTXFeed\V8\Feed\FeedReporter();
		$file_path = $reporter->get_report_path( $feed['slug'] );

		// Generate report if file doesn't exist.
		if ( ! file_exists( $file_path ) ) {
			$reporter->generate( $feed['slug'], $feed );
		}

		if ( ! file_exists( $file_path ) ) {
			return $this->error( __( 'Unable to generate report file.', 'woo-feed' ), 500 );
		}

		$file_name = sanitize_title( $feed['slug'] ) . '-report-' . gmdate( 'Y-m-d' ) . '.txt';

		header( 'Pragma: public' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Cache-Control: private', false );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '";' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Streams the generated feed file straight to the browser as a download; reading it into a PHP string first (WP_Filesystem) would double the memory for a file that can be hundreds of megabytes.
		readfile( $file_path );
		exit;
	}

	/**
	 * Get feed generation status.
	 *
	 * V8 engine returns enriched progress with batch sizing, ETA,
	 * and timing data. V5 falls back to the simple transient check.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.2
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_feed_status( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		// --- V8 Engine Path ---
		if ( defined( 'CTXFEED_V8_ACTIVE' ) && CTXFEED_V8_ACTIVE ) {
			return $this->get_feed_status_v8( $id );
		}

		// --- V5 Fallback ---
		$progress = get_transient( 'wf_feed_generating_' . $id );

		return $this->success(
			array(
				'status'  => $progress ? 'generating' : 'idle',
				'percent' => $progress ? (int) $progress : 100,
			) 
		);
	}

	/**
	 * V8 enriched feed generation status.
	 *
	 * Resolves FeedManager from the DI Container and returns full
	 * progress data including adaptive batch size, ETA, and timing.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-2.2, FEED-FRD-2.2
	 *
	 * @param string $id Feed identifier (option_id or feed_name slug).
	 * @return \WP_REST_Response
	 */
	private function get_feed_status_v8( string $id ): \WP_REST_Response {
		// Resolve the feed_name from the ID (same lookup as generate_feed).
		$feed_data = $this->find_feed_raw( $id );
		if ( ! $feed_data ) {
			return $this->error( __( 'Feed not found.', 'woo-feed' ), 404 );
		}

		$option_name = $feed_data['option_name'];
		$feed_name   = str_replace( 'wf_feed_', '', $option_name );

		$container = \CTXFeed\V8\Core\Container::get_instance();

		if ( ! $container->has( 'feed.manager' ) ) {
			return $this->error( __( 'Feed manager not available.', 'woo-feed' ), 500 );
		}

		/** @var \CTXFeed\V8\Feed\FeedManager $manager */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$manager  = $container->resolve( 'feed.manager' );
		$progress = $manager->get_progress( $feed_name );

		return $this->success( $progress );
	}

	/**
	 * Bulk actions on multiple feeds.
	 *
	 * Supports: delete, duplicate, auto_update_on, auto_update_off.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function bulk_action( \WP_REST_Request $request ): \WP_REST_Response {
		$action = sanitize_text_field( $request->get_param( 'action' ) );
		$ids    = $request->get_param( 'ids' );

		if ( empty( $action ) || empty( $ids ) || ! is_array( $ids ) ) {
			return $this->error( __( 'Invalid bulk action request.', 'woo-feed' ), 400 );
		}

		// Optional per-feed names for the bulk duplicate ({ feedId: name }).
		$names = $request->get_param( 'names' );
		$names = is_array( $names ) ? $names : array();

		$results    = array();
		$duplicated = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );

			switch ( $action ) {
				case 'delete':
					$feed_data = $this->find_feed_raw( $id );
					if ( $feed_data ) {
						$config    = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
						$feed_slug = str_replace( 'wf_feed_', '', $feed_data['option_name'] );

						// Delete generated feed file if it exists.
						if ( is_array( $config ) && ! empty( $config['url'] ) ) {
							$upload_dir = wp_upload_dir();
							$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $config['url'] );
							if ( file_exists( $file_path ) ) {
								wp_delete_file( $file_path );
							}
						}

						// Cancel recurring before destroying the option so
						// Action Scheduler doesn't keep firing for a
						// missing config.
						$this->sync_feed_schedule( $feed_data['option_name'], array( 'status' => 0 ) );

						delete_option( $feed_data['option_name'] );
						delete_option( 'wf_config' . $feed_slug );
						$results[] = true;
					}
					break;

				case 'duplicate':
					$feed_data = $this->find_feed_raw( $id );
					if ( $feed_data ) {
						$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
						if ( is_array( $config ) && isset( $config['feedrules'] ) ) {
							$orig_slug = str_replace( 'wf_feed_', '', $feed_data['option_name'] );
							$rules     = $config['feedrules'];

							// Use the name the user typed for this feed in the
							// bulk dialog; empty falls back to orig-copy(-N).
							$custom_name = isset( $names[ $id ] ) ? sanitize_text_field( (string) $names[ $id ] ) : '';

							$identity = self::build_duplicate_identity(
								$custom_name,
								$orig_slug,
								(string) ( $rules['filename'] ?? '' ),
								static function ( $slug ) {
									return get_option( 'wf_feed_' . $slug ) !== false;
								}
							);
							$new_slug = $identity['slug'];

							$new_config                          = $config;
							$new_config['feedrules']['filename'] = $identity['filename'];
							$new_config['url']                   = '';
							$new_config['last_updated']          = '';

							update_option( 'wf_config' . $new_slug, $new_config['feedrules'], false );
							update_option( 'wf_feed_' . $new_slug, $new_config, false );

							// Copy the existing generated file so the copy's URL
							// serves immediately (nested V5 layout).
							if ( ! empty( $rules['feedType'] ) ) {
								$ext       = (string) $rules['feedType'];
								$provider  = (string) ( $rules['provider'] ?? '' );
								$fs        = new \CTXFeed\V8\Utility\Filesystem();
								$orig_file = $fs->get_feed_path( $orig_slug, $ext, $provider );
								$new_file  = $fs->get_feed_path( $new_slug, $ext, $provider );
								if ( file_exists( $orig_file ) ) {
									$dir = dirname( $new_file );
									if ( ! is_dir( $dir ) ) {
										wp_mkdir_p( $dir );
									}
									if ( copy( $orig_file, $new_file ) ) {
										$new_config['url'] = $fs->get_feed_url( $new_slug, $ext, $provider );
										update_option( 'wf_feed_' . $new_slug, $new_config, false );
									}
								}
							}

							// Bulk duplicate does NOT auto-regenerate each copy:
							// the original's generated file is copied above so the
							// copy serves immediately, and regenerating many feeds
							// at once would queue a long, lock-contended run. The
							// user regenerates a copy on demand from the list.

							$duplicated[] = $new_slug;
							$results[]    = true;
						}
					}
					break;

				case 'auto_update_on':
				case 'auto_update_off':
					$feed_data = $this->find_feed_raw( $id );
					if ( $feed_data ) {
						$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $feed_data['option_value'] ) );
						if ( is_array( $config ) ) {
							$config['status'] = ( 'auto_update_on' === $action ) ? 1 : 0;
							$results[]        = update_option( $feed_data['option_name'], $config, false );

							// Reconcile recurring action — on registers,
							// off cancels.
							$this->sync_feed_schedule( $feed_data['option_name'], $config );
						}
					}
					break;
			}
		}

		return $this->success(
			array(
				'action'     => $action,
				'processed'  => count( $results ),
				'duplicated' => $duplicated,
			)
		);
	}

	/**
	 * Whether this request should auto-start a one-time generation on save.
	 *
	 * Defaults to true (V5 parity + API / MCP clients rely on it). The React
	 * admin's "Save & generate" sends autoGenerate=false: it navigates to the
	 * feed list and triggers the regenerate there (with a progress bar), so
	 * the save itself must not also start a background run — a second run
	 * double-schedules and, for large async feeds, clobbers the batches.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	private function request_wants_auto_generate( \WP_REST_Request $request ): bool {
		$auto = $request->get_param( 'autoGenerate' );
		if ( null === $auto ) {
			return true;
		}
		return false !== filter_var( $auto, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/**
	 * Auto-start a one-time generation after a feed is created, saved, or
	 * duplicated — V5 parity, where saving a feed built it immediately. This is
	 * separate from the recurring auto-update schedule (which stays off until
	 * toggled). Filterable so it can be disabled site-wide.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $context   'create' | 'update' | 'duplicate'.
	 * @return void
	 */
	private function maybe_auto_generate( string $feed_name, string $context ): void {
		/**
		 * Filter whether saving a feed auto-starts a one-time generation.
		 *
		 * @since 8.0.0
		 *
		 * @param bool   $enabled   Default true (V5 parity).
		 * @param string $feed_name Feed slug.
		 * @param string $context   create|update|duplicate.
		 */
		if ( apply_filters( 'ctxfeed_feed_auto_generate_on_save', true, $feed_name, $context ) ) {
			$this->schedule_generation_for( $feed_name );
		}
	}

	/**
	 * Schedule feed generation for a slug, swallowing errors.
	 *
	 * Best-effort variant of generate_feed_v8()'s scheduling used inside
	 * bulk loops — one feed's scheduling failure must not abort the rest.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @return void
	 */
	private function schedule_generation_for( string $feed_name ): void {
		try {
			$container = \CTXFeed\V8\Core\Container::get_instance();
			if ( ! $container->has( 'feed.scheduler' ) ) {
				return;
			}

			$config = \CTXFeed\V8\Core\Config::from_feed_name( $feed_name );
			if ( ! $config ) {
				return;
			}

			$container->resolve( 'feed.scheduler' )->schedule_generation( $feed_name, $config );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only record of a swallowed scheduling failure inside the bulk loop; the feed still exists and can be regenerated manually.
			error_log( '[CTXFeed V8] bulk duplicate generation scheduling failed for ' . $feed_name . ': ' . $e->getMessage() );
		}
	}

	// =====================================================================
	// Template / channel list endpoints.
	// =====================================================================

	/**
	 * Get all available merchant templates (grouped).
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_templates( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$channels = DropdownRegistry::get_channels();

		$data = array();
		foreach ( $channels as $group ) {
			$group_name = $group['optionGroup'] ?? '';
			$options    = $group['options'] ?? array();
			foreach ( $options as $key => $label ) {
				$data[ $key ] = array(
					'label' => $label,
					'group' => $group_name,
				);
			}
		}

		return $this->success( $data );
	}

	/**
	 * Get template info (supported file types, docs, video) for a merchant.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_template_info( \WP_REST_Request $request ): \WP_REST_Response {
		$template = $request->get_param( 'template' );

		$info = TemplateInfo::get( $template );

		// Attach the resolved channel-logo URL (empty string when the channel
		// has no bundled logo) so the Make Feed header / Channel-info rail can
		// show the brand mark without building URLs client-side.
		$info['logo'] = TemplateInfo::logo_url( (string) $template );

		return $this->success(
			array(
				'template' => $template,
				'info'     => $info,
			)
		);
	}

	// =====================================================================
	// Helper methods.
	// =====================================================================

	/**
	 * Build V5-compatible feedrules array from React form data.
	 *
	 * The Make Feed UI stores rules as row objects:
	 *   [ { mattribute, prefix, type, attribute, default, suffix, output_type, limit } ]
	 *
	 * V5 expects parallel arrays:
	 *   { mattributes: [...], prefix: [...], type: [...], attributes: [...], ... }
	 *
	 * @since 8.0.0
	 *
	 * @param array  $form                The makeFeedForm object.
	 * @param array  $rules               The feedRules row array.
	 * @param array  $filter_options      Simple filter toggles keyed by V5 filter key ('yes'/'no').
	 * @param array  $post_status         Product post statuses to include, as slugs.
	 * @param array  $categories          Product category slugs to include or exclude.
	 * @param array  $product_ids         Product IDs to include or exclude.
	 * @param array  $filter_mode         'include'/'exclude' mode keyed by post_status, categories, product_ids.
	 * @param array  $number_format       Price formatting: decimals, decimal_separator, thousand_separator.
	 * @param array  $campaign_parameters UTM parameters for the Campaign URL Builder.
	 * @param array  $string_replace      Search/replace rows applied to attribute output.
	 * @param string $shipping_country    Shipping country scope: 'feed', 'all', or '' for the store default.
	 * @param string $tax_country         Tax country scope: 'feed', 'all', or '' for the store default.
	 * @param array  $ftp                 Remote transport settings (enabled, protocol, mode, host, port, username, password, path).
	 * @param array  $existing_feedrules  Previously saved feedrules; used to keep the stored FTP password when the form sends a masked value. Empty on create.
	 * @param array  $advanced_filter     Advanced filter groups from the query builder.
	 * @return array V5-compatible feedrules.
	 */
	private function build_feedrules_from_form(
		array $form,
		array $rules,
		array $filter_options = array(),
		array $post_status = array(),
		array $categories = array(),
		array $product_ids = array(),
		array $filter_mode = array(),
		array $number_format = array(),
		array $campaign_parameters = array(),
		array $string_replace = array(),
		string $shipping_country = '',
		string $tax_country = '',
		array $ftp = array(),
		array $existing_feedrules = array(),
		array $advanced_filter = array()
	): array {
		// Build parallel arrays from row objects.
		$mattributes = array();
		$prefixes    = array();
		$types       = array();
		$attributes  = array();
		$defaults    = array();
		$suffixes    = array();
		$output_type = array();
		$limits      = array();

		foreach ( $rules as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$mattributes[] = isset( $row['mattribute'] ) ? sanitize_text_field( $row['mattribute'] ) : '';
			// prefix / suffix use the whitespace-preserving sanitizer so
			// customer-typed decorators like "Brand: " (trailing space) or
			// " USD" (leading space) survive the save round-trip.
			// sanitize_text_field would trim them, producing a dashed-on
			// value with no visual separator. PROD-FRD-10.12.
			$prefixes[]   = Sanitizer::preserve_whitespace( $row['prefix'] ?? '' );
			$types[]      = isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : 'attribute';
			$attributes[] = isset( $row['attribute'] ) ? sanitize_text_field( $row['attribute'] ) : '';
			$defaults[]   = isset( $row['default'] ) ? sanitize_text_field( $row['default'] ) : '';
			$suffixes[]   = Sanitizer::preserve_whitespace( $row['suffix'] ?? '' );
			// V5-parity multiselect: each row stores an ARRAY of output-type
			// codes (['2','12']). Accept the array, a comma-joined string, or
			// a bare code and whitelist to the known 1–24 set. NOT
			// sanitize_text_field — that would flatten the array. PROD-FRD-10.11.
			$output_type[] = self::sanitize_output_type( $row['output_type'] ?? array( '1' ) );
			$limits[]      = isset( $row['limit'] ) ? sanitize_text_field( $row['limit'] ) : '';
		}

		// V5 parity: `feedCurrency` is never stored empty. When no
		// multi-currency plugin is active the V5 feed editor submitted the
		// store's WooCommerce currency through a hidden input
		// (V5/src/.../FeedContentSettings.js:983-984); mirror that fallback
		// so compat shims comparing `$config->get_feed_currency()` against
		// the active currency never see ''.
		$feed_currency = sanitize_text_field( $form['currency'] ?? '' );
		if ( '' === $feed_currency && function_exists( 'get_woocommerce_currency' ) ) {
			$feed_currency = get_woocommerce_currency();
		}

		$feedrules = array(
			'provider'          => sanitize_text_field( $form['template'] ?? '' ),
			'filename'          => sanitize_text_field( $form['fileName'] ?? '' ),
			'feedType'          => sanitize_text_field( $form['fileType'] ?? 'xml' ),
			// CSV/TSV/TXT column separator + field enclosure (V5 keys, decoded
			// by Config::get_delimiter()/get_enclosure() + CSVTemplate). NOT
			// run through sanitize_text_field — it would strip the tab / space
			// delimiter and the "None" (space) enclosure to empty; a value
			// whitelist keeps them exact and matches V5's stored payload.
			'delimiter'         => self::sanitize_csv_delimiter( $form['delimiter'] ?? ',' ),
			'enclosure'         => self::sanitize_csv_enclosure( $form['enclosure'] ?? 'double' ),
			// Opt-in UTF-8 BOM for delimited feeds (Excel). 'yes'/'no'.
			'csv_bom'           => ( 'yes' === ( $form['csvBom'] ?? '' ) ) ? 'yes' : 'no',
			'feed_country'      => sanitize_text_field( $form['country'] ?? '' ),
			'feedCurrency'      => $feed_currency,
			// Feed language (V5 key: `feedLanguage`) — consumed by the
			// WPML/Polylang/TranslatePress compat shims at batch start via
			// `$config->get_feed_language()`. Empty when no multilingual
			// plugin is active (the UI renders no Language select then).
			'feedLanguage'      => sanitize_text_field( $form['feedLanguage'] ?? '' ),
			'is_variations'     => sanitize_text_field( $form['includeVariations'] ?? '' ),
			// Variation strategy keys (V5: `variable_price` /
			// `variable_quantity`) — which variation's price/quantity a
			// VARIABLE (parent) row reports when `is_variations` is 'n' or
			// 'both'. V5's editor always persisted both keys, defaulting to
			// 'first' (V5/Feed/FeedRules.php:54-55, V5/src/data/makeFeed.js),
			// so an absent/empty form value stores 'first' — never ''.
			'variable_price'    => $this->sanitize_variation_strategy( $form['variablePrice'] ?? '' ),
			'variable_quantity' => $this->sanitize_variation_strategy( $form['variableQuantity'] ?? '' ),
			// Feed sort (V5 "Sort feed by") — consumed by ProductQuery::resolve_sort(),
			// which whitelists the value, so an unknown one is a harmless no-op.
			'feed_sort'         => sanitize_text_field( $form['feedSort'] ?? '' ),
			'feed_sort_order'   => sanitize_text_field( $form['feedSortOrder'] ?? '' ),
			// Custom Template 1 XML skeleton (V5 keys, Config.php:976-980):
			// <itemsWrapper> wraps the file, <itemWrapper> wraps each
			// product, extraHeader lines are appended after the XML
			// declaration (XMLTemplate consumes all three). V5 never stored
			// the wrappers empty — defaults 'products' / 'product'.
			// phpcs:disable Universal.Operators.DisallowShortTernary.Found -- The short ternary is deliberate: spelling it out would call sanitize_text_field() twice on the same value, re-running the `sanitize_text_field` filter chain. The intent — "the sanitized value, or the V5 default when it sanitizes to empty" — is exactly what ?: expresses.
			'itemsWrapper'      => sanitize_text_field( $form['itemsWrapper'] ?? '' ) ?: 'products',
			'itemWrapper'       => sanitize_text_field( $form['itemWrapper'] ?? '' ) ?: 'product',
			// phpcs:enable Universal.Operators.DisallowShortTernary.Found
			// Raw like feed_config_custom2 below — header lines are XML
			// markup (namespaces, processing instructions); a text-field
			// sanitize would strip them.
			'extraHeader'       => wp_unslash( (string) ( $form['extraHeader'] ?? '' ) ),
			'cron'              => sanitize_text_field( $form['intervalTime'] ?? '' ),
			'mattributes'       => $mattributes,
			'prefix'            => $prefixes,
			'type'              => $types,
			'attributes'        => $attributes,
			'default'           => $defaults,
			'suffix'            => $suffixes,
			'output_type'       => $output_type,
			'limit'             => $limits,
		);

		// Custom Template 2 (XML) user-authored template body. The React
		// editor only renders this textarea for the custom2-merchant family
		// (custom2/admarkt/yandex_xml/glami) so non-Custom2 feeds will pass
		// an empty string. Persist it verbatim — V5 stripped backslashes
		// at *render* time (see FeedRules.php:130) so we don't sanitize
		// the body here beyond `wp_unslash` to undo the WP-injected slashes.
		// PROD-FRD-10.6.
		if ( isset( $form['feedConfigCustom2'] ) ) {
			$feedrules['feed_config_custom2'] = wp_unslash( (string) $form['feedConfigCustom2'] );
		}

		// Merge Yes/No radio filter options (V5-compatible keys).
		//
		// Values stored as PHP booleans to match V5 storage format exactly —
		// V5 uses isset($cfg[$key]) && $cfg[$key] truthy checks, so storing
		// 'no' as a string would incorrectly evaluate as truthy (non-empty
		// string). Booleans avoid this bug and stay compatible with V5's
		// frontend too.
		$allowed_filter_keys = array(
			'is_backorder',
			'is_outOfStock',
			'product_visibility',
			'outofstock_visibility',
			'is_emptyDescription',
			'is_emptyImage',
			'is_emptyPrice',
		);

		foreach ( $allowed_filter_keys as $key ) {
			$raw               = isset( $filter_options[ $key ] ) ? $filter_options[ $key ] : 'no';
			$feedrules[ $key ] = $this->normalise_yes_no_to_bool( $raw );
		}

		// Product Status / Categories / Product IDs + per-field include-exclude mode.
		// V5 keys: post_status (array of slugs), categories (array of slugs),
		// product_ids (array of ints), filter_mode (object keyed by field).
		$feedrules['post_status'] = array_values(
			array_filter(
				array_map( 'sanitize_text_field', (array) $post_status ),
				static function ( $s ) {
					return '' !== $s;
				}
			) 
		);

		$feedrules['categories'] = array_values(
			array_filter(
				array_map( 'sanitize_title', (array) $categories ),
				static function ( $s ) {
					return '' !== $s;
				}
			) 
		);

		$feedrules['product_ids'] = array_values(
			array_filter(
				array_map( 'absint', (array) $product_ids ),
				static function ( $id ) {
					return $id > 0;
				}
			) 
		);

		$mode_fields = array( 'post_status', 'categories', 'product_ids' );
		$safe_mode   = array();
		foreach ( $mode_fields as $field ) {
			$raw_mode            = isset( $filter_mode[ $field ] ) ? sanitize_text_field( $filter_mode[ $field ] ) : 'include';
			$safe_mode[ $field ] = in_array( $raw_mode, array( 'include', 'exclude' ), true ) ? $raw_mode : 'include';
		}
		$feedrules['filter_mode'] = $safe_mode;

		// Number Format (V5 keys: decimals, decimal_separator, thousand_separator).
		// Stored as strings to preserve V5 behaviour and allow empty values
		// which mean "use WooCommerce defaults".
		$feedrules['decimals']           = isset( $number_format['decimals'] )
			? sanitize_text_field( $number_format['decimals'] )
			: '';
		$feedrules['decimal_separator']  = isset( $number_format['decimal_separator'] )
			? sanitize_text_field( $number_format['decimal_separator'] )
			: '';
		$feedrules['thousand_separator'] = isset( $number_format['thousand_separator'] )
			? sanitize_text_field( $number_format['thousand_separator'] )
			: '';

		// Campaign URL Builder (V5 key: campaign_parameters).
		$utm_keys   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
		$utm_params = array();
		foreach ( $utm_keys as $utm_key ) {
			$utm_params[ $utm_key ] = isset( $campaign_parameters[ $utm_key ] )
				? sanitize_text_field( $campaign_parameters[ $utm_key ] )
				: '';
		}
		$feedrules['campaign_parameters'] = $utm_params;

		// String Replace (V5 key: str_replace).
		// Array of { subject, search, replace } rows. Drop fully-empty rows
		// (where subject is blank) since they produce no-op at generation.
		$safe_str_replace = array();
		foreach ( $string_replace as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$subject = isset( $row['subject'] ) ? sanitize_text_field( $row['subject'] ) : '';
			if ( '' === $subject ) {
				continue;
			}
			$safe_str_replace[] = array(
				'subject' => $subject,
				// search/replace must PRESERVE whitespace and Unicode:
				// customers commonly want to match/replace patterns like
				// ", " (comma+space) or "\n" — sanitize_textarea_field
				// trims leading/trailing whitespace and collapses runs of
				// tabs/spaces, which silently ate the space in ", " and
				// broke the observed feed. Custom sanitizer strips only
				// HTML tags + null bytes, keeping every other byte intact
				// (including emoji, leading/trailing whitespace, tabs,
				// and multi-space runs). PROD-FRD-10.9.
				'search'  => self::sanitize_str_replace_pattern( $row['search'] ?? '' ),
				'replace' => self::sanitize_str_replace_pattern( $row['replace'] ?? '' ),
			);
		}
		$feedrules['str_replace'] = $safe_str_replace;

		// Advanced (Pro) filter — nested groups in / V5-compat flat out.
		//
		// UI sends a 2-level tree:
		// advancedFilter = {
		// groups: [{
		// base_operator: 'AND'|'OR',           // joins this group with the previous
		// base_filters: [{
		// attribute, condition, compare,
		// operator: 'AND'|'OR',              // joins this sub with previous within group
		// }, ...],
		// }, ...],
		// filterType: 1 | 2,                     // global default joiner (1=OR, 2=AND)
		// }
		//
		// We flatten to V5's parallel arrays (fattribute / condition /
		// filterCompare / concatType + filterType) so 70K+ Pro feeds and
		// the V8/Filter/AdvanceFilter engine both keep working unchanged.
		// Group structure is persisted alongside in `_filter_groups`
		// (V8-only metadata, ignored by the filter engine) so the user's
		// visual grouping survives save / reload.
		//
		// Flattening rule (V5 evaluates left-to-right flat):
		// - First sub of group 0: V5 ignores its concatType; we still
		// write the sub's own operator for consistency.
		// - Subsequent subs within a group: concatType = sub.operator
		// - First sub of a non-first group: concatType = group.base_operator
		// - Subsequent subs of that group: concatType = sub.operator.
		$advance_groups = isset( $advanced_filter['groups'] ) && is_array( $advanced_filter['groups'] )
			? $advanced_filter['groups']
			: array();
		$valid_ops      = array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'nContains', 'between' );
		$valid_concat   = array( 'AND', 'OR' );

		$f_attrs      = array();
		$f_conditions = array();
		$f_compares   = array();
		$f_concats    = array();
		$group_meta   = array();

		foreach ( $advance_groups as $group_idx => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group_op = isset( $group['base_operator'] ) ? strtoupper( (string) $group['base_operator'] ) : 'AND';
			if ( ! in_array( $group_op, $valid_concat, true ) ) {
				$group_op = 'AND';
			}
			$subs = isset( $group['base_filters'] ) && is_array( $group['base_filters'] )
				? $group['base_filters']
				: array();

			$accepted_in_group = 0;
			foreach ( $subs as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$attribute = isset( $sub['attribute'] ) ? sanitize_text_field( $sub['attribute'] ) : '';
				if ( '' === $attribute ) {
					continue; // Drop empty rows — they'd reject every product.
				}
				$op = isset( $sub['condition'] ) ? (string) $sub['condition'] : '';
				if ( ! in_array( $op, $valid_ops, true ) ) {
					continue; // Unknown operator — skip rather than store junk.
				}
				$sub_concat = isset( $sub['operator'] ) ? strtoupper( (string) $sub['operator'] ) : 'AND';
				if ( ! in_array( $sub_concat, $valid_concat, true ) ) {
					$sub_concat = 'AND';
				}

				// First accepted sub of a non-first group inherits the
				// group's base_operator. All other rows use the sub's own
				// operator.
				$concat_for_v5 = ( 0 === $accepted_in_group && $group_idx > 0 )
					? $group_op
					: $sub_concat;

				$f_attrs[]      = $attribute;
				$f_conditions[] = $op;
				$f_compares[]   = isset( $sub['compare'] ) ? sanitize_text_field( (string) $sub['compare'] ) : '';
				$f_concats[]    = $concat_for_v5;
				++$accepted_in_group;
			}

			if ( $accepted_in_group > 0 ) {
				$group_meta[] = array(
					'size'          => $accepted_in_group,
					'base_operator' => $group_op,
				);
			}
		}

		$feedrules['fattribute']    = $f_attrs;
		$feedrules['condition']     = $f_conditions;
		$feedrules['filterCompare'] = $f_compares;
		$feedrules['concatType']    = $f_concats;
		// V8-only metadata: lets the UI rebuild the user's visual grouping
		// on the next edit. V5 / V8 AdvanceFilter ignore this key.
		$feedrules['_filter_groups'] = $group_meta;
		// V5 filterType (int): 1 = OR default, 2 = AND default. Anything
		// else falls back to 2 to match V5's installer default.
		$f_type                  = isset( $advanced_filter['filterType'] ) ? (int) $advanced_filter['filterType'] : 2;
		$feedrules['filterType'] = ( 1 === $f_type ) ? 1 : 2;

		// Shipping / Tax country scope (V5 keys). Only 'feed' or 'all' are
		// accepted values; anything else is ignored (empty falls back to
		// the global allow_all_shipping setting at generation time).
		$feedrules['shipping_country'] = in_array( $shipping_country, array( 'feed', 'all' ), true )
			? $shipping_country
			: '';
		$feedrules['tax_country']      = in_array( $tax_country, array( 'feed', 'all' ), true )
			? $tax_country
			: '';

		// FTP / SFTP upload settings (V5 feedrules keys).
		// Passwords are encrypted at rest via V8 Encryptor. Empty password
		// from the UI means "keep the existing one" — important so users
		// editing a feed don't have to re-enter their password every save.
		$feedrules['ftpenabled'] = ! empty( $ftp['enabled'] ) ? 1 : 0;
		$feedrules['ftporsftp']  = ( isset( $ftp['protocol'] ) && 'sftp' === $ftp['protocol'] ) ? 'sftp' : 'ftp';
		$feedrules['ftpmode']    = ( isset( $ftp['mode'] ) && 'active' === $ftp['mode'] ) ? 'active' : 'passive';
		$feedrules['ftphost']    = isset( $ftp['host'] ) ? sanitize_text_field( $ftp['host'] ) : '';
		$feedrules['ftpport']    = isset( $ftp['port'] ) ? sanitize_text_field( (string) $ftp['port'] ) : '';
		$feedrules['ftpuser']    = isset( $ftp['username'] ) ? sanitize_text_field( $ftp['username'] ) : '';
		$feedrules['ftppath']    = isset( $ftp['path'] ) ? sanitize_text_field( $ftp['path'] ) : '';

		$incoming_password = isset( $ftp['password'] ) ? (string) $ftp['password'] : '';
		if ( '' !== $incoming_password ) {
			$encryptor                = new \CTXFeed\V8\Utility\Encryptor();
			$feedrules['ftppassword'] = $encryptor->encrypt( $incoming_password );
		} elseif ( isset( $existing_feedrules['ftppassword'] ) ) {
			$feedrules['ftppassword'] = $existing_feedrules['ftppassword'];
		} else {
			$feedrules['ftppassword'] = '';
		}

		return $feedrules;
	}

	/**
	 * Normalise a Yes/No-ish value to a strict boolean.
	 *
	 * Accepts booleans, 1/0, 'yes'/'no', 'on'/'off', 'y'/'n', 'enable'/'disable'.
	 * Anything else (including empty/null) → false.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw input.
	 * @return bool
	 */
	/**
	 * Sanitize a String-Replace search/replace pattern while PRESERVING
	 * every observable byte the user typed.
	 *
	 * Unlike `sanitize_text_field()` / `sanitize_textarea_field()`, this:
	 *   • Does NOT trim leading/trailing whitespace.
	 *   • Does NOT collapse runs of tabs or spaces.
	 *   • Does NOT strip valid UTF-8 code points above 0x7F (emoji,
	 *     accented characters, invisible-but-meaningful marks).
	 *
	 * It only removes what's actually unsafe:
	 *   • HTML/JS tags (XSS surface if we ever echo the pattern back).
	 *   • Null bytes (defence in depth).
	 *   • Objects/arrays (accident guard — pattern MUST be a string).
	 *
	 * Rationale: this field feeds a `preg_replace('/…/mi', …, $value)`
	 * call, so bytes in equals bytes out is a hard requirement.
	 * Customers commonly want to match ", " (comma+space), "\n", or
	 * emoji separators — any whitespace/Unicode-destructive sanitizer
	 * silently breaks their filters. PROD-FRD-10.9.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw form input.
	 * @return string Byte-preserving sanitized string.
	 */
	private static function sanitize_str_replace_pattern( $value ): string {
		return Sanitizer::preserve_whitespace( $value );
	}

	/**
	 * Sanitize a variation-strategy value (`variable_price` /
	 * `variable_quantity` feedrules), defaulting to V5's form default.
	 *
	 * V5 stored whatever the strategy <select> submitted and its editor
	 * seeded both keys with 'first' for every feed — the keys are never
	 * persisted empty. The option VALUES are not whitelisted here because
	 * the lists are filterable (`ctxfeed_dropdown_variable_price` /
	 * `ctxfeed_dropdown_variable_quantity`) — a hard whitelist would break
	 * filter-added strategies. Unknown values are harmless at generation
	 * time: both consumers fall through to their V5 default branch
	 * (price → 'min' family default, quantity → sum).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw form value.
	 * @return string Sanitized strategy, 'first' when empty.
	 */
	private function sanitize_variation_strategy( $value ): string {
		$strategy = sanitize_text_field( (string) $value );

		return '' !== $strategy ? $strategy : 'first';
	}

	/**
	 * Whitelist-validate the CSV/TSV/TXT delimiter.
	 *
	 * The value must survive intact — sanitize_text_field() would strip the
	 * TAB and Space delimiters to empty — so only V5's known delimiter
	 * characters are accepted (woo_feed_get_csv_delimiters()); anything else
	 * falls back to a comma.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Submitted delimiter value.
	 * @return string One of the allowed delimiter characters.
	 */
	private static function sanitize_csv_delimiter( $value ): string {
		$allowed = array( ',', ':', ' ', '|', ';', "\t" );

		return in_array( (string) $value, $allowed, true ) ? (string) $value : ',';
	}

	/**
	 * Whitelist-validate the CSV enclosure type.
	 *
	 * V5 stores the enclosure as a type name ('double'/'single') or a space
	 * for "None" (woo_feed_get_csv_enclosure()); CSVTemplate::resolve_enclosure()
	 * decodes it to the actual character. Anything else falls back to double
	 * quotes.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Submitted enclosure value.
	 * @return string One of the allowed enclosure types.
	 */
	private static function sanitize_csv_enclosure( $value ): string {
		$allowed = array( 'double', 'single', ' ' );

		return in_array( (string) $value, $allowed, true ) ? (string) $value : 'double';
	}

	/**
	 * Whitelist-validate a per-row `output_type` value into an array of codes.
	 *
	 * V5's output type was a MULTISELECT per attribute row
	 * (`output_type[row][]`), stored — and read by
	 * `FormatOutput::get_output()` (which requires `is_array()`) — as an ARRAY
	 * of numeric code strings. V8's field was single-select for a while, so a
	 * submitted value can arrive as that V5 array (['2','12']), a comma-joined
	 * string ('1, 2, 11', as some channel TemplateDefaults carry), or a bare
	 * string ('2'). All three are normalised to a clean, de-duplicated array
	 * of codes drawn from the known 1–24 set (DropdownRegistry::get_output_types()).
	 *
	 * Writing the ARRAY shape keeps the shared V5↔V8 feedrules payload
	 * compatible (a bare string would make V5's `is_array()` guard skip
	 * formatting entirely) and lets the generator apply several codes to one
	 * column. `sanitize_text_field()` is deliberately NOT used — it would
	 * mangle the array; the value whitelist is the sanitiser. An empty result
	 * stores Default ('1'), never '' (V5 parity — no row is left value-less).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Submitted per-row output_type (array|string).
	 * @return string[] Array of allowed code strings; ['1'] when none valid.
	 */
	private static function sanitize_output_type( $value ): array {
		$allowed = array_map( 'strval', range( 1, 24 ) );
		$parts   = is_array( $value ) ? $value : explode( ',', (string) $value );

		$codes = array();
		foreach ( $parts as $part ) {
			$code = trim( (string) $part );
			if ( in_array( $code, $allowed, true ) && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}

		return empty( $codes ) ? array( '1' ) : $codes;
	}

	/**
	 * Coerce a checkbox-style form value to a strict boolean.
	 *
	 * The React form and the stored V5 feedrules disagree on representation:
	 * a toggle can arrive as a real bool, as 1/0, or as one of the legacy
	 * strings ('yes', 'on', 'true', …). Anything not recognised is false.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw form or feedrules value.
	 * @return bool True when the value represents an enabled toggle.
	 */
	private function normalise_yes_no_to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$v = strtolower( trim( $value ) );
		return in_array( $v, array( 'yes', 'y', 'on', 'enable', '1', 'true' ), true );
	}

	/**
	 * Collect non-blocking environment warnings surfaced back to the Make
	 * Feed UI at save time.
	 *
	 * Today this is a single warning when the feed output directory
	 * (`wp-content/uploads/woo-feed/`) is not writable, so the user learns
	 * the moment they save that generation will produce no file — instead of
	 * discovering it only when the feed silently never updates. The save
	 * itself still succeeds (the config is stored); only the eventual file
	 * write would fail, and that is worth flagging early.
	 *
	 * @since 8.1.0
	 *
	 * @return array<int,string> Human-readable warnings (empty when healthy).
	 */
	private function save_warnings(): array {
		$warnings = array();

		$filesystem = new \CTXFeed\V8\Utility\Filesystem();
		if ( ! $filesystem->is_feed_dir_writable() ) {
			$warnings[] = __(
				'The feed output folder (wp-content/uploads/woo-feed/) is not writable, so generated feed files cannot be saved. Fix the folder permissions before generating.',
				'woo-feed'
			);
		}

		return $warnings;
	}

	/**
	 * Find a feed by option_id (numeric) or option_name/slug.
	 *
	 * @since 8.0.0
	 *
	 * @param string|int $id Feed option_id or slug.
	 * @return array|null Normalized feed data or null.
	 */
	private function find_feed( $id ): ?array {
		$raw = $this->find_feed_raw( $id );

		if ( ! $raw ) {
			return null;
		}

		$config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( $raw['option_value'] ) );
		if ( ! is_array( $config ) || ! isset( $config['feedrules'] ) ) {
			return null;
		}

		$rules     = $config['feedrules'];
		$feed_name = str_replace( 'wf_feed_', '', $raw['option_name'] );
		$provider  = isset( $rules['provider'] ) ? $rules['provider'] : '';

		// Decrypt FTP password for the edit form. Plaintext passwords never
		// leave this method's output path otherwise — stored encrypted, and
		// only decrypted when the frontend explicitly loads the feed.
		if ( ! empty( $rules['ftppassword'] ) ) {
			$encryptor            = new \CTXFeed\V8\Utility\Encryptor();
			$rules['ftppassword'] = $encryptor->decrypt( $rules['ftppassword'] );
		}

		return array(
			'id'          => $raw['option_id'],
			'option_name' => $raw['option_name'],
			'feed_name'   => isset( $rules['filename'] ) ? $rules['filename'] : $feed_name,
			'slug'        => $feed_name,
			'channel'     => ucwords( str_replace( '_', ' ', $provider ) ),
			'provider'    => $provider,
			'logo'        => TemplateInfo::logo_url( $provider ),
			'file_type'   => isset( $rules['feedType'] ) ? strtoupper( $rules['feedType'] ) : 'XML',
			'feed_url'    => isset( $config['url'] ) ? $config['url'] : '',
			'auto_update' => isset( $config['status'] ) ? 1 === (int) $config['status'] : true,
			'interval'    => isset( $rules['cron'] ) ? $this->format_interval( $rules['cron'] ) : '—',
			'last_update' => $this->to_iso8601_utc( isset( $config['last_updated'] ) ? (string) $config['last_updated'] : '' ),
			'feedrules'   => $rules,
			'config'      => $config,
		);
	}

	/**
	 * Find raw feed row from wp_options by option_id or slug.
	 *
	 * @since 8.0.0
	 *
	 * @param string|int $id Feed option_id or slug.
	 * @return array|null Raw database row or null.
	 */
	private function find_feed_raw( $id ): ?array {
		global $wpdb;

		if ( is_numeric( $id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under the wf_feed_ prefix (shared with V5 — no migration). get_option() cannot enumerate by prefix and these rows are deliberately non-autoloaded, so a direct prepared query is the only way to list them. Admin REST route only; never on the feed-generation path.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id = %d",
					absint( $id )
				),
				ARRAY_A
			);
		} else {
			$option_name = $id;

			// Add prefix if not already present.
			if ( 0 !== strpos( $option_name, 'wf_feed_' ) ) {
				$option_name = 'wf_feed_' . $option_name;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under the wf_feed_ prefix (shared with V5 — no migration). get_option() cannot enumerate by prefix and these rows are deliberately non-autoloaded, so a direct prepared query is the only way to list them. Admin REST route only; never on the feed-generation path.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name = %s",
					$option_name
				),
				ARRAY_A
			);
		}

		return $row ? $row : null;
	}

	/**
	 * Reconcile a feed's recurring schedule after a CRUD mutation.
	 *
	 * Wraps {@see FeedScheduler::sync_recurring_schedule()} with the
	 * defensive checks the endpoint needs:
	 *   - V5 mode is a no-op (V5 has its own cron handlers).
	 *   - If the scheduler service isn't bound (extreme edge — Bootstrap
	 *     hasn't run), bail silently rather than 500ing the endpoint.
	 *   - Accepts the option_name (with `wf_feed_` prefix) for convenience
	 *     since most callsites already have it on hand.
	 *
	 * Call this from every endpoint path that writes or deletes a
	 * `wf_feed_*` option. Safe to call multiple times — it's idempotent.
	 *
	 * For deletions, pass `array( 'status' => 0 )` so the helper cancels
	 * any registered recurring action.
	 *
	 * @since 8.0.0
	 *
	 * @param string $option_name `wf_feed_<slug>` option name.
	 * @param array  $feed_data   Stored feed array (status, feedrules, ...).
	 *
	 * @return void
	 */
	private function sync_feed_schedule( string $option_name, array $feed_data ): void {
		if ( ! defined( 'CTXFEED_V8_ACTIVE' ) || ! CTXFEED_V8_ACTIVE ) {
			return; // V5 owns its own cron — don't touch.
		}

		$container = Container::get_instance();
		if ( ! $container->has( 'feed.scheduler' ) ) {
			return; // V8 not booted — happens during installer/upgrade race.
		}

		$feed_name = 0 === strpos( $option_name, 'wf_feed_' )
			? substr( $option_name, 8 )
			: $option_name;

		try {
			/** @var FeedScheduler $scheduler */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
			$scheduler = $container->resolve( 'feed.scheduler' );
			$scheduler->sync_recurring_schedule( $feed_name, $feed_data );
		} catch ( \Throwable $e ) {
			// Schedule sync must never break a feed save. Log and move on.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Not debug code: schedule sync must never break a feed save, so the exception is swallowed. Without this line the failure would be completely silent.
			error_log( '[CTXFeed V8] sync_feed_schedule failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Format a cron interval value to a human-readable string.
	 *
	 * @since 8.0.0
	 *
	 * @param string $interval Interval value in hours or a named schedule.
	 * @return string Human-readable interval.
	 */
	private function format_interval( string $interval ): string {
		$map = array(
			'1'   => 'Every 1 Hour',
			'6'   => 'Every 6 Hours',
			'12'  => 'Every 12 Hours',
			'24'  => 'Every 24 Hours',
			'168' => 'Every Week',
		);

		return $map[ $interval ] ?? $interval;
	}
}
