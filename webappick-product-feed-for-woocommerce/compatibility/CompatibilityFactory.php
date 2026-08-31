<?php
/**
 * Compatibility Factory
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\Compat
 * @category   MyCategory
 * @since      5.0.0
 */

namespace CTXFeed\Compat;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Compatibility Factory
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\Compat
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
		// Caching-plugin exclusions are always-on: the shim self-guards per
		// caching plugin (and behind the `ctxfeed_cache_exclude_enabled`
		// filter), so it is instantiated directly rather than through the
		// per-plugin `{Key}Compatibility` scan below. Runs front-end + admin
		// (plugins_loaded:25) so its template_redirect / runtime-filter
		// exclusions register on public feed requests, not just wp-admin.
		new Shims\ExcludeCaching();

		$classes            = self::get_classes();
		$compatible_plugins = self::compatible_plugins_grouped();
		foreach ( $classes as $class ) {
			$class_name = __NAMESPACE__ . '\\Shims\\' . $class . 'Compatibility';

			// Skip if no plugins are mapped to this class or class doesn't exist.
			if ( ! isset( $compatible_plugins[ $class ] ) || ! class_exists( $class_name ) ) {
				continue;
			}

			// Check if ANY of the plugins mapped to this class are active.
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

			new $class_name();
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
		$grouped = array();

		foreach ( $plugins as $plugin_path => $class_name ) {
			if ( empty( $class_name ) ) {
				continue;
			}
			if ( ! isset( $grouped[ $class_name ] ) ) {
				$grouped[ $class_name ] = array();
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
		// Free tier only. The Pro-only shims (composite / bundle / grouped
		// product types, currency switchers, translation plugins, subscriptions,
		// Germanized, DIVI) live in the CTX Feed Pro plugin's own
		// `compatibility/` layer (CTXFeed\Pro\Compat).
		return self::get_free_compatible_plugins();
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
			return array( 'aco-woo-dynamic-pricing/start.php' => 'CTX_AWDP_Discount' );
		} elseif ( is_plugin_active( 'aco-woo-dynamic-pricing-pro/start.php' ) ) {
			return array( 'aco-woo-dynamic-pricing-pro/start.php' => 'CTX_AWDP_Discount' );
		}
		return array();
	}

	/**
	 * Get base compatible plugins for free version.
	 *
	 * @return array
	 */
	private static function get_base_free_compatible_plugins() {
		return array(
			// WooCommerce Dynamic Pricing & Discounts plugins.
			'woo-discount-rules/woo-discount-rules.php'   => 'Wdr_Configuration',
			'woo-advanced-discounts/wad.php'              => 'WAD_Discount',
			'easy-woocommerce-discounts/easy-woocommerce-discounts.php' => 'WCCS_Pricing',
			'wc-dynamic-pricing-and-discounts/wc-dynamic-pricing-and-discounts.php' => 'RP_WCDPD',

			// SEO plugins (also available in free).
			'wordpress-seo/wp-seo.php'                    => 'WPSEO_Frontend',
			'wordpress-seo-premium/wp-seo-premium.php'    => 'WPSEO_Frontend',
			'seo-by-rank-math/rank-math.php'              => 'RankMath',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'AIOSEO',

			// ACF (Advanced Custom Fields) plugin (also available in free).
			'advanced-custom-fields/acf.php'              => 'ACF',
			'advanced-custom-fields-pro/acf.php'          => 'ACF',

			// Variation Gallery plugins (also available in free).
			'woo-variation-gallery/woo-variation-gallery.php' => 'VariationGallery',
			'woo-product-variation-gallery/woo-product-variation-gallery.php' => 'VariationGallery',
			'woocommerce-additional-variation-images/woocommerce-additional-variation-images.php' => 'VariationGallery',
		);
	}

	/**
	 * Get the compatibility class for the current plugin version
	 *
	 * @return array Array of compatibility classes
	 */
	public static function get_classes() {
		// Scan the Shims/ subfolder for the rewritten compatibility classes.
		$directory = plugin_dir_path( __FILE__ ) . 'Shims/';

		// Scan the directory for files.
		$all_files = scandir( $directory );

		// Filter files to get only those ending with 'Compatibility.php'.
		$filtered_files = array_filter(
			$all_files,
			static function ( $file ) {
				return strpos( $file, 'Compatibility.php' ) && substr( $file, - strlen( 'Compatibility.php' ) ) === 'Compatibility.php';
			}
		);

		// Extract the part of the filename before 'Compatibility'.
		return array_map(
			static function ( $file ) {
				return str_replace( 'Compatibility.php', '', $file );
			},
			$filtered_files
		);
	}
}
