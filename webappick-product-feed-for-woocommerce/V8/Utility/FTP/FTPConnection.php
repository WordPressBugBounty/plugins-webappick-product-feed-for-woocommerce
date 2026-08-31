<?php
/**
 * FTPConnection — plain-FTP upload client.
 *
 * Rewritten from the former libs/WebAppick/FTP/ SDK into V8 so the engine
 * owns its remote transport. The legacy libs/ tree carries no live consumers
 * and has been moved out of the plugin (03-source/ctx-old/) alongside V5.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility/FTP
 * @since      8.0.0
 */

namespace CTXFeed\V8\Utility\FTP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes FTP uploading.
 *
 * @since 8.0.0
 */
class FTPConnection {
	/**
	 * Holds The FTP Connection Resource
	 *
	 * @var resource
	 */
	private $connection_id;
	/**
	 * Login Check Flag
	 *
	 * @var bool
	 */
	private $login_ok = false;
	/**
	 * Message Array
	 *
	 * @var array
	 */
	private $message_array = array();
	/**
	 * The remote path the last upload actually wrote to (may differ from the
	 * requested path when a missing directory triggered the root fallback).
	 *
	 * @var string
	 */
	private $last_remote_path = '';

	/**
	 * FTPConnection constructor.
	 */
	public function __construct() {
	}

	/**
	 * Store Log Messages.
	 *
	 * @param string $message   message to add.
	 */
	private function log_message( $message ) {
		$this->message_array[] = $message;
	}

	/**
	 * Get Logs.
	 *
	 * @return array
	 */
	public function get_messages() {
		return $this->message_array;
	}

	/**
	 * Remote path the most recent upload_file() actually wrote to.
	 *
	 * Equals the requested target unless a missing directory triggered the
	 * root fallback, in which case it is "/{filename}". Lets the caller verify
	 * the file at the real location.
	 *
	 * @return string
	 */
	public function get_last_remote_path() {
		return $this->last_remote_path;
	}

	/**
	 * Connect to FTP Server
	 *
	 * @param string $server        Server host.
	 * @param string $ftp_user       FTP Username.
	 * @param string $ftp_password   FTP Password.
	 * @param bool   $is_passive     FTP Transfer Mode.
	 * @param int    $ftp_port       FTP Port.
	 * @return bool
	 */
	public function connect( $server, $ftp_user, $ftp_password, $is_passive = false, $ftp_port = 21 ) {

		// *** Set up basic connection
		$this->connection_id = ftp_connect( $server, $ftp_port );
		if ( ! $this->connection_id ) {
			$this->log_message( esc_html__( 'FTP connection has failed!', 'woo-feed' ) );
			/* translators: 1: ftp username, 2: server host, 3: server port */
			$this->log_message( sprintf( esc_html__( 'Attempted to connect to %1$s@%2$s:%3$s', 'woo-feed' ), $ftp_user, $server, $ftp_port ) );
			return false;
		}
		// *** Login with username and password
		$login_result = ftp_login( $this->connection_id, $ftp_user, $ftp_password );
		// *** Sets passive mode on/off (default off)
		ftp_pasv( $this->connection_id, $is_passive );
		// *** Check connection
		if ( ! $login_result ) {
			$this->log_message( esc_html__( 'FTP Login has failed!', 'woo-feed' ) );
			/* translators: 1: ftp username, 2: server host, 3: server port */
			$this->log_message( sprintf( esc_html__( 'Attempted to login %1$s@%2$s:%3$s', 'woo-feed' ), $ftp_user, $server, $ftp_port ) );
			return false;
		} else {
			/* translators: 1: ftp username, 2: server host, 3: server port */
			$this->log_message( sprintf( esc_html__( 'Connected to %1$s@%2$s:%3$s', 'woo-feed' ), $ftp_user, $server, $ftp_port ) );
			$this->login_ok = true;
			return true;
		}
	}

	/**
	 * Check if input is valid octal
	 *
	 * @param mixed $input  input data.
	 *
	 * @return bool
	 */
	private function is_octal( $input ) {
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Deliberate: decoct() always returns a string while $input arrives as either int 644 or string '0644' depending on how the feed's FTP settings were saved. A strict comparison would reject every integer permission mode and silently stop chmod'ing uploaded feeds.
		return decoct( octdec( $input ) ) == $input;
	}

	/**
	 * Give permission to file.
	 *
	 * @param int    $permissions          permission mode.
	 * @param string $remote_filename   remote file name with full path.
	 * @return bool
	 */
	public function chmod( $permissions, $remote_filename ) {
		if ( $this->is_octal( $permissions ) ) {
			$result = ftp_chmod( $this->connection_id, $permissions, $remote_filename );
			if ( $result ) {
				$this->log_message( esc_html__( 'File Permission Granted', 'woo-feed' ) );

				return true;
			} else {
				$this->log_message( esc_html__( 'File Permission Failed', 'woo-feed' ) );

				return false;
			}
		} else {
			/* translators: Permission Mode */
			$this->log_message( sprintf( esc_html__( '%s must be an octal number', 'woo-feed' ), $permissions ) );

			return false;
		}
	}

	/**
	 * Make Directory.
	 *
	 * @param string $directory     Directory name and path.
	 * @return bool
	 */
	public function make_dir( $directory ) {
		// *** If creating a directory is successful...
		if ( ftp_mkdir( $this->connection_id, $directory ) ) {
			/* translators: Permission Mode */
			$this->log_message( sprintf( esc_html__( 'Directory "%s" created successfully.', 'woo-feed' ), $directory ) );
			return true;

		} else {
			/* translators: Directory Path */
			$this->log_message( sprintf( esc_html__( 'Failed creating directory "%s".', 'woo-feed' ), $directory ) );
			return false;
		}
	}

	/**
	 * Delete a file on the FTP server.
	 *
	 * Used by the connection-test endpoint to clean up its probe file so
	 * nothing is left behind on the merchant server.
	 *
	 * @since 8.0.0
	 *
	 * @param string $remote_filename Remote file name with full path.
	 * @return bool
	 */
	public function delete_file( $remote_filename ) {
		if ( ! $this->connection_id || ! $this->login_ok ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- ftp_delete() emits a PHP warning when the probe file is already gone or the account lacks delete rights; that is an expected outcome of a connection test, not an error to surface. The boolean return is checked and logged instead.
		if ( @ftp_delete( $this->connection_id, $remote_filename ) ) {
			/* translators: %s: remote file path */
			$this->log_message( sprintf( esc_html__( 'Deleted "%s".', 'woo-feed' ), $remote_filename ) );
			return true;
		}

		/* translators: %s: remote file path */
		$this->log_message( sprintf( esc_html__( 'Failed deleting "%s".', 'woo-feed' ), $remote_filename ) );
		return false;
	}

	/**
	 * Upload files to FTP server
	 *
	 * @param string $file_from      file name and path that needs to be uploaded.
	 * @param string $file_to        file name and path where the to put the file.
	 *
	 * @return bool
	 */
	public function upload_file( $file_from, $file_to ) {

		// *** Set the transfer mode
		$ascii_array   = array( 'txt', 'csv', 'xml' );
		$get_extension = explode( '.', $file_from );
		$extension     = end( $get_extension );

		$mode = in_array( $extension, $ascii_array, true ) ? FTP_ASCII : FTP_BINARY;

		// *** Upload the file
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- ftp_put() emits a PHP warning ("Could not create file") when the remote directory is missing — the exact condition the root fallback below handles. The boolean return is checked and logged; suppressing the warning keeps a strict error handler (WP debug / test harness) from escalating it and aborting the upload before the retry runs.
		$upload                 = @ftp_put( $this->connection_id, $file_to, $file_from, $mode );
		$this->last_remote_path = $file_to;

		// Missing remote directory — fall back to the server root so the feed
		// still uploads instead of failing. Product decision: a mistyped or
		// absent ftppath must never drop the feed. Only retried when a
		// sub-directory was targeted, so a normal root upload is untouched.
		if ( ! $upload ) {
			$root_target = '/' . basename( $file_to );
			if ( $root_target !== $file_to ) {
				/* translators: 1: original remote path, 2: root fallback path */
				$this->log_message( sprintf( esc_html__( 'Remote path "%1$s" failed — retrying at root "%2$s".', 'woo-feed' ), $file_to, $root_target ) );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- Same rationale as the first ftp_put above: the boolean return drives success/failure, and the warning must not escalate.
				$upload = @ftp_put( $this->connection_id, $root_target, $file_from, $mode );
				if ( $upload ) {
					$this->last_remote_path = $root_target;
				}
			}
		}

		// *** Check upload status
		if ( ! $upload ) {
			$this->log_message( 'FTP upload has failed!' );
			return false;
		} else {
			/* translators: 1: file from, 2: file to */
			$this->log_message( sprintf( esc_html__( 'Uploaded "%1$s" as "%2$s"', 'woo-feed' ), $file_from, $this->last_remote_path ) );
			return true;
		}
	}
}
