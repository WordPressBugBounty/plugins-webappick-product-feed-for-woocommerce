<?php
/**
 * SensitiveOptions — WordPress options that must never be read through
 * CTX Feed's WP Options attribute (CBT-709).
 *
 * The WP Options screen lets a manage_woocommerce user (Shop Manager) put
 * any option on the feed allowlist and read its value back — including
 * `mcp_jwt_secret` (lets them mint OAuth tokens for any user), security
 * salts, licence keys and payment-gateway API secrets. This is the hard
 * block both the endpoint and the feed resolver apply, whatever the list
 * says.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.32
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SensitiveOptions
 *
 * @since 8.0.32
 */
class SensitiveOptions {

	/**
	 * Name fragments that mark a credential.
	 *
	 * @var string
	 */
	const NAME_PATTERN = '/pass(word|wd)?\b|_pwd|secret|token|api_?key|apikey|private|salt|nonce|licen[cs]e|credential|jwt|auth|cookie|session|hash|encrypt|recovery|_key$|^key_|ftp|smtp/i';

	/**
	 * Array-value keys that mark a credential (gateway settings, …).
	 *
	 * @var string
	 */
	const VALUE_KEY_PATTERN = '/pass(word)?|secret|token|api_?key|apikey|private|signature|credential|webhook|client_id|merchant_key/i';

	/**
	 * Whether an option may never be exposed as a feed value.
	 *
	 * @since 8.0.32
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value when already loaded (null = load it).
	 * @return bool
	 */
	public static function is_sensitive( string $name, $value = null ): bool {
		$name = trim( $name );
		if ( '' === $name ) {
			return true;
		}

		$sensitive = 1 === preg_match( self::NAME_PATTERN, $name )
			// The plugin's own rows: feed configs carry FTP/SFTP passwords.
			|| 0 === strpos( $name, 'wf_feed_' )
			|| 0 === strpos( $name, 'wf_config' )
			|| 'wpfp_option' === $name
			|| 0 === strpos( $name, '_transient' )
			|| 0 === strpos( $name, '_site_transient' );

		if ( ! $sensitive ) {
			if ( null === $value && function_exists( 'get_option' ) ) {
				$value = get_option( $name, null );
			}
			// Raw serialized values (wp_load_alloptions()) — never instantiate objects.
			if ( is_string( $value ) && 1 === preg_match( '/^a:\d+:\{/', $value ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- allowed_classes=false is the object-injection guard; a damaged value returns false and is treated as not an array.
				$value = @unserialize( $value, array( 'allowed_classes' => false ) );
			}
			$sensitive = is_array( $value ) || is_object( $value ) ? self::holds_credentials( (array) $value ) : false;
		}

		/**
		 * Filter whether an option is too sensitive to expose in a feed.
		 *
		 * Can only ADD protection in practice: return true to block more
		 * names. (Returning false for a credential is the site owner's call.)
		 *
		 * @since 8.0.32
		 *
		 * @param bool   $sensitive Whether the option is blocked.
		 * @param string $name      Option name.
		 */
		return (bool) apply_filters( 'ctxfeed_sensitive_option', $sensitive, $name );
	}

	/**
	 * Whether an array value contains a credential-looking key (any depth).
	 *
	 * @param array $value Value.
	 * @param int   $depth Recursion depth.
	 * @return bool
	 */
	private static function holds_credentials( array $value, int $depth = 0 ): bool {
		if ( $depth > 4 ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && 1 === preg_match( self::VALUE_KEY_PATTERN, $key ) && '' !== ( is_scalar( $item ) ? (string) $item : 'x' ) ) {
				return true;
			}
			if ( ( is_array( $item ) || is_object( $item ) ) && self::holds_credentials( (array) $item, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}
}
