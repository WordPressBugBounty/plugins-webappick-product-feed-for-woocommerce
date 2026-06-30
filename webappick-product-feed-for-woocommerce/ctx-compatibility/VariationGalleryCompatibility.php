<?php
/**
 * Compatibility class for Variation Gallery plugins.
 *
 * @package CTXFeed\Compatibility
 */

namespace CTXFeed\Compatibility;

/**
 * Class VariationGalleryCompatibility
 *
 * Handles variation gallery images from multiple plugins:
 * - Additional Variation Images Gallery for WooCommerce (Woo_Variation_Gallery)
 * - Variation Images Gallery for WooCommerce (WooProductVariationGallery)
 * - WooCommerce Additional Variation Images (WC_Additional_Variation_Images)
 * - WOODMART Theme
 *
 * @package CTXFeed\Compatibility
 */
class VariationGalleryCompatibility {

	/**
	 * VariationGalleryCompatibility Constructor.
	 */
	public function __construct() {
		// Hook into variation gallery attachment IDs filter.
		add_filter( 'woo_feed_filter_variation_gallery_attachment_ids', array( $this, 'get_variation_gallery_ids' ), 10, 2 );
	}

	/**
	 * Get variation gallery attachment IDs from compatible plugins.
	 *
	 * @param array       $attachment_ids Current attachment IDs.
	 * @param \WC_Product $product        Product object.
	 *
	 * @return array
	 */
	public function get_variation_gallery_ids( $attachment_ids, $product ) {


		// Only process variation products.
		if ( ! $product->is_type( 'variation' ) ) {
			return $attachment_ids;
		}

		// Return existing IDs if already found.
		if ( ! empty( $attachment_ids ) ) {
			return $attachment_ids;
		}

		// Check Additional Variation Images Gallery for WooCommerce.
		if ( class_exists( 'Woo_Variation_Gallery' ) ) {
			$gallery_ids = get_post_meta( $product->get_id(), 'woo_variation_gallery_images', true );
			if ( ! empty( $gallery_ids ) && is_array( $gallery_ids ) ) {
				return $gallery_ids;
			}
		}

		// Check Variation Images Gallery for WooCommerce (woo-product-variation-gallery plugin).
		// The class is namespaced as Rtwpvg\WooProductVariationGallery, so we check for function or constant.
		if ( function_exists( 'rtwpvg' ) || defined( 'RTWPVG_VERSION' ) || class_exists( 'Rtwpvg\\WooProductVariationGallery' ) ) {
			$gallery_ids = get_post_meta( $product->get_id(), 'rtwpvg_images', true );
			if ( ! empty( $gallery_ids ) && is_array( $gallery_ids ) ) {
				return $gallery_ids;
			}
		}

		// Check WooCommerce Additional Variation Images.
		if ( class_exists( 'WC_Additional_Variation_Images' ) ) {
			$gallery_string = get_post_meta( $product->get_id(), '_wc_additional_variation_images', true );
			if ( ! empty( $gallery_string ) ) {
				$gallery_ids = explode( ',', $gallery_string );
				if ( ! empty( $gallery_ids ) ) {
					return $gallery_ids;
				}
			}
		}

		// Check WOODMART Theme.
		if ( class_exists( 'WOODMART_Theme' ) ) {
			$var_id    = $product->get_id();
			$parent_id = $product->get_parent_id();

			$variation_obj = get_post_meta( $parent_id, 'woodmart_variation_gallery_data', true );
			if ( isset( $variation_obj, $variation_obj[ $var_id ] ) ) {
				$gallery_ids = explode( ',', $variation_obj[ $var_id ] );
				if ( ! empty( $gallery_ids ) ) {
					return $gallery_ids;
				}
			}

			$wd_data = get_post_meta( $var_id, 'wd_additional_variation_images_data', true );
			if ( ! empty( $wd_data ) ) {
				$gallery_ids = explode( ',', $wd_data );
				if ( ! empty( $gallery_ids ) ) {
					return $gallery_ids;
				}
			}
		}

		// Check Woodmart Child Theme.
		$theme = wp_get_theme();
		if ( 'Woodmart Child' === $theme->name ) {
			$var_id    = $product->get_id();
			$parent_id = $product->get_parent_id();

			$variation_obj = get_post_meta( $parent_id, 'woodmart_variation_gallery_data', true );
			if ( isset( $variation_obj, $variation_obj[ $var_id ] ) ) {
				$gallery_ids = explode( ',', $variation_obj[ $var_id ] );
				if ( ! empty( $gallery_ids ) ) {
					return $gallery_ids;
				}
			}

			$wd_data = get_post_meta( $var_id, 'wd_additional_variation_images_data', true );
			if ( ! empty( $wd_data ) ) {
				$gallery_ids = explode( ',', $wd_data );
				if ( ! empty( $gallery_ids ) ) {
					return $gallery_ids;
				}
			}
		}

		return $attachment_ids;
	}

}
