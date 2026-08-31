<?php
/**
 * WpOptionResolver — Resolves WordPress option values for feed attributes.
 *
 * Handles attributes prefixed with "wf_option_" that map to WordPress
 * site-wide options (e.g., siteurl, blogname). These values are the
 * same for every product row since they are global site settings.
 *
 * V5 stores tracked options in the "wpfp_option" wp_option. The user
 * adds option names via the admin UI, and they appear in the "Options"
 * group of the Value dropdown with keys like "wf_option_siteurl".
 *
 * During feed generation, this resolver strips the prefix and calls
 * get_option() to fetch the actual WordPress option value.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress option resolver.
 *
 * @since 8.0.0
 */
class WpOptionResolver {

	/**
	 * Prefix used to identify WordPress option attributes.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const WP_OPTION_PREFIX = 'wf_option_';

	/**
	 * Internal cache of resolved options to avoid repeated get_option() calls
	 * within the same feed generation run.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $cache = array();

	/**
	 * Check if an attribute key is a WordPress option attribute.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute key.
	 *
	 * @return bool True if the attribute starts with the WP option prefix.
	 */
	public function is_wp_option( string $attr ): bool {
		return 0 === strpos( $attr, self::WP_OPTION_PREFIX );
	}

	/**
	 * Resolve a WordPress option attribute value.
	 *
	 * Strips the "wf_option_" prefix and retrieves the value via get_option().
	 * Results are cached per option name to avoid redundant DB lookups during
	 * batch processing (same value for every product row).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product (unused — options are global).
	 * @param string      $attr    Attribute key (e.g., "wf_option_siteurl").
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Resolved option value or empty string.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string {
		$option_name = str_replace( self::WP_OPTION_PREFIX, '', $attr );

		if ( empty( $option_name ) ) {
			return '';
		}

		// Return cached value if already resolved.
		if ( isset( $this->cache[ $option_name ] ) ) {
			return $this->cache[ $option_name ];
		}

		$value = get_option( $option_name, '' );

		// Arrays/objects are flattened for feed output.
		if ( is_array( $value ) ) {
			$value = $this->stringify_array( $value );
		} elseif ( is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = (string) $value;

		/**
		 * Filter the resolved WordPress option value.
		 *
		 * @since 8.0.0
		 *
		 * @param string      $value       Resolved option value.
		 * @param string      $option_name Original WordPress option name.
		 * @param string      $attr        Full attribute key with prefix.
		 * @param \WC_Product $product     WooCommerce product instance.
		 * @param Config      $config      Feed configuration.
		 */
		$value = apply_filters( 'ctxfeed_wp_option_value', $value, $option_name, $attr, $product, $config );

		// Cache the resolved value.
		$this->cache[ $option_name ] = $value;

		return $value;
	}

	/**
	 * Convert an option array to a feed-safe string without losing data.
	 *
	 * A flat array of scalars keeps the V5-style comma join, which is the
	 * human-readable shape merchants expect for simple lists. But if any
	 * element is itself an array or object, that member cannot be stringified
	 * with strval() — PHP would emit the literal "Array" (plus an "Array to
	 * string conversion" warning) and silently discard the real value. In that
	 * case JSON-encode the whole structure instead, mirroring how a plain
	 * object value is already handled, so no data is lost (BUG-0059 / BUG-0054).
	 *
	 * @since 8.0.0
	 *
	 * @param array $value Option array value.
	 * @return string Comma-joined scalars, or a JSON string when nested.
	 */
	private function stringify_array( array $value ): string {
		foreach ( $value as $item ) {
			if ( is_array( $item ) || is_object( $item ) ) {
				return (string) wp_json_encode( $value );
			}
		}

		return implode( ', ', array_map( 'strval', $value ) );
	}

	/**
	 * Clear the internal cache.
	 *
	 * Called between feed generation runs if the resolver is reused.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->cache = array();
	}
}
