<?php
/**
 * ChannelInterface — Contract that all merchant/channel implementations must follow.
 *
 * Each channel defines its required attributes, feed format, and validation
 * rules. All 15 concrete channels implement this interface.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-2.1
 */

namespace CTXFeed\V8\Channel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel interface contract.
 *
 * @since 8.0.0
 */
interface ChannelInterface {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID (e.g., 'google_shopping').
	 */
	public function get_id(): string;

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name (e.g., 'Google Shopping').
	 */
	public function get_name(): string;

	/**
	 * Get supported feed file types.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Array of formats (e.g., array( 'xml', 'csv' )).
	 */
	public function get_supported_formats(): array;

	/**
	 * Get default feed format.
	 *
	 * @since 8.0.0
	 *
	 * @return string Default format (e.g., 'xml').
	 */
	public function get_default_format(): string;

	/**
	 * Get required attributes for this channel.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Array of required attribute names.
	 */
	public function get_required_attributes(): array;

	/**
	 * Get optional but recommended attributes.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Array of optional attribute names.
	 */
	public function get_optional_attributes(): array;

	/**
	 * Get default attribute mappings for this channel.
	 *
	 * @since 8.0.0
	 *
	 * @return array Mapping of channel attrs to WC attrs.
	 */
	public function get_default_mappings(): array;

	/**
	 * Get channel specification/documentation URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL or empty string.
	 */
	public function get_spec_url(): string;
}
