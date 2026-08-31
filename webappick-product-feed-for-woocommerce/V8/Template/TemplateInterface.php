<?php
/**
 * TemplateInterface — Contract for all format-specific templates.
 *
 * Each template renders products in a specific output format (XML, CSV,
 * JSON, TXT). The three methods map to the feed file lifecycle:
 * header → rows → footer.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-2.1
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template interface for format-specific rendering.
 *
 * @since 8.0.0
 */
interface TemplateInterface {

	/**
	 * Render the feed header.
	 *
	 * Returns format-specific header content such as XML declaration,
	 * CSV column names, or JSON opening bracket.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Header content.
	 */
	public function render_header( Config $config ): string;

	/**
	 * Render a single product as a feed row.
	 *
	 * @since 8.0.0
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Optional product instance. The
	 *                                       Custom Template 2 (XML) renderer
	 *                                       needs the live product to walk
	 *                                       sub-loops (variations/images/
	 *                                       categories) defined in the
	 *                                       user-supplied template string.
	 *                                       Other templates may ignore this
	 *                                       argument. Defaults to null for
	 *                                       back-compat with older callers
	 *                                       (e.g. unit tests).
	 *
	 * @return string Rendered row content.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string;

	/**
	 * Render the feed footer.
	 *
	 * Returns format-specific footer content such as XML closing tag,
	 * JSON closing bracket, or empty string for flat formats.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Footer content.
	 */
	public function render_footer( Config $config ): string;
}
