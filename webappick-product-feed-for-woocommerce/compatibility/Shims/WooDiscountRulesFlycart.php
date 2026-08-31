<?php
/**
 * Compatibility class for Discount Rules for WooCommerce (Flycart).
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/woo-discount-rules/
 */

namespace CTXFeed\Compat\Shims;

use Wdr\App\Controllers\Configuration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooDiscountRulesFlycart
 *
 * @package CTXFeed\Compat\Shims
 */
class WooDiscountRulesFlycart {


	/**
	 * Resolve the Flycart discounted price, honouring WPML multi-currency.
	 *
	 * @param string|float $price      Current price value.
	 * @param \WC_Product  $product    Product object.
	 * @param mixed        $config     Feed configuration object.
	 * @param string       $price_type Which price attribute is being resolved.
	 *
	 * @return string|float
	 */
	public function woo_discount_rules_flycart( $price, $product, $config, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.

		$base_price = $price;

		$wpml_active_currency_status = ( is_plugin_active( 'woocommerce-multilingual/wpml-woocommerce.php' ) && $config->get_feed_currency() !== get_woocommerce_currency() );
		if ( $wpml_active_currency_status ) {
			// Wpml custom price start.
			// Resolve against the product's own ID. WPML source-language post
			// resolution is a Pro-only concern (handled by the
			// SitePressCompatibility shim there); the free layer reads the
			// product's own currency meta.
			$original_id = $product->get_id();

			$wpml_regular_price = get_post_meta( $original_id, '_regular_price_' . $config->get_feed_currency(), false );
			$wpml_sale_price    = get_post_meta( $original_id, '_sale_price_' . $config->get_feed_currency(), false );
			$wpml_data          = get_option( '_wcml_settings' );
			$exchange_rate      = $wpml_data['currency_options'][ $config->get_feed_currency() ]['rate'];

			if ( count( $wpml_regular_price ) >= 1 ) {
				$wpml_regular_price = floatval( $wpml_regular_price[0] ) / floatval( $exchange_rate );
				$wpml_sale_price    = floatval( $wpml_sale_price[0] ) / floatval( $exchange_rate );
			}
			// Wpml custom price end.
			if ( 0 !== $exchange_rate ) {
				$base_price    = floatval( $price ) / floatval( $exchange_rate );
				$exchange_rate = $base_price;
			}
		} else {
			$exchange_rate = $product->get_regular_price();
		}

		if ( class_exists( 'Wdr\App\Controllers\Configuration' ) ) {
			$discount_config = Configuration::getInstance()->getConfig( 'calculate_discount_from', 'sale_price' );
			if ( isset( $discount_config ) && ! empty( $discount_config ) ) {
				if ( 'regular_price' === $discount_config ) {
					$price = $product->get_regular_price();
					if ( $wpml_active_currency_status ) {
						$price = $wpml_regular_price;
					}
				} elseif ( 'sale_price' === $discount_config ) {
					$price = $product->get_sale_price();
					if ( $wpml_active_currency_status ) {
						$price = $wpml_sale_price;
					}
				} else {
					$price = $exchange_rate;
				}
			} else {
				$price = $exchange_rate;
			}

			if ( $product->is_type( 'variable' ) ) {
				$min = $product->get_variation_price( 'min', false );
				$max = $product->get_variation_price( 'max', false );

				$price = $min;
				if ( $max === $base_price ) {
					$price = $max;
				}
			}

			$price = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price', false, $product, 1, $price, 'discounted_price', true, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by Discount Rules for WooCommerce; the name is fixed by that plugin and must not be prefixed.

			if ( empty( $price ) ) {
				$price = $base_price;
			}

			$price = apply_filters( 'wcml_raw_price_amount', $price, $config->get_feed_currency() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by WooCommerce Multilingual (WCML); the name is fixed by that plugin and must not be prefixed.
		}

		return $price;
	}
}
