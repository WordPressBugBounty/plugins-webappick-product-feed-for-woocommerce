<?php
/**
 * Html — HTML entity helpers with explicit flags and an explicit UTF-8
 * charset (CBT-817).
 *
 * The native html_entity_decode(), htmlentities(), htmlspecialchars() and
 * htmlspecialchars_decode() called without flags or a charset behave
 * differently by PHP version and by host:
 *
 * - Below PHP 8.1 the default flags are ENT_COMPAT | ENT_HTML401: `'` is
 *   never encoded and `&#039;` / `&#39;` / `&#x27;` are never decoded, so
 *   "Men&#039;s" shipped literally on PHP 7.4/8.0 and as "Men's" on 8.1+;
 *   without ENT_SUBSTITUTE, htmlentities() / htmlspecialchars() return ''
 *   for a value with one invalid UTF-8 byte — the whole value is lost.
 * - Without the charset argument the functions follow the
 *   `default_charset` ini. A plugin or host that sets it to ISO-8859-1
 *   makes html_entity_decode( '&eacute;' ) write the single byte 0xE9 —
 *   invalid UTF-8 that makes libxml reject the whole XML feed.
 *
 * Every helper here passes ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 and
 * 'UTF-8' — PHP 8.1's defaults on a UTF-8 host — so the bytes are the same
 * on every PHP version and under every `default_charset`. On PHP 8.1+ with
 * the default UTF-8 charset nothing changes; PHP 7.4/8.0 now behave like
 * 8.1 (owner decision #5).
 *
 * encode() / special() deliberately do NOT scrub invalid UTF-8 first
 * (Part 2 amendment 24): ENT_SUBSTITUTE writes U+FFFD for an invalid byte,
 * exactly as PHP 8.1+ does today, so 8.1+ output stays byte-identical. The
 * output-type codes scrub once per row before they run (D5).
 *
 * strip_all_tags() / strip_comments() (CBT-822) remove tags and comments
 * without a regex that can fail: core's wp_strip_all_tags() and the
 * `<!--.*?-->` strip returned null on a long block once
 * `pcre.backtrack_limit` was reached, and the value shipped ''.
 *
 * Implements CBT-809 plan §1.2 (Html rows), §2 D1–D4, E1, E2.
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
 * Explicit-flag, explicit-UTF-8 HTML entity helpers.
 *
 * @since 8.0.39
 */
final class Html {

	/**
	 * PHP 8.1's default entity flags.
	 */
	const FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401;

	/**
	 * The one charset feed values are computed in.
	 */
	const CHARSET = 'UTF-8';

	/**
	 * The regex of wp_strip_all_tags(), '@<(script|style)[^>]*?>.*?</\1>@si' with ASCII
	 * letter classes instead of /i: identical under the C locale, and PCRE
	 * locale tables (a Turkish locale makes /i miss "<SCRIPT>") play no part.
	 */
	const BLOCKS = '@<(?:[sS][cC][rR][iI][pP][tT][^>]*?>.*?</[sS][cC][rR][iI][pP][tT]>|[sS][tT][yY][lL][eE][^>]*?>.*?</[sS][tT][yY][lL][eE]>)@s';

	/**
	 * Decode every HTML entity (named, decimal, hex) to UTF-8.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function decode( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return html_entity_decode( $value, self::FLAGS, self::CHARSET );
	}

	/**
	 * Encode every character that has an HTML entity (htmlentities()).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function encode( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return htmlentities( $value, self::FLAGS, self::CHARSET );
	}

	/**
	 * Encode the five HTML special characters & < > " ' (htmlspecialchars()).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function special( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return htmlspecialchars( $value, self::FLAGS, self::CHARSET );
	}

	/**
	 * Decode the special-character entities only (htmlspecialchars_decode()):
	 * &amp; &lt; &gt; &quot; and the single-quote forms &#039; / &#39; / &#x27;.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function special_decode( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		return htmlspecialchars_decode( $value, self::FLAGS );
	}

	/**
	 * Strip every HTML tag the way wp_strip_all_tags() does — and never
	 * return '' because a regex failed (CBT-822, plan §1.2 + §2 E1).
	 *
	 * Core removes <script> / <style> blocks with
	 * preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si' ) and then runs
	 * strip_tags() + trim(). On a long block — or a long text after an
	 * unclosed one — the lazy `.*?` exhausts `pcre.backtrack_limit`,
	 * preg_replace() returns null and strip_tags( null ) gives '': the whole
	 * description (or review, or command result) left the feed empty.
	 *
	 * Here the same regex runs first (spelled with ASCII letter classes
	 * instead of /i — the same match under the C locale, and PCRE locale
	 * tables play no part), so a normal value costs what core's did and its
	 * bytes are core's. When PCRE gives up, a linear strpos() loop removes
	 * exactly the blocks the regex would have: an opener is "<" + "script" /
	 * "style" in any ASCII case + everything up to the next ">", closed by
	 * the first "</script>" / "</style>" (any ASCII case) after it; an opener
	 * with no closer stays for strip_tags(), as when the regex finds no
	 * match. The failure is logged once per request and counted for the
	 * running feed batch (Regex::log_failure()).
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function strip_all_tags( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}
		if ( false !== strpos( $value, '<' ) ) {
			// Core's regex, spelled with explicit letter classes instead of
			// /i (the same match under the C locale; PCRE locale tables play
			// no part). When PCRE gives up, the linear copy below removes
			// exactly what the regex would have.
			$stripped = preg_replace( self::BLOCKS, '', $value );
			if ( null === $stripped ) {
				Regex::log_failure( 'Html::strip_all_tags()', self::BLOCKS, preg_last_error(), 'the <script> / <style> blocks were removed without the regex; the text was kept' );
				$stripped = self::strip_script_style_blocks( $value );
			}
			$value = $stripped;
		}

		// strip_tags() is a linear state machine (it never fails); core runs it on every value, so does this.
		return trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- This IS wp_strip_all_tags() minus its failing regex; the blocks were removed above.
	}

	/**
	 * Remove every HTML comment `<!-- … -->` — preg_replace( '/<!--.*?-->/s' )
	 * without the regex (CBT-822, plan §1.2 + §2 E2).
	 *
	 * A long comment (a page-builder block delimiter carrying JSON, or an
	 * unclosed `<!--` before a long text) exhausted `pcre.backtrack_limit`
	 * and the `(string)` cast of the null made the value ''. A comment
	 * ends at the first "-->" after its "<!--" (so "<!---->" is one
	 * comment); an unclosed "<!--" and everything after it are kept — the
	 * regex's result when it succeeds. Linear, never fails.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Text (scalars are cast; arrays/objects give '').
	 * @return string
	 */
	public static function strip_comments( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = self::text( $value );
		}

		$open = strpos( $value, '<!--' );
		if ( false === $open ) {
			return $value;
		}

		$out = '';
		$pos = 0;
		while ( false !== $open ) {
			$close = strpos( $value, '-->', $open + 4 );
			if ( false === $close ) {
				break;
			}
			$out .= (string) substr( $value, $pos, $open - $pos );
			$pos  = $close + 3;
			$open = strpos( $value, '<!--', $pos );
		}

		return $out . (string) substr( $value, $pos );
	}

	/**
	 * Remove closed <script> / <style> blocks exactly as core's
	 * '@<(script|style)[^>]*?>.*?</\1>@si' does when it succeeds.
	 *
	 * Offsets come from an A-Z-lowered copy (same length, same offsets);
	 * the bytes copied out come from the original. The next "<script" and
	 * the next "<style" are cached and searched again only once a removed
	 * block has passed them. Once the closer of a name is missing after one
	 * opener it is missing after every later one (a later opener's ">" is
	 * never earlier), so that name is dropped — every byte is scanned a
	 * bounded number of times and the loop stays linear.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function strip_script_style_blocks( string $value ): string {
		// A-Z-lowered copy of the same length (three-argument strtr(): a
		// byte table — ASCII only, no locale, linear on long texts).
		$hay = strtr( $value, Ascii::UPPER, Ascii::LOWER );
		$at  = array(
			'script' => strpos( $hay, '<script' ),
			'style'  => strpos( $hay, '<style' ),
		);

		$out = '';
		$pos = 0;
		while ( false !== $at['script'] || false !== $at['style'] ) {
			// The nearest opener, as the regex scans left to right.
			$name = ( false === $at['style'] || ( false !== $at['script'] && $at['script'] < $at['style'] ) ) ? 'script' : 'style';
			$open = (int) $at[ $name ];

			$tag_end = strpos( $hay, '>', $open + 1 + strlen( $name ) );
			if ( false === $tag_end ) {
				break; // No ">" left: no later opener can complete either.
			}

			$close = strpos( $hay, '</' . $name . '>', $tag_end + 1 );
			if ( false === $close ) {
				$at[ $name ] = false; // No later opener of this name can close.
				continue;
			}

			$out .= (string) substr( $value, $pos, $open - $pos );
			$pos  = $close + strlen( $name ) + 3;
			foreach ( $at as $other => $at_pos ) {
				if ( false !== $at_pos && $at_pos < $pos ) {
					$at[ $other ] = strpos( $hay, '<' . $other, $pos );
				}
			}
		}

		return $out . (string) substr( $value, $pos );
	}

	/**
	 * The string a native entity function would have received.
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
