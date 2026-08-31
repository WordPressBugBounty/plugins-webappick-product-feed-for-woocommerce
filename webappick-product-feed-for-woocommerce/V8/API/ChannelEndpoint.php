<?php
/**
 * ChannelEndpoint — REST API for available channels, configs, and merchant taxonomies.
 *
 * Lists channels, attribute requirements, supported formats from the ChannelRegistry,
 * and serves merchant product category taxonomies (Google Shopping, Facebook Catalog)
 * used for category mapping in the admin UI.
 *
 * Endpoints:
 *   GET  /ctxfeed/v8/channels                          — List available channels.
 *   GET  /ctxfeed/v8/channels/{id}                     — Get a single channel's details.
 *   GET  /ctxfeed/v8/channels/taxonomies               — Get merchant taxonomy list.
 *   POST /ctxfeed/v8/channels/taxonomies/update        — Download & update taxonomy file.
 *   GET  /ctxfeed/v8/channels/taxonomies/country-codes — Get supported country codes.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-3.1
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\Channel\MerchantAttributes;
use CTXFeed\V8\Channel\TemplateDefaults;
use CTXFeed\V8\Channel\TemplateInfo;
use CTXFeed\V8\Template\TemplateLocator;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel REST endpoint.
 *
 * @since 8.0.0
 */
class ChannelEndpoint extends RestController {

	/**
	 * Supported merchants and their remote taxonomy URLs.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private const MERCHANT_URLS = array(
		'google'   => 'https://www.google.com/basepages/producttype/',
		'facebook' => 'https://www.facebook.com/products/categories/',
	);

	/**
	 * Register channel routes.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-3.1
	 *
	 * @return void
	 */
	public function register_routes(): void {

		// List all available channels.
		register_rest_route(
			$this->namespace,
			'/channels',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_channels' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Merchant taxonomy list — must be registered BEFORE the {id} route
		// to avoid WordPress matching "taxonomies" as a channel ID.
		register_rest_route(
			$this->namespace,
			'/channels/taxonomies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_merchant_taxonomy' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'merchant'     => array(
						'default'           => 'google',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'google', 'facebook' ), true );
						},
					),
					'country_code' => array(
						'default'           => 'en-US',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) 
		);

		// Download & update merchant taxonomy file from remote source.
		register_rest_route(
			$this->namespace,
			'/channels/taxonomies/update',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_merchant_taxonomy' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'merchant'     => array(
						'default'           => 'google',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'google', 'facebook' ), true );
						},
					),
					'country_code' => array(
						'default'           => 'en-US',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'with_id'      => array(
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			) 
		);

		// FREE: refresh the active Google product taxonomy from a validated
		// Google URL. Unlike the Pro /taxonomies/update route above (which
		// builds the URL server-side from merchant/country and writes into the
		// plugin dir), this accepts an explicit user-supplied URL — validated
		// to be a Google taxonomy URL — and writes to uploads so the refreshed
		// list survives plugin updates. Available in free because category
		// mapping is a free feature.
		register_rest_route(
			$this->namespace,
			'/channels/taxonomies/google/update',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_google_taxonomy' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => static function ( $value ) {
							return self::is_valid_google_taxonomy_url( (string) $value );
						},
						'description'       => __( 'A Google product taxonomy URL, e.g. https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt', 'woo-feed' ),
					),
				),
			)
		);

		// Supported country codes for taxonomy download.
		register_rest_route(
			$this->namespace,
			'/channels/taxonomies/country-codes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_country_codes' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Merchant attributes for a given channel/provider template.
		register_rest_route(
			$this->namespace,
			'/channels/attributes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_merchant_attributes' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Provider/merchant template key (e.g., google, facebook).', 'woo-feed' ),
					),
				),
			) 
		);

		// Feed rules / template config for a provider.
		register_rest_route(
			$this->namespace,
			'/channels/feed-rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed_rules' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'template' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Template name (e.g., google, facebook, custom).', 'woo-feed' ),
					),
				),
			) 
		);

		// Batch fetch initial feed rules for multiple templates.
		register_rest_route(
			$this->namespace,
			'/channels/feed-rules/batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_initial_feed_rules' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Single channel details.
		register_rest_route(
			$this->namespace,
			'/channels/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_channel' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	// =====================================================================
	// Merchant attributes & feed rules — V8 replacement for V5 /drop_down/?type=mattributes
	// =====================================================================

	/**
	 * Get merchant-specific attributes for a provider.
	 *
	 * V8 equivalent of V5 DropDownOptions::mattributes() +
	 * MerchantAttributesFactory::get().
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_merchant_attributes( \WP_REST_Request $request ): \WP_REST_Response {
		$provider = $request->get_param( 'provider' );

		$attributes = MerchantAttributes::get( $provider );

		if ( false === $attributes ) {
			$attributes = array();
		}

		return $this->success(
			array(
				'provider'   => $provider,
				'attributes' => $attributes,
				'total'      => is_array( $attributes ) ? count( $attributes ) : 0,
			) 
		);
	}

	/**
	 * Get feed rules / template configuration for a provider.
	 *
	 * V8 equivalent of V5 /drop_down/feed_rules/?template=X
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_feed_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$template = $request->get_param( 'template' );

		$config = TemplateDefaults::get( $template );

		/**
		 * Filter feed rules for a template.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $config   Template configuration.
		 * @param string $template Template name.
		 */
		$config = apply_filters( 'ctxfeed_feed_rules', $config, $template );

		return $this->success(
			array(
				'template' => $template,
				'provider' => $template,
				'config'   => $config,
			) 
		);
	}

	/**
	 * Batch fetch initial feed rules for multiple templates.
	 *
	 * V8 equivalent of V5 POST /drop_down/initial_feed_rules
	 * Request body: JSON array of template names.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_initial_feed_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$templates = $request->get_json_params();

		if ( ! is_array( $templates ) || empty( $templates ) ) {
			return $this->error( __( 'Request body must be a JSON array of template names.', 'woo-feed' ), 400 );
		}

		$templates = array_map( 'sanitize_text_field', $templates );

		$configs = TemplateDefaults::get_multiple( $templates );

		return $this->success(
			array(
				'templates' => $templates,
				'configs'   => $configs,
			) 
		);
	}

	// =====================================================================
	// Channel list endpoints (TODO: wire to ChannelRegistry).
	// =====================================================================

	/**
	 * List available channels.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_channels( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		// TODO: Implement via ChannelRegistry.
		return $this->success( array() );
	}

	/**
	 * Get a single channel's details.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_channel( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		// TODO: Implement via ChannelRegistry.
		return $this->success( array() );
	}

	// =====================================================================
	// Merchant taxonomy endpoints.
	// =====================================================================

	/**
	 * Get merchant taxonomy list from local file.
	 *
	 * Reads the pre-downloaded taxonomy file for the given merchant.
	 * For Google: google_taxonomy.txt (default) or google_taxonomy_{code}.txt
	 * For Facebook: fb_taxonomy.txt
	 *
	 * V8 equivalent of V5's `/ctxfeed/v1/product_taxonomy?merchant=google&country_code=en-US`.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_merchant_taxonomy( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! FeatureGate::is_pro() ) {
			return $this->error( __( 'Merchant taxonomy requires Pro version.', 'woo-feed' ), 403 );
		}

		$merchant     = $request->get_param( 'merchant' );
		$country_code = $request->get_param( 'country_code' );

		$file_path = $this->get_local_file_path( $merchant, $country_code );

		if ( ! file_exists( $file_path ) ) {
			// Fall back to default taxonomy file.
			$file_path = $this->get_local_file_path( $merchant );
		}

		if ( ! file_exists( $file_path ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: merchant name */
					__( 'Taxonomy file not found for %s. Please update the taxonomy first.', 'woo-feed' ),
					$merchant
				),
				404
			);
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a local taxonomy file (path built by get_local_file_path()), never a remote URL.

		if ( false === $content ) {
			return $this->error( __( 'Failed to read taxonomy file.', 'woo-feed' ), 500 );
		}

		return $this->success(
			array(
				'merchant'     => $merchant,
				'country_code' => $country_code,
				'taxonomy'     => $content,
			) 
		);
	}

	/**
	 * Download and update merchant taxonomy file from remote source.
	 *
	 * Downloads the latest taxonomy from Google or Facebook and saves
	 * it to the local taxonomy templates folder.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_merchant_taxonomy( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! FeatureGate::is_pro() ) {
			return $this->error( __( 'Merchant taxonomy requires Pro version.', 'woo-feed' ), 403 );
		}

		$merchant     = $request->get_param( 'merchant' );
		$country_code = $request->get_param( 'country_code' );
		$with_id      = $request->get_param( 'with_id' );

		$url = $this->build_remote_url( $merchant, $country_code, $with_id );

		$response = wp_safe_remote_get( $url, array( 'timeout' => 30 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Downloads a multi-megabyte merchant taxonomy on an explicit admin action; the default 3s timeout would always fail.

		if ( is_wp_error( $response ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download taxonomy: %s', 'woo-feed' ),
					$response->get_error_message()
				),
				500
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $status_code ) {
			return $this->error(
				sprintf(
					/* translators: 1: URL, 2: status code */
					__( 'Remote taxonomy URL returned status %2$d: %1$s', 'woo-feed' ),
					$url,
					$status_code
				),
				502
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Save to local taxonomy folder.
		$save_path = $this->get_taxonomy_dir();
		$filename  = $this->get_save_filename( $merchant, $country_code );
		$filepath  = $save_path . $filename;

		// Ensure directory exists.
		if ( ! is_dir( $save_path ) ) {
			wp_mkdir_p( $save_path );
		}

		// Use WP Filesystem for writing.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$written = $wp_filesystem->put_contents( $filepath, $body, FS_CHMOD_FILE );

		if ( ! $written ) {
			return $this->error( __( 'Failed to save taxonomy file.', 'woo-feed' ), 500 );
		}

		return $this->success(
			array(
				'merchant'     => $merchant,
				'country_code' => $country_code,
				'filename'     => $filename,
				'source_url'   => $url,
				'size'         => strlen( $body ),
			)
		);
	}

	/**
	 * FREE: download the Google product taxonomy from a validated URL and
	 * store it as the active category-mapping list.
	 *
	 * The URL is validated (https + google.com host + the taxonomy file path)
	 * both in the route's validate_callback and again here, then fetched with
	 * wp_safe_remote_get (blocks internal/loopback hosts). The body is
	 * content-validated before it overwrites anything, so a transient error
	 * page can never replace a good list. The file lands in uploads (survives
	 * plugin updates) and the parsed-list cache is dropped so the mapping UI
	 * reflects the new taxonomy immediately.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_google_taxonomy( \WP_REST_Request $request ): \WP_REST_Response {
		$url = (string) $request->get_param( 'url' );

		// Defense in depth: re-assert on the final (sanitized) value, not just
		// in the route's validate_callback.
		if ( ! self::is_valid_google_taxonomy_url( $url ) ) {
			return $this->error( __( 'Please enter a valid Google product taxonomy URL.', 'woo-feed' ), 400 );
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 30 ) ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Downloads a multi-megabyte Google taxonomy on an explicit admin action; the default 3s timeout would always fail.

		if ( is_wp_error( $response ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download taxonomy: %s', 'woo-feed' ),
					$response->get_error_message()
				),
				502
			);
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->error( __( 'The taxonomy URL did not return a valid response.', 'woo-feed' ), 502 );
		}

		$body = wp_remote_retrieve_body( $response );

		// Never overwrite a good list with junk (HTML error page, truncated
		// response, wrong file). Passing this guarantees the reader parses rows.
		if ( ! self::is_valid_taxonomy_body( $body ) ) {
			return $this->error( __( 'The downloaded file does not look like a Google product taxonomy.', 'woo-feed' ), 422 );
		}

		// Write to uploads (survives plugin updates), NOT the plugin dir.
		$save_path = TemplateLocator::taxonomy_override_dir();
		$filepath  = TemplateLocator::google_override_file();

		if ( '' === $save_path || '' === $filepath ) {
			return $this->error( __( 'Could not resolve the uploads directory.', 'woo-feed' ), 500 );
		}

		if ( ! is_dir( $save_path ) ) {
			wp_mkdir_p( $save_path );
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( empty( $wp_filesystem ) || ! $wp_filesystem->put_contents( $filepath, $body, FS_CHMOD_FILE ) ) {
			return $this->error( __( 'Failed to save the taxonomy file. Check the uploads folder permissions.', 'woo-feed' ), 500 );
		}

		// The active list changed — drop the parsed-list cache so the mapping
		// UI serves the new taxonomy on the next request.
		CategoryMappingEndpoint::flush_google_taxonomy_cache();

		return $this->success(
			array(
				'version'    => $this->parse_taxonomy_version( $body ),
				'size'       => strlen( $body ),
				'updated_at' => current_time( 'mysql' ),
				'message'    => __( 'Google product taxonomy updated successfully.', 'woo-feed' ),
			)
		);
	}

	/**
	 * Validate that a URL is a Google product taxonomy file URL.
	 *
	 * Enforced identically on the client (modal) and here. Doubles as an SSRF
	 * allowlist: only https URLs on the exact google.com host, pointing at the
	 * `basepages/producttype/taxonomy[-with-ids].<locale>.txt` path, with no
	 * credentials, port, query, or fragment, are accepted.
	 *
	 * @since 8.0.0
	 *
	 * @param string $url Candidate URL.
	 * @return bool True when the URL is a valid Google taxonomy URL.
	 */
	public static function is_valid_google_taxonomy_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		// A taxonomy file URL carries no credentials, port, query, or fragment.
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] )
			|| ! empty( $parts['port'] ) || ! empty( $parts['query'] )
			|| ! empty( $parts['fragment'] ) ) {
			return false;
		}

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( 'google.com' !== $host && 'www.google.com' !== $host ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		return 1 === preg_match(
			'#^/basepages/producttype/taxonomy(-with-ids)?\.[a-z]{2,3}(-[A-Z]{2})?\.txt$#',
			$path
		);
	}

	/**
	 * Validate that a downloaded body actually looks like a Google taxonomy.
	 *
	 * Guards the overwrite: size bounds, a minimum line count, and a first
	 * real row shaped `<id> - <Category>` — exactly what the reader's
	 * explode('-', $line, 2) + absint() parse expects.
	 *
	 * @since 8.0.0
	 *
	 * @param string $body Downloaded file contents.
	 * @return bool True when the body is a plausible Google taxonomy file.
	 */
	public static function is_valid_taxonomy_body( string $body ): bool {
		$len = strlen( $body );
		if ( $len < 10240 || $len > 15728640 ) { // 10 KB .. 15 MB.
			return false;
		}

		$lines = preg_split( '/\R/', $body );
		if ( ! is_array( $lines ) || count( $lines ) < 3000 ) {
			return false;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue; // Skip blanks + the "# Google_Product_Taxonomy_Version" header.
			}

			// First real row must be id-prefixed, e.g. "1 - Animals & Pet Supplies".
			return 1 === preg_match( '/^\d+ - /', $line );
		}

		return false;
	}

	/**
	 * Extract the taxonomy version from the file's header comment.
	 *
	 * Google ships "# Google_Product_Taxonomy_Version: 2021-09-21" as line one.
	 *
	 * @since 8.0.0
	 *
	 * @param string $body Downloaded file contents.
	 * @return string Version string, or '' when not present.
	 */
	private function parse_taxonomy_version( string $body ): string {
		if ( preg_match( '/Taxonomy_Version:\s*([0-9A-Za-z._-]+)/i', $body, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Get supported country codes for taxonomy download.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_country_codes( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		/**
		 * Filter the list of supported country codes.
		 *
		 * @since 8.0.0
		 *
		 * @param array $codes Country code list.
		 */
		$codes = apply_filters( 'ctxfeed_country_codes', $this->get_default_country_codes() );

		return $this->success(
			array(
				'country_codes' => $codes,
				'total'         => count( $codes ),
			) 
		);
	}

	// =====================================================================
	// Private: Merchant taxonomy helpers.
	// =====================================================================

	/**
	 * Get the path to local taxonomy templates directory.
	 *
	 * @since 8.0.0
	 * @return string
	 */
	private function get_taxonomy_dir(): string {
		return TemplateLocator::taxonomy_dir();
	}

	/**
	 * Get the local file path for a merchant taxonomy.
	 *
	 * @since 8.0.0
	 *
	 * @param string $merchant     Merchant name (google|facebook).
	 * @param string $country_code Optional country code.
	 * @return string
	 */
	private function get_local_file_path( string $merchant, string $country_code = '' ): string {
		$dir = $this->get_taxonomy_dir();

		if ( 'facebook' === $merchant ) {
			if ( ! empty( $country_code ) ) {
				$file = $dir . 'facebook_taxonomy_' . sanitize_file_name( $country_code ) . '.txt';
				if ( file_exists( $file ) ) {
					return $file;
				}
			}
			return $dir . 'fb_taxonomy.txt';
		}

		// Google — try country-specific first.
		if ( ! empty( $country_code ) ) {
			$file = $dir . 'google_taxonomy_' . sanitize_file_name( $country_code ) . '.txt';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return $dir . 'google_taxonomy.txt';
	}

	/**
	 * Build the remote URL for taxonomy download.
	 *
	 * @since 8.0.0
	 *
	 * @param string $merchant     Merchant name.
	 * @param string $country_code Country code.
	 * @param bool   $with_id      Include taxonomy IDs (Google only).
	 * @return string
	 */
	private function build_remote_url( string $merchant, string $country_code, bool $with_id ): string {
		/**
		 * Filter merchant taxonomy base URLs.
		 *
		 * @since 8.0.0
		 *
		 * @param array $urls Merchant => URL mapping.
		 */
		$urls = apply_filters( 'ctxfeed_merchant_url', self::MERCHANT_URLS );

		$base_url = $urls[ $merchant ] ?? $urls['google'];

		// Resolve full country code from partial match.
		$resolved_code = $this->resolve_country_code( $country_code );

		if ( 'facebook' === $merchant ) {
			$fb_code = str_replace( '-', '_', $resolved_code );
			return $base_url . $fb_code . '.txt';
		}

		// Google.
		$prefix = $with_id ? 'taxonomy-with-ids.' : 'taxonomy.';
		return $base_url . $prefix . $resolved_code . '.txt';
	}

	/**
	 * Get the filename for saving a downloaded taxonomy.
	 *
	 * @since 8.0.0
	 *
	 * @param string $merchant     Merchant name.
	 * @param string $country_code Country code.
	 * @return string
	 */
	private function get_save_filename( string $merchant, string $country_code ): string {
		$safe_code = sanitize_file_name( $country_code );

		if ( 'facebook' === $merchant ) {
			return 'facebook_taxonomy_' . $safe_code . '.txt';
		}

		return 'google_taxonomy_' . $safe_code . '.txt';
	}

	/**
	 * Resolve a partial country code to a full code from the supported list.
	 *
	 * @since 8.0.0
	 *
	 * @param string $code Partial or full country code.
	 * @return string Resolved country code (falls back to input if no match).
	 */
	private function resolve_country_code( string $code ): string {
		$codes = $this->get_default_country_codes();

		// Exact match.
		if ( in_array( $code, $codes, true ) ) {
			return $code;
		}

		// Partial match (e.g., 'en' matches 'en-US').
		foreach ( $codes as $full_code ) {
			if ( false !== strpos( $full_code, $code ) ) {
				return $full_code;
			}
		}

		return $code;
	}

	/**
	 * Default supported country codes.
	 *
	 * Same list as V5 ProductTaxonomy::get_country_codes().
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_default_country_codes(): array {
		return array(
			'af',
			'ak',
			'sq',
			'am',
			'ar',
			'hy',
			'rup-MK',
			'as',
			'az',
			'az-TR',
			'ba',
			'eu',
			'bel',
			'bn-BD',
			'bs-BA',
			'bg-BG',
			'my-MM',
			'ca',
			'bal',
			'zh-CN',
			'zh-HK',
			'zh-TW',
			'co',
			'hr',
			'cs-CZ',
			'da-DK',
			'dv',
			'nl-NL',
			'nl-BE',
			'en-US',
			'en-AU',
			'en-CA',
			'en-GB',
			'eo',
			'et',
			'fo',
			'fi',
			'fr-BE',
			'fr-FR',
			'fy',
			'fuc',
			'gl-ES',
			'ka-GE',
			'de-DE',
			'de-CH',
			'el',
			'gn',
			'gu-IN',
			'haw-US',
			'haz',
			'he-IL',
			'hi-IN',
			'hu-HU',
			'is-IS',
			'ido',
			'id-ID',
			'ga',
			'it-IT',
			'ja',
			'jv-ID',
			'kn',
			'kk',
			'km',
			'kin',
			'ky-KY',
			'ko-KR',
			'ckb',
			'lo',
			'lv',
			'li',
			'lin',
			'lt-LT',
			'lb-LU',
			'mk-MK',
			'mg-MG',
			'ms-MY',
			'ml-IN',
			'mr',
			'xmf',
			'mn',
			'me-ME',
			'ne-NP',
			'nb-NO',
			'nn-NO',
			'ory',
			'os',
			'ps',
			'fa-IR',
			'fa-AF',
			'pl-PL',
			'pt-BR',
			'pt-PT',
			'pa-IN',
			'rhg',
			'ro-RO',
			'ru-RU',
			'ru-UA',
			'rue',
			'sah',
			'sa-IN',
			'srd',
			'gd',
			'sr-RS',
			'sd-PK',
			'si-LK',
			'sk-SK',
			'sl-SI',
			'so-SO',
			'azb',
			'es-AR',
			'es-CL',
			'es-CO',
			'es-MX',
			'es-PE',
			'es-PR',
			'es-ES',
			'es-VE',
			'su-ID',
			'sw',
			'sv-SE',
			'gsw',
			'tl',
			'tg',
			'tzm',
			'ta-IN',
			'ta-LK',
			'tt-RU',
			'te',
			'th',
			'bo',
			'tir',
			'tr-TR',
			'tuk',
			'ug-CN',
			'uk',
			'ur',
			'uz-UZ',
			'vi',
			'wa',
			'cy',
			'yor',
		);
	}
}
