<?php
/**
 * AttributeResolver — Routes attribute resolution to the correct sub-resolver.
 *
 * Central routing hub that delegates to MetaResolver, TaxonomyResolver,
 * PriceResolver, ImageResolver, SEOResolver, CustomFieldResolver,
 * VariationResolver, WpOptionResolver, AttributeMappingResolver,
 * or DynamicAttributeResolver based on the mapping type.
 * All sub-resolvers are injected via
 * constructor (AD-PROD-002). Uses switch instead of match() for
 * WPCS/PHP 7.4 compatibility (AD-PROD-004).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-4.1, PROD-FRD-4.2, PROD-FRD-4.3, PROD-FRD-4.4
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Utility\Sanitizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute resolution router.
 *
 * @since 8.0.0
 */
class AttributeResolver {

	/**
	 * Meta resolver instance.
	 *
	 * @since 8.0.0
	 * @var MetaResolver
	 */
	private $meta;

	/**
	 * Taxonomy resolver instance.
	 *
	 * @since 8.0.0
	 * @var TaxonomyResolver
	 */
	private $taxonomy;

	/**
	 * Price resolver instance.
	 *
	 * @since 8.0.0
	 * @var PriceResolver
	 */
	private $price;

	/**
	 * Image resolver instance.
	 *
	 * @since 8.0.0
	 * @var ImageResolver
	 */
	private $image;

	/**
	 * SEO resolver instance.
	 *
	 * @since 8.0.0
	 * @var SEOResolver
	 */
	private $seo;

	/**
	 * Custom field resolver instance.
	 *
	 * @since 8.0.0
	 * @var CustomFieldResolver
	 */
	private $custom_field;

	/**
	 * Variation resolver instance.
	 *
	 * @since 8.0.0
	 * @var VariationResolver
	 */
	private $variation;

	/**
	 * Shipping resolver instance.
	 *
	 * @since 8.0.0
	 * @var ShippingResolver
	 */
	private $shipping;

	/**
	 * Tax resolver instance.
	 *
	 * @since 8.0.0
	 * @var TaxResolver
	 */
	private $tax;

	/**
	 * WordPress option resolver instance.
	 *
	 * @since 8.0.0
	 * @var WpOptionResolver
	 */
	private $wp_option;

	/**
	 * Attribute-mapping engine (Pro); null when the Pro plugin is absent.
	 *
	 * @since 8.0.0
	 * @var PrefixResolverInterface|null
	 */
	private $attr_mapping;

	/**
	 * Dynamic-attribute engine (Pro); null when the Pro plugin is absent.
	 *
	 * @since 8.0.0
	 * @var PrefixResolverInterface|null
	 */
	private $dynamic_attr;

	/**
	 * Prefix resolvers registered through `register_prefix_resolver()` /
	 * the `ctxfeed_attribute_prefix_resolvers` filter (CBT-642).
	 *
	 * @since 8.0.27
	 * @var PrefixResolverInterface[]
	 */
	private $prefix_resolvers = array();

	/**
	 * Source-name prefixes owned by Pro engines. When no resolver claims one
	 * of these (Pro absent), the value is '' — never a stray meta lookup.
	 *
	 * @since 8.0.27
	 * @var string[]
	 */
	private const PRO_PREFIXES = array( 'wf_dattribute_', 'wp_attr_mapping_', 'acf_fields_', 'toolset_fields_' );

	/**
	 * Pro prefixes already reported as unhandled in this request.
	 *
	 * @since 8.0.27
	 * @var array<string,bool>
	 */
	private static $unhandled_logged = array();

	/**
	 * V5-compat custom field resolver (woo_feed_* identifier path).
	 *
	 * Optional — falls back to bare MetaResolver when null so legacy
	 * test fixtures still construct cleanly without re-wiring.
	 *
	 * @since 8.0.0
	 * @var V5CustomFieldResolver|null
	 */
	private $v5_custom_field;

	/**
	 * V5-compat category-mapping resolver (wf_cmapping_* path).
	 *
	 * Optional — falls back to bare MetaResolver (returns empty) when
	 * null so legacy test fixtures still construct cleanly. Production
	 * wiring always passes it.
	 *
	 * @since 8.0.0
	 * @var CategoryMappingResolver|null
	 */
	private $category_mapping;

	/**
	 * Constructor.
	 *
	 * All sub-resolvers are injected via DI container (AD-PROD-002).
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-4.4
	 *
	 * @param MetaResolver                 $meta         Meta resolver.
	 * @param TaxonomyResolver             $taxonomy     Taxonomy resolver.
	 * @param PriceResolver                $price        Price resolver.
	 * @param ImageResolver                $image        Image resolver.
	 * @param SEOResolver                  $seo          SEO resolver.
	 * @param CustomFieldResolver          $custom_field Custom field resolver (ACF/Toolset).
	 * @param VariationResolver            $variation    Variation resolver.
	 * @param ShippingResolver             $shipping     Shipping resolver.
	 * @param TaxResolver                  $tax          Tax resolver.
	 * @param WpOptionResolver             $wp_option    WordPress option resolver.
	 * @param PrefixResolverInterface|null $attr_mapping Attribute mapping engine (Pro), or null.
	 * @param PrefixResolverInterface|null $dynamic_attr Dynamic attribute engine (Pro), or null.
	 * @param V5CustomFieldResolver|null   $v5_custom_field   V5-compat woo_feed_*
	 *                                                       resolver. Optional for
	 *                                                       back-compat with older
	 *                                                       tests; new wiring always
	 *                                                       passes it. PROD-FRD-10.2.
	 * @param CategoryMappingResolver|null $category_mapping V5-compat wf_cmapping_*
	 *                                                       resolver. Optional for
	 *                                                       back-compat. PROD-FRD-10.4.
	 */
	public function __construct(
		MetaResolver $meta,
		TaxonomyResolver $taxonomy,
		PriceResolver $price,
		ImageResolver $image,
		SEOResolver $seo,
		CustomFieldResolver $custom_field,
		VariationResolver $variation,
		ShippingResolver $shipping,
		TaxResolver $tax,
		WpOptionResolver $wp_option,
		?PrefixResolverInterface $attr_mapping = null,
		?PrefixResolverInterface $dynamic_attr = null,
		?V5CustomFieldResolver $v5_custom_field = null,
		?CategoryMappingResolver $category_mapping = null
	) {
		$this->meta             = $meta;
		$this->taxonomy         = $taxonomy;
		$this->price            = $price;
		$this->image            = $image;
		$this->seo              = $seo;
		$this->custom_field     = $custom_field;
		$this->variation        = $variation;
		$this->shipping         = $shipping;
		$this->tax              = $tax;
		$this->wp_option        = $wp_option;
		$this->attr_mapping     = $attr_mapping;
		$this->dynamic_attr     = $dynamic_attr;
		$this->v5_custom_field  = $v5_custom_field;
		$this->category_mapping = $category_mapping;
	}

	/**
	 * Register a prefix resolver (Pro engines, third parties).
	 *
	 * Registered resolvers are consulted after the constructor-injected
	 * mapping / dynamic-attribute engines, in registration order. The
	 * resolver receives this router so nested source attributes resolve.
	 *
	 * @since 8.0.27
	 * @implements PROD-FRD-10 (extension seam, CBT-642)
	 *
	 * @param PrefixResolverInterface $resolver Prefix resolver.
	 *
	 * @return void
	 */
	public function register_prefix_resolver( PrefixResolverInterface $resolver ): void {
		$this->prefix_resolvers[] = $resolver;
		$resolver->set_attribute_resolver( $this );
	}

	/**
	 * Registered prefix resolvers (constructor-injected engines excluded).
	 *
	 * @since 8.0.27
	 *
	 * @return PrefixResolverInterface[]
	 */
	public function get_prefix_resolvers(): array {
		return $this->prefix_resolvers;
	}

	/**
	 * Resolve a Pro-prefixed source attribute through the registered engines.
	 *
	 * Returns null when no resolver claims the name so the caller keeps its
	 * ordinary fall-through; returns '' (logged once per prefix and request)
	 * when the name carries a known Pro prefix that nothing handles — the
	 * graceful-degradation contract for a free install (CBT-642).
	 *
	 * @since 8.0.27
	 *
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $attr          Source attribute name.
	 * @param Config      $config        Feed configuration.
	 * @param string      $merchant_attr Channel field name.
	 *
	 * @return string|null
	 */
	private function resolve_prefixed( \WC_Product $product, string $attr, Config $config, string $merchant_attr ): ?string {
		foreach ( $this->prefix_resolvers as $resolver ) {
			if ( $resolver->handles( $attr ) ) {
				return $resolver->resolve( $product, $attr, $config, $merchant_attr );
			}
		}

		foreach ( self::PRO_PREFIXES as $prefix ) {
			if ( 0 === strpos( $attr, $prefix ) ) {
				if ( ! isset( self::$unhandled_logged[ $prefix ] ) ) {
					self::$unhandled_logged[ $prefix ] = true;
					Logger::debug(
						'Source attribute prefix has no resolver (CTX Feed Pro inactive?) — value left empty.',
						array(
							'prefix'    => $prefix,
							'attribute' => $attr,
						)
					);
				}
				return '';
			}
		}

		return null;
	}

	/**
	 * SEO-plugin attribute keys offered by the value picker (AttributeRegistry
	 * + SeoCompatibilityProvider dropdowns). In feed ROWS these resolve to raw
	 * meta and are then completed by the Legacy Bridge
	 * `woo_feed_filter_product_{attr}` filter that the compatibility/Shims/
	 * SEO classes answer. Mapping/dynamic-attribute paths bypass the bridge,
	 * so they must route through resolve_seo_bridged() (#69018 — Attribute
	 * Mapping printed the literal key name instead).
	 *
	 * @since 8.0.16
	 * @var array<string,bool>
	 */
	private const SEO_ATTRIBUTES = array(
		'yoast_wpseo_title'       => true,
		'yoast_wpseo_metadesc'    => true,
		'yoast_canonical_url'     => true,
		'yoast_primary_category'  => true,
		'yoast_gtin8'             => true,
		'yoast_gtin12'            => true,
		'yoast_gtin13'            => true,
		'yoast_gtin14'            => true,
		'yoast_isbn'              => true,
		'yoast_mpn'               => true,
		'rank_math_title'         => true,
		'rank_math_description'   => true,
		'rank_math_canonical_url' => true,
		'rank_math_gtin'          => true,
		'_aioseop_title'          => true,
		'_aioseop_description'    => true,
		'_aioseop_canonical_url'  => true,
	);

	/**
	 * Whether an attribute key is one of the SEO-plugin picker attributes.
	 *
	 * @since 8.0.16
	 *
	 * @param string $attr Attribute key.
	 * @return bool
	 */
	public static function is_seo_attribute( string $attr ): bool {
		return isset( self::SEO_ATTRIBUTES[ $attr ] );
	}

	/**
	 * Source attributes whose Legacy Bridge hook is fired ONCE by
	 * PriceResolver on the raw value (V5 timing). Firing it again on a
	 * resolved value double-applies a multiplicative currency shim
	 * (24 → 48 → 96) — the same list ProductRepository skips at row level.
	 *
	 * @since 8.0.28
	 * @var array<string,true>
	 */
	private const PRICE_FAMILY = array(
		'price'                  => true,
		'regular_price'          => true,
		'sale_price'             => true,
		'current_price'          => true,
		'price_with_tax'         => true,
		'regular_price_with_tax' => true,
		'sale_price_with_tax'    => true,
		'current_price_with_tax' => true,
	);

	/**
	 * Whether an attribute key belongs to the price family (bridge fired by
	 * PriceResolver, never again downstream).
	 *
	 * @since 8.0.28
	 *
	 * @param string $attr Attribute key.
	 * @return bool
	 */
	public static function is_price_family( string $attr ): bool {
		return isset( self::PRICE_FAMILY[ $attr ] );
	}

	/**
	 * Resolve an attribute exactly the way a feed ROW does: raw resolver
	 * value, then the Legacy Bridge `woo_feed_filter_product_{attr}` filter
	 * (same 4-arg signature as ProductRepository::resolve_with_filters,
	 * variation parent included) so every V5 compat listener — the
	 * TranslatePress / WPML / Polylang title shims, the SEO shims, customer
	 * snippets — computes the value it computes for a row.
	 *
	 * Used by AttributeMappingResolver and DynamicAttributeResolver for the
	 * PARTS they combine: their plain resolve() calls bypass the bridge, so a
	 * German feed shipped the untranslated title inside an attribute mapping
	 * while the same title translated as a direct row (#69269, CBT-662).
	 * V5 resolved parts through the ProductInfo getters that fired the
	 * filters, so this is parity restoration; 8.0.16 restored it for the
	 * SEO keys only (#69018), this generalises it.
	 *
	 * Price-family attributes are resolved WITHOUT the bridge — PriceResolver
	 * fires their hook once on the raw value, and a second fire is the
	 * double-apply currency bug (see PRICE_FAMILY). Deliberately NOT wired
	 * into the row path either — rows already fire the bridge once.
	 *
	 * @since 8.0.28
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Source attribute key.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	public function resolve_bridged( \WC_Product $product, string $attr, Config $config ): string {
		$value = $this->resolve(
			$product,
			array(
				'type'    => 'attribute',
				'wc_attr' => $attr,
				'default' => '',
			),
			$config
		);

		if ( self::is_price_family( $attr ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}

		$legacy_filter = "woo_feed_filter_product_{$attr}";

		if ( has_filter( $legacy_filter ) ) {
			$parent_product = null;
			if ( $product->is_type( 'variation' ) ) {
				$parent_id      = $product->get_parent_id();
				$parent_product = $parent_id ? ProductMemo::get( (int) $parent_id ) : null;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built above as "woo_feed_filter_product_{$attr}", always carrying the registered woo_feed prefix; the sniff cannot resolve the variable.
			$value = apply_filters( $legacy_filter, $value, $product, $config, $parent_product );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Resolve an SEO attribute through the Legacy Bridge (#69018).
	 *
	 * Kept as an alias of {@see resolve_bridged()} for callers written
	 * against 8.0.16–8.0.27; the general method covers every attribute.
	 *
	 * @since 8.0.16
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    SEO attribute key.
	 * @param Config      $config  Feed configuration.
	 * @return string
	 */
	public function resolve_seo_bridged( \WC_Product $product, string $attr, Config $config ): string {
		return $this->resolve_bridged( $product, $attr, $config );
	}

	/**
	 * Resolve a single attribute value for a product based on mapping config.
	 *
	 * Routes to the appropriate sub-resolver based on mapping type,
	 * applies variation fallback for empty values, then applies default.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-4.1, PROD-FRD-4.3
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $mapping Attribute mapping (type, wc_attr, default, value).
	 * @param Config      $config  Feed configuration.
	 *
	 * @return mixed Resolved attribute value.
	 */
	public function resolve( \WC_Product $product, array $mapping, Config $config ) {
		$type          = isset( $mapping['type'] ) ? $mapping['type'] : 'attribute';
		$wc_attr       = isset( $mapping['wc_attr'] ) ? $mapping['wc_attr'] : '';
		$merchant_attr = isset( $mapping['merchant_attr'] ) ? (string) $mapping['merchant_attr'] : '';

		// @implements PROD-FRD-4.1 — Type-based routing (AD-PROD-004: switch not match).
		switch ( $type ) {
			case 'attribute':
				$value = $this->resolve_wc_attribute( $product, $wc_attr, $config, $merchant_attr );
				break;

			case 'taxonomy':
				$value = $this->taxonomy->resolve( $product, $wc_attr, $config );
				break;

			case 'custom_field':
				// A custom_field row may carry a Pro picker prefix (acf_fields_ /
				// toolset_fields_): the registered prefix engine owns it, and an
				// unclaimed Pro prefix ships '' (CBT-652).
				$value = $this->resolve_prefixed( $product, (string) $wc_attr, $config, $merchant_attr );
				if ( null === $value ) {
					$value = $this->custom_field->resolve( $product, $wc_attr, $config );
				}
				break;

			case 'pattern':
			case 'text': // V8-UI alias briefly written for static rows — same semantics.
				$value = isset( $mapping['value'] ) ? $mapping['value'] : ( isset( $mapping['default'] ) ? $mapping['default'] : '' );
				// Feeds saved on 8.0.16–8.0.23 stored `>` / `&` as entities
				// (CBT-607): restore the typed characters at generation so
				// existing feeds heal without an option rewrite.
				$value = Sanitizer::restore_text( $value );
				break;

			case 'image':
				$value = $this->image->resolve( $product, $wc_attr, $config );
				break;

			case 'price':
				$value = $this->price->resolve( $product, $wc_attr, $config );
				break;

			case 'seo':
				$value = $this->seo->resolve( $product, $wc_attr, $config );
				break;

			case 'shipping':
				$value = $this->shipping->resolve( $product, $wc_attr, $config );
				break;

			case 'tax':
				$value = $this->tax->resolve( $product, $wc_attr, $config );
				break;

			default:
				// Unknown mapping types are resolvable by extensions via this dynamic hook.
				$value = apply_filters( "ctxfeed_resolve_attribute_{$type}", '', $product, $mapping, $config );
				break;
		}

		// @implements PROD-FRD-4.3 — Variation fallback for empty values.
		//
		// The SALE-price family is excluded: an empty sale value on a
		// variation MEANS "this variant is not discounted". Re-resolving
		// against the variable parent would return the MINIMUM of all
		// variations' sale prices (WC_Product_Variable semantics) — i.e.
		// a SIBLING's discount — telling Google a full-price variant is
		// on sale. Same reasoning for the sale date fields.
		// The GALLERY image family is excluded too (owner decision
		// 2026-09-05, #68988): a variation ships only its OWN gallery —
		// empty means empty, never the parent's images. Stores that want
		// the parent gallery opt in per attribute with the "parent if
		// empty" output command (code 20). The main `image` /
		// `feature_image` attributes are NOT listed: their parent-thumbnail
		// behavior is handled inside ImageResolver and matches the
		// storefront.
		static $no_parent_fallback = array(
			'sale_price',
			'sale_price_with_tax',
			'sale_price_sdate',
			'sale_price_edate',
			'sale_price_effective_date',
			'images',
			'image_1',
			'image_2',
			'image_3',
			'image_4',
			'image_5',
			'image_6',
			'image_7',
			'image_8',
			'image_9',
			'image_10',
		);

		// A legitimate ZERO is not "empty": V5's fallbacks triggered on
		// `'' === $value` only, so quantity 0 (out of stock) stayed 0.
		// `empty()` alone would inherit the PARENT's value for it —
		// wrong data (e.g. an out-of-stock variation reporting the
		// variable parent's aggregate stock).
		$is_zero = 0 === $value || 0.0 === $value || '0' === $value;

		if (
			empty( $value )
			&& ! $is_zero
			&& $product->is_type( 'variation' )
			&& empty( $mapping['no_variation_fallback'] )
				&& ! in_array( (string) ( $mapping['wc_attr'] ?? '' ), $no_parent_fallback, true )
		) {
			$value = $this->variation->resolve_from_parent( $product, $mapping, $config );
		}

		// Default value fallback.
		if ( empty( $value ) && ! empty( $mapping['default'] ) ) {
			$value = $mapping['default'];
		}

		return $value;
	}

	/**
	 * Resolve standard WooCommerce product attributes.
	 *
	 * Maps common attribute names to WC product getter methods.
	 * Falls back to MetaResolver for unknown attributes.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-4.2
	 *
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $attr          Attribute name.
	 * @param Config      $config        Feed configuration.
	 * @param string      $merchant_attr Optional. Original merchant attribute name — forwarded to
	 *                                   DynamicAttributeResolver for condition context. Default ''.
	 *
	 * @return mixed Resolved attribute value.
	 */
	private function resolve_wc_attribute( \WC_Product $product, string $attr, Config $config, string $merchant_attr = '' ) {
		/**
		 * Short-circuit a WooCommerce source attribute before the built-in
		 * accessor switch (CBT-642 extension seam — Pro engines answer the
		 * subscription / installment value groups here).
		 *
		 * @since 8.0.27
		 *
		 * @param mixed       $pre     Null to continue; any other value is returned as-is.
		 * @param string      $attr    Source attribute name.
		 * @param \WC_Product $product WooCommerce product.
		 * @param Config      $config  Feed configuration.
		 */
		$pre = apply_filters( 'ctxfeed_pre_resolve_wc_attribute', null, $attr, $product, $config );
		if ( null !== $pre ) {
			return $pre;
		}

		// AD-PROD-004: switch instead of match() for WPCS compatibility.
		switch ( $attr ) {
			case 'id':
				return $product->get_id();

			case 'name':
			case 'title':
				// Variations: translation compat (TranslatePress via
				// ctx-compatibility) can construct the title from parts
				// — V5 6.6.x woo_feed_filter_variation_title_with_attributes.
				// Null from the filter keeps WC's own variation name.
				if ( $product->is_type( 'variation' ) ) {
					$parent          = ProductMemo::get( (int) $product->get_parent_id() );
					$variation_parts = array();
					foreach ( array_keys( (array) $product->get_attributes() ) as $attr_slug ) {
						$attr_value = $product->get_attribute( $attr_slug );
						if ( '' === $attr_value ) {
							continue;
						}
						// Translation plugins (WPML/WCML) translate CUSTOM
						// (per-product) attribute values only in WooCommerce's
						// display layer — a translated variation's stored meta
						// keeps the original language. Mirror
						// wc_get_formatted_variation() and run each value
						// through the display filter (BUG-0101 / CBT-592).
						$attr_value = (string) apply_filters( 'woocommerce_variation_option_name', $attr_value, null, $attr_slug, $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing WooCommerce core's display filter so WCML-style handlers run.
						if ( '' !== $attr_value ) {
							$variation_parts[] = $attr_value;
						}
					}

					$base_title = $parent instanceof \WC_Product ? $parent->get_title() : $product->get_name();
					$separator  = apply_filters( 'woo_feed_attribute_separator', ' , ', $config, $product );
					$merger     = apply_filters( 'woo_feed_product_title_and_attributes_merger', ' - ', $product, $config );
					$attr_text  = implode( $separator, $variation_parts );

					$filtered = apply_filters(
						'woo_feed_filter_variation_title_with_attributes',
						null,
						$base_title,
						$attr_text,
						$merger,
						$product,
						$config
					);

					if ( null !== $filtered ) {
						return $filtered;
					}

					// Build the title ourselves (V5 parity) instead of trusting
					// WC_Product_Variation::get_name(): WooCommerce appends the
					// attribute summary only when a variation has FEWER THAN 3
					// attributes (and none with a hyphenated name), so products
					// with size + colour + style shipped the bare parent title
					// while simpler ones got "Parent - Blue, L" (support #68899).
					if ( '' !== $attr_text ) {
						return $base_title . $merger . $attr_text;
					}
					return $base_title;
				}
				return $product->get_name();

			// Parent product's clean title (variations only). Google's
			// `item_group_title` needs the group's own title WITHOUT
			// WooCommerce's auto-appended variant attributes, so we
			// cannot re-use `title` here — WC returns e.g.
			// "Slim Fit Shirt - Blue" for a variation's own name, but
			// the group title needs to be "Slim Fit Shirt" (same across
			// all variants in the group). Returns empty for non-variation
			// products; V5-compat filter fires so ctx-compatibility shims
			// (WPML/TranslatePress) can translate the parent's title.
			// PROD-FRD-10.10.
			case 'parent_title':
				if ( $product->is_type( 'variation' ) ) {
					$parent = ProductMemo::get( (int) $product->get_parent_id() );
					$title  = $parent instanceof \WC_Product ? $parent->get_name() : '';
				} else {
					// V5 parity (CBT-663, #69269): a simple/variable product
					// has no parent, and V5's parent_title() fell back to the
					// product's OWN title — V8 shipped '' and every mixed
					// catalog mapping Title ← Parent Title lost its simple
					// products' titles.
					$title = (string) $product->get_name();
				}
				return apply_filters(
					'woo_feed_filter_product_parent_title',
					$title,
					$product,
					$config
				);

			case 'description':
				// Plain description ships as PLAIN TEXT (V5 parity): strip
				// shortcode leftovers, strip HTML tags (entities decoded
				// first), then collapse runs of whitespace — so a page-builder
				// / Gutenberg description does not dump layout markup + blank
				// lines into the feed. Merchants who need the markup use
				// `description_with_html` (and can add the "Remove page-builder
				// markup" command to clean it further).
				$plain_desc = (string) $product->get_description();
				if ( function_exists( 'strip_shortcodes' ) ) {
					$plain_desc = (string) strip_shortcodes( $plain_desc );
				}
				$plain_desc = wp_strip_all_tags( html_entity_decode( $plain_desc, ENT_QUOTES ) );
				// Invalid UTF-8 (wrong-charset import) made the /u collapse
				// return null and the whole description vanish (CBT-694).
				$plain_desc = trim( (string) preg_replace( '/\s+/u', ' ', \CTXFeed\V8\Utility\Utf8::scrub( $plain_desc ) ) );
				return apply_filters( 'woo_feed_filter_product_description', $plain_desc, $product, $config );

			// V5 attribute: `description_with_html` — preserves HTML
			// markup (unlike `description` which strips tags above). Falls
			// back to short_description on empty; variations fall back to the
			// parent's descriptions. V5 ProductInfo.php:207-227. Exposed in the
			// source dropdown as "Product Description (with HTML)"
			// (AttributeRegistry::get_primary_attributes).
			case 'description_with_html':
				$desc = $product->get_description();
				if ( '' === $desc ) {
					$desc = $product->get_short_description();
				}
				if ( $product->is_type( 'variation' ) ) {
					$parent_prod = ProductMemo::get( (int) $product->get_parent_id() );
					if ( $parent_prod instanceof \WC_Product ) {
						$desc = $parent_prod->get_description();
						if ( '' === $desc ) {
							$desc = $parent_prod->get_short_description();
						}
					}
				}
				$desc = wp_check_invalid_utf8( wp_specialchars_decode( $desc ), true );
				return apply_filters(
					'woo_feed_filter_product_description_with_html',
					$desc,
					$product,
					$config
				);

			// V5 attribute: `parent_description` — for variations, returns
			// the parent product's description; otherwise the product's
			// own. V5 ProductInfo.php:191-199.
			case 'parent_description':
				$parent_desc = $product->get_description();
				if ( $product->is_type( 'variation' ) ) {
					$parent_prod = ProductMemo::get( (int) $product->get_parent_id() );
					if ( $parent_prod instanceof \WC_Product ) {
						$parent_desc = $parent_prod->get_description();
					}
				}
				return apply_filters(
					'woo_feed_filter_product_parent_description',
					$parent_desc,
					$product,
					$config
				);

			case 'short_description':
				return $product->get_short_description();

			case 'sku':
				return $product->get_sku();

			// V5 attribute: `sku_id` — SKU when present, otherwise the
			// product's numeric ID. V5 ProductInfo.php:793-802.
			case 'sku_id':
				$sku_id = $product->get_sku();
				if ( '' === $sku_id ) {
					$sku_id = (string) $product->get_id();
				}
				return apply_filters(
					'woo_feed_filter_product_sku_id',
					$sku_id,
					$product,
					$config
				);

			// V5 attribute: `parent_sku` — parent product's SKU on
			// variations (helpful when variations share a parent MPN).
			// Falls back to own SKU otherwise. V5 ProductInfo.php:810-818.
			case 'parent_sku':
				$parent_sku = $product->get_sku();
				if ( $product->is_type( 'variation' ) ) {
					$parent_prod = ProductMemo::get( (int) $product->get_parent_id() );
					if ( $parent_prod instanceof \WC_Product ) {
						$parent_sku = $parent_prod->get_sku();
					}
				}
				return apply_filters(
					'woo_feed_filter_product_parent_sku',
					$parent_sku,
					$product,
					$config
				);

			case 'link':
			case 'url':
				return $product->get_permalink();

			// V5 attribute: `canonical_link` — permalink with a V5-specific
			// filter so canonical-URL rewriters (SEO plugins, hreflang shims)
			// can transform it independently of the plain link. V5 ProductInfo.php:540-548.
			case 'canonical_link':
				return apply_filters(
					'woo_feed_filter_product_canonical_link',
					$product->get_permalink(),
					$product,
					$config
				);

			// V5 attribute: `parent_link` — for variations, points at the
			// parent product's page. Non-variations → own permalink. V5
			// ProductInfo.php:560-570.
			case 'parent_link':
				$parent_link = $product->get_permalink();
				if ( $product->is_type( 'variation' ) ) {
					$parent_prod = ProductMemo::get( (int) $product->get_parent_id() );
					if ( $parent_prod instanceof \WC_Product ) {
						$parent_link = $parent_prod->get_permalink();
					}
				}
				return apply_filters(
					'woo_feed_filter_product_parent_link',
					$parent_link,
					$product,
					$config
				);

			// V5 attribute: `external_link` / `ex_link` — external-product
			// destination URL, empty for other product types. Some V5
			// customer configs use `ex_link` as the attribute key. V5
			// ProductInfo.php:580-586.
			case 'external_link':
			case 'ex_link':
				$ext_url = '';
				if ( 'external' === $product->get_type() && method_exists( $product, 'get_product_url' ) ) {
					$ext_url = $product->get_product_url();
				}
				return apply_filters(
					'woo_feed_filter_product_ex_link',
					$ext_url,
					$product,
					$config
				);

			// V5 parity: availability maps the raw WC stock status —
			// including `onbackorder` → 'backorder' (Google's required
			// enum). The previous is_in_stock() shortcut was wrong: WC
			// returns TRUE for backordered products, which emitted
			// 'in stock' for items that cannot ship. V5 ProductInfo.php:827-839.
			case 'availability':
				$status = $product->get_stock_status();
				if ( 'instock' === $status ) {
					$status = 'in stock';
				} elseif ( 'outofstock' === $status ) {
					$status = 'out of stock';
				} elseif ( 'onbackorder' === $status ) {
					$status = 'backorder';
				}
				return apply_filters( 'woo_feed_filter_product_availability', $status, $product, $config );

			case 'stock_quantity':
				return $product->get_stock_quantity();

			case 'stock_status':
				return $product->get_stock_status();

			// @implements G-06 — Weight/dimension with unit suffix for feed compliance.
			case 'weight':
				$weight = $product->get_weight();
				if ( '' === $weight || null === $weight ) {
					return '';
				}
				$weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );
				return $weight . ' ' . $weight_unit;

			// V5 attribute: `weight_unit` — the store's WC weight unit
			// setting on its own (no value). Some feed specs (Skroutz,
			// certain marketplaces) require a separate unit column. V5
			// ProductInfo.php:1057-1059.
			case 'weight_unit':
				return apply_filters(
					'woo_feed_filter_product_weight_unit',
					get_option( 'woocommerce_weight_unit', 'kg' ),
					$product,
					$config
				);

			// V5 attribute: `checkout_link_template` — deep-link URL that
			// adds the product ID to the checkout (XML) or cart (CSV/JSON)
			// page, used by Google's `<g:checkout_link_template>` spec.
			// V5 ProductInfo.php:1114-1122.
			case 'checkout_link_template':
				$feed_type = strtolower( (string) $config->get( 'feedType', 'xml' ) );
				if ( 'xml' === $feed_type ) {
					$checkout_link_url = wc_get_checkout_url() . $product->get_id();
				} else {
					$checkout_link_url = wc_get_page_permalink( 'cart' ) . '?productId=' . $product->get_id();
				}
				return apply_filters(
					'woo_feed_filter_product_checkout_link_url',
					$checkout_link_url,
					$product,
					$config
				);

			case 'length':
				$length = $product->get_length();
				if ( '' === $length || null === $length ) {
					return '';
				}
				$dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
				return $length . ' ' . $dim_unit;

			case 'width':
				$width = $product->get_width();
				if ( '' === $width || null === $width ) {
					return '';
				}
				$dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
				return $width . ' ' . $dim_unit;

			case 'height':
				$height = $product->get_height();
				if ( '' === $height || null === $height ) {
					return '';
				}
				$dim_unit = get_option( 'woocommerce_dimension_unit', 'cm' );
				return $height . ' ' . $dim_unit;

			case 'type':
				return $product->get_type();

			case 'slug':
				return $product->get_slug();

			case 'date_created':
				$date = $product->get_date_created();
				return $date ? $date->date( 'Y-m-d' ) : '';

			// Price attributes — delegate to PriceResolver.
			// V5 config stores type='attribute' for ALL attributes (including prices),
			// so price attrs arrive here instead of the 'price' case in resolve().
			// V5 ProductInfo method names: price(), current_price(), sale_price(),
			// price_with_tax(), current_price_with_tax(), sale_price_with_tax().
			case 'price':
			case 'regular_price':
				return $this->price->resolve( $product, 'regular_price', $config );

			case 'current_price':
				return $this->price->resolve( $product, 'price', $config );

			case 'sale_price':
				return $this->price->resolve( $product, 'sale_price', $config );

			case 'price_with_tax':
			case 'regular_price_with_tax':
				return $this->price->resolve( $product, 'regular_price_with_tax', $config );

			case 'current_price_with_tax':
				return $this->price->resolve( $product, 'price_with_tax', $config );

			case 'sale_price_with_tax':
				return $this->price->resolve( $product, 'sale_price_with_tax', $config );

			case 'price_excluding_tax':
				return $this->price->resolve( $product, 'price_excluding_tax', $config );

			// Sale price date attributes — V5 ProductInfo methods that use WC getters.
			case 'sale_price_effective_date':
				$from = $product->get_date_on_sale_from();
				$to   = $product->get_date_on_sale_to();
				if ( $from && $to ) {
					return gmdate( 'c', $from->getTimestamp() ) . '/' . gmdate( 'c', $to->getTimestamp() );
				}
				return '';

			case 'sale_price_sdate':
				$from = $product->get_date_on_sale_from();
				return $from instanceof \WC_DateTime ? $from->date_i18n() : '';

			case 'sale_price_edate':
				$to = $product->get_date_on_sale_to();
				return $to instanceof \WC_DateTime ? $to->date_i18n() : '';

			// V5 parity: `product_type` is a deprecated alias for `categories`
			// (V5 ProductInfo::product_type() delegates to categories()).
			// Google's g:product_type expects the store category path
			// ("Apparel > Shirts"), NEVER WC's internal type string
			// ('simple'/'variable') — the default Google template maps
			// this source, so returning get_type() would ship invalid
			// data to every default-configured feed. V5 ProductInfo.php:286-293.
			case 'product_type':
				return $this->taxonomy->resolve( $product, 'categories', $config );

			// V5 parity (WooFeedProducts): every product carries an
			// item_group_id — a variation shares its parent variable product's
			// id, and every other product type (simple, variable parent,
			// grouped, external) uses its OWN id. Google accepts a
			// self-referential group id, so a store migrating from V5 keeps its
			// populated column instead of the empty one V8 used to emit.
			case 'item_group_id':
				return $product->is_type( 'variation' )
					? (string) $product->get_parent_id()
					: (string) $product->get_id();

			// `parent_id` is the literal parent product id: the variable
			// product for a variation, empty for anything with no parent.
			case 'parent_id':
				return $product->is_type( 'variation' ) ? (string) $product->get_parent_id() : '';

			// The product's DEFAULT-LANGUAGE counterpart id. This is V5's
			// `parent_id` attribute semantics restored under an honest name
			// (#69001 — Meta language-override feeds key on the primary
			// catalog's ids): the Pro WPML shim answers the V5 filter with
			// wpml_object_id(default language); Polylang/none leave the
			// filter unfiltered, so the product's OWN id returns — never
			// empty.
			case 'default_language_id':
				return (string) apply_filters( 'woo_feed_original_post_id', $product->get_id(), $product, $config );

			case 'quantity':
				return $this->resolve_quantity( $product, $config );

			case 'currency':
				return get_woocommerce_currency();

			// V5 attribute: `condition` — always 'new' by default, but
			// V5 fires the NON-STANDARD filter `woo_feed_product_condition`
			// (no "filter_" prefix). Compat shims that support refurbished
			// / used products hook this name. V5 ProductInfo.php:656-658.
			case 'condition':
				return apply_filters(
					'woo_feed_product_condition',
					'new',
					$product,
					$config
				);

			case 'date_updated':
				$date = $product->get_date_modified();
				return $date ? $date->date( 'Y-m-d' ) : '';

			case 'visibility':
				return $product->get_catalog_visibility();

			case 'rating_total':
				return (string) $product->get_rating_count();

			case 'rating_average':
				return (string) $product->get_average_rating();

			case 'total_sold':
				return (string) $product->get_total_sales();

			case 'tags':
				// Variations carry no tags of their own — read the parent's
				// (V5 parity: ProductInfo::tags() used the parent id). Without
				// this every "Tags contains …" dynamic-attribute rule failed on
				// variable products (support #68948).
				return $this->resolve_term_names( $product, 'product_tag' );

			// WooCommerce's core Brands taxonomy (product_brand, WC 9.6+).
			// Free stores could not select it (taxonomy groups are Pro-gated)
			// and mapped CTX Feed's own empty Brand field instead (#68893).
			case 'wc_brand':
			case 'product_brand':
				return $this->resolve_term_names( $product, 'product_brand' );

			// Parent / child level of the (hierarchical) WooCommerce brand.
			case 'wc_brand_parent':
			case 'wc_brand_child':
				return $this->taxonomy->resolve( $product, $attr, $config );

			case 'shipping_class':
				return $product->get_shipping_class();

			case 'tax_class':
				return $product->get_tax_class();

			case 'tax_status':
				return $product->get_tax_status();

			case 'featured_status':
				return $product->is_featured() ? 'yes' : 'no';

			case 'add_to_cart_link':
				return $product->add_to_cart_url();

			// V5 attribute: `is_bundle` — checks the 5 known WC bundle
			// plugin type names V5 ships with support for. V8 previously
			// only recognised the WooCommerce Product Bundles type,
			// silently returning 'no' for YITH / WooSB / Easy Product
			// Bundles catalogs. V5 ProductInfo.php:676-685.
			case 'is_bundle':
				// Bundle-plugin type detection is supplied by the compat layer
				// (ProductTypeCompatibilityProvider); the bundle type slugs
				// (bundle/bundled/yith_bundle/woosb/easy_product_bundle) no
				// longer live in core. @hook ctxfeed_resolve_is_bundle.
				$is_bundle = apply_filters( 'ctxfeed_resolve_is_bundle', 'no', $product, $config );
				return apply_filters(
					'woo_feed_filter_product_is_bundle',
					$is_bundle,
					$product,
					$config
				);

			// V5 attribute: `multipack` — for grouped products, the child
			// count. Empty for other product types. Compat shims map this
			// to Google's <g:multipack> spec. V5 ProductInfo.php:693-701.
			case 'multipack':
				$is_multipack = '';
				if ( $product->is_type( 'grouped' ) ) {
					$children = $product->get_children();
					if ( ! empty( $children ) ) {
						$is_multipack = count( $children );
					}
				}
				return apply_filters(
					'woo_feed_filter_product_is_multipack',
					$is_multipack,
					$product,
					$config
				);

			case 'product_status':
				return get_post_status( $product->get_id() );

			// @implements G-05 — identifier_exists: "yes" if 2+ identifiers present.
			case 'identifier_exists':
				return $this->resolve_identifier_exists( $product, $config );

			// Shipping/tax — delegate to resolvers (V5 configs use type='attribute').
			// @implements G-01, G-02.
			case 'shipping':
				return $this->shipping->resolve( $product, $attr, $config );

			case 'tax':
				return $this->tax->resolve( $product, $attr, $config );

			// @implements G-07 / G-08 — Google installment_* and subscription_*
			// values are Pro picker groups: resolved by the Pro
			// SubscriptionValues engine through ctxfeed_pre_resolve_wc_attribute
			// (CBT-652); without it they fall through to the meta resolver.

			// @implements S-05 — Excluded/included destination.
			case 'excluded_destination':
			case 'included_destination':
				$meta_val = get_post_meta( $product->get_id(), '_ctxfeed_' . $attr, true );
				return ! empty( $meta_val ) ? $meta_val : $config->get( $attr, '' );

			// @implements F-05 — Facebook product category.
			case 'fb_product_category':
				$meta_val = get_post_meta( $product->get_id(), '_ctxfeed_fb_product_category', true );
				if ( ! empty( $meta_val ) ) {
					return $meta_val;
				}
				return $this->taxonomy->resolve( $product, 'primary_category', $config );

			// Product highlights (Google).
			case 'product_highlight_1':
			case 'product_highlight_2':
			case 'product_highlight_3':
			case 'product_highlight_4':
			case 'product_highlight_5':
			case 'product_highlight_6':
			case 'product_highlight_7':
			case 'product_highlight_8':
			case 'product_highlight_9':
			case 'product_highlight_10':
				return get_post_meta( $product->get_id(), '_ctxfeed_' . $attr, true );

			// Shipping handling/transit time attributes.
			case 'min_handling_time':
			case 'max_handling_time':
			case 'min_transit_time':
			case 'max_transit_time':
			case 'shipping_label':
				$meta_val = get_post_meta( $product->get_id(), '_ctxfeed_' . $attr, true );
				return ! empty( $meta_val ) ? $meta_val : $config->get( $attr, '' );

			// @implements G-06 — availability_date: Google only accepts
			// it for backorder/preorder products, and V5 gates it behind
			// the identifier settings (ProductInfo::availability_date).
			case 'availability_date':
				return $this->resolve_availability_date( $product, $config );

			// @implements G-08 — Google unit pricing (V5 ProductInfo::
			// unit_price_measure / unit_price_base_measure): "{measure}
			// {unit}" composed from the CTX Feed product-panel fields,
			// gated by the identifier settings, with a WooCommerce
			// Germanized fallback.
			case 'unit_price_unit':
				return $this->resolve_plugin_custom_field( $product, 'woo_feed_unit', $config );

			case 'unit_price_measure':
				return $this->resolve_unit_price_measure(
					$product,
					$config,
					'woo_feed_unit_pricing_measure',
					'_unit_product',
					'woo_feed_filter_unit_price_measure'
				);

			case 'unit_price_base_measure':
				return $this->resolve_unit_price_measure(
					$product,
					$config,
					'woo_feed_unit_pricing_base_measure',
					'_unit_base',
					'woo_feed_filter_unit_price_base_measure'
				);

			// WooCommerce Germanized raw fields (V5 ProductInfo::
			// wc_germanized_*). No settings gate — the dropdown only
			// offers them when the plugin is active.
			case 'wc_germanized_unit_price_measure':
				return apply_filters( 'woo_feed_filter_wc_germanized_unit_price_measure', (string) apply_filters( 'ctxfeed_resolve_germanized_field', '', $product, '_unit_product', $config ), $product, $config );

			case 'wc_germanized_unit_price_base_measure':
				return apply_filters( 'woo_feed_filter_wc_germanized_unit_price_base_measure', (string) apply_filters( 'ctxfeed_resolve_germanized_field', '', $product, '_unit_base', $config ), $product, $config );

			case 'wc_germanized_gtin':
				return apply_filters( 'woo_feed_filter_wc_germanized_gtin', (string) apply_filters( 'ctxfeed_resolve_germanized_field', '', $product, '_ts_gtin', $config ), $product, $config );

			case 'wc_germanized_mpn':
				return apply_filters( 'woo_feed_filter_wc_germanized_mpn', (string) apply_filters( 'ctxfeed_resolve_germanized_field', '', $product, '_ts_mpn', $config ), $product, $config );

			// Image attributes — delegate to ImageResolver.
			// V5 stores type='attribute' for images too. The 'image' type case
			// in resolve() never fires for V5 configs.
			case 'image':
			case 'images':
			case 'feature_image':
			case 'image_1':
			case 'image_2':
			case 'image_3':
			case 'image_4':
			case 'image_5':
			case 'image_6':
			case 'image_7':
			case 'image_8':
			case 'image_9':
			case 'image_10':
				return $this->image->resolve( $product, $attr, $config );

			// Category attributes — use taxonomy resolution.
			case 'categories':
			case 'product_full_cat':
			case 'primary_category':
			case 'primary_category_id':
			case 'child_category':
			case 'child_category_id':
				return $this->taxonomy->resolve( $product, $attr, $config );

			// Post author is a WP_User (never post meta), so the generic meta
			// fallback can never find it. V5 ProductInfo::author_name/email. BUG-0050.
			case 'author_name':
				return $this->resolve_author_field( $product, 'user_login', 'author_name', $config );

			case 'author_email':
				return $this->resolve_author_field( $product, 'user_email', 'author_email', $config );

			// Most recent approved review author. V5 offered this option but never
			// resolved it - reviews live in the comments table, not post meta. BUG-0050.
			case 'reviewer_name':
				return $this->resolve_reviewer_name( $product, $config );

			// WooCommerce 8.x native product identifier. V5 ProductInfo::gtin_upc_ean_isbn.
			case 'gtin_upc_ean_isbn':
				$gtin = get_post_meta( $product->get_id(), '_global_unique_id', true );
				if ( '' === (string) $gtin && $product->is_type( 'variation' ) ) {
					$gtin = get_post_meta( $product->get_parent_id(), '_global_unique_id', true );
				}
				return apply_filters( 'woo_feed_filter_product_gtin_upc_ean_isbn', (string) $gtin, $product, $config );

			// Custom Template 2 (XML) sources. In V5 these fed a per-element loop in
			// Custom2Template; V8 has no such loop yet, so resolve a scalar (comma-joined)
			// so the data is present rather than silently empty. BUG-0050.
			case 'custom_xml_categories':
				$cat_product = $product;
				if ( $product->is_type( 'variation' ) ) {
					$cat_parent = ProductMemo::get( (int) $product->get_parent_id() );
					if ( $cat_parent instanceof \WC_Product ) {
						$cat_product = $cat_parent;
					}
				}
				return $this->taxonomy->resolve( $cat_product, 'product_full_cat', $config );

			case 'custom_xml_images':
				return $this->image->resolve( $product, 'images', $config );

			case 'custom_xml_variations':
				return $this->resolve_custom_xml_variations( $product, $config );

			default:
				// WooCommerce product attributes (wf_attr_* prefix).
				// Handles both global (taxonomy-based, e.g., pa_color) and
				// custom product-level attributes. Uses WC's get_attribute()
				// which resolves both types. Falls back to parent for variations.
				// @implements PROD-FRD-4.2.
				if ( 0 === strpos( $attr, 'wf_attr_' ) ) {
					return $this->resolve_wc_product_attribute( $product, $attr, $config );
				}

				// WooCommerce custom fields / post meta (wf_cattr_* prefix).
				// These are raw post meta values, not WC product attributes.
				if ( 0 === strpos( $attr, 'wf_cattr_' ) ) {
					// V5-compat: when the underlying meta key is a `woo_feed_*`
					// identifier (e.g. wf_cattr_woo_feed_gtin), route through
					// V5CustomFieldResolver so the variation _var suffix,
					// legacy old-key fallback, and availability_date ISO
					// formatting all run. PROD-FRD-10.2.
					if ( null !== $this->v5_custom_field && V5CustomFieldResolver::handles( $attr ) ) {
						return $this->v5_custom_field->resolve( $product, $attr, $config );
					}

					$meta_key = str_replace( 'wf_cattr_', '', $attr );
					$value    = $this->meta->resolve( $product, $meta_key, $config );

					// Variation fallback to parent.
					if ( '' === $value && $product->is_type( 'variation' ) ) {
						$parent = ProductMemo::get( (int) $product->get_parent_id() );
						if ( $parent instanceof \WC_Product ) {
							$value = $this->meta->resolve( $parent, $meta_key, $config );
						}
					}

					// Rank Math stores its primary category as a term ID; V5's
					// ProductHelper converted it to the category NAME through
					// the ctx-compatibility RankMath shim. V8 shipped the raw
					// ID ("332") — support #68950.
					if ( 'rank_math_primary_product_cat' === $meta_key && is_numeric( $value ) ) {
						$value = (string) apply_filters( 'woo_feed_filter_rank_math_primary_category', $value, $product, $config );
					}

					// Fired with V5's argument order — value, product, config —
					// so third-party shims written for V5 keep working.
					return apply_filters( 'woo_feed_filter_product_meta', $value, $product, $config );
				}

				// WooCommerce taxonomy attributes (wf_taxo_* prefix).
				if ( 0 === strpos( $attr, 'wf_taxo_' ) ) {
					$taxonomy = str_replace( 'wf_taxo_', '', $attr );
					return $this->taxonomy->resolve( $product, $taxonomy, $config );
				}

				// WordPress option attributes (wf_option_* prefix).
				if ( $this->wp_option->is_wp_option( $attr ) ) {
					return $this->wp_option->resolve( $product, $attr, $config );
				}

				// Attribute mapping (wp_attr_mapping_* prefix) — Pro engine.
				if ( null !== $this->attr_mapping && $this->attr_mapping->handles( $attr ) ) {
					return $this->attr_mapping->resolve( $product, $attr, $config, $merchant_attr );
				}

				// Category mapping (wf_cmapping_* prefix). V5 dispatched
				// these to CategoryMapping::getCategoryMappingValue with the
				// PARENT product for variations (V5 line 281-284); we mirror
				// that swap here so variations inherit the parent's mapped
				// category. PROD-FRD-10.4.
				if ( null !== $this->category_mapping && CategoryMappingResolver::handles( $attr ) ) {
					$lookup_product = $product;
					if ( $product->is_type( 'variation' ) ) {
						$parent = ProductMemo::get( (int) $product->get_parent_id() );
						if ( $parent instanceof \WC_Product ) {
							$lookup_product = $parent;
						}
					}
					return $this->category_mapping->resolve( $lookup_product, $attr, $config );
				}

				// Dynamic attributes (wf_dattribute_* prefix). Pass through
				// the merchant attribute so V5's woo_feed_after_dynamic_
				// attribute_value hook receives the channel field name as
				// arg #4 (V5 line 288 signature). PROD-FRD-10.5.
				if ( null !== $this->dynamic_attr && $this->dynamic_attr->handles( $attr ) ) {
					return $this->dynamic_attr->resolve( $product, $attr, $config, $merchant_attr );
				}

				// Registered prefix engines (ctxfeed_attribute_prefix_resolvers),
				// then the known Pro prefixes' graceful '' when nothing claims
				// them (CBT-642).
				$prefixed = $this->resolve_prefixed( $product, $attr, $config, $merchant_attr );
				if ( null !== $prefixed ) {
					return $prefixed;
				}

				// The custom-field plugin prefixes (acf_fields_ / toolset_fields_)
				// are Pro picker groups: their resolution is the Pro
				// CustomFieldPrefixResolver engine (CBT-652), registered
				// through ctxfeed_attribute_prefix_resolvers and consulted by
				// resolve_prefixed() above; unclaimed they resolve to ''.

				// V5-compat custom-field identifier keys (woo_feed_* prefix).
				// These are admin-saved meta from V5\CustomFields\InputCustomFiled
				// (and the V8 CustomFieldRegistrar port). They need V5's full
				// six-step lookup chain, not bare get_post_meta. PROD-FRD-10.2.
				if ( null !== $this->v5_custom_field && V5CustomFieldResolver::handles( $attr ) ) {
					return $this->v5_custom_field->resolve( $product, $attr, $config );
				}

				// Unknown attributes fall through to meta resolution.
				return $this->meta->resolve( $product, $attr, $config );
		}
	}

	/**
	 * Comma-separated term names of a product taxonomy, read from the PARENT
	 * for variations (variations never carry tags / brands of their own).
	 *
	 * @since 8.0.10
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param string      $taxonomy Taxonomy name (product_tag, product_brand…).
	 * @return string
	 */
	private function resolve_term_names( \WC_Product $product, string $taxonomy ): string {
		$source_id = $product->is_type( 'variation' ) && $product->get_parent_id()
			? (int) $product->get_parent_id()
			: (int) $product->get_id();

		$terms = get_the_terms( $source_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Let extensions narrow a variable parent's attribute option list.
	 *
	 * Builds the parent's options in WooCommerce's own order — the same list
	 * `get_attribute()` joined — as `{ name, slug }` items and passes them
	 * through `ctxfeed_variable_parent_attribute_items`. The joined value is
	 * rebuilt only when a callback REMOVED items, so an unhooked or no-op
	 * callback can never change the exported string (CBT-639: the Pro stock
	 * filters keep only the options that still have a sellable variation).
	 *
	 * @since 8.0.29
	 *
	 * @param \WC_Product $parent_product Variable parent product.
	 * @param string      $attr_name Bare attribute name (no wf_attr_ / pa_ prefix).
	 * @param string      $value     Value from get_attribute().
	 * @param Config      $config    Feed configuration.
	 * @return string
	 */
	private function variable_parent_value( \WC_Product $parent_product, string $attr_name, string $value, Config $config ): string {
		if ( ! has_filter( 'ctxfeed_variable_parent_attribute_items' ) ) {
			return $value;
		}

		$attributes = $parent_product->get_attributes();
		$key        = sanitize_title( $attr_name );
		$attribute  = $attributes[ $key ] ?? ( $attributes[ 'pa_' . $key ] ?? null );
		if ( ! $attribute instanceof \WC_Product_Attribute ) {
			return $value;
		}

		$items = array();
		if ( $attribute->is_taxonomy() ) {
			$terms = wc_get_product_terms( $parent_product->get_id(), $attribute->get_name(), array( 'fields' => 'all' ) );
			foreach ( (array) $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$items[] = array(
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
			}
		} else {
			foreach ( $attribute->get_options() as $option ) {
				$items[] = array(
					'name' => (string) $option,
					'slug' => sanitize_title( (string) $option ),
				);
			}
		}

		$kept = self::filter_parent_items( $items, $parent_product, $attribute->get_name(), $config );
		if ( null === $kept ) {
			return $value;
		}

		// Join exactly as get_attribute() does: ', ' for global attributes,
		// WooCommerce's text-attribute delimiter (' | ') for custom ones.
		return $attribute->is_taxonomy() || ! function_exists( 'wc_implode_text_attributes' )
			? implode( ', ', $kept )
			: wc_implode_text_attributes( $kept );
	}

	/**
	 * Run the `ctxfeed_variable_parent_attribute_items` filter.
	 *
	 * Shared by the product-attribute path here and the taxonomy path in
	 * TaxonomyResolver so both mean the same thing.
	 *
	 * @since 8.0.29
	 *
	 * @param array       $items     Ordered `{ name, slug }` option items.
	 * @param \WC_Product $parent_product Variable parent product.
	 * @param string      $attribute Attribute name as WooCommerce stores it (`pa_size`, `size`).
	 * @param Config      $config    Feed configuration.
	 * @return string[]|null Names of the kept items, or null when nothing was removed.
	 */
	public static function filter_parent_items( array $items, \WC_Product $parent_product, string $attribute, Config $config ): ?array {
		if ( empty( $items ) || ! has_filter( 'ctxfeed_variable_parent_attribute_items' ) ) {
			return null;
		}

		/**
		 * Filter a variable parent's attribute options before they are joined.
		 *
		 * Return the items to keep, in order. Removing nothing leaves the
		 * exported value untouched. Used by CTX Feed Pro to drop options
		 * whose variations are out of stock when the feed removes
		 * out-of-stock products (CBT-639).
		 *
		 * @since 8.0.29
		 *
		 * @param array       $items     `{ name, slug }` items in export order.
		 * @param \WC_Product $parent_product Variable parent product.
		 * @param string      $attribute Attribute name (`pa_size` or a custom attribute name).
		 * @param Config      $config    Feed configuration.
		 */
		$kept = apply_filters( 'ctxfeed_variable_parent_attribute_items', $items, $parent_product, $attribute, $config );
		if ( ! is_array( $kept ) || count( $kept ) >= count( $items ) ) {
			return null;
		}

		$names = array();
		foreach ( $kept as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$names[] = (string) $item['name'];
			}
		}
		return $names;
	}

	/**
	 * Resolve WooCommerce product attribute (wf_attr_* prefix).
	 *
	 * Handles both global attributes (taxonomy-based, e.g., pa_color, pa_size)
	 * created from WooCommerce > Products > Attributes, and custom product-level
	 * attributes created directly on individual products.
	 *
	 * Uses WC's built-in get_attribute() method which resolves both types
	 * automatically. Falls back to parent product for variations with empty values.
	 *
	 * Matches V5 ProductHelper::get_product_attribute() behavior.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-4.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Full attribute key with prefix (e.g., "wf_attr_color").
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Resolved attribute value or empty string.
	 */
	private function resolve_wc_product_attribute( \WC_Product $product, string $attr, Config $config ): string {
		// Strip the wf_attr_ prefix to get the bare attribute name.
		$attr_name = str_replace( 'wf_attr_', '', $attr );

		// Also strip pa_ prefix if present (V5 compat for WC 3.6+).
		$attr_name = str_replace( 'pa_', '', $attr_name );

		if ( empty( $attr_name ) ) {
			return '';
		}

		// WC's get_attribute() handles both global (taxonomy) and product-level attributes.
		$value = $product->get_attribute( $attr_name );

		// A variable parent's value lists every option of the attribute; the
		// Pro stock filters may narrow it to the options that still have a
		// sellable variation (CBT-639).
		if ( '' !== $value && $product->is_type( 'variable' ) ) {
			$value = $this->variable_parent_value( $product, $attr_name, $value, $config );
		}

		// Same display-translation seam as the variation-title path (BUG-0101 /
		// CBT-592): a variation's CUSTOM attribute meta keeps the original
		// language — WPML/WCML translate it only via WooCommerce's display
		// filter. This also covers Attribute Mapping and Dynamic Attribute
		// references, which resolve wf_attr_* through this method. The parent
		// fallback below stays raw: that value is the parent's product-level
		// attribute list, not a variation option.
		if ( '' !== $value && $product->is_type( 'variation' ) ) {
			$value = (string) apply_filters( 'woocommerce_variation_option_name', $value, null, $attr_name, $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing WooCommerce core's display filter so WCML-style handlers run.
		}

		// Variation fallback: try parent product if variation has empty value.
		if ( '' === $value && $product->is_type( 'variation' ) ) {
			$parent = ProductMemo::get( (int) $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$value = $parent->get_attribute( $attr_name );
				if ( '' !== $value && $parent->is_type( 'variable' ) ) {
					$value = $this->variable_parent_value( $parent, $attr_name, $value, $config );
				}
			}
		}

		/**
		 * Filter the resolved WC product attribute value.
		 *
		 * Compatible with V5 hook: woo_feed_filter_product_attribute.
		 *
		 * @since 8.0.0
		 *
		 * @param string      $value   Resolved attribute value.
		 * @param string      $attr_name Bare attribute name (without prefix).
		 * @param \WC_Product $product WooCommerce product instance.
		 * @param Config      $config  Feed configuration.
		 */
		return apply_filters( 'woo_feed_filter_product_attribute', $value, $attr_name, $product, $config );
	}

	/**
	 * Resolve the product author's user field (V5 ProductInfo::author_name/email).
	 *
	 * The post author is a WP_User, never post meta, so the generic meta
	 * fallback can never find it. Variations fall back to the parent's author.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $field   WP_User field ('user_login' | 'user_email').
	 * @param string      $attr    Feed attribute name, for the compat filter.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string
	 */
	private function resolve_author_field( \WC_Product $product, string $field, string $attr, Config $config ): string {
		$author_id = (int) get_post_field( 'post_author', $product->get_id() );
		if ( 0 === $author_id && $product->is_type( 'variation' ) ) {
			$author_id = (int) get_post_field( 'post_author', $product->get_parent_id() );
		}
		$value = $author_id > 0 ? (string) get_the_author_meta( $field, $author_id ) : '';
		return apply_filters( 'woo_feed_filter_product_' . $attr, $value, $product, $config );
	}

	/**
	 * Resolve the most recent approved review author's name (BUG-0050).
	 *
	 * WooCommerce reviews are `review`-type comments on the product post,
	 * never post meta, so the generic fallback always returned empty.
	 * Variations use the parent product's reviews.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string
	 */
	private function resolve_reviewer_name( \WC_Product $product, Config $config ): string {
		$post_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 1,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		$name     = '';
		if ( is_array( $comments ) && ! empty( $comments ) ) {
			$first = reset( $comments );
			if ( is_object( $first ) && isset( $first->comment_author ) ) {
				$name = (string) $first->comment_author;
			}
		}
		return apply_filters( 'woo_feed_filter_product_reviewer_name', $name, $product, $config );
	}

	/**
	 * Resolve a scalar summary of a variable product's variations (BUG-0050).
	 *
	 * Custom Template 2's `custom_xml_variations` was a per-variation loop in
	 * V5 with no scalar form, and V8 has no loop yet - so emit the child
	 * variation SKUs (falling back to the variation id) as a comma-joined
	 * stopgap so the source is not silently empty.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string
	 */
	private function resolve_custom_xml_variations( \WC_Product $product, Config $config ): string {
		$value = '';
		if ( $product->is_type( 'variable' ) ) {
			$skus = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product ) {
					$sku    = $child->get_sku();
					$skus[] = '' !== $sku ? $sku : (string) $child_id;
				}
			}
			$value = implode( ', ', $skus );
		}
		return apply_filters( 'woo_feed_filter_product_custom_xml_variations', $value, $product, $config );
	}

	/**
	 * Resolve product stock quantity — V5-verbatim semantics.
	 *
	 * Port of V5 ProductInfo::quantity() (V5/Product/ProductInfo.php:898-947):
	 *
	 *   • Base value is WC `get_stock_quantity()`.
	 *   • In-stock products without managed stock report 1; out-of-stock
	 *     products without managed stock report 0.
	 *   • VARIABLE (parent) products aggregate their visible children's
	 *     `_stock` meta according to the per-feed `variable_quantity`
	 *     feedrule: 'min' / 'max' / 'first'. Any other value — including
	 *     the absent key — sums all variation quantities, matching V5's
	 *     else-branch (and its Config getter default 'sum'). An empty
	 *     `_stock` meta counts as 0; a parent with no visible children
	 *     reports 0.
	 *
	 * The children's `_stock` metas are primed by CacheWarmer::warm()
	 * before each batch, so the per-child get_post_meta() calls below are
	 * cache hits — no extra queries.
	 *
	 * Variable parents only enter the feed when `is_variations` is 'n'
	 * (Variable Products) or 'both' (Variable + Variations) — the same
	 * values that reveal the "Variation quantity" select in the feed
	 * editor. The single-pick modes (cheap/expensive/first/last/default)
	 * emit a chosen variation row instead, resolved via the simple path.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-4.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Quantity, or '' when WC reports no stock quantity.
	 */
	private function resolve_quantity( \WC_Product $product, Config $config ): string {
		$quantity = $product->get_stock_quantity();
		$status   = $product->get_stock_status();

		// V5 parity: unmanaged stock reports 1 when in stock, 0 when out.
		if ( 'instock' === $status && null === $quantity ) {
			$quantity = 1;
		}

		if ( 'outofstock' === $status && null === $quantity ) {
			$quantity = 0;
		}

		if ( $product->is_type( 'variable' ) ) {
			$strategy = (string) $config->get( 'variable_quantity', '' );

			/**
			 * Filter the variable-product quantity strategy.
			 *
			 * Mirrors `ctxfeed_variable_price_strategy` (PriceResolver).
			 * 'min' / 'max' / 'first' pick one variation's quantity;
			 * anything else sums all variation quantities (V5 default).
			 *
			 * @since 8.0.0
			 *
			 * @param string $strategy Strategy from the `variable_quantity` feedrule.
			 * @param Config $config   Feed configuration.
			 */
			$strategy = apply_filters( 'ctxfeed_variable_quantity_strategy', $strategy, $config );

			$variation_ids = $product->get_visible_children();

			// V5-verbatim: read each child's `_stock` meta ('' counts as 0).
			$variation_quantities = array_map(
				static function ( $variation_id ) {
					$stock = get_post_meta( $variation_id, '_stock', true );

					if ( '' === $stock ) {
						$stock = 0;
					}

					return $stock;
				},
				(array) $variation_ids
			);

			if ( empty( $variation_quantities ) ) {
				$quantity = 0;
			} elseif ( 'min' === $strategy ) {
				$quantity = min( $variation_quantities );
			} elseif ( 'max' === $strategy ) {
				$quantity = max( $variation_quantities );
			} elseif ( 'first' === $strategy ) {
				$quantity = $variation_quantities[0];
			} else {
				$quantity = array_sum( $variation_quantities );
			}
		}

		return ( null !== $quantity ) ? (string) $quantity : '';
	}

	/**
	 * Resolve identifier_exists attribute.
	 *
	 * Returns "yes" if ANY product identifier (brand, gtin, mpn, upc, ean,
	 * isbn — or a SKU the feed emits as an identifier column) is present in
	 * the feed output. A single identifier is enough.
	 *
	 * @since 8.0.0
	 * @implements G-05
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string "yes" or "no".
	 */
	private function resolve_identifier_exists( \WC_Product $product, Config $config ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config kept for resolver-helper signature parity; reserved for future identifier hooks.
		// Per-attribute path: no resolved feed row is available yet, so check
		// product meta only. ProductRepository::resolve_single recomputes this
		// from the fully resolved row afterwards, so identifiers the feed
		// actually outputs (e.g. mpn mapped to the SKU, brand mapped to a
		// static value) are detected too.
		return $this->identifier_exists_from_row( $product );
	}

	/**
	 * Compute identifier_exists ("yes"/"no") from the feed's resolved output.
	 *
	 * A product "has identifiers" when ANY single one is present: brand / gtin
	 * / mpn / upc / ean / isbn, or a SKU the feed emits as an identifier
	 * column. Each identifier is read from the RESOLVED feed row first — so a
	 * feed that maps mpn to the SKU, or brand to a static value, counts what it
	 * actually emits — and falls back to product meta only for identifiers the
	 * feed does not map as their own column.
	 *
	 * A bare product SKU that the feed does NOT output as an identifier does
	 * not qualify on its own: SKU alone → "no". Only a SKU the feed actually
	 * emits (e.g. mapped to g:mpn) counts, which is why `sku` is read from the
	 * row but never from the raw product. Reflects the real feed output rather
	 * than raw meta alone.
	 *
	 * @since 8.0.0
	 * @implements G-05
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param array       $row     Resolved feed row (merchant_attr => value); empty on the per-attribute path.
	 *
	 * @return string "yes" or "no".
	 */
	public function identifier_exists_from_row( \WC_Product $product, array $row = array() ): string {
		$product_id = $product->get_id();

		// Any one of these identifiers, present in the feed, is enough. `sku`
		// is checked from the resolved row only (a SKU mapped to g:mpn etc.) —
		// a bare, unmapped product SKU is not an identifier on its own.
		$identifier_keys = array( 'sku', 'brand', 'gtin', 'mpn', 'upc', 'ean', 'isbn' );

		foreach ( $identifier_keys as $key ) {
			// Prefer the value the feed actually outputs for this identifier.
			$value = isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';

			// Fall back to product meta for identifiers the feed doesn't map as
			// their own column (common prefixes). SKU has no meta fallback — it
			// only counts when the feed emits it as an identifier column.
			if ( '' === $value && 'sku' !== $key ) {
				$value = get_post_meta( $product_id, '_ctxfeed_' . $key, true );

				if ( empty( $value ) ) {
					$value = get_post_meta( $product_id, $key, true );
				}

				if ( empty( $value ) ) {
					$value = get_post_meta( $product_id, '_' . $key, true );
				}
			}

			// A single identifier is enough.
			if ( ! empty( $value ) ) {
				return 'yes';
			}
		}

		return 'no';
	}

	/**
	 * Resolve a CTX Feed product-panel custom field (woo_feed_*).
	 *
	 * Delegates to V5CustomFieldResolver for full V5 semantics
	 * (variation `_var` suffix, new/legacy key pair, remap filter);
	 * falls back to a plain meta read when the resolver isn't wired.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $field   V5 field key (e.g. `woo_feed_unit`).
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Field value.
	 */
	private function resolve_plugin_custom_field( \WC_Product $product, string $field, Config $config ): string {
		if ( null !== $this->v5_custom_field ) {
			return $this->v5_custom_field->resolve( $product, $field, $config );
		}

		return (string) get_post_meta( $product->get_id(), $field, true );
	}

	/**
	 * The identifier-settings block from the shared V5 settings option.
	 *
	 * V5 Settings::get('woo_feed_identifier') — same option, same
	 * defaults (availability_date on, unit pricing off) so a store
	 * migrating from V5 keeps its configured behaviour.
	 *
	 * @since 8.0.0
	 *
	 * @return array<string,string> Setting key → 'enable' | 'disable'.
	 */
	private function get_identifier_settings(): array {
		$settings   = get_option( 'woo_feed_settings', array() );
		$identifier = isset( $settings['woo_feed_identifier'] ) && is_array( $settings['woo_feed_identifier'] )
			? $settings['woo_feed_identifier']
			: array();

		return wp_parse_args(
			$identifier,
			array(
				'availability_date'         => 'enable',
				'unit'                      => 'disable',
				'unit_pricing_measure'      => 'disable',
				'unit_pricing_base_measure' => 'disable',
			)
		);
	}

	/**
	 * Resolve availability_date with V5's gates.
	 *
	 * V5 parity — ProductInfo::availability_date(): empty unless the
	 * identifier settings enable it (default: enabled) AND the product
	 * is on backorder (Google only wants the date for products that
	 * aren't immediately shippable). Reads the CTX Feed panel field
	 * `woo_feed_availability_date` (`_var` suffix on variations) and
	 * formats ISO 8601. Unlike V5, an empty/unparseable date returns
	 * empty instead of the epoch.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string ISO 8601 date or empty string.
	 */
	private function resolve_availability_date( \WC_Product $product, Config $config ): string {
		$identifiers = $this->get_identifier_settings();

		if ( 'disable' === $identifiers['availability_date'] || 'onbackorder' !== $product->get_stock_status() ) {
			return '';
		}

		$meta_key = 'woo_feed_availability_date';
		if ( $product->is_type( 'variation' ) ) {
			$meta_key .= '_var';
		}

		$availability_date = (string) get_post_meta( $product->get_id(), $meta_key, true );

		if ( '' !== $availability_date ) {
			// A date the merchant picked is a date in the STORE's timezone;
			// strtotime() read it as UTC midnight, the evening before in
			// UTC−X stores (CBT-719).
			try {
				$zone              = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
				$availability_date = ( new \DateTimeImmutable( $availability_date, $zone ) )->format( 'c' );
			} catch ( \Exception $e ) {
				$availability_date = '';
			}
		}

		// @hook woo_feed_filter_product_availability_date — V5 legacy bridge.
		return (string) apply_filters( 'woo_feed_filter_product_availability_date', $availability_date, $product, $config );
	}

	/**
	 * Compose a Google unit pricing measure: "{measure} {unit}".
	 *
	 * V5 parity — ProductInfo::unit_price_measure() /
	 * unit_price_base_measure(): requires unit, unit_pricing_measure
	 * AND unit_pricing_base_measure all enabled in the identifier
	 * settings; reads the CTX Feed product-panel fields; falls back to
	 * WooCommerce Germanized metas when empty and that plugin is
	 * active. Unlike V5 the unit is only appended when a measure value
	 * exists — no dangling " ml" for products without one.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product         WooCommerce product.
	 * @param Config      $config          Feed configuration.
	 * @param string      $measure_field   CTX Feed panel field for the measure.
	 * @param string      $germanized_meta Germanized fallback meta key.
	 * @param string      $filter          Legacy V5 filter to fire.
	 *
	 * @return string "measure unit" or empty string.
	 */
	private function resolve_unit_price_measure( \WC_Product $product, Config $config, string $measure_field, string $germanized_meta, string $filter ): string {
		$value       = '';
		$identifiers = $this->get_identifier_settings();

		$units_enabled = 'enable' === $identifiers['unit']
			&& 'enable' === $identifiers['unit_pricing_measure']
			&& 'enable' === $identifiers['unit_pricing_base_measure'];

		if ( $units_enabled ) {
			$unit    = $this->resolve_plugin_custom_field( $product, 'woo_feed_unit', $config );
			$measure = $this->resolve_plugin_custom_field( $product, $measure_field, $config );

			if ( '' !== $measure ) {
				$value = trim( $measure . ' ' . $unit );
			}
		}

		// Plugin fallback (WooCommerce Germanized) supplied by the compat
		// layer (ProductTypeCompatibilityProvider) when the CTX Feed panel
		// fields are empty. @hook ctxfeed_resolve_unit_price_measure_fallback.
		if ( '' === $value ) {
			$value = (string) apply_filters( 'ctxfeed_resolve_unit_price_measure_fallback', $value, $product, $germanized_meta, $config );
		}

		return apply_filters( $filter, $value, $product, $config ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $filter is always a "woo_feed_filter_unit_price_*" V5 legacy-bridge hook (see call sites).
	}
}
