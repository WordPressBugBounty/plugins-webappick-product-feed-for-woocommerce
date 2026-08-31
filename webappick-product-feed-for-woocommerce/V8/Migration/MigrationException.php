<?php
/**
 * MigrationException — Custom exception for migration errors.
 *
 * Thrown by the Migrator when migration or rollback operations fail.
 * Caught by MigrationEndpoint to return structured error responses.
 *
 * @package    CTXFeed
 * @subpackage V8/Migration
 * @since      8.0.0
 */

namespace CTXFeed\V8\Migration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration exception.
 *
 * @since 8.0.0
 */
class MigrationException extends \RuntimeException {
}
