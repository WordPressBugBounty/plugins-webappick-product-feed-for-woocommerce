<?php
/**
 * Mb — character (not byte) string helpers that always mean UTF-8 (CBT-815).
 *
 * Without the encoding argument, mb_strlen(), mb_substr(), mb_strtolower() …
 * follow mb_internal_encoding(). Another plugin that sets it to
 * ISO-8859-1 or ASCII for its own work leaves it set for the feed batch:
 * a 100-character Cyrillic title then counts as 200 "characters", is cut at
 * byte 150 — in the middle of a letter — and ships as 75 characters of
 * which the last is broken; mb_strtolower() folds the UTF-8 bytes as if
 * they were Latin-1 letters ("İpek şal" became "pek al").
 *
 * Every helper here passes 'UTF-8' explicitly. When mbstring is missing
 * (WordPress polyfills only mb_substr() / mb_strlen()), length and
 * substring fall back to a UTF-8 character split — never to strlen() /
 * substr(), which would cut letters in half — and case folding falls back
 * to Ascii (A–Z only). Calling these never fatals on a host without
 * mbstring.
 *
 * Implements CBT-809 plan §1.2 (Mb row) and Part 2 amendment 22 (A12).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.39
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit-UTF-8 multibyte helpers.
 *
 * @since 8.0.39
 */
final class Mb {

	/**
	 * The one encoding feed values are computed in.
	 */
	const ENCODING = 'UTF-8';

	/**
	 * One UTF-8 character, or one stray byte (counted as one character, as
	 * mbstring does). Byte mode, no /u: never fails on invalid UTF-8 and
	 * never backtracks.
	 */
	const CHAR_PATTERN = '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|[\x80-\xFF]/';

	/**
	 * Unicode lower case (mb_strtolower in UTF-8); A–Z only without mbstring.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function lower( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return function_exists( 'mb_strtolower' ) ? (string) mb_strtolower( $value, self::ENCODING ) : Ascii::lower( $value );
	}

	/**
	 * Unicode upper case (mb_strtoupper in UTF-8); a–z only without mbstring.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function upper( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return function_exists( 'mb_strtoupper' ) ? (string) mb_strtoupper( $value, self::ENCODING ) : Ascii::upper( $value );
	}

	/**
	 * Length in UTF-8 characters.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return int
	 */
	public static function strlen( $value ): int {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, self::ENCODING );
		}

		return count( self::chars( $value ) );
	}

	/**
	 * Substring in UTF-8 characters (mb_substr semantics, negative start and
	 * length included).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed    $value  Text (scalars are cast; arrays/objects give '').
	 * @param int      $start  First character.
	 * @param int|null $length Number of characters; null = to the end.
	 * @return string
	 */
	public static function substr( $value, int $start, ?int $length = null ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, $start, $length, self::ENCODING );
		}

		return implode( '', array_slice( self::chars( $value ), $start, $length ) );
	}

	/**
	 * Character position of the last occurrence of $needle, or false.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed  $haystack Text searched.
	 * @param string $needle   Text looked for ('' never matches).
	 * @return int|false
	 */
	public static function strrpos( $haystack, string $needle ) {
		if ( ! is_string( $haystack ) ) {
			$haystack = self::text( $haystack );
		}
		if ( '' === $needle ) {
			return false;
		}

		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( $haystack, $needle, 0, self::ENCODING );
		}

		// Without mbstring: the byte position, turned back into characters.
		$byte = strrpos( $haystack, $needle );
		if ( false === $byte ) {
			return false;
		}

		return count( self::chars( substr( $haystack, 0, $byte ) ) );
	}

	/**
	 * UTF-8 characters of $value (stray bytes count as one character each).
	 *
	 * @param string $value Text.
	 * @return string[]
	 */
	private static function chars( string $value ): array {
		if ( '' === $value ) {
			return array();
		}
		preg_match_all( self::CHAR_PATTERN, $value, $matches );

		return $matches[0];
	}

	/**
	 * The string a native mb_* function would have received.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( null === $value || is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return '';
	}
}
