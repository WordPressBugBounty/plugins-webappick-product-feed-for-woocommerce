<?php
/**
 * X — X (Twitter) Shopping catalog channel.
 *
 * Shopping Manager ingests CSV/TSV only (bulk upload or scheduled URL
 * fetch). Raw underscore field names, lowercase space-form availability
 * enums (in stock / out of stock / preorder / available for order /
 * discontinued), money "<n> <ISO-4217>", HTTPS product links, one of
 * brand/gtin/mpn required.
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
 * X Shopping channel.
 *
 * @since 8.0.0
 */
class X extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'x';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'X (Twitter) Shopping';
	}

	/**
	 * Get supported formats — Shopping Manager takes CSV/TSV only.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Supported format identifiers.
	 */
	public function get_supported_formats(): array {
		return array( 'csv', 'tsv' );
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
	 * Get required attributes (brand satisfies the one-of
	 * brand/gtin/mpn rule).
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand' );
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
			'brand'        => array(
				'type'  => 'pattern',
				'value' => '',
			),
		);
	}

	/**
	 * Get X Shopping specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://business.x.com/en/help/shopping-specs';
	}
}
