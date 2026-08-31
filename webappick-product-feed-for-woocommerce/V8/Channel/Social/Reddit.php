<?php
/**
 * Reddit — Dynamic Product Ads catalog channel.
 *
 * Google-like catalog with raw underscore column names, UTF-8
 * CSV/TSV/XML RSS 2.0 (no ATOM), catalog id must match the Pixel/CAPI
 * product id.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Social
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Social;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reddit DPA channel.
 *
 * @since 8.0.0
 */
class Reddit extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'reddit';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Reddit Dynamic Product Ads';
	}

	/**
	 * Get supported formats.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Supported format identifiers.
	 */
	public function get_supported_formats(): array {
		return array( 'csv', 'tsv', 'xml' );
	}

	/**
	 * Get default output format.
	 *
	 * @since 8.0.0
	 *
	 * @return string Default format.
	 */
	public function get_default_format(): string {
		return 'csv';
	}

	/**
	 * Get required attributes.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link' );
	}

	/**
	 * Get default attribute mappings.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return array Default mappings.
	 */
	public function get_default_mappings(): array {
		return array(
			'id'           => array(
				'type'    => 'attribute',
				'wc_attr' => 'id',
			),
			'title'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'title',
			),
			'description'  => array(
				'type'    => 'attribute',
				'wc_attr' => 'description',
			),
			'availability' => array(
				'type'    => 'attribute',
				'wc_attr' => 'availability',
			),
			'condition'    => array(
				'type'  => 'pattern',
				'value' => 'new',
			),
			'price'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'price',
			),
			'link'         => array(
				'type'    => 'attribute',
				'wc_attr' => 'link',
			),
			'image_link'   => array(
				'type'    => 'attribute',
				'wc_attr' => 'image',
			),
		);
	}

	/**
	 * Get Reddit catalog specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://business.reddithelp.com/s/article/catalog-requirements';
	}
}
