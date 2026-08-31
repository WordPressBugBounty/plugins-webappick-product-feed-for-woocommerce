<?php
/**
 * Encryptor — AES-256-CBC encryption for sensitive data.
 *
 * Encrypts API keys, FTP/SFTP credentials, webhook secrets, and other
 * sensitive values before storage in wp_options. Uses a random IV per
 * call so encrypting the same value twice produces different ciphertext.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-2.1, UTIL-FRD-2.2, UTIL-FRD-2.3, UTIL-FRD-2.4
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-CBC encryption service.
 *
 * @since 8.0.0
 */
class Encryptor {

	/**
	 * OpenSSL cipher method.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Fallback encryption key when no constants are defined.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const FALLBACK_KEY = 'ctxfeed-default-encryption-key';

	/**
	 * Encrypt a value for safe storage.
	 *
	 * Each call generates a fresh random IV. The output format is
	 * base64_encode( IV + encrypted_payload ).
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-2.1
	 *
	 * @param string $value Plaintext value to encrypt.
	 *
	 * @return string Encrypted base64-encoded string, or empty string if input is empty.
	 */
	public function encrypt( string $value ): string {

		if ( '' === $value ) {
			return '';
		}

		$key       = $this->get_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, 0, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64 here is binary-safe transport for the IV + ciphertext of stored credentials, not obfuscation.
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * Expects base64-encoded input with IV prepended to the ciphertext.
	 * Returns empty string on corruption or decryption failure.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-2.2
	 *
	 * @param string $value Encrypted base64-encoded string.
	 *
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	public function decrypt( string $value ): string {

		if ( '' === $value ) {
			return '';
		}

		$key = $this->get_key();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 here decodes the binary IV + ciphertext of stored credentials, not obfuscation.
		$data = base64_decode( $value, true );

		if ( false === $data ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		// Ensure data is long enough to contain IV.
		if ( strlen( $data ) <= $iv_length ) {
			return '';
		}

		$iv        = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );

		$decrypted = openssl_decrypt( $encrypted, self::CIPHER, $key, 0, $iv );

		if ( false === $decrypted ) {
			return '';
		}

		return $decrypted;
	}

	/**
	 * Resolve the encryption key in priority order.
	 *
	 * Priority:
	 * 1. CTXFEED_ENCRYPTION_KEY constant (site-specific).
	 * 2. AUTH_KEY WordPress constant (wp-config.php).
	 * 3. FALLBACK_KEY hardcoded constant.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-2.3
	 *
	 * @return string Encryption key.
	 */
	private function get_key(): string {

		if ( defined( 'CTXFEED_ENCRYPTION_KEY' ) ) {
			return CTXFEED_ENCRYPTION_KEY;
		}

		if ( defined( 'AUTH_KEY' ) ) {
			return AUTH_KEY;
		}

		return self::FALLBACK_KEY;
	}
}
