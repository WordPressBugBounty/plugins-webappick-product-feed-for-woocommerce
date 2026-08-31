<?php
/**
 * XMLTemplate — Renders products as XML items.
 *
 * Handles channel-specific XML wrappers (Google Shopping RSS, Skroutz,
 * BestPrice, etc.) by loading template files from admin/partials/templates/.
 * Supports template variables ({BlogName}, {BlogURL}, {DateTimeNow}, etc.),
 * extraHeader injection, configurable CDATA wrapping, and fires four
 * filter hooks: ctxfeed_xml_header, ctxfeed_xml_item_wrapper,
 * ctxfeed_xml_row, ctxfeed_xml_footer for full extensibility.
 * Also fires the legacy V5 `woo_feed_product_item_wrapper` filter so
 * existing V5 Override classes (Heureka SK, Zbozi.cz) work without changes.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-4.1, TMPL-FRD-4.2, TMPL-FRD-4.3, TMPL-FRD-4.4
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Channel\FeedWrapper;
use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML template with channel-specific wrappers and CDATA escaping.
 *
 * @since 8.0.0
 */
class XMLTemplate implements TemplateInterface {

	/**
	 * Render the XML header.
	 *
	 * Loads channel-specific template file if available (e.g., google.txt,
	 * skroutz.txt), splits on {separator}, replaces template variables,
	 * and appends extraHeader. Falls back to generic XML wrapper if no
	 * template file exists.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-4.1
	 * @hook ctxfeed_xml_header Filter to modify XML header.
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string XML header content.
	 */
	public function render_header( Config $config ): string {
		$provider = $config->get_provider();
		$template = $this->load_channel_template( $provider );

		// The Extra header field is optional and overloaded. Pull an optional
		// XML declaration OVERRIDE out of it first — a custom version/encoding
		// the user wants at the very top. The remainder (a namespace like
		// `xmlns:g="…"`, or element content like `<generated_by>…`) is applied
		// to the body below. The feed always emits exactly ONE declaration.
		$raw          = (string) $config->get( 'extraHeader', '' );
		$extra_header = '' === $raw ? '' : trim( (string) wp_unslash( $raw ) );
		$declaration  = '<?xml version="1.0" encoding="UTF-8"?>';
		if ( '' !== $extra_header && preg_match( '/<\?xml\s+version\b[^>]*\?>/is', $extra_header, $m ) ) {
			$declaration  = trim( $m[0] );
			$extra_header = trim( str_replace( $m[0], '', $extra_header ) );
		}

		if ( ! empty( $template ) ) {
			// Channel-specific template found — split on {separator}.
			$parts  = explode( '{separator}', $template );
			$header = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		} else {
			// Fallback: generic wrapper — the opening root element only.
			$header = '<' . $this->items_wrapper( $config ) . '>';
		}

		// A template must not carry its own declaration; the single one is
		// prepended below so it is never duplicated.
		$header = trim( (string) preg_replace( '#^<\?xml\s+version\b[^>]*\?>\s*#is', '', $header ) );

		// Apply the remaining Extra header. Bare attribute(s) (a namespace) go
		// ON the root element — a valid declaration — while element content
		// nests inside the root after its opening tag.
		if ( '' !== $extra_header ) {
			if ( false === strpos( $extra_header, '<' ) ) {
				$header = $this->inject_root_attributes( $header, $extra_header );
			} else {
				$header .= PHP_EOL . $extra_header;
			}
		}

		// Replace template variables.
		$header = $this->replace_template_variables( $header, $config );

		// Exactly one declaration leads the document (the default, or the user
		// override). Strict parsers need it to read non-ASCII product text with
		// the declared encoding — V5 parity (V5/File/XML.php:261).
		$header = $declaration . PHP_EOL . $header;

		/**
		 * Filter the XML header output.
		 *
		 * @since 8.0.0
		 *
		 * @param string $header XML header string.
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_xml_header', $header, $config );
	}

	/**
	 * Inject bare attribute(s) onto the feed's root element opening tag.
	 *
	 * `extraHeader` values such as `xmlns:g="http://base.google.com/ns/1.0"`
	 * are namespace declarations: they are only valid AS attributes on the
	 * root element (`<products xmlns:g="…">`), never as a separate line after
	 * the opening tag (which strict parsers reject with "Namespace prefix g …
	 * is not defined"). This finds the first element opening tag — skipping
	 * the `<?xml …?>` declaration and any `<!-- … -->` comment — and inserts
	 * the attribute(s) just before that tag's closing `>` (or before the `/`
	 * on a self-closing tag).
	 *
	 * @since 8.0.0
	 *
	 * @param string $header XML header built so far.
	 * @param string $attrs  Trimmed attribute string (no surrounding markup).
	 *
	 * @return string Header with the attribute(s) placed on the root element.
	 */
	private function inject_root_attributes( string $header, string $attrs ): string {
		if ( '' === $attrs ) {
			return $header;
		}

		// First element opening tag. The "(?![?!])" lookahead skips the XML
		// declaration and any comment / doctype node.
		if ( ! preg_match( '/<(?![?!])[^>]*>/', $header, $m, PREG_OFFSET_CAPTURE ) ) {
			// No root element found — keep the value rather than drop it.
			return $header . PHP_EOL . $attrs;
		}

		$gt        = $m[0][1] + strlen( $m[0][0] ) - 1;                          // Index of the tag's ">".
		$insert_at = ( $gt > 0 && '/' === $header[ $gt - 1 ] ) ? $gt - 1 : $gt;  // Before "/" on self-closing tags.

		return substr( $header, 0, $insert_at ) . ' ' . $attrs . substr( $header, $insert_at );
	}

	/**
	 * Render a single product as an XML item.
	 *
	 * Each product data key-value pair becomes an XML element.
	 * Values are escaped based on global CDATA setting or special
	 * character detection. Applies the `ctxfeed_xml_row` filter.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-4.2
	 * @hook ctxfeed_xml_row Filter to modify XML row.
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Product object (reserved for row-level hooks; part of the shared template signature).
	 *
	 * @return string XML row content.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature.
		$item_wrapper = $this->item_wrapper( $config );

		/**
		 * Filter the XML item wrapper element name.
		 *
		 * V8 equivalent of V5's `woo_feed_product_item_wrapper` filter.
		 * Used by Heureka SK and Zbozi.cz to change wrapper to SHOPITEM.
		 *
		 * @since 8.0.0
		 *
		 * @param string $item_wrapper Item wrapper element name.
		 * @param Config $config       Feed configuration.
		 */
		$item_wrapper = apply_filters( 'ctxfeed_xml_item_wrapper', $item_wrapper, $config );

		// Legacy V5 hook — allows existing V5 Override classes to work without modification.
		$item_wrapper = apply_filters( 'woo_feed_product_item_wrapper', $item_wrapper );

		$xml = "\t<{$item_wrapper}>" . PHP_EOL;

		$xml .= $this->render_elements( $product_data, 2 );

		$xml .= "\t</{$item_wrapper}>";

		/**
		 * Filter a single XML product row.
		 *
		 * @since 8.0.0
		 *
		 * @param string $xml          XML row string.
		 * @param array  $product_data Product data key-value pairs.
		 * @param Config $config       Feed configuration.
		 */
		return apply_filters( 'ctxfeed_xml_row', $xml, $product_data, $config );
	}

	/**
	 * Render the XML footer.
	 *
	 * Loads channel-specific template file if available and extracts
	 * the footer portion (after {separator}). Falls back to closing
	 * the wrapper element. Applies the `ctxfeed_xml_footer` filter.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-4.3
	 * @hook ctxfeed_xml_footer Filter to modify XML footer.
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string XML footer content.
	 */
	public function render_footer( Config $config ): string {
		$provider = $config->get_provider();
		$template = $this->load_channel_template( $provider );

		if ( ! empty( $template ) ) {
			// Channel-specific template found — footer is after {separator}.
			$parts  = explode( '{separator}', $template );
			$footer = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		} else {
			// Fallback: close generic wrapper.
			$wrapper = $this->items_wrapper( $config );
			$footer  = "</{$wrapper}>";
		}

		/**
		 * Filter the XML footer output.
		 *
		 * @since 8.0.0
		 *
		 * @param string $footer XML footer string.
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_xml_footer', $footer, $config );
	}

	/**
	 * Load a channel-specific XML template file.
	 *
	 * Looks for {provider}.txt in admin/partials/templates/. These files
	 * contain header and footer separated by {separator}. Returns empty
	 * string if no template file exists for the provider.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug (e.g., 'google', 'skroutz').
	 *
	 * @return string Raw template content, or empty string if not found.
	 */
	private function load_channel_template( string $provider ): string {
		if ( empty( $provider ) ) {
			return '';
		}

		// Resolve the templates directory (V8-owned once relocated, else the
		// shared admin/partials/templates/ path). See TemplateLocator.
		$template_dir = TemplateLocator::dir();

		if ( empty( $template_dir ) ) {
			return '';
		}

		$file = $template_dir . sanitize_file_name( $provider ) . '.txt';

		if ( ! file_exists( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local bundled template file (path built from TemplateLocator::dir()), never a remote URL.
		$content = file_get_contents( $file );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Replace template variables in header/footer content.
	 *
	 * Supported variables:
	 * - {DateTimeNow}     — Current date/time in Y-m-d H:i:s format.
	 * - {BlogName}        — Site name from get_bloginfo('name').
	 * - {BlogURL}         — Site URL from get_bloginfo('url').
	 * - {BlogDescription} — CTX Feed attribution description.
	 * - {BlogEmail}       — Admin email from get_bloginfo('admin_email').
	 *
	 * @since 8.0.0
	 *
	 * @param string $content Template content with variables.
	 * @param Config $config  Feed configuration.
	 *
	 * @return string Content with variables replaced.
	 */
	private function replace_template_variables( string $content, Config $config ): string {
		$variables = array(
			'{DateTimeNow}'            => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) ),
			'{BlogName}'               => get_bloginfo( 'name' ),
			'{BlogURL}'                => get_bloginfo( 'url' ),
			'{BlogDescription}'        => 'CTX Feed - This product feed is generated with the CTX Feed - WooCommerce Product Feed Manager plugin by WebAppick.com. For all your support questions check out our plugin Docs on https://webappick.com/docs or e-mail to: support@webappick.com',
			'{BlogEmail}'              => get_bloginfo( 'admin_email' ),
			// Google review feed only — the <publisher><favicon> element.
			// Empty (removed) unless a favicon is available.
			'{ReviewPublisherFavicon}' => $this->review_publisher_favicon( $config ),
		);

		/**
		 * Filter template variables for XML header/footer.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $variables Key-value map of template variables.
		 * @param Config $config    Feed configuration.
		 */
		$variables = apply_filters( 'ctxfeed_xml_header_template_variables', $variables, $config );

		return str_replace( array_keys( $variables ), array_values( $variables ), $content );
	}

	/**
	 * Build the Google review feed's optional <publisher><favicon> element.
	 *
	 * XSD 2.4: <publisher> may carry a <favicon> (16×16 GIF/JPG/PNG). It
	 * defaults to the site's WordPress Site Icon (Appearance → Customize →
	 * Site Identity) and can be overridden with the
	 * `ctxfeed_review_publisher_favicon` filter. Returns an empty string —
	 * which removes the template placeholder entirely — when no favicon is
	 * available or the feed is not a Google review feed, so a missing
	 * favicon never emits an empty (invalid) element.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string The favicon element (with leading newline/indent) or ''.
	 */
	private function review_publisher_favicon( Config $config ): string {
		if ( 'googlereview' !== $config->get_provider() ) {
			return '';
		}

		$default = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 16 ) : '';

		/**
		 * Filter the Google review feed's <publisher><favicon> URL.
		 *
		 * Defaults to the WordPress Site Icon (16×16). Return an empty
		 * string to omit the <favicon> element.
		 *
		 * @since 8.0.0
		 *
		 * @param string $url    Favicon URL.
		 * @param Config $config Feed configuration.
		 */
		$url = trim( (string) apply_filters( 'ctxfeed_review_publisher_favicon', $default, $config ) );

		if ( '' === $url ) {
			return '';
		}

		return "\n\t<favicon>" . esc_url( $url ) . '</favicon>';
	}

	/**
	 * Escape a value for safe XML output.
	 *
	 * Array values are joined with comma separator. CDATA wrapping is
	 * controlled by the global 'enable_cdata' setting. If CDATA is
	 * disabled, values with special XML characters are HTML-encoded.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-4.4
	 *
	 * @param mixed $value Raw value to escape.
	 *
	 * @return string Escaped value safe for XML output.
	 */
	private function escape_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		$value = (string) $value;

		// Check global CDATA setting.
		$cdata_enabled = $this->is_cdata_enabled();

		if ( $cdata_enabled && '' !== $value ) {
			// Strip existing CDATA markers, then wrap fresh.
			$clean = str_replace( array( '<![CDATA[', ']]>' ), '', html_entity_decode( $value ) );
			return '<![CDATA[' . $clean . ']]>';
		}

		// No global CDATA — only wrap if value contains special XML characters.
		if ( preg_match( '/[<>&]/', $value ) ) {
			// Neutralise any embedded CDATA terminator: a product field
			// containing the literal "]]>" would otherwise close the section
			// early and corrupt the ENTIRE feed. The split-and-rejoin
			// (`]]]]><![CDATA[>`) is the canonical way to carry a literal "]]>"
			// inside CDATA, preserving the content exactly.
			$safe = str_replace( ']]>', ']]]]><![CDATA[>', $value );
			return '<![CDATA[' . $safe . ']]>';
		}

		return $value;
	}

	/**
	 * Recursively render elements to XML.
	 *
	 * Handles three data shapes:
	 * - Scalar values: `<key>value</key>`
	 * - Associative arrays: `<key><sub_key>value</sub_key></key>` (grouped attributes)
	 * - Numeric-indexed arrays: repeated elements without extra wrapper (repeating groups)
	 *
	 * This matches V5's array_to_xml() behavior from V5/File/XML.php,
	 * enabling nested XML for grouped attributes like g:tax, g:shipping,
	 * g:installment, product_detail, product_highlight, etc.
	 *
	 * @since 8.0.0
	 *
	 * @param array $elements   Key-value pairs (may contain nested arrays).
	 * @param int   $depth      Current indentation depth (tabs).
	 *
	 * @return string XML elements string.
	 */
	private function render_elements( array $elements, int $depth = 2 ): string {
		$xml    = '';
		$indent = str_repeat( "\t", $depth );

		foreach ( $elements as $key => $value ) {
			// XML element names cannot contain spaces, so a user-typed Custom
			// Template channel attribute like "product type" must emit the
			// element <product_type>, not the invalid <product type>. Numeric
			// keys are repeating-group indices and are never used as a tag name,
			// so leave them untouched. @see sanitize_xml_name().
			$tag = is_numeric( $key ) ? (string) $key : $this->sanitize_xml_name( (string) $key );

			if ( is_array( $value ) ) {
				if ( array_key_exists( '@attributes', $value ) || array_key_exists( '@value', $value ) ) {
					// Element carrying XML attributes and/or text content:
					// [ '@attributes' => [ 'name' => 'X' ], '@value' => 'Y' ]
					// renders as <key name="X">Y</key>. Used by the Skroutz
					// <specifications><spec name="…">value</spec> group.
					$attrs = '';
					if ( ! empty( $value['@attributes'] ) && is_array( $value['@attributes'] ) ) {
						foreach ( $value['@attributes'] as $attr_name => $attr_val ) {
							$attrs .= ' ' . $this->sanitize_xml_name( (string) $attr_name ) . '="' . esc_attr( (string) $attr_val ) . '"';
						}
					}
					$text = array_key_exists( '@value', $value ) ? $this->escape_value( $value['@value'] ) : '';
					$xml .= $indent . "<{$tag}{$attrs}>{$text}</{$tag}>" . PHP_EOL;
					continue;
				}
				if ( is_numeric( $key ) ) {
					// Numeric key: repeating group — recurse without extra wrapper.
					// Example: multiple g:shipping or product_highlight entries.
					$xml .= $this->render_elements( $value, $depth );
				} elseif ( $this->is_scalar_list( $value ) ) {
					// String key holding a PLAIN LIST of scalars (e.g. a
					// filter returning ['summer','cotton']): render one
					// leaf with escape_value's comma-join. Without this
					// branch the numeric child keys rendered as
					// <0>…</0><1>…</1> — INVALID XML (element names
					// cannot start with a digit).
					$escaped = $this->escape_value( $value );
					$xml    .= $indent . "<{$tag}>{$escaped}</{$tag}>" . PHP_EOL;
				} else {
					// Associative key: grouped attribute wrapper.
					// Example: g:tax => [ g:country => 'US', g:rate => '8.5' ].
					$xml .= $indent . "<{$tag}>" . PHP_EOL;
					$xml .= $this->render_elements( $value, $depth + 1 );
					$xml .= $indent . "</{$tag}>" . PHP_EOL;
				}
			} else {
				// Scalar value: leaf element.
				$value = $this->escape_value( $value );
				$xml  .= $indent . "<{$tag}>{$value}</{$tag}>" . PHP_EOL;
			}
		}

		return $xml;
	}

	/**
	 * Whether an array is a plain list of scalar values (sequential
	 * numeric keys, no nested arrays) — the shape that must render as
	 * ONE comma-joined leaf element rather than recursing into invalid
	 * numeric-named children.
	 *
	 * @since 8.0.0
	 *
	 * @param array $value Candidate array.
	 * @return bool
	 */
	private function is_scalar_list( array $value ): bool {
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}

		foreach ( $value as $entry ) {
			if ( ! is_scalar( $entry ) && null !== $entry ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve the per-product XML element name (the "item wrapper").
	 *
	 * The canonical wrapper is decided by the CHANNEL, not the stored
	 * feedrules — Google & every Google-spec channel wrap each product in
	 * `<item>`, googlereview in `<review>`, etc. (see Channel\FeedWrapper,
	 * V5 parity). Resolving here — rather than mutating the Config upstream —
	 * keeps the opening item tag, the closing item tag AND the file footer
	 * consistent even though the footer renders from a separately-built
	 * Config in FeedGenerator::finalize(). Channels with no canonical wrapper
	 * (legacy `<products>` merchants, Custom templates) fall back to the
	 * stored `itemWrapper` (default `product`).
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string Element name, spaces normalised to underscores.
	 */
	private function item_wrapper( Config $config ): string {
		$canonical = FeedWrapper::xml_wrapper( $config->get_provider() );
		$wrapper   = null !== $canonical
			? $canonical['item']
			: (string) $config->get( 'itemWrapper', 'product' );

		return $this->sanitize_xml_name( $wrapper );
	}

	/**
	 * Make a string safe to use as an XML element (or attribute) name.
	 *
	 * XML names cannot contain whitespace, so a user-typed Custom Template
	 * channel attribute like "product type" would otherwise emit the invalid
	 * element `<product type>`. Collapse any run of whitespace to a single
	 * underscore and drop leading/trailing whitespace — the same normalisation
	 * the item/items wrappers use. Only whitespace is touched, so namespaced
	 * names (`g:id`) and already-valid names pass through unchanged. Applies to
	 * every XML feed built through this template — Custom Template 1 & 2 and any
	 * channel that renders on the custom XML base.
	 *
	 * @since 8.0.0
	 *
	 * @param string $name Raw element/attribute name.
	 * @return string XML-safe name.
	 */
	private function sanitize_xml_name( string $name ): string {
		return (string) preg_replace( '/\s+/', '_', trim( $name ) );
	}

	/**
	 * Resolve the outer wrapper element name (the "items wrapper").
	 *
	 * Only used for channels WITHOUT an XML template file (google.txt etc.
	 * supply their own `<channel>` header/footer); for those it must open and
	 * close the same element. Channel-canonical value wins, else the stored
	 * `itemsWrapper` (default `products`).
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string Element name, spaces normalised to underscores.
	 */
	private function items_wrapper( Config $config ): string {
		$canonical = FeedWrapper::xml_wrapper( $config->get_provider() );
		$wrapper   = null !== $canonical
			? $canonical['items']
			: (string) $config->get( 'itemsWrapper', 'products' );

		return $this->sanitize_xml_name( $wrapper );
	}

	/**
	 * Check if global CDATA wrapping is enabled.
	 *
	 * Reads the CTX Feed 'enable_cdata' setting. Matches V5 behavior
	 * from Settings::get('enable_cdata').
	 *
	 * @since 8.0.0
	 *
	 * @return bool True if CDATA wrapping is globally enabled.
	 */
	private function is_cdata_enabled(): bool {
		// V5 stores this in woo_feed_settings option.
		$settings = get_option( 'woo_feed_settings', array() );
		// Default ON when unset or blank (product decision); an explicit 'no'
		// is still respected.
		$enabled = $settings['enable_cdata'] ?? 'yes';
		if ( '' === $enabled ) {
			$enabled = 'yes';
		}

		/**
		 * Filter CDATA enable/disable setting.
		 *
		 * @since 8.0.0
		 *
		 * @param string $enabled CDATA setting value ('yes' or 'no').
		 */
		$enabled = apply_filters( 'ctxfeed_xml_cdata_enabled', $enabled );

		return 'yes' === $enabled;
	}
}
