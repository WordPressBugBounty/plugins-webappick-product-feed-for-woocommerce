<?php
/**
 * ChannelRegistry — metadata registry for the built-in channel classes.
 *
 * This is NOT the template list and it gates nothing. Every channel template
 * and its attributes are FREE; the list a store owner picks from comes from
 * `MerchantAttributes` + `TemplateDefaults` (see
 * 01-features/channels/google-clone-channels.md). The registry only holds
 * the thin channel classes under V8/Channel/* — the performance dashboard
 * reads `get_all()` for channel names — and fires
 * `ctxfeed_channels_registered` so third-party code can add its own.
 *
 * History: the original V8 design gated channels behind Pro ("top 5 free,
 * Pro unlocks the rest"). That design was dropped before 8.0.0 shipped; the
 * gate method and its "Pro-only" stub classes were removed in 8.0.27
 * (CBT-633) after a support draft cited them as fact.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-1.1, CHAN-FRD-1.2, CHAN-FRD-1.4, CHAN-FRD-7.1
 */

namespace CTXFeed\V8\Channel;

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
		// Built-in channel classes. @implements CHAN-FRD-1.4.
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
		 * Fires after the built-in channel classes are registered.
		 *
		 * Third-party code can register additional channel classes here.
		 * (The Pro plugin does not: it gates features, never channels.)
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
}
