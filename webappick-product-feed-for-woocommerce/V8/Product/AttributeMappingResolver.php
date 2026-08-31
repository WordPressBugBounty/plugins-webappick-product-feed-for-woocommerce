<?php
/**
 * AttributeMappingResolver — Resolves user-defined attribute mappings.
 *
 * Handles attributes prefixed with "wp_attr_mapping_" that combine
 * multiple source product attributes into a single value with a
 * configurable glue/separator.
 *
 * Example: A mapping named "brand" with sources [title, id] and glue "-"
 * resolves to "Shirt-123" for a product titled "Shirt" with ID 123.
 *
 * Mapping configs are stored in wp_options as:
 *   wp_attr_mapping_brand → { name: "brand", glue: "-", mapping: ["title", "id"] }
 *
 * Uses setter injection for AttributeResolver to break the circular
 * dependency (same pattern as VariationResolver).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute mapping resolver.
 *
 * @since 8.0.0
 */
class AttributeMappingResolver {

	/**
	 * Prefix used to identify attribute mapping keys.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PREFIX = 'wp_attr_mapping_';

	/**
	 * Attribute resolver reference (set via setter injection).
	 *
	 * Used to resolve individual source attributes within a mapping.
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver = null;

	/**
	 * Internal cache of mapping configurations fetched from wp_options.
	 *
	 * The mapping config is the same for every product — only source
	 * attribute values change per product. Caching avoids repeated
	 * get_option() calls during batch processing.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $config_cache = array();

	/**
	 * Attribute names currently being resolved — recursion cycle detector.
	 *
	 * Keyed by full attribute name (value is irrelevant). An entry means the
	 * name is on the current resolution stack; being asked to resolve a name
	 * that is already present is a circular reference (direct or via another
	 * mapping/dynamic attribute). Added on entry, removed in `finally`, so
	 * sequential (non-nested) reuse of a mapping is not mistaken for a cycle.
	 *
	 * @since 8.0.0
	 * @var array<string,bool>
	 */
	private $resolving = array();

	/**
	 * Known attribute prefixes used to distinguish product attributes from custom text.
	 *
	 * If a mapping item doesn't match any known attribute key or prefix,
	 * it's treated as literal custom text (e.g., "by", "-", "Model:").
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private static $known_prefixes = array(
		'wf_attr_',
		'wf_cattr_',
		'wf_taxo_',
		'wf_option_',
		'wf_extra_',
		'wf_cmapping_',
		'wf_dattribute_',
		'wp_attr_mapping_',
		'acf_fields_',
		'woo_feed_',
		'csv_tax_',
		'csv_shipping_',
		'image_',
	);

	/**
	 * Set the AttributeResolver instance.
	 *
	 * Called during ProductServiceProvider::boot() to break the
	 * circular dependency: AttributeResolver → AttributeMappingResolver → AttributeResolver.
	 *
	 * @since 8.0.0
	 *
	 * @param AttributeResolver $resolver Attribute resolver instance.
	 *
	 * @return void
	 */
	public function set_attribute_resolver( AttributeResolver $resolver ): void {
		$this->attribute_resolver = $resolver;
	}

	/**
	 * Check if an attribute key is an attribute mapping.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute key.
	 *
	 * @return bool True if the attribute starts with the mapping prefix.
	 */
	public function is_attribute_mapping( string $attr ): bool {
		return 0 === strpos( $attr, self::PREFIX );
	}

	/**
	 * Resolve an attribute mapping value for a product.
	 *
	 * Fetches the mapping config from wp_options, iterates each source
	 * item in the mapping array. Each item can be either:
	 * - A product attribute key (e.g., "title", "wf_attr_brand") → resolved via AttributeResolver.
	 * - Custom/literal text (e.g., "by", "-", "Model:") → returned as-is.
	 *
	 * Results are joined with the configured glue separator.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Attribute key (e.g., "wp_attr_mapping_brand").
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Resolved combined value or empty string.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config ): string {
		if ( null === $this->attribute_resolver ) {
			return '';
		}

		// Recursion cycle guard. A mapping that references itself — directly,
		// or through another mapping / dynamic attribute that points back here
		// — would recurse until PHP exhausts the stack and fatals the whole
		// feed run. Detect re-entry for the same attribute still on the
		// resolution stack and resolve it to empty instead.
		if ( isset( $this->resolving[ $attr ] ) ) {
			Logger::warning(
				'Circular attribute mapping reference detected; resolved to empty.',
				array( 'attribute' => $attr )
			);
			return '';
		}

		$this->resolving[ $attr ] = true;

		try {
			// Fetch and cache the mapping configuration.
			$mapping_config = $this->get_mapping_config( $attr );

			if ( empty( $mapping_config ) || empty( $mapping_config['mapping'] ) ) {
				return '';
			}

			$glue   = ! empty( $mapping_config['glue'] ) ? $mapping_config['glue'] : ' ';
			$result = $this->combine( $product, (array) $mapping_config['mapping'], $glue, $config );
			$output = $result['output'];

			/**
			 * Filter the resolved attribute mapping value.
			 *
			 * Compatible with V5 hook: woo_feed_filter_attribute_mapping.
			 *
			 * @since 8.0.0
			 *
			 * @param string      $output  Resolved combined value.
			 * @param string      $attr    Full attribute key with prefix.
			 * @param \WC_Product $product WooCommerce product instance.
			 * @param Config      $config  Feed configuration.
			 */
			$output = apply_filters( 'woo_feed_filter_attribute_mapping', $output, $attr, $product, $config );

			return $output;
		} finally {
			// Always clear the in-progress marker, even on an early return or
			// a thrown error, so a later resolve of the same name is not
			// wrongly treated as a cycle.
			unset( $this->resolving[ $attr ] );
		}
	}

	/**
	 * Preview a mapping definition against a product without saving it.
	 *
	 * Runs the exact combine logic resolve() uses, but takes the raw
	 * mapping items + glue (an unsaved definition) instead of a stored
	 * wp_attr_mapping_* option, and returns per-item detail alongside
	 * the joined output — so the admin UI can show which attribute
	 * items resolve empty for the chosen product.
	 *
	 * The V5 woo_feed_filter_attribute_mapping filter is NOT applied
	 * here: it keys on the stored attribute name, which an unsaved
	 * definition doesn't have yet.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param array       $mapping Mapping items (attribute keys / literals).
	 * @param string      $glue    Separator; defaults to a space when empty.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array{parts: array, output: string}
	 */
	public function preview_items( \WC_Product $product, array $mapping, string $glue, Config $config ): array {
		if ( null === $this->attribute_resolver ) {
			return array(
				'parts'  => array(),
				'output' => '',
			);
		}

		$glue = ! empty( $glue ) ? $glue : ' ';

		return $this->combine( $product, $mapping, $glue, $config );
	}

	/**
	 * Combine mapping items into the joined output — the single source of
	 * truth shared by resolve() and preview_items().
	 *
	 * Returns one parts entry per input item (including skipped empties)
	 * with: source (raw item), is_attribute (resolved vs literal), value
	 * (resolved value), included (made it into the output).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param array       $mapping Mapping items.
	 * @param string      $glue    Normalized separator.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array{parts: array, output: string}
	 */
	private function combine( \WC_Product $product, array $mapping, string $glue, Config $config ): array {
		$parts  = array();
		$output = '';

		foreach ( $mapping as $source_item ) {
			$source_item = is_scalar( $source_item ) ? (string) $source_item : '';

			if ( empty( $source_item ) && ' ' !== $source_item ) {
				$parts[] = array(
					'source'       => $source_item,
					'is_attribute' => false,
					'value'        => '',
					'included'     => false,
				);
				continue;
			}

			// Determine if this item is a known product attribute or custom text.
			if ( $this->is_known_attribute( $source_item ) ) {
				// Resolve as product attribute via AttributeResolver.
				$source_mapping = array(
					'type'    => 'attribute',
					'wc_attr' => $source_item,
					'default' => '',
				);

				$value        = $this->attribute_resolver->resolve( $product, $source_mapping, $config );
				$is_attribute = true;
			} else {
				// Custom/literal text — use as-is (e.g., "by", "-", "Model:").
				$value        = $source_item;
				$is_attribute = false;
			}

			$included = ! empty( $value ) || ' ' === $value;

			if ( $included ) {
				$output .= $glue . $value;
			}

			$parts[] = array(
				'source'       => $source_item,
				'is_attribute' => $is_attribute,
				'value'        => is_scalar( $value ) ? (string) $value : '',
				'included'     => $included,
			);
		}

		// Trim extra glue from the beginning.
		$output = trim( $output, $glue );

		// Remove extra whitespace.
		$output = preg_replace( '!\s\s+!', ' ', $output );

		return array(
			'parts'  => $parts,
			'output' => $output,
		);
	}

	/**
	 * Get the mapping configuration from wp_options, with caching.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Full attribute key (e.g., "wp_attr_mapping_brand").
	 *
	 * @return array|false Mapping config array or false if not found.
	 */
	private function get_mapping_config( string $attr ) {
		if ( isset( $this->config_cache[ $attr ] ) ) {
			return $this->config_cache[ $attr ];
		}

		$mapping_config = get_option( $attr, false );

		$this->config_cache[ $attr ] = $mapping_config;

		return $mapping_config;
	}

	/**
	 * Determine if a mapping item is a known product attribute.
	 *
	 * Checks against known attribute prefixes (wf_attr_, wf_cattr_, etc.)
	 * and the standard WC attribute keys handled by AttributeResolver's
	 * switch/case (title, price, sku, etc.).
	 *
	 * If the item doesn't match any known pattern, it's treated as custom
	 * text — matching V5 behavior where unrecognized strings in
	 * AttributeValueByType::get_value() are returned as-is.
	 *
	 * @since 8.0.0
	 *
	 * @param string $item Mapping item (attribute key or custom text).
	 *
	 * @return bool True if the item is a known product attribute.
	 */
	private function is_known_attribute( string $item ): bool {
		// Check against known attribute prefixes.
		foreach ( self::$known_prefixes as $prefix ) {
			if ( 0 === strpos( $item, $prefix ) ) {
				return true;
			}
		}

		// Check against standard WC attribute keys recognized by
		// AttributeResolver::resolve_wc_attribute().
		static $known_attrs = null;
		if ( null === $known_attrs ) {
			$known_attrs = array_flip(
				array(
					'id',
					'name',
					'title',
					'description',
					'short_description',
					'sku',
					'link',
					'url',
					'availability',
					'stock_quantity',
					'stock_status',
					'weight',
					'length',
					'width',
					'height',
					'type',
					'slug',
					'date_created',
					'price',
					'regular_price',
					'current_price',
					'sale_price',
					'price_with_tax',
					'regular_price_with_tax',
					'current_price_with_tax',
					'sale_price_with_tax',
					'price_excluding_tax',
					'sale_price_effective_date',
					'sale_price_sdate',
					'sale_price_edate',
					'product_type',
					'item_group_id',
					'parent_id',
					'quantity',
					'currency',
					'condition',
					'date_updated',
					'visibility',
					'rating_total',
					'rating_average',
					'total_sold',
					'tags',
					'shipping_class',
					'tax_class',
					'tax_status',
					'featured_status',
					'add_to_cart_link',
					'is_bundle',
					'product_status',
					'identifier_exists',
					'shipping',
					'tax',
					'installment_months',
					'installment_amount',
					'subscription_period',
					'subscription_period_length',
					'subscription_amount',
					'excluded_destination',
					'included_destination',
					'fb_product_category',
					'product_highlight_1',
					'product_highlight_2',
					'product_highlight_3',
					'product_highlight_4',
					'product_highlight_5',
					'product_highlight_6',
					'product_highlight_7',
					'product_highlight_8',
					'product_highlight_9',
					'product_highlight_10',
					'min_handling_time',
					'max_handling_time',
					'min_transit_time',
					'max_transit_time',
					'shipping_label',
					'image',
					'feature_image',
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
					'categories',
					'product_full_cat',
					'primary_category',
					'primary_category_id',
					'child_category',
					'child_category_id',
					// BUG-0047 — selectable dropdown options that were absent from this
					// whitelist, so Attribute Mapping emitted their literal key name instead
					// of resolving them. All are handled by AttributeResolver (author/review/
					// gtin/images/custom_xml_* via the BUG-0050 fix).
					'parent_title',
					'parent_description',
					'description_with_html',
					'parent_link',
					'canonical_link',
					'ex_link',
					'external_link',
					'sku_id',
					'parent_sku',
					'availability_date',
					'reviewer_name',
					'weight_unit',
					'author_name',
					'author_email',
					'checkout_link_template',
					'gtin_upc_ean_isbn',
					'images',
					'unit_price_unit',
					'custom_xml_variations',
					'custom_xml_images',
					'custom_xml_categories',
				) 
			);
		}

		return isset( $known_attrs[ $item ] );
	}

	/**
	 * Clear the internal caches.
	 *
	 * Called between feed generation runs if the resolver is reused.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->config_cache = array();
	}
}
