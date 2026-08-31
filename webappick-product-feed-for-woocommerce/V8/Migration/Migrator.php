<?php
/**
 * Migrator — Orchestrates V5-to-V8 migration process.
 *
 * Coordinates config migration, schedule migration, and rollback capability.
 * Fires WordPress hooks at each lifecycle point for extensibility and
 * wraps operations in error handling via MigrationException.
 *
 * @package    CTXFeed
 * @subpackage V8/Migration
 * @since      8.0.0
 * @implements MIG-FRD-1
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration orchestrator.
 *
 * @since 8.0.0
 */
class Migrator {

	/**
	 * Feed config migrator.
	 *
	 * @since 8.0.0
	 * @var ConfigMigrator
	 */
	private ConfigMigrator $configMigrator;

	/**
	 * Schedule migrator.
	 *
	 * @since 8.0.0
	 * @var ScheduleMigrator
	 */
	private ScheduleMigrator $scheduleMigrator;

	/**
	 * Rollback manager.
	 *
	 * @since 8.0.0
	 * @var RollbackManager
	 */
	private RollbackManager $rollback;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param ConfigMigrator   $configMigrator   Feed config migrator.
	 * @param ScheduleMigrator $scheduleMigrator Schedule migrator.
	 * @param RollbackManager  $rollback         Rollback manager.
	 */
	public function __construct(
		ConfigMigrator $configMigrator,
		ScheduleMigrator $scheduleMigrator,
		RollbackManager $rollback
	) {
		$this->configMigrator   = $configMigrator;
		$this->scheduleMigrator = $scheduleMigrator;
		$this->rollback         = $rollback;
	}

	/**
	 * Run the full migration from V5 to V8.
	 *
	 * Creates a backup of current V5 state, fires the migration_started
	 * hook, migrates configs and schedules, switches the engine to V8,
	 * and fires the migration_completed hook.
	 *
	 * @since 8.0.0
	 * @implements MIG-FRD-1.1
	 *
	 * @return array Migration results with 'configs' and 'schedules' keys.
	 *
	 * @throws MigrationException If migration fails after backup.
	 *
	 * @hook action ctxfeed_migration_started  (fired after backup, before migration)
	 * @hook action ctxfeed_migration_completed (fired after engine switch)
	 */
	public function migrate(): array {
		Logger::info( 'Starting V5 to V8 migration.' );

		// Backup current state for rollback.
		$this->rollback->createBackup();

		try {
			/**
			 * Fires after backup is created, before config/schedule migration begins.
			 *
			 * @since 8.0.0
			 */
			do_action( 'ctxfeed_migration_started' );

			$results = array(
				'configs'   => $this->configMigrator->migrateAll(),
				'schedules' => $this->scheduleMigrator->migrateAll(),
			);

			// Switch engine to V8.
			update_option( 'ctxfeed_engine', 'v8' );
			update_option( 'ctxfeed_migration_date', current_time( 'mysql' ) );

			/**
			 * Fires after migration is complete and engine is switched to V8.
			 *
			 * @since 8.0.0
			 *
			 * @param int $config_count Number of feed configs migrated.
			 */
			do_action( 'ctxfeed_migration_completed', count( $results['configs'] ) );

			Logger::info( 'Migration completed.', $results );

			return $results;

		} catch ( \Exception $e ) {
			Logger::error( 'Migration failed: ' . $e->getMessage() );
			throw new MigrationException(
				esc_html( 'Migration failed: ' . $e->getMessage() ),
				(int) $e->getCode(),
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception chaining: the previous-exception object is not output, it preserves the stack trace.
			);
		}
	}

	/**
	 * Rollback to V5.
	 *
	 * Fires the before_rollback hook, restores the V5 backup,
	 * and sets the engine back to V5.
	 *
	 * @since 8.0.0
	 * @implements MIG-FRD-1.2
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @throws MigrationException If rollback fails.
	 *
	 * @hook action ctxfeed_before_rollback (fired before rollback begins)
	 */
	public function rollback(): bool {
		Logger::info( 'Rolling back to V5.' );

		try {
			/**
			 * Fires before rollback begins.
			 *
			 * @since 8.0.0
			 */
			do_action( 'ctxfeed_before_rollback' );

			$result = $this->rollback->restore();
			update_option( 'ctxfeed_engine', 'v5' );

			return $result;

		} catch ( \Exception $e ) {
			Logger::error( 'Rollback failed: ' . $e->getMessage() );
			throw new MigrationException(
				esc_html( 'Rollback failed: ' . $e->getMessage() ),
				(int) $e->getCode(),
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception chaining: the previous-exception object is not output, it preserves the stack trace.
			);
		}
	}

	/**
	 * Check if migration is needed.
	 *
	 * @since 8.0.0
	 *
	 * @return bool True if current engine is V5.
	 */
	public function isMigrationNeeded(): bool {
		$engine = get_option( 'ctxfeed_engine', 'v5' );
		return 'v5' === $engine;
	}

	/**
	 * Get the rollback manager instance.
	 *
	 * @since 8.0.0
	 *
	 * @return RollbackManager
	 */
	public function getRollbackManager(): RollbackManager {
		return $this->rollback;
	}
}
