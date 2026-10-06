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

		// YayCurrency (CBT-703).
		$currencies = $this->yay_currencies();
		if ( ! empty( $currencies ) ) {
			return $currencies;
		}

		// X-Currency (CBT-703).
		$currencies = $this->x_currency_currencies();
		if ( ! empty( $currencies ) ) {
			return $currencies;
		}

		// WooPayments Multi-Currency (CBT-702) — last, so a dedicated switcher wins.
		return $this->wcpay_currencies();
	}

	/**
	 * YayCurrency currencies (code => code), store default first.
	 *
	 * Reads Yay's own `yay-currency-manage` posts (title = currency code)
	 * READ-ONLY. Yay's Helper::get_currencies_post_type() is deliberately not
	 * used: it deletes duplicate currency posts as a side effect, which an
	 * admin dropdown must never trigger (CBT-703).
	 *
	 * @since 8.0.31
	 *
	 * @return array
	 */
	private function yay_currencies(): array {
		if ( ! class_exists( 'Yay_Currency\Helpers\Helper' ) || ! function_exists( 'get_posts' ) ) {
			return array();
		}

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Enumerates the handful of YayCurrency definition posts (never product data) for the admin currency dropdown; paginating would drop currencies. Admin-only, off the feed batch path.
		$posts = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'yay-currency-manage',
				'post_status'    => 'publish',
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page

		$currencies = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			$code = isset( $post->post_title ) ? strtoupper( trim( (string) $post->post_title ) ) : '';
			if ( '' !== $code ) {
				$currencies[ $code ] = $code;
			}
		}

		return $currencies;
	}

	/**
	 * X-Currency active currencies (code => code), base currency first.
	 *
	 * @since 8.0.31
	 *
	 * @return array
	 */
	private function x_currency_currencies(): array {
		if ( ! function_exists( 'x_currency_singleton' ) || ! class_exists( 'XCurrency\App\Repositories\CurrencyRepository' ) ) {
			return array();
		}

		$currencies = array();
		try {
			if ( function_exists( 'x_currency_base_code' ) ) {
				$base = strtoupper( trim( (string) x_currency_base_code() ) );
				if ( '' !== $base ) {
					$currencies[ $base ] = $base;
				}
			}

			$repository = x_currency_singleton( 'XCurrency\App\Repositories\CurrencyRepository' );
			$active     = is_object( $repository ) && method_exists( $repository, 'get' ) ? $repository->get() : array();
			foreach ( is_iterable( $active ) ? $active : array() as $currency ) {
				$code = is_object( $currency ) && isset( $currency->code ) ? strtoupper( trim( (string) $currency->code ) ) : '';
				if ( '' !== $code ) {
					$currencies[ $code ] = $code;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $currencies;
	}

	/**
	 * Enabled WooPayments Multi-Currency currencies (code => code), or an
	 * empty array when its Multi-Currency feature is off or only the store
	 * currency is enabled (WooPayments active as a payment gateway only).
	 *
	 * @since 8.0.31
	 *
	 * @return array
	 */
	private function wcpay_currencies(): array {
		$feature = get_option( '_wcpay_feature_customer_multi_currency', '1' );
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) || ! is_scalar( $feature ) || '1' !== (string) $feature ) {
			return array();
		}

		try {
			$mc      = \WC_Payments_Multi_Currency();
			$enabled = is_object( $mc ) && method_exists( $mc, 'get_enabled_currencies' ) ? $mc->get_enabled_currencies() : array();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $enabled ) || count( $enabled ) < 2 ) {
			return array();
		}

		$codes = array_map( 'strval', array_keys( $enabled ) );

		return array_combine( $codes, $codes );
	}
}
