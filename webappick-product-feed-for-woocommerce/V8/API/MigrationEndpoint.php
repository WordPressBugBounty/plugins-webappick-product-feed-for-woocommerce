<?php
/**
 * MigrationEndpoint — REST API for V5-to-V8 migration management.
 *
 * Handles migration start, status check, and rollback operations.
 * Delegates to the Migrator orchestrator for actual migration logic.
 *
 * Routes:
 * - POST /ctxfeed/v8/migration/start    → Trigger full V5→V8 migration.
 * - GET  /ctxfeed/v8/migration/status   → Current migration state.
 * - POST /ctxfeed/v8/migration/rollback → Rollback to V5.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-4.5
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Migration\Migrator;
use CTXFeed\V8\Migration\MigrationException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration REST endpoint.
 *
 * @since 8.0.0
 */
class MigrationEndpoint extends RestController {

	/**
	 * The migration orchestrator.
	 *
	 * Nullable for backward compatibility with ApiServiceProvider's
	 * initial no-arg registration. MigrationServiceProvider re-registers
	 * with the Migrator injected during boot().
	 *
	 * @since 8.0.0
	 * @var Migrator|null
	 */
	private ?Migrator $migrator;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param Migrator|null $migrator The migration orchestrator (null when
	 *                                registered by ApiServiceProvider placeholder).
	 */
	public function __construct( ?Migrator $migrator = null ) {
		$this->migrator = $migrator;
	}

	/**
	 * Register migration routes.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.5
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/migration/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_migration' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/migration/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/migration/rollback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	/**
	 * POST /ctxfeed/v8/migration/start
	 *
	 * Triggers the full V5→V8 migration: backup, config migration,
	 * schedule migration, and engine switch.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function start_migration( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		if ( null === $this->migrator ) {
			return $this->error( 'Migration service not available.', 503 );
		}

		try {
			$results = $this->migrator->migrate();

			return $this->success(
				array(
					'status'    => 'completed',
					'engine'    => 'v8',
					'configs'   => $results['configs'],
					'schedules' => $results['schedules'],
				) 
			);
		} catch ( MigrationException $e ) {
			return $this->error( $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /ctxfeed/v8/migration/status
	 *
	 * Returns the current migration state: engine version, whether
	 * migration is needed, migration date, and backup availability.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$engine           = get_option( 'ctxfeed_engine', 'v5' );
		$migration_date   = get_option( 'ctxfeed_migration_date', '' );
		$backup_exists    = ! empty( get_option( 'ctxfeed_v5_backup' ) );
		$migration_needed = ( 'v5' === $engine );

		return $this->success(
			array(
				'engine'           => $engine,
				'migration_needed' => $migration_needed,
				'migration_date'   => $migration_date,
				'backup_exists'    => $backup_exists,
			) 
		);
	}

	/**
	 * POST /ctxfeed/v8/migration/rollback
	 *
	 * Rolls back from V8 to V5 by restoring the backup created
	 * during migration and switching the engine back to V5.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function rollback( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		if ( null === $this->migrator ) {
			return $this->error( 'Migration service not available.', 503 );
		}

		try {
			$result = $this->migrator->rollback();

			if ( ! $result ) {
				return $this->error( 'No backup found for rollback.', 404 );
			}

			return $this->success(
				array(
					'status' => 'rolled_back',
					'engine' => 'v5',
				) 
			);
		} catch ( MigrationException $e ) {
			return $this->error( $e->getMessage(), 500 );
		}
	}
}
