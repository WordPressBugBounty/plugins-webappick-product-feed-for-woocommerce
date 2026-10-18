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
	 * The `Shims/{Key}Compatibility.php` shims, by key, in file-name order.
	 *
	 * A fixed list instead of reading the Shims/ folder on every request
	 * (CBT-806): the folder read cost a directory scan per page view, and
	 * while an update replaces the plugin folder it returns false — PHP 8
	 * then throws a TypeError. A unit test keeps this list equal to the
	 * files in Shims/, so a new shim cannot be forgotten here.
	 *
	 * @since 8.0.38
	 * @var string[]
	 */
	const SHIMS = array(
		'ACF',
		'AIOSEO',
		'CTX_AWDP_Discount',
		'RP_WCDPD',
		'RankMath',
		'VariationGallery',
		'WAD_Discount',
		'WCCS_Pricing',
		'WPSEO_Frontend',
		'Wdr_Configuration',
	);

	/**
	 * Initialize the compatibility classes
	 *
	 * @throws \RuntimeException When the shim of an active plugin or theme cannot be loaded (CBT-807).
	 */
	public static function init() {
		// Caching-plugin exclusions are always-on: the shim self-guards per
		// caching plugin (and behind the `ctxfeed_cache_exclude_enabled`
		// filter), so it is instantiated directly rather than through the
		// per-plugin `{Key}Compatibility` loop below. Runs front-end + admin
		// (plugins_loaded:25) so its template_redirect / runtime-filter
		// exclusions register on public feed requests, not just wp-admin.
		// class_exists() first: its file can vanish while an update replaces
		// the plugin folder, and that must never be a fatal (CBT-806).
		if ( class_exists( Shims\ExcludeCaching::class ) ) {
			new Shims\ExcludeCaching();
		}

		// woo-feed.php loads wp-admin/includes/plugin.php at include time;
		// without it no plugin can be detected, so no shim applies.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return;
		}

		$compatible_plugins = self::compatible_plugins_grouped();
		foreach ( self::get_classes() as $class ) {
			// Skip if no plugins are mapped to this class.
			if ( ! isset( $compatible_plugins[ $class ] ) ) {
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

			// Some shims serve a THEME, not a plugin (CBT-772: WoodMart's
			// variation gallery). is_plugin_active() never sees a theme.
			if ( ! $is_any_plugin_active && self::theme_activates( $class ) ) {
				$is_any_plugin_active = true;
			}

			if ( ! $is_any_plugin_active ) {
				continue;
			}

			// Load the shim only now, for an active plugin or theme: checking
			// class_exists() first autoloaded every shim on every page view,
			// even for plugins the site does not use (CBT-806).
			//
			// All or nothing (CBT-807, same as Pro's factory): the plugin is
			// active, so a shim that cannot load would let feeds go out
			// without its values. Throw — woo-feed.php's guarded() step rolls
			// back the shims added so far and marks the stack incomplete, and
			// feed runs wait instead.
			$class_name = __NAMESPACE__ . '\\Shims\\' . $class . 'Compatibility';
			if ( ! class_exists( $class_name ) ) {
				// No quotes around the name: esc_html() would encode them, and
				// the boot-failure record maps "Class X not found" to its file.
				throw new \RuntimeException( esc_html( 'Class ' . $class_name . ' not found' ) );
			}

			new $class_name();
		}
	}

	/**
	 * Whether the active theme (or its parent theme) needs a shim.
	 *
	 * Reads the `template` option through get_template(), so it works at
	 * plugins_loaded (before the theme loads) and covers any child theme,
	 * whatever it is named.
	 *
	 * @since 8.0.36
	 *
	 * @param string $class_key Shim key (e.g. 'VariationGallery').
	 * @return bool
	 */
	public static function theme_activates( string $class_key ): bool {
		$theme_shims = array(
			// WoodMart theme → its variation gallery (CBT-772).
			'VariationGallery' => array( 'woodmart' ),
		);

		if ( ! isset( $theme_shims[ $class_key ] ) || ! function_exists( 'get_template' ) ) {
			return false;
		}

		return in_array( strtolower( (string) get_template() ), $theme_shims[ $class_key ], true );
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
	 * Get the compatibility class keys: `{Key}` of each `Shims/{Key}Compatibility`.
	 *
	 * @return string[] Shim keys, in file-name order (see SHIMS).
	 */
	public static function get_classes() {
		return self::SHIMS;
	}
}
