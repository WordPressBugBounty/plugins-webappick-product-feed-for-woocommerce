<?php
/**
 * AbstractChannel — Base implementation with common logic.
 *
 * Concrete channels override only what's different. Provides sensible
 * defaults for supported formats (xml, csv, tsv), default format (xml),
 * optional attributes (empty), and spec URL (empty).
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-3.1, CHAN-FRD-3.2
 */

namespace CTXFeed\V8\Channel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract channel base class.
 *
 * @since 8.0.0
 */
abstract class AbstractChannel implements ChannelInterface {

	/**
	 * Get supported feed file types.
	 *
	 * Default: XML, CSV, TSV.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-3.1
	 *
	 * @return string[] Supported formats.
	 */
	public function get_supported_formats(): array {
		return array( 'xml', 'csv', 'tsv' );
	}

	/**
	 * Get default feed format.
	 *
	 * Default: XML.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-3.1
	 *
	 * @return string Default format.
	 */
	public function get_default_format(): string {
		return 'xml';
	}

	/**
	 * Get optional but recommended attributes.
	 *
	 * Default: empty array.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-3.1
	 *
	 * @return string[] Optional attribute names.
	 */
	public function get_optional_attributes(): array {
		return array();
	}

	/**
	 * Get channel specification/documentation URL.
	 *
	 * Default: empty string.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-3.1
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return '';
	}

	/**
	 * Get all channel attributes (required + optional), filtered.
	 *
	 * Merges required and optional attributes, then applies the
	 * `ctxfeed_channel_attributes` filter so extensions can add
	 * or remove attributes per channel.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-7.1
	 * @hook ctxfeed_channel_attributes
	 *
	 * @return string[] Filtered attribute names.
	 */
	public function get_attributes(): array {
		$attributes = array_merge(
			$this->get_required_attributes(),
			$this->get_optional_attributes()
		);

		/**
		 * Filter the attribute list for a channel.
		 *
		 * @since 8.0.0
		 *
		 * @param string[]         $attributes Merged required + optional attributes.
		 * @param ChannelInterface $channel    The channel instance.
		 */
		return apply_filters( 'ctxfeed_channel_attributes', $attributes, $this );
	}
}
