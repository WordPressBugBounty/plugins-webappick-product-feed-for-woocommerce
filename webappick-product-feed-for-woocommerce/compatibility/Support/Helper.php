<?php
/**
 * Helper — misc helpers for the compat layer.
 *
 * Native (V5-free) replacement for the `CTXFeed\V5\Common\Helper` surface the
 * rewritten shims depend on (currently just `is_pro()` — the multi-vendor role
 * detection was relocated into MultiVendorCompatibilityProvider).
 *
 * @package CTXFeed\Compat\Support
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Support;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compat helper.
 *
 * @since 8.0.0
 */
class Helper {

	/**
	 * Whether the CTX Feed Pro plugin is active.
	 *
	 * Prefers the V8 FeatureGate; falls back to the pro-plugin constants. The
	 * result is filterable via `woo_feed_is_pro` for parity with V5.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	public static function is_pro() {
		if ( class_exists( '\\CTXFeed\\V8\\Core\\FeatureGate' ) && method_exists( '\\CTXFeed\\V8\\Core\\FeatureGate', 'is_pro' ) ) {
			return (bool) \CTXFeed\V8\Core\FeatureGate::is_pro();
		}

		$is_pro = defined( 'WOO_FEED_PRO_VERSION' )
			|| ( defined( 'WOO_FEED_PLUGIN_FILE' ) && 'webappick-product-feed-for-woocommerce-pro.php' === WOO_FEED_PLUGIN_FILE );

		return (bool) apply_filters( 'woo_feed_is_pro', $is_pro );
	}
}
