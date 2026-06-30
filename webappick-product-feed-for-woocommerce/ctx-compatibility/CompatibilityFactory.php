<?php
/**
 * Compatibility Factory
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Compatibility
 * @category   MyCategory
 * @since      5.0.0
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Common\Helper;

/**
 * Class Compatibility Factory
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Compatibility
 * @author     Ohidul Islam <wahid0003@gmail.com>
 * @link       https://webappick.com
 * @license    https://opensource.org/licenses/gpl-license.php GNU Public License
 * @category   MyCategory
 */
class CompatibilityFactory {
	/**
	 * Initialize the compatibility classes
	 */
	public static function init() {
		$classes            = self::get_classes();
		$compatible_plugins = self::compatible_plugins_grouped();
		foreach ( $classes as $class ) {
			$class_name = __NAMESPACE__ . '\\' . $class . 'Compatibility';

			// Skip if no plugins are mapped to this class or class doesn't exist
			if ( ! isset( $compatible_plugins[ $class ] ) || ! class_exists( $class_name ) ) {
				continue;
			}

			// Check if ANY of the plugins mapped to this class are active
			$is_any_plugin_active = false;
			foreach ( $compatible_plugins[ $class ] as $plugin_path ) {
				if ( is_plugin_active( $plugin_path ) ) {
					$is_any_plugin_active = true;
					break;
				}
			}

			if ( ! $is_any_plugin_active ) {
				continue;
			}

			new $class_name;
		}

	}

	/**
	 * Get compatible plugins grouped by class name.
	 *
	 * This method returns an array where keys are class names and values are arrays
	 * of plugin paths that can trigger that compatibility class.
	 *
	 * This fixes the issue where array_flip() would lose duplicate mappings
	 * (e.g., multiple variation gallery plugins mapping to 'VariationGallery').
	 *
	 * @return array Array with class names as keys and arrays of plugin paths as values.
	 */
	private static function compatible_plugins_grouped() {
		$plugins = self::get_compatible_plugins_raw();
		$grouped = [];

		foreach ( $plugins as $plugin_path => $class_name ) {
			if ( empty( $class_name ) ) {
				continue;
			}
			if ( ! isset( $grouped[ $class_name ] ) ) {
				$grouped[ $class_name ] = [];
			}
			$grouped[ $class_name ][] = $plugin_path;
		}

		return $grouped;
	}

	/**
	 * Get the raw compatible plugins list (plugin path => class name).
	 *
	 * @return array
	 */
	private static function get_compatible_plugins_raw() {
		if ( Helper::is_pro() ) {
			return self::get_pro_compatible_plugins();
		} else {
			return self::get_free_compatible_plugins();
		}
	}

	/**
	 * Get pro compatible plugins list.
	 *
	 * @return array
	 */
	private static function get_pro_compatible_plugins() {
		$compatible_plugins = self::get_base_compatible_plugins();
		$compatible_plugins = array_merge( $compatible_plugins, self::get_awdp_discount_plugins(), self::get_polylang_plugins() );

		// If WooCommerce Multi Currency Pro version by VillaTheme is active, Free version will be removed from the list.
		if ( is_plugin_active( 'woocommerce-multi-currency/woocommerce-multi-currency.php' ) ) {
			$compatible_plugins['woocommerce-multi-currency/woocommerce-multi-currency.php'] = 'WOOMULTI_CURRENCY';
			unset( $compatible_plugins['woo-multi-currency/woo-multi-currency.php'] );
		}

		return $compatible_plugins;
	}

	/**
	 * Get free compatible plugins list.
	 *
	 * @return array
	 */
	private static function get_free_compatible_plugins() {
		$compatible_plugins = self::get_base_free_compatible_plugins();
		return array_merge( $compatible_plugins, self::get_awdp_discount_plugins() );
	}

	/**
	 * Get AWDP Discount plugins based on which is active.
	 *
	 * @return array
	 */
	private static function get_awdp_discount_plugins() {
		if ( is_plugin_active( 'aco-woo-dynamic-pricing/start.php' ) ) {
			return [ 'aco-woo-dynamic-pricing/start.php' => 'CTX_AWDP_Discount' ];
		} elseif ( is_plugin_active( 'aco-woo-dynamic-pricing-pro/start.php' ) ) {
			return [ 'aco-woo-dynamic-pricing-pro/start.php' => 'CTX_AWDP_Discount' ];
		}
		return [];
	}

	/**
	 * Get Polylang plugins based on which is active.
	 *
	 * @return array
	 */
	private static function get_polylang_plugins() {
		if ( is_plugin_active( 'polylang-pro/polylang.php' ) ) {
			return [ 'polylang-pro/polylang.php' => 'Polylang' ];
		} elseif ( is_plugin_active( 'polylang-wc/polylang-wc.php' ) ) {
			return [ 'polylang-wc/polylang-wc.php' => 'Polylang' ];
		}
		return [];
	}

	/**
	 * Get base compatible plugins for pro version.
	 *
	 * @return array
	 */
	private static function get_base_compatible_plugins() {
		return [
			#################################################################################
			# WooCommerce Dynamic Pricing & Discounts plugins                               #
			#################################################################################
			'woo-discount-rules/woo-discount-rules.php'                                 => 'Wdr_Configuration',
			'woo-advanced-discounts/wad.php'                                            => 'WAD_Discount',
			'easy-woocommerce-discounts/easy-woocommerce-discounts.php'                 => 'WCCS_Pricing',
			'wc-dynamic-pricing-and-discounts/wc-dynamic-pricing-and-discounts.php'     => 'RP_WCDPD',

			#################################################################################
			# Composite Products for WooCommerce plugins                                    #
			#################################################################################
			'woocommerce-composite-products/woocommerce-composite-products.php'         => 'WC_Composite_Products',
			'wpc-composite-products/wpc-composite-products.php'                         => 'WPCleverWooco',

			#################################################################################
			# WooCommerce Product Bundles plugins                                           #
			#################################################################################
			'woocommerce-product-bundles/woocommerce-product-bundles.php'               => 'WC_Product_Bundle',
			'woo-product-bundle/wpc-product-bundles.php'                                => 'WPCProductBundles',
			'iconic-woo-bundled-products/iconic-woo-bundled-products.php'               => 'IconicBundleProduct',

			#################################################################################
			# WPC Grouped Product plugin                                                    #
			#################################################################################
			'wpc-grouped-product/wpc-grouped-product.php'                               => 'WPCGroupedProduct',

			#################################################################################
			# WooCommerce Currency Switcher plugins                                         #
			#################################################################################
			'woocommerce-currency-switcher/index.php'                                   => 'WOOCS',
			'currency-switcher-woocommerce/currency-switcher-woocommerce.php'           => 'Alg_WC_Currency_Switcher',
			'woocommerce-multicurrency/woocommerce-multicurrency.php'                   => 'WOOMC_API',
			'woocommerce-multi-currency/woocommerce-multi-currency.php'                 => '',
			'woocommerce-multilingual/wpml-woocommerce.php'                             => 'woocommerce_wpml',
			'woocommerce-aelia-currencyswitcher/woocommerce-aelia-currencyswitcher.php' => 'WC_Aelia_CurrencySwitcher',
			'woo-multi-currency/woo-multi-currency.php'                                 => 'WOOMULTI_CURRENCY_F',
			'yaycurrency/yay-currency.php'                                              => 'YayCurrency',
			'x-currency/x-currency.php'                                                 => 'XCurrency',

			#################################################################################
			# WooCommerce Translation plugins                                               #
			#################################################################################
			'sitepress-multilingual-cms/sitepress.php'                                  => 'SitePress',
			'translatepress-multilingual/index.php'                                     => 'TRP_Translate_Press',

			'divigrid/divigrid.php'                                                     => 'DIVI_GRID_PLUGIN',

			#################################################################################
			# WooCommerce Subscriptions plugin                                              #
			#################################################################################
			'woocommerce-subscriptions/woocommerce-subscriptions.php'                   => 'WC_Subscriptions',

			#################################################################################
			# WooCommerce Germanized plugin                                                 #
			#################################################################################
			'woocommerce-germanized/woocommerce-germanized.php'                         => 'WooCommerce_Germanized',

			#################################################################################
			# SEO plugins                                                                   #
			#################################################################################
			'wordpress-seo/wp-seo.php'                                                  => 'WPSEO_Frontend',
			'wordpress-seo-premium/wp-seo-premium.php'                                  => 'WPSEO_Frontend',
			'seo-by-rank-math/rank-math.php'                                            => 'RankMath',
			'all-in-one-seo-pack/all_in_one_seo_pack.php'                               => 'AIOSEO',

			#################################################################################
			# ACF (Advanced Custom Fields) plugin                                           #
			#################################################################################
			'advanced-custom-fields/acf.php'                                            => 'ACF',
			'advanced-custom-fields-pro/acf.php'                                        => 'ACF',

			#################################################################################
			# Variation Gallery plugins                                                     #
			#################################################################################
			'woo-variation-gallery/woo-variation-gallery.php'                           => 'VariationGallery',
			'woo-product-variation-gallery/woo-product-variation-gallery.php'           => 'VariationGallery',
			'woocommerce-additional-variation-images/woocommerce-additional-variation-images.php' => 'VariationGallery',
		];
	}

	/**
	 * Get base compatible plugins for free version.
	 *
	 * @return array
	 */
	private static function get_base_free_compatible_plugins() {
		return [
			#################################################################################
			# WooCommerce Dynamic Pricing & Discounts plugins                               #
			#################################################################################
			'woo-discount-rules/woo-discount-rules.php'                                 => 'Wdr_Configuration',
			'woo-advanced-discounts/wad.php'                                            => 'WAD_Discount',
			'easy-woocommerce-discounts/easy-woocommerce-discounts.php'                 => 'WCCS_Pricing',
			'wc-dynamic-pricing-and-discounts/wc-dynamic-pricing-and-discounts.php'     => 'RP_WCDPD',

			#################################################################################
			# SEO plugins (also available in free)                                          #
			#################################################################################
			'wordpress-seo/wp-seo.php'                                                  => 'WPSEO_Frontend',
			'wordpress-seo-premium/wp-seo-premium.php'                                  => 'WPSEO_Frontend',
			'seo-by-rank-math/rank-math.php'                                            => 'RankMath',
			'all-in-one-seo-pack/all_in_one_seo_pack.php'                               => 'AIOSEO',

			#################################################################################
			# ACF (Advanced Custom Fields) plugin (also available in free)                  #
			#################################################################################
			'advanced-custom-fields/acf.php'                                            => 'ACF',
			'advanced-custom-fields-pro/acf.php'                                        => 'ACF',

			#################################################################################
			# Variation Gallery plugins (also available in free)                            #
			#################################################################################
			'woo-variation-gallery/woo-variation-gallery.php'                           => 'VariationGallery',
			'woo-product-variation-gallery/woo-product-variation-gallery.php'           => 'VariationGallery',
			'woocommerce-additional-variation-images/woocommerce-additional-variation-images.php' => 'VariationGallery',
		];
	}

	/**
	 * Get the compatibility class for the current plugin version
	 *
	 * @return array Array of compatibility classes
	 */
	public static function get_classes() {
		// Get the current working directory
		$directory = plugin_dir_path( __FILE__ );

		// Scan the directory for files
		$all_files = scandir( $directory );

		// Filter files to get only those ending with 'Compatibility.php'
		$filtered_files = array_filter(
			$all_files,
			static function ( $file ) {
				return strpos( $file, 'Compatibility.php' ) && substr( $file, - strlen( 'Compatibility.php' ) ) === 'Compatibility.php';
			}
		);

		// Extract the part of the filename before 'Compatibility'
		return array_map(
			static function ( $file ) {
				return str_replace( 'Compatibility.php', '', $file );
			},
			$filtered_files
		);
	}

}
