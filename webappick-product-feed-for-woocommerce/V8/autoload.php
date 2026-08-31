<?php
/**
 * PSR-4 Autoloader for CTXFeed\V8 namespace.
 *
 * Maps CTXFeed\V8\{Module}\{Class} to V8/{Module}/{Class}.php.
 *
 * @package    CTXFeed
 * @subpackage V8
 * @since      8.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	/**
	 * Autoload CTXFeed\V8 classes using PSR-4 mapping.
	 *
	 * @since 8.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return void
	 */
	function ( $class_name ) {

		$prefix     = 'CTXFeed\\V8\\';
		$prefix_len = strlen( $prefix );

		// Only handle CTXFeed\V8 namespace.
		if ( 0 !== strncmp( $class_name, $prefix, $prefix_len ) ) {
			return;
		}

		$relative_class = substr( $class_name, $prefix_len );
		$file_path      = __DIR__ . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- A PSR-4 autoloader resolves the path from the class name by definition; the path is built from __DIR__ plus the namespace segment, never from user input.
			require_once $file_path;
		}
	}
);

spl_autoload_register(
	/**
	 * Autoload the relocated WebAppick AppServices SDK, which keeps its
	 * original `CTXFeed\AppServices` namespace (the un-editable V5-era wiring
	 * references it) but now lives under V8/AppServices/.
	 *
	 * @since 8.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return void
	 */
	function ( $class_name ) {

		$prefix     = 'CTXFeed\\AppServices\\';
		$prefix_len = strlen( $prefix );

		if ( 0 !== strncmp( $class_name, $prefix, $prefix_len ) ) {
			return;
		}

		$relative_class = substr( $class_name, $prefix_len );
		$file_path      = __DIR__ . DIRECTORY_SEPARATOR . 'AppServices' . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- A PSR-4 autoloader resolves the path from the class name by definition; the path is built from __DIR__ plus the namespace segment, never from user input.
			require_once $file_path;
		}
	}
);
