<?php
/**
 * Redactor — blanks credentials before data leaves the site.
 *
 * Feed configurations carry the FTP/SFTP password (`ftppassword`, stored
 * encrypted since V8; V5-era configs may hold it as saved). Anything that
 * sends a feed configuration off the site — the Contact support email and
 * the usage data / deactivation feedback sent to WebAppick — passes it
 * through here first (CBT-690).
 *
 * @package    CTXFeed
 * @subpackage V8\Utility
 * @since      8.0.31
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Redactor
 *
 * @since 8.0.31
 */
class Redactor {

	/**
	 * Placeholder written instead of a credential.
	 *
	 * @var string
	 */
	const PLACEHOLDER = '(redacted)';

	/**
	 * Keys whose non-empty values are credentials.
	 *
	 * @var string
	 */
	const KEY_PATTERN = '/password|secret|api_key|apikey/i';

	/**
	 * Recursively replace credential values (any depth) with the placeholder.
	 * Empty values stay empty so "no password set" remains visible.
	 *
	 * @since 8.0.31
	 *
	 * @param array $data Config array.
	 * @return array
	 */
	public static function credentials( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::credentials( $value );
				continue;
			}
			if ( is_string( $key ) && preg_match( self::KEY_PATTERN, $key ) && '' !== (string) $value ) {
				$data[ $key ] = self::PLACEHOLDER;
			}
		}

		return $data;
	}

	/**
	 * Redact a stored option value: unserializes it, redacts, and returns it
	 * in the same serialized form. Non-array values are returned unchanged.
	 *
	 * @since 8.0.31
	 *
	 * @param mixed $stored Raw option_value (serialized string or array).
	 * @return mixed
	 */
	public static function stored_value( $stored ) {
		$value = is_string( $stored ) ? maybe_unserialize( $stored ) : $stored;
		if ( ! is_array( $value ) ) {
			return $stored;
		}
		$clean = self::credentials( $value );

		return is_string( $stored ) ? maybe_serialize( $clean ) : $clean;
	}
}
