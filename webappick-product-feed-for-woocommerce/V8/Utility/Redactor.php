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

		// V5 saved feeds as update_option( serialize( $feed ) ): the row is
		// serialized TWICE, so one pass yields a string and its plaintext
		// password left the site in telemetry (CBT-708).
		$double = false;
		if ( is_string( $value ) ) {
			$inner = self::unwrap( $value );
			if ( is_array( $inner ) ) {
				$value  = $inner;
				$double = true;
			}
		}

		if ( ! is_array( $value ) ) {
			return $stored;
		}
		$clean = self::credentials( $value );
		if ( $double ) {
			$clean = maybe_serialize( $clean );
		}

		return is_string( $stored ) ? maybe_serialize( $clean ) : $clean;
	}

	/**
	 * Redact free text (log contents for support bundles): every given
	 * secret, plus the legacy "… and password X ." line older versions
	 * wrote on an SFTP login failure (CBT-708).
	 *
	 * @since 8.0.32
	 *
	 * @param string   $text    Text.
	 * @param string[] $secrets Secrets to remove (short ones ignored).
	 * @return string
	 */
	public static function text( string $text, array $secrets = array() ): string {
		foreach ( array_unique( array_map( 'strval', $secrets ) ) as $secret ) {
			if ( strlen( $secret ) >= 3 ) {
				$text = str_replace( $secret, self::PLACEHOLDER, $text );
				if ( function_exists( 'esc_attr' ) ) {
					$text = str_replace( esc_attr( $secret ), self::PLACEHOLDER, $text );
				}
			}
		}

		return (string) preg_replace( '/(\band password )\S.*?( \.|$)/m', '$1' . self::PLACEHOLDER . '$2', $text );
	}

	/**
	 * Every saved FTP/SFTP password on the site, stored and readable forms.
	 *
	 * @since 8.0.32
	 *
	 * @return string[]
	 */
	public static function saved_ftp_passwords(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerates the plugin's own wf_feed_* rows for an on-demand support bundle; get_option() cannot list by prefix.
		$rows    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wf_feed_' ) . '%' ) );
		$crypto  = new Encryptor();
		$secrets = array();
		foreach ( $rows as $row ) {
			$config = self::unwrap( self::unwrap( $row ) );
			$stored = is_array( $config ) && isset( $config['feedrules']['ftppassword'] ) ? (string) $config['feedrules']['ftppassword'] : '';
			if ( '' === $stored ) {
				continue;
			}
			$secrets[] = $stored;
			$plain     = $crypto->reveal_password( $stored );
			if ( null !== $plain && '' !== $plain ) {
				$secrets[] = $plain;
			}
		}

		return $secrets;
	}

	/**
	 * Unserialize one level of a serialized ARRAY string; anything else is
	 * returned unchanged. Objects are never instantiated.
	 *
	 * @since 8.0.32
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function unwrap( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^a:\d+:\{/', $value ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- allowed_classes=false is the object-injection guard; a damaged row returns false and the input is kept.
		$out = @unserialize( $value, array( 'allowed_classes' => false ) );

		return false === $out ? $value : $out;
	}
}
