<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * AttributeRegistry — Central catalog of all available product attributes.
 *
 * Combines WooCommerce built-in, taxonomy, custom field, and
 * compat-provided attributes grouped by category. Used to
 * populate the attribute dropdown in the admin UI and as the
 * canonical source for all attribute definitions.
 *
 * Provides two output formats:
 *   - get_all():          Simple key => label groups for internal use.
 *   - get_for_dropdown(): optionGroup/options format for admin UI dropdowns.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-13.1
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\CustomFields\CustomFieldHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute registry.
 *
 * @since 8.0.0
 */
class AttributeRegistry {

	/**
	 * Attribute value type prefixes — matches V5 AttributeValueByType constants.
	 *
	 * @since 8.0.0
	 */
	public const PREFIX_ATTRIBUTE     = 'wf_attr_';
	public const PREFIX_TAXONOMY      = 'wf_taxo_';
	public const PREFIX_POST_META     = 'wf_cattr_';
	public const PREFIX_CAT_MAPPING   = 'wf_cmapping_';
	public const PREFIX_DYN_ATTRIBUTE = 'wf_dattribute_';
	public const PREFIX_ATTR_MAPPING  = 'wp_attr_mapping_';

	/**
	 * Get all available attributes grouped by category (simple format).
	 *
	 * Returns a filterable array of attribute groups. Each group is
	 * an associative array of attribute_key => human_label pairs.
	 * Used for internal resolution — NOT for admin UI dropdowns.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-13.1
	 *
	 * @return array<string, array<string, string>> Grouped attributes.
	 */
	public function get_all(): array {
		$attributes = array();

		$attributes['Basic']    = $this->get_basic_attributes();
		$attributes['Price']    = $this->get_simple_price_attributes();
		$attributes['Image']    = $this->get_simple_image_attributes();
		$attributes['Taxonomy'] = $this->get_simple_taxonomy_attributes();
		// SEO attributes are supplied by the compat layer (SeoCompatibilityProvider).
		// @hook ctxfeed_register_simple_seo_attributes — flat SEO attribute group.
		$attributes['SEO']      = apply_filters( 'ctxfeed_register_simple_seo_attributes', array() );
		$attributes['Shipping'] = $this->get_simple_shipping_attributes();

		// WooCommerce product attributes (pa_color, pa_size, etc.).
		$attributes['Product Attributes'] = $this->get_wc_attribute_taxonomies();

		// Custom fields from post meta.
		$attributes['Custom Fields'] = $this->get_custom_field_attributes();

		// @hook ctxfeed_attribute_registry — Compat-provided attributes.
		$attributes = apply_filters( 'ctxfeed_attribute_registry', $attributes );

		return $attributes;
	}

	/**
	 * Get the full grouped attribute list for admin UI channel mapping dropdown.
	 *
	 * Returns attributes in the optionGroup/options structure the admin React UI
	 * expects for populating the attribute-to-channel mapping dropdowns.
	 *
	 * This is the V8 canonical source for all attribute groups. The ProductEndpoint
	 * delegates to this method and adds transient caching + legacy filter firing.
	 *
	 * Groups returned:
	 *   - Primary Attributes (id, title, description, link, sku, etc.)
	 *   - Images (main, featured, gallery 1-10)
	 *   - Price (regular, sale, with tax, effective dates)
	 *   - Shipping
	 *   - Tax
	 *   - Subscription & Installment (conditional on WC Subscriptions)
	 *   - SEO Plugin attributes (Yoast, RankMath, AIOSEO — detected dynamically)
	 *   - Product Attributes (WC global attributes / pa_*)
	 *   - Product Custom Attributes (local variation + custom attrs)
	 *   - Product Taxonomies (delegates to TaxonomyRegistry)
	 *   - Options (wp_options based)
	 *   - Category Mappings
	 *   - ACF Fields (if ACF active)
	 *   - Custom Fields & Post Metas
	 *   - Pro-only groups: Multi-Language, Multi-Vendor, Dynamic Attributes, Attribute Mappings, Custom XML
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-13.1
	 *
	 * @param string $context 'full' or 'basic'.
	 * @return array Array of optionGroup/options arrays.
	 */
	public function get_for_dropdown( string $context = 'full' ): array {
		$attributes = array();

		// --- Core attribute groups (always present) ---
		$attributes[] = $this->get_primary_attributes();
		$attributes[] = $this->get_image_attributes();
		$attributes[] = $this->get_price_attributes();
		$attributes[] = $this->get_shipping_attributes();
		$attributes[] = $this->get_tax_attributes();

		// Subscription & Installment (conditional).
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$attributes[] = $this->get_subscription_attributes();
		}

		if ( 'basic' === $context ) {
			return array_filter( $attributes );
		}

		// --- Dynamic attribute groups (detected from environment) ---

		// CTX Feed custom fields.
		$ctx_fields = $this->get_ctx_custom_fields();
		if ( ! empty( $ctx_fields['options'] ) ) {
			$attributes[] = $ctx_fields;
		}

		// Unit price attributes (WC Germanized).
		$unit_price = $this->get_unit_price_attributes();
		if ( ! empty( $unit_price['options'] ) ) {
			$attributes[] = $unit_price;
		}

		// SEO plugin attributes (Yoast / RankMath / AIOSEO) — supplied by the
		// compat layer (SeoCompatibilityProvider).
		// @hook ctxfeed_register_seo_attributes — dropdown SEO option group.
		$seo = apply_filters( 'ctxfeed_register_seo_attributes', array() );
		if ( ! empty( $seo['options'] ) ) {
			$attributes[] = $seo;
		}

		// WooCommerce global attributes (pa_*).
		$global_attrs = $this->get_global_attributes();
		if ( ! empty( $global_attrs['options'] ) ) {
			$attributes[] = $global_attrs;
		}

		// Product custom attributes (local/variation).
		$custom_attrs = $this->get_custom_attributes();
		if ( ! empty( $custom_attrs['options'] ) ) {
			$attributes[] = $custom_attrs;
		}

		// Product taxonomies — delegate to TaxonomyRegistry.
		$taxonomies = $this->get_taxonomy_dropdown();
		if ( ! empty( $taxonomies['options'] ) ) {
			$attributes[] = $taxonomies;
		}

		// Options (WP options based).
		$options = $this->get_all_options();
		if ( ! empty( $options['options'] ) ) {
			$attributes[] = $options;
		}

		// Category mapping.
		$cat_mapping = $this->get_category_mapped_attributes();
		if ( ! empty( $cat_mapping['options'] ) ) {
			$attributes[] = $cat_mapping;
		}

		// ACF fields — Pro-only in the attribute picker. Existing feeds keep
		// working regardless: the `acf_fields_` prefix + value resolution live
		// in Free (CustomFieldResolver), so a saved mapping still resolves
		// without Pro; only LISTING ACF fields to map is gated. Pro unlocks
		// `acf_attributes` via FeatureGate. @gate acf_attributes.
		if ( FeatureGate::has( 'acf_attributes' ) ) {
			$acf = $this->get_acf_attributes();
			if ( ! empty( $acf['options'] ) ) {
				$attributes[] = $acf;
			}
		}

		// Custom fields & post metas.
		$metas = $this->get_post_meta_attributes();
		if ( ! empty( $metas['options'] ) ) {
			$attributes[] = $metas;
		}

		// --- Pro-only groups (via FeatureGate) ---
		if ( FeatureGate::is_pro() || defined( 'WOO_FEED_PRO_VERSION' ) ) {
			$multi_lang = $this->get_multi_language_attributes();
			if ( ! empty( $multi_lang['options'] ) ) {
				$attributes[] = $multi_lang;
			}

			// Multi-vendor attributes are supplied by the compat layer
			// (MultiVendorCompatibilityProvider). @hook ctxfeed_register_vendor_attributes.
			$multi_vendor = apply_filters( 'ctxfeed_register_vendor_attributes', array() );
			if ( ! empty( $multi_vendor['options'] ) ) {
				$attributes[] = $multi_vendor;
			}

			$dynamic = $this->get_dynamic_attributes();
			if ( ! empty( $dynamic['options'] ) ) {
				$attributes[] = $dynamic;
			}

			$attr_mapping = $this->get_attribute_mappings();
			if ( ! empty( $attr_mapping['options'] ) ) {
				$attributes[] = $attr_mapping;
			}

			$attributes[] = $this->get_custom_xml_attributes();
		}

		return array_values( array_filter( $attributes ) );
	}

	// =====================================================================
	// Simple format methods (key => label) — used by get_all().
	// =====================================================================

	/**
	 * Get basic product attributes (simple format).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_basic_attributes(): array {
		return array(
			'id'                => 'Product ID',
			'title'             => 'Product Title',
			'description'       => 'Product Description',
			'short_description' => 'Short Description',
			'link'              => 'Product URL',
			'sku'               => 'SKU',
			'availability'      => 'Availability',
			'stock_quantity'    => 'Stock Quantity',
			'type'              => 'Product Type',
			'weight'            => 'Weight',
			'length'            => 'Length',
			'width'             => 'Width',
			'height'            => 'Height',
			'date_created'      => 'Date Created',
		);
	}

	/**
	 * Get price-related attributes (simple format).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_simple_price_attributes(): array {
		return array(
			'price'                  => 'Price',
			'regular_price'          => 'Regular Price',
			'sale_price'             => 'Sale Price',
			'price_with_tax'         => 'Price (with Tax)',
			'regular_price_with_tax' => 'Regular Price (with Tax)',
			'sale_price_with_tax'    => 'Sale Price (with Tax)',
		);
	}

	/**
	 * Get image-related attributes (simple format).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_simple_image_attributes(): array {
		return array(
			'image'         => 'Main Image',
			'feature_image' => 'Featured Image',
			'images'        => 'All Images',
			'image_1'       => 'Additional Image 1',
			'image_2'       => 'Additional Image 2',
			'image_3'       => 'Additional Image 3',
			'image_4'       => 'Additional Image 4',
			'image_5'       => 'Additional Image 5',
		);
	}

	/**
	 * Get taxonomy-related attributes (simple format).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_simple_taxonomy_attributes(): array {
		return array(
			'product_type' => 'Product Category',
			'product_cat'  => 'Product Categories',
			'product_tag'  => 'Product Tags',
		);
	}

	/**
	 * Get shipping-related attributes (simple format).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_simple_shipping_attributes(): array {
		return array(
			'shipping_class'  => 'Shipping Class',
			'shipping_weight' => 'Shipping Weight',
		);
	}

	/**
	 * Get dynamic WooCommerce product attributes (pa_color, pa_size, etc.).
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_wc_attribute_taxonomies(): array {
		$attributes = array();
		$taxonomies = wc_get_attribute_taxonomies();

		foreach ( $taxonomies as $tax ) {
			$attributes[ 'pa_' . $tax->attribute_name ] = $tax->attribute_label;
		}

		return $attributes;
	}

	/**
	 * Get custom field attributes (simple format).
	 *
	 * Populated dynamically via filter by store-specific integrations.
	 *
	 * @since 8.0.0
	 *
	 * @return array<string, string> Attribute key => label pairs.
	 */
	private function get_custom_field_attributes(): array {
		// @hook ctxfeed_custom_field_attributes
		return apply_filters( 'ctxfeed_custom_field_attributes', array() );
	}

	// =====================================================================
	// Dropdown format methods (optionGroup/options) — used by get_for_dropdown().
	// =====================================================================

	/**
	 * Primary product attributes.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_primary_attributes(): array {
		return array(
			'optionGroup' => __( 'Primary Attributes', 'woo-feed' ),
			'options'     => array(
				'id'                     => __( 'Product Id', 'woo-feed' ),
				'title'                  => __( 'Product Title', 'woo-feed' ),
				'parent_title'           => __( 'Parent Title', 'woo-feed' ),
				'description'            => __( 'Product Description', 'woo-feed' ),
				'parent_description'     => __( 'Parent Description', 'woo-feed' ),
				'description_with_html'  => __( 'Product Description (with HTML)', 'woo-feed' ),
				'short_description'      => __( 'Product Short Description', 'woo-feed' ),
				'primary_category'       => __( 'Parent Category', 'woo-feed' ),
				'primary_category_id'    => __( 'Parent Category ID', 'woo-feed' ),
				'child_category'         => __( 'Child Category', 'woo-feed' ),
				'child_category_id'      => __( 'Child Category ID', 'woo-feed' ),
				'product_type'           => __( 'Product Category [Category Path]', 'woo-feed' ),
				'product_full_cat'       => __( 'Product Full Category [Category Full Path]', 'woo-feed' ),
				'link'                   => __( 'Product URL', 'woo-feed' ),
				'parent_link'            => __( 'Parent URL', 'woo-feed' ),
				'canonical_link'         => __( 'Canonical URL', 'woo-feed' ),
				'ex_link'                => __( 'External Product URL', 'woo-feed' ),
				'add_to_cart_link'       => __( 'Add to Cart URL', 'woo-feed' ),
				'item_group_id'          => __( 'Parent Id [Group Id]', 'woo-feed' ),
				'sku'                    => __( 'SKU', 'woo-feed' ),
				'sku_id'                 => __( 'SKU_ID', 'woo-feed' ),
				'parent_sku'             => __( 'Parent SKU', 'woo-feed' ),
				'availability'           => __( 'Availability', 'woo-feed' ),
				'availability_date'      => __( 'Availability Date', 'woo-feed' ),
				'quantity'               => __( 'Quantity', 'woo-feed' ),
				'reviewer_name'          => __( 'Reviewer Name', 'woo-feed' ),
				'weight'                 => __( 'Weight', 'woo-feed' ),
				'weight_unit'            => __( 'Weight Unit', 'woo-feed' ),
				'width'                  => __( 'Width', 'woo-feed' ),
				'height'                 => __( 'Height', 'woo-feed' ),
				'length'                 => __( 'Length', 'woo-feed' ),
				'type'                   => __( 'Product Type', 'woo-feed' ),
				'visibility'             => __( 'Visibility', 'woo-feed' ),
				'rating_total'           => __( 'Total Rating', 'woo-feed' ),
				'rating_average'         => __( 'Average Rating', 'woo-feed' ),
				'tags'                   => __( 'Tags', 'woo-feed' ),
				'is_bundle'              => __( 'Is Bundle', 'woo-feed' ),
				'author_name'            => __( 'Author Name', 'woo-feed' ),
				'author_email'           => __( 'Author Email', 'woo-feed' ),
				'date_created'           => __( 'Date Created', 'woo-feed' ),
				'date_updated'           => __( 'Date Updated', 'woo-feed' ),
				'product_status'         => __( 'Product Status', 'woo-feed' ),
				'featured_status'        => __( 'Featured Status', 'woo-feed' ),
				'checkout_link_template' => __( 'Checkout Link Template', 'woo-feed' ),
				'gtin_upc_ean_isbn'      => __( 'GTIN, UPC, EAN, or ISBN', 'woo-feed' ),
			),
		);
	}

	/**
	 * Image attributes (dropdown format).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_image_attributes(): array {
		return array(
			'optionGroup' => __( 'Images', 'woo-feed' ),
			'options'     => array(
				'image'         => __( 'Main Image', 'woo-feed' ),
				'feature_image' => __( 'Featured Image', 'woo-feed' ),
				'images'        => __( 'Images [Comma Separated]', 'woo-feed' ),
				'image_1'       => __( 'Additional Image 1', 'woo-feed' ),
				'image_2'       => __( 'Additional Image 2', 'woo-feed' ),
				'image_3'       => __( 'Additional Image 3', 'woo-feed' ),
				'image_4'       => __( 'Additional Image 4', 'woo-feed' ),
				'image_5'       => __( 'Additional Image 5', 'woo-feed' ),
				'image_6'       => __( 'Additional Image 6', 'woo-feed' ),
				'image_7'       => __( 'Additional Image 7', 'woo-feed' ),
				'image_8'       => __( 'Additional Image 8', 'woo-feed' ),
				'image_9'       => __( 'Additional Image 9', 'woo-feed' ),
				'image_10'      => __( 'Additional Image 10', 'woo-feed' ),
			),
		);
	}

	/**
	 * Price attributes (dropdown format).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_price_attributes(): array {
		return array(
			'optionGroup' => __( 'Price', 'woo-feed' ),
			'options'     => array(
				'currency'                  => __( 'Currency', 'woo-feed' ),
				'price'                     => __( 'Regular Price', 'woo-feed' ),
				'current_price'             => __( 'Price', 'woo-feed' ),
				'sale_price'                => __( 'Sale Price', 'woo-feed' ),
				'price_with_tax'            => __( 'Regular Price With Tax', 'woo-feed' ),
				'current_price_with_tax'    => __( 'Price With Tax', 'woo-feed' ),
				'sale_price_with_tax'       => __( 'Sale Price With Tax', 'woo-feed' ),
				'sale_price_sdate'          => __( 'Sale Start Date', 'woo-feed' ),
				'sale_price_edate'          => __( 'Sale End Date', 'woo-feed' ),
				'sale_price_effective_date' => __( 'Sale Price Effective Date', 'woo-feed' ),
			),
		);
	}

	/**
	 * Shipping attributes (dropdown format).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_shipping_attributes(): array {
		return array(
			'optionGroup' => __( 'Shipping', 'woo-feed' ),
			'options'     => array(
				'shipping'       => __( 'Shipping (Google Format)', 'woo-feed' ),
				'shipping_class' => __( 'Shipping Class', 'woo-feed' ),
			),
		);
	}

	/**
	 * Tax attributes (dropdown format).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_tax_attributes(): array {
		return array(
			'optionGroup' => __( 'Tax', 'woo-feed' ),
			'options'     => array(
				'tax'       => __( 'Tax (Google Format)', 'woo-feed' ),
				'tax_class' => __( 'Tax Class', 'woo-feed' ),
			),
		);
	}

	/**
	 * Subscription & Installment attributes (WC Subscriptions).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_subscription_attributes(): array {
		return array(
			'optionGroup' => __( 'Subscription & Installment', 'woo-feed' ),
			'options'     => array(
				'subscription_period'          => __( 'Subscription Period', 'woo-feed' ),
				'subscription_period_interval' => __( 'Subscription Period Length', 'woo-feed' ),
				'subscription_amount'          => __( 'Subscription Amount', 'woo-feed' ),
				'installment_months'           => __( 'Installment Months', 'woo-feed' ),
				'installment_amount'           => __( 'Installment Amount', 'woo-feed' ),
			),
		);
	}

	/**
	 * CTX Feed custom identifier fields.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_ctx_custom_fields(): array {
		$options = array();

		// Native V8 source of truth for the identifier/custom-field list
		// (brand, gtin, mpn, …). CustomFieldHelper::get_fields() fires the
		// `woo_feed_product_custom_fields` filter internally, so Pro/compat
		// hooks still apply. Replaces the former dependency on the V5 global
		// woo_feed_product_custom_fields(), which is not loaded in V8 mode —
		// so these identifier options used to silently disappear.
		$custom_fields = CustomFieldHelper::get_fields();
		if ( is_array( $custom_fields ) && ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $key => $value ) {
				if ( is_array( $value ) && isset( $value[0] ) ) {
					$options[ 'woo_feed_identifier_' . sanitize_text_field( $key ) ] = sanitize_text_field( $value[0] );
				}
			}
		}

		return array(
			'optionGroup' => __( 'Custom Fields by CTX Feed', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Unit price attributes.
	 *
	 * Always includes the base CTX Feed unit price attribute.
	 * Adds WooCommerce Germanized attributes when that plugin is active.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_unit_price_attributes(): array {
		// Base unit price attribute — always available (matches V5 behavior).
		$options = array(
			'unit_price_unit' => __( 'Unit', 'woo-feed' ),
		);

		/**
		 * Get Germanized for WooCommerce unit attributes.
		 *
		 * @link https://wordpress.org/plugins/woocommerce-germanized/
		 */
		if ( class_exists( 'WooCommerce_Germanized' ) ) {
			$options += array(
				'wc_germanized_unit_price_measure'      => __( 'Unit Price Measure', 'woo-feed' ),
				'wc_germanized_unit_price_base_measure' => __( 'Unit Price Base Measure', 'woo-feed' ),
				'wc_germanized_gtin'                    => __( 'GTIN', 'woo-feed' ),
				'wc_germanized_mpn'                     => __( 'MPN', 'woo-feed' ),
			);
		}

		return array(
			'optionGroup' => __( 'Unit Price (CTX Feed)', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * SEO plugin attributes — detects Yoast, RankMath, or AIOSEO.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_seo_attributes(): array {
		// Yoast SEO.
		if ( class_exists( 'WPSEO_Frontend' ) || class_exists( 'WPSEO_Premium' ) ) {
			$options = array(
				'yoast_wpseo_title'      => __( 'Title [Yoast SEO]', 'woo-feed' ),
				'yoast_wpseo_metadesc'   => __( 'Description [Yoast SEO]', 'woo-feed' ),
				'yoast_canonical_url'    => __( 'Canonical URL [Yoast SEO]', 'woo-feed' ),
				'yoast_primary_category' => __( 'Primary Category [Yoast SEO]', 'woo-feed' ),
			);
			if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
				$options += array(
					'yoast_gtin8'  => __( 'GTIN8 [Yoast SEO]', 'woo-feed' ),
					'yoast_gtin12' => __( 'GTIN12 / UPC [Yoast SEO]', 'woo-feed' ),
					'yoast_gtin13' => __( 'GTIN13 / EAN [Yoast SEO]', 'woo-feed' ),
					'yoast_gtin14' => __( 'GTIN14 / ITF-14 [Yoast SEO]', 'woo-feed' ),
					'yoast_isbn'   => __( 'ISBN [Yoast SEO]', 'woo-feed' ),
					'yoast_mpn'    => __( 'MPN [Yoast SEO]', 'woo-feed' ),
				);
			}
			return array(
				'optionGroup' => __( 'Yoast SEO', 'woo-feed' ),
				'options'     => $options,
			);
		}

		// RankMath.
		if ( class_exists( 'RankMath' ) || class_exists( 'RankMathPro' ) ) {
			$options = array(
				'rank_math_title'         => __( 'Title [RankMath SEO]', 'woo-feed' ),
				'rank_math_description'   => __( 'Description [RankMath SEO]', 'woo-feed' ),
				'rank_math_canonical_url' => __( 'Canonical URL [RankMath SEO]', 'woo-feed' ),
			);
			if ( class_exists( 'RankMathPro' ) ) {
				$options['rank_math_gtin'] = __( 'GTIN [RankMath Pro SEO]', 'woo-feed' );
			}
			return array(
				'optionGroup' => __( 'RANK MATH SEO', 'woo-feed' ),
				'options'     => $options,
			);
		}

		// All in One SEO.
		if ( class_exists( 'AIOSEO\Plugin\AIOSEO' ) ) {
			return array(
				'optionGroup' => __( 'ALL IN ONE SEO', 'woo-feed' ),
				'options'     => array(
					'_aioseop_title'         => __( 'Title [All in One SEO]', 'woo-feed' ),
					'_aioseop_description'   => __( 'Description [All in One SEO]', 'woo-feed' ),
					'_aioseop_canonical_url' => __( 'Canonical URL [All in One SEO]', 'woo-feed' ),
				),
			);
		}

		return array(
			'optionGroup' => '',
			'options'     => array(),
		);
	}

	/**
	 * WooCommerce global attributes (pa_* taxonomies).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_global_attributes(): array {
		$options    = array();
		$taxonomies = wc_get_attribute_taxonomy_labels();

		if ( ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $key => $value ) {
				$options[ self::PREFIX_ATTRIBUTE . 'pa_' . $key ] = $value;
			}
		}

		return array(
			'optionGroup' => __( 'Product Attributes', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Product custom attributes (local variation + custom attributes).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_custom_attributes(): array {
		global $wpdb;
		$options = array();

		// Variation local attributes.
		$sql         = "SELECT DISTINCT( meta_key ) FROM {$wpdb->postmeta}
			WHERE post_id IN (
			    SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation'
			) AND (
			    meta_key LIKE 'attribute_%'
			    AND meta_key NOT LIKE 'attribute_pa_%'
			)";
		$local_attrs = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is a fixed literal built only from $wpdb table properties, so there is nothing to prepare. Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.

		foreach ( $local_attrs as $attr ) {
			$clean                                      = str_replace( 'attribute_', '', $attr );
			$options[ self::PREFIX_ATTRIBUTE . $clean ] = ucwords( str_replace( '-', ' ', $clean ) );
		}

		// Product custom attributes from _product_attributes meta.
		$sql2           = "SELECT meta.meta_value as type FROM {$wpdb->postmeta} AS meta
			INNER JOIN {$wpdb->posts} AS posts ON meta.post_id = posts.ID
			WHERE posts.post_type LIKE '%product%'
			AND meta.meta_key = '_product_attributes'";
		$custom_results = $wpdb->get_results( $sql2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql2 is a fixed literal built only from $wpdb table properties, so there is nothing to prepare. Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.

		if ( ! empty( $custom_results ) ) {
			foreach ( $custom_results as $row ) {
				$product_attr = maybe_unserialize( $row->type );
				if ( is_array( $product_attr ) ) {
					foreach ( $product_attr as $key => $arr_value ) {
						if ( strpos( $key, 'pa_' ) === false && isset( $arr_value['name'] ) ) {
							$options[ self::PREFIX_ATTRIBUTE . $key ] = ucwords( str_replace( '-', ' ', $arr_value['name'] ) );
						}
					}
				}
			}
		}

		return array(
			'optionGroup' => __( 'Product Custom Attributes', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * All product taxonomies (excluding built-in ones and pa_*).
	 *
	 * Delegates to TaxonomyRegistry::get_for_dropdown() for taxonomy
	 * discovery and exclusion filtering.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_taxonomy_dropdown(): array {
		$container = \CTXFeed\V8\Core\Container::get_instance();

		/** @var TaxonomyRegistry $registry */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$registry = $container->resolve( 'product.taxonomy_registry' );
		$options  = $registry->get_for_dropdown( self::PREFIX_TAXONOMY );

		return array(
			'optionGroup' => __( 'Product Taxonomies', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * WP Options-based attributes.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_all_options(): array {
		$wp_options     = wp_list_pluck( get_option( 'wpfp_option', array() ), 'option_name' );
		$wp_options_val = str_replace( 'wf_option_', '', $wp_options );
		$options        = ! empty( $wp_options ) ? array_combine( $wp_options, $wp_options_val ) : array();

		return array(
			'optionGroup' => __( 'Options', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Category mapping attributes.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_category_mapped_attributes(): array {
		global $wpdb;
		$options = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix scan over wp_options; get_option() cannot enumerate by LIKE and there is no autoloaded index of these rows. Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::PREFIX_CAT_MAPPING . '%'
			)
		);

		if ( ! empty( $data ) ) {
			foreach ( $data as $row ) {
				$opts                         = maybe_unserialize( $row->option_value );
				$opts                         = maybe_unserialize( $opts );
				$options[ $row->option_name ] = is_array( $opts ) && isset( $opts['mappingname'] )
					? $opts['mappingname']
					: str_replace( self::PREFIX_CAT_MAPPING, '', $row->option_name );
			}
		}

		return array(
			'optionGroup' => __( 'Category Mapping', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * ACF (Advanced Custom Fields) attributes.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_acf_attributes(): array {
		$options = array();

		if ( class_exists( 'ACF' ) && function_exists( 'acf_get_field_groups' ) ) {
			$field_groups = acf_get_field_groups();
			foreach ( $field_groups as $group ) {
				// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Reads the field definitions of one ACF field group (a handful of acf-field posts, never product data), using the exact query shape ACF itself uses; paginating would silently drop fields from the mapping dropdown. Admin-only and served from the 5-minute transient in ProductEndpoint::get_mapping_attributes(), so it never runs on the 200-product feed batch path.
				$fields = get_posts(
					array(
						'posts_per_page'         => -1,
						'post_type'              => 'acf-field',
						'orderby'                => 'menu_order',
						'order'                  => 'ASC',
						'suppress_filters'       => true,
						'post_parent'            => $group['ID'],
						'post_status'            => 'any',
						'update_post_meta_cache' => false,
					) 
				);
				// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page, WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters

				foreach ( $fields as $field ) {
					$options[ 'acf_fields_' . $field->post_name ] = $field->post_title;
				}
			}
		}

		return array(
			'optionGroup' => __( 'Advanced Custom Fields (ACF)', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Custom fields & post meta attributes.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_post_meta_attributes(): array {
		global $wpdb;
		$options = array();

		$exclude_keys = array(
			'_edit_lock',
			'_wp_old_slug',
			'_edit_last',
			'_wp_old_date',
			'_downloadable_files',
			'_sku',
			'_weight',
			'_width',
			'_height',
			'_length',
			'_file_path',
			'_file_paths',
			'_default_attributes',
			'_product_attributes',
			'_children',
			'_variation_description',
			'_wpcom_is_markdown',
			'_yith_wcpb_bundle_data',
			'_et_builder_version',
			'_vc_post_settings',
			'_enable_sidebar',
			'frs_woo_product_tabs',
		);

		/**
		 * Filter excluded meta keys.
		 *
		 * @since 8.0.0
		 * @param array $user_exclude    Additional meta keys to exclude.
		 * @param array $default_exclude Default excluded keys.
		 */
		$user_exclude = apply_filters( 'ctxfeed_dropdown_exclude_meta_keys', array(), $exclude_keys );
		if ( ! empty( $user_exclude ) ) {
			$exclude_keys = array_merge( $exclude_keys, $user_exclude );
		}

		// V5 legacy filter for backward compatibility with existing extensions.
		$legacy_exclude = apply_filters( 'woo_feed_dropdown_exclude_meta_keys', null, $exclude_keys );
		if ( is_array( $legacy_exclude ) && ! empty( $legacy_exclude ) ) {
			$legacy_exclude = esc_sql( $legacy_exclude );
			$exclude_keys   = array_merge( $exclude_keys, $legacy_exclude );
		}

		$exclude_keys     = array_map( 'esc_sql', $exclude_keys );
		$exclude_keys_str = "'" . implode( "', '", $exclude_keys ) . "'";

		$exclude_patterns = array(
			'%_et_pb_%',
			'attribute_%',
			'_yoast_wpseo_%',
			'_acf-%',
			'_aioseop_%',
			'_oembed%',
			'_wpml_%',
			'_oh_add_script_%',
		);

		/**
		 * Filter excluded meta key patterns.
		 *
		 * @since 8.0.0
		 * @param array $user_patterns    Additional patterns to exclude.
		 * @param array $default_patterns Default excluded patterns.
		 */
		$user_patterns = apply_filters( 'ctxfeed_dropdown_exclude_meta_keys_pattern', array(), $exclude_patterns );
		if ( ! empty( $user_patterns ) ) {
			$exclude_patterns = array_merge( $exclude_patterns, $user_patterns );
		}

		// V5 legacy filter for backward compatibility with existing extensions.
		$legacy_patterns = apply_filters( 'woo_feed_dropdown_exclude_meta_keys_pattern', null, $exclude_patterns );
		if ( is_array( $legacy_patterns ) && ! empty( $legacy_patterns ) ) {
			$exclude_patterns = array_merge( $exclude_patterns, $legacy_patterns );
		}

		$pattern_sql = '';
		foreach ( $exclude_patterns as $pattern ) {
			$pattern_sql .= $wpdb->prepare( ' AND meta_key NOT LIKE %s', $pattern );
		}

		$sql = sprintf(
			"SELECT DISTINCT( meta_key ) FROM {$wpdb->postmeta}
			WHERE 1=1
			AND post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' OR post_type = 'product_variation' )
			AND ( meta_key NOT IN ( %s ) %s )",
			$exclude_keys_str,
			$pattern_sql
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is assembled above with sprintf() from values already run through $wpdb->prepare()/esc_sql(). Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.
		$data = $wpdb->get_results( $sql );

		if ( ! empty( $data ) ) {
			foreach ( $data as $row ) {
				$options[ self::PREFIX_POST_META . $row->meta_key ] = $row->meta_key;
			}
		}

		return array(
			'optionGroup' => __( 'Custom Fields & Post Metas', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Multi-language attributes (WPML). Pro only.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_multi_language_attributes(): array {
		if ( ! class_exists( 'SitePress' ) ) {
			return array(
				'optionGroup' => '',
				'options'     => array(),
			);
		}

		return array(
			'optionGroup' => __( 'WPML Attributes', 'woo-feed' ),
			'options'     => array(
				'parent_id' => __( 'Parent Product ID', 'woo-feed' ),
			),
		);
	}

	/**
	 * Dynamic attributes from wp_options. Pro only.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_dynamic_attributes(): array {
		global $wpdb;
		$options = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix scan over wp_options; get_option() cannot enumerate by LIKE and there is no autoloaded index of these rows. Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::PREFIX_DYN_ATTRIBUTE . '%'
			)
		);

		if ( ! empty( $data ) ) {
			foreach ( $data as $row ) {
				$opts                         = maybe_unserialize( $row->option_value );
				$opts                         = maybe_unserialize( $opts );
				$options[ $row->option_name ] = is_array( $opts ) && isset( $opts['wfDAttributeName'] )
					? $opts['wfDAttributeName']
					: str_replace( self::PREFIX_DYN_ATTRIBUTE, '', $row->option_name );
			}
		}

		return array(
			'optionGroup' => __( 'Dynamic Attributes', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Custom attribute mappings from wp_options. Pro only.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_attribute_mappings(): array {
		global $wpdb;
		$options = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix scan over wp_options; get_option() cannot enumerate by LIKE and there is no autoloaded index of these rows. Admin-only dropdown catalog: there is no WP API for "distinct meta keys in use", and the result is served from a 5-minute transient by ProductEndpoint::get_mapping_attributes(), so this runs at most once per cache window and never on the feed-generation path.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				self::PREFIX_ATTR_MAPPING . '%'
			)
		);

		if ( ! empty( $data ) ) {
			foreach ( $data as $row ) {
				$opts                         = maybe_unserialize( $row->option_value );
				$options[ $row->option_name ] = is_array( $opts ) && isset( $opts['name'] )
					? $opts['name']
					: str_replace( self::PREFIX_ATTR_MAPPING, '', $row->option_name );
			}
		}

		return array(
			'optionGroup' => __( 'Attribute Mappings', 'woo-feed' ),
			'options'     => $options,
		);
	}

	/**
	 * Custom XML template 2 attributes. Pro only.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	private function get_custom_xml_attributes(): array {
		return array(
			'optionGroup' => __( 'Custom Template 2 (XML)', 'woo-feed' ),
			'options'     => array(
				'custom_xml_variations' => __( 'Product Variations', 'woo-feed' ),
				'custom_xml_images'     => __( 'Product Gallery Images', 'woo-feed' ),
				'custom_xml_categories' => __( 'Product Categories', 'woo-feed' ),
			),
		);
	}
}
