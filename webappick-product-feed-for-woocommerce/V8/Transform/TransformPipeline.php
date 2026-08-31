<?php
/**
 * TransformPipeline — Runs product data through an ordered chain of transforms.
 *
 * Receives DI-injected transforms via constructor. Applies prefix/suffix,
 * string operations, number formatting, category mapping, conditional logic,
 * output encoding, and UTM tracking in the order defined by XFRM-FRD-1.2.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-1.1, XFRM-FRD-1.2, XFRM-FRD-1.3
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transform pipeline orchestrator.
 *
 * @since 8.0.0
 */
class TransformPipeline {

	/**
	 * Ordered array of transform instances.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $transforms = array();

	/**
	 * Constructor.
	 *
	 * Accepts a DI-injected array of transforms. Does NOT instantiate
	 * transforms internally (AD-XFRM-001).
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-1.3
	 *
	 * @param array $transforms Ordered array of TransformInterface instances.
	 */
	public function __construct( array $transforms ) {
		$this->transforms = $transforms;
	}

	/**
	 * Run all transforms on a product's resolved attributes.
	 *
	 * Fires `ctxfeed_before_transform` before the loop and
	 * `ctxfeed_after_transform` after. The final result is
	 * filterable via `ctxfeed_transformed_product`.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-1.1
	 * @hook ctxfeed_before_transform Action before pipeline runs.
	 * @hook ctxfeed_after_transform  Action after pipeline completes.
	 * @hook ctxfeed_transformed_product Filter on the final result.
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function apply( array $product_data, Config $config ): array {
		/**
		 * Fires before the transform pipeline runs.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $product_data Resolved product attributes.
		 * @param Config $config       Feed configuration.
		 */
		do_action( 'ctxfeed_before_transform', $product_data, $config );

		foreach ( $this->transforms as $transform ) {
			if ( $transform instanceof TransformInterface ) {
				$product_data = $transform->transform( $product_data, $config );
			}
		}

		/**
		 * Fires after all transforms complete.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $product_data Transformed product attributes.
		 * @param Config $config       Feed configuration.
		 */
		do_action( 'ctxfeed_after_transform', $product_data, $config );

		/**
		 * Filter the final transformed product data.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $product_data Transformed product attributes.
		 * @param Config $config       Feed configuration.
		 */
		return apply_filters( 'ctxfeed_transformed_product', $product_data, $config );
	}
}
