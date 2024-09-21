<?php

/**
 * Compatibility class for DIVI_GRID_PLUGINCompatibility plugin
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

/**
 * Class DIVI_GRID_PLUGINCompatibility
 *
 * @package CTXFeed\V5\Compatibility
 */
class DIVI_GRID_PLUGINCompatibility
{
	/**
	 * DIVI_GRID_PLUGINCompatibility Constructor.
	 */
	public function __construct()
	{
		add_filter('woo_feed_filter_product_description', array($this, 'get_divi_generated_description'), 10, 4);
	}

	/**
	 * Retrieves the Divi generated description for a product.
	 *
	 * This function checks if a Divi-generated description exists for the product or its parent.
	 * If the product is a variation and has a parent product, it retrieves the variation description.
	 * Otherwise, it retrieves the old content (presumably Divi-generated) for the product or its parent.
	 * If a Divi-generated description is found, it replaces the original description with it.
	 *
	 * @param string $description The original product description.
	 * @param WC_Product $product The product object.
	 * @param array $config Configuration options (not used in this function).
	 * @param WC_Product $parent_product The parent product object (if applicable).
	 * @return string The modified product description.
	 */
	public function get_divi_generated_description($description, $product, $config, $parent_product)
	{
		if (!is_null($parent_product) && $product->is_type('variation')) {
			$divi_description = get_post_meta($product->get_id(), '_variation_description', true);
		} else {
			$divi_description = get_post_meta($product->get_id(), '_et_pb_old_content', true);
		}
		if (empty($divi_description)) {
			$divi_description = get_post_meta($product->get_parent_id(), '_et_pb_old_content', true);
		}

		if ($divi_description) {
			$description = $divi_description;
		}

		return $description;
	}

}
