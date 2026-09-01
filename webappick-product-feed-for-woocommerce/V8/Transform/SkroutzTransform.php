<?php
/**
 * SkroutzTransform — Skroutz-specific formatting rules.
 *
 * Availability is intentionally NOT rewritten. Skroutz.gr's `<availability>`
 * element expects a Greek delivery-time expression from the shop's own set
 * (e.g. "Άμεσα διαθέσιμο", "Διαθέσιμο από 1 έως 3 ημέρες", …) that Skroutz
 * cross-links to its predefined Greek values; a separate `<instock>` element
 * carries the Y/N stock flag. The old v8 conversion emitted the *English*
 * strings "Delivery 1 to 3 days" / "Delivery up to 30 days", which never
 * cross-link on a Greek channel. The resolved value the user maps now passes
 * through unchanged (V5 parity); users map the correct Greek phrase.
 *
 * Kept as a registered (currently pass-through) transform so any future
 * Skroutz-specific formatting has a home without touching the pipeline.
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
 * Skroutz transform.
 *
 * @since 8.0.0
 */
class SkroutzTransform implements TransformInterface {

	/**
	 * Transform product data for Skroutz compliance.
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
