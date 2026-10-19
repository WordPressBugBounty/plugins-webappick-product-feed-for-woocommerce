<?php
/**
 * NumberFormat — the feed's price number format, V5 semantics.
 *
 * Port of V5 `Utility\Config::get_number_format()` (CBT-635 / CBT-640, owner
 * decision 2026-09-23: price formatting must behave as V5 did, free and Pro)
 * with the FREE half only. The base is the MACHINE format — 2 decimals, "."
 * decimal separator, NO thousand separator ("173427.98", CBT-641): safe for
 * every channel, unlike the store's WooCommerce display settings V5 used
 * ("1,099.90 USD" on US stores, "16.980" on HUF stores).
 *
 * The Pro branch — the feed's Filters-tab boxes, applied only for the boxes
 * the merchant filled in — lives in the Pro plugin
 * (`CTXFeed\Pro\Engine\NumberFormatOverrides`, CBT-643) and reaches this
 * resolver through the V5 `ctx_feed_number_format` filter. This file never
 * reads the boxes, so a comma seeded into a V5-era feed can never reach a
 * free user's file (wordpress.org "Feed generation ignores WP default
 * thousand separator", CBT-640). The boxes are never pre-populated.
 *
 * This triple is consumed ONLY by the "Price" (6) and "Rounded Price" (7)
 * output types and by custom2 dynamic-attribute results — exactly the three
 * places V5 used it. Plain price rows ship the raw WooCommerce value. (The
 * ChatGPT sale-price check also reads it, only to parse code-6 text back.)
 *
 * Idealo is the one channel with a FIXED format: `IdealoTransform::
 * fixed_number_format()` returns 2 / "." / none on this filter at priority
 * 20, after the Pro boxes (V5 `Override/IdealoTemplate.php` parity, CBT-811).
 *
 * {@see to_machine()} is the other half (CBT-816): the text a computed float
 * becomes on its way into a feed value — the bytes PHP 8 prints at the
 * default `precision` (14), never the PHP 7 `LC_NUMERIC` decimal comma or
 * another plugin's `precision`.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.27
 */

namespace CTXFeed\V8\Utility;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the feed's number format triple.
 *
 * @since 8.0.27
 */
final class NumberFormat {

	/**
	 * Option-key prefix of a dynamic attribute (Pro engine).
	 *
	 * @since 8.0.39
	 * @var string
	 */
	const DYNAMIC_ATTRIBUTE_PREFIX = 'wf_dattribute_';

	/**
	 * Option-key prefix of an attribute mapping (Pro engine).
	 *
	 * @since 8.0.39
	 * @var string
	 */
	const ATTRIBUTE_MAPPING_PREFIX = 'wp_attr_mapping_';

	/**
	 * How many attribute mappings {@see is_formatted_source()} reads at most.
	 *
	 * @since 8.0.39
	 * @var int
	 */
	const FORMATTED_SOURCE_MAX_MAPPINGS = 50;

	/**
	 * The `precision` ini value PHP ships with: every feed number on a
	 * default host was written with it ({@see to_machine()}).
	 *
	 * @since 8.0.39
	 * @var string
	 */
	const MACHINE_PRECISION = '14';

	/**
	 * Deepest array level {@see floats_to_machine()} walks (feed values are a
	 * few levels deep; the cap only stops a self-referencing array).
	 *
	 * @since 8.0.39
	 * @var int
	 */
	const FLOATS_MAX_DEPTH = 32;

	/**
	 * Whether ini_get() / ini_set() can be called (a host can disable them).
	 * Probed once per request.
	 *
	 * @var bool|null
	 */
	private static $can_pin_precision = null;

	/**
	 * Whether localeconv() can be called. Probed once per request.
	 *
	 * @var bool|null
	 */
	private static $can_read_locale = null;

	/**
	 * Resolve `[ decimals, decimal_separator, thousand_separator ]` for a feed.
	 *
	 * V5 `Config::get_number_format()`, free half:
	 *  - base = 2 decimals, `.`, no thousands (MACHINE format, CBT-641) — V5
	 *    used the store's WooCommerce display settings here;
	 *  - the array then passes through the V5 `ctx_feed_number_format` filter,
	 *    where the Pro engine applies the filled Filters-tab boxes (priority
	 *    10, CBT-643) and the Idealo channel forces its fixed triple (priority
	 *    20, CBT-811 — V5 IdealoTemplate parity).
	 *
	 * Separators are decoded with `wp_specialchars_decode( wp_unslash() )`
	 * so a slashed or entity-encoded form value (`&nbsp;`) formats correctly,
	 * as V5 `FormatOutput::get_price_format()` did.
	 *
	 * @since 8.0.27
	 * @implements XFRM-FRD-4.6 (codes 6/7), PROD-FRD-10 (custom2 dynamic attributes)
	 * @hook ctx_feed_number_format V5 filter, the resolved array (the Pro engine applies the boxes here).
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array{decimals:int, decimal_separator:string, thousand_separator:string}
	 */
	public static function resolve( Config $config ): array {
		$number_format = array(
			'decimal_separator'  => '.',
			'thousand_separator' => '',
			'decimals'           => 2,
		);

		/**
		 * Filter the resolved feed number format (V5 hook).
		 *
		 * @since 8.0.27
		 *
		 * @param array  $number_format { decimals, decimal_separator, thousand_separator }.
		 * @param Config $config        Feed configuration.
		 */
		$number_format = (array) apply_filters( 'ctx_feed_number_format', $number_format, $config ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- V5 hook name kept verbatim for third-party parity.

		return array(
			'decimals'           => isset( $number_format['decimals'] ) ? (int) $number_format['decimals'] : 2,
			'decimal_separator'  => self::decode( isset( $number_format['decimal_separator'] ) ? (string) $number_format['decimal_separator'] : '.' ),
			'thousand_separator' => self::decode( isset( $number_format['thousand_separator'] ) ? (string) $number_format['thousand_separator'] : '' ),
		);
	}

	/**
	 * Format a value the way V5 `FormatOutput::get_price_format()` did.
	 *
	 * @since 8.0.27
	 *
	 * @param float $number Parsed numeric value.
	 * @param array $nf     Triple from {@see resolve()}.
	 *
	 * @return string
	 */
	public static function format( float $number, array $nf ): string {
		return number_format( $number, (int) $nf['decimals'], (string) $nf['decimal_separator'], (string) $nf['thousand_separator'] );
	}

	/**
	 * A value as the text a feed carries: floats in machine form.
	 *
	 * A float cast to text follows two process-wide settings another plugin
	 * or the host can leave changed (CBT-809 §1.2, B1–B8):
	 *  - `precision` on every PHP version — at 17, `23.79` prints
	 *    `23.789999999999999`, `10.1 + 20.2` prints `30.299999999999997`;
	 *  - `LC_NUMERIC` on PHP 7 — under `de_DE`, `23.79` prints `23,79`
	 *    (and `(float) '23,79'` reads back `23`).
	 * The FeedRuntime guard pins both for generation and previews, but not a
	 * value a plugin changes mid-batch, ZTS hosts' locale, admin/REST paths or
	 * Pro beside an older free (§1.1.6), so every feed site that turns a
	 * float into text calls this instead.
	 *
	 * Contract: a float gives exactly what `(string) $float` gives on PHP 8
	 * at `precision` 14 — byte-identical to every earlier run on a default
	 * host. When `precision` is not `'14'` it is set to 14 for the one cast
	 * and put back (try/finally); on PHP < 8 a non-numeric result has the
	 * locale's decimal point replaced by `.` (localeconv() is read only
	 * then). Never `wc_float_to_string()` (follows `precision`, third-party
	 * symbol), `sprintf( '%.nF' )` (changes unrounded tax output) or `%G`
	 * (follows LC_NUMERIC).
	 *
	 * Every other value is cast as `(string)` casts it (strings unchanged,
	 * null → '', true → '1', ints, `__toString()` objects); an array or an
	 * object without `__toString()` gives '' (a cast would print `Array` with
	 * a warning, or throw on PHP 8).
	 *
	 * @since 8.0.39
	 * @implements CBT-809 §1.2 (NumberFormat::to_machine), CBT-816
	 *
	 * @param mixed $value Value.
	 *
	 * @return string
	 */
	public static function to_machine( $value ): string {
		if ( ! is_float( $value ) ) {
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

		// Inline fast path (one ini_get): the default precision casts as is.
		if ( null === self::$can_pin_precision ) {
			self::$can_pin_precision = function_exists( 'ini_get' ) && function_exists( 'ini_set' );
		}
		$precision = self::$can_pin_precision ? ini_get( 'precision' ) : false;
		$text      = ( self::MACHINE_PRECISION === $precision || ! is_string( $precision ) )
			? (string) $value
			: self::cast_at_machine_precision( $value, $precision );

		// PHP 7 writes the LC_NUMERIC decimal point; PHP 8 always writes '.'.
		// A machine text is numeric, so the locale is read only when it is not.
		if ( PHP_VERSION_ID < 80000 && ! is_numeric( $text ) ) {
			$text = self::machine_decimal_point( $text );
		}

		return $text;
	}

	/**
	 * Every float in a value — the value itself or any array leaf — as
	 * {@see to_machine()} text; everything else untouched.
	 *
	 * Ints, null, bools, strings and objects keep their type and value (B8,
	 * amendment 5: floats only). An array without a float is returned as is.
	 *
	 * @since 8.0.39
	 * @implements CBT-809 §2 B8, CBT-816
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	public static function floats_to_machine( $value ) {
		if ( is_float( $value ) ) {
			return self::to_machine( $value );
		}

		return is_array( $value ) ? self::array_floats_to_machine( $value, 1 ) : $value;
	}

	/**
	 * {@see floats_to_machine()} for an array, level `$depth`.
	 *
	 * @param array $values Array.
	 * @param int   $depth  Current level (1 = the value itself).
	 *
	 * @return array
	 */
	private static function array_floats_to_machine( array $values, int $depth ): array {
		foreach ( $values as $key => $leaf ) {
			if ( is_float( $leaf ) ) {
				$values[ $key ] = self::to_machine( $leaf );
			} elseif ( is_array( $leaf ) && $depth < self::FLOATS_MAX_DEPTH ) {
				$mapped = self::array_floats_to_machine( $leaf, $depth + 1 );
				if ( $mapped !== $leaf ) {
					$values[ $key ] = $mapped;
				}
			}
		}

		return $values;
	}

	/**
	 * `(string) $value` at `precision` 14 when the host runs another one.
	 *
	 * @param float  $value     Float.
	 * @param string $precision The host's `precision`, put back afterwards.
	 *
	 * @return string
	 */
	private static function cast_at_machine_precision( float $value, string $precision ): string {
		// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- One cast at PHP's default precision; the host value is put back in finally (CBT-816).
		if ( false === ini_set( 'precision', self::MACHINE_PRECISION ) ) {
			return (string) $value;
		}

		try {
			return (string) $value;
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Puts back the precision the host had before the cast (CBT-816).
			ini_set( 'precision', $precision );
		}
	}

	/**
	 * Replace the LC_NUMERIC decimal point (PHP < 8 float text) with `.`.
	 *
	 * PHP 7 writes the first byte of `localeconv()['decimal_point']`.
	 *
	 * @param string $text Float text.
	 *
	 * @return string
	 */
	private static function machine_decimal_point( string $text ): string {
		if ( null === self::$can_read_locale ) {
			self::$can_read_locale = function_exists( 'localeconv' );
		}
		if ( ! self::$can_read_locale ) {
			return $text;
		}

		$locale = localeconv();
		$point  = isset( $locale['decimal_point'] ) ? substr( (string) $locale['decimal_point'], 0, 1 ) : '.';

		return ( '' === $point || '.' === $point ) ? $text : str_replace( $point, '.', $text );
	}

	/**
	 * Whether a feed-row source yields text this pipeline already formatted.
	 *
	 * On `custom2` the Pro dynamic-attribute engine writes a numeric row
	 * result with {@see format()} (V5 `DynamicAttributes.php:239-243`), so a
	 * row sourced from a dynamic attribute carries FORMATTED text ("1.499"
	 * for 1499 at 0 / "," / "."), which must be read back with the feed's
	 * separators (`LocalizedNumber::parse()`), never as a machine number
	 * (#68878). Every other source is raw (CBT-811).
	 *
	 * True for a `wf_dattribute_*` source, and for a `wp_attr_mapping_*`
	 * source whose stored mapping holds a `wf_dattribute_*` part at any depth
	 * (mapping inside mapping). A mapping without one stays raw — never widen
	 * this to every mapping, or a raw 3-decimal price is read as thousands
	 * again. Parts saved as Text are literal and never count. Each mapping is
	 * read once per call (a cycle stops there), at most
	 * {@see FORMATTED_SOURCE_MAX_MAPPINGS} of them. Callers memoise the answer
	 * per feed config; the option reads come from the WordPress options cache.
	 *
	 * Known limit: this answers per SOURCE, not per value. A dynamic attribute
	 * that returns a raw value (its Otherwise output, a non-numeric condition,
	 * Text) still counts as formatted here, as it did up to 8.0.38. The Pro
	 * engine decides its own dynamic-attribute-inside-dynamic-attribute case
	 * per value and does not use this method.
	 *
	 * @since 8.0.39
	 * @implements XFRM-FRD-4.6, PROD-FRD-10 (CBT-811)
	 *
	 * @param string $attr Row source attribute key.
	 *
	 * @return bool
	 */
	public static function is_formatted_source( string $attr ): bool {
		$visited = array();
		return self::holds_dynamic_attribute( $attr, $visited );
	}

	/**
	 * Walk an attribute key (and nested mappings) for a dynamic-attribute part.
	 *
	 * @since 8.0.39
	 *
	 * @param string             $attr    Attribute key.
	 * @param array<string,bool> $visited Mapping keys already read in this walk.
	 *
	 * @return bool
	 */
	private static function holds_dynamic_attribute( string $attr, array &$visited ): bool {
		if ( 0 === strpos( $attr, self::DYNAMIC_ATTRIBUTE_PREFIX ) ) {
			return true;
		}

		if (
			0 !== strpos( $attr, self::ATTRIBUTE_MAPPING_PREFIX )
			|| isset( $visited[ $attr ] )
			|| count( $visited ) >= self::FORMATTED_SOURCE_MAX_MAPPINGS
			|| ! function_exists( 'get_option' )
		) {
			return false;
		}
		$visited[ $attr ] = true;

		$mapping = get_option( $attr, false );
		if ( ! is_array( $mapping ) || empty( $mapping['mapping'] ) ) {
			return false;
		}

		$items = array_values( (array) $mapping['mapping'] );
		$types = isset( $mapping['types'] ) && is_array( $mapping['types'] ) && count( $mapping['types'] ) === count( $items )
			? array_values( $mapping['types'] )
			: array();

		foreach ( $items as $index => $item ) {
			if ( ! is_string( $item ) || '' === $item || ( isset( $types[ $index ] ) && 'text' === $types[ $index ] ) ) {
				continue;
			}
			if ( self::holds_dynamic_attribute( $item, $visited ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode a stored separator (`wp_specialchars_decode( wp_unslash() )`, V5).
	 *
	 * @since 8.0.27
	 *
	 * @param string $separator Stored separator.
	 *
	 * @return string
	 */
	private static function decode( string $separator ): string {
		if ( function_exists( 'wp_specialchars_decode' ) && function_exists( 'wp_unslash' ) ) {
			return (string) wp_specialchars_decode( wp_unslash( $separator ) );
		}
		return $separator;
	}
}
