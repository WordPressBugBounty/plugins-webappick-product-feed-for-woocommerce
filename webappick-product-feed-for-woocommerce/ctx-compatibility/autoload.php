<?php
/*
 * Autoloading file & classes
 *
 * @since 4.7
 *
 * */

defined( 'ABSPATH' ) || die();


if ( ! class_exists( 'CompatibilityLoader' ) ) {
	class CompatibilityLoader {
		public function __construct() {
			spl_autoload_register( [ $this, 'ctx_feed_compatibility_autoloader' ] );
		}

		public function ctx_feed_compatibility_autoloader( $class ) {
			if ( strpos( $class, 'CTXFeed\Compatibility' ) !== false ) {

				$temp_class = str_replace( [ "CTXFeed\\Compatibility\\", "\\" ], [ '', '/' ], $class );

				$file_path = __DIR__ . DIRECTORY_SEPARATOR . $temp_class . '.php';

				$file_path = str_replace( 'WebAppick' . DIRECTORY_SEPARATOR . 'Feed', '', $file_path );

				if ( !class_exists($temp_class) && file_exists( $file_path ) ) {
					require_once $file_path;
				}
			}

		}
	}
}

new CompatibilityLoader();

