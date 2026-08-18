<?php

namespace CTXFeed\V5\Common;


use CTXFeed\V5\Download\FileDownload;
use CTXFeed\V5\Utility\Config;
use CTXFeed\V5\Utility\CTX_WC_Log_Handler;
use \WP_Error;

/**
 * Class DownloadFiles
 *
 * @package    CTXFeed\V5\Common
 * @subpackage CTXFeed\V5\Common
 */
class DownloadFiles {

	public function __construct() {
		add_action( 'admin_post_wf_download_feed_log', [ $this, 'download_log' ], 10 );
		add_action( 'admin_post_wf_download_feed', [ $this, 'download_feed' ], 10 );
	}

	/**
	 * Validate that a file path is within an allowed directory.
	 *
	 * @param string $file_path The file path to validate.
	 * @param string $allowed_dir The allowed base directory.
	 * @return bool|string Returns the real path if valid, false otherwise.
	 */
	public static function validate_file_path( $file_path, $allowed_dir ) {
		$real_path = realpath( $file_path );
		$real_allowed_dir = realpath( $allowed_dir );

		// Check if realpath resolved successfully
		if ( false === $real_path || false === $real_allowed_dir ) {
			return false;
		}

		// Ensure file is within allowed directory
		if ( 0 !== stripos( $real_path, $real_allowed_dir ) ) {
			return false;
		}

		return $real_path;
	}

	/**
	 * Download Feed Log.
	 *
	 * @return void
	 *
	 * @throw RuntimeException
	 */
	public function download_log() {
		// Verify user has permission
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'woo-feed' ), 403 );
		}

		if (
			isset( $_REQUEST['feed'], $_REQUEST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'wpf-log-download' )
		) {
			$feed_name     = sanitize_text_field( wp_unslash( $_REQUEST['feed'] ) );
			$feed_name     = str_replace( 'wf_feed_', '', $feed_name );
			$log_file_path = CTX_WC_Log_Handler::get_log_file_path( $feed_name );

			// Validate path is within allowed log directory
			$validated_path = self::validate_file_path( $log_file_path, WOO_FEED_LOG_DIR );
			if ( false === $validated_path ) {
				wp_die( esc_html__( 'Invalid file path.', 'woo-feed' ), 403 );
			}
			$log_file_path = $validated_path;

			$file_name = sprintf(
				'%s-%s-%s.log',
				sanitize_title( $feed_name ),
				gmdate( 'Y-m-d', time() ),
				time()
			);

			if ( ! file_exists( $log_file_path ) ) {
				exit( esc_url(wp_redirect( add_query_arg( 'wpf_notice_code', 'log_file_not_found', admin_url( 'admin.php?page=webappick-manage-feeds' ) ) ) ));
			}

			$fileDownload = new FileDownload( fopen( $log_file_path, 'rb' ) );
			$fileDownload->sendDownload( $file_name );
		} else {
			exit( esc_url(wp_redirect( add_query_arg( 'wpf_notice_code', 'log_file_not_found', admin_url( 'admin.php?page=webappick-manage-feeds' ) ) ) ));
		}
	}

	/**
	 * Download feed.
	 *
	 * @return void
	 */
	public function download_feed() {
		// Verify user has permission
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to download feeds.', 'woo-feed' ), 403 );
		}

		if (
			isset( $_REQUEST['feed'], $_REQUEST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'wpf-download-feed' )
		) {
			$feed_name = sanitize_text_field( wp_unslash( $_REQUEST['feed'] ) );
			/* your file, somewhere opened with fopen() or tmpfile(), etc.. */
			$config = Factory::get_feed_info( $feed_name );

			$feed_path = $config->get_feed_path();
			$upload_dir = wp_get_upload_dir();
			$allowed_feed_dir = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed';

			// Validate path is within allowed feed directory
			$validated_path = self::validate_file_path( $feed_path, $allowed_feed_dir );
			if ( false === $validated_path ) {
				wp_die( esc_html__( 'Invalid file path.', 'woo-feed' ), 403 );
			}

			if ( ! file_exists( $validated_path ) ) {
				exit( esc_url(wp_redirect( add_query_arg( 'wpf_notice_code', 'feed_download_failed', admin_url( 'admin.php?page=webappick-manage-feeds' ) ) ) ));
			}

			$fileData     = fopen( $validated_path, 'rb' );
			$fileDownload = new FileDownload( $fileData );
			$fileDownload->sendDownload( $config->get_feed_file_name() );
		} else {
			exit( esc_url(wp_redirect( add_query_arg( 'wpf_notice_code', 'feed_download_failed', admin_url( 'admin.php?page=webappick-manage-feeds' ) ) ) ));
		}
	}

	/**
	 * @param $feed_name
	 *
	 * @return array|WP_Error
	 */
	public static function rest_download_feed( $feed_name ) {
		$feed_name = sanitize_text_field( wp_unslash( $feed_name ) );
		/* your file, somewhere opened with fopen() or tmpfile(), etc.. */
		$config = Factory::get_feed_info( $feed_name );

		$feed_path = $config->get_feed_path();
		$upload_dir = wp_get_upload_dir();
		$allowed_feed_dir = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed';

		// Validate path is within allowed feed directory
		$validated_path = self::validate_file_path( $feed_path, $allowed_feed_dir );
		if ( false === $validated_path ) {
			return new WP_Error( 'invalid_file_path', 'Invalid file path.' );
		}

		if ( ! file_exists( $validated_path ) ) {
			return new WP_Error( 'feed_file_not_found', 'Feed file: ' . $feed_name . ' does\'nt exists.' );
		}

		return ['path' => $validated_path, 'file_name' => $config->get_feed_file_name() ];

	}

	/**
	 * @param $feed_name
	 *
	 * @return array|WP_Error
	 */
	public static function rest_download_log( $feed_name ) {

		$feed_name     = sanitize_text_field( wp_unslash( $feed_name ) );
		$feed_name     = str_replace( 'wf_feed_', '', $feed_name );
		$log_file_path = CTX_WC_Log_Handler::get_log_file_path( $feed_name );

		// Validate path is within allowed log directory
		$validated_path = self::validate_file_path( $log_file_path, WOO_FEED_LOG_DIR );
		if ( false === $validated_path ) {
			return new WP_Error( 'invalid_file_path', 'Invalid file path.' );
		}
		$log_file_path = $validated_path;

		$file_name = sprintf(
			'%s-%s-%s.log',
			sanitize_title( $feed_name ),
			gmdate( 'Y-m-d', time() ),
			time()
		);

		if ( ! file_exists( $log_file_path ) ) {
			return new WP_Error( 'log_file_not_found', 'Feed file: ' . $feed_name . ' does\'nt have any log' );
		}

		return ['path' => $log_file_path, 'file_name' => $file_name ];
	}

	/**
	 * Rest Download config.
	 *
	 * @return bool|WP_Error
	 */
	public static function rest_download_config( $feed_name ) {
		$feed   = sanitize_text_field( wp_unslash( $feed_name ) );
		$feed   = str_replace( [ 'wf_feed_', 'wf_config' ], '', $feed );
		$config = Factory::get_feed_info( $feed );

		$file_name = sprintf(
			'%s-%s.wpf',
			sanitize_title( $config->get_feed_file_name() ),
			time()
		);
		$feed      = wp_json_encode( $config->get_feed_rules() );
		$meta      = wp_json_encode( [
			'version'   => WOO_FEED_FREE_VERSION,
			'file_name' => $file_name,
			'hash'      => md5( $feed ),
		] );
		$bin       = pack( 'VA*VA*', strlen( $meta ), $meta, strlen( $feed ), $feed );
		$feed_config      = gzdeflate( $bin, 9 );

		return $feed_config;


	}

}
