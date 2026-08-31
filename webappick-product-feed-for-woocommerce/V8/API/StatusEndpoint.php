<?php
/**
 * StatusEndpoint — REST API for system status and diagnostics.
 *
 * Collects environment data natively from WordPress / WooCommerce and
 * structures it into clean, section-grouped JSON for the V8 admin React UI.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status REST endpoint.
 *
 * @since 8.0.0
 */
class StatusEndpoint extends RestController {

	/**
	 * Register status routes.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /ctxfeed/v8/status — full system status.
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// GET /ctxfeed/v8/status/logs — error logs.
		register_rest_route(
			$this->namespace,
			'/status/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// DELETE /ctxfeed/v8/status/cache — clear status cache.
		register_rest_route(
			$this->namespace,
			'/status/cache',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// GET /ctxfeed/v8/status/notices — admin notices for the React app.
		register_rest_route(
			$this->namespace,
			'/status/notices',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notices' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// POST /ctxfeed/v8/status/notices/dismiss — persist a per-user dismissal.
		register_rest_route(
			$this->namespace,
			'/status/notices/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_notice' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Notice ID to dismiss.', 'woo-feed' ),
					),
				),
			)
		);
	}

	/**
	 * Return the admin notices for the React app (slider + Status page list).
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_notices( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return $this->success(
			array( 'notices' => ( new \CTXFeed\V8\Status\NoticeProvider() )->collect() )
		);
	}

	/**
	 * Persist a per-user dismissal for the given notice id.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function dismiss_notice( \WP_REST_Request $request ): \WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( '' === $id ) {
			return $this->error( __( 'Missing notice ID.', 'woo-feed' ), 400 );
		}

		( new \CTXFeed\V8\Admin\Notices() )->dismiss_notice( $id );

		return $this->success( array( 'dismissed' => $id ) );
	}

	/**
	 * Get full system status.
	 *
	 * Collects environment data natively from WordPress / WooCommerce, then
	 * reshapes it into section-grouped arrays the frontend StatusDisclosure
	 * components consume directly.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		try {
			// Build structured sections for the frontend. Every section is
			// assembled natively from WP / WooCommerce — no V5 dependency.
			$sections = array(
				'ctx_feed'   => $this->build_ctx_feed_section(),
				'wordpress'  => $this->build_wordpress_section(),
				'server'     => $this->build_server_section(),
				'database'   => $this->build_database_section(),
				'plugins'    => $this->build_plugins_section(),
				'theme'      => $this->build_theme_section(),
				'filesystem' => $this->build_filesystem_section(),
			);

			// Always build a plain-text dump of every section for the
			// frontend "Copy All" button. V5's get_status_logs_error()
			// produced this for V5's old admin page, but it's empty when
			// V5 has no errors logged — leading to a confusing
			// "no status to copy" UX even though the page is full of data.
			// Build it ourselves from the same sections the UI renders.
			$status_text = $this->build_status_text( $sections );

			return $this->success(
				array(
					'sections'    => $sections,
					'status_text' => $status_text,
				) 
			);
		} catch ( \Throwable $e ) {
			return $this->error( $e->getMessage(), 500 );
		}
	}

	/**
	 * Get error logs.
	 *
	 * Reads CTX Feed's log files directly from the filesystem — the V8 per-feed
	 * logs under `uploads/woo-feed/logs/` plus any legacy `uploads/wc-logs/
	 * woo-feed-*.log` — and returns the most recent, capped for payload size.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_logs( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		// V8 writes per-feed logs to the filesystem; read them directly.
		$logs = $this->read_logs_from_filesystem();

		if ( '' === $logs ) {
			$logs = __( 'No logs available.', 'woo-feed' );
		}

		return $this->success(
			array(
				'logs' => $logs,
			) 
		);
	}

	/**
	 * Concatenate the most recent CTX Feed log files for display.
	 *
	 * Reads the V8 per-feed logs under `uploads/woo-feed/logs/*.log` plus any
	 * legacy `uploads/wc-logs/woo-feed-*.log`. Caps the total at ~256KB so a
	 * runaway log doesn't blow up the response payload. Returns an empty string
	 * when nothing is found — the caller substitutes a friendly message.
	 *
	 * @since 8.0.0
	 *
	 * @return string Concatenated log content (latest files first).
	 */
	private function read_logs_from_filesystem(): string {
		$upload = wp_get_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$base  = trailingslashit( $upload['basedir'] );
		$files = array();

		// V8 per-feed logs (woo-feed/logs/{slug}.log — written by FeedLogger).
		$v8_dir = $base . 'woo-feed/logs';
		if ( is_dir( $v8_dir ) && is_readable( $v8_dir ) ) {
			$files = array_merge( $files, (array) glob( $v8_dir . '/*.log' ) );
		}

		// Legacy WooCommerce logs (wc-logs/woo-feed-*.log).
		$wc_dir = $base . 'wc-logs';
		if ( is_dir( $wc_dir ) && is_readable( $wc_dir ) ) {
			$files = array_merge( $files, (array) glob( $wc_dir . '/woo-feed-*.log' ) );
		}

		$files = array_filter( $files );
		if ( empty( $files ) ) {
			return '';
		}

		// Newest first.
		usort( $files, static fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );

		$max_bytes = 256 * 1024; // 256 KB ceiling.
		$out       = '';

		foreach ( $files as $file ) {
			$header   = "\n=== " . basename( $file ) . " ===\n";
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads local plugin log files only, never a remote URL.
			if ( false === $contents ) {
				continue;
			}
			$out .= $header . $contents;
			if ( strlen( $out ) >= $max_bytes ) {
				$out  = substr( $out, 0, $max_bytes );
				$out .= "\n…(truncated)\n";
				break;
			}
		}

		return $out;
	}

	/**
	 * Clear status cache.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		// Delete the V5 status-page cache transient natively. The V5 global
		// woo_feed_delete_cache_data() was just delete_transient() with this
		// prefix, and it isn't loaded in V8 mode — inline it so the clear
		// keeps working after includes/ is removed.
		delete_transient( '__woo_feed_cache_woo_feed_status_page_info' );

		// Clear the full plugin cache natively. flush_plugin() removes both the
		// V8 (`ctxfeed_`) and legacy (`__woo_feed_cache_`) transients — covering
		// what V5's Cache::flush() / Helper::clear_cache_data() used to do.
		( new \CTXFeed\V8\Utility\Cache() )->flush_plugin();

		return $this->success(
			array(
				'message' => __( 'Status cache cleared successfully.', 'woo-feed' ),
			) 
		);
	}

	// =================================================================
	// Section Builders
	// =================================================================

	/**
	 * Build the CTX Feed diagnostics section natively (no V5 dependency).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function build_ctx_feed_section(): array {
		$items = array();

		// Plugin version.
		$plugin_version = defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : 'Unknown';
		$items[]        = $this->item( 'CTX Feed Version', 'success', $plugin_version );

		// Pro version if available.
		if ( defined( 'WOO_FEED_PRO_VERSION' ) ) {
			$items[] = $this->item( 'CTX Feed Pro', 'success', WOO_FEED_PRO_VERSION );
		}

		// Engine version.
		$items[] = $this->item( 'Engine', 'success', 'V8' );

		// Total published products.
		if ( function_exists( 'wp_count_posts' ) ) {
			$counts  = wp_count_posts( 'product' );
			$total   = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$items[] = $this->item( 'Total Products', 'success', (string) $total );
		}

		// Registered product types.
		if ( function_exists( 'wc_get_product_types' ) ) {
			$types   = array_keys( (array) wc_get_product_types() );
			$items[] = $this->item( 'Product Types', 'success', $types ? implode( ', ', $types ) : 'N/A' );
		}

		// Feed generation settings.
		$settings = get_option( 'woo_feed_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		// Batch size is auto-calculated per run by BatchCalculator from the
		// server's memory/time limits and adapted after each batch — there is
		// no fixed configured value to report.
		$items[]    = $this->item( 'Batch Sizing', 'success', 'Automatic (adaptive)' );
		$query_type = isset( $settings['products_query_type'] ) ? (string) $settings['products_query_type'] : 'default';
		$items[]    = $this->item( 'Product Query Type', 'success', $query_type );

		// Multi-language environment.
		$is_multilang = class_exists( 'SitePress' )
			|| function_exists( 'pll_languages_list' )
			|| class_exists( 'TRP_Translate_Press' );
		$items[]      = $this->item( 'Multi Language Site', 'success', $is_multilang ? 'Yes' : 'No' );

		// Multi-currency environment.
		$is_multicurrency = class_exists( 'WOOCS' )
			|| class_exists( 'WC_Aelia_CurrencySwitcher' )
			|| class_exists( 'WOOMULTI_CURRENCY_F' )
			|| class_exists( 'WOOMULTI_CURRENCY' )
			|| function_exists( 'alg_get_current_currency_code' );
		$items[]          = $this->item( 'Multi Currency Site', 'success', $is_multicurrency ? 'Yes' : 'No' );

		// Active page-caching plugin. Green when CTX Feed auto-excludes the
		// feed path for it; a warning (with the "how to exclude" guide in the
		// status banner) only when the feed URL cannot be excluded programmatically.
		$cache_plugin = $this->detect_cache_plugin();
		$items[]      = $this->cache_plugin_item( $cache_plugin['name'], $cache_plugin['auto_excluded'] );

		// WooCommerce default customer location.
		$customer_location = get_option( 'woocommerce_default_customer_address', 'base' );
		$items[]           = $this->item( 'Default Customer Location', 'success', (string) $customer_location );

		return $items;
	}

	/**
	 * Build the "Cache Plugin Installed" status row.
	 *
	 * Pure mapping (no plugin detection) so the three outcomes are unit
	 * testable: none → pass "None"; a plugin CTX Feed auto-excludes → pass
	 * "<name> (feed URL excluded)"; a plugin it cannot exclude → warning
	 * "<name>" (the frontend adds the "how to exclude" doc link banner).
	 *
	 * @since 8.0.0
	 *
	 * @param string $name          Detected caching plugin name, or ''.
	 * @param bool   $auto_excluded Whether CTX Feed excludes the feed path for it.
	 * @return array Status item { label, status, message }.
	 */
	private function cache_plugin_item( string $name, bool $auto_excluded ): array {
		if ( '' === $name ) {
			return $this->item( 'Cache Plugin Installed', 'success', 'None' );
		}

		if ( $auto_excluded ) {
			return $this->item(
				'Cache Plugin Installed',
				'success',
				sprintf(
					/* translators: %s: caching plugin name. */
					__( '%s (feed URL excluded)', 'woo-feed' ),
					$name
				)
			);
		}

		return $this->item( 'Cache Plugin Installed', 'warning', $name );
	}

	/**
	 * Detect a well-known page-caching plugin, if active.
	 *
	 * `auto_excluded` is true when the ExcludeCaching shim
	 * (compatibility/Shims/ExcludeCaching.php) registers a feed-path exclusion
	 * for the plugin. W3 Total Cache has no per-plugin exclusion hook (only the
	 * DONOTCACHEPAGE lever, which does not apply to physically served feed
	 * files), so it is flagged for manual exclusion.
	 *
	 * @since 8.0.0
	 * @return array{name:string,auto_excluded:bool}
	 */
	private function detect_cache_plugin(): array {
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			return array(
				'name'          => 'WP Rocket',
				'auto_excluded' => true,
			);
		}
		if ( defined( 'LSCWP_V' ) ) {
			return array(
				'name'          => 'LiteSpeed Cache',
				'auto_excluded' => true,
			);
		}
		if ( defined( 'WPFC_MAIN_PATH' ) ) {
			return array(
				'name'          => 'WP Fastest Cache',
				'auto_excluded' => true,
			);
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			return array(
				'name'          => 'WP Super Cache',
				'auto_excluded' => true,
			);
		}
		if ( class_exists( 'WP_Optimize' ) ) {
			return array(
				'name'          => 'WP-Optimize',
				'auto_excluded' => true,
			);
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			return array(
				'name'          => 'Cache Enabler',
				'auto_excluded' => true,
			);
		}
		if ( class_exists( 'SiteGround_Optimizer\Options\Options' ) ) {
			return array(
				'name'          => 'SiteGround Optimizer',
				'auto_excluded' => true,
			);
		}
		if ( defined( 'W3TC' ) ) {
			return array(
				'name'          => 'W3 Total Cache',
				'auto_excluded' => false,
			);
		}

		return array(
			'name'          => '',
			'auto_excluded' => true,
		);
	}

	/**
	 * Build WordPress section.
	 *
	 * @return array
	 */
	private function build_wordpress_section(): array {
		$items   = array();
		$items[] = $this->item( 'Home URL', 'success', home_url() );
		$items[] = $this->item( 'Site URL', 'success', site_url() );
		$items[] = $this->item( 'WP Version', 'success', get_bloginfo( 'version' ) );
		$items[] = $this->item( 'WP Multisite', 'success', is_multisite() ? 'Yes' : 'No' );
		$items[] = $this->item( 'WP Memory Limit', 'success', defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'N/A' );
		$items[] = $this->item( 'WP Debug Mode', defined( 'WP_DEBUG' ) && WP_DEBUG ? 'warning' : 'success', defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Enabled' : 'Disabled' );
		$items[] = $this->item( 'Background Scheduler', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'warning' : 'success', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Disabled' : 'Enabled' );
		$items[] = $this->item( 'Language', 'success', get_locale() );
		$items[] = $this->item( 'Timezone', 'success', wp_timezone_string() );

		// WooCommerce info.
		if ( function_exists( 'WC' ) ) {
			$items[] = $this->item( 'WooCommerce Version', 'success', WC()->version );
			$items[] = $this->item( 'WC Store Currency', 'success', get_woocommerce_currency() );
		}

		// Log directory.
		$upload_dir = wp_get_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/wc-logs/';
		$items[]    = $this->item( 'Log Directory', 'success', $log_dir );
		$items[]    = $this->item( 'Log Directory Writable', wp_is_writable( $log_dir ) ? 'success' : 'error', wp_is_writable( $log_dir ) ? 'Yes' : 'No' );

		return $items;
	}

	/**
	 * Build Server section.
	 *
	 * @return array
	 */
	private function build_server_section(): array {
		$items   = array();
		$items[] = $this->item( 'Server Info', 'success', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown' );
		$items[] = $this->item( 'PHP Version', 'success', PHP_VERSION );
		$items[] = $this->item( 'PHP Post Max Size', 'success', ini_get( 'post_max_size' ) );
		$items[] = $this->item( 'PHP Max Execution Time', 'success', ini_get( 'max_execution_time' ) . 's' );
		$items[] = $this->item( 'PHP Max Input Vars', 'success', ini_get( 'max_input_vars' ) );
		$items[] = $this->item( 'PHP Memory Limit', 'success', ini_get( 'memory_limit' ) );

		// cURL.
		if ( function_exists( 'curl_version' ) ) {
			$curl    = curl_version();
			$items[] = $this->item( 'cURL Version', 'success', $curl['version'] . ', ' . $curl['ssl_version'] );
		} else {
			$items[] = $this->item( 'cURL Version', 'error', 'Not available' );
		}

		$items[] = $this->item( 'Max Upload Size', 'success', size_format( wp_max_upload_size() ) );
		$items[] = $this->item( 'Default Timezone', 'success', date_default_timezone_get() );
		$items[] = $this->item( 'Fsockopen/cURL', 'success', ( function_exists( 'fsockopen' ) || function_exists( 'curl_init' ) ) ? 'Yes' : 'No' );
		$items[] = $this->item( 'SoapClient', 'success', class_exists( 'SoapClient' ) ? 'Yes' : 'No' );
		$items[] = $this->item( 'DOMDocument', 'success', class_exists( 'DOMDocument' ) ? 'Yes' : 'No' );
		$items[] = $this->item( 'GZip', 'success', is_callable( 'gzopen' ) ? 'Yes' : 'No' );
		$items[] = $this->item( 'Mbstring', 'success', extension_loaded( 'mbstring' ) ? 'Yes' : 'No' );

		// Remote connectivity.
		$remote_post = wp_remote_post(
			'https://www.paypal.com/cgi-bin/webscr',
			array(
				'timeout'   => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Connectivity diagnostic on an explicit admin action; mirrors the WooCommerce system-status check.
				'body'      => array( 'cmd' => '_notify-validate' ),
				'sslverify' => false,
			) 
		);

		if ( ! is_wp_error( $remote_post ) ) {
			$items[] = $this->item( 'Remote POST', 'success', (string) wp_remote_retrieve_response_code( $remote_post ) );
		} else {
			$items[] = $this->item( 'Remote POST', 'error', $remote_post->get_error_message() );
		}

		$remote_get = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() only exists on the VIP platform; failure is handled below.
			'https://woocommerce.com/wc-api/product-key-api?request=ping&network=0',
			array(
				'timeout'   => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Connectivity diagnostic on an explicit admin action; mirrors the WooCommerce system-status check.
				'sslverify' => false,
			) 
		);

		if ( ! is_wp_error( $remote_get ) ) {
			$items[] = $this->item( 'Remote GET', 'success', (string) wp_remote_retrieve_response_code( $remote_get ) );
		} else {
			$items[] = $this->item( 'Remote GET', 'error', $remote_get->get_error_message() );
		}

		return $items;
	}

	/**
	 * Build Database section.
	 *
	 * @return array
	 */
	private function build_database_section(): array {
		global $wpdb;

		$items   = array();
		$items[] = $this->item( 'Extension', 'success', $wpdb->use_mysqli ? 'mysqli' : 'mysql' );
		$items[] = $this->item( 'Server Version', 'success', $wpdb->db_version() );
		$items[] = $this->item( 'Client Version', 'success', $wpdb->use_mysqli && function_exists( 'mysqli_get_client_info' ) ? mysqli_get_client_info() : 'N/A' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_get_client_info -- Reports the client library version for diagnostics only; no query is run.
		$items[] = $this->item( 'Database Name', 'success', $wpdb->dbname );
		$items[] = $this->item( 'Database Host', 'success', $wpdb->dbhost );
		$items[] = $this->item( 'Database Charset', 'success', $wpdb->charset );
		$items[] = $this->item( 'Database Collation', 'success', $wpdb->collate ? $wpdb->collate : 'N/A' );
		$items[] = $this->item( 'Table Prefix', 'success', $wpdb->prefix );

		// Max allowed packet.
		$max_packet = $wpdb->get_var( 'SHOW VARIABLES LIKE "max_allowed_packet"', 1 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW VARIABLES has no WP API; read once for the System Status report, admin-only.
		$items[]    = $this->item( 'Max Allowed Packet', 'success', $max_packet ? size_format( $max_packet ) : 'N/A' );

		return $items;
	}

	/**
	 * Build Active Plugins section.
	 *
	 * @return array
	 */
	private function build_plugins_section(): array {
		$items          = array();
		$active_plugins = get_option( 'active_plugins', array() );

		// Ensure get_plugins() is available (not loaded by default on frontend).
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin  = $all_plugins[ $plugin_file ];
			$name    = $plugin['Name'];
			$author  = ! empty( $plugin['AuthorName'] ) ? ' (' . $plugin['AuthorName'] . ')' : '';
			$version = $plugin['Version'];

			$items[] = $this->item( $name . $author, 'success', $version );
		}

		return $items;
	}

	/**
	 * Build Theme section.
	 *
	 * @return array
	 */
	private function build_theme_section(): array {
		$theme   = wp_get_theme();
		$items   = array();
		$items[] = $this->item( 'Active Theme', 'success', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
		$items[] = $this->item( 'Theme Author', 'success', $theme->get( 'Author' ) );

		if ( $theme->parent() ) {
			$parent  = $theme->parent();
			$items[] = $this->item( 'Parent Theme', 'success', $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) );
		}

		return $items;
	}

	/**
	 * Build File System Permission section.
	 *
	 * @return array
	 */
	private function build_filesystem_section(): array {
		$items = array();
		$dirs  = array(
			'WordPress Root' => ABSPATH,
			'wp-content'     => WP_CONTENT_DIR,
			'Uploads'        => wp_get_upload_dir()['basedir'],
			'Plugins'        => WP_PLUGIN_DIR,
			'Themes'         => get_theme_root(),
		);

		// Feed output directory.
		$upload_dir                     = wp_get_upload_dir();
		$feed_dir                       = $upload_dir['basedir'] . '/woo-feed';
		$dirs['Feed Output (woo-feed)'] = $feed_dir;

		foreach ( $dirs as $label => $dir ) {
			$writable = file_exists( $dir ) && wp_is_writable( $dir );
			$items[]  = $this->item( $label, $writable ? 'success' : 'error', $writable ? 'Writable' : 'Not Writable' );
		}

		return $items;
	}

	// =================================================================
	// Helpers
	// =================================================================

	/**
	 * Build a plain-text representation of every status section.
	 *
	 * Mirrors the visual order of the React `StatusDisclosures` component:
	 * each section becomes a header line followed by `label: message`
	 * pairs, with HTML stripped from the message body so the result
	 * pastes cleanly into a support ticket. Used by the frontend
	 * "Copy All" button via `data.status_text`.
	 *
	 * @since 8.0.0
	 *
	 * @param array<string,array<int,array{label:string,status:string,message:string}>> $sections Section -> items.
	 * @return string Formatted text.
	 */
	private function build_status_text( array $sections ): string {
		$titles = array(
			'ctx_feed'   => 'CTX Feed',
			'wordpress'  => 'WordPress',
			'server'     => 'Server',
			'database'   => 'Database',
			'plugins'    => 'Active Plugins',
			'theme'      => 'Theme',
			'filesystem' => 'File System Permission',
		);

		$out = '';

		foreach ( $sections as $key => $items ) {
			if ( empty( $items ) || ! is_array( $items ) ) {
				continue;
			}

			$title = isset( $titles[ $key ] ) ? $titles[ $key ] : (string) $key;
			$out  .= "=== {$title} ===\n";

			foreach ( $items as $item ) {
				$label   = isset( $item['label'] ) ? (string) $item['label'] : '';
				$message = isset( $item['message'] ) ? wp_strip_all_tags( (string) $item['message'] ) : '';
				$out    .= $label . ': ' . $message . "\n";
			}

			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Build a single status item.
	 *
	 * @param string $label   Display label.
	 * @param string $status  'success', 'warning', or 'error'.
	 * @param string $message Value/message.
	 * @return array
	 */
	private function item( string $label, string $status, string $message ): array {
		return array(
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}
}
