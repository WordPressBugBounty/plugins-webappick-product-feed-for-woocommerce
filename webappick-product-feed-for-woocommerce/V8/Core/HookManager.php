<?php
/**
 * HookManager — Centralized WordPress hook coordinator for V8 engine.
 *
 * Registers top-level WordPress hook entry points (rest_api_init,
 * admin_menu, etc.) but delegates actual callback registration to
 * individual module ServiceProviders. This keeps module code in
 * module files.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-5.1, CORE-FRD-5.2, CORE-FRD-5.3
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized hook coordinator extending ServiceProvider.
 *
 * @since 8.0.0
 */
class HookManager extends ServiceProvider {

	/**
	 * Register services into the container.
	 *
	 * HookManager has no services to register — it is a coordinator only.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-5.3
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function register( Container $container ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $container is required by the ServiceProvider signature; HookManager registers no services.

		// Nothing to register — HookManager is a hook coordinator only.
	}

	/**
	 * Boot the hook manager by registering all top-level WordPress hooks.
	 *
	 * This is the single entry point that sets up all top-level WordPress hooks.
	 * Individual module ServiceProviders register their own specific callbacks
	 * during their own boot().
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-5.3
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $container is required by the ServiceProvider signature; hooks are registered without container services.

		$this->register_all();
	}

	/**
	 * Register all top-level WordPress hooks grouped by context.
	 *
	 * Hook groups:
	 * - REST:      rest_api_init → delegates to API module.
	 * - Admin:     admin_menu, admin_enqueue_scripts → delegates to Admin module.
	 * - Scheduler: Action Scheduler hooks → delegates to Feed module.
	 * - Init:      General hooks on init → delegates to relevant modules.
	 *
	 * After all internal hooks, fires `ctxfeed_v8_hooks_registered` for
	 * Pro and Extension plugins to register additional hooks.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-5.1, CORE-FRD-5.2
	 * @hook ctxfeed_v8_hooks_registered
	 *
	 * @return void
	 */
	public function register_all(): void {

		// REST: delegates to ApiServiceProvider.
		add_action( 'rest_api_init', array( $this, 'on_rest_api_init' ) );

		// Admin: delegates to AdminServiceProvider.
		add_action( 'admin_menu', array( $this, 'on_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'on_admin_enqueue_scripts' ) );

		// Scheduler: Action Scheduler hooks delegate to FeedServiceProvider.
		add_action( 'ctxfeed_generate_batch', array( $this, 'on_generate_batch' ), 10, 4 );
		add_action( 'ctxfeed_finalize_feed', array( $this, 'on_finalize_feed' ), 10, 2 );

		// Init: general module hooks.
		add_action( 'init', array( $this, 'on_init' ) );

		/**
		 * Fires after all V8 internal hooks are registered.
		 *
		 * Pro and Extension plugins hook here to register additional hooks.
		 *
		 * @since 8.0.0
		 *
		 * @param HookManager $hook_manager The HookManager instance.
		 */
		do_action( 'ctxfeed_v8_hooks_registered', $this );
	}

	/**
	 * Callback for rest_api_init — delegates to API module.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function on_rest_api_init(): void {

		/**
		 * Fires when V8 REST routes should be registered.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_v8_register_rest_routes' );
	}

	/**
	 * Callback for admin_menu — delegates to Admin module.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function on_admin_menu(): void {

		/**
		 * Fires when V8 admin pages should be registered.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_v8_register_admin_pages' );
	}

	/**
	 * Callback for admin_enqueue_scripts — delegates to Admin module.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function on_admin_enqueue_scripts(): void {

		/**
		 * Fires when V8 admin assets should be enqueued.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_v8_enqueue_admin_assets' );
	}

	/**
	 * Callback for ctxfeed_generate_batch — re-dispatches to a public action
	 * for third-party extensions. The actual batch processing happens in
	 * FeedScheduler::handle_batch which hooks into the same action.
	 *
	 * Action Scheduler dispatches the array values from
	 * `as_schedule_single_action()` as positional callback arguments — so
	 * the parameter order here MUST match the order the scheduler enqueues
	 * the args in (see FeedScheduler::trigger_run() and
	 * FeedScheduler::schedule_next_or_finalize()).
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name  Feed slug identifier.
	 * @param int    $offset     Current batch offset (0-based).
	 * @param int    $batch_size Products in this batch.
	 * @param int    $total      Total products to process.
	 *
	 * @return void
	 */
	public function on_generate_batch( string $feed_name, int $offset, int $batch_size, int $total ): void {

		/**
		 * Fires when a feed batch should be processed.
		 *
		 * @since 8.0.0
		 *
		 * @param string $feed_name  Feed slug identifier.
		 * @param int    $offset     Current batch offset (0-based).
		 * @param int    $batch_size Products in this batch.
		 * @param int    $total      Total products to process.
		 */
		do_action( 'ctxfeed_v8_process_batch', $feed_name, $offset, $batch_size, $total );
	}

	/**
	 * Callback for ctxfeed_finalize_feed — delegates to Feed module.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_id Feed identifier.
	 * @param int    $total   Total products processed.
	 *
	 * @return void
	 */
	public function on_finalize_feed( string $feed_id, int $total ): void {

		/**
		 * Fires when a feed should be finalized after all batches complete.
		 *
		 * @since 8.0.0
		 *
		 * @param string $feed_id Feed identifier.
		 * @param int    $total   Total products processed.
		 */
		do_action( 'ctxfeed_v8_finalize_feed', $feed_id, $total );
	}

	/**
	 * Callback for init — general module hooks.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function on_init(): void {

		/**
		 * Fires on WordPress init for V8 general initialization.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_v8_init' );
	}
}
