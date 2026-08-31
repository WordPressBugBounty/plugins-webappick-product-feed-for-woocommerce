<?php
/**
 * OutputFormatter — Final formatting before writing to feed file.
 *
 * Handles encoding normalization (UTF-8 to target encoding) and
 * control character removal. Preserves newlines and tabs.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-8.1, XFRM-FRD-8.2, XFRM-FRD-8.3
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output formatter transform.
 *
 * @since 8.0.0
 */
class OutputFormatter implements TransformInterface {

	/**
	 * Apply output encoding normalization and control character removal.
	 *
	 * Converts string values to the target encoding when not UTF-8.
	 * Strips control characters while preserving newlines and tabs.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-8.1, XFRM-FRD-8.2
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with encoding applied.
	 */
	public function transform( array $product_data, Config $config ): array {
		$encoding = $config->get( 'feed_encoding', 'UTF-8' );

		foreach ( $product_data as &$value ) {
			// Skip non-string values. @implements XFRM-FRD-8.3.
			if ( ! is_string( $value ) ) {
				continue;
			}

			// Normalize encoding. @implements XFRM-FRD-8.1.
			if ( 'UTF-8' !== $encoding && function_exists( 'mb_convert_encoding' ) ) {
				$value = mb_convert_encoding( $value, $encoding, 'UTF-8' );
			}

			// Remove control characters except newlines and tabs. @implements XFRM-FRD-8.2.
			$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		}

		return $product_data;
	}
}
