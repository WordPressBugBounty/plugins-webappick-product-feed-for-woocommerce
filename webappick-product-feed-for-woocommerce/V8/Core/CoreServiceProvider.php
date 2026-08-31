<?php
/**
 * CoreServiceProvider — Registers core module services and boots the HookManager.
 *
 * This is the first ServiceProvider in the registration order. It binds
 * the essential core services (config_factory, hook_manager) and boots
 * the HookManager to register top-level WordPress hooks.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-2.3
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core module service provider.
 *
 * @since 8.0.0
 */
class CoreServiceProvider extends ServiceProvider {

	/**
	 * Register core services into the container.
	 *
	 * Services registered:
	 * - config_factory: Factory for creating Config instances from feed names.
	 * - hook_manager:   The centralized HookManager instance.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {

		$container->register(
			'core.config_factory',
			function () {
				return new ConfigFactory();
			} 
		);

		$container->register(
			'core.hook_manager',
			function () {
				return new HookManager();
			} 
		);

		$container->register(
			'core.logger',
			function () {
				return new Logger();
			} 
		);
	}

	/**
	 * Boot core services.
	 *
	 * HookManager extends ServiceProvider and its boot() method calls
	 * register_all() automatically. However, HookManager is registered
	 * as a container service (not as a provider), so we must explicitly
	 * trigger its boot here.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {

		/** @var HookManager $hook_manager */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$hook_manager = $container->resolve( 'core.hook_manager' );
		$hook_manager->boot( $container );
	}
}
