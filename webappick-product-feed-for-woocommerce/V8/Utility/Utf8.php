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
 * CBT-817 adds is_valid(), from_windows1252() (output type "UTF-8 Encode",
 * code 3) and clean() — scrub plus the control-character strip
 * (CONTROL_PATTERN), the two steps OutputFormatter runs and the rows that
 * bypass it call (Google review entries, Skroutz nested variations).
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
	 * Control characters a feed value never carries: C0 except tab, line
	 * feed and carriage return (XML 1.0 forbids them; XFRM-FRD-8.2), and the
	 * C1 controls U+0080–U+009F (CBT-817 D10 — invisible, and the sign of a
	 * Windows-1252 text decoded as Latin-1). Byte mode on valid UTF-8: \xC2
	 * is only ever a lead byte there, so `\xC2[\x80-\x9F]` is exactly
	 * U+0080–U+009F.
	 *
	 * @since 8.0.39
	 */
	const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xC2[\x80-\x9F]/';

	/**
	 * Windows-1252 bytes 0x80–0x9F as UTF-8 (WHATWG Encoding Standard). The
	 * five bytes Windows-1252 leaves undefined (0x81, 0x8D, 0x8F, 0x90,
	 * 0x9D) map to the C1 control of the same number, which clean() and
	 * OutputFormatter then drop. 0xA0–0xFF are Latin-1 (U+00A0–U+00FF).
	 *
	 * @since 8.0.39
	 */
	const WINDOWS_1252_HIGH = array(
		"\x80" => "\xE2\x82\xAC", // U+20AC euro sign.
		"\x81" => "\xC2\x81", // C1 control (undefined).
		"\x82" => "\xE2\x80\x9A", // U+201A.
		"\x83" => "\xC6\x92", // U+0192.
		"\x84" => "\xE2\x80\x9E", // U+201E.
		"\x85" => "\xE2\x80\xA6", // U+2026 ellipsis.
		"\x86" => "\xE2\x80\xA0", // U+2020.
		"\x87" => "\xE2\x80\xA1", // U+2021.
		"\x88" => "\xCB\x86", // U+02C6.
		"\x89" => "\xE2\x80\xB0", // U+2030.
		"\x8A" => "\xC5\xA0", // U+0160.
		"\x8B" => "\xE2\x80\xB9", // U+2039.
		"\x8C" => "\xC5\x92", // U+0152.
		"\x8D" => "\xC2\x8D", // C1 control (undefined).
		"\x8E" => "\xC5\xBD", // U+017D.
		"\x8F" => "\xC2\x8F", // C1 control (undefined).
		"\x90" => "\xC2\x90", // C1 control (undefined).
		"\x91" => "\xE2\x80\x98", // U+2018.
		"\x92" => "\xE2\x80\x99", // U+2019.
		"\x93" => "\xE2\x80\x9C", // U+201C.
		"\x94" => "\xE2\x80\x9D", // U+201D.
		"\x95" => "\xE2\x80\xA2", // U+2022.
		"\x96" => "\xE2\x80\x93", // U+2013 en dash.
		"\x97" => "\xE2\x80\x94", // U+2014 em dash.
		"\x98" => "\xCB\x9C", // U+02DC.
		"\x99" => "\xE2\x84\xA2", // U+2122 trade mark.
		"\x9A" => "\xC5\xA1", // U+0161.
		"\x9B" => "\xE2\x80\xBA", // U+203A.
		"\x9C" => "\xC5\x93", // U+0153.
		"\x9D" => "\xC2\x9D", // C1 control (undefined).
		"\x9E" => "\xC5\xBE", // U+017E.
		"\x9F" => "\xC5\xB8", // U+0178.
	);

	/**
	 * Full byte => UTF-8 map for from_windows1252(), built on first use.
	 *
	 * @var array<string, string>|null
	 */
	private static $windows_1252 = null;

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

	/**
	 * Whether $value is well-formed UTF-8 ('' is).
	 *
	 * @since 8.0.39
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return '' === $value || 1 === preg_match( '//u', $value );
	}

	/**
	 * Read $value as Windows-1252 and return it as UTF-8 (CBT-817 D9).
	 *
	 * This is what a non-UTF-8 product text almost always is: Latin-1 plus
	 * the Windows "smart" punctuation in 0x80–0x9F (“ ” – € ™), which a
	 * Latin-1 reading turns into invisible C1 controls. A byte table, not
	 * mbstring: the same bytes on every PHP version (mbstring writes U+FFFD
	 * for an undefined byte on PHP 7.4 and "?" on 8.1+) and on hosts
	 * without mbstring. The whole string is read as Windows-1252 — call it
	 * only when is_valid() is false.
	 *
	 * @since 8.0.39
	 *
	 * @param string $value Windows-1252 / Latin-1 text.
	 * @return string UTF-8.
	 */
	public static function from_windows1252( string $value ): string {
		if ( null === self::$windows_1252 ) {
			$map = self::WINDOWS_1252_HIGH;
			for ( $byte = 0xA0; $byte <= 0xFF; $byte++ ) {
				$map[ chr( $byte ) ] = chr( 0xC0 | ( $byte >> 6 ) ) . chr( 0x80 | ( $byte & 0x3F ) );
			}
			self::$windows_1252 = $map;
		}

		return strtr( $value, self::$windows_1252 );
	}

	/**
	 * Valid UTF-8 without control characters: scrub(), then drop C0 (except
	 * tab / line feed / carriage return) and C1 controls (CONTROL_PATTERN).
	 *
	 * OutputFormatter runs the same two steps (inlined) on every string
	 * value; rows written without OutputFormatter (Google review entries,
	 * Skroutz nested variations) call this at their source (CBT-817 D8).
	 *
	 * @since 8.0.39
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function clean( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$value    = self::scrub( $value );
		$stripped = preg_replace( self::CONTROL_PATTERN, '', $value );

		return null === $stripped ? $value : $stripped;
	}
}
