<?php
/**
 * FeedServiceProvider — Registers feed module services.
 *
 * Seventh ServiceProvider in the Bootstrap registration order.
 * Binds 8 services: FeedManager, FeedGenerator, FeedValidator,
 * FeedScheduler, FeedRemoteTransport, FeedHealth, and BatchCalculator.
 * During boot(), resolves 12 cross-module dependencies (9 external
 * + batch_calculator + attribute_mapper + grouped_builder), re-registers
 * FeedGenerator with all 13 injected deps, and wires FeedScheduler
 * Action Scheduler callbacks with BatchCalculator for adaptive batch sizing.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-7.1, FEED-FRD-7.2
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;
use CTXFeed\V8\Utility\FeedLogger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed module service provider.
 *
 * @since 8.0.0
 */
class FeedServiceProvider extends ServiceProvider {

	/**
	 * Register feed services into the container.
	 *
	 * Services registered:
	 * - feed.manager:          CRUD for feed configurations.
	 * - feed.batch_calculator: Adaptive batch size calculator.
	 * - feed.generator:        Batch pipeline orchestrator (populated during boot).
	 * - feed.validator:        Pre-generation validation.
	 * - feed.scheduler:        Action Scheduler integration.
	 * - feed.exporter:         FTP/SFTP upload (Pro).
	 * - feed.health:           Post-generation quality analysis.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-7.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'feed.manager',
			function () {
				return new FeedManager();
			} 
		);

		$container->register(
			'feed.batch_calculator',
			function () {
				return new BatchCalculator();
			} 
		);

		// Generator is registered as placeholder; populated in boot().
		$container->register(
			'feed.generator',
			function () {
				return new FeedGenerator(
					new FeedManager(),
					null, // Logger — injected in boot().
					null, // CacheWarmer — injected in boot().
					null, // ProductRepository — injected in boot().
					null, // ProductQuery — injected in boot().
					null, // TemplateEngine — injected in boot().
					null, // StreamWriter — injected in boot().
					null, // TransformPipeline — injected in boot().
					null, // FilterManager — injected in boot().
					null, // BatchCalculator — injected in boot().
					null, // AttributeNameMapper — injected in boot().
					null  // GroupedAttributeBuilder — injected in boot().
				);
			} 
		);

		$container->register(
			'feed.validator',
			function () {
				return new FeedValidator();
			} 
		);

		$container->register(
			'feed.scheduler',
			function () {
				return new FeedScheduler();
			} 
		);

		$container->register(
			'feed.logger',
			function () {
				return new FeedLogger();
			} 
		);

		$container->register(
			'feed.exporter',
			function () {
				return new FeedRemoteTransport();
			} 
		);

		$container->register(
			'feed.health',
			function () {
				return new FeedHealth();
			} 
		);
	}

	/**
	 * Boot feed services.
	 *
	 * Resolves 12 cross-module dependencies from the container (9 external
	 * + batch_calculator + attribute_mapper + grouped_builder), re-registers
	 * FeedGenerator with all 13 dependencies injected, and wires FeedScheduler
	 * with both the generator and BatchCalculator for adaptive batch sizing.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-7.2
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Resolve cross-module dependencies (9 external + 1 internal).
		$manager          = $container->resolve( 'feed.manager' );
		$logger           = $container->resolve( 'core.logger' );
		$cache_warmer     = $container->resolve( 'product.cache_warmer' );
		$product_repo     = $container->resolve( 'product.repository' );
		$product_query    = $container->resolve( 'product.query' );
		$template_engine  = $container->resolve( 'template.engine' );
		$stream_writer    = $container->resolve( 'template.stream_writer' );
		$transform        = $container->resolve( 'transform.pipeline' );
		$filter_manager   = $container->resolve( 'filter.manager' );
		$batch_calculator = $container->resolve( 'feed.batch_calculator' );
		$attribute_mapper = $container->resolve( 'channel.attribute_mapper' );
		$grouped_builder  = $container->resolve( 'template.grouped_builder' );
		$review_resolver  = $container->resolve( 'product.review_resolver' );
		$filesystem       = $container->resolve( 'utility.filesystem' );

		// Re-register generator with all dependencies. @implements FEED-FRD-7.2.
		// Compat manager removed — third-party plugin compatibility is handled
		// by the `ctx-compatibility/` submodule directly via V5-shaped hooks.
		$container->register(
			'feed.generator',
			function () use (
				$manager,
				$logger,
				$cache_warmer,
				$product_repo,
				$product_query,
				$template_engine,
				$stream_writer,
				$transform,
				$filter_manager,
				$batch_calculator,
				$attribute_mapper,
				$grouped_builder,
				$review_resolver,
				$filesystem
			) {
				return new FeedGenerator(
					$manager,
					$logger,
					$cache_warmer,
					$product_repo,
					$product_query,
					$template_engine,
					$stream_writer,
					$transform,
					$filter_manager,
					$batch_calculator,
					$attribute_mapper,
					$grouped_builder,
					$review_resolver,
					$filesystem
				);
			} 
		);

		// Inject per-feed logger into generator.
		$feed_logger = $container->resolve( 'feed.logger' );
		$generator   = $container->resolve( 'feed.generator' );
		$generator->set_feed_logger( $feed_logger );

		// Inject the Skroutz per-variation nested-block builder. It reuses the
			// product repository to resolve each child variation's mapped sub-fields
			// and self-gates (provider=skroutz + variation_* mapped), so it costs
			// nothing for any other feed.
			$generator->set_variations_builder(
				new \CTXFeed\V8\Product\SkroutzVariationsBuilder( $product_repo )
			);

			// Wire Action Scheduler callbacks with dependencies. @implements FEED-FRD-7.2.
		$scheduler = $container->resolve( 'feed.scheduler' );
		$scheduler->set_generator( $generator );
		$scheduler->set_batch_calculator( $batch_calculator );
		$scheduler->set_manager( $manager );
		$scheduler->set_feed_logger( $feed_logger );
		$scheduler->boot();

		$this->register_recurring_backfill( $scheduler, $manager );
	}

	/**
	 * One-shot backfill: register recurring schedules for already-active
	 * feeds.
	 *
	 * Existing 70K-install feeds with `status=1` were marked auto-update-on
	 * before V8's auto-update wiring landed. Without this sweep, a flag
	 * mismatch persists indefinitely: the UI shows auto-update enabled but
	 * no Action Scheduler entry exists.
	 *
	 * The sweep runs as a single deferred Action Scheduler job — NOT
	 * inline on every page load — so it doesn't add cost to normal
	 * requests. A flag option (`ctxfeed_recurring_synced_v1`) marks the
	 * sweep as done, so subsequent boots skip the scheduling. Bump the
	 * version (`_v2`) in a future patch to force a re-sync.
	 *
	 * Hooks the handler unconditionally so any AS worker that picks up
	 * the action can dispatch it; only schedules a new job when the flag
	 * is unset AND no job is already pending.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedScheduler $scheduler Resolved scheduler instance.
	 * @param FeedManager   $manager   Resolved manager instance.
	 *
	 * @return void
	 */
	private function register_recurring_backfill( $scheduler, $manager ): void {
		$flag_option = 'ctxfeed_recurring_synced_v1';
		$action      = 'ctxfeed_v8_sync_recurring_schedules';

		// Always register the handler — AS may dispatch the action even
		// after the flag is set if a previous worker crashed mid-run.
		add_action(
			$action,
			static function () use ( $scheduler, $manager, $flag_option ) {
				$scheduler->sync_all_recurring_schedules( $manager );
				update_option( $flag_option, time(), false );
			},
			10,
			0
		);

		if ( get_option( $flag_option ) ) {
			return; // Already synced.
		}

		if ( ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_schedule_single_action' )
		) {
			return; // Action Scheduler not available — skip silently.
		}

		// Guard against double-scheduling on rapid back-to-back requests.
		if ( as_has_scheduled_action( $action, array(), 'ctxfeed' ) ) {
			return;
		}

		// 30-second delay so the boot request returns quickly. AS worker
		// will pick it up on the next cron tick.
		as_schedule_single_action( time() + 30, $action, array(), 'ctxfeed' );
	}
}
