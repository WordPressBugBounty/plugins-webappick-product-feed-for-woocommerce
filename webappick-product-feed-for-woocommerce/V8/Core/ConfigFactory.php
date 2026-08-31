<?php
/**
 * ConfigFactory — Creates Config instances from feed names.
 *
 * Thin wrapper around Config::from_feed_name() for DI compatibility.
 * Registered as 'config_factory' in the Container.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config factory service.
 *
 * @since 8.0.0
 */
class ConfigFactory {

	/**
	 * Create a Config instance from a feed name.
	 *
	 * Delegates to Config::from_feed_name() static factory method.
	 *
	 * @since 8.0.0
	 *
	 * @param string $name Feed name identifier.
	 *
	 * @return Config|null Config instance or null if not found.
	 */
	public function create( string $name ): ?Config {

		return Config::from_feed_name( $name );
	}
}
