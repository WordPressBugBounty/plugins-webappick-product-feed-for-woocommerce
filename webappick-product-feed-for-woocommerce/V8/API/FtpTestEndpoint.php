<?php
/**
 * FtpTestEndpoint — REST API for the FTP / SFTP "Test connection" card
 * on the Make Feed FTP tab (edit-feed v2 redesign, owner-approved
 * backend addition, 2026-07-28).
 *
 * POST /ctxfeed/v8/ftp/test — connects with the SUBMITTED form values
 * (not the saved ones), writes a ~1 KB probe file to the remote
 * directory and deletes it again, then reports an honest success or
 * failure message.
 *
 * Uses the same V8 transport classes as the generation-time upload
 * (FeedRemoteTransport → Utility\FTP\FTPConnection / SFTPConnection),
 * so a green test is a true predictor of the real upload.
 *
 * Security: the password is used for the connection only. It is never
 * logged, never stored, and never echoed back — every message that
 * leaves this endpoint passes through scrub(), which redacts the
 * password should a transport-layer exception happen to contain it.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Utility\FTP\FTPConnection;
use CTXFeed\V8\Utility\FTP\SFTPConnection;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FTP / SFTP connection-test REST endpoint.
 *
 * @since 8.0.0
 */
class FtpTestEndpoint extends RestController {

	/**
	 * Register the connection-test route.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/ftp/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'host'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'port'     => array(
						'required' => false,
						'type'     => array( 'integer', 'string' ),
					),
					'username' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					// Deliberately NOT sanitized — passwords may legitimately
					// contain characters sanitize_text_field would strip. The
					// value is used for the connection only; never stored,
					// logged, or echoed (see scrub()).
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
					'protocol' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mode'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'path'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) 
		);
	}

	/**
	 * Test FTP / SFTP connectivity with the submitted form values.
	 *
	 * Writes a ~1 KB probe file to the remote directory and deletes it,
	 * so nothing is left behind on the merchant server.
	 *
	 * POST /ctxfeed/v8/ftp/test
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		// Host — tolerate a pasted URL by dropping the scheme prefix.
		$host = trim( (string) $request->get_param( 'host' ) );
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $host );
		$host = trim( $host, "/ \t" );
		if ( '' === $host ) {
			return $this->error( __( 'Please enter a host name or IP — without a protocol prefix.', 'woo-feed' ), 400 );
		}

		$username = trim( (string) $request->get_param( 'username' ) );
		if ( '' === $username ) {
			return $this->error( __( 'Please enter the username.', 'woo-feed' ), 400 );
		}

		$password = (string) $request->get_param( 'password' );
		if ( '' === $password ) {
			return $this->error( __( 'Please enter the password.', 'woo-feed' ), 400 );
		}

		$protocol = 'sftp' === $request->get_param( 'protocol' ) ? 'sftp' : 'ftp';
		$passive  = 'active' !== $request->get_param( 'mode' );

		$port = (int) $request->get_param( 'port' );
		if ( $port < 1 || $port > 65535 ) {
			$port = 'sftp' === $protocol ? 22 : 21;
		}

		// Path — must be absolute, matches the generation-time upload
		// contract (relative paths and ~ do not resolve over ftp/ssh2).
		$path = trim( (string) $request->get_param( 'path' ) );
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
			return $this->error( __( 'The remote directory must be an absolute path starting with / — relative paths and ~ do not work.', 'woo-feed' ), 400 );
		}
		$path = trailingslashit( untrailingslashit( $path ) );

		// Transport capability — same checks FeedRemoteTransport makes.
		if ( 'ftp' === $protocol && ! $this->has_ftp_support() ) {
			return $this->error( __( 'The PHP FTP extension is not enabled on this server. Ask your host to enable it or use SFTP.', 'woo-feed' ), 400 );
		}
		if ( 'sftp' === $protocol && ! $this->has_sftp_support() ) {
			return $this->error( __( 'The PHP ssh2 extension is not enabled on this server. Ask your host to enable it or use FTP.', 'woo-feed' ), 400 );
		}

		// Reachability first, with a short timeout: an unreachable host or a
		// blocked outgoing port used to sit inside ssh2_connect()/ftp_connect()
		// for up to 90 s and then surface as a generic "check the credentials".
		$unreachable = $this->reach_host( $host, $port );
		if ( null !== $unreachable ) {
			return $this->error(
				sprintf(
					/* translators: 1: host, 2: port, 3: socket error detail. */
					__( 'Could not reach %1$s on port %2$d — %3$s. Check the host and port, and that this server may open outgoing connections on that port.', 'woo-feed' ),
					$host,
					$port,
					$unreachable
				),
				502
			);
		}

		$local_file = $this->create_probe_file();
		if ( '' === $local_file ) {
			return $this->error( __( 'Could not create a temporary test file on this server.', 'woo-feed' ), 500 );
		}

		$probe_name = 'ctxfeed-connection-test-' . uniqid() . '.txt';

		// Collect the PHP warnings the ftp_*/ssh2_* functions emit on failure
		// ("Authentication failed…", "Connection refused", …): they are the
		// actual reason and belong in the message shown to the admin.
		$this->warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to the probe below and restored in finally; the only way to read the reason ftp_*()/ssh2_*() report.
		set_error_handler(
			function ( $errno, $errstr ) {
				$this->warnings[] = (string) $errstr;
				return true;
			},
			E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
		);
		try {
			if ( 'ftp' === $protocol ) {
				$result = $this->run_ftp_probe( $host, $port, $username, $password, $passive, $local_file, $path, $probe_name );
			} else {
				$result = $this->run_sftp_probe( $host, $port, $username, $password, $local_file, $path, $probe_name );
			}
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: protocol label (FTP/SFTP), 2: transport error detail (password-scrubbed before output). */
					__( '%1$s error: %2$s', 'woo-feed' ),
					strtoupper( $protocol ),
					$e->getMessage()
				),
			);
		} finally {
			restore_error_handler();
			wp_delete_file( $local_file );
		}

		if ( empty( $result['ok'] ) ) {
			$message = isset( $result['message'] ) && '' !== $result['message']
				? (string) $result['message']
				: __( 'The connection test failed.', 'woo-feed' );

			$detail = $this->last_warning();
			if ( '' !== $detail && false === strpos( $message, $detail ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: the PHP warning text reported by the ftp/ssh2 function that failed. */
					__( 'Server said: %s', 'woo-feed' ),
					$detail
				);
			}
			return $this->error( $this->scrub( $message, $password ), 502 );
		}

		$message = sprintf(
			/* translators: 1: username, 2: remote directory path. */
			__( 'Connected — signed in as %1$s and wrote a test file to %2$s.', 'woo-feed' ),
			$username,
			$path
		);

		if ( ! empty( $result['warning'] ) ) {
			$message .= ' ' . $this->scrub( (string) $result['warning'], $password );
		}

		return $this->success( array( 'message' => $message ) );
	}

	/**
	 * PHP warnings captured while a probe ran (see test_connection()).
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * The most recent captured warning, without the "function(): " prefix.
	 *
	 * @since 8.0.10
	 *
	 * @return string
	 */
	private function last_warning(): string {
		if ( empty( $this->warnings ) ) {
			return '';
		}
		$last = (string) end( $this->warnings );

		return trim( (string) preg_replace( '/^[a-z0-9_]+\(\):\s*/i', '', $last ) );
	}

	/**
	 * TCP reachability check with a 10 s timeout.
	 *
	 * @since 8.0.10
	 *
	 * @param string $host Host name or IP.
	 * @param int    $port Port.
	 * @return string|null Socket error text when unreachable, null when reachable.
	 */
	protected function reach_host( string $host, int $port ): ?string {
		$errno  = 0;
		$errstr = '';
		// A failed connect also raises a PHP warning; swallow it here (the
		// $errstr carries the same text) instead of silencing with "@".
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to the one socket call below and restored right after.
		set_error_handler( static fn() => true, E_WARNING | E_NOTICE );
		try {
			$socket = stream_socket_client( 'tcp://' . $host . ':' . $port, $errno, $errstr, 10 );
		} finally {
			restore_error_handler();
		}
		if ( false === $socket ) {
			$errstr = trim( (string) $errstr );
			return '' !== $errstr ? $errstr : __( 'connection timed out', 'woo-feed' );
		}
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the probe socket.

		return null;
	}

	/**
	 * Whether the PHP FTP extension is available.
	 *
	 * Protected seam so unit tests can force either branch.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	protected function has_ftp_support(): bool {
		return function_exists( 'ftp_connect' );
	}

	/**
	 * Whether the PHP ssh2 extension is available.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	protected function has_sftp_support(): bool {
		return extension_loaded( 'ssh2' );
	}

	/**
	 * Create the ~1 KB local probe file in the temp directory.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute path, or '' on failure.
	 */
	protected function create_probe_file(): string {
		$file    = get_temp_dir() . 'ctxfeed-ftp-test-' . uniqid() . '.txt';
		$content = str_repeat( "CTX Feed connection test — this file is deleted right after the test.\n", 15 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Tiny throw-away probe file in the system temp dir; WP_Filesystem is not initialised in this REST context.
		return false === file_put_contents( $file, $content ) ? '' : $file;
	}

	/**
	 * Connect + write + delete the probe over plain FTP.
	 *
	 * @since 8.0.0
	 *
	 * @param string $host       Host name or IP.
	 * @param int    $port       Port.
	 * @param string $username   Username.
	 * @param string $password   Password (never logged or echoed).
	 * @param bool   $passive    Passive-mode flag.
	 * @param string $local_file Local probe file path.
	 * @param string $path       Remote directory with trailing slash.
	 * @param string $probe_name Remote probe file name.
	 *
	 * @return array { ok: bool, message?: string, warning?: string }
	 */
	protected function run_ftp_probe( string $host, int $port, string $username, string $password, bool $passive, string $local_file, string $path, string $probe_name ): array {
		$ftp = new FTPConnection();

		if ( ! $ftp->connect( $host, $username, $password, $passive, $port ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: host, 2: port, 3: username. */
					__( 'Could not sign in to %1$s:%2$d as %3$s — check the host, port and credentials.', 'woo-feed' ),
					$host,
					$port,
					$username
				),
			);
		}

		if ( ! $ftp->upload_file( $local_file, $path . $probe_name ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: remote directory path, 2: username. */
					__( 'Signed in, but could not write to %1$s — check that the directory exists and is writable by %2$s.', 'woo-feed' ),
					$path,
					$username
				),
			);
		}

		$warning = '';
		if ( ! $ftp->delete_file( $path . $probe_name ) ) {
			$warning = sprintf(
				/* translators: %s: remote probe file path. */
				__( 'The test file %s could not be deleted — remove it manually.', 'woo-feed' ),
				$path . $probe_name
			);
		}

		return array(
			'ok'      => true,
			'warning' => $warning,
		);
	}

	/**
	 * Connect + write + delete the probe over SFTP (ssh2).
	 *
	 * @since 8.0.0
	 *
	 * @param string $host       Host name or IP.
	 * @param int    $port       Port.
	 * @param string $username   Username.
	 * @param string $password   Password (never logged or echoed).
	 * @param string $local_file Local probe file path.
	 * @param string $path       Remote directory with trailing slash.
	 * @param string $probe_name Remote probe file name.
	 *
	 * @return array { ok: bool, message?: string, warning?: string }
	 */
	protected function run_sftp_probe( string $host, int $port, string $username, string $password, string $local_file, string $path, string $probe_name ): array {
		try {
			$sftp = new SFTPConnection( $host, $port );
		} catch ( \Throwable $e ) {
			// Reached the port but no SSH handshake: wrong service on that
			// port (an HTTPS port, a plain FTP server) or a rejected client.
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: host, 2: port, 3: detail from the connection attempt. */
					__( 'Reached %1$s:%2$d but could not start an SSH session — is that really the SFTP port? %3$s', 'woo-feed' ),
					$host,
					$port,
					$e->getMessage()
				),
			);
		}
		try {
			// NB: SFTPConnection::login()'s failure exception includes the
			// credentials — replace it with a safe message here (and
			// test_connection() scrubs every outbound message besides).
			$sftp->login( $username, $password );
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: host, 2: port, 3: username. */
					__( 'Connected to %1$s:%2$d but the sign-in as %3$s was rejected — check the username and password (or key passphrase).', 'woo-feed' ),
					$host,
					$port,
					$username
				),
			);
		}
		try {
			$sftp->upload_file( $local_file, $probe_name, $path );
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: remote directory path, 2: transport error detail (password-scrubbed before output). */
					__( 'Signed in, but could not write to %1$s — %2$s', 'woo-feed' ),
					$path,
					$e->getMessage()
				),
			);
		}

		$warning = '';
		try {
			$sftp->delete_file( $path . $probe_name );
		} catch ( \Throwable $e ) {
			$warning = sprintf(
				/* translators: %s: remote probe file path. */
				__( 'The test file %s could not be deleted — remove it manually.', 'woo-feed' ),
				$path . $probe_name
			);
		}

		return array(
			'ok'      => true,
			'warning' => $warning,
		);
	}

	/**
	 * Redact the password from any outbound message.
	 *
	 * Transport-layer exceptions can embed credentials (e.g. the legacy
	 * SFTP login failure text) — nothing containing the password may
	 * ever leave this endpoint.
	 *
	 * @since 8.0.0
	 *
	 * @param string $message  Message to scrub.
	 * @param string $password Password to redact.
	 * @return string
	 */
	private function scrub( string $message, string $password ): string {
		if ( '' === $password ) {
			return $message;
		}

		$needles = array_unique(
			array(
				$password,
				function_exists( 'esc_attr' ) ? esc_attr( $password ) : $password,
				htmlspecialchars( $password, ENT_QUOTES ),
			) 
		);

		return str_replace( $needles, '•••', $message );
	}
}
