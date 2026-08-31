<?php
/**
 * TikTok — Product catalog feed for TikTok Shop and TikTok Ads.
 *
 * CSV-only channel with TikTok-specific attribute names
 * (sku_id instead of id).
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
 * TikTok Catalog channel.
 *
 * @since 8.0.0
 */
class TikTok extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'tiktok';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'TikTok Catalog';
	}

	/**
	 * Get supported formats — CSV only.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Supported format identifiers.
	 */
	public function get_supported_formats(): array {
		return array( 'csv' );
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
	 * Get required attributes for TikTok.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link' );
	}

	/**
	 * Get default attribute mappings for TikTok.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return array Default mappings.
	 */
	public function get_default_mappings(): array {
		return array(
			'sku_id'       => array(
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
	 * Get TikTok specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://ads.tiktok.com/help/article/catalog-product-parameters';
	}
}
