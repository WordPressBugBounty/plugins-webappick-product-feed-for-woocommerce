<?php
/**
 * Custom2Template — Renders products using the user-supplied
 * `feed_config_custom2` template string (V5 "Custom Template 2 (XML)").
 *
 * Verbatim port of `V5\Template\Custom2Template`. Used for any feed whose
 * provider is one of {custom2, admarkt, yandex_xml, glami}; the
 * TemplateEngine routes the `xml` slot here at render time when the
 * provider matches. PROD-FRD-10.6.
 *
 * The body of the template (between `{{each product start}}` and
 * `{{each product end}}`) is parsed once per render by Custom2Structure
 * into an elements array. For each product, this class walks that
 * elements array and emits one product block. Sub-loops V5 handles:
 *
 *   • variation              — for variable products with children
 *   • ifVariationAvailable   — skip block when not a variable product
 *   • images                 — gallery image URLs (per-image element)
 *   • categories             — `product_cat` term hierarchy strings
 *
 * Other sub-loops parsed by Custom2Structure (shipping, tax, crossSale,
 * upSale, relatedProducts, associatedProduct) are NOT special-cased here
 * — V5's Custom2Template doesn't handle them either. Customers using
 * those loops in V5 saw a single rendered element rather than a true
 * loop; we preserve that behavior.
 *
 * Security note: V5 supports `{(return ...)}` and `{(php ...)}` blocks in
 * the user template, evaluated via `eval()`. The template is admin-authored
 * (manage_woocommerce capability), but eval() on user-supplied content is
 * still risky. We mirror V5 verbatim and gate the eval through the
 * `ctxfeed_custom2_eval_enabled` filter so security-conscious admins can
 * disable it without losing other features. Default: enabled (V5 parity).
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-9.1, TMPL-FRD-9.2, TMPL-FRD-9.3
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Filter\FilterManager;
use CTXFeed\V8\Product\AttributeResolver;
use CTXFeed\V8\Transform\CommandProcessor;
use CTXFeed\V8\Transform\StringTransform;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Template 2 (XML) renderer.
 *
 * @since 8.0.0
 */
class Custom2Template implements TemplateInterface {

	/**
	 * Template-string parser.
	 *
	 * @since 8.0.0
	 * @var Custom2Structure
	 */
	private $structure_parser;

	/**
	 * Attribute resolver — used to resolve `{attribute_name}` placeholders
	 * to product values.
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver;

	/**
	 * Cached parse result keyed by template hash, so the structure parser
	 * runs once per feed rather than once per product.
	 *
	 * @since 8.0.0
	 * @var array<string,array<string,mixed>>
	 */
	private $structure_cache = array();

	/**
	 * Lazily-built shared command executor for `formatter[]` chains
	 * (V5 OutputCommands parity). Carries the attribute resolver so the
	 * parent trio (only_parent / parent / parent_if_empty) can re-resolve
	 * against the parent product. XFRM-FRD-9.1.
	 *
	 * @since 8.0.0
	 * @var CommandProcessor|null
	 */
	private $command_processor = null;

	/**
	 * Product filter orchestrator — used to validate variation children in
	 * the `{{each variation start}}` sub-loop, mirroring V5's per-variation
	 * `ValidateProduct::is_valid()` call (V5 Custom2Template line 108).
	 * Optional; when null, variations render unfiltered.
	 *
	 * @since 8.0.0
	 * @var FilterManager|null
	 */
	private $filter_manager;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param Custom2Structure       $parser            Template-string parser.
	 * @param AttributeResolver|null $attribute_resolver Attribute resolver. Optional
	 *                                                  for tests; production wiring
	 *                                                  always passes one.
	 * @param FilterManager|null     $filter_manager    Product filter orchestrator
	 *                                                  for variation sub-loop
	 *                                                  validation (V5 parity).
	 */
	public function __construct( Custom2Structure $parser, ?AttributeResolver $attribute_resolver = null, ?FilterManager $filter_manager = null ) {
		$this->structure_parser   = $parser;
		$this->attribute_resolver = $attribute_resolver;
		$this->filter_manager     = $filter_manager;
	}

	/**
	 * Render the feed header — everything in the template before
	 * `{{each product start}}`. Template variables (`{(return …)}`) are
	 * evaluated. Backslash escapes are stripped to match V5.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string Header content.
	 */
	public function render_header( Config $config ): string {
		$template = (string) $config->get( 'feed_config_custom2', '' );
		if ( '' === $template ) {
			return '<?xml version="1.0" encoding="utf-8" ?>' . "\n";
		}

		$parts  = explode( Custom2Structure::PRODUCT_LOOP_START, $template );
		$header = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		$lines  = explode( "\n", $header );

		$out = '';
		foreach ( $lines as $line ) {
			$line = preg_replace( '/\\\\/', '', $line );
			if ( false !== strpos( $line, 'return' ) ) {
				$expression = $this->between( $line, '{(', ')}' );
				$value      = $this->evaluate_return( $expression, null, $config );
				$line       = preg_replace( '/\{\(.*?\)\}/', $value, $line );
			}
			$out .= $line . "\n";
		}

		if ( '' === trim( $out ) ) {
			$out = '<?xml version="1.0" encoding="utf-8" ?>' . "\n";
		}

		/**
		 * Filter the Custom2 header.
		 *
		 * @since 8.0.0
		 *
		 * @param string $out    Header content.
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_custom2_header', $out, $config );
	}

	/**
	 * Render one product block.
	 *
	 * @since 8.0.0
	 *
	 * @param array            $product_data Resolved key-value data (unused here;
	 *                                       Custom2 walks attributes via the
	 *                                       template's element list directly).
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Live product object. Required —
	 *                                       Custom2 cannot render without it.
	 *                                       If null, returns empty string.
	 *
	 * @return string XML for the product (or '' when no product passed).
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string {
		if ( null === $product ) {
			return '';
		}

		$parsed          = $this->get_structure( $config );
		$elements        = isset( $parsed['structure'] ) ? (array) $parsed['structure'] : array();
		$variation_start = isset( $parsed['variationElementsStart'] ) ? $parsed['variationElementsStart'] : null;

		if ( empty( $elements ) ) {
			return '';
		}

		$out = '';

		foreach ( $elements as $key => $element ) {
			// Variation sub-loop: at the marked element index, expand each
			// variation child product and emit the variation-bucket elements
			// for it. Verbatim from V5 lines 103-122.
			if ( $key === $variation_start
				&& $product->is_type( 'variable' )
				&& method_exists( $product, 'has_child' )
				&& $product->has_child()
			) {
				$variations = $product->get_children();
				foreach ( $variations as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation instanceof \WC_Product ) {
						continue;
					}
					// V5 validated every variation child through
					// ValidateProduct::is_valid() (stock/visibility/advance
					// filters — V5 Custom2Template line 108). FilterManager
					// is V8's equivalent orchestrator.
					if ( null !== $this->filter_manager && ! $this->filter_manager->should_include( $variation, $config ) ) {
						continue;
					}
					foreach ( $elements as $sub_element ) {
						if ( isset( $sub_element['for'] ) && 'variation' === $sub_element['for'] ) {
							$out .= $this->make_xml_element( $sub_element, $variation, $config );
						}
					}
				}
			}

			// Skip the variation-bucket elements at top level — they were
			// emitted in the inner loop above.
			if ( isset( $element['for'] ) && 'variation' === $element['for'] ) {
				continue;
			}

			// ifVariationAvailable bucket: skip when not a variable product.
			if ( isset( $element['for'] ) && 'ifVariationAvailable' === $element['for']
				&& 'variable' !== $product->get_type()
			) {
				continue;
			}

			// images sub-loop: emit one element per gallery image URL.
			if ( isset( $element['for'], $element['attr_code'] ) && 'images' === $element['for'] ) {
				$images = $this->get_image_urls( $product, $config );
				foreach ( $images as $image_url ) {
					$image_element                    = $element;
					$image_element['elementTextInfo'] = $image_url;
					$image_element['attr_type']       = 'text';
					$image_element['attr_value']      = $image_url;
					unset( $image_element['attr_code'] );
					$out .= $this->make_xml_element( $image_element, $product, $config );
				}
				continue;
			}

			// categories sub-loop: emit one element per category-path.
			if ( isset( $element['for'], $element['attr_code'] ) && 'categories' === $element['for'] ) {
				$categories = $this->get_category_paths( $product, $config );
				foreach ( $categories as $category ) {
					$cat_element                    = $element;
					$cat_element['elementTextInfo'] = $category;
					$cat_element['attr_type']       = 'text';
					$cat_element['attr_value']      = $category;
					unset( $cat_element['attr_code'] );
					$out .= $this->make_xml_element( $cat_element, $product, $config );
				}
				continue;
			}

			// Default: emit the element as-is for the parent product.
			$out .= $this->make_xml_element( $element, $product, $config );
		}

		/**
		 * Filter a single Custom2 product row.
		 *
		 * @since 8.0.0
		 *
		 * @param string      $out     Rendered product block.
		 * @param \WC_Product $product Product instance.
		 * @param Config      $config  Feed configuration.
		 */
		return apply_filters( 'ctxfeed_custom2_row', $out, $product, $config );
	}

	/**
	 * Render the feed footer — everything after `{{each product end}}`.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string Footer content.
	 */
	public function render_footer( Config $config ): string {
		$template = (string) $config->get( 'feed_config_custom2', '' );
		if ( '' === $template ) {
			return '';
		}

		$parts = explode( Custom2Structure::PRODUCT_LOOP_END, $template );
		if ( ! isset( $parts[1] ) ) {
			return '';
		}

		$footer = '';
		$lines  = explode( "\n", $parts[1] );
		foreach ( $lines as $line ) {
			$footer .= $line;
		}

		/**
		 * Filter the Custom2 footer.
		 *
		 * @since 8.0.0
		 *
		 * @param string $footer Footer content.
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_custom2_footer', $footer, $config );
	}

	// ---------------------------------------------------------------------
	// Element rendering
	// ---------------------------------------------------------------------

	/**
	 * Render a single parsed element for a product. Verbatim port of V5
	 * Custom2Template::make_xml_element().
	 *
	 * @since 8.0.0
	 *
	 * @param array       $element Parsed element row from Custom2Structure.
	 * @param \WC_Product $product Product (or variation).
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string XML fragment.
	 */
	private function make_xml_element( array $element, \WC_Product $product, Config $config ): string {
		$start  = '';
		$end    = '';
		$output = '';
		$wrote  = false;

		// Root element (no body, 6 keys, e.g. just `<offers>`): emit
		// opening tag with newline.
		if (
			empty( $element['elementTextInfo'] )
			&& empty( $element['end'] )
			&& 6 === count( $element )
		) {
			$tag    = $this->process_starting_tag( $element, $product, $config );
			$end   .= '<' . $tag . '>';
			$result = $end . "\n";
			$wrote  = true;
		} elseif ( ! empty( $element['start'] ) ) {
			$tag    = $this->process_starting_tag( $element, $product, $config );
			$start .= '<' . $tag . '>';
		}

		// Element body.
		if ( ! empty( $element['elementTextInfo'] ) ) {
			$output = $this->resolve_body_value( $element, $product, $config );

			// Apply `formatter[]` output commands (V5 OutputCommands parity —
			// V5 ran process_command() for ANY element carrying a formatter
			// key, regardless of attr_type; the attribute slug is only
			// forwarded for attr_type=attribute, matching V5's
			// $pluginAttribute). Pro-gated inside the processor.
			// XFRM-FRD-9.1.
			if ( isset( $element['formatter'] ) && is_array( $element['formatter'] ) ) {
				$attr = ( isset( $element['attr_type'], $element['attr_code'] ) && 'attribute' === $element['attr_type'] )
					? (string) $element['attr_code']
					: '';

				$output = (string) $this->command_processor()->process(
					$output,
					array( 'formatter' => $element['formatter'] ),
					$product,
					$config,
					$attr
				);
			}

			$output = str_replace( '&', '&amp;', $output );
			$wrote  = false;
		}

		// Closing tag.
		if (
			isset( $element['start'], $element['end'] )
			&& '/' . $element['end'] === $element['start']
			&& empty( $element['elementTextInfo'] )
			&& 6 === count( $element )
		) {
			if ( ! empty( $element['end'] ) ) {
				$end .= '<' . $element['start'] . '>';
			}
			$result = $end . "\n";
			$wrote  = true;
		} elseif ( ! empty( $element['end'] ) ) {
			$end .= '</' . $element['end'] . ">\n";
		}

		if ( ! $wrote ) {
			// Truthiness checks are deliberate V5 parity (V5 lines 304-308):
			// a literal-'0' prefix/suffix is dropped, and a literal-'0'
			// output skips the CDATA wrap. Quirky, but part of the V5
			// behavior contract.
			$prefix = isset( $element['prefix'] ) ? preg_replace( '!\s+!', ' ', $element['prefix'] ) : '';
			$suffix = isset( $element['suffix'] ) ? preg_replace( '!\s+!', ' ', $element['suffix'] ) : '';
			if ( $prefix ) {
				$output = $prefix . $output;
			}
			if ( $suffix ) {
				$output .= $suffix;
			}

			if ( ! empty( $output ) ) {
				$output = $this->add_cdata( isset( $element['include_cdata'] ) ? $element['include_cdata'] : 'no', $output );
			}

			$result = $start . $output . $end;
		}

		return isset( $result ) ? $result : '';
	}

	/**
	 * Resolve the body value of an element based on its attr_type.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $element Parsed element row.
	 * @param \WC_Product $product Product (or variation).
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_body_value( array $element, \WC_Product $product, Config $config ): string {
		$type = isset( $element['attr_type'] ) ? $element['attr_type'] : 'text';

		if ( 'attribute' === $type ) {
			$attr_code = isset( $element['attr_code'] ) ? (string) $element['attr_code'] : '';
			$value     = (string) $this->resolve_attribute( $attr_code, $product, $config );

			// V5 ran the user's find/replace rules on every attribute body
			// (ProductHelper::str_replace, V5 Custom2Template line 256) —
			// but NOT on start-tag codes or `$var` eval substitutions.
			// Match that exactly. Rules live in feedrules `str_replace`.
			$rules = (array) $config->get( 'str_replace', array() );
			if ( ! empty( $rules ) && '' !== $attr_code ) {
				$value = StringTransform::apply_str_replace_rules( $value, $attr_code, $attr_code, $rules );
			}

			return $value;
		}

		if ( 'return' === $type ) {
			$expression = isset( $element['to_return'] ) ? $element['to_return'] : '';
			// V5 `round(...)` shortcut.
			if ( '' !== $expression && preg_match( '/\bround\b/', $expression ) ) {
				$expression = preg_replace( '/round\(|\)/', '', $expression );
				$value      = $this->evaluate_return( $expression, $product, $config );
				return (string) round( (float) $value );
			}
			return (string) $this->evaluate_return( $expression, $product, $config );
		}

		if ( 'php' === $type ) {
			// V5 just returns the literal back; preserved for parity.
			return isset( $element['to_return'] ) ? (string) $element['to_return'] : '';
		}

		// 'text' (or unknown) — literal.
		return isset( $element['attr_value'] ) ? (string) $element['attr_value'] : '';
	}

	/**
	 * Resolve a single attribute value via the AttributeResolver.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $attr    Attribute slug (e.g. 'title', 'price', 'wf_attr_color').
	 * @param \WC_Product $product Product.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	private function resolve_attribute( string $attr, \WC_Product $product, Config $config ): string {
		if ( '' === $attr ) {
			return '';
		}

		if ( null === $this->attribute_resolver ) {
			return '';
		}

		$value = $this->attribute_resolver->resolve(
			$product,
			array(
				'type'    => 'attribute',
				'wc_attr' => $attr,
			),
			$config
		);

		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}

		return (string) $value;
	}

	/**
	 * Process the starting tag string, substituting `{placeholder}` /
	 * `{(return …)}` start-codes with quoted attribute values.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $element Parsed element row.
	 * @param \WC_Product $product Product.
	 * @param Config      $config  Feed configuration.
	 * @return string Resolved tag string (no surrounding angle brackets).
	 */
	private function process_starting_tag( array $element, \WC_Product $product, Config $config ): string {
		$tag = isset( $element['start'] ) ? stripslashes( $element['start'] ) : '';
		if ( empty( $element['start_code'] ) || ! is_array( $element['start_code'] ) ) {
			return $tag;
		}

		$replacements = array();
		foreach ( $element['start_code'] as $placeholder ) {
			if ( false !== strpos( $placeholder, 'return' ) ) {
				$expression                                   = $this->between( $placeholder, '{(', ')}' );
				$value                                        = $this->evaluate_return( $expression, $product, $config );
				$replacements[ stripslashes( $placeholder ) ] = $this->quote( (string) $value );
			} else {
				$attr_code                    = $this->between( $placeholder, '{', '}' );
				$value                        = $this->resolve_attribute( $attr_code, $product, $config );
				$replacements[ $placeholder ] = $this->quote( (string) $value );
			}
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $tag );
	}

	/**
	 * Quote a value for inclusion in a starting tag attribute.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Raw value.
	 * @return string Single-quoted, HTML-special-chars-escaped value.
	 */
	private function quote( string $value ): string {
		return "'" . str_replace( array( "'", '"', '&quot;' ), '', htmlspecialchars( $value ) ) . "'";
	}

	/**
	 * Evaluate a `{(return …)}` expression. Variables prefixed with `$`
	 * inside the expression are resolved against the product's attribute
	 * map and `extract()`ed before eval. Mirrors V5's
	 * Custom2Template::getReturnTypeValue().
	 *
	 * Eval is gated through the `ctxfeed_custom2_eval_enabled` filter
	 * (default true). Disabling the filter returns an empty string from
	 * any return/eval expression.
	 *
	 * @since 8.0.0
	 *
	 * @param string           $expression Expression body (no surrounding `{(`/`)}`).
	 * @param \WC_Product|null $product    Product context, or null for header eval.
	 * @param Config           $config     Feed configuration.
	 *
	 * @return string Result, cast to string. Empty on any error or when
	 *                eval is disabled.
	 */
	private function evaluate_return( string $expression, ?\WC_Product $product, Config $config ): string {
		$expression = trim( (string) $expression );
		if ( '' === $expression ) {
			return '';
		}

		/**
		 * Filter whether `{(return …)}` and `{(php …)}` expressions in the
		 * Custom2 template are evaluated. Default: true (V5 parity). Site
		 * owners with stricter security policies can return false to
		 * disable eval entirely — header/body return-blocks then yield ''.
		 *
		 * @since 8.0.0
		 *
		 * @param bool   $enabled    True to allow eval.
		 * @param Config $config     Feed configuration.
		 */
		$enabled = (bool) apply_filters( 'ctxfeed_custom2_eval_enabled', true, $config );
		if ( ! $enabled ) {
			return '';
		}

		// Resolve `$variable` references against the product's attributes.
		$variables = array();
		if ( null !== $product && false !== strpos( $expression, '$' ) ) {
			preg_match_all( '/\$\S+/', $expression, $matches, PREG_SET_ORDER );
			$names = array_column( $matches, 0 );
			foreach ( $names as $raw ) {
				$name = str_replace( array( '$', ';' ), '', $raw );
				if ( '' !== $name ) {
					$variables[ $name ] = $this->resolve_attribute( $name, $product, $config );
				}
			}
		}

		// Strip any backslash escapes V5 also strips before eval.
		$expression = preg_replace( '/\\\\/', '', $expression );

		// Sandbox the eval — extract scalars locally, then eval.
		try {
			extract( $variables, EXTR_OVERWRITE ); // phpcs:ignore WordPress.PHP.DontExtract -- CT2 templates declare their own variables for the {(return ...)} block; extract() is how V5 exposed them and the names are author-defined.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Owner-approved CT2 {(return ...)} evaluation of admin-authored template code; hardened with try/catch + scalar cast and disableable via ctxfeed_custom2_eval_enabled.
			$result = eval( $expression );
		} catch ( \Throwable $e ) {
			$result = '';
		}

		return is_scalar( $result ) ? (string) $result : '';
	}

	// ---------------------------------------------------------------------
	// Sub-loop data sources
	// ---------------------------------------------------------------------

	/**
	 * Gallery image URLs for the images sub-loop.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product.
	 * @param Config      $config  Feed configuration.
	 * @return string[]
	 */
	private function get_image_urls( \WC_Product $product, Config $config ): array {
		$ids = array();
		if ( method_exists( $product, 'get_image_id' ) ) {
			$featured = (int) $product->get_image_id();
			if ( $featured > 0 ) {
				$ids[] = $featured;
			}
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$gid = (int) $gid;
				if ( $gid > 0 ) {
					$ids[] = $gid;
				}
			}
		}

		$urls = array();
		foreach ( array_unique( $ids ) as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		/**
		 * Filter the per-product gallery image URLs used by Custom2's
		 * images sub-loop.
		 *
		 * @since 8.0.0
		 *
		 * @param string[]    $urls    Image URLs.
		 * @param \WC_Product $product Product instance.
		 * @param Config      $config  Feed configuration.
		 */
		return (array) apply_filters( 'ctxfeed_custom2_image_urls', $urls, $product, $config );
	}

	/**
	 * Category-path strings for the categories sub-loop. Each entry is
	 * a fully-qualified category path joined by the configured separator
	 * (default ` > `, V5 parity).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product.
	 * @param Config      $config  Feed configuration.
	 * @return string[]
	 */
	private function get_category_paths( \WC_Product $product, Config $config ): array {
		$pid       = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$term_ids  = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
		$separator = apply_filters( 'woo_feed_filter_category_separator', ' > ', $product, $config );

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return array();
		}

		$paths = array();
		foreach ( $term_ids as $term_id ) {
			$names = array();
			foreach ( get_ancestors( $term_id, 'product_cat' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$names[] = $ancestor->name;
				}
			}
			$leaf = get_term( $term_id, 'product_cat' );
			if ( $leaf && ! is_wp_error( $leaf ) ) {
				$names[] = $leaf->name;
			}
			$paths[] = implode( $separator, $names );
		}

		return $paths;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Wrap with CDATA when the parsed element marked it.
	 *
	 * @since 8.0.0
	 *
	 * @param string $status 'yes' when the parsed element requested CDATA wrapping.
	 * @param string $output Rendered element value.
	 *
	 * @return string Value, CDATA-wrapped when requested.
	 */
	private function add_cdata( string $status, string $output ): string {
		if ( 'yes' !== $status ) {
			return $output;
		}
		$clean = str_replace( array( '<![CDATA[', ']]>' ), '', $output );
		return '<![CDATA[' . $clean . ']]>';
	}

	/**
	 * Inline `between` helper (same shape as Custom2Structure's).
	 *
	 * @param string $haystack String to search in.
	 * @param string $start    Opening marker.
	 * @param string $end      Closing marker.
	 *
	 * @return string Substring between the markers, or empty string when absent.
	 */
	private function between( string $haystack, string $start, string $end ): string {
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
	 * Shared command executor, built on first use with this template's
	 * attribute resolver (may be null in tests — parent trio then no-ops).
	 *
	 * @since 8.0.0
	 * @return CommandProcessor
	 */
	private function command_processor(): CommandProcessor {
		if ( null === $this->command_processor ) {
			$this->command_processor = new CommandProcessor( $this->attribute_resolver );
		}

		return $this->command_processor;
	}

	/**
	 * Get the parsed structure for the active feed config, caching by
	 * the template-string hash so the parser runs once per feed.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return array{variationElementsStart: int|null, structure: array<int,array<string,mixed>>}
	 */
	private function get_structure( Config $config ): array {
		$key = md5( (string) $config->get( 'feed_config_custom2', '' ) );
		if ( ! isset( $this->structure_cache[ $key ] ) ) {
			$this->structure_cache[ $key ] = $this->structure_parser->parse( $config );
		}
		return $this->structure_cache[ $key ];
	}
}
