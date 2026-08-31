<?php
/**
 * JSONTemplate — Renders products as JSON objects.
 *
 * Outputs a JSON array with one object per product. Uses wp_json_encode
 * with JSON_UNESCAPED_UNICODE and JSON_UNESCAPED_SLASHES flags.
 * Fires `ctxfeed_json_row` filter for row extensibility.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-6.1, TMPL-FRD-6.2, TMPL-FRD-6.3
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON template with wp_json_encode.
 *
 * @since 8.0.0
 */
class JSONTemplate implements TemplateInterface {

	/**
	 * Render the JSON header.
	 *
	 * Returns the opening JSON array bracket.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-6.1
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Opening bracket.
	 */
	public function render_header( Config $config ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; other formats read $config.
		return '[';
	}

	/**
	 * Render a single product as a JSON object.
	 *
	 * Uses wp_json_encode with unescaped Unicode and slashes.
	 * Applies the `ctxfeed_json_row` filter for extensibility.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-6.2
	 * @hook ctxfeed_json_row Filter to modify JSON row.
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Product object (unused by JSON; part of the shared template signature).
	 *
	 * @return string JSON-encoded product object.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; XML uses $product.
		$json = wp_json_encode( $product_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		/**
		 * Filter a single JSON product row.
		 *
		 * @since 8.0.0
		 *
		 * @param string $json         JSON-encoded product string.
		 * @param array  $product_data Product data key-value pairs.
		 * @param Config $config       Feed configuration.
		 */
		return apply_filters( 'ctxfeed_json_row', $json, $product_data, $config );
	}

	/**
	 * Render the JSON footer.
	 *
	 * Returns the closing JSON array bracket.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-6.3
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Closing bracket.
	 */
	public function render_footer( Config $config ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; other formats read $config.
		return ']';
	}
}
