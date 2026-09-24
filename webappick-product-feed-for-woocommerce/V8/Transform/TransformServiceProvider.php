<?php
/**
 * TransformServiceProvider — Registers transform module services.
 *
 * Fourth ServiceProvider in the Bootstrap registration order.
 * Binds 24 services: 23 individual transforms and the pipeline orchestrator.
 * During boot(), resolves transforms, builds ordered array, applies filter,
 * and injects into TransformPipeline.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-10.1, XFRM-FRD-10.2
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transform module service provider.
 *
 * @since 8.0.0
 */
class TransformServiceProvider extends ServiceProvider {

	/**
	 * Register transform services into the container.
	 *
	 * Services registered:
	 * - transform.prefix_suffix:    Prefix/suffix text on attribute values.
	 * - transform.string:           String operations (uppercase, trim, strip_tags, etc.).
	 * - transform.category_mapping: WooCommerce to channel category mapping.
	 * - transform.output_formatter: Encoding normalization and control char removal.
	 * - transform.utm:              UTM tracking parameter injection.
	 * - transform.google:           Google Shopping-specific formatting.
	 * - transform.facebook:         Facebook/Meta Catalog-specific formatting.
	 * - transform.pinterest:        Pinterest Catalog-specific formatting.
	 * - transform.bing:             Bing/Microsoft Merchant Center formatting.
	 * - transform.snapchat:         Snapchat-specific formatting.
	 * - transform.tiktok:           TikTok Shop-specific formatting.
	 * - transform.idealo:           Idealo-specific formatting.
	 * - transform.bestprice:        BestPrice-specific formatting.
	 * - transform.skroutz:          Skroutz-specific formatting.
	 * - transform.pricerunner:      Pricerunner-specific formatting.
	 * - transform.spartoo:          Spartoo-specific formatting.
	 * - transform.zbozi:            Zbozi.cz-specific formatting.
	 * - transform.pinterest_rss:    Pinterest RSS-specific formatting.
	 * - transform.admarkt:          Admarkt-specific formatting.
	 * - transform.google_review:    Google Product Review feed formatting.
	 * - transform.trovaprezzi:      Trovaprezzi-specific formatting.
	 * - transform.pipeline:         Pipeline orchestrator (populated during boot).
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-10.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {
		// V5-compat per-attribute output_type formatter — runs FIRST so
		// V5's order (format → prefix/suffix → encode) is preserved.
		// XFRM-FRD-4.6.
		$container->register(
			'transform.output_type',
			function () {
				return new OutputTypeTransform();
			} 
		);

		$container->register(
			'transform.prefix_suffix',
			function () {
				return new PrefixSuffix();
			} 
		);

		$container->register(
			'transform.string',
			function () {
				return new StringTransform();
			} 
		);

		$container->register(
			'transform.category_mapping',
			function () {
				return new CategoryMapping();
			} 
		);

		$container->register(
			'transform.output_formatter',
			function () {
				return new OutputFormatter();
			} 
		);

		$container->register(
			'transform.utm',
			function () {
				return new UTMTransform();
			} 
		);

		$container->register(
			'transform.google',
			function () {
				return new GoogleTransform();
			} 
		);

		$container->register(
			'transform.facebook',
			function () {
				return new FacebookTransform();
			} 
		);

		$container->register(
			'transform.pinterest',
			function () {
				return new PinterestTransform();
			} 
		);

		$container->register(
			'transform.bing',
			function () {
				return new BingTransform();
			} 
		);

		$container->register(
			'transform.snapchat',
			function () {
				return new SnapchatTransform();
			} 
		);

		$container->register(
			'transform.tiktok',
			function () {
				return new TikTokTransform();
			} 
		);

		$container->register(
			'transform.idealo',
			function () {
				return new IdealoTransform();
			} 
		);

		$container->register(
			'transform.bestprice',
			function () {
				return new BestPriceTransform();
			} 
		);

		$container->register(
			'transform.skroutz',
			function () {
				return new SkroutzTransform();
			} 
		);

		$container->register(
			'transform.pricerunner',
			function () {
				return new PricerunnerTransform();
			} 
		);

		$container->register(
			'transform.spartoo',
			function () {
				return new SpartooTransform();
			} 
		);

		$container->register(
			'transform.zbozi',
			function () {
				return new ZboziTransform();
			} 
		);

		$container->register(
			'transform.pinterest_rss',
			function () {
				return new PinterestRssTransform();
			} 
		);

		$container->register(
			'transform.admarkt',
			function () {
				return new AdmarktTransform();
			} 
		);

		$container->register(
			'transform.google_review',
			function () {
				return new GoogleReviewTransform();
			} 
		);

		$container->register(
			'transform.trovaprezzi',
			function () {
				return new TrovaprezziTransform();
			} 
		);

		$container->register(
			'transform.reddit',
			function () {
				return new RedditTransform();
			} 
		);

		$container->register(
			'transform.chatgpt',
			function () {
				return new ChatGptTransform();
			} 
		);

		$container->register(
			'transform.x',
			function () {
				return new XTransform();
			} 
		);

		// Pipeline is registered as placeholder; populated in boot().
		$container->register(
			'transform.pipeline',
			function () {
				return new TransformPipeline( array() );
			} 
		);
	}

	/**
	 * Boot transform services.
	 *
	 * Resolves all 7 transforms, builds the ordered array per XFRM-FRD-1.2,
	 * applies the `ctxfeed_transform_pipeline` filter, and re-registers the
	 * pipeline with the final transform array.
	 *
	 * Pipeline order (no feed-wide number formatting stage — V5 parity,
	 * CBT-635: prices are formatted only by the row's "Price" / "Rounded
	 * Price" output type, inside OutputTypeTransform):
	 *  1. OutputTypeTransform
	 *  2. CommandTransform — Pro plugin, inserted via ctxfeed_transform_pipeline (CBT-644)
	 *  3. PrefixSuffix
	 *  4. StringTransform
	 *  5. CategoryMapping
	 *  6. ConditionalTransform — Pro plugin, inserted via ctxfeed_transform_pipeline (CBT-645)
	 *  7. GoogleTransform
	 *  8. FacebookTransform
	 *  9. PinterestTransform
	 * 10. BingTransform
	 * 11. SnapchatTransform
	 * 12. TikTokTransform
	 * 13. IdealoTransform
	 * 14. BestPriceTransform
	 * 15. SkroutzTransform
	 * 16. PricerunnerTransform
	 * 17. SpartooTransform
	 * 18. ZboziTransform
	 * 19. PinterestRssTransform
	 * 20. AdmarktTransform
	 * 21. GoogleReviewTransform
	 * 22. TrovaprezziTransform
	 * 23. RedditTransform
	 * 24. ChatGptTransform
	 * 25. XTransform
	 * 26. OutputFormatter
	 * 27. UTMTransform
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-10.2
	 * @hook ctxfeed_transform_pipeline Filter to modify the transform list.
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Build ordered transform array per XFRM-FRD-1.2.
		// XFRM-FRD-4.6: OutputTypeTransform runs FIRST so V5 numeric codes
		// (Format Price, ucwords, Strip Tags, etc.) execute before prefix/
		// suffix wrap the value. This matches V5's
		// `process_output()` order: str_replace → output_types →
		// formatters → prefix/suffix.
		$transforms = array(
			$container->resolve( 'transform.output_type' ),
			// XFRM-FRD-9.5: the Pro plugin inserts its CommandTransform right
			// after output_type through the ctxfeed_transform_pipeline filter
			// (Core\PipelineOrder), mirroring V5 process_output() ordering
			// (output_types → commands → prefix/suffix). CBT-644.
			$container->resolve( 'transform.prefix_suffix' ),
			$container->resolve( 'transform.string' ),
			$container->resolve( 'transform.category_mapping' ),
			// The Pro plugin inserts ConditionalTransform right after
			// CategoryMapping through ctxfeed_transform_pipeline (CBT-645).
			// Provider-specific transforms (no-op for non-matching providers).
			$container->resolve( 'transform.google' ),
			$container->resolve( 'transform.facebook' ),
			$container->resolve( 'transform.pinterest' ),
			$container->resolve( 'transform.bing' ),
			$container->resolve( 'transform.snapchat' ),
			$container->resolve( 'transform.tiktok' ),
			$container->resolve( 'transform.idealo' ),
			$container->resolve( 'transform.bestprice' ),
			$container->resolve( 'transform.skroutz' ),
			$container->resolve( 'transform.pricerunner' ),
			$container->resolve( 'transform.spartoo' ),
			$container->resolve( 'transform.zbozi' ),
			$container->resolve( 'transform.pinterest_rss' ),
			$container->resolve( 'transform.admarkt' ),
			$container->resolve( 'transform.google_review' ),
			$container->resolve( 'transform.trovaprezzi' ),
			$container->resolve( 'transform.reddit' ),
			$container->resolve( 'transform.chatgpt' ),
			$container->resolve( 'transform.x' ),
			// Output finalization.
			$container->resolve( 'transform.output_formatter' ),
			$container->resolve( 'transform.utm' ),
		);

		/**
		 * Filter the transform pipeline.
		 *
		 * Allows third-party code to add, remove, or reorder transforms.
		 * All items MUST implement TransformInterface.
		 *
		 * @since 8.0.0
		 *
		 * @param TransformInterface[] $transforms Ordered array of transform instances.
		 */
		$transforms = apply_filters( 'ctxfeed_transform_pipeline', $transforms );

		// Re-register pipeline with final transform array.
		$container->register(
			'transform.pipeline',
			function () use ( $transforms ) {
				return new TransformPipeline( $transforms );
			} 
		);
	}
}
