<?php
/**
 * Autoloader for the temporary `compatibility/` layer.
 *
 * Maps the `CTXFeed\Compat\*` namespace to files under this directory
 * (Support/, Shims/, Providers/, plus top-level classes).
 *
 * This layer deliberately uses a DISTINCT namespace (`CTXFeed\Compat`)
 * from the live `ctx-compatibility/` submodule (`CTXFeed\Compatibility`)
 * so the two can coexist during the transition with zero class collisions.
 *
 * Loading this file only registers the autoloader — it does NOT activate
 * any compatibility behaviour. Activation is explicit:
 *   • Shims  → CTXFeed\Compat\CompatibilityFactory::init()  (dormant; not
 *              yet called from woo-feed.php — the submodule stays live).
 *   • Providers → CTXFeed\Compat\Bootstrap::init()          (active; wired
 *              from V8 so the relocated-from-core logic keeps running).
 *
 * @package CTXFeed\Compat
 * @since   8.0.0
 */

defined( 'ABSPATH' ) || die();

if ( ! class_exists( 'CTXFeed_Compat_Autoloader' ) ) {

	/**
	 * PSR-4-style autoloader for the CTXFeed\Compat namespace.
	 */
	class CTXFeed_Compat_Autoloader {

		/**
		 * Namespace prefix owned by this autoloader.
		 *
		 * @var string
		 */
		const PREFIX = 'CTXFeed\\Compat\\';

		/**
		 * Register the autoloader.
		 */
		public function __construct() {
			spl_autoload_register( array( $this, 'load' ) );
		}

		/**
		 * Resolve a class name to a file under this directory and require it.
		 *
		 * @param string $class_name Fully-qualified class name.
		 * @return void
		 */
		public function load( $class_name ) {
			if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( self::PREFIX ) );
			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
			$file     = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';

			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Autoloader by definition resolves a path at runtime; $file is built from the PSR-4 prefix plus __DIR__ and is file_exists()-checked above.
				require_once $file;
			}
		}
	}

	new CTXFeed_Compat_Autoloader();
}
