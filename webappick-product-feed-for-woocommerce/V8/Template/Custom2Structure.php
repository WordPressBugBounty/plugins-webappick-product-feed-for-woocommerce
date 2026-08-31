<?php
/**
 * Custom2Structure — Parses the user-supplied `feed_config_custom2` template
 * string into an element array consumed by Custom2Template.
 *
 * Verbatim port of `V5\Structure\Custom2Structure::get_xml_structure()`.
 * The template string uses the V5 mini-DSL:
 *
 *   <?xml version="1.0" ?>
 *   <products>
 *   {{each product start}}
 *     <product>
 *       <id>{id}</id>
 *       {{each image start}}<image>{image}</image>{{each image end}}
 *       {{if variation available}}
 *       {{each variation start}}<variation>{title}</variation>{{each variation end}}
 *       {{endif variation}}
 *     </product>
 *   {{each product end}}
 *   </products>
 *
 * Markers V5 supports (we mirror them verbatim):
 *   START                            END
 *   {{if variation available}}       {{endif variation}}
 *   {{each variation start}}         {{each variation end}}
 *   {{each image start}}             {{each image end}}
 *   {{each shipping start}}          {{each shipping end}}
 *   {{each tax start}}               {{each tax end}}
 *   {{each category start}}          {{each category end}}     ← V5 inconsistent (categoryEnd not categoriesEnd)
 *   {{each crossSale start}}         {{each crossSale end}}
 *   {{each upSale start}}            {{each upSale end}}
 *   {{each relatedProducts start}}   {{each relatedProducts end}}
 *   {{each associatedProduct start}} {{each associatedProduct end}}
 *
 * Element field shape (per parsed XML node):
 *   for             → loop bucket name (variation/images/categories/...) or '' for top-level
 *   start           → opening tag string (e.g. 'product')
 *   end             → closing tag string (e.g. 'product')
 *   include_cdata   → 'yes' if the body had a CDATA wrapper, else 'no'
 *   elementTextInfo → the raw text between < … > and < … />
 *   attr_type       → 'text' | 'attribute' | 'return' | 'php'
 *   attr_value      → for attr_type=text, the literal value
 *   attr_code       → for attr_type=attribute, the attribute slug
 *   to_return       → for attr_type=return|php, the eval-string body
 *   id_type         → after-pipe modifier on attr_code (e.g. 'parent')
 *   formatter[]     → list of [name] formatters between commas
 *   prefix / suffix → text outside the {…} placeholder on the same line
 *   start_code[]    → list of {…} placeholders extracted from the start tag
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-9.1
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the `feed_config_custom2` template string.
 *
 * @since 8.0.0
 */
class Custom2Structure {

	/**
	 * Marker indicating where each-product loop begins.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PRODUCT_LOOP_START = '{{each product start}}';

	/**
	 * Marker indicating where each-product loop ends.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PRODUCT_LOOP_END = '{{each product end}}';

	/**
	 * Sub-loop START markers, keyed by loop name. Verbatim from V5.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private const SUB_LOOPS_START = array(
		'ifVariationAvailable' => '{{if variation available}}',
		'variation'            => '{{each variation start}}',
		'images'               => '{{each image start}}',
		'shipping'             => '{{each shipping start}}',
		'tax'                  => '{{each tax start}}',
		'categories'           => '{{each category start}}',
		'crossSale'            => '{{each crossSale start}}',
		'upSale'               => '{{each upSale start}}',
		'relatedProducts'      => '{{each relatedProducts start}}',
		'associatedProduct'    => '{{each associatedProduct start}}',
	);

	/**
	 * Sub-loop END markers, keyed by loop-name + 'End'. Verbatim from V5.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private const SUB_LOOPS_END = array(
		'ifVariationAvailableEnd' => '{{endif variation}}',
		'variationEnd'            => '{{each variation end}}',
		'imagesEnd'               => '{{each image end}}',
		'shippingEnd'             => '{{each shipping end}}',
		'taxEnd'                  => '{{each tax end}}',
		'categoryEnd'             => '{{each category end}}',
		'crossSaleEnd'            => '{{each crossSale end}}',
		'upSaleEnd'               => '{{each upSale end}}',
		'relatedProductsEnd'      => '{{each relatedProducts end}}',
		'associatedProductEnd'    => '{{each associatedProduct end}}',
	);

	/**
	 * Parse the template body of the configured `feed_config_custom2`
	 * string into an elements array.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array{variationElementsStart: int|null, structure: array<int,array<string,mixed>>}
	 */
	public function parse( Config $config ): array {
		$template = (string) $config->get( 'feed_config_custom2', '' );

		if ( '' === $template ) {
			return array(
				'variationElementsStart' => null,
				'structure'              => array(),
			);
		}

		// V5 strips literal '+' chars from the entire template before parsing.
		// Customers wrap CDATA bodies in `+...+` markers in some templates
		// and V5 silently dropped them — preserve that quirk.
		$xml = trim( preg_replace( '/\+/', '', $template ) );

		// Extract just the body between the product-loop markers.
		$body = self::between( $xml, self::PRODUCT_LOOP_START, self::PRODUCT_LOOP_END );
		if ( '' === $body ) {
			return array(
				'variationElementsStart' => null,
				'structure'              => array(),
			);
		}

		$lines    = explode( "\n", $body );
		$elements = array();
		$i        = 1;

		$current_sub_loop         = '';
		$variation_elements_start = null;

		// V5 keys the yandex quote-preservation branch off the merchant
		// PROVIDER (V5 Config::get_feed_template() returns
		// config['provider'] — V5/Utility/Config.php:150). Read the same
		// feedrules key; `template` is kept as a legacy fallback for
		// callers that pass the merchant under that name.
		$feed_template = (string) $config->get( 'provider', '' );
		if ( '' === $feed_template ) {
			$feed_template = (string) $config->get( 'template', '' );
		}

		foreach ( $lines as $value ) {
			if ( '' === $value ) {
				continue;
			}

			$trimmed = trim( $value );

			// Sub-loop START marker — switch the bucket.
			$start_loop_key = array_search( $trimmed, self::SUB_LOOPS_START, true );
			if ( false !== $start_loop_key ) {
				$current_sub_loop = (string) $start_loop_key;
				if ( 'variation' === $current_sub_loop ) {
					$variation_elements_start = $i;
				}
				continue;
			}

			// Sub-loop END marker — leave the bucket. The `ifVariationAvailable`
			// end gets stamped onto the previous element so the renderer knows
			// where the if-block boundary is (V5 quirk preserved).
			$end_loop_key = array_search( $trimmed, self::SUB_LOOPS_END, true );
			if ( false !== $end_loop_key ) {
				if ( 'ifVariationAvailableEnd' === $end_loop_key && isset( $elements[ $i - 1 ] ) ) {
					$elements[ $i - 1 ]['for'] = 'ifVariationAvailable';
				}
				$current_sub_loop = '';
				continue;
			}

			// Parse the actual XML node line.
			$element_inner = self::between( $value, '<', '>' );
			if ( '' === $element_inner ) {
				continue;
			}

			$elements[ $i ] = array(
				'for' => $current_sub_loop,
			);

			// Get starting element. yandex_xml templates use literal '"' inside
			// attribute strings (e.g. <param name="brand">…</param>), so V5
			// preserves quotes for that template. All other templates strip them.
			if ( 'yandex_xml' === $feed_template ) {
				$elements[ $i ]['start'] = $element_inner;
			} else {
				$elements[ $i ]['start'] = self::strip_quotes_unless_static( $element_inner );
			}

			// Closing tag.
			$elements[ $i ]['end'] = self::between( $value, '</', '>' );

			// CDATA detection — strip the markers and remember to re-wrap on render.
			$body_text                       = self::between( $value, '>', '</' );
			$elements[ $i ]['include_cdata'] = 'no';
			if ( false !== stripos( $body_text, 'CDATA' ) ) {
				$elements[ $i ]['include_cdata'] = 'yes';
				$body_text                       = str_replace( array( '<![CDATA[', ']]>' ), '', $body_text );
			}
			$elements[ $i ]['elementTextInfo'] = $body_text;

			if ( '' !== $body_text ) {
				$has_open  = false !== strpos( $body_text, '{' );
				$has_close = false !== strpos( $body_text, '}' );

				if ( ! $has_open && ! $has_close ) {
					// Static literal body.
					$elements[ $i ]['attr_type']  = 'text';
					$elements[ $i ]['attr_value'] = $body_text;
				} elseif ( false !== strpos( $body_text, 'return' ) ) {
					// {(return ...)} expression — captured for eval at render time.
					$elements[ $i ]['attr_type'] = 'return';
					$elements[ $i ]['to_return'] = self::between( $body_text, '{(', ')}' );
				} elseif ( false !== strpos( $body_text, 'php ' ) ) {
					// {(php ...)} expression — passes through as a literal.
					$elements[ $i ]['attr_type'] = 'php';
					$elements[ $i ]['to_return'] = str_replace(
						'php',
						'',
						self::between( $body_text, '{(', ')}' )
					);
				} else {
					// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Documents the {attribute|id_type, formatter} placeholder syntax, not commented-out code.
					// {attribute_name|id_type, [formatter1], [formatter2]} placeholder.
					$elements[ $i ]['attr_type'] = 'attribute';
					$attribute                   = self::between( $body_text, '{', '}' );
					$base_format                 = explode( ',', $attribute );

					$attr_info = $base_format[0];

					if ( count( $base_format ) > 1 ) {
						$j = 0;
						foreach ( $base_format as $segment ) {
							$formatter = self::between( $segment, '[', ']' );
							if ( '' !== $formatter ) {
								$elements[ $i ]['formatter'][ $j ] = $formatter;
								++$j;
							}
						}
					}

					$attr_codes                  = explode( '|', $attr_info );
					$elements[ $i ]['attr_code'] = $attr_codes[0];
					$elements[ $i ]['id_type']   = isset( $attr_codes[1] ) ? $attr_codes[1] : '';
				}

				// Prefix/suffix — text on the same line outside the {…}.
				// V5 runs the position check against the TRIMMED body
				// (V5/Structure/Custom2Structure.php:180,186) so leading
				// whitespace before `{attr}` never becomes a phantom
				// prefix; the explode itself stays untrimmed (V5 parity).
				$elements[ $i ]['prefix'] = '';
				if ( 'text' !== $elements[ $i ]['attr_type'] && 0 !== strpos( trim( $body_text ), '{' ) ) {
					$prefix_split             = explode( '{', $body_text );
					$elements[ $i ]['prefix'] = count( $prefix_split ) > 1 ? $prefix_split[0] : '';
				}

				$elements[ $i ]['suffix'] = '';
				if ( 'text' !== $elements[ $i ]['attr_type'] && 0 !== strpos( trim( $body_text ), '}' ) ) {
					$suffix_split             = explode( '}', $body_text );
					$elements[ $i ]['suffix'] = count( $suffix_split ) > 1 ? $suffix_split[1] : '';
				}
			}

			// Extract any {…} placeholders inside the start tag (for things
			// like <product id="{id}">).
			preg_match_all( '/{(.*?)}/', $element_inner, $matches );
			$start_codes                  = isset( $matches[0] ) ? $matches[0] : array();
			$elements[ $i ]['start_code'] = array_filter( $start_codes );

			++$i;
		}

		return array(
			'variationElementsStart' => $variation_elements_start,
			'structure'              => $elements,
		);
	}

	/**
	 * Substring between two delimiters (V5 helper FeedHelper::get_string_between).
	 *
	 * @since 8.0.0
	 *
	 * @param string $haystack Source string.
	 * @param string $start    Opening delimiter.
	 * @param string $end      Closing delimiter.
	 * @return string The substring (empty if either delimiter is absent).
	 */
	private static function between( string $haystack, string $start, string $end ): string {
		$ini = strpos( $haystack, $start );
		if ( false === $ini ) {
			return '';
		}
		$ini += strlen( $start );
		$len  = strpos( $haystack, $end, $ini );
		if ( false === $len ) {
			return '';
		}
		$len -= $ini;
		return substr( $haystack, $ini, $len );
	}

	/**
	 * Strip quotation marks from a tag string, but preserve them when the
	 * value contains static-attribute-style literals (V5 keeps these). The
	 * regex `="..."` inside a tag means a static string was given (e.g.
	 * `<param name="brand">`); we leave that intact so it round-trips.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Tag-inner string.
	 * @return string
	 */
	private static function strip_quotes_unless_static( string $value ): string {
		$value = stripslashes( $value );
		if ( preg_match( '/="[a-zA-Z0-9 ]+"/', $value ) ) {
			return $value;
		}
		/**
		 * Filter the replacement used when stripping quotes from Custom
		 * Template 2 values.
		 *
		 * V5 6.6.x hook (CTX-904) — return a non-empty string to keep a
		 * stand-in character instead of removing quotes outright.
		 *
		 * @since 8.0.0
		 *
		 * @param string $replacement Replacement string (default '').
		 */
		$replacement = apply_filters( 'woo_feed_template_2_quotation', '' );

		return wp_unslash( str_replace( array( "'", '"', '&quot;' ), $replacement, $value ) );
	}
}
