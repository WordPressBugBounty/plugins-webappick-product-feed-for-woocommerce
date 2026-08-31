<?php
/**
 * Sanitizer — Centralized sanitization for feed data.
 *
 * Provides feed-specific sanitization methods for values, names,
 * configuration arrays, and URLs. Used by Feed, API, and Admin
 * modules to ensure consistent data cleaning.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-3.1, UTIL-FRD-3.2, UTIL-FRD-3.3, UTIL-FRD-3.4
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed data sanitization service.
 *
 * @since 8.0.0
 */
class Sanitizer {

	/**
	 * Normalize a feed name to a URL-safe slug.
	 *
	 * Processing: lowercase → replace non-alnum (except dash/underscore) with
	 * dashes → collapse consecutive dashes → trim leading/trailing dashes.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-3.1
	 *
	 * @param string $name Raw feed name.
	 *
	 * @return string URL-safe slug matching [a-z0-9][a-z0-9_-]*[a-z0-9].
	 */
	public function feed_name( string $name ): string {

		// Lowercase.
		$slug = strtolower( trim( $name ) );

		// Replace non-alphanumeric characters (except dash and underscore) with dashes.
		$slug = preg_replace( '/[^a-z0-9_-]/', '-', $slug );

		// Collapse consecutive dashes.
		$slug = preg_replace( '/-+/', '-', $slug );

		// Trim leading/trailing dashes.
		$slug = trim( $slug, '-' );

		return $slug;
	}

	/**
	 * Clean a product attribute value for feed output.
	 *
	 * Strips HTML tags, decodes entities, and trims whitespace. The
	 * cleaned value is filterable via `ctxfeed_sanitize_value`.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-3.2
	 * @hook ctxfeed_sanitize_value
	 *
	 * @param string $value Raw feed attribute value.
	 *
	 * @return string Sanitized value.
	 */
	public function feed_value( string $value ): string {

		$original = $value;

		// Strip HTML tags.
		$value = wp_strip_all_tags( $value );

		// Decode HTML entities.
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		// Trim whitespace.
		$value = trim( $value );

		/**
		 * Filters a sanitized feed attribute value.
		 *
		 * @since 8.0.0
		 *
		 * @param string $value    Sanitized value.
		 * @param string $original Original unsanitized value.
		 */
		return apply_filters( 'ctxfeed_sanitize_value', $value, $original );
	}

	/**
	 * Recursively sanitize a feed configuration array.
	 *
	 * String values are sanitized via sanitize_text_field(). Arrays are
	 * recursed. Non-string, non-array values (int, bool, null) are preserved.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-3.3
	 *
	 * @param array $config Raw configuration array.
	 *
	 * @return array Sanitized configuration array.
	 */
	public function config( array $config ): array {

		$sanitized = array();

		foreach ( $config as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->config( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Validate and sanitize a URL.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-3.4
	 *
	 * @param string $url Raw URL string.
	 *
	 * @return string Sanitized URL, or empty string if invalid.
	 */
	public function url( string $url ): string {

		return esc_url_raw( $url );
	}

	/**
	 * XSS-safe sanitizer that preserves every whitespace and Unicode
	 * byte the user typed.
	 *
	 * `sanitize_text_field()` trims leading/trailing whitespace and
	 * collapses runs of spaces/tabs — fine for headings, disastrous
	 * for anything the user intends to use as a byte-exact pattern:
	 * str_replace search/replace, dynamic-attribute prefix/suffix
	 * (`"Brand: "`, `" USD"`), currency separators, emoji dividers.
	 *
	 * Cleans:
	 *   - `<script>` / `<style>` blocks and any HTML tag (XSS defence)
	 *   - Null bytes (never present in a legit user pattern)
	 *   - Arrays/objects → empty string (defensive, matches WP core sanitizers)
	 *
	 * Preserves:
	 *   - Leading/trailing whitespace and tabs/newlines
	 *   - Consecutive whitespace runs
	 *   - Emoji, accented characters, zero-width chars — anything Unicode
	 *
	 * PROD-FRD-10.9, PROD-FRD-10.12.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw form input (typically string; arrays/objects
	 *                     tolerated for endpoint robustness).
	 * @return string Byte-preserving sanitized string.
	 */
	public static function preserve_whitespace( $value ): string {
		if ( is_object( $value ) || is_array( $value ) ) {
			return '';
		}
		$value = (string) $value;

		// Strip <script>/<style> blocks and any HTML tag (XSS defence).
		// preg_replace on failure returns null → coerce back to string.
		$value = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $value ) ?? $value;
		// phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- The script/style blocks the sniff worries about are already stripped by the preg_replace on the line above (the same pattern wp_strip_all_tags() uses). wp_strip_all_tags() additionally trim()s, which would defeat this method's entire contract of byte-preserving leading/trailing whitespace.
		$value = strip_tags( $value );

		// Null-byte removal — safe defence, never present in a legit
		// user-typed pattern.
		$value = str_replace( "\0", '', $value );

		return $value;
	}

	/**
	 * Unserialize without instantiating objects.
	 *
	 * PHP Object Injection defence for serialized feed options — V5
	 * 6.6.x parity (CTX-908, Feed::safe_unserialize). A serialized
	 * object payload comes back as __PHP_Incomplete_Class instead of a
	 * live object, so gadget chains never execute; plain arrays and
	 * scalars round-trip unchanged. Non-serialized input is returned
	 * as-is (maybe_unserialize semantics).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $data Raw option/row value.
	 *
	 * @return mixed Unserialized data, or the input when not serialized.
	 */
	public static function safe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		if ( ! is_serialized( $data ) ) {
			return $data;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- allowed_classes=false IS the object-injection mitigation (CTX-908). The `@` is required because a truncated or corrupt serialized option emits an E_NOTICE that unserialize() already signals through its `false` return; suppressing keeps one damaged row from spraying notices through every feed run, and callers treat false as "not usable" (maybe_unserialize semantics).
		return @unserialize( $data, array( 'allowed_classes' => false ) );
	}

	/**
	 * Whether an option name is a feed option this plugin owns.
	 *
	 * Guards code paths where an option NAME can be influenced from
	 * outside before being read + unserialized — V5 6.6.x parity
	 * (CTX-908, Feed::is_valid_feed_option).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $option_name Candidate option name.
	 *
	 * @return bool True when the name carries a feed prefix.
	 */
	public static function is_valid_feed_option( $option_name ): bool {
		if ( ! is_string( $option_name ) ) {
			return false;
		}

		foreach ( array( 'wf_feed_', 'wf_config' ) as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
