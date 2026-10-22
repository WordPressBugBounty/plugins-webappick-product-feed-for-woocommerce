<?php
/**
 * AttributeKey — find a custom attribute's stored key across site-locale changes.
 *
 * WooCommerce keys a product's custom attribute by `sanitize_title( name )`
 * at save time, and `sanitize_title()` folds accents by the CURRENT site
 * locale (`remove_accents()`: German "Länge" → `laenge`, English → `lange`).
 * After the site locale changes, products saved before and after carry
 * different keys for the same attribute, so a feed row mapped to one key
 * silently resolves empty on the others (CBT-830).
 *
 * @package    CTXFeed\V8\Product
 * @since      8.0.40
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locale-tolerant custom-attribute key lookup. Only used after a normal
 * lookup came back empty, so stores that never changed locale keep the
 * old code path.
 *
 * @since 8.0.40
 */
final class AttributeKey {

	/**
	 * Locales whose `remove_accents()` rules differ from the default ones
	 * (WordPress core: de*, da_DK, ca, sr_RS / bs_BA), plus the default.
	 *
	 * @var string[]
	 */
	const ACCENT_LOCALES = array( 'en_US', 'de_DE', 'da_DK', 'ca', 'sr_RS' );

	/**
	 * Per-request memo: product id + wanted key → stored key ('' = none).
	 *
	 * @var array<string,string>
	 */
	private static $memo = array();

	/**
	 * Per-request memo: parent (or simple product) id → its custom attributes
	 * indexed by every spelling a feed row can use for them — the stored key
	 * and the display name folded under each accent locale — mapped to
	 * `[ name, stored key ]`. Built once per parent; every variation of it
	 * and every empty row then costs an isset() (CBT-855).
	 *
	 * @var array<int,array<string,array{0:string,1:string}>>
	 */
	private static $parent_maps = array();

	/**
	 * Per-request memo: display name → its folded keys (as array keys).
	 *
	 * @var array<string,array<string,true>>
	 */
	private static $folds = array();

	/**
	 * How many parent maps were built (tests: one per parent).
	 *
	 * @var int
	 */
	private static $maps_built = 0;

	/**
	 * The key under which `$product` holds the custom attribute a feed row
	 * maps as `$wanted`, when it differs from `$wanted`; '' otherwise.
	 *
	 * WooCommerce re-keys a product's custom attributes on every READ by
	 * `sanitize_title( name )` under the current locale, while the picker
	 * lists the keys stored at save time and variations keep their saved
	 * `attribute_*` meta keys. So after a locale change a parent may hold
	 * `lange` while the row (and its variations) say `laenge`, or the other
	 * way round. Matching goes through the attribute's display name.
	 * Global attributes (`pa_*`) never change key and are skipped.
	 *
	 * Runs on every EMPTY attribute lookup of a feed, so the parent-level
	 * work (loading the parent, folding every custom attribute name under
	 * every accent locale) is done once per parent (CBT-855: it cost
	 * ~0.75 ms per empty lookup when repeated per variation and per row).
	 *
	 * @since 8.0.40
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $wanted  Key the feed row maps (e.g. `laenge`).
	 * @return string Key on this product (e.g. `lange`), or ''.
	 */
	public static function find( \WC_Product $product, string $wanted ): string {
		if ( '' === $wanted || 0 === strpos( $wanted, 'pa_' ) || ! function_exists( 'remove_accents' ) || ! function_exists( 'sanitize_title_with_dashes' ) ) {
			return '';
		}

		$memo_key = $product->get_id() . '|' . $wanted;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		if ( count( self::$memo ) > 2000 ) {
			self::$memo = array();
		}

		$is_variation = $product->is_type( 'variation' );
		$map          = self::parent_map( $product, $is_variation );

		// The custom attribute the row means, by key or by display name
		// (the first attribute in order wins, as the map was built).
		$found = '';
		if ( null !== $map && isset( $map[ $wanted ] ) ) {
			list( $name, $parent_key ) = $map[ $wanted ];
			if ( $is_variation ) {
				// Variations keep the keys their attribute meta was saved under.
				$folds = self::folds( $name );
				foreach ( array_keys( (array) $product->get_attributes() ) as $key ) {
					$key = (string) $key;
					if ( $key !== $wanted && isset( $folds[ $key ] ) ) {
						$found = $key;
						break;
					}
				}
			} elseif ( $parent_key !== $wanted ) {
				$found = $parent_key;
			}
		}

		self::$memo[ $memo_key ] = $found;
		return $found;
	}

	/**
	 * Whether `$name` sanitizes to `$key` under any accent-locale rule set.
	 *
	 * @since 8.0.40
	 *
	 * @param string $name Attribute display name ("Länge").
	 * @param string $key  Stored key (`laenge`).
	 * @return bool
	 */
	public static function name_folds_to( string $name, string $key ): bool {
		if ( ! function_exists( 'remove_accents' ) || ! function_exists( 'sanitize_title_with_dashes' ) ) {
			return false;
		}
		return isset( self::folds( $name )[ $key ] );
	}

	/**
	 * The custom-attribute map of a product's parent (variation) or of the
	 * product itself, built once per id; null when the parent cannot load.
	 *
	 * @since 8.0.40
	 *
	 * @param \WC_Product $product      Product or variation.
	 * @param bool        $is_variation Whether `$product` is a variation.
	 * @return array<string,array{0:string,1:string}>|null
	 */
	private static function parent_map( \WC_Product $product, bool $is_variation ): ?array {
		$parent_id = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( isset( self::$parent_maps[ $parent_id ] ) ) {
			return self::$parent_maps[ $parent_id ];
		}

		$source = $product;
		if ( $is_variation ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return null;
			}
			$source = wc_get_product( $parent_id );
			if ( ! $source instanceof \WC_Product ) {
				return null;
			}
		}

		$map = array();
		foreach ( (array) $source->get_attributes() as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}
			$key   = (string) $key;
			$name  = (string) $attribute->get_name();
			$entry = array( $name, $key );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $entry;
			}
			foreach ( array_keys( self::folds( $name ) ) as $folded ) {
				if ( ! isset( $map[ $folded ] ) ) {
					$map[ (string) $folded ] = $entry;
				}
			}
		}

		if ( count( self::$parent_maps ) > 2000 ) {
			self::$parent_maps = array();
		}
		self::$parent_maps[ $parent_id ] = $map;
		++self::$maps_built;

		return $map;
	}

	/**
	 * Every key `$name` sanitizes to under the accent locales, as array keys.
	 *
	 * @since 8.0.40
	 *
	 * @param string $name Attribute display name.
	 * @return array<string,true>
	 */
	private static function folds( string $name ): array {
		if ( isset( self::$folds[ $name ] ) ) {
			return self::$folds[ $name ];
		}
		$set = array();
		foreach ( self::ACCENT_LOCALES as $locale ) {
			$set[ (string) sanitize_title_with_dashes( remove_accents( $name, $locale ), '', 'save' ) ] = true;
		}
		if ( count( self::$folds ) > 2000 ) {
			self::$folds = array();
		}
		self::$folds[ $name ] = $set;
		return $set;
	}

	/**
	 * Reset the per-request memo (tests).
	 *
	 * @since 8.0.40
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo        = array();
		self::$parent_maps = array();
		self::$folds       = array();
	}
}
