<?php
/**
 * ProductTypeCompatibilityProvider — bundle + WooCommerce Germanized integration.
 *
 * Holds the product-type plugin logic relocated OUT of V8 core
 * (`V8/Product/AttributeResolver.php`):
 *   • Bundle-plugin type detection (WC Product Bundles, legacy bundled, YITH,
 *     WooSB, Easy Product Bundles) for the `is_bundle` attribute.
 *   • WooCommerce Germanized raw meta reads (`_ts_gtin`, `_ts_mpn`,
 *     `_unit_product`, `_unit_base`) for the `wc_germanized_*` attributes.
 *   • The Germanized `unit_price_measure` / `unit_price_base_measure` fallback
 *     (unit + measure composition) when the CTX Feed panel fields are empty.
 *
 * Composite / subscription / grouped product handling already flowed through
 * the legacy `woo_feed_filter_*` bridge (externalised per CTX-924) or carries
 * no plugin-specific branch in core, so it is NOT re-homed here.
 *
 * Hooks answered (registered from Bootstrap::init()):
 *   • `ctxfeed_resolve_is_bundle`                     — 'yes' / 'no'.
 *   • `ctxfeed_resolve_germanized_field`             — raw Germanized meta value.
 *   • `ctxfeed_resolve_unit_price_measure_fallback`  — "measure unit" fallback.
 *
 * @package CTXFeed\Compat\Providers
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Providers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-type compatibility provider.
 *
 * @since 8.0.0
 */
class ProductTypeCompatibilityProvider {

	/**
	 * Product-type slugs recognised as bundles across bundle plugins.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $bundle_types = array( 'bundle', 'bundled', 'yith_bundle', 'woosb', 'easy_product_bundle' );

	/**
	 * Register the hook seams this provider answers.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ctxfeed_resolve_is_bundle', array( $this, 'is_bundle' ), 10, 3 );
		add_filter( 'ctxfeed_resolve_germanized_field', array( $this, 'germanized_field' ), 10, 4 );
		add_filter( 'ctxfeed_resolve_unit_price_measure_fallback', array( $this, 'unit_price_measure_fallback' ), 10, 4 );
	}

	/**
	 * Resolve whether a product is a bundle across bundle plugins.
	 *
	 * Answers `ctxfeed_resolve_is_bundle`. Returns the incoming default
	 * (typically 'no') for non-bundle types.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $value   Current value ('no' default).
	 * @param \WC_Product $product WooCommerce product.
	 * @param mixed       $config  Feed configuration (unused).
	 * @return string 'yes' or 'no'.
	 */
	public function is_bundle( $value, $product, $config = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! $product instanceof \WC_Product ) {
			return $value;
		}

		return in_array( $product->get_type(), self::$bundle_types, true ) ? 'yes' : $value;
	}

	/**
	 * Read a raw WooCommerce Germanized meta value.
	 *
	 * Answers `ctxfeed_resolve_germanized_field`. Defers when a value has
	 * already been supplied.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $value    Current value ('' default).
	 * @param \WC_Product $product  WooCommerce product.
	 * @param string      $meta_key Germanized meta key (_ts_gtin, _unit_product, …).
	 * @param mixed       $config   Feed configuration (unused).
	 * @return string
	 */
	public function germanized_field( $value, $product, $meta_key, $config = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( '' !== $value ) {
			return $value;
		}
		if ( ! $product instanceof \WC_Product ) {
			return $value;
		}

		return (string) get_post_meta( $product->get_id(), (string) $meta_key, true );
	}

	/**
	 * WooCommerce Germanized unit-price fallback (unit + measure).
	 *
	 * Answers `ctxfeed_resolve_unit_price_measure_fallback`. Only applies when
	 * the value is still empty and Germanized is active.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $value           Current value ('' default).
	 * @param \WC_Product $product         WooCommerce product.
	 * @param string      $germanized_meta Germanized measure meta key (_unit_product / _unit_base).
	 * @param mixed       $config          Feed configuration (unused).
	 * @return string "measure unit" or the incoming value.
	 */
	public function unit_price_measure_fallback( $value, $product, $germanized_meta, $config = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( '' !== $value ) {
			return $value;
		}
		if ( ! $product instanceof \WC_Product || ! class_exists( 'WooCommerce_Germanized' ) ) {
			return $value;
		}

		$unit    = (string) get_post_meta( $product->get_id(), '_unit', true );
		$measure = (string) get_post_meta( $product->get_id(), (string) $germanized_meta, true );

		return '' !== $measure ? trim( $measure . ' ' . $unit ) : $value;
	}
}
