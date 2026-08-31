<?php
/**
 * GoogleShopping — Google Merchant Center product feed.
 *
 * Supports XML (Atom) and CSV formats with Google's product data
 * specification. Primary channel with full default attribute mappings.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Google
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Google;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Shopping channel.
 *
 * @since 8.0.0
 */
class GoogleShopping extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'google_shopping';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Google Shopping';
	}

	/**
	 * Get required attributes for Google Shopping.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array(
			'id',
			'title',
			'description',
			'link',
			'image_link',
			'price',
			'availability',
			'google_product_category',
		);
	}

	/**
	 * Get optional attributes for Google Shopping.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Optional attribute names.
	 */
	public function get_optional_attributes(): array {
		return array(
			'brand',
			'gtin',
			'mpn',
			'condition',
			'color',
			'size',
			'material',
			'gender',
			'age_group',
			'sale_price',
			'additional_image_link',
			'shipping',
			'tax',
			'product_type',
		);
	}

	/**
	 * Get default attribute mappings for Google Shopping.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return array Default mappings.
	 */
	public function get_default_mappings(): array {
		return array(
			'id'                      => array(
				'type'    => 'attribute',
				'wc_attr' => 'id',
			),
			'title'                   => array(
				'type'    => 'attribute',
				'wc_attr' => 'title',
			),
			'description'             => array(
				'type'    => 'attribute',
				'wc_attr' => 'description',
			),
			'link'                    => array(
				'type'    => 'attribute',
				'wc_attr' => 'link',
			),
			'image_link'              => array(
				'type'    => 'attribute',
				'wc_attr' => 'image',
			),
			'price'                   => array(
				'type'    => 'attribute',
				'wc_attr' => 'price',
			),
			'availability'            => array(
				'type'    => 'attribute',
				'wc_attr' => 'availability',
			),
			'google_product_category' => array(
				'type'    => 'attribute',
				'wc_attr' => 'product_type',
			),
			'condition'               => array(
				'type'  => 'pattern',
				'value' => 'new',
			),
		);
	}

	/**
	 * Get Google Shopping specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://support.google.com/merchants/answer/7052112';
	}
}
