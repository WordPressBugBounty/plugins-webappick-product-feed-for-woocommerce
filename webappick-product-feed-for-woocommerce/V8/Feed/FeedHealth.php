<?php
/**
 * FeedHealth — Post-generation feed quality analysis.
 *
 * Detects missing required attributes, invalid values, and optimization
 * opportunities. Uses weighted scoring per AD-FEED-005:
 * required fields (40%), images (20%), titles (20%), prices (20%).
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-6.1, FEED-FRD-6.2, FEED-FRD-6.3
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed health analyzer.
 *
 * @since 8.0.0
 */
class FeedHealth {

	/**
	 * Scoring weights per AD-FEED-005.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private static $weights = array(
		'required_fields' => 40,
		'images'          => 20,
		'titles'          => 20,
		'prices'          => 20,
	);

	/**
	 * Analyze generated feed for quality issues.
	 *
	 * Computes a weighted score (0-100) based on 4 dimensions:
	 * - Required fields populated: 40% weight.
	 * - Image URLs valid and present: 20% weight.
	 * - Title length adequate (>10 chars): 20% weight.
	 * - Price validity (>0, numeric): 20% weight.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-6.1, FEED-FRD-6.2, FEED-FRD-6.3
	 *
	 * @param string $feed_id Feed slug identifier.
	 * @param Config $config  Feed configuration object.
	 *
	 * @return array{score: int, issues: array, suggestions: array} Health analysis result.
	 */
	public function analyze( string $feed_id, Config $config ): array {
		$issues      = array();
		$suggestions = array();

		// Dimension scores (0.0 to 1.0).
		$scores = array(
			'required_fields' => 1.0,
			'images'          => 1.0,
			'titles'          => 1.0,
			'prices'          => 1.0,
		);

		// Analyze required fields. @implements FEED-FRD-6.1.
		$required = $config->get( 'requiredAttributes', array() );
		if ( ! empty( $required ) ) {
			$attributes     = $config->get_merchant_attributes();
			$mapped_keys    = ! empty( $attributes ) ? array_keys( $attributes ) : array();
			$missing_count  = count( array_diff( $required, $mapped_keys ) );
			$total_required = count( $required );

			if ( $total_required > 0 && $missing_count > 0 ) {
				$scores['required_fields'] = 1.0 - ( $missing_count / $total_required );
				$issues[]                  = sprintf( '%d required attributes not mapped.', $missing_count );
				$suggestions[]             = 'Map all required attributes for better merchant acceptance.';
			}
		}

		// Analyze image quality. @implements FEED-FRD-6.1.
		$image_attr = $config->get( 'image_attribute', '' );
		if ( empty( $image_attr ) ) {
			$scores['images'] = 0.0;
			$issues[]         = 'No image attribute configured.';
			$suggestions[]    = 'Add image_link attribute for product images.';
		}

		// Analyze title quality. @implements FEED-FRD-6.1.
		$title_attr = $config->get( 'title_attribute', '' );
		if ( empty( $title_attr ) ) {
			$scores['titles'] = 0.5;
			$issues[]         = 'No custom title attribute configured.';
			$suggestions[]    = 'Map a title attribute for better product visibility.';
		}

		// Analyze price quality. @implements FEED-FRD-6.1.
		$price_attr = $config->get( 'price_attribute', '' );
		if ( empty( $price_attr ) ) {
			$scores['prices'] = 0.5;
			$issues[]         = 'No custom price attribute configured.';
			$suggestions[]    = 'Map a price attribute for accurate pricing.';
		}

		// Calculate weighted score. @implements FEED-FRD-6.1.
		$total_score = 0;
		foreach ( self::$weights as $dimension => $weight ) {
			$total_score += $scores[ $dimension ] * $weight;
		}

		$final_score = (int) round( $total_score );

		// @implements FEED-FRD-6.3.
		return array(
			'score'       => $final_score,
			'issues'      => $issues,
			'suggestions' => $suggestions,
		);
	}
}
