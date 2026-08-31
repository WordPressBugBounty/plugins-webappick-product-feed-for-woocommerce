<?php
/**
 * TransformInterface — Contract for all data transform classes.
 *
 * Each transform receives the full product data array and feed config,
 * modifies applicable attributes, and returns the array.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-2.1
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transform interface.
 *
 * @since 8.0.0
 */
interface TransformInterface {

	/**
	 * Transform product data according to feed config rules.
	 *
	 * Implementations SHALL return the `$product_data` array with
	 * modifications applied. When no applicable config rules exist,
	 * return unchanged (no-op behavior).
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-2.1
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array;
}
