<?php
/**
 * TemplateServiceProvider — Registers template module services.
 *
 * Sixth ServiceProvider in the Bootstrap registration order.
 * Binds 7 services: StreamWriter, 4 format templates, GroupedAttributeBuilder,
 * and the TemplateEngine orchestrator. During boot(), resolves templates,
 * builds format-keyed map, applies filter hook, and injects into TemplateEngine.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-8.1, TMPL-FRD-8.2
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template module service provider.
 *
 * @since 8.0.0
 */
class TemplateServiceProvider extends ServiceProvider {

	/**
	 * Register template services into the container.
	 *
	 * Services registered:
	 * - template.stream_writer: Low-level file I/O for feed output.
	 * - template.xml:           XML format template.
	 * - template.csv:           CSV format template (comma delimiter).
	 * - template.json:          JSON format template.
	 * - template.txt:           TXT format template (tab-delimited).
	 * - template.grouped_builder: GroupedAttributeBuilder for nested XML structures.
	 * - template.engine:        TemplateEngine orchestrator (populated during boot).
	 *
	 * Note: TSV is not a separate service. It is created inline during boot()
	 * as `new CSVTemplate( "\t" )` per AD-TMPL-005.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-8.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'template.stream_writer',
			function () {
				return new StreamWriter();
			} 
		);

		$container->register(
			'template.xml',
			function () {
				return new XMLTemplate();
			} 
		);

		$container->register(
			'template.csv',
			function () {
				return new CSVTemplate();
			} 
		);

		$container->register(
			'template.json',
			function () {
				return new JSONTemplate();
			} 
		);

		$container->register(
			'template.txt',
			function () {
				return new TXTTemplate();
			} 
		);

		$container->register(
			'template.grouped_builder',
			function () {
				return new GroupedAttributeBuilder();
			} 
		);

		// Custom Template 2 (XML) — user-supplied template string parser
		// and renderer. Routed by TemplateEngine when feedType=xml AND
		// provider is in the custom2 merchant set. PROD-FRD-10.6.
		$container->register(
			'template.custom2_structure',
			function () {
				return new Custom2Structure();
			} 
		);

		$container->register(
			'template.custom2',
			function ( Container $c ) {
				// AttributeResolver / FilterManager may not be available in all
				// bootstraps (e.g. partial test wiring). Guard with try/catch so
				// the container can still resolve other template services.
				$attribute_resolver = null;
				try {
					$attribute_resolver = $c->resolve( 'product.attribute_resolver' );
				} catch ( \Throwable $e ) {
					$attribute_resolver = null;
				}
				// FilterManager validates variation children in the
				// `{{each variation start}}` sub-loop (V5 ran per-variation
				// ValidateProduct::is_valid()). PROD-FRD-10.6.
				$filter_manager = null;
				try {
					$filter_manager = $c->resolve( 'filter.manager' );
				} catch ( \Throwable $e ) {
					$filter_manager = null;
				}
				return new Custom2Template(
					$c->resolve( 'template.custom2_structure' ),
					$attribute_resolver,
					$filter_manager
				);
			} 
		);

		// Engine is registered as placeholder; populated in boot().
		$container->register(
			'template.engine',
			function () {
				return new TemplateEngine( array() );
			} 
		);
	}

	/**
	 * Boot template services.
	 *
	 * Resolves all 4 registered templates, creates TSV inline, builds
	 * the format-keyed map, applies the `ctxfeed_template_engines` filter,
	 * and re-registers the TemplateEngine with the final map.
	 *
	 * Format map:
	 * - 'xml'  → XMLTemplate
	 * - 'csv'  → CSVTemplate (comma)
	 * - 'tsv'  → CSVTemplate (tab) — created inline per AD-TMPL-005
	 * - 'json' → JSONTemplate
	 * - 'txt'  → TXTTemplate
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-8.2
	 * @hook ctxfeed_template_engines Filter to modify the template map.
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Build format-keyed template map. @implements TMPL-FRD-8.2.
		$templates = array(
			'xml'  => $container->resolve( 'template.xml' ),
			'csv'  => $container->resolve( 'template.csv' ),
			'tsv'  => new CSVTemplate( "\t" ),
			'json' => $container->resolve( 'template.json' ),
			'txt'  => $container->resolve( 'template.txt' ),
		);

		/**
		 * Filter the template engine format map.
		 *
		 * Allows third-party code to add, remove, or replace format templates.
		 * All items MUST implement TemplateInterface.
		 *
		 * @since 8.0.0
		 *
		 * @param TemplateInterface[] $templates Format-keyed map of template instances.
		 */
		$templates = apply_filters( 'ctxfeed_template_engines', $templates );

		// Resolve the Custom2 renderer (registered above). When the
		// AttributeResolver isn't yet available the resolved Custom2
		// instance still works, just without product-attribute resolution
		// at render time — which is what V5 customers would also see.
		$custom2 = null;
		try {
			$custom2 = $container->resolve( 'template.custom2' );
		} catch ( \Throwable $e ) {
			$custom2 = null;
		}

		// Re-register engine with final template map and optional Custom2
		// router. PROD-FRD-10.6.
		$container->register(
			'template.engine',
			function () use ( $templates, $custom2 ) {
				return new TemplateEngine( $templates, $custom2 );
			} 
		);
	}
}
