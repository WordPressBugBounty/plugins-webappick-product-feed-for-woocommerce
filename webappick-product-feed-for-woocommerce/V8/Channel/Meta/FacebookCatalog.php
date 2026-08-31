<?php
/**
 * FacebookCatalog — Product feed for Meta Commerce Manager.
 *
 * Supports CSV and TSV formats. Also used as base for InstagramShopping
 * since both platforms share the same catalog format.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Meta
 * @since      8.0.0
 * @implements CHAN-FRD-4.2
 */

namespace CTXFeed\V8\Channel\Meta;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facebook / Instagram Catalog channel.
 *
 * @since 8.0.0
 */
class FacebookCatalog extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'facebook_catalog';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Facebook / Instagram Catalog';
	}

	/**
	 * Get supported feed file types.
	 *
	 * Facebook prefers CSV/TSV but also supports XML.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.2
	 *
	 * @return string[] Supported formats.
	 */
	public function get_supported_formats(): array {
		return array( 'csv', 'tsv', 'xml' );
	}

	/**
	 * Get default feed format.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.2
	 *
	 * @return string Default format.
	 */
	public function get_default_format(): string {
		return 'csv';
	}

	/**
	 * Get required attributes for Facebook Catalog.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.2
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand' );
	}

	/**
	 * Get default attribute mappings for Facebook Catalog.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.2
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
	 * Get Facebook catalog specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://www.facebook.com/business/help/120325381656392';
	}
}
