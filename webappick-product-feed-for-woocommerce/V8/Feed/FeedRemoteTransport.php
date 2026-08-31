<?php
/**
 * FeedRemoteTransport — Uploads the generated feed file to FTP / SFTP.
 *
 * V5-compatible: reads `ftpenabled`, `ftporsftp`, `ftpmode`, `ftphost`,
 * `ftpport`, `ftpuser`, `ftppassword` (encrypted), `ftppath` from feedrules.
 *
 * Uses V8's own `CTXFeed\V8\Utility\FTP\FTPConnection` / `SFTPConnection`
 * classes (relocated from libs/WebAppick/FTP) so the upload behaviour is
 * identical to what 70K installs rely on, with no dependency on the legacy
 * libs/ tree.
 *
 * Called from FeedGenerator::finalize() after the feed file is closed.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-5.1, FEED-FRD-5.2, FEED-FRD-5.3
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Utility\Encryptor;
use CTXFeed\V8\Utility\FeedLogger;
use CTXFeed\V8\Utility\FTP\FTPConnection;
use CTXFeed\V8\Utility\FTP\SFTPConnection;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed file remote transport (FTP / SFTP upload).
 *
 * @since 8.0.0
 */
class FeedRemoteTransport {

	/**
	 * Optional per-feed logger (setter-injected).
	 *
	 * @since 8.0.0
	 * @var FeedLogger|null
	 */
	private $feed_logger;

	/**
	 * Set the per-feed logger.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedLogger $logger Per-feed buffered logger.
	 * @return void
	 */
	public function set_feed_logger( FeedLogger $logger ): void {
		$this->feed_logger = $logger;
	}

	/**
	 * Export feed file via FTP / SFTP.
	 *
	 * Reads V5-compatible feedrules keys. Early-returns when upload is
	 * disabled or configuration is incomplete. Uses the legacy V5 FTP
	 * connection classes for the actual transfer.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $feed_id  Feed slug identifier.
	 * @param string      $filepath Absolute path to the generated feed file.
	 * @param Config|null $config   Feed configuration object.
	 *
	 * @return bool True on successful upload, false on skip / failure.
	 */
	public function export( string $feed_id, string $filepath, Config $config = null ): bool {
		if ( null === $config ) {
			return false;
		}

		// Upload disabled in this feed's Filter/FTP tab.
		$enabled = (int) $config->get( 'ftpenabled', 0 );
		if ( 1 !== $enabled ) {
			return false;
		}

		if ( '' === $filepath || ! file_exists( $filepath ) ) {
			$this->log_error( $feed_id, 'FTP export skipped — feed file not found: ' . $filepath );
			return false;
		}

		$host = trim( (string) $config->get( 'ftphost', '' ) );
		$user = trim( (string) $config->get( 'ftpuser', '' ) );
		if ( '' === $host || '' === $user ) {
			$this->log_error( $feed_id, 'FTP export skipped — host or username not configured.' );
			return false;
		}

		// Decrypt stored password.
		$encrypted_password = (string) $config->get( 'ftppassword', '' );
		$password           = '';
		if ( '' !== $encrypted_password ) {
			$encryptor = new Encryptor();
			$password  = $encryptor->decrypt( $encrypted_password );
		}

		$protocol = 'sftp' === $config->get( 'ftporsftp', 'ftp' ) ? 'sftp' : 'ftp';
		$mode     = 'active' === $config->get( 'ftpmode', 'passive' ) ? 'active' : 'passive';
		$path     = (string) $config->get( 'ftppath', '' );
		$port     = (int) $config->get( 'ftpport', 0 );

		if ( $port < 1 || $port > 65535 ) {
			$port = 'sftp' === $protocol ? 22 : 21;
		}

		// Normalise path with a trailing slash — matches V5's behaviour.
		$path         = trailingslashit( untrailingslashit( $path ) );
		$remote_file  = basename( $filepath );
		$passive_mode = 'passive' === $mode;

		Logger::info(
			'Starting feed export',
			array(
				'feed_id'  => $feed_id,
				'host'     => $host,
				'protocol' => $protocol,
				'port'     => $port,
				'path'     => $path,
				'file'     => $remote_file,
			) 
		);

		$this->log_info( $feed_id, sprintf( 'Uploading via %s to %s@%s:%d%s', strtoupper( $protocol ), $user, $host, $port, $path ) );

		try {
			if ( 'ftp' === $protocol ) {
				return $this->upload_ftp( $feed_id, $host, $user, $password, $port, $passive_mode, $filepath, $path . $remote_file );
			}
			return $this->upload_sftp( $feed_id, $host, $user, $password, $port, $filepath, $remote_file, $path );
		} catch ( \Throwable $e ) {
			$message = sprintf( 'FTP upload error (%s): %s', $protocol, $e->getMessage() );
			Logger::error(
				$message,
				array(
					'feed_id' => $feed_id,
					'host'    => $host,
				) 
			);
			$this->log_error( $feed_id, $message );
			return false;
		}
	}

	/**
	 * Upload via FTP using V8's FTPConnection class.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id      Feed slug.
	 * @param string $host         Host.
	 * @param string $user         User.
	 * @param string $password     Password.
	 * @param int    $port         Port.
	 * @param bool   $passive_mode Passive mode flag.
	 * @param string $local_file   Absolute local file path.
	 * @param string $remote_file  Absolute remote file path (incl. filename).
	 *
	 * @return bool
	 */
	private function upload_ftp( string $feed_id, string $host, string $user, string $password, int $port, bool $passive_mode, string $local_file, string $remote_file ): bool {
		if ( ! function_exists( 'ftp_connect' ) ) {
			$msg = 'FTP upload failed — PHP FTP extension is not enabled on this server.';
			Logger::error( $msg, array( 'feed_id' => $feed_id ) );
			$this->log_error( $feed_id, $msg );
			return false;
		}

		$this->log_info(
			$feed_id,
			sprintf(
				'FTP target: ftp://%s@%s:%d%s%s',
				$user,
				$host,
				$port,
				( '/' === substr( $remote_file, 0, 1 ) ) ? '' : '/',
				$remote_file
			) 
		);
		$this->log_info( $feed_id, 'Local file: ' . $local_file . ' (' . ( file_exists( $local_file ) ? size_format( filesize( $local_file ) ) : 'missing' ) . ')' );

		$ftp = new FTPConnection();

		if ( ! $ftp->connect( $host, $user, $password, $passive_mode, $port ) ) {
			foreach ( (array) $ftp->get_messages() as $m ) {
				$this->log_error( $feed_id, $m );
			}
			return false;
		}

		$uploaded = $ftp->upload_file( $local_file, $remote_file );

		foreach ( (array) $ftp->get_messages() as $m ) {
			$this->log_info( $feed_id, $m );
		}

		if ( $uploaded ) {
			// Post-upload verification: prove the file is actually on the
			// server at the path we expected. ftp_put can return true
			// against some misbehaving daemons even when the file is
			// silently dropped, so we re-query the size and pwd here. Use the
			// path the upload actually wrote to — it may be the root fallback
			// rather than the requested directory.
			$this->verify_ftp_upload( $feed_id, $host, $user, $password, $port, $passive_mode, $ftp->get_last_remote_path() );

			Logger::info( 'FTP upload succeeded', array( 'feed_id' => $feed_id ) );
			$this->log_info( $feed_id, 'FTP upload succeeded.' );
		}

		return (bool) $uploaded;
	}

	/**
	 * Open a fresh FTP connection and confirm the uploaded file exists at
	 * the expected path. Logs the server-reported pwd, size, and a directory
	 * listing of the parent so the user can see exactly where the file
	 * landed. Helps when Cyberduck shows nothing — we can prove the file
	 * is on the server vs. confirm it really was silently dropped.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id      Feed slug.
	 * @param string $host         Host.
	 * @param string $user         User.
	 * @param string $password     Password.
	 * @param int    $port         Port.
	 * @param bool   $passive_mode Passive mode flag.
	 * @param string $remote_file  Remote target path (path + filename).
	 *
	 * @return void
	 */
	private function verify_ftp_upload( string $feed_id, string $host, string $user, string $password, int $port, bool $passive_mode, string $remote_file ): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- Diagnostic probe, error-suppressed by design: every ftp_* call below is a *question* asked of a possibly-broken merchant server (does the file exist? what is pwd? can we list the directory?). PHP's ftp_* functions emit warnings for the very conditions being probed, and each return value is already checked and written to the per-feed log. Letting the warnings through would spray a user's misconfigured FTP host into the site error log on every feed run without adding information.
		$conn = @ftp_connect( $host, $port, 10 );
		if ( ! $conn ) {
			$this->log_error( $feed_id, 'Verify: ftp_connect failed.' );
			return;
		}

		if ( ! @ftp_login( $conn, $user, $password ) ) {
			$this->log_error( $feed_id, 'Verify: ftp_login failed.' );
			@ftp_close( $conn );
			return;
		}
		@ftp_pasv( $conn, $passive_mode );

		$pwd = @ftp_pwd( $conn );
		$this->log_info( $feed_id, 'Verify: ftp_pwd = ' . ( false === $pwd ? 'unknown' : $pwd ) );

		$size = @ftp_size( $conn, $remote_file );
		if ( -1 === $size ) {
			$this->log_error( $feed_id, 'Verify: ftp_size(' . $remote_file . ') = -1 — server reports the file does not exist at this path.' );
		} else {
			$this->log_info( $feed_id, 'Verify: ftp_size(' . $remote_file . ') = ' . size_format( (int) $size ) );
		}

		// List the parent directory so the user sees what's actually there.
		$parent  = '' === dirname( $remote_file ) || '.' === dirname( $remote_file ) ? '/' : dirname( $remote_file );
		$listing = @ftp_nlist( $conn, $parent );
		if ( is_array( $listing ) ) {
			$entries = implode( ', ', array_slice( $listing, 0, 20 ) );
			$suffix  = count( $listing ) > 20 ? sprintf( ' (+%d more)', count( $listing ) - 20 ) : '';
			$this->log_info( $feed_id, 'Verify: directory listing of ' . $parent . ' = [' . $entries . ']' . $suffix );
		} else {
			$this->log_error( $feed_id, 'Verify: ftp_nlist(' . $parent . ') returned no listing — daemon may not allow LIST for this user.' );
		}

		@ftp_close( $conn );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden
	}

	/**
	 * Upload via SFTP using V8's SFTPConnection class.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id     Feed slug.
	 * @param string $host        Host.
	 * @param string $user        User.
	 * @param string $password    Password.
	 * @param int    $port        Port.
	 * @param string $local_file  Absolute local file path.
	 * @param string $remote_file Just the filename on the remote server.
	 * @param string $path        Remote directory with trailing slash.
	 *
	 * @return bool
	 * @throws \Throwable Re-thrown from SFTPConnection so export() can log the underlying message.
	 */
	private function upload_sftp( string $feed_id, string $host, string $user, string $password, int $port, string $local_file, string $remote_file, string $path ): bool {
		if ( ! extension_loaded( 'ssh2' ) ) {
			$msg = 'SFTP upload failed — the PHP ssh2 extension is not enabled on this server. Ask your host to enable it or switch the feed to FTP.';
			Logger::error( $msg, array( 'feed_id' => $feed_id ) );
			$this->log_error( $feed_id, $msg );
			return false;
		}

		$this->log_info(
			$feed_id,
			sprintf(
				'SFTP target: ssh2.sftp://%s@%s:%d%s%s',
				$user,
				$host,
				$port,
				$path,
				$remote_file
			) 
		);
		$this->log_info( $feed_id, 'Local file: ' . $local_file . ' (' . ( file_exists( $local_file ) ? size_format( filesize( $local_file ) ) : 'missing' ) . ')' );

		// Capture every PHP warning/notice emitted while we're inside the
		// ssh2 stream wrapper so the per-feed log shows the *actual* reason
		// fopen / fwrite failed (e.g. "Failure creating remote file",
		// "Permission denied", quota errors), not the legacy class's
		// generic "Could not open file" text.
		$captured = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code: this scopes a temporary handler around the ssh2 stream-wrapper calls so the PHP warning text ("Failure creating remote file", "Permission denied", quota errors) can be surfaced in the per-feed log instead of the legacy class's opaque message. Always undone by restore_error_handler() in the finally block.
			function ( $errno, $errstr, $errfile, $errline ) use ( &$captured ) {
				$captured[] = sprintf( '[errno %d] %s (%s:%d)', $errno, $errstr, basename( (string) $errfile ), (int) $errline );
				return true; // suppress default handler.
			}
		);

		$ok        = false;
		$exception = null;
		try {
			$sftp_conn = new SFTPConnection( $host, $port );
			$sftp_conn->login( $user, $password );
			$ok = $sftp_conn->upload_file( $local_file, $remote_file, $path );
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			restore_error_handler();
		}

		// Emit captured warnings to the feed log regardless of outcome —
		// they're often the difference between "permission denied" and
		// "no such directory" when the legacy class's message is opaque.
		foreach ( $captured as $w ) {
			if ( $ok && false === strpos( $w, 'fopen' ) && false === strpos( $w, 'ssh2' ) ) {
				continue; // ignore unrelated notices on success.
			}
			$this->log_error( $feed_id, 'SFTP warning: ' . $w );
		}

		if ( $exception || ! $ok ) {
			// Re-run the connection in a diagnostic mode so the log shows
			// concretely whether the path exists, whether it's writable,
			// and what the resolved absolute path is on the remote server.
			$this->diagnose_sftp( $feed_id, $host, $user, $password, $port, $path );

			if ( $exception ) {
				throw $exception; // re-throw so export() catches it and logs the message.
			}
			return false;
		}

		Logger::info( 'SFTP upload succeeded', array( 'feed_id' => $feed_id ) );
		$this->log_info( $feed_id, 'SFTP upload succeeded.' );
		return true;
	}

	/**
	 * Run a diagnostic probe against the SFTP server and write the results
	 * to the per-feed log. Called when an upload fails — gives the user
	 * concrete info about what went wrong with the remote path / perms
	 * instead of a generic exception message.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id  Feed slug.
	 * @param string $host     Host.
	 * @param string $user     User.
	 * @param string $password Password.
	 * @param int    $port     Port.
	 * @param string $path     Remote directory with trailing slash.
	 *
	 * @return void
	 */
	private function diagnose_sftp( string $feed_id, string $host, string $user, string $password, int $port, string $path ): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Diagnostic probe, error-suppressed by design: every call below is a *question* asked of a possibly-broken merchant SFTP server (does the path resolve? is it a directory? is it writable? can we create a file?). The ssh2 extension and the ssh2.sftp:// stream wrapper emit warnings for exactly the conditions being probed, and each return value is checked and written to the per-feed log. The file ops target the merchant's own remote server through the stream wrapper, which WP_Filesystem cannot address; the probe file is written and immediately unlinked.
		$captured = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code: this scopes a temporary handler around the ssh2 stream-wrapper calls so the PHP warning text ("Failure creating remote file", "Permission denied", quota errors) can be surfaced in the per-feed log instead of the legacy class's opaque message. Always undone by restore_error_handler() in the finally block.
			function ( $errno, $errstr ) use ( &$captured ) {
				$captured[] = sprintf( '[errno %d] %s', $errno, $errstr );
				return true;
			}
		);

		try {
			$conn = @ssh2_connect( $host, $port );
			if ( ! $conn ) {
				$this->log_error( $feed_id, sprintf( 'Diagnostic: ssh2_connect(%s:%d) failed.', $host, $port ) );
				return;
			}
			$this->log_info( $feed_id, 'Diagnostic: ssh2_connect OK.' );

			if ( ! @ssh2_auth_password( $conn, $user, $password ) ) {
				$this->log_error( $feed_id, sprintf( 'Diagnostic: ssh2_auth_password failed for user "%s".', $user ) );
				return;
			}
			$this->log_info( $feed_id, 'Diagnostic: ssh2_auth_password OK.' );

			$sftp = @ssh2_sftp( $conn );
			if ( ! $sftp ) {
				$this->log_error( $feed_id, 'Diagnostic: ssh2_sftp() failed.' );
				return;
			}

			$resolved = @ssh2_sftp_realpath( $sftp, $path );
			$this->log_info( $feed_id, 'Diagnostic: ssh2_sftp_realpath(' . $path . ') = ' . ( false === $resolved ? 'FALSE (path does not resolve)' : $resolved ) );

			$wrapper_path = "ssh2.sftp://{$sftp}{$path}";
			$is_dir       = @is_dir( $wrapper_path );
			$is_writable  = @is_writable( $wrapper_path );
			$this->log_info( $feed_id, 'Diagnostic: is_dir(remote path) = ' . ( $is_dir ? 'true' : 'false' ) );
			$this->log_info( $feed_id, 'Diagnostic: is_writable(remote path) = ' . ( $is_writable ? 'true' : 'false' ) );

			$stat = @ssh2_sftp_stat( $sftp, $path );
			if ( is_array( $stat ) ) {
				$this->log_info(
					$feed_id,
					sprintf(
						'Diagnostic: stat — uid=%s gid=%s mode=%s size=%s',
						$stat['uid'] ?? 'n/a',
						$stat['gid'] ?? 'n/a',
						isset( $stat['mode'] ) ? sprintf( '0%o', $stat['mode'] & 0777 ) : 'n/a',
						$stat['size'] ?? 'n/a'
					) 
				);
			} else {
				$this->log_error( $feed_id, 'Diagnostic: ssh2_sftp_stat(' . $path . ') returned no data — directory likely does not exist.' );
			}

			// Test create / write a tiny probe file so we can distinguish
			// "directory not writable" from "directory missing".
			$probe        = $path . '.ctxfeed_probe_' . time();
			$probe_url    = "ssh2.sftp://{$sftp}{$probe}";
			$probe_handle = @fopen( $probe_url, 'w' );
			if ( $probe_handle ) {
				@fwrite( $probe_handle, 'probe' );
				@fclose( $probe_handle );
				@ssh2_sftp_unlink( $sftp, $probe );
				$this->log_info( $feed_id, 'Diagnostic: probe file write+delete OK — directory is writable for this user.' );
			} else {
				$this->log_error( $feed_id, 'Diagnostic: probe fopen(' . $probe_url . ') FAILED — user "' . $user . '" cannot write to "' . $path . '".' );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( $feed_id, 'Diagnostic exception: ' . $e->getMessage() );
		} finally {
			restore_error_handler();
		}

		foreach ( $captured as $w ) {
			$this->log_error( $feed_id, 'Diagnostic warning: ' . $w );
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Write an info line to the per-feed log (when the logger is injected).
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id Feed slug.
	 * @param string $message Log message.
	 * @return void
	 */
	private function log_info( string $feed_id, string $message ): void {
		if ( $this->feed_logger ) {
			$this->feed_logger->info( $feed_id, $message );
		}
	}

	/**
	 * Write an error line to the per-feed log (when the logger is injected).
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id Feed slug.
	 * @param string $message Log message.
	 * @return void
	 */
	private function log_error( string $feed_id, string $message ): void {
		if ( $this->feed_logger ) {
			$this->feed_logger->error( $feed_id, $message );
		}
	}
}
