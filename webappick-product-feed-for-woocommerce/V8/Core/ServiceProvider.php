<?php
/**
 * ServiceProvider — Abstract base class for all V8 module service providers.
 *
 * Each V8 module has exactly one ServiceProvider that handles service
 * registration (binding factories into the Container) and boot-time
 * initialization (registering WordPress hooks and runtime behavior).
 *
 * The Bootstrap guarantees that register() is called on ALL providers
 * before boot() is called on ANY provider, ensuring cross-module
 * dependency resolution works correctly.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-2.1
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract service provider base class.
 *
 * @since 8.0.0
 */
abstract class ServiceProvider {

	/**
	 * Register services into the container.
	 *
	 * Called during Phase 1 of the boot lifecycle. All providers have their
	 * register() called before any boot() is called.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-2.1
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	abstract public function register( Container $container ): void;

	/**
	 * Boot the service provider.
	 *
	 * Called during Phase 3 of the boot lifecycle, after all providers
	 * have registered their services. Safe to resolve any service here.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-2.1
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	abstract public function boot( Container $container ): void;
}
