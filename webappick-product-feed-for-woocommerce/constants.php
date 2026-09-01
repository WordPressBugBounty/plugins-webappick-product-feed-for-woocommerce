<?php
/**
 * Class: Woo Feed Constants
 *
 * @since 4.4.41
 */

if( ! class_exists("Woo_Feed_Constants") ) {
	class Woo_Feed_Constants {
		public $version;
		function __construct() {
			$this->version = "free";
		}

		static function defined_constants() {
			if ( defined( 'WOO_FEED_FREE_VERSION' ) )
				return;

			if ( ! defined( 'WOO_FEED_FREE_VERSION' ) ) {
				/**
				 * Plugin Version.
				 *
				 * Read from the plugin header (the `Version:` line in
				 * woo-feed.php) so there is ONE source of truth and the runtime
				 * version can never drift from the release. This runs before the
				 * V8 Bootstrap, so defining it here (from the header) is what the
				 * whole plugin then reports. Falls back to a literal only if the
				 * header cannot be read.
				 *
				 * @var string
				 * @since 3.1.6
				 */
				$woo_feed_free_version = '';
				if ( function_exists( 'get_file_data' ) && defined( 'WOO_FEED_FREE_FILE' ) && file_exists( WOO_FEED_FREE_FILE ) ) {
					$woo_feed_header       = get_file_data( WOO_FEED_FREE_FILE, array( 'Version' => 'Version' ) );
					$woo_feed_free_version = isset( $woo_feed_header['Version'] ) ? $woo_feed_header['Version'] : '';
				}

				define( 'WOO_FEED_FREE_VERSION', '' !== $woo_feed_free_version ? $woo_feed_free_version : '8.0.4' );

			}

			if ( ! defined( 'WOO_FEED_FREE_PATH' ) ) {
				/**
				 * Plugin Path with trailing slash
				 *
				 * @var string dirname( __FILE__ )
				 * * @since 3.1.6
				 */
				/** @define "WOO_FEED_FREE_PATH" "./" */ // phpcs:ignore
				define( 'WOO_FEED_FREE_PATH', plugin_dir_path( WOO_FEED_FREE_FILE ) );
			}

			if ( ! defined( 'WOO_FEED_PLUGIN_URL' ) ) {
				/**
				 * Plugin Directory URL
				 *
				 * @var string
				 * @since 3.1.37
				 */
				define( 'WOO_FEED_PLUGIN_URL', trailingslashit( plugin_dir_url( WOO_FEED_FREE_FILE ) ) );
			}
			if ( ! defined( 'WOO_FEED_MIN_PHP_VERSION' ) ) {
				/**
				 * Minimum PHP Version Supported
				 *
				 * @var string
				 * @since 3.1.41
				 */
				define( 'WOO_FEED_MIN_PHP_VERSION', '7.4' );
			}

			if ( ! defined( 'WOO_FEED_LOG_DIR' ) ) {
				$upload_dir = wp_get_upload_dir();
				/**
				 * Log Directory
				 *
				 * @var string
				 * @since 3.2.1
				 */
				/** @define "WOO_FEED_LOG_DIR" "./../../uploads/woo-feed/logs" */ // phpcs:ignore
				define( 'WOO_FEED_LOG_DIR', $upload_dir['basedir'] . '/woo-feed/logs/' );
			}

		}
	}
}
