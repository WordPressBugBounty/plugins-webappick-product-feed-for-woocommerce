<?php
/**
 * PrefixResolverInterface — a resolver that claims a source-attribute prefix.
 *
 * The extension seam the Pro plugin uses to plug its attribute engines
 * (dynamic attributes `wf_dattribute_*`, attribute mapping
 * `wp_attr_mapping_*`) into the free AttributeResolver (CBT-642). Free
 * asks each registered resolver whether it `handles()` an attribute name
 * and delegates `resolve()` to the first one that does. Anything can be
 * registered through the `ctxfeed_attribute_prefix_resolvers` filter.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.27
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A source-attribute resolver keyed by name prefix.
 *
 * @since 8.0.27
 */
interface PrefixResolverInterface {

	/**
	 * Whether this resolver owns the given source attribute name.
	 *
	 * @since 8.0.27
	 *
	 * @param string $attr Source attribute name (e.g. `wf_dattribute_size`).
	 *
	 * @return bool
	 */
	public function handles( string $attr ): bool;

	/**
	 * Resolve the attribute for a product.
	 *
	 * @since 8.0.27
	 *
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $attr          Source attribute name.
	 * @param Config      $config        Feed configuration.
	 * @param string      $merchant_attr Optional. Channel field name the row maps to.
	 *
	 * @return string Resolved value ('' when nothing applies).
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config, string $merchant_attr = '' ): string;

	/**
	 * Receive the central resolver so nested source attributes can be resolved.
	 *
	 * @since 8.0.27
	 *
	 * @param AttributeResolver $resolver Central attribute router.
	 *
	 * @return void
	 */
	public function set_attribute_resolver( AttributeResolver $resolver ): void;
}
