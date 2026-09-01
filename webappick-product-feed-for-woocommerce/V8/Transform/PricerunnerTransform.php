<?php
/**
 * PricerunnerTransform — Pricerunner-specific formatting rules.
 *
 * Availability is intentionally NOT rewritten. PriceRunner's stock field is
 * permissive — it accepts "in stock"/"out of stock", "Yes"/"No",
 * InStock/OutOfStock, "preorder", "backorder", a numeric stock count, etc. —
 * so the resolved WooCommerce availability the user maps is already valid and
 * passes through unchanged (V5 parity). Forcing "Yes"/"No" (v8.0.0–8.0.1)
 * silently overrode whatever the user mapped, which this restores.
 *
 * Kept as a registered (currently pass-through) transform so any future
 * PriceRunner-specific formatting has a home without touching the pipeline.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pricerunner transform.
 *
 * @since 8.0.0
 */
class PricerunnerTransform implements TransformInterface {

	/**
	 * Transform product data for Pricerunner compliance.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data (unchanged — see class docblock).
	 */
	public function transform( array $product_data, Config $config ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config is required by TransformInterface; this transform passes data through unchanged.
		return $product_data;
	}
}
