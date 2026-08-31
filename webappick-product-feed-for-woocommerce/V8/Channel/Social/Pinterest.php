<?php
/**
 * Pinterest — Product catalog feed for Pinterest Shopping.
 *
 * Pinterest Catalog channel with product data specification
 * for shopping pins and dynamic retargeting.
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
 * Pinterest Catalog channel.
 *
 * @since 8.0.0
 */
class Pinterest extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'pinterest';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Pinterest Catalog';
	}

	/**
	 * Get required attributes for Pinterest.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'link', 'image_link', 'price', 'availability' );
	}

	/**
	 * Get default attribute mappings for Pinterest.
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
			'link'         => array(
				'type'    => 'attribute',
				'wc_attr' => 'link',
			),
			'image_link'   => array(
				'type'    => 'attribute',
				'wc_attr' => 'image',
			),
			'price'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'price',
			),
			'availability' => array(
				'type'    => 'attribute',
				'wc_attr' => 'availability',
			),
		);
	}

	/**
	 * Get Pinterest specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://help.pinterest.com/en/business/article/before-you-get-started-with-catalogs';
	}
}
