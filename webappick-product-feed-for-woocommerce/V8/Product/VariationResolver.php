<?php
/**
 * VariationResolver — Handles variation-specific resolution with parent fallback.
 *
 * When a variation product has an empty attribute value, this resolver
 * fetches the value from the parent variable product. Uses setter
 * injection for the AttributeResolver reference to break the
 * circular dependency (AD-PROD-003).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-11.1, PROD-FRD-11.2
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variation resolver with parent product fallback.
 *
 * @since 8.0.0
 */
class VariationResolver {

	/**
	 * Attribute resolver reference (set via setter injection).
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver = null;

	/**
	 * Set the AttributeResolver instance.
	 *
	 * Called during ProductServiceProvider::boot() to break the
	 * circular dependency between VariationResolver and AttributeResolver.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-11.2
	 *
	 * @param AttributeResolver $resolver Attribute resolver instance.
	 *
	 * @return void
	 */
	public function set_attribute_resolver( AttributeResolver $resolver ): void {
		$this->attribute_resolver = $resolver;
	}

	/**
	 * Resolve an attribute from the parent product.
	 *
	 * Fetches the parent variable product and re-resolves the same
	 * attribute mapping against it via AttributeResolver.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-11.1
	 *
	 * @param \WC_Product $variation Variation product.
	 * @param array       $mapping   Attribute mapping configuration.
	 * @param Config      $config    Feed configuration.
	 *
	 * @return mixed Parent product's resolved value or empty string.
	 */
	public function resolve_from_parent( \WC_Product $variation, array $mapping, Config $config ) {
		$parent_id = $variation->get_parent_id();

		if ( 0 === $parent_id ) {
			return '';
		}

		$parent = wc_get_product( $parent_id );

		if ( ! $parent ) {
			return '';
		}

		// Re-resolve the same mapping against the parent product.
		if ( null === $this->attribute_resolver ) {
			return '';
		}

		return $this->attribute_resolver->resolve( $parent, $mapping, $config );
	}
}
