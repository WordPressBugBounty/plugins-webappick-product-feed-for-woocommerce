<?php
/**
 * SupportEndpoint — REST API for the Contact Support and Claim Discount
 * modals in the admin UI.
 *
 * Both routes email support@webappick.com via wp_mail() (owner decision,
 * 2026-07-28). The contact route appends a compact system report and the
 * store's feed list so support can act without a follow-up round-trip.
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
 * Support REST endpoint.
 *
 * @since 8.0.0
 */
class SupportEndpoint extends RestController {

	/**
	 * Register the support routes.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/support/contact',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_contact' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'name'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'feed'    => array(
						'required'          => false,
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/discount',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_discount_claim' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'handle' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);
	}

	/**
	 * Recipient for both support mails.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	private function recipient(): string {
		/**
		 * Filter the support email recipient.
		 *
		 * @since 8.0.0
		 *
		 * @param string $email Recipient address.
		 */
		return apply_filters( 'ctxfeed_support_email', 'support@webappick.com' );
	}

	/**
	 * Send a support message with the system report and feed list attached.
	 *
	 * POST /ctxfeed/v8/support/contact
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function send_contact( \WP_REST_Request $request ): \WP_REST_Response {
		$name    = $request->get_param( 'name' );
		$email   = $request->get_param( 'email' );
		$message = $request->get_param( 'message' );
		$feed    = (string) $request->get_param( 'feed' );

		if ( ! is_email( $email ) ) {
			return $this->error( __( 'Please enter a valid email address.', 'woo-feed' ), 400 );
		}

		if ( '' === trim( (string) $message ) ) {
			return $this->error( __( 'Please enter a message.', 'woo-feed' ), 400 );
		}

		// Keep parity with the UI's 2000-character limit.
		$message = mb_substr( $message, 0, 2000 );

		// The user's message first, then the diagnostics support needs to act
		// without a follow-up: the system status, and — when the user picked a
		// feed (optional) — that feed's log.
		$body  = "From: {$name} <{$email}>\n";
		$body .= 'Site: ' . home_url() . "\n\n";
		$body .= $message . "\n\n";
		$body .= "--- System status ---\n" . $this->system_report() . "\n\n";
		$body .= "--- Feed log ---\n" . $this->feed_log( $feed );

		// BUG-0064: attach the ACTUAL generated feed file for the selected feed
		// (none when the optional feed picker is left unset), plus any files the
		// user attached manually in the form. wp_mail takes real file paths.
		$attachments = $this->feed_files( $feed );

		$manual      = $this->manual_attachments( $request );
		$attachments = array_merge( $attachments, $manual );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Single transactional support email triggered by an explicit admin action, never bulk.
		$sent = wp_mail(
			$this->recipient(),
			sprintf( 'CTX Feed support — %s', $name ),
			$body,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				sprintf( 'Reply-To: %s <%s>', $name, $email ),
			),
			$attachments
		);

		// Clean up the temp copies of the manual uploads, if any.
		foreach ( $manual as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		if ( ! $sent ) {
			return $this->error(
				__( 'Your message could not be sent — the site failed to send email. Please email support@webappick.com directly.', 'woo-feed' ),
				500
			);
		}

		return $this->success(
			array(
				'message' => __( 'Message sent. Support replies within one business day.', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Send a review-discount claim.
	 *
	 * POST /ctxfeed/v8/support/discount
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function send_discount_claim( \WP_REST_Request $request ): \WP_REST_Response {
		$handle = trim( (string) $request->get_param( 'handle' ) );
		$email  = trim( (string) $request->get_param( 'email' ) );

		if ( '' === $handle ) {
			return $this->error( __( 'Please enter your WordPress.org handle or profile URL.', 'woo-feed' ), 400 );
		}

		if ( ! is_email( $email ) ) {
			return $this->error( __( 'Please enter a valid email address.', 'woo-feed' ), 400 );
		}

		$handle = mb_substr( $handle, 0, 200 );

		$body  = "Review discount claim (10% off, single-use)\n\n";
		$body .= "WordPress.org handle / profile: {$handle}\n";
		$body .= "Claimant email: {$email}\n";
		$body .= 'Site: ' . home_url() . "\n";
		$body .= 'Admin email: ' . get_option( 'admin_email' ) . "\n";
		$body .= 'Plugin version: ' . ( defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : 'unknown' ) . "\n";

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Single transactional support email triggered by an explicit admin action, never bulk.
		$sent = wp_mail(
			$this->recipient(),
			'CTX Feed review discount claim',
			$body,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				sprintf( 'Reply-To: %s', $email ),
			)
		);

		if ( ! $sent ) {
			return $this->error(
				__( 'Your claim could not be sent — the site failed to send email. Please email support@webappick.com directly.', 'woo-feed' ),
				500
			);
		}

		return $this->success(
			array(
				'message' => __( 'Claim sent — we will email your coupon after checking the review.', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Compact environment summary for the support mail.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	private function system_report(): string {
		global $wp_version;

		$lines = array(
			'Plugin: ' . ( defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : 'unknown' ),
			'Pro: ' . ( defined( 'WOO_FEED_PRO_VERSION' ) ? WOO_FEED_PRO_VERSION : 'not installed' ),
			'WordPress: ' . $wp_version,
			'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'not active' ),
			'PHP: ' . PHP_VERSION,
			'Multisite: ' . ( is_multisite() ? 'yes' : 'no' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Read the feed log(s) for the support bundle.
	 *
	 * Empty means the optional feed picker was left unset — no log is read.
	 * `all` concatenates every per-feed log; a specific feed id (the same value
	 * the Manage Feeds "Download log" action uses) reads that feed's
	 * `{slug}.log` (or a legacy `{slug}-*.log`). Capped so a runaway log can't
	 * bloat the email.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed '', 'all', or a feed id.
	 * @return string Log content, or a hint when there is none.
	 */
	private function feed_log( string $feed ): string {
		if ( '' === $feed ) {
			return '(no feed selected)';
		}

		$upload = wp_get_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '(logs directory unavailable)';
		}

		$log_dir = trailingslashit( $upload['basedir'] ) . 'woo-feed/logs/';
		if ( ! is_dir( $log_dir ) ) {
			return '(no logs yet — enable debug logging in Settings, then regenerate the feed)';
		}

		if ( 'all' === $feed ) {
			$files = (array) glob( $log_dir . '*.log' );
		} else {
			$slug = $this->feed_slug( $feed );
			if ( '' === $slug ) {
				return '(feed not found)';
			}
			$file  = $log_dir . sanitize_file_name( $slug ) . '.log';
			$files = file_exists( $file ) ? array( $file ) : (array) glob( $log_dir . $slug . '-*.log' );
		}

		$files = array_values( array_filter( (array) $files, 'file_exists' ) );
		if ( empty( $files ) ) {
			return '(no log for this feed — enable debug logging in Settings, then regenerate it)';
		}

		$max = 200 * 1024; // 200KB cap across all included logs.
		$out = '';

		foreach ( $files as $file ) {
			$out .= "\n### " . basename( (string) $file ) . " ###\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads the plugin's own log file for the on-demand support bundle.
			$out .= (string) file_get_contents( (string) $file );

			if ( strlen( $out ) >= $max ) {
				$out = substr( $out, 0, $max ) . "\n… (truncated)";
				break;
			}
		}

		return trim( $out );
	}

	/**
	 * Resolve a feed id (numeric option_id or wf_feed_ slug) to its log slug.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed Feed id.
	 * @return string Log slug, or '' when the feed is not found.
	 */
	private function feed_slug( string $feed ): string {
		global $wpdb;

		if ( is_numeric( $feed ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feed configs live in wp_options under wf_feed_ (shared with V5, no migration); get_option cannot enumerate by prefix and these rows are non-autoloaded. Admin-only, on demand, bound via prepare().
			$option_name = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", absint( $feed ) )
			);
		} else {
			$candidate = 0 === strpos( $feed, 'wf_feed_' ) ? $feed : 'wf_feed_' . $feed;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See note above; verifies the wf_feed_ option exists before deriving its log slug.
			$option_name = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $candidate )
			);
		}

		return $option_name ? str_replace( 'wf_feed_', '', (string) $option_name ) : '';
	}

	/**
	 * Resolve the generated feed FILE path(s) for the selected feed (BUG-0064).
	 *
	 * Empty (the optional picker left unset) → no file. A single feed → its one
	 * output file; 'all' → every feed's file, capped by a total-size budget so
	 * the email can't balloon. The path mirrors
	 * Utility\Filesystem::get_feed_path(): woo-feed/{provider}/{ext}/{slug}.{ext}.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed '', 'all', or a feed id.
	 * @return string[] Absolute paths to existing feed files.
	 */
	private function feed_files( string $feed ): array {
		if ( '' === $feed ) {
			return array();
		}

		$upload = wp_get_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return array();
		}
		$base = trailingslashit( $upload['basedir'] ) . 'woo-feed/';

		$slugs = array();
		if ( 'all' === $feed ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wf_feed_ configs are non-autoloaded options with no enumerable get_option API; admin-only, on demand.
			$names = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wf_feed_%'" );
			foreach ( $names as $name ) {
				$slugs[] = str_replace( 'wf_feed_', '', (string) $name );
			}
		} else {
			$slug = $this->feed_slug( $feed );
			if ( '' === $slug ) {
				return array();
			}
			$slugs[] = $slug;
		}

		$files     = array();
		$total     = 0;
		$max_total = 15 * 1024 * 1024; // 15 MB across all attached feed files.

		foreach ( $slugs as $slug ) {
			$config   = get_option( 'wf_feed_' . $slug );
			$rules    = ( is_array( $config ) && isset( $config['feedrules'] ) && is_array( $config['feedrules'] ) ) ? $config['feedrules'] : array();
			$provider = isset( $rules['provider'] ) ? sanitize_file_name( (string) $rules['provider'] ) : '';
			$ext      = isset( $rules['feedType'] ) ? strtolower( sanitize_file_name( (string) $rules['feedType'] ) ) : 'xml';

			if ( '' === $provider ) {
				continue;
			}

			$path = $base . $provider . '/' . $ext . '/' . sanitize_file_name( $slug ) . '.' . $ext;
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$size = (int) filesize( $path );
			if ( $total + $size > $max_total ) {
				continue; // Skip files that would blow the attachment budget.
			}

			$files[] = $path;
			$total  += $size;
		}

		return $files;
	}

	/**
	 * Validate and stage the user's manual file attachments, if any (BUG-0064).
	 *
	 * Accepts one file (legacy `attachment`) or several (`attachment[]`). Each
	 * valid upload is moved to a temp file that preserves its original name
	 * (wp_mail names an attachment from its path basename); the caller deletes
	 * them after sending. Individual files are skipped when errored, empty, or
	 * over 10 MB; the set is bounded by a max count and a total-size budget so
	 * the email can't balloon.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string[] Temp file paths for the staged uploads (possibly empty).
	 */
	private function manual_attachments( \WP_REST_Request $request ): array {
		if ( ! method_exists( $request, 'get_file_params' ) ) {
			return array();
		}

		$params = $request->get_file_params();
		if ( empty( $params['attachment'] ) || ! is_array( $params['attachment'] ) ) {
			return array();
		}

		$files = $this->normalize_files( $params['attachment'] );

		/**
		 * Filter the maximum number of manual attachments accepted per message.
		 *
		 * @since 8.0.0
		 *
		 * @param int $max Maximum file count.
		 */
		$max_count = max( 1, (int) apply_filters( 'ctxfeed_support_max_attachments', 5 ) );
		$max_bytes = 10 * 1024 * 1024; // 10 MB per file.
		$max_total = 25 * 1024 * 1024; // 25 MB across all manual files.

		$staged = array();
		$total  = 0;

		foreach ( $files as $file ) {
			if ( count( $staged ) >= $max_count ) {
				break;
			}

			$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
			$size  = isset( $file['size'] ) ? (int) $file['size'] : 0;
			$tmp   = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			$name  = isset( $file['name'] ) ? (string) $file['name'] : '';

			if ( UPLOAD_ERR_OK !== $error || $size <= 0 || $size > $max_bytes || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				continue;
			}
			if ( $total + $size > $max_total ) {
				continue; // Skip files that would blow the attachment budget.
			}

			$safe_name = sanitize_file_name( $name );
			if ( '' === $safe_name ) {
				$safe_name = 'attachment';
			}

			$dest = trailingslashit( get_temp_dir() ) . 'ctxfeed-support-' . wp_generate_password( 8, false ) . '-' . $safe_name;
			if ( ! move_uploaded_file( $tmp, $dest ) ) {
				continue;
			}

			$staged[] = $dest;
			$total   += $size;
		}

		return $staged;
	}

	/**
	 * Flatten a $_FILES['attachment'] field into a list of per-file arrays.
	 *
	 * PHP represents `attachment[]` (multiple) as parallel arrays keyed by
	 * name/tmp_name/size/error, and a single `attachment` as one file array.
	 * Normalize both to a list of `{name, tmp_name, size, error}` arrays.
	 *
	 * @since 8.0.0
	 *
	 * @param array $field The $_FILES['attachment'] entry.
	 * @return array<int,array> One entry per uploaded file.
	 */
	private function normalize_files( array $field ): array {
		// Multi-file shape: name/tmp_name/size/error are parallel arrays.
		if ( isset( $field['name'] ) && is_array( $field['name'] ) ) {
			$out   = array();
			$count = count( $field['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$out[] = array(
					'name'     => isset( $field['name'][ $i ] ) ? $field['name'][ $i ] : '',
					'tmp_name' => isset( $field['tmp_name'][ $i ] ) ? $field['tmp_name'][ $i ] : '',
					'size'     => isset( $field['size'][ $i ] ) ? $field['size'][ $i ] : 0,
					'error'    => isset( $field['error'][ $i ] ) ? $field['error'][ $i ] : UPLOAD_ERR_NO_FILE,
				);
			}
			return $out;
		}

		// Single-file shape: the field itself is one file array.
		if ( isset( $field['name'] ) ) {
			return array( $field );
		}

		return array();
	}
}
