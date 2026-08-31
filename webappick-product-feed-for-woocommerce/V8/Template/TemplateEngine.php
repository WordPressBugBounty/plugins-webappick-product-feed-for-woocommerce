<?php
/**
 * TemplateEngine — Routes format string to correct template implementation.
 *
 * Accepts a format-keyed map of TemplateInterface instances via DI
 * constructor injection (no internal `new` calls). Delegates rendering
 * to the correct template based on feed config format.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-3.1, TMPL-FRD-3.2, TMPL-FRD-3.3
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template engine with DI-injected format map.
 *
 * @since 8.0.0
 */
class TemplateEngine {

	/**
	 * Merchant providers that opt into the user-supplied
	 * `feed_config_custom2` template (V5 grouping; verbatim).
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private const CUSTOM2_PROVIDERS = array( 'custom2', 'admarkt', 'yandex_xml', 'glami' );

	/**
	 * Format-keyed map of template instances.
	 *
	 * @since 8.0.0
	 * @var TemplateInterface[]
	 */
	private $templates;

	/**
	 * Optional Custom Template 2 (XML) renderer. When set AND the feed's
	 * provider is one of CUSTOM2_PROVIDERS AND feedType is xml, the engine
	 * routes to this template instead of the default xml entry. Optional
	 * so older tests / wiring still construct cleanly. PROD-FRD-10.6.
	 *
	 * @since 8.0.0
	 * @var TemplateInterface|null
	 */
	private $custom2_template;

	/**
	 * Construct TemplateEngine with DI-injected format map.
	 *
	 * Templates SHALL NOT be instantiated internally.
	 * The format map is built by TemplateServiceProvider::boot().
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-3.1
	 *
	 * @param array                  $templates        Format-keyed map of TemplateInterface instances.
	 * @param TemplateInterface|null $custom2_template Optional Custom Template 2 (XML) renderer.
	 */
	public function __construct( array $templates, ?TemplateInterface $custom2_template = null ) {
		$this->templates        = $templates;
		$this->custom2_template = $custom2_template;
	}

	/**
	 * Get the template for a specific format.
	 *
	 * When the format is `xml`, the optional Custom2 renderer is wired,
	 * AND the feed's provider is one of {custom2, admarkt, yandex_xml,
	 * glami}, this returns the Custom2 renderer instead of the default
	 * xml entry. This way existing callers (FeedGenerator) get the right
	 * template just by checking format — no provider-aware code needed
	 * at the call site. PROD-FRD-10.6.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-3.2
	 *
	 * @param string      $format Feed format string (xml/csv/tsv/json/txt).
	 * @param Config|null $config Optional feed config. Required for the
	 *                            Custom2 routing decision; when null, the
	 *                            default xml template is returned.
	 *
	 * @return TemplateInterface Template instance for the requested format.
	 *
	 * @throws \InvalidArgumentException If the format is not supported.
	 */
	public function get_template( $format, ?Config $config = null ) {
		$format = strtolower( $format );

		// V5→V8 upgrade safety: XLS/XLSX were selectable historically but the
		// V8 engine has no spreadsheet writer. Rather than fatally throwing on
		// an existing feed still stored with feedType='xls'/'xlsx' (which also
		// left a 0-byte file and — before the lock fix — froze the whole site),
		// fall back to CSV, the closest tabular format. New feeds can no longer
		// select these (removed from DropdownRegistry::get_file_types()).
		if ( 'xls' === $format || 'xlsx' === $format ) {
			$format = 'csv';
		}

		if ( 'xml' === $format && null !== $this->custom2_template && null !== $config ) {
			$provider = '';
			if ( method_exists( $config, 'get_provider' ) ) {
				$provider = (string) $config->get_provider();
			}
			if ( '' === $provider ) {
				$provider = (string) $config->get( 'provider', '' );
			}
			if ( in_array( $provider, self::CUSTOM2_PROVIDERS, true ) ) {
				return $this->custom2_template;
			}
		}

		if ( ! isset( $this->templates[ $format ] ) ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is consumed by the feed logger/WP_Error chain, never rendered as HTML.
				sprintf( 'CTXFeed: Unsupported template format: %s', $format )
			);
		}

		return $this->templates[ $format ];
	}

	/**
	 * Render a single product row using the config-driven format.
	 *
	 * Reads the format from config, gets the correct template (with
	 * Custom2 routing), and delegates rendering. Optional product
	 * argument is forwarded so the Custom2 renderer can walk
	 * sub-loops (variations/images/categories) — other templates
	 * ignore it.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-3.3
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Optional live product instance.
	 *
	 * @return string Rendered row content.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ) {
		$template = $this->get_template( $config->get( 'feedType', 'xml' ), $config );

		return $template->render_row( $product_data, $config, $product );
	}
}
