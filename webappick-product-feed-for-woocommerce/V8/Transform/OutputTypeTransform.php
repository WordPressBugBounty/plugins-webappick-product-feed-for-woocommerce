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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductRepository;
use CTXFeed\V8\Utility\Ascii;
use CTXFeed\V8\Utility\Html;
use CTXFeed\V8\Utility\LocalizedNumber;
use CTXFeed\V8\Utility\Mb;
use CTXFeed\V8\Utility\NumberFormat;
use CTXFeed\V8\Utility\Regex;
use CTXFeed\V8\Utility\Utf8;

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
	/**
	 * Templates whose price rows default to output type "Price" (V5 parity).
	 *
	 * @since 8.0.27
	 * @var string[]
	 */
	private const SPECIAL_PRICE_TEMPLATES = array( 'custom2', 'admarkt', 'glami', 'yandex_xml' );

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
	 * Config object the memos below were built from (held by reference so
	 * its object identity cannot be recycled while the memo is alive).
	 *
	 * @since 8.0.11
	 * @var Config|null
	 */
	private $memo_config = null;

	/**
	 * Memoised attr => code lookup for {@see $memo_config}.
	 *
	 * @since 8.0.11
	 * @var array<string,mixed>|null
	 */
	private $memo_lookup = null;

	/**
	 * Memoised normalized code sets, keyed by merchant attribute.
	 *
	 * @since 8.0.11
	 * @var array<string,array<string,int>>
	 */
	private $memo_selected = array();

	/**
	 * Memoised product-data keys whose value the pipeline already formatted
	 * (custom2 rows sourced from a dynamic attribute), for {@see $memo_config}.
	 * Null until a Price / Rounded Price code first needs it.
	 *
	 * @since 8.0.39
	 * @var array<string,bool>|null
	 */
	private $memo_formatted = null;

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
		// The lookup and per-attribute code sets depend only on feed config,
		// which is fixed for the life of a batch — build them once per
		// Config instead of re-zipping arrays for every product.
		if ( $config !== $this->memo_config ) {
			$this->memo_config    = $config;
			$this->memo_lookup    = $this->build_lookup( $config );
			$this->memo_selected  = array();
			$this->memo_formatted = null;
		}

		$lookup = $this->memo_lookup;
		if ( empty( $lookup ) ) {
			return $product_data;
		}

		$is_xml = 'xml' === Ascii::lower( (string) $config->get( 'feedType', 'xml' ) );

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

		$by_row = self::is_positional( (array) $config->get( 'output_type', array() ) );

		foreach ( $product_data as $attr => &$value ) {
			// A positional config is keyed per ROW (`name`, `name##2`, …), so
			// a repeated merchant attribute keeps its own output type
			// (CBT-847). A name-keyed fixture applies to every repeat.
			$lookup_attr = $by_row ? (string) $attr : ProductRepository::strip_dup_suffix( (string) $attr );
			if ( ! isset( $lookup[ $lookup_attr ] ) ) {
				continue;
			}

			// A row can carry SEVERAL codes (V5 multiselect). Normalise to a
			// set, then apply each in V5's fixed order — never the UI's
			// selection order. Codes 1/default and the parent codes
			// (18/19/20/23/24, handled upstream) simply aren't in
			// FORMAT_CODE_ORDER, so they're skipped without a special case.
			if ( ! array_key_exists( $lookup_attr, $this->memo_selected ) ) {
				$this->memo_selected[ $lookup_attr ] = array_flip( self::normalize_codes( $lookup[ $lookup_attr ] ) );
			}
			$selected = $this->memo_selected[ $lookup_attr ];
			if ( empty( $selected ) ) {
				continue;
			}

			// Code 6 hands its parsed (rounded) number to code 7 so Rounded
			// Price never re-reads Price's formatted text (CBT-811).
			$carried  = null;
			$scrubbed = false;

			foreach ( self::FORMAT_CODE_ORDER as $code ) {
				if ( ! isset( $selected[ $code ] ) ) {
					continue;
				}
				// One scrub per row, AFTER code 3's slot (CBT-817 D5,
				// amendment 23): invalid UTF-8 made codes 4/8/21 return ''
				// and the case codes write '?'. Never before codes 2 or 3 —
				// code 3 must still see the Latin-1 bytes it converts
				// ("Caf\xE9" → "Café", not "Caf"). Only rows that have such
				// a code pay the one //u check.
				if ( ! $scrubbed && '2' !== $code && '3' !== $code ) {
					$scrubbed = true;
					if ( is_string( $value ) ) {
						$value = Utf8::scrub( $value );
					}
				}
				if ( '6' === $code || '7' === $code ) {
					$value = $this->apply_price_code( $code, $value, $nf_loader(), $this->is_formatted_row( (string) $attr, $config ), $carried );
					continue;
				}
				$value = $this->apply_code( $code, $value, $is_xml, array() );
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
		return self::lookup( $config );
	}

	/**
	 * The feed's `merchant attribute => stored code(s)` lookup (uncached).
	 *
	 * The same map {@see transform()} applies — one entry per row keyed like
	 * the resolved values (`name`, `name##2`, …; CBT-847), a string-keyed
	 * fixture shape as-is, and the special-template Price default. Shared
	 * with channel transforms
	 * that must know whether a row was formatted by code 6/7 (ChatGPT's sale
	 * check, CBT-811). Callers memoise it per Config.
	 *
	 * @since 8.0.39
	 *
	 * @param Config $config Feed configuration.
	 * @return array<string,mixed> Map of row key (merchant attr, `##N` for repeats) → code(s).
	 */
	public static function lookup( Config $config ): array {
		$output_types = (array) $config->get( 'output_type', array() );

		// String-keyed (V8 test fixture style) — return as-is.
		if ( ! self::is_positional( $output_types ) ) {
			return $output_types;
		}

		// Parallel-array shape — one entry per ROW, keyed like the resolved
		// values (`name`, `name##2`, …; CBT-847).
		$row_keys = ProductRepository::row_keys( (array) $config->get_merchant_attributes() );
		$lookup   = array();
		foreach ( $row_keys as $i => $key ) {
			if ( ! isset( $output_types[ $i ] ) || '' === $key ) {
				continue;
			}
			$lookup[ $key ] = $output_types[ $i ];
		}
		return self::apply_special_template_price_default( $lookup, $config );
	}

	/**
	 * Whether a stored `output_type` array is the positional (per-row) shape.
	 *
	 * @since 8.0.40
	 *
	 * @param array $output_types Stored `output_type` config.
	 * @return bool
	 */
	private static function is_positional( array $output_types ): bool {
		return empty( $output_types ) || range( 0, count( $output_types ) - 1 ) === array_keys( $output_types );
	}

	/**
	 * V5 special-template default: price rows without an output type get "Price" (6).
	 *
	 * V5 `Config::get_attribute_output_types()`: on `custom2`, `admarkt`,
	 * `glami` and `yandex_xml` a row whose SOURCE attribute name contains
	 * `price` and has no stored output type falls back to `array( 6 )`.
	 * Rows that store any code (including Default `1`) are left alone,
	 * exactly as V5's `! empty()` test did.
	 *
	 * @since 8.0.27
	 *
	 * @param array<string,mixed> $lookup Merchant attr → code(s).
	 * @param Config              $config Feed configuration.
	 *
	 * @return array<string,mixed>
	 */
	private static function apply_special_template_price_default( array $lookup, Config $config ): array {
		if ( ! in_array( $config->get_provider(), self::SPECIAL_PRICE_TEMPLATES, true ) ) {
			return $lookup;
		}

		$mattributes = (array) $config->get( 'mattributes', array() );
		$attributes  = (array) $config->get( 'attributes', array() );
		$types       = (array) $config->get( 'type', array() );
		$row_keys    = ProductRepository::row_keys( $mattributes );

		foreach ( $mattributes as $i => $mattr ) {
			$key    = isset( $row_keys[ $i ] ) ? $row_keys[ $i ] : '';
			$source = isset( $attributes[ $i ] ) ? (string) $attributes[ $i ] : '';
			$type   = isset( $types[ $i ] ) ? (string) $types[ $i ] : 'attribute';
			if ( '' === $key || 'attribute' !== $type || false === strpos( $source, 'price' ) ) {
				continue;
			}
			if ( empty( $lookup[ $key ] ) ) {
				$lookup[ $key ] = '6';
			}
		}

		return $lookup;
	}

	/**
	 * Resolve the feed's number format for the "Price" / "Rounded Price" codes.
	 *
	 * V5 semantics, one owner ({@see NumberFormat::resolve()}): the base is the
	 * machine format 2 / "." / none (CBT-641); Pro replaces each with the
	 * Filters-tab box the user actually filled in; free ignores the boxes
	 * (CBT-640); Idealo forces its fixed format over the boxes (CBT-811).
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return array{decimals:int, decimal_separator:string, thousand_separator:string}
	 */
	private function resolve_number_format( Config $config ): array {
		return NumberFormat::resolve( $config );
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
			// Entities decode with explicit flags and charset (CBT-817 D1):
			// below PHP 8.1 the defaults left "&#039;" in the value, and a
			// non-UTF-8 default_charset wrote "&eacute;" as one Latin-1 byte.
			case '2': // Strip Tags.
				// Html::strip_all_tags(): core's script/style regex returned
				// null on a long block and the value shipped '' (CBT-822 E1).
				return Html::strip_all_tags( Html::decode( $value ) );

			case '3': // UTF-8 Encode.
				// Valid UTF-8 is already UTF-8 — returned unchanged. PHP 8.1+
				// mb_detect_encoding() scores "Ķekava" / "Tiếng Việt" as
				// ISO-8859-1 and the conversion wrote mojibake ("Ä¶ekava").
				// Anything else is read as Windows-1252 (CBT-817 D9).
				return Utf8::is_valid( $value ) ? $value : Utf8::from_windows1252( $value );

			case '4': // htmlentities (UTF-8, ENT_QUOTES).
				return htmlentities( $value, ENT_QUOTES, 'UTF-8' );

			case '5': // Integer cast.
				return (string) (int) $value;

			case '6': // Format Price (a raw value; transform() routes 6/7 through apply_price_code()).
			case '7': // Rounded Price.
				$carried = null;
				return $this->apply_price_code( $code, $value, $nf, false, $carried );

			case '8': // Delete Space.
				return $this->delete_space( $value );

			case '9': // Wrap CDATA (XML only).
				if ( ! $is_xml || '' === $value ) {
					return $value;
				}
				// Strip any existing markers before wrapping fresh.
				$clean = str_replace( array( '<![CDATA[', ']]>' ), '', Html::decode( $value ) );
				return '<![CDATA[' . $clean . ']]>';

			case '10': // Remove invalid XML chars.
				return $this->strip_invalid_xml( $value );

			case '11': // Remove ShortCodes — V5 CommonHelper::remove_shortcodes parity.
				return $this->remove_shortcodes( $value );

			// Codes 12-15 (CBT-815): Mb folds in explicit UTF-8 (never the
			// mb_internal_encoding() another plugin left set) and Ascii
			// capitalises a-z only — PHP 8.2's ucwords()/ucfirst() on every
			// version, so a non-C locale can no longer fold the bytes of
			// "中文" or "–" into invalid UTF-8.
			case '12': // ucwords (lowercase first).
				return Ascii::ucwords( Mb::lower( $value ) );

			case '13': // ucfirst (lowercase first).
				return Ascii::ucfirst( Mb::lower( $value ) );

			case '14': // strtoupper.
				// V5 wraps mb_strtolower inside mb_strtoupper — the inner
				// lowercase is a no-op for ASCII but normalises some
				// Unicode forms. Mirror the quirk for byte-exact parity.
				return Mb::upper( Mb::lower( $value ) );

			case '15': // strtolower.
				return Mb::lower( $value );

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
	 * Apply "Price" (6) or "Rounded Price" (7) to one value.
	 *
	 * V5 `FormatOutput` read the value with a bare `(float)` cast, because the
	 * input is the RAW WooCommerce value. So does this (CBT-811): a raw value
	 * is parsed machine-first ({@see LocalizedNumber::parse_raw()}) — with the
	 * Pro boxes "," / "." a raw 17.991 is 17.991 → "17,99", never 17 991 →
	 * "17.991,00" (the 1000× price up to 8.0.38). Only a row whose value the
	 * pipeline itself formatted (a custom2 dynamic-attribute source, see
	 * {@see is_formatted_row()}) is read format-first with the feed's
	 * separators ({@see LocalizedNumber::parse()}), which keeps #68878
	 * idempotent: 1499 at 0 / "," / "." stays "1.499".
	 *
	 * Code 6 hands code 7 its number rounded to the feed's decimals through
	 * `$carried`, so Rounded Price rounds what Price wrote (17.495 → "17.50"
	 * → "18.00") and never re-reads Price's text. Code 7 alone parses the
	 * value itself the same way code 6 does.
	 *
	 * Empty, non-numeric and zero-or-negative values are returned unchanged
	 * (V5 — `if ( ! empty( $output ) && $output > 0 )`).
	 *
	 * @since 8.0.39
	 * @implements XFRM-FRD-4.6
	 *
	 * @param string                                                                   $code      '6' or '7'.
	 * @param mixed                                                                    $value     Current attribute value.
	 * @param array{decimals:int, decimal_separator:string, thousand_separator:string} $nf        Format settings.
	 * @param bool                                                                     $formatted Whether the row value is pipeline-formatted text.
	 * @param float|null                                                               $carried   In: code 6's number for code 7; out: the number code 6 wrote.
	 *
	 * @return mixed Formatted value, or the original on no-op / type mismatch.
	 */
	private function apply_price_code( string $code, $value, array $nf, bool $formatted, ?float &$carried ) {
		if ( ! is_scalar( $value ) ) {
			$carried = null;
			return $value;
		}
		$value = (string) $value;

		if ( '6' === $code ) {
			$parsed  = $this->parse_price( $value, $nf, $formatted );
			$carried = null;
			if ( null === $parsed || $parsed <= 0 ) {
				return $value;
			}
			// number_format() rounds with the same routine and clamps
			// negative decimals to 0 — mirror both so code 7 sees exactly
			// the number code 6 wrote.
			$carried = round( $parsed, max( 0, (int) $nf['decimals'] ) );
			return $this->format_number( $parsed, $nf );
		}

		$parsed  = null !== $carried ? $carried : $this->parse_price( $value, $nf, $formatted );
		$carried = null;
		if ( null === $parsed || $parsed <= 0 ) {
			return $value;
		}
		return $this->format_number( round( $parsed ), $nf );
	}

	/**
	 * Read a Price / Rounded Price input as a float.
	 *
	 * @since 8.0.39
	 *
	 * @param string                                                                   $value     Value.
	 * @param array{decimals:int, decimal_separator:string, thousand_separator:string} $nf        Format settings.
	 * @param bool                                                                     $formatted Pipeline-formatted text (format-first) vs raw (machine-first).
	 *
	 * @return float|null
	 */
	private function parse_price( string $value, array $nf, bool $formatted ): ?float {
		if ( $formatted ) {
			return LocalizedNumber::parse( $value, (string) $nf['decimal_separator'], (string) $nf['thousand_separator'] );
		}
		return LocalizedNumber::parse_raw( $value, (string) $nf['decimal_separator'], (string) $nf['thousand_separator'] );
	}

	/**
	 * Whether a product-data key carries text the pipeline already formatted.
	 *
	 * Only on `custom2`, where the Pro dynamic-attribute engine writes numeric
	 * results with the feed number format: a row whose source is a dynamic
	 * attribute, or an attribute mapping holding one at any depth
	 * ({@see NumberFormat::is_formatted_source()}). Built once per Config and
	 * keyed exactly like `ProductRepository::resolve_single()` keys the row
	 * (duplicate names get `##N`), so O(rows) per batch, nothing per product.
	 *
	 * @since 8.0.39
	 *
	 * @param string $key    Product-data key (may carry the `##N` suffix).
	 * @param Config $config Feed configuration.
	 *
	 * @return bool
	 */
	private function is_formatted_row( string $key, Config $config ): bool {
		if ( null === $this->memo_formatted ) {
			$this->memo_formatted = self::formatted_rows( $config );
		}
		return isset( $this->memo_formatted[ $key ] );
	}

	/**
	 * Product-data keys of the rows whose value is pipeline-formatted text.
	 *
	 * @since 8.0.39
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array<string,bool>
	 */
	private static function formatted_rows( Config $config ): array {
		if ( 'custom2' !== $config->get_provider() ) {
			return array();
		}

		$sources = $config->get_attributes();
		$types   = $config->get_type();
		$seen    = array();
		$rows    = array();

		foreach ( $config->get_merchant_attributes() as $index => $merchant_attr ) {
			$merchant_attr = (string) $merchant_attr;
			if ( '' === $merchant_attr ) {
				continue;
			}

			// Same keys as ProductRepository::dedup_merchant_key().
			if ( isset( $seen[ $merchant_attr ] ) ) {
				++$seen[ $merchant_attr ];
				$key = $merchant_attr . ProductRepository::DUP_KEY_SEPARATOR . $seen[ $merchant_attr ];
			} else {
				$seen[ $merchant_attr ] = 1;
				$key                    = $merchant_attr;
			}

			$type   = isset( $types[ $index ] ) ? (string) $types[ $index ] : 'attribute';
			$source = isset( $sources[ $index ] ) ? (string) $sources[ $index ] : '';
			if ( 'attribute' === $type && '' !== $source && NumberFormat::is_formatted_source( $source ) ) {
				$rows[ $key ] = true;
			}
		}

		return $rows;
	}

	/**
	 * Format a parsed float with the feed’s number_format() settings.
	 *
	 * @since 8.0.10
	 *
	 * @param float                                                                    $number Parsed numeric value.
	 * @param array{decimals:int, decimal_separator:string, thousand_separator:string} $nf     Format settings.
	 * @return string
	 */
	private function format_number( float $number, array $nf ): string {
		return number_format(
			$number,
			$nf['decimals'],
			$nf['decimal_separator'],
			$nf['thousand_separator']
		);
	}

	/**
	 * Replace runs of whitespace with a single space (V5 code 8).
	 *
	 * Port of V5 FormatOutput::delete_space(): htmlentities → swap `&nbsp;`
	 * to space → decode → collapse whitespace. Encode and decode use the
	 * same flags and charset (CBT-817 D1): V5's bare decode left the
	 * `&#039;` its own ENT_QUOTES encode wrote, so "Men's" shipped as
	 * "Men&#039;s" on PHP 7.4/8.0.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function delete_space( string $value ): string {
		$encoded = Html::encode( $value );
		$encoded = str_replace( '&nbsp;', ' ', $encoded );
		$decoded = Html::decode( $encoded );
		// The six ASCII whitespace bytes `\s` means in the C locale. A byte
		// `\s` follows PCRE locale tables: macOS UTF-8 locales count 0xA0 —
		// the second byte of "à" / "Å" / "х" — as a space and cut the letter
		// in half (CBT-822 A11). Never `\v`: in byte mode it matches 0x85.
		return Regex::replace( '/[\t\n\x0B\f\r ]+/', ' ', $decoded, 'output type "Delete Space"' );
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
		// V5's pattern (V5/Helper/CommonHelper.php:99) with possessive
		// quantifiers (CBT-822 E4): the class already holds "/" and not "]",
		// so the matches are the same, but the three overlapping greedy runs
		// no longer backtrack — "[" + 3000 slashes took a second and
		// returned null at the default pcre.backtrack_limit.
		return Regex::replace( Regex::SHORTCODE_CATCH_ALL, '', $value, 'output type "Remove ShortCodes"' );
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
