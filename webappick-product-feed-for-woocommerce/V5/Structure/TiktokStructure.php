<?php

/**
 * Class TiktokStructure
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Structure
 */

namespace CTXFeed\V5\Structure;

use CTXFeed\V5\Merchant\MerchantAttributeReplaceFactory;

class TiktokStructure {

	/**
	 * Configuration settings.
	 *
	 * @var \Config $config
	 */
	private $config;

	/**
	 * Constructor for TiktokStructure.
	 *
	 * @param mixed $config Configuration settings.
	 */
	public function __construct( $config ) {
		$this->config               = $config;
		$this->config->itemWrapper  = 'item';
		$this->config->itemsWrapper = 'items';
	}

	/**
	 * Retrieves the XML structure.
	 *
	 * @return array The constructed XML data structure.
	 */
	public function get_xml_structure() {
		$attributes  = $this->config->attributes;
		$mattributes = $this->config->mattributes;
		$static      = $this->config->default;
		$type        = $this->config->type;

		$wrapper     = \str_replace( " ", "_", $this->config->itemWrapper );;
		$wrapper     = apply_filters('woo_feed_product_item_wrapper', $wrapper, $this->config );

		$data = [];
		foreach ( $mattributes as $key => $attribute ) {

			$attribute_value                           = ( $type[ $key ] === 'pattern' ) ? $static[ $key ] : $attributes[ $key ];
			$replaced_attribute                        = MerchantAttributeReplaceFactory::replace_attribute( $attribute, $this->config );
			$replaced_attribute                        = \str_replace( " ", "_", $replaced_attribute );
			$data[ $wrapper ][][ $replaced_attribute ] = $attribute_value;
		}

		return $data;
	}

}
