<?php
/**
 * MigrationServiceProvider — Registers migration module services.
 *
 * Thirteenth ServiceProvider in the Bootstrap registration order.
 * Binds 4 services: ConfigMigrator, ScheduleMigrator, RollbackManager,
 * and Migrator. During boot(), wires the Migrator with its 3 collaborators
 * and re-registers `api.migration` (from ApiServiceProvider) with the
 * Migrator injected so that MigrationEndpoint can delegate to it.
 *
 * @package    CTXFeed
 * @subpackage V8/Migration
 * @since      8.0.0
 * @implements MIG-FRD-1, MIG-FRD-2
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;
use CTXFeed\V8\API\MigrationEndpoint;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration module service provider.
 *
 * Orchestrates the registration and bootstrapping of the V5→V8
 * migration system including config migration, schedule migration,
 * rollback management, and REST API endpoint wiring.
 *
 * @since 8.0.0
 */
class MigrationServiceProvider extends ServiceProvider {

	/**
	 * Register migration services into the container.
	 *
	 * Phase 1: Binds factories — no cross-module resolution here.
	 * All 3 collaborators (ConfigMigrator, ScheduleMigrator, RollbackManager)
	 * have zero constructor dependencies, so they are registered directly.
	 * Migrator is registered as a placeholder — re-registered in boot()
	 * with resolved collaborators.
	 *
	 * Services registered:
	 * - migration.config_migrator:  V5→V8 feed config converter.
	 * - migration.schedule_migrator: WP-Cron → Action Scheduler converter.
	 * - migration.rollback:         Backup/restore manager.
	 * - migration.migrator:         Orchestrator (placeholder).
	 *
	 * @since 8.0.0
	 * @implements MIG-FRD-2.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {

		// ConfigMigrator — no dependencies.
		$container->register(
			'migration.config_migrator',
			function () {
				return new ConfigMigrator();
			} 
		);

		// ScheduleMigrator — no dependencies.
		$container->register(
			'migration.schedule_migrator',
			function () {
				return new ScheduleMigrator();
			} 
		);

		// RollbackManager — no dependencies.
		$container->register(
			'migration.rollback',
			function () {
				return new RollbackManager();
			} 
		);

		// Migrator placeholder — re-registered in boot() with resolved collaborators.
		$container->register(
			'migration.migrator',
			function () {
				return new Migrator(
					new ConfigMigrator(),
					new ScheduleMigrator(),
					new RollbackManager()
				);
			} 
		);
	}

	/**
	 * Boot migration services.
	 *
	 * Phase 3: Resolves the 3 collaborator singletons from the container,
	 * re-registers the Migrator with shared instances, then re-registers
	 * `api.migration` (originally bound by ApiServiceProvider at #9) with
	 * the Migrator injected. Container uses last-write-wins for singleton()
	 * so position #13 overrides #9's no-arg placeholder.
	 *
	 * Boot sequence:
	 * 1. Resolve 3 collaborator singletons from container.
	 * 2. Re-register migration.migrator with shared instances.
	 * 3. Resolve migration.migrator.
	 * 4. Re-register api.migration with Migrator injected.
	 * 5. Fire ctxfeed_migration_booted action.
	 *
	 * @since 8.0.0
	 * @implements MIG-FRD-1.1, MIG-FRD-2.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 *
	 * @hook action ctxfeed_migration_booted (fired)
	 */
	public function boot( Container $container ): void {

		// ── Resolve collaborator singletons ──────────────────────────
		$config_migrator   = $container->resolve( 'migration.config_migrator' );
		$schedule_migrator = $container->resolve( 'migration.schedule_migrator' );
		$rollback          = $container->resolve( 'migration.rollback' );

		// ── Re-register Migrator with shared instances ───────────────
		$container->register(
			'migration.migrator',
			function () use ( $config_migrator, $schedule_migrator, $rollback ) {
				return new Migrator( $config_migrator, $schedule_migrator, $rollback );
			} 
		);

		$migrator = $container->resolve( 'migration.migrator' );

		// ── Re-register api.migration with Migrator injected ─────────
		// ApiServiceProvider (#9) registered a no-arg MigrationEndpoint.
		// We override it here (#13) with the Migrator dependency injected.
		$container->register(
			'api.migration',
			function () use ( $migrator ) {
				return new MigrationEndpoint( $migrator );
			} 
		);

		/**
		 * Fires after all migration services have been booted and wired.
		 *
		 * Allows Pro and third-party plugins to hook into the migration
		 * system after the Migrator and endpoint are fully configured.
		 *
		 * @since 8.0.0
		 *
		 * @param Migrator $migrator The migration orchestrator instance.
		 */
		do_action( 'ctxfeed_migration_booted', $migrator );
	}
}
