<?php
/**
 * FeedGenerator — Orchestrates the product-to-file pipeline.
 *
 * Coordinates CacheWarmer, ProductRepository, FilterManager, TransformPipeline,
 * TemplateEngine, StreamWriter, BatchCalculator, AttributeNameMapper, and
 * GroupedAttributeBuilder into a 10-step batch processing pipeline. All 13
 * dependencies injected via constructor (AD-FEED-001).
 *
 * Steps 1 and 9 capture timing and memory baselines for BatchCalculator
 * adaptive sizing. Step 9 calls record_batch() and calculate_next() so
 * the scheduler can chain the next batch with an optimized size.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-2.1, FEED-FRD-2.2, FEED-FRD-2.3, FEED-FRD-2.4, FEED-FRD-2.5, FEED-FRD-11.2, FEED-FRD-11.3
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Product\CacheWarmer;
use CTXFeed\V8\Product\ProductRepository;
use CTXFeed\V8\Product\ProductQuery;
use CTXFeed\V8\Template\TemplateEngine;
use CTXFeed\V8\Template\StreamWriter;
use CTXFeed\V8\Transform\TransformPipeline;
use CTXFeed\V8\Filter\FilterManager;
use CTXFeed\V8\Channel\AttributeNameMapper;
use CTXFeed\V8\Channel\AutoAttributes;
use CTXFeed\V8\Template\GroupedAttributeBuilder;
use CTXFeed\V8\Product\SkroutzVariationsBuilder;
use CTXFeed\V8\Utility\FeedLogger;
use CTXFeed\V8\Utility\Filesystem;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed generation orchestrator.
 *
 * @since 8.0.0
 */
class FeedGenerator {

	/**
	 * Suffix for the temp working file a run streams into before it is
	 * atomically promoted onto the live feed in finalize().
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private const WORKING_SUFFIX = '.tmp';

	/**
	 * Feed manager instance.
	 *
	 * @since 8.0.0
	 * @var FeedManager
	 */
	private $manager;

	/**
	 * Logger instance.
	 *
	 * @since 8.0.0
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Cache warmer instance.
	 *
	 * @since 8.0.0
	 * @var CacheWarmer|null
	 */
	private $cache_warmer;

	/**
	 * Product repository instance.
	 *
	 * @since 8.0.0
	 * @var ProductRepository|null
	 */
	private $product_repo;

	/**
	 * Product query instance.
	 *
	 * @since 8.0.0
	 * @var ProductQuery|null
	 */
	private $product_query;

	/**
	 * Template engine instance.
	 *
	 * @since 8.0.0
	 * @var TemplateEngine|null
	 */
	private $template_engine;

	/**
	 * Stream writer instance.
	 *
	 * @since 8.0.0
	 * @var StreamWriter|null
	 */
	private $stream_writer;

	/**
	 * Transform pipeline instance.
	 *
	 * @since 8.0.0
	 * @var TransformPipeline|null
	 */
	private $transform;

	/**
	 * Filter manager instance.
	 *
	 * @since 8.0.0
	 * @var FilterManager|null
	 */
	private $filter_manager;

	/**
	 * Batch calculator instance.
	 *
	 * @since 8.0.0
	 * @var BatchCalculator|null
	 */
	private $batch_calculator;

	/**
	 * Attribute name mapper instance.
	 *
	 * @since 8.0.0
	 * @var AttributeNameMapper|null
	 */
	private $attribute_mapper;

	/**
	 * Grouped attribute builder instance.
	 *
	 * @since 8.0.0
	 * @var GroupedAttributeBuilder|null
	 */
	private $grouped_builder;

	/**
	 * Review resolver for Google Product Review feeds.
	 *
	 * @since 8.0.0
	 * @var \CTXFeed\V8\Product\ReviewResolver|null
	 */
	private $review_resolver;

	/**
	 * Feed filesystem helper — single source of truth for feed paths/URLs.
	 *
	 * @since 8.0.0
	 * @var Filesystem|null
	 */
	private $filesystem;

	/**
	 * Per-feed buffered logger (setter-injected).
	 *
	 * @since 8.0.0
	 * @var FeedLogger|null
	 */
	private $feed_logger;

	/**
	 * Skroutz per-variation nested-block builder (optional).
	 *
	 * @var SkroutzVariationsBuilder|null
	 */
	private $variations_builder = null;

	/**
	 * Set the Skroutz variations builder.
	 *
	 * Injected via a setter (not the constructor) so the 14-arg DI signature
	 * and its call sites stay untouched. Only the Skroutz channel uses it;
	 * the builder self-gates, so leaving it null simply disables the feature.
	 *
	 * @since 8.0.0
	 *
	 * @param SkroutzVariationsBuilder $builder Variations builder.
	 *
	 * @return void
	 */
	public function set_variations_builder( SkroutzVariationsBuilder $builder ): void {
		$this->variations_builder = $builder;
	}

	/**
	 * Set the per-feed logger instance.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedLogger $feed_logger Per-feed logger.
	 *
	 * @return void
	 */
	public function set_feed_logger( FeedLogger $feed_logger ): void {
		$this->feed_logger = $feed_logger;
	}

	/**
	 * Constructor — receives dependencies via DI.
	 *
	 * Dependencies SHALL NOT be instantiated internally (AD-FEED-001).
	 *
	 * Note: third-party plugin compatibility is NOT a dependency here. The
	 * `ctx-compatibility/` submodule registers V5-shaped hook listeners
	 * during plugin boot, and FeedGenerator fires those V5 hooks
	 * (`woo_feed_filter_product_*`, `before/after_woo_feed_generate_batch_data`,
	 * etc.) during generation — there's no in-process manager object the
	 * generator needs to talk to.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-2.1
	 *
	 * @param FeedManager                  $manager          Feed manager for config/progress.
	 * @param Logger|null                  $logger           Logger for performance tracking.
	 * @param CacheWarmer|null             $cache_warmer     Cache warmer for meta priming.
	 * @param ProductRepository|null       $product_repo     Product data resolver.
	 * @param ProductQuery|null            $product_query    Product ID query service.
	 * @param TemplateEngine|null          $template_engine  Template format router.
	 * @param StreamWriter|null            $stream_writer    File I/O writer.
	 * @param TransformPipeline|null       $transform        Data transform pipeline.
	 * @param FilterManager|null           $filter_manager   Product filter manager.
	 * @param BatchCalculator|null         $batch_calculator Adaptive batch size calculator.
	 * @param AttributeNameMapper|null     $attribute_mapper Merchant attribute name mapper.
	 * @param GroupedAttributeBuilder|null $grouped_builder Nested XML structure builder.
	 * @param ReviewResolver|null          $review_resolver  Google review resolver.
	 * @param Filesystem|null              $filesystem       Feed path/URL helper.
	 */
	public function __construct(
		FeedManager $manager,
		$logger = null,
		$cache_warmer = null,
		$product_repo = null,
		$product_query = null,
		$template_engine = null,
		$stream_writer = null,
		$transform = null,
		$filter_manager = null,
		$batch_calculator = null,
		$attribute_mapper = null,
		$grouped_builder = null,
		$review_resolver = null,
		$filesystem = null
	) {
		$this->manager          = $manager;
		$this->logger           = $logger;
		$this->cache_warmer     = $cache_warmer;
		$this->product_repo     = $product_repo;
		$this->product_query    = $product_query;
		$this->template_engine  = $template_engine;
		$this->stream_writer    = $stream_writer;
		$this->transform        = $transform;
		$this->filter_manager   = $filter_manager;
		$this->batch_calculator = $batch_calculator;
		$this->attribute_mapper = $attribute_mapper;
		$this->grouped_builder  = $grouped_builder;
		$this->review_resolver  = $review_resolver;
		$this->filesystem       = $filesystem;
	}

	/**
	 * Process a single batch of products.
	 *
	 * 10-step pipeline per AD-FEED-004:
	 *  1. Capture timing and memory baseline.
	 *  2. Fire ctxfeed_before_generate_batch hook.
	 *  3. Fire before_woo_feed_generate_batch_data V5 bridge hook.
	 *  4. Load config, query product IDs, warm cache.
	 *  5. Loop: get product → filter → resolve → transform → render → write.
	 *  6. Fire after_woo_feed_generate_batch_data V5 bridge hook.
	 *  7. Fire ctxfeed_after_generate_batch hook.
	 *  8. Update progress transient with enriched batch data.
	 *  9. Record batch performance in BatchCalculator and compute next batch size.
	 * 10. Log performance.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-2.2, FEED-FRD-2.3, FEED-FRD-8.1, FEED-FRD-9.1, FEED-FRD-11.2, FEED-FRD-11.3
	 * @hook ctxfeed_before_generate_batch Action before batch.
	 * @hook ctxfeed_after_generate_batch Action after batch.
	 * @hook before_woo_feed_generate_batch_data V5 legacy bridge.
	 * @hook after_woo_feed_generate_batch_data V5 legacy bridge.
	 *
	 * @param string $feed_name  Feed slug identifier.
	 * @param int    $offset     Product offset for this batch.
	 * @param int    $batch_size Number of products per batch.
	 * @param array  $context    Additional context data.
	 *
	 * @return array Batch result with 'count' and 'next_batch_size' keys.
	 */
	public function process_batch( string $feed_name, int $offset, int $batch_size, array $context = array() ): array {
		// Bind BatchCalculator to this feed for per-feed history persistence.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->set_feed_name( $feed_name );
		}

		// Step 1: Capture timing and memory baseline. @implements FEED-FRD-11.2.
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Step 2: V8 before hook. @implements FEED-FRD-8.1.
		do_action( 'ctxfeed_before_generate_batch', $feed_name, $offset, $batch_size );

		// Step 3: Load config FIRST so the V5 compat hooks below can pass
		// the Config object (their expected arg shape). The V5 compat shims
		// in `ctx-compatibility/` call `$config->get_feed_currency()`,
		// `$config->get_feed_language()`, etc. on this argument — passing
		// anything else fatals.
		$config = $this->manager->get_config( $feed_name );
		if ( ! $config ) {
			if ( $this->logger ) {
				$this->logger->error( "Feed config not found: {$feed_name}" );
			}
			return array(
				'count'           => 0,
				'next_batch_size' => $batch_size,
			);
		}

		// V5 parity: Google/Facebook/Bing auto-append `identifier_exists`
		// to the mapping when the user hasn't mapped it (GoogleStructure /
		// FacebookStructure / BingStructure). Applied here — before the
		// header write and the product loop — so XML rows and delimited
		// headers/rows all see the same augmented mapping. CHAN-FRD-4.1.
		$config = AutoAttributes::apply( $config );

		// Step 4: V5 batch-start hooks — fire AFTER config is loaded so the
		// compat shims (WPML/SitePress/PolylangCompatibility/WOOCSCompatibility/etc.)
		// receive the Config object their callbacks expect. V5 fires both
		// hooks at the same boundary; we mirror that.
		// @implements FEED-FRD-9.1.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exact V5 legacy hook name; Legacy Bridge compatibility requires it unchanged.
		do_action( 'before_woo_feed_generate_batch_data', $config );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exact V5 legacy hook name; Legacy Bridge compatibility requires it unchanged.
		do_action( 'before_woo_feed_get_product_information', $config );

		// Bind the query to this feed so get_paginated_ids() reads from
		// the snapshot transient seeded at schedule_generation() time.
		// Skipping this binding falls back to running the full
		// WC_Product_Query on every batch, which is the 400×-redundant
		// behaviour we're avoiding for 200K-product catalogs.
		$this->product_query->set_feed_name( $feed_name );

		$ids = $this->product_query->get_paginated_ids( $config, $offset, $batch_size );

		if ( $this->feed_logger ) {
			$this->feed_logger->info( $feed_name, sprintf( 'Batch offset=%d, batch_size=%d, products_in_batch=%d', $offset, $batch_size, count( $ids ) ) );
		}

		$this->cache_warmer->warm( $ids );

		// Pre-compute format and provider (used by init, loop, and grouping).
		$format       = $config->get( 'feedType', 'xml' );
		$format_lower = strtolower( $format );
		$provider     = $config->get_provider();
		$is_xml       = ( 'xml' === $format_lower );

		// NOTE: the canonical per-channel XML item/items wrapper (Google →
		// <item>, googlereview → <review>, …) is resolved inside XMLTemplate
		// from the provider (see Channel\FeedWrapper), so header, rows AND
		// footer stay consistent — the footer renders in finalize() from a
		// separately-built Config, so forcing the wrapper here would only fix
		// the header/rows and leave a mismatched closing tag. TMPL-FRD-4.2.

		// First batch initialization. @implements FEED-FRD-2.3.
		if ( 0 === $offset ) {
			// Stream into a .tmp working file — never the live feed. finalize()
			// atomically swaps it into place, so a mid-run crash leaves the
			// previous good feed untouched (CTX 1.1.4 / Comp 3.5).
			$file_path = $this->get_working_file_path( $feed_name, $format, $provider );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the feed file IS the product; StreamWriter needs a real handle for chunked output and WP_Filesystem offers no streaming API.
			$this->stream_writer->open( $file_path, $format );

			// UTF-8 BOM (opt-in) for delimited text feeds — Excel and some
			// receivers only read them as UTF-8 with a leading BOM. Must land
			// before the header, so it's written here on the first batch only.
			if ( in_array( $format_lower, array( 'csv', 'tsv', 'txt' ), true )
				&& 'yes' === $config->get( 'csv_bom', 'no' ) ) {
				$this->stream_writer->write_bom();
			}

			// Pass config so the Custom Template 2 (XML) routing can fire
			// for custom2-merchant providers. PROD-FRD-10.6.
			$template = $this->template_engine->get_template( $format, $config );

			// For CSV/TSV/TXT: write mapped merchant attribute headers.
			if ( in_array( $format_lower, array( 'csv', 'tsv', 'txt' ), true ) && $this->attribute_mapper ) {
				$mattributes = $config->get_merchant_attributes();

				// Delimited formats put each nested group (tax, shipping,
				// product_detail, installment, subscription_cost) in ONE
				// column — collapse the per-member entries so the header
				// aligns with build_delimited()'d row data. TMPL-FRD-4.5.
				if ( $this->grouped_builder ) {
					$mattributes = $this->grouped_builder->collapse_delimited_headers( $mattributes, $provider );
				}

				$headers = $this->attribute_mapper->get_mapped_headers( $mattributes, $provider, $format );
				$this->stream_writer->write_header( $this->build_csv_header( $headers, $template, $config ) );
			} else {
				$this->stream_writer->write_header( $template->render_header( $config ) );
			}
		} elseif ( $this->stream_writer ) {
			// Batches after the first run in a FRESH PHP process (each
			// Action Scheduler job is its own request), where the handle
			// opened at offset 0 does not exist. Reopen the partial file
			// in APPEND mode — without this every row from batch 2+
			// wrote to a null handle and was silently dropped, truncating
			// any catalog larger than one batch. Same-process loops
			// (tests, WP-CLI) skip the reopen: the handle is still live.
			$file_path = $this->get_working_file_path( $feed_name, $format, $provider );
			if ( ! $this->stream_writer->is_open() || $this->stream_writer->get_file_path() !== $file_path ) {
				$this->stream_writer->open_append( $file_path, $format );
			}
		}

		// Step 5: V5 product-loop hooks — fired before the actual iteration
		// begins so compat shims like the Pro `MultiCurrency` orchestrator
		// can swap currency context for the entire batch. V5 signature:
		// ($productIds, $feedRules, $config). PROD-FRD-10.7.
		$feed_rules_array = method_exists( $config, 'to_array' ) ? $config->to_array() : array();
		do_action( 'woo_feed_before_product_loop', $ids, $feed_rules_array, $config );

		// Product loop — filter → resolve → transform → render → write.
		//
		// Wrapped in try/finally so the paired `woo_feed_after_product_loop`
		// teardown ALWAYS fires — even if a row throws — keeping before/after
		// hook listeners balanced (e.g. FeedTaxLocation, MultiCurrency) so
		// their state never leaks past the batch. Any exception still
		// propagates out of the finally.
		$count       = 0;
		$error_count = 0;

		try {
			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				if ( ! $this->filter_manager->should_include( $product, $config ) ) {
					continue;
				}

				// Per-product error isolation. A throw while resolving,
				// transforming, rendering, or writing ONE product must NOT
				// abort the batch — that would drop every remaining product in
				// it. Log the product id + reason, count it, and move on, so a
				// single malformed product can't break the whole feed
				// (the resilience the V5 engine relied on, made explicit here).
				try {
					// Google Product Review feeds export the product's REVIEWS,
					// not a product row — one <review> entry per approved comment
					// with content and a star rating (V5 GooglereviewStructure).
					// Review entries carry final tag names and bypass the product
					// transform/mapping pipeline.
					if ( 'googlereview' === $provider && $is_xml && $this->review_resolver ) {
						foreach ( $this->review_resolver->resolve( $product, $config ) as $review_entry ) {
							$this->stream_writer->write_row(
								$this->template_engine->render_row( $review_entry, $config, $product )
							);
						}
						// $count tracks PRODUCTS for pagination, not rows written.
						++$count;
						continue;
					}

					$product_data = $this->product_repo->resolve_single( $product, $config );
					$transformed  = $this->transform->apply( $product_data, $config );

					// Skroutz native nested <variations>: build per-child variation
					// blocks from a variable parent's children. Config-gated inside
					// the builder (provider=skroutz + variation_* mapped) — a no-op
					// for every other feed. XML-only (Skroutz has no CSV map).
					if ( $this->variations_builder && $is_xml ) {
						$transformed = $this->variations_builder->build( $transformed, $product, $config );
					}

					// For XML: restructure grouped attributes into nested arrays before name mapping.
					// GroupedAttributeBuilder works with internal names and outputs nested groups
					// with merchant-specific sub-element names. @implements TMPL-FRD-4.5.
					if ( $this->grouped_builder && $is_xml ) {
						$transformed = $this->grouped_builder->build( $transformed, $provider );
					} elseif ( $this->grouped_builder && in_array( $format_lower, array( 'csv', 'tsv', 'txt' ), true ) ) {
						// Delimited formats: collapse each nested group into one
						// colon-joined column value ("US:CA:8.25:y"), repeated
						// instances comma-joined — Google delimited spec + V5
						// GoogleStructure::get_csv_structure() parity.
						$transformed = $this->grouped_builder->build_delimited( $transformed, $provider );
					}

					// Map remaining flat attribute names to merchant-specific format (e.g., id → g:id for Google XML).
					if ( $this->attribute_mapper ) {
						$transformed = $this->attribute_mapper->map_product_data( $transformed, $provider, $format );
					}

					// Forward the live product so Custom Template 2 (XML) can walk
					// sub-loops (variations/images/categories) defined in the
					// user-supplied template. Other templates ignore this arg.
					// PROD-FRD-10.6.
					$row = $this->template_engine->render_row( $transformed, $config, $product );

					// JSON: rows stream with a trailing comma (a streaming writer
					// cannot know which row is last); finalize() trims the final
					// comma before the closing bracket so the document parses.
					if ( 'json' === $format_lower ) {
						$row .= ',';
					}

					$this->stream_writer->write_row( $row );
					++$count;
				} catch ( \Throwable $e ) {
					// Isolate this one product: record it and keep the batch going.
					++$error_count;
					if ( $this->feed_logger ) {
						$this->feed_logger->error(
							$feed_name,
							sprintf( 'Product #%d skipped — %s', (int) $product_id, $e->getMessage() )
						);
					}
					continue;
				}
			}
		} finally {
			// Step 6: V5 product-loop after hook — fires on EVERY exit path
			// (success or thrown row) so paired before/after listeners always
			// tear down. PROD-FRD-10.7.
			do_action( 'woo_feed_after_product_loop', $ids, $feed_rules_array, $config );
		}

		// Step 7: V5 batch-end hooks — fired with the Config object.
		// @implements FEED-FRD-9.1.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exact V5 legacy hook name; Legacy Bridge compatibility requires it unchanged.
		do_action( 'after_woo_feed_get_product_information', $config );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exact V5 legacy hook name; Legacy Bridge compatibility requires it unchanged.
		do_action( 'after_woo_feed_generate_batch_data', $config );

		// Step 8: V8 after hook. @implements FEED-FRD-8.1.
		do_action( 'ctxfeed_after_generate_batch', $feed_name, $offset, $count );

		// Log batch result.
		if ( $this->feed_logger ) {
			$this->feed_logger->info( $feed_name, sprintf( 'Batch complete: %d products written, %d skipped, in %.2fs', $count, $error_count, microtime( true ) - $start_time ) );
		}

		// Step 8: Update enriched progress. @implements FEED-FRD-2.2.
		$batch_time = microtime( true ) - $start_time;
		$total      = isset( $context['total'] ) ? (int) $context['total'] : 0;
		$processed  = min( $offset + $batch_size, $total );

		// Compute batch number and ETA.
		$batch_number  = ( $batch_size > 0 ) ? (int) floor( $offset / $batch_size ) + 1 : 1;
		$batches_total = ( $batch_size > 0 && $total > 0 ) ? (int) ceil( $total / $batch_size ) : 1;

		// Compute rolling average batch time for ETA estimation.
		$progress          = $this->manager->get_progress( $feed_name );
		$prev_avg          = (float) $progress['avg_batch_time'];
		$avg_batch_time    = ( $prev_avg > 0 )
			? ( $prev_avg + $batch_time ) / 2.0
			: $batch_time;
		$remaining_batches = max( 0, $batches_total - $batch_number );
		$eta_seconds       = (int) round( $remaining_batches * $avg_batch_time );

		$this->manager->update_progress(
			$feed_name,
			array(
				'current'        => $processed,
				'total'          => $total,
				'status'         => 'generating',
				'batch_size'     => $batch_size,
				'batches_done'   => $batch_number,
				'batches_total'  => $batches_total,
				'eta_seconds'    => $eta_seconds,
				'avg_batch_time' => round( $avg_batch_time, 4 ),
			) 
		);

		// Step 9: Record batch performance and compute adaptive next size. @implements FEED-FRD-11.2, FEED-FRD-11.3.
		$memory_delta    = memory_get_usage() - $start_memory;
		$next_batch_size = $batch_size;

		if ( $this->batch_calculator ) {
			$this->batch_calculator->record_batch( $count, $batch_time, $memory_delta );
			$next_batch_size = $this->batch_calculator->calculate_next();
		}

		// Step 10: Performance logging. @implements FEED-FRD-2.5.
		$duration_ms = $batch_time * 1000;
		if ( $this->logger ) {
			$this->logger->performance(
				"Batch at offset {$offset}",
				$duration_ms,
				array(
					'products'        => $count,
					'feed_name'       => $feed_name,
					'offset'          => $offset,
					'batch_size'      => $batch_size,
					'next_batch_size' => $next_batch_size,
					'batch_time_sec'  => round( $batch_time, 4 ),
					'memory_delta_kb' => round( $memory_delta / 1024, 1 ),
				) 
			);
		}

		// Flush per-feed log buffer to disk (single I/O per batch).
		if ( $this->feed_logger ) {
			$this->feed_logger->flush( $feed_name );
		}

		return array(
			'count'           => $count,
			'next_batch_size' => $next_batch_size,
			'errors'          => $error_count,
		);
	}

	/**
	 * Finalize feed generation.
	 *
	 * Writes footer, closes file, runs health analysis, updates progress,
	 * triggers export if configured, and fires finalization hook.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-2.4
	 * @hook ctxfeed_feed_finalized Action after feed finalization.
	 *
	 * @param string $feed_name Feed slug identifier.
	 *
	 * @return void
	 */
	public function finalize( string $feed_name ): void {
		$config = $this->manager->get_config( $feed_name );
		if ( ! $config ) {
			return;
		}

		// Write footer and close. @implements FEED-FRD-2.4.
		// Pass config so the Custom Template 2 (XML) routing can fire for
		// custom2-merchant providers. PROD-FRD-10.6.
		$format   = $config->get( 'feedType', 'xml' );
		$provider = $config->get_provider();
		$template = $this->template_engine->get_template( $format, $config );

		// finalize() runs in its own Action Scheduler request — the batch
		// handles don't exist here. Reopen the completed file in APPEND
		// mode so the footer lands at the end (and get_file_path() below
		// resolves for promote/export). The path must match the batch write
		// path exactly (same provider-nested location) or the footer lands
		// in a different file.
		$working_path = $this->get_working_file_path( $feed_name, $format, $provider );
		if ( ! $this->stream_writer->is_open() || $this->stream_writer->get_file_path() !== $working_path ) {
			$this->stream_writer->open_append( $working_path, $format );
		}

		// JSON: drop the last row's trailing comma before the closing
		// bracket — rows stream with trailing commas (see process_batch).
		if ( 'json' === strtolower( (string) $format ) ) {
			$this->stream_writer->trim_trailing( ',' . PHP_EOL );
		}

		$this->stream_writer->write_footer( $template->render_footer( $config ) );
		$this->stream_writer->close();

		// Atomic delivery: the whole run streamed into $working_path, so the
		// live feed was never touched. Swap it into place now — unless the run
		// produced 0 products and a previously-good feed exists, in which case
		// keep the last good feed rather than publish a suddenly-empty one
		// (CTX 1.1.4 / Comp 3.5). $file_path is the LIVE path from here on.
		$file_path     = $this->get_feed_file_path( $feed_name, $format, $provider );
		$progress      = $this->manager->get_progress( $feed_name );
		$product_total = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
		$this->promote_working_file( $working_path, $file_path, $product_total, $feed_name );

		// Promote feed: update wf_feed_ with URL and timestamp.
		if ( ! empty( $file_path ) ) {
			$this->manager->promote_feed( $feed_name, $file_path );
		}

		// V5-compat "after the feed file is saved" hook (V5 parity:
		// FeedHelper::save_feed_file fired this once the live feed was written).
		// Fires UNCONDITIONALLY — even a 0-product run toggled per-run global
		// state during its batches that a compat shim must now restore. The
		// WPML/WCML multi-currency shim uses this to restore the store's
		// WooCommerce currency mode it forced to `by_language` while resolving,
		// so the site isn't left permanently switched. Wrapped so a listener
		// cannot crash finalization.
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- V5-parity hook name: the Pro WPML/WCML compat shim listens on this EXACT legacy name (`ctx_feed_after_save_feed_file`, as fired by V5 FeedHelper). Renaming it to a `ctxfeed_` prefix would silently break that compat listener.
			do_action( 'ctx_feed_after_save_feed_file', $feed_name, $config );
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( "after_save_feed_file hook error (non-fatal): {$feed_name}", array( 'error' => $e->getMessage() ) );
			}
		}

		// Health analysis. @implements FEED-FRD-2.4.
		$health_score = 0;
		try {
			$health        = new FeedHealth();
			$health_result = $health->analyze( $feed_name, $config );
			$health_score  = $health_result['score'];
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( "Health analysis failed (non-fatal): {$feed_name}", array( 'error' => $e->getMessage() ) );
			}
		}

		// Update progress to completed. @implements FEED-FRD-10.1.
		$progress = $this->manager->get_progress( $feed_name );
		$this->manager->update_progress(
			$feed_name,
			array(
				'current' => $progress['total'],
				'total'   => $progress['total'],
				'status'  => 'completed',
			) 
		);

		// Export (FTP / SFTP upload) if configured. @implements FEED-FRD-5.1.
		try {
			$exporter = new FeedRemoteTransport();
			if ( $this->feed_logger ) {
				$exporter->set_feed_logger( $this->feed_logger );
			}
			// Use the LIVE feed path (the working file was renamed on promote).
			$exporter->export( $feed_name, $file_path, $config );
		} catch ( \Throwable $e ) {
			// Export failure must not prevent feed completion.
			if ( $this->logger ) {
				$this->logger->error( "Feed export failed (non-fatal): {$feed_name}", array( 'error' => $e->getMessage() ) );
			}
		}

		// Clean up BatchCalculator state: clear history and release lock.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->clear_history( $feed_name );
			$this->batch_calculator->release_lock( $feed_name );
		}

		// Drop the product-ID snapshot so the next generation cycle picks
		// up newly-added or removed products from a fresh query.
		if ( $this->product_query ) {
			$this->product_query->clear_snapshot( $feed_name );
		}

		// Finalization hook — wrapped so third-party/dashboard listeners
		// cannot crash the generation pipeline. @implements FEED-FRD-8.1.
		try {
			do_action( 'ctxfeed_feed_finalized', $feed_name, $health_score );
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( "Finalization hook error (non-fatal): {$feed_name}", array( 'error' => $e->getMessage() ) );
			}
		}

		if ( $this->logger ) {
			$this->logger->info(
				"Feed finalized: {$feed_name}",
				array(
					'health_score' => $health_score,
				) 
			);
		}

		// Write completion footer to per-feed log.
		if ( $this->feed_logger ) {
			$file_path = $this->stream_writer ? $this->stream_writer->get_file_path() : '';
			$file_size = ( ! empty( $file_path ) && file_exists( $file_path ) ) ? size_format( filesize( $file_path ) ) : 'N/A';

			$this->feed_logger->complete(
				$feed_name,
				array(
					'total_products' => $progress['total'],
					'health_score'   => $health_score . '%',
					'file_size'      => $file_size,
				) 
			);
		}
	}

	/**
	 * Build a CSV/TSV/TXT header row from mapped header names.
	 *
	 * CSV/TSV delegate to CSVTemplate::render_columns() so the header uses the
	 * CONFIGURED enclosure and the rows' QUOTE_ALL quoting (never a hardcoded
	 * "); TXT is raw tab-joined; other templates fall back to minimal fputcsv.
	 *
	 * @since 8.0.0
	 *
	 * @param array             $headers  Mapped header names.
	 * @param TemplateInterface $template CSV/TXT template instance.
	 * @param Config            $config   Feed configuration (delimiter/enclosure).
	 *
	 * @return string Delimited header row.
	 */
	private function build_csv_header( array $headers, $template, Config $config ): string {
		$delimiter = ',';

		// Use template's delimiter if available (CSVTemplate exposes get_delimiter).
		if ( method_exists( $template, 'get_delimiter' ) ) {
			$delimiter = $template->get_delimiter();
		}

		// TXT rows are RAW tab-joined (TXTTemplate::render_row never
		// quotes), so the header must match — fputcsv would wrap any
		// header containing a space (e.g. the auto-added "identifier
		// exists") in quotes the rows never carry. V5 parity: TXT is
		// plain text, not CSV. TMPL-FRD-4.5.
		if ( $template instanceof \CTXFeed\V8\Template\TXTTemplate ) {
			return implode( $delimiter, $headers );
		}

		// CSV/TSV: render the header through the template's OWN formatter, so it
		// uses the CONFIGURED enclosure and the rows' QUOTE_ALL quoting — never a
		// hardcoded ". Otherwise a single-quote (or any custom) enclosure feed
		// emitted a double-quoted header its data rows never carried, and CSV
		// consumers (Google Merchant, Excel, Numbers) misparsed the mismatch.
		if ( $template instanceof \CTXFeed\V8\Template\CSVTemplate ) {
			return $template->render_columns( $headers, $config );
		}

		// Fallback (unknown template): minimal fputcsv with the default enclosure.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional use of php://temp for CSV formatting.
		$stream = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- Formats a CSV header line into an in-memory php://temp stream (no filesystem write); needed for proper CSV escaping.
		fputcsv( $stream, $headers, $delimiter, '"' );
		rewind( $stream );
		// CR/LF only — a bare rtrim() would eat the trailing tab of an empty
		// last header column in a tab-delimited feed (see CSVTemplate).
		$line = rtrim( stream_get_contents( $stream ), "\r\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream.
		fclose( $stream );

		return $line;
	}

	/**
	 * Get the feed file path based on feed name and format.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param string $format    Feed format (xml, csv, json, etc.).
	 * @param string $provider  Channel provider slug — nests the file under
	 *                          `woo-feed/{provider}/{format}/` (V5 layout) so
	 *                          the public feed URL is unchanged after a V5→V8
	 *                          upgrade. Empty keeps the flat layout.
	 *
	 * @return string Full file path.
	 */
	private function get_feed_file_path( string $feed_name, string $format, string $provider = '' ): string {
		// Single source of truth for the feed output path. Delegating to the
		// Filesystem utility (always injected in production) keeps generation,
		// duplication, and URL building in agreement and honors the
		// `ctxfeed_feed_dir` filter. The inline fallback (canonical
		// {uploads}/woo-feed/) only applies if the class is constructed without
		// the dependency — defensive; production always injects it.
		if ( $this->filesystem instanceof Filesystem ) {
			return $this->filesystem->get_feed_path( $feed_name, $format, $provider );
		}

		$upload_dir = wp_upload_dir();
		$feed_dir   = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/';
		if ( '' !== $provider ) {
			$feed_dir .= trailingslashit( sanitize_file_name( $provider ) )
				. trailingslashit( sanitize_file_name( $format ) );
		}

		if ( ! is_dir( $feed_dir ) ) {
			wp_mkdir_p( $feed_dir );
		}

		return $feed_dir . sanitize_file_name( $feed_name ) . '.' . $format;
	}

	/**
	 * Working (temp) path the feed streams into before atomic promotion.
	 *
	 * The whole run writes to "{feed}.{ext}.tmp"; finalize() renames it onto
	 * the live feed only when the run succeeds and produced content. This is
	 * what keeps a mid-run crash — or a transient zero-product query — from
	 * truncating the previous good feed (the top forum failure theme).
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $format    Feed format.
	 * @param string $provider  Channel provider.
	 * @return string Absolute working-file path.
	 */
	private function get_working_file_path( string $feed_name, string $format, string $provider = '' ): string {
		return $this->get_feed_file_path( $feed_name, $format, $provider ) . self::WORKING_SUFFIX;
	}

	/**
	 * Atomically move the finished working file onto the live feed path.
	 *
	 * Refuses to replace a previously-good, non-empty feed with a
	 * zero-product result — the merchant keeps their last good feed rather
	 * than a suddenly-empty one (CTX 1.1.4 / Comp 3.5). rename() on the same
	 * directory is atomic on POSIX filesystems, so a reader of the public
	 * feed URL never sees a half-written file.
	 *
	 * @since 8.0.0
	 *
	 * @param string $working_path  Finished temp file.
	 * @param string $final_path    Live feed path.
	 * @param int    $product_total Products the run was scheduled for.
	 * @param string $feed_name     Feed slug (for logging).
	 * @return bool True if the working file was promoted; false if kept-previous or absent.
	 */
	private function promote_working_file( string $working_path, string $final_path, int $product_total, string $feed_name ): bool {
		if ( ! file_exists( $working_path ) ) {
			return false;
		}

		$previous_ok = file_exists( $final_path ) && (int) filesize( $final_path ) > 0;

		if ( 0 === $product_total && $previous_ok ) {
			// Zero products + a good previous feed = almost always a transient
			// empty query. Keep the last good feed; discard the empty temp.
			wp_delete_file( $working_path );
			if ( $this->logger ) {
				$this->logger->warning(
					"Feed produced 0 products; kept the previous good feed: {$feed_name}",
					array( 'feed' => $final_path )
				);
			}
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Atomic same-directory swap of our own feed file; readers of the public feed URL never see a half-written file, and WP_Filesystem offers no atomic move.
		return rename( $working_path, $final_path );
	}
}
