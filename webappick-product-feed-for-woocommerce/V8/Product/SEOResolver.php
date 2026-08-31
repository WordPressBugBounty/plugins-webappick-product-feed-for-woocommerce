<?php
/**
 * SEOResolver — thin seam delegating SEO attribute resolution to compat.
 *
 * The Yoast / Rank Math / AIOSEO plugin logic that used to live here has been
 * relocated OUT of core into
 * `compatibility/Providers/SeoCompatibilityProvider.php`. This resolver now
 * just fires the `ctxfeed_resolve_seo_attribute` seam and returns whatever a
 * compat provider supplies — no SEO-plugin-specific branch remains in core.
 *
 * The provider is registered by `CTXFeed\Compat\Bootstrap::init()` (wired from
 * woo-feed.php). When no provider answers, the seam returns its `null` default
 * and this resolver emits an empty string.
 *
 * Attribute-level V5 filters (`woo_feed_filter_product_yoast_wpseo_title` etc.)
 * still fire from ProductRepository's generic wrapping, unchanged.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-9.1, PROD-FRD-9.2, PROD-FRD-10.14
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO resolver seam.
 *
 * @since 8.0.0
 */
class SEOResolver {

	/**
	 * Resolve an SEO attribute for a product via the compat seam.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-9.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    SEO attribute name (V5 or V8 shape).
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string {
		/**
		 * Resolve an SEO plugin attribute value.
		 *
		 * Answered by SeoCompatibilityProvider in the compatibility layer.
		 * `null` (the default) means unresolved → core emits an empty string.
		 *
		 * @since 8.0.0
		 *
		 * @param string|null $value   Resolved value, or null when unresolved.
		 * @param \WC_Product $product WooCommerce product.
		 * @param string      $attr    SEO attribute name.
		 * @param Config      $config  Feed configuration.
		 */
		$value = apply_filters( 'ctxfeed_resolve_seo_attribute', null, $product, $attr, $config );

		return null === $value ? '' : (string) $value;
	}
}
