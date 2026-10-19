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
use CTXFeed\V8\Utility\Date;

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
	 * @since 8.0.39 Parsed as UTC, independent of PHP's default (CBT-819).
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

		// A zone-less date is UTC, as it always was under WordPress's UTC
		// default — named here so another plugin's default cannot move it
		// (CBT-819, H6). Unparseable stays as it is.
		$parsed = date_create_immutable( (string) $date, Date::utc() );

		if ( false !== $parsed ) {
			$data['pubDate'] = $parsed->setTimezone( Date::utc() )->format( 'r' );
		}

		return $data;
	}
}
