<?php
/**
 * Ascii — case folding that never follows the PHP locale (CBT-815).
 *
 * Below PHP 8.2, strtolower(), strtoupper(), ucfirst(), ucwords() and
 * stripos() fold bytes through the C library's tolower()/toupper(), which
 * follow LC_CTYPE. Once another plugin or the host leaves a non-C locale
 * set, two things go wrong in feed values:
 *
 * - Single-byte locales (pl_PL ISO-8859-2, de_DE ISO-8859-1 …) and macOS
 *   UTF-8 locales fold the BYTES of multi-byte UTF-8 letters: ucwords()
 *   turns "中文" (E4 B8 AD) into C4 B8 AD — invalid UTF-8 that the scrub
 *   or libxml then drops ("Koszulka  Czerwona x” П").
 * - Turkish locales map "I" to dotless "ı": strtolower( 'IN STOCK' ) is no
 *   longer "in stock", so an availability enum, a command name or a filter
 *   comparison stops matching.
 *
 * Every helper here folds ASCII A–Z / a–z only — exactly what PHP 8.2+
 * does natively under any locale — so the result is the same on every PHP
 * version and every host. On PHP 8.2+ the native function is called (it
 * is already ASCII-only); below 8.2 a byte-exact userland copy runs.
 *
 * Unicode-aware folding of non-ASCII letters is Mb::lower() / Mb::upper().
 *
 * Implements CBT-809 plan §1.2 (Ascii row).
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
 * Locale-free ASCII case helpers.
 *
 * @since 8.0.39
 */
final class Ascii {

	/**
	 * ASCII upper-case letters.
	 */
	const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * ASCII lower-case letters.
	 */
	const LOWER = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Upper-case A–Z → a–z.
	 *
	 * The pair form of strtr(): on PHP 7.4 it is several times faster than
	 * strtr( $s, 'A…Z', 'a…z' ), which rebuilds a 256-byte table per call.
	 */
	const TO_LOWER = array(
		'A' => 'a',
		'B' => 'b',
		'C' => 'c',
		'D' => 'd',
		'E' => 'e',
		'F' => 'f',
		'G' => 'g',
		'H' => 'h',
		'I' => 'i',
		'J' => 'j',
		'K' => 'k',
		'L' => 'l',
		'M' => 'm',
		'N' => 'n',
		'O' => 'o',
		'P' => 'p',
		'Q' => 'q',
		'R' => 'r',
		'S' => 's',
		'T' => 't',
		'U' => 'u',
		'V' => 'v',
		'W' => 'w',
		'X' => 'x',
		'Y' => 'y',
		'Z' => 'z',
	);

	/**
	 * Lower-case a–z → A–Z.
	 *
	 * The pair form of strtr(): on PHP 7.4 it is several times faster than
	 * strtr( $s, 'A…Z', 'a…z' ), which rebuilds a 256-byte table per call.
	 */
	const TO_UPPER = array(
		'a' => 'A',
		'b' => 'B',
		'c' => 'C',
		'd' => 'D',
		'e' => 'E',
		'f' => 'F',
		'g' => 'G',
		'h' => 'H',
		'i' => 'I',
		'j' => 'J',
		'k' => 'K',
		'l' => 'L',
		'm' => 'M',
		'n' => 'N',
		'o' => 'O',
		'p' => 'P',
		'q' => 'Q',
		'r' => 'R',
		's' => 'S',
		't' => 'T',
		'u' => 'U',
		'v' => 'V',
		'w' => 'W',
		'x' => 'X',
		'y' => 'Y',
		'z' => 'Z',
	);

	/**
	 * Default word delimiters of ucwords().
	 */
	const WORD_DELIMITERS = " \t\r\n\f\v";

	/**
	 * Whether the native case functions are already locale-free (PHP 8.2+).
	 *
	 * @since 8.0.39
	 *
	 * @return bool
	 */
	public static function native_is_ascii(): bool {
		return PHP_VERSION_ID >= 80200;
	}

	/**
	 * Lower-case A–Z only; every other byte is kept.
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

		return PHP_VERSION_ID >= 80200 ? strtolower( $value ) : strtr( $value, self::TO_LOWER );
	}

	/**
	 * Upper-case a–z only; every other byte is kept.
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

		return PHP_VERSION_ID >= 80200 ? strtoupper( $value ) : strtr( $value, self::TO_UPPER );
	}

	/**
	 * Upper-case the first byte when it is a–z (PHP 8.2 ucfirst()).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function ucfirst( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}
		if ( PHP_VERSION_ID >= 80200 ) {
			return ucfirst( $value ); // locale-safe: ASCII-only since PHP 8.2.
		}

		if ( '' !== $value && $value[0] >= 'a' && $value[0] <= 'z' ) {
			$value[0] = chr( ord( $value[0] ) - 32 );
		}

		return $value;
	}

	/**
	 * Upper-case a–z at the start and after every delimiter byte (PHP 8.2
	 * ucwords()).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed  $value      Text (scalars are cast; arrays/objects give '').
	 * @param string $delimiters Word-delimiter bytes (ucwords() default).
	 * @return string
	 */
	public static function ucwords( $value, string $delimiters = self::WORD_DELIMITERS ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}
		if ( PHP_VERSION_ID >= 80200 ) {
			return ucwords( $value, $delimiters ); // locale-safe: ASCII-only since PHP 8.2.
		}
		// Under the C / POSIX locale toupper() maps exactly a–z on every libc,
		// so the native function is the fast exact answer (the usual host).
		// setlocale() can be in disable_functions (removed on PHP 8): no fast path then.
		$ctype = function_exists( 'setlocale' ) ? setlocale( LC_CTYPE, '0' ) : false; // locale-safe: a query — '0' never changes the locale.
		if ( 'C' === $ctype || 'POSIX' === $ctype ) {
			return ucwords( $value, $delimiters ); // locale-safe: C / POSIX locale (checked above).
		}

		// Same positions as php_ucwords(): byte 0, then the byte right after
		// each delimiter byte. strcspn() jumps a whole word at a time.
		$length = strlen( $value );
		$i      = 0;
		while ( $i < $length ) {
			$byte = $value[ $i ];
			if ( $byte >= 'a' && $byte <= 'z' ) {
				$value[ $i ] = chr( ord( $byte ) - 32 );
			}
			$i += strcspn( $value, $delimiters, $i ) + 1;
		}

		return $value;
	}

	/**
	 * Case-insensitive (ASCII) substring test — stripos() as on PHP 8.2+.
	 *
	 * An empty needle follows the running PHP version, exactly like
	 * stripos(): no match on PHP 7.x, a match on PHP 8.x (the warning PHP
	 * 7.x printed is not repeated).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $haystack Text searched.
	 * @param mixed $needle   Text looked for.
	 * @return bool
	 */
	public static function contains_ci( $haystack, $needle ): bool {
		if ( ! is_string( $haystack ) ) {
			$haystack = self::text( $haystack );
		}
		if ( ! is_string( $needle ) ) {
			$needle = self::text( $needle );
		}

		if ( '' === $needle ) {
			return PHP_VERSION_ID >= 80000;
		}
		if ( PHP_VERSION_ID >= 80200 ) {
			return false !== stripos( $haystack, $needle );
		}

		return false !== strpos( strtr( $haystack, self::TO_LOWER ), strtr( $needle, self::TO_LOWER ) );
	}

	/**
	 * Case-insensitive (ASCII) equality.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $a First text.
	 * @param mixed $b Second text.
	 * @return bool
	 */
	public static function equals_ci( $a, $b ): bool {
		return self::lower( $a ) === self::lower( $b );
	}

	/**
	 * The string a native string function would have received.
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
