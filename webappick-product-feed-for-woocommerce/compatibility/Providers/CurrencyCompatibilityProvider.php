<?php
/**
 * CurrencyCompatibilityProvider — multi-currency plugin integration.
 *
 * Holds the active-currency detection relocated OUT of V8 core
 * (`V8/Common/DropdownRegistry::get_active_currencies()`). Detects WCML,
 * Aelia, WOOCS, ALG, WooCommerce MultiCurrency, and Woo Multi Currency.
 *
 * NOTE: this owns only the DROPDOWN currency list (which currencies a feed can
 * target). The per-product price CONVERSION for these plugins is handled
 * separately by the rewritten shims (dormant) / the live ctx-compatibility
 * submodule via the `woo_feed_filter_product_{price}` bridge — not here.
 *
 * Hooks answered (registered from Bootstrap::init()):
 *   • `ctxfeed_dropdown_currencies` — currency code => currency code.
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
 * Multi-currency compatibility provider.
 *
 * @since 8.0.0
 */
class CurrencyCompatibilityProvider {

	/**
	 * Register the hook seam this provider answers.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ctxfeed_dropdown_currencies', array( $this, 'currencies' ) );
	}

	/**
	 * Resolve the active currency list from whichever multi-currency plugin
	 * is active (first match wins).
	 *
	 * Answers `ctxfeed_dropdown_currencies`. Returns the incoming value
	 * untouched when already populated by an earlier provider.
	 *
	 * @since 8.0.0
	 *
	 * @param array $currencies Incoming currency list (default empty).
	 * @return array Currency code => currency code.
	 */
	public function currencies( $currencies = array() ): array {
		if ( ! empty( $currencies ) ) {
			return (array) $currencies;
		}

		$currencies = array();

		// WPML + WooCommerce Multilingual (WCML).
		global $woocommerce_wpml;
		if (
			class_exists( 'SitePress' )
			&& class_exists( 'woocommerce_wpml' )
			&& function_exists( 'wcml_is_multi_currency_on' )
			&& wcml_is_multi_currency_on()
			&& isset( $woocommerce_wpml->multi_currency->currencies )
		) {
			foreach ( $woocommerce_wpml->multi_currency->currencies as $code => $data ) {
				$currencies[ $code ] = $code;
			}

			return $currencies;
		}

		// Aelia Currency Switcher.
		if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
			$base       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
			$aelia_list = apply_filters( 'wc_aelia_cs_enabled_currencies', $base ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by WC_Aelia_CurrencySwitcher; the name is fixed by that plugin and must not be prefixed.
			if ( is_array( $aelia_list ) ) {
				$currencies = array_combine( $aelia_list, $aelia_list );
			} elseif ( is_string( $aelia_list ) ) {
				$currencies = array( $aelia_list => $aelia_list );
			}

			return $currencies;
		}

		// WOOCS — WooCommerce Currency Switcher.
		if ( ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-currency-switcher/index.php' ) ) || class_exists( 'WOOCS' ) ) {
			global $WOOCS;
			if ( isset( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) ) {
				foreach ( $WOOCS->get_currencies() as $code => $data ) {
					$currencies[ $code ] = $code;
				}
			}
			if ( empty( $currencies ) ) {
				$woocs_opt = get_option( 'woocs', array() );
				if ( is_array( $woocs_opt ) ) {
					foreach ( $woocs_opt as $code => $data ) {
						$currencies[ $code ] = $code;
					}
				}
			}

			return $currencies;
		}

		// ALG Currency Switcher.
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'currency-switcher-woocommerce/currency-switcher-woocommerce.php' ) ) {
			if ( function_exists( 'alg_get_enabled_currencies' ) ) {
				$alg        = alg_get_enabled_currencies();
				$currencies = array_combine( $alg, $alg );
			}

			return $currencies;
		}

		// WooCommerce MultiCurrency.
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-multicurrency/woocommerce-multicurrency.php' ) ) {
			if ( class_exists( 'WOOMC\DAO\Factory' ) ) {
				$mc         = \WOOMC\DAO\Factory::getDao()->getEnabledCurrencies();
				$currencies = array_combine( $mc, $mc );
			}

			return $currencies;
		}

		// Woo Multi Currency / WooCommerce Multi Currency.
		if (
			function_exists( 'is_plugin_active' )
			&& ( is_plugin_active( 'woo-multi-currency/woo-multi-currency.php' )
				|| is_plugin_active( 'woocommerce-multi-currency/woocommerce-multi-currency.php' ) )
		) {
			$settings = get_option( 'woo_multi_currency_params' );
			if ( isset( $settings['currency'] ) && is_array( $settings['currency'] ) ) {
				$currencies = array_combine( $settings['currency'], $settings['currency'] );
			}

			return $currencies;
		}

		return $currencies;
	}
}
