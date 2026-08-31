<?php
/**
 * Container — Lightweight Dependency Injection container for V8 engine.
 *
 * Registers services via factory callables, resolves them as singletons,
 * and manages the two-phase ServiceProvider lifecycle (register → boot).
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-1.1, CORE-FRD-1.2, CORE-FRD-1.3, CORE-FRD-1.4, CORE-FRD-1.5, CORE-FRD-1.6
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency injection container with singleton resolution and provider lifecycle.
 *
 * @since 8.0.0
 */
class Container {

	/**
	 * Global singleton instance.
	 *
	 * @since 8.0.0
	 * @var Container|null
	 */
	private static ?Container $instance = null;

	/**
	 * Registered factory callables keyed by service ID.
	 *
	 * @since 8.0.0
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved service instances (singleton cache).
	 *
	 * @since 8.0.0
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Registered service providers.
	 *
	 * @since 8.0.0
	 * @var ServiceProvider[]
	 */
	private array $providers = array();

	/**
	 * Whether boot() has been called.
	 *
	 * @since 8.0.0
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the global singleton container instance.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.6
	 *
	 * @return Container The singleton container instance.
	 */
	public static function get_instance(): Container {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a service with a factory callable.
	 *
	 * The factory receives the Container as its first argument and is only
	 * called on the first resolve(). Last-write-wins: re-registering an ID
	 * overwrites the previous factory.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.1
	 *
	 * @param string   $id      Service identifier (snake_case).
	 * @param callable $factory Factory callable that receives Container.
	 *
	 * @return void
	 */
	public function register( string $id, callable $factory ): void {

		$this->factories[ $id ] = $factory;

		// Clear cached instance so overridden factory takes effect.
		unset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service by ID (singleton — same instance on subsequent calls).
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.2
	 *
	 * @param string $id Service identifier.
	 *
	 * @return mixed The resolved service instance.
	 *
	 * @throws \RuntimeException If the service ID is not registered.
	 */
	public function resolve( string $id ) {

		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( esc_html( "Service not found: {$id}" ) );
		}

		$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );

		return $this->instances[ $id ];
	}

	/**
	 * Check if a service factory is registered (does NOT trigger resolution).
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.3
	 *
	 * @param string $id Service identifier.
	 *
	 * @return bool True if registered, false otherwise.
	 */
	public function has( string $id ): bool {

		return isset( $this->factories[ $id ] );
	}

	/**
	 * Add a service provider to the container.
	 *
	 * @since 8.0.0
	 *
	 * @param ServiceProvider $provider The service provider instance.
	 *
	 * @return void
	 */
	public function add_provider( ServiceProvider $provider ): void {

		$this->providers[] = $provider;
	}

	/**
	 * Call register() on all added service providers (Phase 1).
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-2.2
	 *
	 * @return void
	 */
	public function register_providers(): void {

		foreach ( $this->providers as $provider ) {
			$provider->register( $this );
		}
	}

	/**
	 * Apply external service bindings via filter (Phase 2).
	 *
	 * Each binding entry must be array( 'id' => string, 'factory' => callable ).
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.4
	 * @hook ctxfeed_container_bindings
	 *
	 * @return void
	 */
	public function apply_external_bindings(): void {

		/**
		 * Filter external service bindings.
		 *
		 * Allows Pro, AI Extension, and third-party plugins to add or override services.
		 *
		 * @since 8.0.0
		 *
		 * @param array $bindings Array of [ 'id' => string, 'factory' => callable ] entries.
		 */
		$bindings = apply_filters( 'ctxfeed_container_bindings', array() );

		if ( ! is_array( $bindings ) ) {
			return;
		}

		foreach ( $bindings as $binding ) {
			if ( isset( $binding['id'], $binding['factory'] ) && is_callable( $binding['factory'] ) ) {
				$this->register( $binding['id'], $binding['factory'] );
			}
		}
	}

	/**
	 * Call boot() on all service providers (Phase 3). Executes only once.
	 *
	 * After boot completes, fires `ctxfeed_v8_booted` action.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-1.5
	 * @hook ctxfeed_v8_booted
	 *
	 * @return void
	 */
	public function boot(): void {

		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->providers as $provider ) {
			$provider->boot( $this );
		}

		/**
		 * Fires after V8 engine is fully initialized.
		 *
		 * All services are registered and all providers have booted.
		 *
		 * @since 8.0.0
		 *
		 * @param Container $container The DI container instance.
		 */
		do_action( 'ctxfeed_v8_booted', $this );
	}

	/**
	 * Reset the container state. For testing only.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function reset(): void {

		$this->factories = array();
		$this->instances = array();
		$this->providers = array();
		$this->booted    = false;
		self::$instance  = null;
	}
}
