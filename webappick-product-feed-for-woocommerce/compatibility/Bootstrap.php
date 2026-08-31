<?php
/**
 * Bootstrap — activates the core-extraction compatibility providers.
 *
 * These providers hold third-party-plugin logic relocated OUT of V8 core and
 * answer neutral hook seams the core resolvers now fire (e.g.
 * `ctxfeed_resolve_seo_attribute`). They must be active for feeds to keep
 * emitting SEO / multi-vendor / product-type / currency data — the logic no
 * longer exists in the resolvers themselves.
 *
 * These seams are NEW `ctxfeed_*` filters, distinct from the legacy
 * `woo_feed_filter_product_*` bridge hooks owned by the live
 * `ctx-compatibility/` submodule, so activating them cannot collide with it.
 *
 * The rewritten submodule shims under `Shims/` are a SEPARATE, dormant concern
 * activated by `CompatibilityFactory::init()` — NOT wired here (see
 * ARCHITECTURE.md).
 *
 * @package CTXFeed\Compat
 * @since   8.0.0
 */

namespace CTXFeed\Compat;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider bootstrap.
 *
 * @since 8.0.0
 */
class Bootstrap {

	/**
	 * Guard against double registration.
	 *
	 * @since 8.0.0
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Instantiate and register every core-extraction provider.
	 *
	 * Idempotent — safe to call more than once.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		foreach ( self::providers() as $provider ) {
			$provider->register();
		}

		/**
		 * Fires after the core-extraction providers register their hooks.
		 *
		 * @since 8.0.0
		 */
		do_action( 'ctxfeed_compat_providers_registered' );
	}

	/**
	 * The ordered list of provider instances.
	 *
	 * @since 8.0.0
	 * @return array Provider instances exposing a register() method.
	 */
	private static function providers(): array {
		return array(
			new Providers\SeoCompatibilityProvider(),
			new Providers\MultiVendorCompatibilityProvider(),
			new Providers\CurrencyCompatibilityProvider(),
			new Providers\ProductTypeCompatibilityProvider(),
		);
	}
}
