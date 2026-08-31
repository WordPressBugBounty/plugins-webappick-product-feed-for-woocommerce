<?php
/**
 * SFTPConnection — SSH2/SFTP upload client.
 *
 * Rewritten from the former libs/WebAppick/FTP/ SDK into V8 so the engine
 * owns its remote transport. The legacy libs/ tree carries no live consumers
 * and has been moved out of the plugin (03-source/ctx-old/) alongside V5.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility/FTP
 * @since      8.0.0
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

namespace CTXFeed\V8\Utility\FTP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes SFTP uploading over the PHP ssh2 extension.
 *
 * @since 8.0.0
 */
class SFTPConnection {

	/**
	 * Holds the SSH2 connection resource returned by ssh2_connect().
	 *
	 * @var resource|false
	 */
	private $connection;

	/**
	 * Holds the SFTP subsystem resource returned by ssh2_sftp().
	 *
	 * @var resource|false
	 */
	private $sftp;

	/**
	 * Open an SSH2 connection to the remote host.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $host    Server host name or IP.
	 * @param int         $port    SSH port. Default 22.
	 * @param string|bool $f_print Expected known-host fingerprint (MD5, hex). Pass false to skip the check.
	 *
	 * @throws \Exception When the ssh2 extension is missing or the host fingerprint does not match.
	 */
	public function __construct( $host, $port = 22, $f_print = false ) {
		if ( ! extension_loaded( 'ssh2' ) ) {
			/* translators: 1: server host, 2: server port */
			throw new \Exception( sprintf( esc_html__( 'Could not connect to %1$s:%2$s. SSH2 is not enabled on this server.', 'woo-feed' ), esc_attr( $host ), esc_attr( $port ) ) );
		}
		$this->connection = ssh2_connect( $host, $port );

		// Security: Man in the middle attack protection.
		if ( $f_print ) {
			$fingerprint = ssh2_fingerprint( $this->connection, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX );
			if ( $fingerprint !== $f_print ) {
				throw new \Exception( sprintf( 'Known host fingerprint "%s" does not match %s:%s fingerprint "%s". Possible man-in-the-middle attack ?', esc_attr( $f_print ), esc_attr( $host ), esc_attr( $port ), esc_attr( $fingerprint ) ) );
			}
		}
	}

	/**
	 * Authenticate with the remote host and open the SFTP subsystem.
	 *
	 * @since 8.0.0
	 *
	 * @param string $username SFTP user name.
	 * @param string $password SFTP password.
	 *
	 * @return void
	 * @throws \Exception When authentication fails or the SFTP subsystem cannot be initialised.
	 */
	public function login( $username, $password ) {
		if ( ! ssh2_auth_password( $this->connection, $username, $password ) ) {
			throw new \Exception( 'Could not authenticate with username ' . esc_attr( $username ) . ' and password ' . esc_attr( $password ) . ' .' );
		}

		$this->sftp = ssh2_sftp( $this->connection );
		if ( ! $this->sftp ) {
			throw new \Exception( 'Could not initialize SFTP subsystem.' );
		}
	}

	/**
	 * Upload a generated feed file to the remote SFTP server.
	 *
	 * @since 8.0.0
	 *
	 * @param string $local_file  Local file to upload.
	 * @param string $remote_file Remote file name.
	 * @param string $path        Must use trailing slash. Directory path to put the file on remote server.
	 *
	 * @return bool True once the file has been written to the remote server.
	 * @throws \Exception When the local file is unreadable, the remote path is invalid, or the transfer fails.
	 */
	public function upload_file( $local_file, $remote_file, $path ) {

		if ( ! file_exists( $local_file ) ) {
			throw new \Exception( "Local file does't exists.: " . esc_attr( $local_file ) . '.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local read only: $local_file is the feed file this plugin just generated under wp-content/uploads. It must be shipped byte-for-byte, and WP_Filesystem is not initialised on the Action Scheduler cron request that performs the upload.
		$data_to_send = file_get_contents( $local_file );
		if ( false === $data_to_send ) {
			throw new \Exception( 'Could not open local file: ' . esc_attr( $local_file ) . '.' );
		}

		$sftp = $this->sftp;
		if ( ! is_dir( "ssh2.sftp://$sftp$path" ) ) {
			// Missing / invalid remote directory — fall back to the server
			// root so the feed still uploads instead of failing. Product
			// decision: a mistyped or absent ftppath must never drop the feed.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records the path fallback so a merchant can see in the site log why their feed landed in root rather than the configured directory; the upload deliberately continues.
			error_log( 'CTX Feed SFTP: remote path "' . $path . '" not found — uploading to root "/" instead.' );
			$path = '/';
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- Probes the ssh2.sftp:// stream wrapper, not the local filesystem; WP_Filesystem has no equivalent for a remote SFTP path.
		if ( ! is_writeable( "ssh2.sftp://$sftp$path" ) ) {

			// Not throwing an exception because only upload permission is required.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic breadcrumb for merchant SFTP misconfiguration; the upload is deliberately still attempted, so there is no WP_Error to return and nothing else would record the cause.
			error_log( "CTX feed sftp upload issue: Can't write to remote. @" . $path );

		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opens the ssh2.sftp:// stream wrapper for the remote feed file; WP_Filesystem cannot address an SFTP stream resource.
		$stream = fopen( "ssh2.sftp://$sftp$path$remote_file", 'w' );
		if ( ! $stream ) {
			throw new \Exception( 'Could not open file: ' . esc_attr( $path ) . '.' );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing the feed file to the merchant's own remote server IS the product feature; the bytes must land unmodified.
		if ( false === fwrite( $stream, $data_to_send ) ) {
			throw new \Exception( 'Could not send data from file: ' . esc_attr( $local_file ) . '.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the ssh2.sftp:// stream opened above.
		fclose( $stream );
		return true;
	}

	/**
	 * Delete a file on the remote SFTP server.
	 *
	 * Used by the connection-test endpoint to clean up its probe file so
	 * nothing is left behind on the merchant server.
	 *
	 * @since 8.0.0
	 *
	 * @param string $remote_file Remote file name with full path.
	 *
	 * @return void
	 */
	public function delete_file( $remote_file ) {
		$sftp = $this->sftp;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Removes the probe file through the ssh2.sftp:// stream wrapper on the merchant's remote server; WP_Filesystem has no SFTP equivalent.
		unlink( "ssh2.sftp://$sftp$remote_file" );
	}
}
