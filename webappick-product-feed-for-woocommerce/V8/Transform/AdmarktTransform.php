<?php
/**
 * AdmarktTransform — Applies Admarkt-specific formatting rules.
 *
 * - Title: XML ampersand escaping (&amp;) for XML feeds
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
 * Admarkt transform.
 *
 * @since 8.0.0
 */
class AdmarktTransform implements TransformInterface {

	/**
	 * Transform product data for Admarkt compliance.
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

		if ( 'admarkt' !== $provider ) {
			return $product_data;
		}

		$feed_type = $config->get( 'feedType', 'xml' );

		if ( 'xml' !== $feed_type ) {
			return $product_data;
		}

		$product_data = $this->transform_title( $product_data );

		return $product_data;
	}

	/**
	 * Escape ampersands in title for Admarkt XML feeds.
	 *
	 * Admarkt requires explicit &amp; escaping in title element.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_title( array $data ): array {
		if ( empty( $data['title'] ) ) {
			return $data;
		}

		// Only escape bare ampersands (not already-escaped ones).
		$data['title'] = preg_replace( '/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $data['title'] );

		return $data;
	}
}
