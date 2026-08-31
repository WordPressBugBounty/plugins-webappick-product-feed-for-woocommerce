<?php
/**
 * ChannelConfig — Per-channel attribute requirements and validation rules.
 *
 * Stores what each channel needs (required fields, format specs, limits).
 * Used by FeedValidator to check if a feed has all required attributes
 * for its target channel.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-5.1
 */

namespace CTXFeed\V8\Channel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-channel attribute configuration.
 *
 * @since 8.0.0
 */
class ChannelConfig {

	/**
	 * Channel identifier.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $channel_id;

	/**
	 * Required attribute names.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private $required;

	/**
	 * Optional attribute names.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private $optional;

	/**
	 * Per-attribute limits (e.g., character count).
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $limits;

	/**
	 * Construct channel config.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-5.1
	 *
	 * @param string   $channel_id Channel identifier.
	 * @param string[] $required   Required attribute names.
	 * @param string[] $optional   Optional attribute names.
	 * @param array    $limits     Per-attribute limits.
	 */
	public function __construct( string $channel_id, array $required, array $optional = array(), array $limits = array() ) {
		$this->channel_id = $channel_id;
		$this->required   = $required;
		$this->optional   = $optional;
		$this->limits     = $limits;
	}

	/**
	 * Get the channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_channel_id(): string {
		return $this->channel_id;
	}

	/**
	 * Get required attribute names.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Required attributes.
	 */
	public function get_required(): array {
		return $this->required;
	}

	/**
	 * Get optional attribute names.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Optional attributes.
	 */
	public function get_optional(): array {
		return $this->optional;
	}

	/**
	 * Get per-attribute limit (e.g., character count).
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute name.
	 *
	 * @return int Limit value, or 0 if not set.
	 */
	public function get_attribute_limit( string $attr ): int {
		return $this->limits[ $attr ] ?? 0;
	}
}
