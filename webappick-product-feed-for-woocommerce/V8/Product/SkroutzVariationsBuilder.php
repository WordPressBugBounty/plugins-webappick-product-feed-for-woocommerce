<?php
/**
 * SkroutzVariationsBuilder — builds Skroutz's channel-specific NESTED
 * product structures that don't fit the generic grouped-attribute model:
 *
 *   1. <variations>  — a variable product's children nested inside ONE
 *      parent product (rather than separate product entries):
 *        <variations>
 *          <variation><variationid/><size/><quantity/><availability/></variation>
 *          …one <variation> per child…
 *        </variations>
 *
 *   2. <specifications> — free-form product specs, each an element with a
 *      `name` ATTRIBUTE and a text value:
 *        <specifications>
 *          <spec name="Material">Leather</spec>
 *          <spec name="Waterproof">Yes</spec>
 *        </specifications>
 *
 * Both are produced as plain nested arrays the XMLTemplate renderer turns
 * into the markup above (the <spec name="…"> attribute form uses the
 * renderer's `@attributes`/`@value` support).
 *
 * Gating (so it NEVER touches any other feed's hot path): provider must be
 * `skroutz`, and each sub-feature runs only when its own attributes are
 * mapped. A Skroutz feed that maps neither is returned untouched.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Transform\SkroutzTransform;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Skroutz per-variation and specifications nested structures.
 *
 * @since 8.0.0
 */
class SkroutzVariationsBuilder {

	/**
	 * Merchant variation sub-attribute (config key) → Skroutz child tag name.
	 *
	 * Required per Skroutz: variationid, size, quantity, availability.
	 * Optional: ean, mpn, price_with_vat, link, outlet.
	 *
	 * @var array<string,string>
	 */
	const VARIATION_MAP = array(
		'variation_id'             => 'variationid',
		'variation_size'           => 'size',
		'variation_quantity'       => 'quantity',
		'variation_availability'   => 'availability',
		'variation_ean'            => 'ean',
		'variation_mpn'            => 'mpn',
		'variation_price_with_vat' => 'price_with_vat',
		'variation_link'           => 'link',
		'variation_outlet'         => 'outlet',
	);

	/**
	 * Product repository used to resolve each child's mapped sub-fields.
	 *
	 * @var ProductRepository
	 */
	private $product_repo;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param ProductRepository $product_repo Product data resolver.
	 */
	public function __construct( ProductRepository $product_repo ) {
		$this->product_repo = $product_repo;
	}

	/**
	 * Attach Skroutz nested structures (specifications + variations) to a row.
	 *
	 * No-op for non-Skroutz feeds; each sub-feature self-gates on its own
	 * mapped attributes.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $data    Resolved + transformed parent product row.
	 * @param \WC_Product $product The live parent product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array Row with `specifications` and/or `variations` added.
	 */
	public function build( array $data, \WC_Product $product, Config $config ): array {
		if ( 'skroutz' !== $config->get( 'provider', '' ) ) {
			return $data;
		}

		$data = $this->build_specifications( $data, $config );
		$data = $this->attach_variations( $data, $product, $config );

		return $data;
	}

	/**
	 * Collect mapped specification_name/specification_value pairs into a <specifications> block.
	 *
	 * Each pair renders as <spec name="{specification_name}">{specification_value}</spec>. The
	 * pair may be mapped multiple times (ProductRepository dedups repeats with
	 * a `##N` suffix); pairs are matched by occurrence order. The flat
	 * specification_name/specification_value keys are stripped — they only exist as <spec>
	 * members, never as parent-level tags.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product row.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Row with `specifications` added (when any spec is mapped).
	 */
	private function build_specifications( array $data, Config $config ): array {
		$merchant_attrs = $config->get_merchant_attributes();
		if ( ! in_array( 'specification_name', $merchant_attrs, true ) && ! in_array( 'specification_value', $merchant_attrs, true ) ) {
			return $data;
		}

		$names  = array();
		$values = array();
		foreach ( $data as $key => $value ) {
			$base = ProductRepository::strip_dup_suffix( (string) $key );
			if ( 'specification_name' === $base ) {
				$names[] = (string) $value;
			} elseif ( 'specification_value' === $base ) {
				$values[] = (string) $value;
			}
		}

		foreach ( array_keys( $data ) as $key ) {
			$base = ProductRepository::strip_dup_suffix( (string) $key );
			if ( 'specification_name' === $base || 'specification_value' === $base ) {
				unset( $data[ $key ] );
			}
		}

		$specs = array();
		foreach ( $names as $i => $name ) {
			if ( '' === $name ) {
				continue; // Skroutz <spec> requires a name attribute.
			}
			$specs[] = array(
				'spec' => array(
					'@attributes' => array( 'name' => $name ),
					'@value'      => isset( $values[ $i ] ) ? $values[ $i ] : '',
				),
			);
		}

		if ( ! empty( $specs ) ) {
			$data['specifications'] = $specs;
		}

		return $data;
	}

	/**
	 * Attach the nested `variations` structure to a variable parent product.
	 *
	 * Runs only when the feed maps variation_* fields; strips those flat keys
	 * (they are per-child, never parent-level tags) and nests one <variation>
	 * per child.
	 *
	 * @since 8.0.0
	 *
	 * @param array       $data    Product row.
	 * @param \WC_Product $product The live parent product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array Row with `variations` added (variable products only).
	 */
	private function attach_variations( array $data, \WC_Product $product, Config $config ): array {
		$merchant_attrs = $config->get_merchant_attributes();
		$mapped_keys    = array_values(
			array_intersect( array_keys( self::VARIATION_MAP ), $merchant_attrs )
		);

		// Not a variations feed → leave the row exactly as it was.
		if ( empty( $mapped_keys ) ) {
			return $data;
		}

		// The variation_* fields are per-child; never emit them as flat
		// parent-level tags (resolve_single would have produced values for
		// the parent object). Strip them.
		foreach ( array_keys( self::VARIATION_MAP ) as $mkey ) {
			unset( $data[ $mkey ] );
		}

		// Only variable products carry a <variations> block.
		if ( ! $product->is_type( 'variable' ) ) {
			return $data;
		}

		$child_ids = $product->get_children();
		if ( empty( $child_ids ) ) {
			return $data;
		}

		// Prime every child's meta in ONE query so the per-child resolution
		// below reads from cache instead of a query per variation.
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $child_ids );
		}

		$variations = array();
		foreach ( $child_ids as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}

			$entry = $this->build_variation_entry( $child, $config, $mapped_keys );
			if ( ! empty( $entry ) ) {
				// Wrap each entry so the renderer emits repeated <variation>
				// elements inside the single <variations> wrapper.
				$variations[] = array( 'variation' => $entry );
			}
		}

		if ( ! empty( $variations ) ) {
			$data['variations'] = $variations;
		}

		return $data;
	}

	/**
	 * Resolve one child variation into a [skroutz_tag => value] map.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $child       Child variation product.
	 * @param Config      $config      Feed configuration.
	 * @param string[]    $mapped_keys Mapped variation_* merchant keys.
	 *
	 * @return array<string,string> Non-empty variation sub-fields.
	 */
	private function build_variation_entry( \WC_Product $child, Config $config, array $mapped_keys ): array {
		// Per-child authority: a variation resolves ONLY its own values (no
		// fall back to the parent), so e.g. a size with stock 0 stays 0
		// instead of inheriting the parent's summed quantity.
		$resolved = $this->product_repo->resolve_attributes_for( $child, $config, $mapped_keys, true );

		$entry = array();
		foreach ( self::VARIATION_MAP as $mkey => $tag ) {
			if ( ! array_key_exists( $mkey, $resolved ) ) {
				continue;
			}

			$value = (string) $resolved[ $mkey ];

			// Per-variation availability uses the same Skroutz delivery-time
			// wording as the product-level SkroutzTransform.
			if ( 'availability' === $tag && '' !== $value ) {
				$value = SkroutzTransform::format_availability( $value );
			}

			if ( '' === $value ) {
				continue;
			}

			$entry[ $tag ] = $value;
		}

		return $entry;
	}
}
