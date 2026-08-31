<?php
/**
 * PinterestRssTransform — Applies Pinterest RSS feed-specific formatting.
 *
 * - pubDate: RFC 2822 format (date('r'))
 * - DateTimeNow: inject current RFC 2822 timestamp
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
 * Pinterest RSS transform.
 *
 * @since 8.0.0
 */
class PinterestRssTransform implements TransformInterface {

	/**
	 * Transform product data for Pinterest RSS feed compliance.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		if ( 'pinterest_rss' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_pub_date( $product_data );

		return $product_data;
	}

	/**
	 * Convert pubDate to RFC 2822 format.
	 *
	 * Pinterest RSS requires dates in RFC 2822 format (e.g.,
	 * "Thu, 21 Dec 2024 16:01:07 +0000").
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_pub_date( array $data ): array {
		if ( empty( $data['pubDate'] ) ) {
			return $data;
		}

		$date = $data['pubDate'];

		// Already RFC 2822.
		if ( preg_match( '/^\w{3}, \d{2} \w{3} \d{4}/', $date ) ) {
			return $data;
		}

		$timestamp = strtotime( $date );

		if ( false !== $timestamp ) {
			$data['pubDate'] = gmdate( 'r', $timestamp );
		}

		return $data;
	}
}
