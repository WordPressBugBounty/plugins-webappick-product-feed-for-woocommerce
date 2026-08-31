<?php
/**
 * ImageResolver — Resolves product images including featured, gallery, and indexed.
 *
 * Supports configurable image sizes and returns URLs for feed output.
 * Image metadata is already cache-primed by CacheWarmer.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-8.1, PROD-FRD-8.2, PROD-FRD-8.3
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image resolver.
 *
 * @since 8.0.0
 */
class ImageResolver {

	/**
	 * Resolve an image attribute for a product.
	 *
	 * Routes to featured image, all-images list, or indexed gallery image
	 * based on the attribute name.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-8.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Image attribute name.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Image URL(s) or empty string.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string {
		// @implements PROD-FRD-8.2
		$size = $config->get( 'image_size', 'full' );

		switch ( $attr ) {
			// V5 parity: `image` and `feature_image` are DIFFERENT
			// attributes for variations. V5 ProductInfo.php:601-618 vs
			// 638-648.
			// image         → variation's own thumbnail if present,
			// else parent's thumbnail (with fallback).
			// feature_image → ALWAYS parent's thumbnail for variations
			// (no fallback to own).
			// For non-variations they resolve identically.
			case 'image':
				return $this->get_image_with_variation_fallback( $product, $size );

			case 'feature_image':
				return $this->get_feature_image_parent_first( $product, $size );

			case 'images':
				return $this->get_all_images( $product, $size );

			default:
				// Handle image_1 through image_10.
				if ( 1 === preg_match( '/^image_(\d+)$/', $attr, $matches ) ) {
					$index        = (int) $matches[1] - 1; // Convert to 0-based.
					$gallery_urls = $this->get_gallery_urls( $product, $size );

					return isset( $gallery_urls[ $index ] ) ? $gallery_urls[ $index ] : '';
				}

				return '';
		}
	}

	/**
	 * V5 `image` semantics: variation uses its own thumbnail if it has
	 * one; otherwise falls back to the parent product's thumbnail.
	 * Non-variations return their own thumbnail. Every URL is normalised
	 * via V5's `woo_feed_get_formatted_url` (trailing-slash strip +
	 * absolute-URL promotion for relatively-configured stores). V5
	 * ProductInfo.php:601-618.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    WP image size.
	 * @return string
	 */
	private function get_image_with_variation_fallback( \WC_Product $product, string $size ): string {
		if ( $product->is_type( 'variation' ) ) {
			$own_id = $product->get_image_id();
			if ( ! empty( $own_id ) ) {
				return $this->format_attachment_url( $own_id, $size );
			}
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$parent_id = $parent->get_image_id();
				if ( ! empty( $parent_id ) ) {
					return $this->format_attachment_url( $parent_id, $size );
				}
			}
			return '';
		}

		$image_id = $product->get_image_id();
		if ( empty( $image_id ) ) {
			return '';
		}
		return $this->format_attachment_url( $image_id, $size );
	}

	/**
	 * V5 `feature_image` semantics: for variations, ALWAYS use the
	 * parent's thumbnail (no fallback to own). Rationale: a customer
	 * mapping `feature_image` to Google's `<g:image_link>` wants the
	 * canonical group image, not the variation-specific one. V5
	 * ProductInfo.php:638-648.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    WP image size.
	 * @return string
	 */
	private function get_feature_image_parent_first( \WC_Product $product, string $size ): string {
		$image_id = $product->get_image_id();

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$parent_image = $parent->get_image_id();
				if ( ! empty( $parent_image ) ) {
					$image_id = $parent_image;
				}
			}
		}

		if ( empty( $image_id ) ) {
			return '';
		}
		return $this->format_attachment_url( $image_id, $size );
	}

	/**
	 * Fetch attachment URL and normalise it the way V5's helper does:
	 *   1. Strip trailing slashes.
	 *   2. Prepend site URL to relative paths (defensive — WC always
	 *      returns absolute URLs, but a customer's CDN plugin may
	 *      transform to relative in a filter).
	 *
	 * V5 ref: includes/helper.php woo_feed_get_formatted_url() at 2094.
	 *
	 * @since 8.0.0
	 *
	 * @param int    $attachment_id WP attachment ID.
	 * @param string $size          WP image size.
	 * @return string Formatted URL, or empty string.
	 */
	private function format_attachment_url( int $attachment_id, string $size ): string {
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $url ) {
			return '';
		}

		$url     = (string) $url;
		$trimmed = trim( $url );
		$prefix4 = substr( $trimmed, 0, 4 );
		$prefix3 = substr( $trimmed, 0, 3 );
		if ( 'http' !== $prefix4 && 'ftp' !== $prefix3 && 'sftp' !== $prefix4 ) {
			$url = get_site_url() . $url;
		}

		return $this->encode_url( rtrim( $url, '/' ) );
	}

	/**
	 * Percent-encode an image URL's PATH so non-ASCII / space characters are
	 * RFC-3986-safe.
	 *
	 * Facebook (and some other channels) reject raw Cyrillic / spaced bytes
	 * in image URLs. Only the path is touched — the scheme/host/port and the
	 * query string (which may carry a CDN signature that must not be
	 * re-encoded) pass through verbatim. Idempotent: each path segment is
	 * decoded before re-encoding, so an already-encoded URL is unchanged and
	 * an encoded slash (`%2F`) inside a segment survives.
	 *
	 * @since 8.0.0
	 *
	 * @param string $url Absolute URL.
	 * @return string URL with an encoded path.
	 */
	private function encode_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$path = implode(
			'/',
			array_map(
				static function ( $segment ) {
					return rawurlencode( rawurldecode( $segment ) );
				},
				explode( '/', $parts['path'] )
			)
		);

		$out = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$out .= $parts['scheme'] . '://';
		}
		if ( ! empty( $parts['user'] ) ) {
			$out .= $parts['user'];
			if ( isset( $parts['pass'] ) ) {
				$out .= ':' . $parts['pass'];
			}
			$out .= '@';
		}
		$out .= $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		$out .= $path;
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$out .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$out .= '#' . $parts['fragment'];
		}

		return $out;
	}

	/**
	 * Get featured image URL for a product.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-8.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    Image size.
	 *
	 * @return string Featured image URL or empty string.
	 */
	private function get_featured_image( \WC_Product $product, string $size ): string {
		$image_id = $product->get_image_id();

		if ( empty( $image_id ) ) {
			return '';
		}

		// Route through the URL normaliser so `images` (comma-joined
		// output) and single-image getters emit identically-shaped URLs.
		return $this->format_attachment_url( (int) $image_id, $size );
	}

	/**
	 * Get all images (featured + gallery) as comma-separated URLs.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-8.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    Image size.
	 *
	 * @return string Comma-separated image URLs or empty string.
	 */
	private function get_all_images( \WC_Product $product, string $size ): string {
		$urls = array();

		// Featured image first.
		$featured = $this->get_featured_image( $product, $size );

		if ( ! empty( $featured ) ) {
			$urls[] = $featured;
		}

		// Gallery images.
		$gallery = $this->get_gallery_urls( $product, $size );
		$urls    = array_merge( $urls, $gallery );

		return implode( ', ', $urls );
	}

	/**
	 * Get gallery image URLs for a product.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-8.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    Image size.
	 *
	 * @return string[] Array of gallery image URLs.
	 */
	private function get_gallery_urls( \WC_Product $product, string $size ): array {
		$gallery_ids = $product->get_gallery_image_ids();

		// Variations have no native WC gallery. V5 6.6.x: variation
		// gallery plugins supply attachment IDs via this filter
		// (ctx-compatibility VariationGalleryCompatibility); without
		// one, fall back to the parent product's gallery so images_N
		// aren't silently empty for variations (CTX-933).
		if ( empty( $gallery_ids ) && $product->is_type( 'variation' ) ) {
			$gallery_ids = (array) apply_filters( 'woo_feed_filter_variation_gallery_attachment_ids', array(), $product );

			if ( empty( $gallery_ids ) ) {
				$parent      = wc_get_product( $product->get_parent_id() );
				$gallery_ids = $parent instanceof \WC_Product ? $parent->get_gallery_image_ids() : array();
			}
		}

		if ( empty( $gallery_ids ) ) {
			return array();
		}

		$urls = array();

		foreach ( $gallery_ids as $id ) {
			$url = $this->format_attachment_url( (int) $id, $size );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}
}
