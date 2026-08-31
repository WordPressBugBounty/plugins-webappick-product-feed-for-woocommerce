<?php
/**
 * UtilityServiceProvider — Registers utility module services.
 *
 * Second ServiceProvider in the Bootstrap registration order.
 * Binds six utility services: filesystem, encryptor, sanitizer,
 * validator, cache, and performance. All are passive helpers
 * resolved on demand — no hooks registered at boot time.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-7.1, UTIL-FRD-7.2
 */

namespace CTXFeed\V8\Utility;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility module service provider.
 *
 * @since 8.0.0
 */
class UtilityServiceProvider extends ServiceProvider {

	/**
	 * Register utility services into the container.
	 *
	 * Services registered:
	 * - filesystem:  Feed file I/O, atomic writes, temp cleanup.
	 * - encryptor:   AES-256-CBC encrypt/decrypt for secrets.
	 * - sanitizer:   Feed value, name, config, URL sanitization.
	 * - validator:   Feed config and mapping validation.
	 * - cache:       Transient-based cache with bulk flush.
	 * - performance: Start/stop metrics tracking.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-7.1
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {

		$container->register(
			'utility.filesystem',
			function () {
				return new Filesystem();
			} 
		);

		$container->register(
			'utility.encryptor',
			function () {
				return new Encryptor();
			} 
		);

		$container->register(
			'utility.sanitizer',
			function () {
				return new Sanitizer();
			} 
		);

		$container->register(
			'utility.validator',
			function () {
				return new Validator();
			} 
		);

		$container->register(
			'utility.cache',
			function () {
				return new Cache();
			} 
		);

		$container->register(
			'utility.performance',
			function () {
				return new Performance();
			} 
		);
	}

	/**
	 * Boot the utility service provider.
	 *
	 * Utility services are passive helpers — no WordPress hooks to register.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-7.2
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $container is required by the ServiceProviderInterface::boot() contract; utility services are passive helpers with no hooks, so nothing is resolved here.

		// Utility services are passive — no hooks to register.
	}
}
