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
		if ( ! $this->connection ) {
			$last   = error_get_last();
			$detail = is_array( $last ) && ! empty( $last['message'] ) ? ' ' . $last['message'] : '';
			/* translators: 1: server host, 2: server port, 3: PHP's own error detail (may be empty). */
			throw new \Exception( sprintf( esc_html__( 'Could not connect to %1$s:%2$s.%3$s', 'woo-feed' ), esc_attr( $host ), esc_attr( $port ), esc_html( $detail ) ) );
		}

		// Security: Man in the middle attack protection.
		if ( $f_print ) {
			$fingerprint = $this->get_fingerprint();
			if ( '' !== $fingerprint && strtolower( $fingerprint ) !== strtolower( (string) $f_print ) ) {
				throw new \Exception( sprintf( 'The SFTP server at %1$s:%2$s presented a different identity (host key) than on earlier uploads, so the upload was stopped to protect your password. If you moved or reinstalled that server, open Edit feed → FTP / SFTP and click Save to accept the new key; otherwise contact your host.', esc_attr( $host ), esc_attr( $port ) ) );
			}
		}
	}

	/**
	 * The server's host-key fingerprint (MD5, hex), or '' when unavailable.
	 *
	 * @since 8.0.32
	 * @return string
	 */
	public function get_fingerprint(): string {
		if ( ! $this->connection || ! function_exists( 'ssh2_fingerprint' ) || ! defined( 'SSH2_FINGERPRINT_MD5' ) || ! defined( 'SSH2_FINGERPRINT_HEX' ) ) {
			return '';
		}

		return (string) ssh2_fingerprint( $this->connection, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX );
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
			// Never put the password in the message: it is written to the
			// feed log, the WooCommerce log and support bundles (CBT-708).
			throw new \Exception( 'Could not authenticate with username ' . esc_attr( $username ) . '. Check the username and password.' );
		}

		$this->sftp = ssh2_sftp( $this->connection );
		if ( ! $this->sftp ) {
			throw new \Exception( 'Could not initialize SFTP subsystem.' );
		}
	}

	/**
	 * Upload a file to the SFTP server under its FINAL name.
	 *
	 * Written straight to the target name, never a temporary name renamed
	 * afterwards (CBT-737): ingest servers such as Google Merchant Center's
	 * (partnerupload.google.com) process every file the moment it is closed,
	 * by its name, so a temporary ".name.part" file was picked up as an
	 * unknown feed and reported as an error. The transfer is still streamed
	 * in chunks (never the whole feed in memory) and verified: every byte
	 * written, the stream closed cleanly, and — when the server reports it —
	 * the remote size equals the local size.
	 *
	 * @param string        $local_file  Local file to upload.
	 * @param string        $remote_file Remote file name.
	 * @param string        $path        Must use trailing slash. Directory path to put the file on remote server.
	 * @param callable|null $on_progress Called as ( int $bytes_sent, int $bytes_total ) after every chunk. @since 8.0.34.
	 *
	 * @return bool True once the file has been written to the remote server.
	 * @throws \Exception When the local file is unreadable, the remote path is invalid, or the transfer fails.
	 */
	public function upload_file( $local_file, $remote_file, $path, $on_progress = null ) {

		if ( ! file_exists( $local_file ) ) {
			throw new \Exception( "Local file does't exists.: " . esc_attr( $local_file ) . '.' );
		}
		$local_size = (int) filesize( $local_file );

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

		$final_remote = $path . $remote_file;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local read of the feed file this plugin just generated; streamed, never loaded whole.
		$local = fopen( $local_file, 'rb' );
		if ( ! $local ) {
			throw new \Exception( 'Could not open local file: ' . esc_attr( $local_file ) . '.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opens the ssh2.sftp:// stream wrapper for the remote feed file; WP_Filesystem cannot address an SFTP stream resource.
		$stream = fopen( "ssh2.sftp://$sftp$final_remote", 'w' );
		if ( ! $stream ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the local handle opened above.
			fclose( $local );
			throw new \Exception( 'Could not open file: ' . esc_attr( $path ) . '.' );
		}

		$copied = self::copy_stream( $local, $stream, $local_size, $on_progress );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the local handle opened above.
		fclose( $local );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the ssh2.sftp:// stream; its result is the last chance to see a failed flush.
		$closed = fclose( $stream );

		if ( $copied !== $local_size || false === $closed ) {
			throw new \Exception( sprintf( 'Upload incomplete: %1$d of %2$d bytes reached the server (connection dropped or remote disk/quota full). The file on the server may be incomplete until the next successful upload.', absint( $copied ), absint( $local_size ) ) );
		}

		// Ingest servers may move the file away the moment it is closed; an
		// unknown size is not a failure, only a different one is.
		$remote_size = $this->remote_size( $final_remote );
		if ( null !== $remote_size && $remote_size !== $local_size ) {
			throw new \Exception( sprintf( 'Upload incomplete: the server holds %1$d of %2$d bytes. The file on the server may be incomplete until the next successful upload.', absint( $remote_size ), absint( $local_size ) ) );
		}

		return true;
	}

	/**
	 * Copy a local stream to a remote one in chunks, reporting progress.
	 *
	 * Handles short writes (an SFTP stream may accept part of a chunk) and
	 * stops on a write that makes no progress, so a dead connection cannot
	 * spin forever.
	 *
	 * @since 8.0.34
	 *
	 * @param resource      $from        Local read handle.
	 * @param resource      $to          Remote write handle.
	 * @param int           $total       Bytes expected (for the progress callback).
	 * @param callable|null $on_progress Called as ( int $bytes_sent, int $bytes_total ).
	 * @return int Bytes written.
	 */
	public static function copy_stream( $from, $to, int $total, $on_progress = null ): int {
		/**
		 * Filter the SFTP upload chunk size in bytes (default 1 MB).
		 *
		 * @since 8.0.34
		 *
		 * @param int $chunk Chunk size in bytes.
		 */
		$chunk = max( 8192, (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'ctxfeed_sftp_upload_chunk_bytes', 1048576 ) : 1048576 ) );
		$sent  = 0;

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Streams the feed from the local file to the ssh2.sftp:// stream wrapper; WP_Filesystem has no streaming API for a remote SFTP resource.
		while ( ! feof( $from ) ) {
			$buffer = fread( $from, $chunk );
			if ( false === $buffer ) {
				break;
			}
			$length = strlen( $buffer );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $to, 0 === $offset ? $buffer : substr( $buffer, $offset ) );
				if ( false === $written || 0 === $written ) {
					return $sent + $offset;
				}
				$offset += $written;
			}
			$sent += $length;
			if ( is_callable( $on_progress ) ) {
				call_user_func( $on_progress, $sent, $total );
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite

		return $sent;
	}

	/**
	 * Remote file size via SFTP stat, or null when unavailable.
	 *
	 * @param string $remote Remote path.
	 * @return int|null
	 */
	private function remote_size( string $remote ): ?int {
		if ( ! function_exists( 'ssh2_sftp_stat' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- A missing stat is a question, not an error; the null return is handled.
		$stat = @ssh2_sftp_stat( $this->sftp, $remote );

		return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : null;
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
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the probe file through the ssh2.sftp:// stream wrapper on the merchant's remote server; WP_Filesystem has no SFTP equivalent.
		unlink( "ssh2.sftp://$sftp$remote_file" );
	}
}
