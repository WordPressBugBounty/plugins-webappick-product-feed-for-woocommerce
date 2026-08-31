<?php
/**
 * ChannelRegistry — Central registry of all supported merchant channels.
 *
 * Free version includes top 5 channels; Pro unlocks all 130+ via
 * FeatureGate. Third-party developers can register custom channels
 * via the ctxfeed_channels_registered action hook.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-1.1, CHAN-FRD-1.2, CHAN-FRD-1.3, CHAN-FRD-1.4, CHAN-FRD-7.1
 */

namespace CTXFeed\V8\Channel;

use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel registry.
 *
 * @since 8.0.0
 */
class ChannelRegistry {

	/**
	 * Registered channels indexed by ID.
	 *
	 * @since 8.0.0
	 * @var ChannelInterface[]
	 */
	private $channels = array();

	/**
	 * Initialize built-in channels and fire registration hook.
	 *
	 * Registers the free channels (Google Shopping, Facebook Catalog,
	 * Bing Shopping, Pinterest, TikTok, Snapchat, Reddit, X, ChatGPT,
	 * Perplexity) then fires the ctxfeed_channels_registered action for
	 * Pro/third-party registration.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-1.4
	 * @hook ctxfeed_channels_registered Action to register additional channels.
	 *
	 * @return void
	 */
	public function init(): void {
		// Free channels (always available). @implements CHAN-FRD-1.4.
		$this->register( new Google\GoogleShopping() );
		$this->register( new Meta\FacebookCatalog() );
		$this->register( new Microsoft\BingShopping() );
		$this->register( new Social\Pinterest() );
		$this->register( new Social\TikTok() );
		$this->register( new Social\Snapchat() );
		$this->register( new Social\Reddit() );
		$this->register( new Social\X() );
		$this->register( new AI\ChatGpt() );
		$this->register( new AI\Perplexity() );

		/**
		 * Fires after built-in channels are registered.
		 *
		 * Pro plugin and third-party developers use this action
		 * to register additional channels.
		 *
		 * @since 8.0.0
		 *
		 * @param ChannelRegistry $registry The channel registry instance.
		 */
		do_action( 'ctxfeed_channels_registered', $this );
	}

	/**
	 * Register a channel.
	 *
	 * Duplicate IDs overwrite (allows Pro to enhance free channels).
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-1.1
	 *
	 * @param ChannelInterface $channel Channel instance to register.
	 *
	 * @return void
	 */
	public function register( ChannelInterface $channel ): void {
		$this->channels[ $channel->get_id() ] = $channel;
	}

	/**
	 * Get a channel by ID.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-1.2
	 *
	 * @param string $id Channel identifier.
	 *
	 * @return ChannelInterface|null Channel instance or null.
	 */
	public function get( string $id ): ?ChannelInterface {
		return $this->channels[ $id ] ?? null;
	}

	/**
	 * Get all registered channels (no limit).
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-1.2
	 *
	 * @return ChannelInterface[] All registered channels.
	 */
	public function get_all(): array {
		return $this->channels;
	}

	/**
	 * Get channels available to current user (respects Free/Pro limits).
	 *
	 * Free version returns max 5 channels. Pro returns all.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-1.3
	 *
	 * @return ChannelInterface[] Available channels.
	 */
	public function get_available(): array {
		$max = FeatureGate::limit( 'channels', 5 );

		if ( $max >= count( $this->channels ) ) {
			return $this->channels;
		}

		return array_slice( $this->channels, 0, $max, true );
	}
}
