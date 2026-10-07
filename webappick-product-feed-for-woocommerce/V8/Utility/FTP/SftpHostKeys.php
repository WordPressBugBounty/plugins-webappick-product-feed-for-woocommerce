<?php
/**
 * SftpHostKeys — trust-on-first-use SFTP server identity (CBT-711).
 *
 * Feed uploads never checked the SFTP server's host key, so anyone able to
 * sit between the store and the server could receive the feed and the
 * password. The first successful upload remembers the server's key for
 * that host:port; a later upload to a server presenting a DIFFERENT key
 * stops with a clear message. Saving the feed's FTP / SFTP settings again
 * accepts the new key (a legitimately reinstalled server).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility/FTP
 * @since      8.0.32
 */

namespace CTXFeed\V8\Utility\FTP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SftpHostKeys
 *
 * @since 8.0.32
 */
class SftpHostKeys {

	/**
	 * Option holding host:port => fingerprint (MD5 hex, ssh2_fingerprint()).
	 *
	 * @var string
	 */
	const OPTION = 'ctxfeed_sftp_host_keys';

	/**
	 * The remembered fingerprint, or '' when none.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @return string
	 */
	public static function get( string $host, int $port ): string {
		$keys = get_option( self::OPTION, array() );
		$id   = self::id( $host, $port );

		return is_array( $keys ) && isset( $keys[ $id ] ) ? (string) $keys[ $id ] : '';
	}

	/**
	 * Remember a server's fingerprint (first successful upload).
	 *
	 * @param string $host        Host.
	 * @param int    $port        Port.
	 * @param string $fingerprint Fingerprint.
	 * @return void
	 */
	public static function remember( string $host, int $port, string $fingerprint ): void {
		if ( '' === $fingerprint ) {
			return;
		}
		$keys                             = get_option( self::OPTION, array() );
		$keys                             = is_array( $keys ) ? $keys : array();
		$keys[ self::id( $host, $port ) ] = $fingerprint;
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * Forget a server's fingerprint (the merchant re-saved the settings).
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @return void
	 */
	public static function forget( string $host, int $port ): void {
		$keys = get_option( self::OPTION, array() );
		$id   = self::id( $host, $port );
		if ( is_array( $keys ) && isset( $keys[ $id ] ) ) {
			unset( $keys[ $id ] );
			update_option( self::OPTION, $keys, false );
		}
	}

	/**
	 * Normalised key for a server.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @return string
	 */
	private static function id( string $host, int $port ): string {
		return strtolower( trim( $host ) ) . ':' . ( $port > 0 ? $port : 22 );
	}
}
