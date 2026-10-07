<?php
/**
 * Utf8 — repair invalid UTF-8 in product text (CBT-694).
 *
 * Product fields imported in the wrong charset (latin1 bytes, text pasted
 * from Word/PDF through a non-UTF-8 sync) carry byte sequences that are not
 * UTF-8. Written as-is they make libxml reject the whole XML feed
 * ("Encoding error"); fed to a `/u` regex they make preg_* return null and
 * the whole value is lost.
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
 * Class Utf8
 *
 * @since 8.0.32
 */
class Utf8 {

	/**
	 * Remove invalid UTF-8 byte sequences, keeping every valid character.
	 *
	 * Valid input (the normal case) returns after one `//u` check. Invalid
	 * input keeps each well-formed UTF-8 sequence and drops stray bytes —
	 * pure PCRE, no mbstring/iconv dependency (iconv's //IGNORE is broken on
	 * some glibc builds).
	 *
	 * @since 8.0.32
	 *
	 * @param string $value Text.
	 * @return string Valid UTF-8.
	 */
	public static function scrub( string $value ): string {
		if ( '' === $value || 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		$clean = preg_replace(
			'/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s',
			'',
			$value
		);

		if ( null !== $clean ) {
			return $clean;
		}

		// PCRE failed (should not happen): substitute rather than ship bad bytes.
		return function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $value, 'UTF-8', 'UTF-8' ) : '';
	}
}
