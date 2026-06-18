<?php
/**
 * Compatibility class for Yoast SEO plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://wordpress.org/plugins/wordpress-seo/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\CommonHelper;

/**
 * Class WPSEO_FrontendCompatibility
 *
 * Handles Yoast SEO product attributes.
 *
 * @package CTXFeed\Compatibility
 */
class WPSEO_FrontendCompatibility {

	/**
	 * WPSEO_FrontendCompatibility Constructor.
	 */
	public function __construct() {
		// SEO plugin attributes for dropdown.
		add_filter( 'woo_feed_seo_plugin_attributes', array( $this, 'get_seo_attributes' ), 10, 1 );

		// Primary category hook.
		add_filter( 'woo_feed_filter_product_yoast_primary_category', array( $this, 'primary_category' ), 10, 3 );

		// SEO title hook.
		add_filter( 'woo_feed_filter_product_yoast_wpseo_title', array( $this, 'wpseo_title' ), 10, 3 );

		// SEO meta description hook.
		add_filter( 'woo_feed_filter_product_yoast_wpseo_metadesc', array( $this, 'wpseo_metadesc' ), 10, 3 );

		// Canonical URL hook.
		add_filter( 'woo_feed_filter_product_yoast_canonical_url', array( $this, 'canonical_url' ), 10, 3 );

		// GTIN hooks.
		add_filter( 'yoast_gtin8_attribute_value', array( $this, 'gtin8' ), 10, 2 );
		add_filter( 'yoast_gtin12_attribute_value', array( $this, 'gtin12' ), 10, 2 );
		add_filter( 'yoast_gtin13_attribute_value', array( $this, 'gtin13' ), 10, 2 );
		add_filter( 'yoast_gtin14_attribute_value', array( $this, 'gtin14' ), 10, 2 );

		// ISBN hook.
		add_filter( 'yoast_isbn_attribute_value', array( $this, 'isbn' ), 10, 2 );

		// MPN hook.
		add_filter( 'yoast_mpn_attribute_value', array( $this, 'mpn' ), 10, 2 );
	}

	/**
	 * Get Yoast SEO attributes for dropdown.
	 *
	 * @param array $attributes Current SEO attributes.
	 *
	 * @return array
	 */
	public function get_seo_attributes( $attributes ) {
		$seoAttributes = [
			'optionGroup' => esc_html__( 'Yoast SEO', 'woo-feed' ),
			'options'     => [
				'yoast_wpseo_title'      => esc_html__( 'Title [Yoast SEO]', 'woo-feed' ),
				'yoast_wpseo_metadesc'   => esc_html__( 'Description [Yoast SEO]', 'woo-feed' ),
				'yoast_canonical_url'    => esc_html__( 'Canonical URL [Yoast SEO]', 'woo-feed' ),
				'yoast_primary_category' => esc_html__( 'Primary Category [Yoast SEO]', 'woo-feed' ),
			],
		];

		// Add WooCommerce SEO identifier attributes if available.
		if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
			$seoAttributes['options'] += [
				'yoast_gtin8'  => esc_html__( 'GTIN8 [Yoast SEO]', 'woo-feed' ),
				'yoast_gtin12' => esc_html__( 'GTIN12 / UPC [Yoast SEO]', 'woo-feed' ),
				'yoast_gtin13' => esc_html__( 'GTIN13 / EAN [Yoast SEO]', 'woo-feed' ),
				'yoast_gtin14' => esc_html__( 'GTIN14 / ITF-14 [Yoast SEO]', 'woo-feed' ),
				'yoast_isbn'   => esc_html__( 'ISBN [Yoast SEO]', 'woo-feed' ),
				'yoast_mpn'    => esc_html__( 'MPN [Yoast SEO]', 'woo-feed' ),
			];
		}

		return $seoAttributes;
	}

	/**
	 * Get Yoast primary category.
	 *
	 * @param string                     $primary_category Current value.
	 * @param \WC_Product                $product          Product object.
	 * @param \CTXFeed\V5\Utility\Config $config           Config object.
	 *
	 * @return string
	 */
	public function primary_category( $primary_category, $product, $config ) {
		if ( ! empty( $primary_category ) ) {
			return $primary_category;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) ) {
			return $primary_category;
		}

		$product_id      = CommonHelper::parent_product_id( $product );
		$primary_term_id = yoast_get_primary_term_id( 'product_cat', $product_id );
		$term            = get_term( $primary_term_id );

		if ( ! is_wp_error( $term ) && ! empty( $term ) ) {
			return $term->name;
		}

		return $primary_category;
	}

	/**
	 * Get Yoast SEO title.
	 *
	 * @param string                     $title   Current title value.
	 * @param \WC_Product                $product Product object.
	 * @param \CTXFeed\V5\Utility\Config $config  Config object.
	 *
	 * @return string
	 */
	public function wpseo_title( $title, $product, $config ) {
		if ( ! class_exists( 'WPSEO_Frontend' ) ) {
			return $title;
		}

		$product_id = $product->get_id();

		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		$yoast_title = get_post_meta( $product_id, '_yoast_wpseo_title', true );

		if ( ! empty( $yoast_title ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
			$replace_vars = new \WPSEO_Replace_Vars();
			$yoast_title  = $replace_vars->replace( $yoast_title, get_post( $product_id ) );
		}

		return ! empty( $yoast_title ) ? $yoast_title : $title;
	}

	/**
	 * Get Yoast SEO meta description.
	 *
	 * @param string                     $description Current description value.
	 * @param \WC_Product                $product     Product object.
	 * @param \CTXFeed\V5\Utility\Config $config      Config object.
	 *
	 * @return string
	 */
	public function wpseo_metadesc( $description, $product, $config ) {
		if ( ! class_exists( 'WPSEO_Frontend' ) ) {
			return $description;
		}

		$product_id = $product->get_id();

		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		$meta_description = get_post_meta( $product_id, '_yoast_wpseo_metadesc', true );

		if ( ! empty( $meta_description ) && class_exists( 'WPSEO_Replace_Vars' ) ) {
			$replace_vars     = new \WPSEO_Replace_Vars();
			$meta_description = $replace_vars->replace( $meta_description, get_post( $product_id ) );
		}

		return ! empty( $meta_description ) ? $meta_description : $description;
	}

	/**
	 * Get Yoast canonical URL.
	 *
	 * @param string                     $canonical_url Current canonical URL.
	 * @param \WC_Product                $product       Product object.
	 * @param \CTXFeed\V5\Utility\Config $config        Config object.
	 *
	 * @return string
	 */
	public function canonical_url( $canonical_url, $product, $config ) {
		if ( ! empty( $canonical_url ) ) {
			return $canonical_url;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) ) {
			return $canonical_url;
		}

		$product_id = $product->get_id();

		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		return get_post_meta( $product_id, '_yoast_wpseo_canonical', true );
	}

	/**
	 * Get Yoast GTIN8.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function gtin8( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'gtin8', $product );
	}

	/**
	 * Get Yoast GTIN12.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function gtin12( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'gtin12', $product );
	}

	/**
	 * Get Yoast GTIN13.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function gtin13( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'gtin13', $product );
	}

	/**
	 * Get Yoast GTIN14.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function gtin14( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'gtin14', $product );
	}

	/**
	 * Get Yoast ISBN.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function isbn( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'isbn', $product );
	}

	/**
	 * Get Yoast MPN.
	 *
	 * @param string      $value   Current value.
	 * @param \WC_Product $product Product object.
	 *
	 * @return string
	 */
	public function mpn( $value, $product ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		if ( ! class_exists( 'WPSEO_Frontend' ) || ! function_exists( 'woo_feed_get_yoast_identifiers_value' ) ) {
			return $value;
		}

		return woo_feed_get_yoast_identifiers_value( 'mpn', $product );
	}

}
