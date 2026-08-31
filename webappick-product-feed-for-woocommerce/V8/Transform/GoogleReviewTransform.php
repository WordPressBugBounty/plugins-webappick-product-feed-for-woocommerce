<?php
/**
 * GoogleReviewTransform — Applies Google Product Review feed-specific formatting.
 *
 * Google Product Review feeds are XML-only and contain WooCommerce product
 * reviews (comments). This transform ensures data compliance:
 * - Strip HTML from review content, decode special chars
 * - Ensure review_timestamp is ISO 8601
 * - Sanitise reviewer name (strip tags)
 * - Escape XML special characters in text fields
 * - Validate rating range (1–5)
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @see        https://developers.google.com/product-review-feeds
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Product Review transform.
 *
 * @since 8.0.0
 */
class GoogleReviewTransform implements TransformInterface {

	/**
	 * Transform product/review data for Google Review feed compliance.
	 *
	 * No-ops for non-Google-Review providers.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product/review attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		// Only apply to Google Review feeds.
		if ( 'googlereview' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_review_content( $product_data );
		$product_data = $this->transform_review_timestamp( $product_data );
		$product_data = $this->transform_reviewer_name( $product_data );
		$product_data = $this->transform_rating( $product_data );
		$product_data = $this->transform_product_name( $product_data );

		return $product_data;
	}

	/**
	 * Strip HTML tags and decode special chars from review content.
	 *
	 * V5 parity: CommonHelper::strip_all_tags( wp_specialchars_decode( $content ) ).
	 * Falls back to original content if stripping yields empty string.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Review data.
	 *
	 * @return array Modified data.
	 */
	private function transform_review_content( array $data ): array {
		if ( empty( $data['content'] ) ) {
			return $data;
		}

		$content  = $data['content'];
		$decoded  = wp_specialchars_decode( $content );
		$stripped = wp_strip_all_tags( $decoded );

		// Fallback to original if stripping yields empty string.
		if ( strlen( $stripped ) > 0 ) {
			$content = $stripped;
		}

		// Escape XML special characters.
		$data['content'] = htmlspecialchars( $content, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		return $data;
	}

	/**
	 * Ensure review_timestamp is ISO 8601 formatted.
	 *
	 * V5 parity: gmdate( 'c', strtotime( $comment_date_gmt ) ).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Review data.
	 *
	 * @return array Modified data.
	 */
	private function transform_review_timestamp( array $data ): array {
		if ( empty( $data['review_timestamp'] ) ) {
			return $data;
		}

		$date = $data['review_timestamp'];

		// Already ISO 8601 — skip.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $date ) ) {
			return $data;
		}

		$timestamp = strtotime( $date );

		if ( false !== $timestamp ) {
			$data['review_timestamp'] = gmdate( 'c', $timestamp );
		}

		return $data;
	}

	/**
	 * Sanitise reviewer name — strip tags and escape XML entities.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Review data.
	 *
	 * @return array Modified data.
	 */
	private function transform_reviewer_name( array $data ): array {
		$name_key = 'name';

		// Support both flat and nested reviewer/name keys.
		if ( ! empty( $data[ $name_key ] ) ) {
			$data[ $name_key ] = htmlspecialchars(
				wp_strip_all_tags( $data[ $name_key ] ),
				ENT_XML1 | ENT_QUOTES,
				'UTF-8'
			);
		}

		// Also handle reviewer_name if used by resolver.
		if ( ! empty( $data['reviewer_name'] ) ) {
			$data['reviewer_name'] = htmlspecialchars(
				wp_strip_all_tags( $data['reviewer_name'] ),
				ENT_XML1 | ENT_QUOTES,
				'UTF-8'
			);
		}

		return $data;
	}

	/**
	 * Validate and clamp rating to 1–5 integer range.
	 *
	 * Google requires rating between min="1" and max="5".
	 * V5 skips reviews with empty ratings entirely.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Review data.
	 *
	 * @return array Modified data.
	 */
	private function transform_rating( array $data ): array {
		// Check both 'overall' (nested) and 'rating' (flat) keys.
		$rating_keys = array( 'overall', 'rating' );

		foreach ( $rating_keys as $key ) {
			if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
				continue;
			}

			$rating = (int) $data[ $key ];

			// Clamp to valid Google range.
			$rating = max( 1, min( 5, $rating ) );

			$data[ $key ] = (string) $rating;
		}

		return $data;
	}

	/**
	 * Escape XML special characters in product_name.
	 *
	 * V5 parity: htmlspecialchars() applied during XML serialisation.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Review data.
	 *
	 * @return array Modified data.
	 */
	private function transform_product_name( array $data ): array {
		if ( empty( $data['product_name'] ) ) {
			return $data;
		}

		$data['product_name'] = htmlspecialchars(
			$data['product_name'],
			ENT_XML1 | ENT_QUOTES,
			'UTF-8'
		);

		return $data;
	}
}
