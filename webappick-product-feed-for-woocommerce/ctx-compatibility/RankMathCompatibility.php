<?php
/**
 * Compatibility class for RankMath SEO plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://wordpress.org/plugins/seo-by-rank-math/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\CommonHelper;
use RankMath\Helper;

/**
 * Class RankMathCompatibility
 *
 * Handles RankMath SEO product attributes.
 *
 * @package CTXFeed\Compatibility
 */
class RankMathCompatibility {

	/**
	 * RankMathCompatibility Constructor.
	 */
	public function __construct() {
		// SEO plugin attributes for dropdown.
		add_filter( 'woo_feed_seo_plugin_attributes', array( $this, 'get_seo_attributes' ), 10, 1 );

		// RankMath active check.
		add_filter( 'woo_feed_is_rank_math_active', array( $this, 'is_rank_math_active' ), 10, 1 );

		// RankMath primary category filter.
		add_filter( 'woo_feed_filter_rank_math_primary_category', array( $this, 'get_primary_category_name' ), 10, 3 );

		// RankMath title hook.
		add_filter( 'woo_feed_filter_product_rank_math_title', array( $this, 'rank_math_title' ), 10, 3 );

		// RankMath description hook.
		add_filter( 'woo_feed_filter_product_rank_math_description', array( $this, 'rank_math_description' ), 10, 3 );

		// RankMath canonical URL hook.
		add_filter( 'woo_feed_filter_product_rank_math_canonical_url', array( $this, 'rank_math_canonical_url' ), 10, 3 );

		// RankMath GTIN hook.
		add_filter( 'rankmath_gtin_attribute_value', array( $this, 'rank_math_gtin' ), 10, 3 );
	}

	/**
	 * Check if RankMath plugin is active.
	 *
	 * @param bool $is_active Current active status.
	 *
	 * @return bool
	 */
	public function is_rank_math_active( $is_active ) {
		return class_exists( 'RankMath' ) || class_exists( 'RankMathPro' );
	}

	/**
	 * Convert RankMath primary category term ID to term name.
	 *
	 * @param mixed $value The term ID.
	 * @param \WC_Product $product The product object.
	 * @param mixed $config The feed config.
	 *
	 * @return mixed The term name or original value.
	 */
	public function get_primary_category_name( $value, $product, $config ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$term = get_term( $value );

		return ( $term instanceof \WP_Term ) ? $term->name : $value;
	}

	/**
	 * Get RankMath SEO attributes for dropdown.
	 *
	 * @param array $attributes Current SEO attributes.
	 *
	 * @return array
	 */
	public function get_seo_attributes( $attributes ) {
		$seoAttributes = [
			'optionGroup' => esc_html__( 'RANK MATH SEO', 'woo-feed' ),
			'options'     => [
				'rank_math_title'         => esc_html__( 'Title [RankMath SEO]', 'woo-feed' ),
				'rank_math_description'   => esc_html__( 'Description [RankMath SEO]', 'woo-feed' ),
				'rank_math_canonical_url' => esc_html__( 'Canonical URL [RankMath SEO]', 'woo-feed' ),
			],
		];

		// Add GTIN if RankMath Pro is available.
		if ( class_exists( 'RankMathPro' ) ) {
			$seoAttributes['options'] += [
				'rank_math_gtin' => esc_html__( 'GTIN [RankMath Pro SEO]', 'woo-feed' ),
			];
		}

		return $seoAttributes;
	}

	/**
	 * Get RankMath SEO title.
	 *
	 * @param string                     $title   Current title value.
	 * @param \WC_Product                $product Product object.
	 * @param \CTXFeed\V5\Utility\Config $config  Config object.
	 *
	 * @return string
	 */
	public function rank_math_title( $title, $product, $config ) {
		if ( ! empty( $title ) ) {
			return $title;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $title;
		}

		$rank_title = get_post_meta( $product->get_id(), 'rank_math_title', true );

		if ( empty( $rank_title ) ) {
			$title_format = Helper::get_settings( 'titles.pt_product_title' );
			$title_format = $title_format ? $title_format : '%title%';
			$sep          = Helper::get_settings( 'titles.title_separator' );

			$rank_title = str_replace( '%title%', $product->get_title(), $title_format );
			$rank_title = str_replace( '%sep%', $sep, $rank_title );
			$rank_title = str_replace( '%page%', '', $rank_title );
			$rank_title = str_replace( '%sitename%', get_bloginfo( 'name' ), $rank_title );
		}

		return $rank_title;
	}

	/**
	 * Get RankMath SEO description.
	 *
	 * @param string                     $description Current description value.
	 * @param \WC_Product                $product     Product object.
	 * @param \CTXFeed\V5\Utility\Config $config      Config object.
	 *
	 * @return string
	 */
	public function rank_math_description( $description, $product, $config ) {
		if ( ! empty( $description ) ) {
			return $description;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $description;
		}

		$rank_description = get_post_meta( $product->get_id(), 'rank_math_description', true );
		$desc_format      = Helper::get_settings( 'titles.pt_post_description' );

		if ( empty( $rank_description ) ) {
			if ( ! empty( $desc_format ) && strpos( (string) $desc_format, 'excerpt' ) !== false ) {
				$rank_description = str_replace( '%excerpt%', get_the_excerpt( $product->get_id() ), $desc_format );
			}

			// Get Variation Description.
			if ( empty( $rank_description ) && $product->is_type( 'variation' ) ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				if ( $parent_product ) {
					$rank_description = $parent_product->get_description();
				}
			}
		}

		if ( is_array( $rank_description ) ) {
			$rank_description = reset( $rank_description );
		}

		$rank_description = CommonHelper::remove_shortcodes( $rank_description );
		$strip_description = CommonHelper::strip_all_tags( wp_specialchars_decode( $rank_description ) );
		$rank_description = ! empty( strlen( $strip_description ) ) && 0 < strlen( $strip_description ) ? $strip_description : $rank_description;

		return $rank_description;
	}

	/**
	 * Get RankMath canonical URL.
	 *
	 * @param string                     $canonical_url Current canonical URL.
	 * @param \WC_Product                $product       Product object.
	 * @param \CTXFeed\V5\Utility\Config $config        Config object.
	 *
	 * @return string
	 */
	public function rank_math_canonical_url( $canonical_url, $product, $config ) {
		if ( ! empty( $canonical_url ) ) {
			return $canonical_url;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $canonical_url;
		}

		$post_canonical_url = get_post_meta( $product->get_id(), 'rank_math_canonical_url', true );

		if ( empty( $post_canonical_url ) ) {
			$post_canonical_url = get_the_permalink( $product->get_id() );
		}

		if ( is_array( $post_canonical_url ) ) {
			$post_canonical_url = reset( $post_canonical_url );
		}

		return $post_canonical_url;
	}

	/**
	 * Get RankMath GTIN.
	 *
	 * @param string                     $gtin    Current GTIN value.
	 * @param \WC_Product                $product Product object.
	 * @param \CTXFeed\V5\Utility\Config $config  Config object.
	 *
	 * @return string
	 */
	public function rank_math_gtin( $gtin, $product, $config ) {
		if ( ! empty( $gtin ) ) {
			return $gtin;
		}

		$product_id     = CommonHelper::parent_product_id( $product );
		$rankmath_gtin  = get_post_meta( $product_id, '_rank_math_gtin_code', true );

		return ! empty( $rankmath_gtin ) ? $rankmath_gtin : $gtin;
	}

}
