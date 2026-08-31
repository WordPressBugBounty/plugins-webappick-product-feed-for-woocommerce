<?php
/**
 * ChannelServiceProvider — Registers channel module services.
 *
 * Eighth ServiceProvider in the Bootstrap registration order.
 * Binds 2 services: ChannelRegistry and ChannelConfig placeholder.
 * During boot(), initializes built-in channels and fires the
 * ctxfeed_channels_registered action for Pro/third-party registration.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-6.1, CHAN-FRD-6.2
 */

namespace CTXFeed\V8\Channel;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel module service provider.
 *
 * @since 8.0.0
 */
class ChannelServiceProvider extends ServiceProvider {

	/**
	 * Register channel services into the container.
	 *
	 * Services registered:
	 * - channel.registry: Central registry of all merchant channels.
	 * - channel.config:   Per-channel attribute configuration (placeholder).
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-6.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'channel.registry',
			function () {
				return new ChannelRegistry();
			} 
		);

		$container->register(
			'channel.config',
			function () {
				return new ChannelConfig( '', array(), array(), array() );
			} 
		);

		$container->register(
			'channel.attribute_mapper',
			function () {
				return new AttributeNameMapper();
			} 
		);
	}

	/**
	 * Boot channel services.
	 *
	 * Initializes the ChannelRegistry with built-in channels and fires
	 * the ctxfeed_channels_registered action for Pro/third-party extensions.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-6.2
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		$registry = $container->resolve( 'channel.registry' );
		$registry->init();
	}
}
