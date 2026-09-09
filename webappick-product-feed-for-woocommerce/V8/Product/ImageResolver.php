<?php
/**
 * ImageResolver — Resolves product images including featured, gallery, and indexed.
 *
 * Supports configurable image sizes and returns URLs for feed output.
 * Attachment posts + meta are bulk-primed by CacheWarmer (products,
 * parents, and their thumbnail/gallery attachment IDs).
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
	 * Memoised formatted attachment URLs, keyed "attachment_id|size".
	 *
	 * Variations without their own gallery re-resolve the SAME parent
	 * attachments; the URL for an (attachment, size) pair is deterministic
	 * per request. FIFO-capped.
	 *
	 * @since 8.0.12
	 * @var array<string,string>
	 */
	private $url_memo = array();

	/**
	 * Memoised gallery URL lists, keyed "product_id|size".
	 *
	 * A feed mapping image_1..image_10 plus additional_image_link rebuilds
	 * the same gallery list up to 11 times per product. Attributes of one
	 * product resolve contiguously, so a tiny FIFO suffices.
	 *
	 * @since 8.0.12
	 * @var array<string,array>
	 */
	private $gallery_memo = array();

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
			$parent = ProductMemo::get( (int) $product->get_parent_id() );
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
			$parent = ProductMemo::get( (int) $product->get_parent_id() );
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
		$memo_key = $attachment_id . '|' . $size;
		if ( isset( $this->url_memo[ $memo_key ] ) ) {
			return $this->url_memo[ $memo_key ];
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $url ) {
			return $this->remember_url( $memo_key, '' );
		}

		$url     = (string) $url;
		$trimmed = trim( $url );
		$prefix4 = substr( $trimmed, 0, 4 );
		$prefix3 = substr( $trimmed, 0, 3 );
		if ( 'http' !== $prefix4 && 'ftp' !== $prefix3 && 'sftp' !== $prefix4 ) {
			$url = get_site_url() . $url;
		}

		return $this->remember_url( $memo_key, $this->encode_url( rtrim( $url, '/' ) ) );
	}

	/**
	 * Store a formatted URL in the FIFO memo and return it.
	 *
	 * @since 8.0.12
	 *
	 * @param string $key Memo key ("attachment_id|size").
	 * @param string $url Formatted URL ('' for unresolvable attachments).
	 * @return string The URL, unchanged.
	 */
	private function remember_url( string $key, string $url ): string {
		if ( count( $this->url_memo ) >= 500 ) {
			unset( $this->url_memo[ array_key_first( $this->url_memo ) ] );
		}
		$this->url_memo[ $key ] = $url;
		return $url;
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
	 * Get all GALLERY images as comma-separated URLs — featured excluded.
	 *
	 * V5 parity (#68878): V5's `images()` read
	 * `ProductHelper::get_product_gallery()`, which returns gallery
	 * attachments only. 8.0.0–8.0.16 prepended the featured image here,
	 * which (a) duplicated the main image into `g:additional_image_link`
	 * (Google's spec wants additional images only) and (b) made the value
	 * non-empty for every variation with a main image — so the
	 * "parent if empty" opt-in (code 20 / `[parent_if_empty]`) could never
	 * fire on an `images`-sourced row. The featured image remains available
	 * through the `image` / `feature_image` attributes.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-8.1
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $size    Image size.
	 *
	 * @return string Comma-separated gallery URLs or empty string.
	 */
	private function get_all_images( \WC_Product $product, string $size ): string {
		return implode( ', ', $this->get_gallery_urls( $product, $size ) );
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
		$memo_key = $product->get_id() . '|' . $size;
		if ( isset( $this->gallery_memo[ $memo_key ] ) ) {
			return $this->gallery_memo[ $memo_key ];
		}

		$gallery_ids = $product->get_gallery_image_ids();

		// Variations: WooCommerce's native variation gallery resolves through
		// get_gallery_image_ids() above (WC 11+); variation-gallery PLUGINS
		// supply attachment IDs via this filter (ctx-compatibility
		// VariationGalleryCompatibility). A variation with neither ships NO
		// gallery images — owner decision 2026-09-05 (#68988): the feed
		// carries only the variation's OWN images, and a store that wants the
		// parent's gallery instead opts in per attribute with the
		// "parent if empty" output command (code 20). The automatic
		// parent-gallery substitution this replaced (CTX-933, V5 parity from
		// the era before WC had native variation galleries) made that choice
		// impossible to opt out of. The image attributes are likewise
		// excluded from AttributeResolver's generic variation fallback so
		// the parent gallery cannot come back through that path either.
		if ( empty( $gallery_ids ) && $product->is_type( 'variation' ) ) {
			$gallery_ids = (array) apply_filters( 'woo_feed_filter_variation_gallery_attachment_ids', array(), $product );
		}

		$urls = array();

		foreach ( $gallery_ids as $id ) {
			$url = $this->format_attachment_url( (int) $id, $size );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		if ( count( $this->gallery_memo ) >= 50 ) {
			unset( $this->gallery_memo[ array_key_first( $this->gallery_memo ) ] );
		}
		$this->gallery_memo[ $memo_key ] = $urls;

		return $urls;
	}
}
