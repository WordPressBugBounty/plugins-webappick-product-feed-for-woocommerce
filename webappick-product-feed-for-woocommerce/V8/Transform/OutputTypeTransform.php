<?php
/**
 * OutputTypeTransform — V5-compatible per-attribute output_type formatter.
 *
 * V5 customers configured per-attribute formatting via 22 numeric codes
 * (1–22, plus 23/24 for translation parents) in the feed editor. V5's
 * `Output\FormatOutput::get_output()` then applied the matching operation
 * during feed generation. V8 previously implemented only 5 of those codes
 * via `StringTransform::transform()`, and silently dropped the other 17 —
 * users selecting "Format Price", "Rounded Price", "ucwords", "Remove
 * Space", "htmlspecialchars", etc. saw no effect on V8.
 *
 * This transform restores the 19 codes that don't require parent-product
 * re-resolution. Codes 18 (only_parent), 19 (parent), 20 (parent_if_empty),
 * 23 (parent_lang), and 24 (parent_lang_if_empty) need access to a
 * different `WC_Product` than the one being processed — V8's pipeline
 * operates on already-resolved data, so those five are deferred until
 * the pipeline gains product-aware context. V8's existing `VariationResolver`
 * provides automatic empty-value parent fallback at resolve time, which
 * partially covers the common case behind code 20.
 *
 * Storage format is V5-verbatim: parallel arrays in feed config.
 *   `mattributes[i]` = 'price', 'title', 'description', …
 *   `output_type[i]` = '6',     '12',    '2',           …
 *
 * The lookup is rebuilt per-call by zipping mattributes ↔ output_type.
 *
 * Pipeline placement: runs FIRST (before PrefixSuffix) so V5's order of
 *   format value → add prefix/suffix → encode
 * is preserved. Code 6 (format price) needs to run on the raw "99.99"
 * before "$" is prepended; otherwise the prefix breaks numeric parsing.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-4.6
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V5 numeric output_type code dispatcher.
 *
 * @since 8.0.0
 */
class OutputTypeTransform implements TransformInterface {

	/**
	 * Canonical order in which selected format codes are applied to a value.
	 *
	 * V5's `FormatOutput::get_output()` runs a FIXED sequence of `in_array()`
	 * checks, so the selection order in the UI never changes the result — the
	 * block order does. This mirrors that order for the codes handled here
	 * (parent codes 18/19/20/23/24 run upstream in ProductRepository; code 3
	 * — V8's UTF-8 encode, absent from V5's list — slots after 2). CDATA (9)
	 * stays last so it wraps the fully-formatted value, exactly as V5 does.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private const FORMAT_CODE_ORDER = array(
		'2',
		'3',
		'4',
		'5',
		'6',
		'7',
		'8',
		'10',
		'11',
		'12',
		'13',
		'14',
		'15',
		'16',
		'17',
		'21',
		'22',
		'9',
	);

	/**
	 * Split a stored per-row `output_type` value into an array of code strings.
	 *
	 * V5 stored the row as a MULTISELECT array (['2','12']); V8's field was
	 * single-select for a while, so a value can also be a bare code ('2') or a
	 * comma-joined string ('1, 2, 11', as some channel TemplateDefaults still
	 * carry). All three normalise to a trimmed, non-empty array of codes.
	 * Shared with `ProductRepository::apply_parent_output_type()` and the save
	 * sanitiser so every layer reads the row identically.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $raw Stored per-row value (array|string|scalar).
	 * @return string[] Ordered list of code strings (may be empty).
	 */
	public static function normalize_codes( $raw ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} elseif ( is_scalar( $raw ) ) {
			$parts = explode( ',', (string) $raw );
		} else {
			return array();
		}

		$codes = array();
		foreach ( $parts as $part ) {
			$code = trim( (string) $part );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/**
	 * Apply the configured V5 output_type code to each attribute value.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-4.6
	 *
	 * @param array  $product_data Resolved product attributes (string|null values).
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with output_type operations applied per attribute.
	 */
	public function transform( array $product_data, Config $config ): array {
		$lookup = $this->build_lookup( $config );
		if ( empty( $lookup ) ) {
			return $product_data;
		}

		$is_xml = 'xml' === strtolower( (string) $config->get( 'feedType', 'xml' ) );

		// Lazy number-format resolution — only computed if a code 6/7 is
		// actually present in the lookup. Calling wc_get_price_decimals()
		// in environments where WC isn't loaded (and the function exists
		// only as a Brain\Monkey stub from a sibling test) makes the
		// suite order-dependent. Avoid that by computing on first need.
		$nf        = null;
		$nf_loader = function () use ( $config, &$nf ) {
			if ( null === $nf ) {
				$nf = $this->resolve_number_format( $config );
			}
			return $nf;
		};

		foreach ( $product_data as $attr => &$value ) {
			// Strip the ##N duplicate-position suffix ProductRepository
			// adds when the user maps the same merchant attribute twice
			// (e.g., product_detail triplets). The lookup is keyed by the
			// base name — both first and later occurrences share the
			// same output_type. PROD-FRD-10.11.
			$lookup_attr = ProductRepository::strip_dup_suffix( (string) $attr );
			if ( ! isset( $lookup[ $lookup_attr ] ) ) {
				continue;
			}

			// A row can carry SEVERAL codes (V5 multiselect). Normalise to a
			// set, then apply each in V5's fixed order — never the UI's
			// selection order. Codes 1/default and the parent codes
			// (18/19/20/23/24, handled upstream) simply aren't in
			// FORMAT_CODE_ORDER, so they're skipped without a special case.
			$selected = array_flip( self::normalize_codes( $lookup[ $lookup_attr ] ) );
			if ( empty( $selected ) ) {
				continue;
			}

			foreach ( self::FORMAT_CODE_ORDER as $code ) {
				if ( ! isset( $selected[ $code ] ) ) {
					continue;
				}
				$nf_for_code = ( '6' === $code || '7' === $code ) ? $nf_loader() : array();
				$value       = $this->apply_code( $code, $value, $is_xml, $nf_for_code );
			}
		}

		return $product_data;
	}

	/**
	 * Build a `merchant_attribute => code` lookup from feed config.
	 *
	 * V5 stores `output_type` as a positional array zipped with
	 * `mattributes`; V8's React UI saves the same shape. Older test
	 * fixtures may already be keyed by attribute name — both forms are
	 * accepted.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return array<string,string> Map of merchant attr → code.
	 */
	private function build_lookup( Config $config ): array {
		$output_types = (array) $config->get( 'output_type', array() );
		if ( empty( $output_types ) ) {
			return array();
		}

		// String-keyed (V8 test fixture style) — return as-is.
		$keys            = array_keys( $output_types );
		$is_zero_indexed = range( 0, count( $output_types ) - 1 ) === $keys;
		if ( ! $is_zero_indexed ) {
			return $output_types;
		}

		// Parallel-array shape — zip with mattributes.
		$mattributes = (array) $config->get_merchant_attributes();
		$lookup      = array();
		foreach ( $mattributes as $i => $attr ) {
			if ( ! isset( $output_types[ $i ] ) || '' === $attr ) {
				continue;
			}
			$lookup[ (string) $attr ] = $output_types[ $i ];
		}
		return $lookup;
	}

	/**
	 * Resolve number-format settings (decimals, separators) from config,
	 * falling back to WooCommerce defaults.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return array{decimals:int, decimal_separator:string, thousand_separator:string}
	 */
	private function resolve_number_format( Config $config ): array {
		$decimals_raw = $config->get( 'decimals', '' );
		$decimals     = ( '' === $decimals_raw || ! is_numeric( $decimals_raw ) )
			? ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 )
			: (int) $decimals_raw;

		$dec_sep = (string) $config->get( 'decimal_separator', '' );
		if ( '' === $dec_sep ) {
			$dec_sep = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		}

		$thou_sep = (string) $config->get( 'thousand_separator', '' );
		if ( '' === $thou_sep ) {
			$thou_sep = function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',';
		}

		// V5 wp_specialchars_decode the separator strings to allow `&nbsp;`
		// etc. saved from the form. Do the same for compat.
		$dec_sep  = function_exists( 'wp_specialchars_decode' ) ? (string) wp_specialchars_decode( wp_unslash( $dec_sep ) ) : $dec_sep;
		$thou_sep = function_exists( 'wp_specialchars_decode' ) ? (string) wp_specialchars_decode( wp_unslash( $thou_sep ) ) : $thou_sep;

		return array(
			'decimals'           => $decimals,
			'decimal_separator'  => $dec_sep,
			'thousand_separator' => $thou_sep,
		);
	}

	/**
	 * Apply a single V5 output_type code to a value.
	 *
	 * Verbatim port of V5 `Output\FormatOutput::get_output()` (V5 lines
	 * 34–125). Each code's behavior mirrors the V5 source line-for-line.
	 *
	 * @since 8.0.0
	 *
	 * @param string $code   Numeric V5 code as string ('2'..'24').
	 * @param mixed  $value  Current attribute value.
	 * @param bool   $is_xml Whether the feed format is XML (gates code 9).
	 * @param array  $nf     Number-format triple from resolve_number_format().
	 *
	 * @return mixed Transformed value, or the original on no-op / type mismatch.
	 */
	private function apply_code( string $code, $value, bool $is_xml, array $nf ) {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$value = (string) $value;

		switch ( $code ) {
			case '2': // Strip Tags.
				return wp_strip_all_tags( html_entity_decode( $value ) );

			case '3': // UTF-8 Encode.
				if ( function_exists( 'mb_convert_encoding' ) ) {
					$detected = mb_detect_encoding( $value, 'UTF-8, ISO-8859-1, ASCII', true );
					return mb_convert_encoding( $value, 'UTF-8', $detected ? $detected : 'UTF-8' );
				}
				return $value;

			case '4': // htmlentities (UTF-8, ENT_QUOTES).
				return htmlentities( $value, ENT_QUOTES, 'UTF-8' );

			case '5': // Integer cast.
				return (string) (int) $value;

			case '6': // Format Price.
				return $this->format_price( $value, $nf );

			case '7': // Rounded Price.
				if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
					return $value;
				}
				$rounded = is_float( (float) $value ) ? (string) round( (float) $value ) : $value;
				return $this->format_price( $rounded, $nf );

			case '8': // Delete Space.
				return $this->delete_space( $value );

			case '9': // Wrap CDATA (XML only).
				if ( ! $is_xml || '' === $value ) {
					return $value;
				}
				// Strip any existing markers before wrapping fresh.
				$clean = str_replace( array( '<![CDATA[', ']]>' ), '', html_entity_decode( $value ) );
				return '<![CDATA[' . $clean . ']]>';

			case '10': // Remove invalid XML chars.
				return $this->strip_invalid_xml( $value );

			case '11': // Remove ShortCodes — V5 CommonHelper::remove_shortcodes parity.
				return $this->remove_shortcodes( $value );

			case '12': // ucwords (lowercase first).
				return ucwords( mb_strtolower( $value ) );

			case '13': // ucfirst (lowercase first).
				return ucfirst( mb_strtolower( $value ) );

			case '14': // strtoupper.
				// V5 wraps mb_strtolower inside mb_strtoupper — the inner
				// lowercase is a no-op for ASCII but normalises some
				// Unicode forms. Mirror the quirk for byte-exact parity.
				return mb_strtoupper( mb_strtolower( $value ) );

			case '15': // strtolower.
				return mb_strtolower( $value );

			case '16': // urlToSecure (http→https).
				return ( 0 === strpos( $value, 'http' ) )
					? str_replace( 'http://', 'https://', $value )
					: $value;

			case '17': // urlToUnsecure (https→http).
				return ( 0 === strpos( $value, 'http' ) )
					? str_replace( 'https://', 'http://', $value )
					: $value;

			case '21': // htmlspecialchars XML.
				return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

			case '22': // htmlspecialchars_decode XML.
				return htmlspecialchars_decode( $value, ENT_XML1 | ENT_QUOTES );

			// Codes 18 (only_parent), 19 (parent), 20 (parent_if_empty),
			// 23 (parent_lang), 24 (parent_lang_if_empty) require
			// re-resolving the attribute against an alternate product
			// (parent or parent-language). The V8 pipeline operates on
			// already-resolved data and has no product context, so those
			// codes are applied UPSTREAM in
			// `ProductRepository::apply_parent_output_type()` (where
			// $product + AttributeResolver are available). PROD-FRD-10.8.
			// When the pipeline reaches OutputTypeTransform the parent
			// substitution has already happened — values pass through
			// here unchanged.
			case '18':
			case '19':
			case '20':
			case '23':
			case '24':
				return $value;

			default:
				return $value;
		}
	}

	/**
	 * Format a numeric string with the configured/resolved separators.
	 *
	 * Empty / non-numeric / zero-or-negative inputs are returned unchanged
	 * (V5 behaviour — `if ( ! empty($output) && $output > 0 ) {…}`).
	 *
	 * @since 8.0.0
	 *
	 * @param string                                                                   $value Value.
	 * @param array{decimals:int, decimal_separator:string, thousand_separator:string} $nf    Format settings.
	 * @return string
	 */
	private function format_price( string $value, array $nf ): string {
		if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
			return $value;
		}
		return number_format(
			(float) $value,
			$nf['decimals'],
			$nf['decimal_separator'],
			$nf['thousand_separator']
		);
	}

	/**
	 * Replace runs of whitespace with a single space (V5 code 8).
	 *
	 * Verbatim port of V5 FormatOutput::delete_space(): htmlentities → swap
	 * `&nbsp;` to space → decode → collapse whitespace.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function delete_space( string $value ): string {
		$encoded = htmlentities( $value, ENT_QUOTES, 'utf-8' );
		$encoded = str_replace( '&nbsp;', ' ', $encoded );
		$decoded = html_entity_decode( $encoded );
		return preg_replace( '/\s+/', ' ', $decoded );
	}

	/**
	 * Remove shortcodes — V5 CommonHelper::remove_shortcodes() parity.
	 *
	 * V5's "Remove ShortCodes" output_type (code 11) does FOUR things:
	 *   1. `do_shortcode($value)` — expand any registered shortcodes so
	 *      their rendered output replaces the tag.
	 *   2. `strip_shortcodes(…)` — strip the registered shortcode tags
	 *      that remain (e.g. inside `[gallery]` with no media).
	 *   3. `strip_invalid_xml(…)` — drop control chars produced by the
	 *      shortcode handlers.
	 *   4. Catch-all regex `/\[\/*[…]*\/*\]/m` — strip ANY remaining
	 *      `[whatever]` bracket pattern, registered or not.
	 *
	 * The catch-all (#4) is the load-bearing step for the 70K install
	 * base: leftover tags from deactivated plugins (e.g.
	 * `[woocommerce_my_account]` after a theme switch) are stripped here.
	 * Without it, `strip_shortcodes()` silently leaves those intact, which
	 * is exactly the bug a customer would see when "Remove ShortCodes"
	 * appears not to work on V8.
	 *
	 * The regex pattern is verbatim from V5 (`CommonHelper.php:99`).
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Value to clean.
	 * @return string Cleaned value.
	 */
	private function remove_shortcodes( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Step 1: expand registered shortcodes so their rendered output
		// replaces the tag. Guard via function_exists for non-WP contexts.
		if ( function_exists( 'do_shortcode' ) ) {
			$value = (string) do_shortcode( $value );
		}

		// Step 2: strip registered shortcode tags that remain.
		if ( function_exists( 'strip_shortcodes' ) ) {
			$value = (string) strip_shortcodes( $value );
		}

		// Step 3: invalid-XML clean (V5 also runs this here; see
		// strip_invalid_xml() below for the regex).
		$value = $this->strip_invalid_xml( $value );

		// Step 4: catch-all regex — strips ANY `[…]` pattern that remains.
		// Pattern is V5-verbatim (V5/Helper/CommonHelper.php:99).
		$expression = '/\[\/*[č?a-zA-Z1-90_| -=\'"\{\}]*\/*\]/m';
		$result     = preg_replace( $expression, '', $value );
		return null === $result ? $value : $result;
	}

	/**
	 * Strip characters that break XML feeds — V5 code 10.
	 *
	 * Two passes:
	 *
	 *   Pass 1 — XML 1.0 disallowed code points (V5 parity).
	 *     Keep: \t \n \r \x20-\x{D7FF} \x{E000}-\x{FFFD} \x{10000}-\x{10FFFF}
	 *     Drop: \x00-\x08, \x0B, \x0C, \x0E-\x1F, U+D800-U+DFFF (surrogates),
	 *     U+FFFE, U+FFFF (non-characters).
	 *
	 *   Pass 2 — XML-valid but feed-breaking invisible characters (V8
	 *   hardening — V5 didn't strip these and customers reported feeds
	 *   failing Google Merchant Center validation with no visible cause).
	 *   These slip in from rich-text editors (Word, Google Docs), CSV
	 *   imports with BOM, copy/paste from web pages, etc.:
	 *
	 *     U+007F             DEL
	 *     U+0080-U+009F      C1 controls (NEL, PAD, BPH, etc.)
	 *     U+00AD             soft hyphen (line-break hint)
	 *     U+061C             Arabic letter mark
	 *     U+180E             Mongolian vowel separator
	 *     U+200B-U+200F      ZWSP, ZWNJ, ZWJ, LRM, RLM
	 *     U+202A-U+202E      bidi embedding/override
	 *     U+2060-U+2064      word joiner, invisible math operators
	 *     U+2066-U+206F      isolate marks, deprecated formatting
	 *     U+FEFF             byte-order mark (anywhere in string)
	 *     U+FFF9-U+FFFB      interlinear annotation anchors
	 *
	 * Deliberately preserved (still feed-safe in practice):
	 *     U+FE00-U+FE0F      variation selectors (emoji presentation)
	 *     U+E000-U+F8FF      Private Use Area (custom symbols)
	 *     U+E0100-U+E01EF    variation selectors supplement
	 *
	 * The third-party filter `ctxfeed_strip_invalid_xml` lets admins
	 * post-process the result — e.g. to strip emoji or private-use
	 * glyphs that a specific merchant rejects.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function strip_invalid_xml( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Pass 1: XML 1.0 hard requirement.
		$pattern_xml = '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';
		$step1       = preg_replace( $pattern_xml, '', $value );
		if ( null !== $step1 ) {
			$value = $step1;
		}

		// Pass 2: invisible / feed-breaking but XML-valid code points.
		$pattern_invisible = '/['
			. '\x{007F}'              // DEL.
			. '\x{0080}-\x{009F}'     // C1 controls.
			. '\x{00AD}'              // Soft hyphen.
			. '\x{061C}'              // Arabic letter mark.
			. '\x{180E}'              // Mongolian vowel separator.
			. '\x{200B}-\x{200F}'     // ZWSP, ZWNJ, ZWJ, LRM, RLM.
			. '\x{202A}-\x{202E}'     // Bidi embedding/override.
			. '\x{2060}-\x{2064}'     // Word joiner, invisible math.
			. '\x{2066}-\x{206F}'     // Isolates, deprecated formatting.
			. '\x{FEFF}'              // BOM.
			. '\x{FFF9}-\x{FFFB}'     // Interlinear annotation.
			. ']/u';
		$step2             = preg_replace( $pattern_invisible, '', $value );
		if ( null !== $step2 ) {
			$value = $step2;
		}

		/**
		 * Filter the cleaned value after invalid-XML stripping.
		 *
		 * Admins can chain additional sanitization — e.g. strip emoji
		 * variation selectors for merchants that reject them, or pull
		 * private-use-area glyphs.
		 *
		 * @since 8.0.0
		 *
		 * @param string $value Value after both stripping passes.
		 */
		return apply_filters( 'ctxfeed_strip_invalid_xml', $value );
	}
}
