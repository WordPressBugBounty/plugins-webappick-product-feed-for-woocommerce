<?php
/**
 * AttributeNameMapper — Maps internal attribute names to merchant-specific format.
 *
 * Replaces channel attribute names to their required format according to file type.
 * For example, Google Shopping XML needs 'g:id' while CSV needs 'id'.
 *
 * Ported from V5 MerchantAttributeReplaceFactory with V8 DI-friendly design.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-2.1
 */

namespace CTXFeed\V8\Channel;

use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps internal attribute names to merchant-specific attribute names.
 *
 * Handles attribute name mapping for 5+ merchant channels (Google, Facebook, Pinterest,
 * Skroutz, TikTok) across multiple feed formats (XML, CSV, JSON).
 *
 * @since 8.0.0
 */
class AttributeNameMapper {

	/**
	 * Attribute name mappings per channel and format.
	 *
	 * Structure: $mappings[provider][feed_type_normalized][internal_attribute] = mapped_name
	 *
	 * @var array
	 */
	private $mappings = array();

	/**
	 * Constructor.
	 *
	 * Initializes the attribute mappings from V5 MerchantAttributeReplaceFactory data.
	 *
	 * @since 8.0.0
	 */
	public function __construct() {
		$this->mappings = $this->build_mappings();
	}

	/**
	 * Maps a single internal attribute name to merchant-specific name.
	 *
	 * Returns the original attribute name if no mapping is found.
	 * Applies the ctxfeed_attribute_name_map filter for extensibility.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attribute Internal attribute name.
	 * @param string $provider  Merchant provider slug (google, facebook, pinterest, skroutz, tiktok).
	 * @param string $feed_type Feed format (xml, csv, json, txt, tsv).
	 *
	 * @return string Mapped merchant-specific attribute name.
	 */
	public function map_name( $attribute, $provider, $feed_type ) {
		// Normalize feed_type.
		$normalized_type = $this->normalize_feed_type( $feed_type );

		// Normalize provider for template grouping.
		$provider = $this->normalize_provider( $provider );

		// Strip the ##N duplicate-position suffix ProductRepository adds
		// when the user maps the same merchant attribute name twice
		// (e.g. two `section_name` entries for two product_detail groups).
		// The suffix must not leak into the output as a tag name.
		// PROD-FRD-10.11.
		$lookup_attr = ProductRepository::strip_dup_suffix( (string) $attribute );

		// Look up mapping.
		if ( isset( $this->mappings[ $provider ][ $normalized_type ][ $lookup_attr ] ) ) {
			$mapped_name = $this->mappings[ $provider ][ $normalized_type ][ $lookup_attr ];
		} else {
			$mapped_name = $lookup_attr;
		}

		// Apply filter for extensibility.
		$mapped_name = apply_filters(
			'ctxfeed_attribute_name_map',
			$mapped_name,
			$attribute,
			$provider,
			$feed_type
		);

		return $mapped_name;
	}

	/**
	 * Maps all keys in a product data array to merchant-specific names.
	 *
	 * Takes a product data array, returns a new array with keys remapped to
	 * merchant-specific names. Values are unchanged. Order is preserved.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Product data array with internal attribute names as keys.
	 * @param string $provider     Merchant provider slug.
	 * @param string $feed_type    Feed format.
	 *
	 * @return array Product data array with remapped keys.
	 */
	public function map_product_data( array $product_data, $provider, $feed_type ) {
		// Delimited formats (CSV/TSV/TXT) are rendered POSITIONALLY — the template
		// does array_values() and the header is built separately, one column per
		// mapped attribute (get_mapped_headers). So emit one value per input
		// attribute IN ORDER and NEVER collapse a same-merchant-name collision:
		// images_1..images_5 all map to additional_image_link, and the name-keyed
		// last-wins below would drop 4 of the 5, leaving the row short so
		// identifier_exists (and every column after the images) shifts one place
		// left — Google Merchant / Numbers then misread the columns. The value
		// keys are irrelevant to the delimited renderer, so no name mapping is
		// needed here; only order and count must match the header.
		if ( in_array( strtolower( (string) $feed_type ), array( 'csv', 'tsv', 'txt' ), true ) ) {
			$positional = array();
			foreach ( $product_data as $value ) {
				$positional[] = is_array( $value ) ? implode( ',', $value ) : $value;
			}
			return $positional;
		}

		$mapped_data = array();

		foreach ( $product_data as $attribute => $value ) {
			// Numeric keys: repeating group entries from GroupedAttributeBuilder.
			// These are already fully named — pass through without mapping.
			if ( is_numeric( $attribute ) ) {
				$mapped_data[] = $value;
				continue;
			}

			// Array values: nested group entries from GroupedAttributeBuilder.
			// The wrapper key and sub-element keys are already merchant-named.
			// Pass through without remapping to preserve the nested structure.
			if ( is_array( $value ) ) {
				$mapped_data[ $attribute ] = $value;
				continue;
			}

			// Flat scalar attribute — map the key name.
			$mapped_key = $this->map_name( $attribute, $provider, $feed_type );

			// Duplicate-mapping collision: the user mapped the SAME merchant
			// attribute more than once (e.g. two Custom-Template rows both named
			// "title"), which ProductRepository keeps distinct with a `##N`
			// suffix (`title`, `title##2`). map_name() strips that suffix, so
			// every occurrence resolves to the same $mapped_key. A plain
			// `$mapped_data[$mapped_key] = $value` would let each occurrence
			// OVERWRITE the previous one — silently dropping the first value.
			// Instead, promote the occurrences to the numeric-indexed
			// [mapped_key => value] repeating shape GroupedAttributeBuilder
			// emits and every template already renders as sibling elements /
			// repeated columns. Gated on the `##N` marker so genuinely distinct
			// sources that happen to share a merchant name (e.g. material_1 +
			// material_2 -> g:material) keep their existing last-wins behaviour.
			// PROD-FRD-10.11.
			$is_user_duplicate = ProductRepository::strip_dup_suffix( (string) $attribute ) !== (string) $attribute;

			if ( $is_user_duplicate && array_key_exists( $mapped_key, $mapped_data ) ) {
				// Second occurrence: convert the already-stored scalar and this
				// value into two repeating entries.
				$existing = $mapped_data[ $mapped_key ];
				unset( $mapped_data[ $mapped_key ] );
				$mapped_data[] = array( $mapped_key => $existing );
				$mapped_data[] = array( $mapped_key => $value );
			} elseif ( $is_user_duplicate && $this->has_repeating_entry( $mapped_data, $mapped_key ) ) {
				// Third+ occurrence: append to the existing repeating group.
				$mapped_data[] = array( $mapped_key => $value );
			} else {
				$mapped_data[ $mapped_key ] = $value;
			}
		}

		return $mapped_data;
	}

	/**
	 * Whether $mapped_data already holds a numeric-indexed repeating entry
	 * for $mapped_key (a single-element array `[ $mapped_key => value ]`),
	 * created by an earlier duplicate-mapping collision.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $mapped_data Accumulated mapped data.
	 * @param string $mapped_key  Merchant attribute name to look for.
	 *
	 * @return bool True when a repeating entry for $mapped_key exists.
	 */
	private function has_repeating_entry( array $mapped_data, $mapped_key ): bool {
		foreach ( $mapped_data as $index => $entry ) {
			if ( is_int( $index ) && is_array( $entry ) && 1 === count( $entry ) && array_key_exists( $mapped_key, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Maps attribute headers from config mattributes array.
	 *
	 * Takes the mattributes array from feed config and returns an array of
	 * mapped header names for CSV/TXT output.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $mattributes Array of attributes from feed config.
	 * @param string $provider    Merchant provider slug.
	 * @param string $feed_type   Feed format.
	 *
	 * @return array Array of mapped header names.
	 */
	public function get_mapped_headers( array $mattributes, $provider, $feed_type ) {
		$mapped_headers = array();

		foreach ( $mattributes as $attribute ) {
			$mapped_name      = $this->map_name( $attribute, $provider, $feed_type );
			$mapped_headers[] = $mapped_name;
		}

		return $mapped_headers;
	}

	/**
	 * Builds the complete mappings array from V5 data.
	 *
	 * Extracts the first element (mapped name) from each [mapped_name, boolean] tuple
	 * from the V5 MerchantAttributeReplaceFactory data structure.
	 *
	 * @since 8.0.0
	 *
	 * @return array Mappings indexed by [provider][normalized_format][attribute].
	 */
	private function build_mappings() {
		// Ported from CTXFeed\V5\Merchant\MerchantAttributeReplaceFactory::data().
		$v5_data = array(
			'google'    => array(
				'XML'  => array(
					'id'                                  => 'g:id',
					'webitemid'                           => 'g:webitemid',
					'region_id'                           => 'g:region_id',
					'title'                               => 'g:title',
					'description'                         => 'g:description',
					'link'                                => 'link',
					'canonical_link'                      => 'g:canonical_link',
					'mobile_link'                         => 'mobile_link',
					'product_type'                        => 'g:product_type',
					'current_category'                    => 'g:google_product_category',
					'image'                               => 'g:image_link',
					'images'                              => 'g:additional_image_link',
					'images_1'                            => 'g:additional_image_link',
					'images_2'                            => 'g:additional_image_link',
					'images_3'                            => 'g:additional_image_link',
					'images_4'                            => 'g:additional_image_link',
					'images_5'                            => 'g:additional_image_link',
					'images_6'                            => 'g:additional_image_link',
					'images_7'                            => 'g:additional_image_link',
					'images_8'                            => 'g:additional_image_link',
					'images_9'                            => 'g:additional_image_link',
					'images_10'                           => 'g:additional_image_link',
					'lifestyle_image'                     => 'g:lifestyle_image_link',
					'video_link'                          => 'g:video_link',
					'virtual_model_link'                  => 'g:virtual_model_link',
					'document_link'                       => 'g:document_link',
					'maximum_retail_price'                => 'g:maximum_retail_price',
					'popularity_rank'                     => 'g:popularity_rank',
					'disclosure_date'                     => 'g:disclosure_date',
					'free_shipping_threshold'             => 'g:free_shipping_threshold',
					'condition'                           => 'g:condition',
					'availability'                        => 'g:availability',
					'availability_date'                   => 'g:availability_date',
					'inventory'                           => 'g:inventory',
					'price'                               => 'g:price',
					'sale_price'                          => 'g:sale_price',
					'sale_price_effective_date'           => 'g:sale_price_effective_date',
					'auto_pricing_min_price'              => 'g:auto_pricing_min_price',
					'brand'                               => 'g:brand',
					'sku'                                 => 'g:mpn',
					'upc'                                 => 'g:gtin',
					'identifier_exists'                   => 'g:identifier_exists',
					'item_group_id'                       => 'g:item_group_id',
					'item_group_title'                    => 'g:item_group_title',
					'external_seller_id'                  => 'g:external_seller_id',
					'product_length'                      => 'g:product_length',
					'product_width'                       => 'g:product_width',
					'product_height'                      => 'g:product_height',
					'product_weight'                      => 'g:product_weight',
					'product_highlight_1'                 => 'g:product_highlight',
					'product_highlight_2'                 => 'g:product_highlight',
					'product_highlight_3'                 => 'g:product_highlight',
					'product_highlight_4'                 => 'g:product_highlight',
					'product_highlight_5'                 => 'g:product_highlight',
					'product_highlight_6'                 => 'g:product_highlight',
					'product_highlight_7'                 => 'g:product_highlight',
					'product_highlight_8'                 => 'g:product_highlight',
					'product_highlight_9'                 => 'g:product_highlight',
					'product_highlight_10'                => 'g:product_highlight',
					'color'                               => 'g:color',
					'gender'                              => 'g:gender',
					'age_group'                           => 'g:age_group',
					'material'                            => 'g:material',
					'pattern'                             => 'g:pattern',
					'size'                                => 'g:size',
					'size_type'                           => 'g:size_type',
					'size_system'                         => 'g:size_system',
					'tax'                                 => 'g:tax',
					'country'                             => 'g:country',
					'region'                              => 'g:region',
					'postal_code'                         => 'g:postal_code',
					'rate'                                => 'g:rate',
					'tax_ship'                            => 'g:tax_ship',
					'tax_category'                        => 'g:tax_category',
					'free_shipping_label'                 => 'g:free_shipping_label',
					'free_shipping_limit'                 => 'g:free_shipping_limit',
					'average_review_rating'               => 'g:average_review_rating',
					'number_of_ratings'                   => 'g:number_of_ratings',
					'number_of_reviews'                   => 'g:number_of_reviews',
					'checkout_link_template'              => 'g:checkout_link_template',
					'shipping'                            => 'g:shipping',
					'weight'                              => 'g:shipping_weight',
					'length'                              => 'g:shipping_length',
					'width'                               => 'g:shipping_width',
					'height'                              => 'g:shipping_height',
					'ships_from_country'                  => 'g:ships_from_country',
					'shipping_label'                      => 'g:shipping_label',
					'multipack'                           => 'g:multipack',
					'is_bundle'                           => 'g:is_bundle',
					'adult'                               => 'g:adult',
					'ads_redirect'                        => 'g:ads_redirect',
					'pause'                               => 'g:pause',
					'ads_labels'                          => 'g:ads_labels',
					'ads_grouping'                        => 'g:ads_grouping',
					'custom_label_0'                      => 'g:custom_label_0',
					'custom_label_1'                      => 'g:custom_label_1',
					'custom_label_2'                      => 'g:custom_label_2',
					'custom_label_3'                      => 'g:custom_label_3',
					'custom_label_4'                      => 'g:custom_label_4',
					'excluded_destination'                => 'g:excluded_destination',
					'shopping_ads_excluded_country'       => 'g:shopping_ads_excluded_country',
					'included_destination'                => 'g:included_destination',
					'expiration_date'                     => 'g:expiration_date',
					'unit_pricing_measure'                => 'g:unit_pricing_measure',
					'unit_pricing_base_measure'           => 'g:unit_pricing_base_measure',
					'subscription_cost'                   => 'g:subscription_cost',
					'months'                              => 'g:months',
					'amount'                              => 'g:amount',
					'period'                              => 'g:period',
					'period_length'                       => 'g:period_length',
					'product_detail'                      => 'g:product_detail',
					'section_name'                        => 'g:section_name',
					'attribute_name'                      => 'g:attribute_name',
					'attribute_value'                     => 'g:attribute_value',
					'product_highlight'                   => 'g:product_highlight',
					'material_1'                          => 'g:material',
					'material_2'                          => 'g:material',
					'energy_efficiency_class'             => 'g:energy_efficiency_class',
					'min_energy_efficiency_class'         => 'g:min_energy_efficiency_class',
					'max_energy_efficiency_class'         => 'g:max_energy_efficiency_class',
					'loyalty_points'                      => 'g:loyalty_points',
					'installment'                         => 'g:installment',
					'promotion_id'                        => 'g:promotion_id',
					'product_applicability'               => 'g:product_applicability',
					'offer_type'                          => 'g:offer_type',
					'long_title'                          => 'g:long_title',
					'promotion_effective_dates'           => 'g:promotion_effective_dates',
					'redemption_channel'                  => 'g:redemption_channel',
					'promotion_destination'               => 'g:promotion_destination',
					'percent_off'                         => 'g:percent_off',
					'money_off_amount'                    => 'g:money_off_amount',
					'get_this_quantity_discounted'        => 'g:get_this_quantity_discounted',
					'free_shipping'                       => 'g:free_shipping',
					'free_gift_value'                     => 'g:free_gift_value',
					'free_gift_description'               => 'g:free_gift_description',
					'promotion_display_dates'             => 'g:promotion_display_dates',
					'generic_redemption_code'             => 'g:generic_redemption_code',
					'fine_print'                          => 'g:fine_print',
					'promotion_price'                     => 'g:promotion_price',
					'coupon_value_type'                   => 'g:coupon_value_type',
					'limit_quantity'                      => 'g:limit_quantity',
					'limit_value'                         => 'g:limit_value',
					'minimum_purchase_amount'             => 'g:minimum_purchase_amount',
					'item_id_exclusion'                   => 'g:item_id_exclusion',
					'product_type_exclusion'              => 'g:product_type_exclusion',
					'brand_exclusion'                     => 'g:brand_exclusion',
					'item_group_id_exclusion'             => 'g:item_group_id_exclusion',
					'cost_of_goods_sold'                  => 'g:cost_of_goods_sold',
					'sell_on_google_quantity'             => 'g:sell_on_google_quantity',
					'location_id'                         => 'g:location_id',
					'location_group_name'                 => 'g:location_group_name',
					'min_handling_time'                   => 'g:min_handling_time',
					'max_handling_time'                   => 'g:max_handling_time',
					'max_transit_time'                    => 'g:max_transit_time',
					'min_transit_time'                    => 'g:min_transit_time',
					'transit_time_label'                  => 'g:transit_time_label',
					'return_address_label'                => 'g:return_address_label',
					'return_policy_label'                 => 'g:return_policy_label',
					'store_code'                          => 'g:store_code',
					'quantity'                            => 'g:quantity',
					'pickup_method'                       => 'g:pickup_method',
					'pickup_sla'                          => 'g:pickup_sla',
					'pickup_link_template'                => 'g:pickup_link_template',
					'link_template'                       => 'g:link_template',
					'mobile_link_template'                => 'g:mobile_link_template',
					'mobile_pickup_link_template'         => 'g:mobile_pickup_link_template',
					'local_shipping_label'                => 'g:local_shipping_label',
					'capacity'                            => 'g:capacity',
					'google_funded_promotion_eligibility' => 'g:google_funded_promotion_eligibility',
					'certified_pre-owned'                 => 'g:certified_pre-owned',
				),
				'CSV'  => array(
					'id'                                  => 'id',
					'webitemid'                           => 'webitemid',
					'region_id'                           => 'region_id',
					'title'                               => 'title',
					'description'                         => 'description',
					'link'                                => 'link',
					'canonical_link'                      => 'canonical link',
					'mobile_link'                         => 'mobile_link',
					'product_type'                        => 'product type',
					'current_category'                    => 'google product category',
					'image'                               => 'image link',
					'images'                              => 'additional image link',
					'images_1'                            => 'additional image link',
					'images_2'                            => 'additional image link',
					'images_3'                            => 'additional image link',
					'images_4'                            => 'additional image link',
					'images_5'                            => 'additional image link',
					'images_6'                            => 'additional image link',
					'images_7'                            => 'additional image link',
					'images_8'                            => 'additional image link',
					'images_9'                            => 'additional image link',
					'images_10'                           => 'additional image link',
					'lifestyle_image'                     => 'lifestyle image link',
					'video_link'                          => 'video_link',
					'virtual_model_link'                  => 'virtual model link',
					'document_link'                       => 'document link',
					'maximum_retail_price'                => 'maximum retail price',
					'popularity_rank'                     => 'popularity rank',
					'disclosure_date'                     => 'disclosure date',
					'free_shipping_threshold'             => 'free shipping threshold',
					'condition'                           => 'condition',
					'availability'                        => 'availability',
					'availability_date'                   => 'availability date',
					'inventory'                           => 'inventory',
					'price'                               => 'price',
					'sale_price'                          => 'sale price',
					'sale_price_effective_date'           => 'sale price effective date',
					'auto_pricing_min_price'              => 'auto pricing minimum price',
					'brand'                               => 'brand',
					'sku'                                 => 'mpn',
					'upc'                                 => 'gtin',
					'identifier_exists'                   => 'identifier exists',
					'item_group_id'                       => 'item group id',
					'item_group_title'                    => 'item group title',
					'external_seller_id'                  => 'external seller id',
					'product_length'                      => 'product length',
					'product_width'                       => 'product width',
					'product_height'                      => 'product height',
					'product_weight'                      => 'product weight',
					'product_highlight'                   => 'product highlight',
					'color'                               => 'color',
					'gender'                              => 'gender',
					'age_group'                           => 'age group',
					'material'                            => 'material',
					'pattern'                             => 'pattern',
					'size'                                => 'size',
					'size_type'                           => 'size type',
					'size_system'                         => 'size system',
					'free_shipping_label'                 => 'free_shipping_label',
					'free_shipping_limit'                 => 'free_shipping_limit',
					'average_review_rating'               => 'average_review_rating',
					'number_of_ratings'                   => 'number_of_ratings',
					'number_of_reviews'                   => 'number_of_reviews',
					'checkout_link_template'              => 'checkout link template',
					'tax'                                 => 'tax(country:region:rate:postal_code:tax_ship)',
					'tax_category'                        => 'tax category',
					'shipping'                            => 'shipping(country:state:service:price)',
					'weight'                              => 'shipping weight',
					'length'                              => 'shipping length',
					'width'                               => 'shipping width',
					'height'                              => 'shipping height',
					'ships_from_country'                  => 'ships from country',
					'shipping_label'                      => 'shipping label',
					'shipping_country'                    => 'shipping country',
					'shipping_service'                    => 'shipping service',
					'shipping_price'                      => 'shipping price',
					'shipping_region'                     => 'shipping region',
					'multipack'                           => 'multipack',
					'is_bundle'                           => 'is bundle',
					'adult'                               => 'adult',
					'ads_redirect'                        => 'ads redirect',
					'pause'                               => 'pause',
					'custom_label_0'                      => 'custom label 0',
					'custom_label_1'                      => 'custom label 1',
					'custom_label_2'                      => 'custom label 2',
					'custom_label_3'                      => 'custom label 3',
					'custom_label_4'                      => 'custom label 4',
					'excluded_destination'                => 'excluded destination',
					'shopping_ads_excluded_country'       => 'shopping ads excluded country',
					'included_destination'                => 'included destination',
					'expiration_date'                     => 'expiration date',
					'unit_pricing_measure'                => 'unit pricing measure',
					'unit_pricing_base_measure'           => 'unit pricing base measure',
					'installment_months'                  => 'months',
					'installment_amount'                  => 'amount',
					'subscription_period'                 => 'period',
					'subscription_period_length'          => 'period_length',
					'subscription_amount'                 => 'amount',
					'energy_efficiency_class'             => 'energy efficiency class',
					'min_energy_efficiency_class'         => 'min energy efficiency class',
					'max_energy_efficiency_class'         => 'max energy efficiency class',
					'loyalty_points'                      => 'loyalty points',
					'installment'                         => 'installment',
					'promotion_id'                        => 'promotion id',
					'cost_of_goods_sold'                  => 'cost of goods sold',
					'sell_on_google_quantity'             => 'sell on google quantity',
					'min_handling_time'                   => 'min handling time',
					'max_handling_time'                   => 'max handling time',
					'transit_time_label'                  => 'transit time label',
					'return_address_label'                => 'return address label',
					'return_policy_label'                 => 'return policy label',
					'store_code'                          => 'store code',
					'pickup_method'                       => 'pickup method',
					'pickup_sla'                          => 'pickup sla',
					'pickup_link_template'                => 'pickup link template',
					'link_template'                       => 'link template',
					'mobile_link_template'                => 'mobile link template',
					'mobile_pickup_link_template'         => 'mobile pickup link template',
					'local_shipping_label'                => 'g:local_shipping_label',
					'google_funded_promotion_eligibility' => 'google funded promotion eligibility',
				),
				'JSON' => array(),
			),
			'facebook'  => array(
				'XML'  => array(
					'id'                           => 'g:id',
					'title'                        => 'g:title',
					'description'                  => 'g:description',
					'link'                         => 'g:link',
					'mobile_link'                  => 'g:mobile_link',
					'product_type'                 => 'g:product_type',
					'current_category'             => 'g:google_product_category',
					'image'                        => 'g:image_link',
					'images'                       => 'g:additional_image_link',
					'images_1'                     => 'g:additional_image_link',
					'images_2'                     => 'g:additional_image_link',
					'images_3'                     => 'g:additional_image_link',
					'images_4'                     => 'g:additional_image_link',
					'images_5'                     => 'g:additional_image_link',
					'images_6'                     => 'g:additional_image_link',
					'images_7'                     => 'g:additional_image_link',
					'images_8'                     => 'g:additional_image_link',
					'images_9'                     => 'g:additional_image_link',
					'images_10'                    => 'g:additional_image_link',
					'condition'                    => 'g:condition',
					'availability'                 => 'g:availability',
					'availability_circle_origin'   => 'g:availability_circle_origin',
					'inventory'                    => 'g:inventory',
					'override'                     => 'g:override',
					'price'                        => 'g:price',
					'sale_price'                   => 'g:sale_price',
					'sale_price_effective_date'    => 'g:sale_price_effective_date',
					'brand'                        => 'g:brand',
					'sku'                          => 'g:mpn',
					'upc'                          => 'g:gtin',
					'identifier_exists'            => 'g:identifier_exists',
					'item_group_id'                => 'g:item_group_id',
					'item_group_title'             => 'g:item_group_title',
					'color'                        => 'g:color',
					'gender'                       => 'g:gender',
					'age_group'                    => 'g:age_group',
					'material'                     => 'g:material',
					'pattern'                      => 'g:pattern',
					'size'                         => 'g:size',
					'size_type'                    => 'g:size_type',
					'size_system'                  => 'g:size_system',
					'tax'                          => 'g:tax',
					'weight'                       => 'g:shipping_weight',
					'length'                       => 'g:shipping_length',
					'width'                        => 'g:shipping_width',
					'height'                       => 'g:shipping_height',
					'shipping'                     => 'g:shipping',
					'ships_from_country'           => 'g:ships_from_country',
					'shipping_label'               => 'g:shipping_label',
					'shipping_country'             => 'g:shipping_country',
					'shipping_service'             => 'g:shipping_service',
					'shipping_price'               => 'g:shipping_price',
					'shipping_region'              => 'g:shipping_region',
					'multipack'                    => 'g:multipack',
					'is_bundle'                    => 'g:is_bundle',
					'adult'                        => 'g:adult',
					'adwords_redirect'             => 'g:adwords_redirect',
					'internal_label'               => 'g:internal_label',
					'custom_label_0'               => 'g:custom_label_0',
					'custom_label_1'               => 'g:custom_label_1',
					'custom_label_2'               => 'g:custom_label_2',
					'custom_label_3'               => 'g:custom_label_3',
					'custom_label_4'               => 'g:custom_label_4',
					'excluded_destination'         => 'g:excluded_destination',
					'expiration_date'              => 'g:expiration_date',
					'unit_pricing_measure'         => 'g:unit_pricing_measure',
					'unit_pricing_base_measure'    => 'g:unit_pricing_base_measure',
					'energy_efficiency_class'      => 'g:energy_efficiency_class',
					'loyalty_points'               => 'g:loyalty_points',
					'installment'                  => 'g:installment',
					'promotion_id'                 => 'g:promotion_id',
					'cost_of_goods_sold'           => 'g:cost_of_goods_sold',
					'availability_date'            => 'g:availability_date',
					'tax_category'                 => 'g:tax_category',
					'included_destination'         => 'g:included_destination',
					'quantity_to_sell_on_facebook' => 'g:quantity_to_sell_on_facebook',
				),
				'CSV'  => array(
					'id'                           => 'id',
					'title'                        => 'title',
					'description'                  => 'description',
					'link'                         => 'link',
					'mobile_link'                  => 'mobile_link',
					'product_type'                 => 'product_type',
					'current_category'             => 'google_product_category',
					'image'                        => 'image_link',
					'images'                       => 'additional_image_link',
					'additional_image_link'        => 'additional_image_link',
					'images_1'                     => 'additional_image_link',
					'images_2'                     => 'additional_image_link',
					'images_3'                     => 'additional_image_link',
					'images_4'                     => 'additional_image_link',
					'images_5'                     => 'additional_image_link',
					'images_6'                     => 'additional_image_link',
					'images_7'                     => 'additional_image_link',
					'images_8'                     => 'additional_image_link',
					'images_9'                     => 'additional_image_link',
					'images_10'                    => 'additional_image_link',
					'condition'                    => 'condition',
					'availability'                 => 'availability',
					'availability_circle_origin'   => 'availability_circle_origin',
					'inventory'                    => 'inventory',
					'override'                     => 'override',
					'price'                        => 'price',
					'sale_price'                   => 'sale_price',
					'sale_price_effective_date'    => 'sale_price_effective_date',
					'brand'                        => 'brand',
					'sku'                          => 'mpn',
					'upc'                          => 'gtin',
					'identifier_exists'            => 'identifier_exists',
					'item_group_id'                => 'item_group_id',
					'item_group_title'             => 'item_group_title',
					'color'                        => 'color',
					'gender'                       => 'gender',
					'age_group'                    => 'age_group',
					'material'                     => 'material',
					'pattern'                      => 'pattern',
					'size'                         => 'size',
					'size_type'                    => 'size_type',
					'size_system'                  => 'size_system',
					'tax'                          => 'tax',
					'weight'                       => 'shipping_weight',
					'length'                       => 'shipping_length',
					'width'                        => 'shipping_width',
					'height'                       => 'shipping_height',
					'ships_from_country'           => 'ships_from_country',
					'shipping_label'               => 'shipping_label',
					'shipping_country'             => 'shipping_country',
					'shipping_service'             => 'shipping_service',
					'shipping_price'               => 'shipping_price',
					'shipping_region'              => 'shipping_region',
					'multipack'                    => 'multipack',
					'is_bundle'                    => 'is_bundle',
					'adult'                        => 'adult',
					'adwords_redirect'             => 'adwords_redirect',
					'internal_label'               => 'internal_label',
					'custom_label_0'               => 'custom_label_0',
					'custom_label_1'               => 'custom_label_1',
					'custom_label_2'               => 'custom_label_2',
					'custom_label_3'               => 'custom_label_3',
					'custom_label_4'               => 'custom_label_4',
					'excluded_destination'         => 'excluded_destination',
					'expiration_date'              => 'expiration_date',
					'unit_pricing_measure'         => 'unit_pricing_measure',
					'unit_pricing_base_measure'    => 'unit_pricing_base_measure',
					'energy_efficiency_class'      => 'energy_efficiency_class',
					'loyalty_points'               => 'loyalty_points',
					'installment'                  => 'installment',
					'promotion_id'                 => 'promotion_id',
					'cost_of_goods_sold'           => 'cost_of_goods_sold',
					'availability_date'            => 'availability_date',
					'tax_category'                 => 'tax_category',
					'included_destination'         => 'included_destination',
					'quantity_to_sell_on_facebook' => 'quantity_to_sell_on_facebook',
					'video_url_0'                  => 'video[0].url',
					'video_url_1'                  => 'video[1].url',
					'video_url_2'                  => 'video[2].url',
					'video_url_3'                  => 'video[3].url',
					'video_url_4'                  => 'video[4].url',
					'video_url_5'                  => 'video[5].url',
					'video_url_6'                  => 'video[6].url',
					'video_url_7'                  => 'video[7].url',
					'video_url_8'                  => 'video[8].url',
					'video_url_9'                  => 'video[9].url',
					'video_url_10'                 => 'video[10].url',
					'video_url_11'                 => 'video[11].url',
					'video_url_12'                 => 'video[12].url',
					'video_url_13'                 => 'video[13].url',
					'video_url_14'                 => 'video[14].url',
					'video_url_15'                 => 'video[15].url',
					'video_url_16'                 => 'video[16].url',
					'video_url_17'                 => 'video[17].url',
					'video_url_18'                 => 'video[18].url',
					'video_url_19'                 => 'video[19].url',
					'custom_number_0'              => 'custom_number_0',
					'custom_number_1'              => 'custom_number_1',
					'custom_number_2'              => 'custom_number_2',
					'custom_number_3'              => 'custom_number_3',
					'custom_number_4'              => 'custom_number_4',
					'vendor_id'                    => 'vendor_id',
					'base_price'                   => 'base_price',
					'wa_compliance_category'       => 'wa_compliance_category',
					'disabled_capabilities'        => 'disabled_capabilities',
					'applink_ios_url'              => 'applink.ios_url',
					'applink_ios_app_store_id'     => 'applink.ios_app_store_id',
					'applink_ios_app_name'         => 'applink.ios_app_name',
					'applink_android_url'          => 'applink.android_url',
					'applink_android_package'      => 'applink.android_package',
					'applink_android_app_name'     => 'applink.android_app_name',
				),
				'JSON' => array(),
			),
			'pinterest' => array(
				'XML'  => array(
					'id'                        => 'g:id',
					'title'                     => 'title',
					'description'               => 'description',
					'link'                      => 'link',
					'mobile_link'               => 'mobile_link',
					'product_type'              => 'g:product_type',
					'current_category'          => 'g:google_product_category',
					'image'                     => 'g:image_link',
					'images'                    => 'g:additional_image_link',
					'images_1'                  => 'g:additional_image_link',
					'images_2'                  => 'g:additional_image_link',
					'images_3'                  => 'g:additional_image_link',
					'images_4'                  => 'g:additional_image_link',
					'images_5'                  => 'g:additional_image_link',
					'images_6'                  => 'g:additional_image_link',
					'images_7'                  => 'g:additional_image_link',
					'images_8'                  => 'g:additional_image_link',
					'images_9'                  => 'g:additional_image_link',
					'images_10'                 => 'g:additional_image_link',
					'condition'                 => 'g:condition',
					'availability'              => 'g:availability',
					'availability_date'         => 'g:availability_date',
					'inventory'                 => 'g:inventory',
					'price'                     => 'g:price',
					'sale_price'                => 'g:sale_price',
					'sale_price_effective_date' => 'g:sale_price_effective_date',
					'brand'                     => 'g:brand',
					'sku'                       => 'g:mpn',
					'upc'                       => 'g:gtin',
					'identifier_exists'         => 'g:identifier_exists',
					'item_group_id'             => 'g:item_group_id',
					'item_group_title'          => 'g:item_group_title',
					'color'                     => 'g:color',
					'gender'                    => 'g:gender',
					'age_group'                 => 'g:age_group',
					'material'                  => 'g:material',
					'pattern'                   => 'g:pattern',
					'size'                      => 'g:size',
					'size_type'                 => 'g:size_type',
					'size_system'               => 'g:size_system',
					'tax'                       => 'g:tax',
					'tax_country'               => 'g:tax_country',
					'tax_region'                => 'g:tax_region',
					'tax_rate'                  => 'g:tax_rate',
					'tax_ship'                  => 'g:tax_ship',
					'tax_category'              => 'g:tax_category',
					'free_shipping_label'       => 'g:free_shipping_label',
					'free_shipping_limit'       => 'g:free_shipping_limit',
					'average_review_rating'     => 'g:average_review_rating',
					'number_of_ratings'         => 'g:number_of_ratings',
					'number_of_reviews'         => 'g:number_of_reviews',
					'weight'                    => 'g:shipping_weight',
					'length'                    => 'g:shipping_length',
					'width'                     => 'g:shipping_width',
					'height'                    => 'g:shipping_height',
					'ships_from_country'        => 'g:ships_from_country',
					'shipping'                  => 'g:shipping',
					'shipping_label'            => 'g:shipping_label',
					'shipping_country'          => 'g:shipping_country',
					'shipping_service'          => 'g:shipping_service',
					'shipping_price'            => 'g:shipping_price',
					'shipping_region'           => 'g:shipping_region',
					'multipack'                 => 'g:multipack',
					'is_bundle'                 => 'g:is_bundle',
					'adult'                     => 'g:adult',
					'adwords_redirect'          => 'g:adwords_redirect',
					'custom_label_0'            => 'g:custom_label_0',
					'custom_label_1'            => 'g:custom_label_1',
					'custom_label_2'            => 'g:custom_label_2',
					'custom_label_3'            => 'g:custom_label_3',
					'custom_label_4'            => 'g:custom_label_4',
					'excluded_destination'      => 'g:excluded_destination',
					'included_destination'      => 'g:included_destination',
					'expiration_date'           => 'g:expiration_date',
					'unit_pricing_measure'      => 'g:unit_pricing_measure',
					'unit_pricing_base_measure' => 'g:unit_pricing_base_measure',
					'energy_efficiency_class'   => 'g:energy_efficiency_class',
					'loyalty_points'            => 'g:loyalty_points',
					'installment'               => 'g:installment',
					'promotion_id'              => 'g:promotion_id',
					'cost_of_goods_sold'        => 'g:cost_of_goods_sold',
				),
				'CSV'  => array(
					'id'                        => 'id',
					'title'                     => 'title',
					'description'               => 'description',
					'link'                      => 'link',
					'mobile_link'               => 'mobile_link',
					'product_type'              => 'product_type',
					'current_category'          => 'google_product_category',
					'image'                     => 'image_link',
					'images'                    => 'additional_image_link',
					'images_1'                  => 'additional_image_link',
					'images_2'                  => 'additional_image_link',
					'images_3'                  => 'additional_image_link',
					'images_4'                  => 'additional_image_link',
					'images_5'                  => 'additional_image_link',
					'images_6'                  => 'additional_image_link',
					'images_7'                  => 'additional_image_link',
					'images_8'                  => 'additional_image_link',
					'images_9'                  => 'additional_image_link',
					'images_10'                 => 'additional_image_link',
					'condition'                 => 'condition',
					'availability'              => 'availability',
					'availability_date'         => 'availability_date',
					'inventory'                 => 'inventory',
					'price'                     => 'price',
					'sale_price'                => 'sale_price',
					'sale_price_effective_date' => 'sale_price_effective_date',
					'brand'                     => 'brand',
					'sku'                       => 'mpn',
					'upc'                       => 'gtin',
					'identifier_exists'         => 'identifier_exists',
					'item_group_id'             => 'item_group_id',
					'item_group_title'          => 'item_group_title',
					'color'                     => 'color',
					'gender'                    => 'gender',
					'age_group'                 => 'age_group',
					'material'                  => 'material',
					'pattern'                   => 'pattern',
					'size'                      => 'size',
					'size_type'                 => 'size_type',
					'size_system'               => 'size_system',
					'tax'                       => 'tax',
					'tax_country'               => 'tax_country',
					'tax_region'                => 'tax_region',
					'tax_rate'                  => 'tax_rate',
					'tax_ship'                  => 'tax_ship',
					'tax_category'              => 'tax_category',
					'free_shipping_label'       => 'free_shipping_label',
					'free_shipping_limit'       => 'free_shipping_limit',
					'average_review_rating'     => 'average_review_rating',
					'number_of_ratings'         => 'number_of_ratings',
					'number_of_reviews'         => 'number_of_reviews',
					'weight'                    => 'shipping_weight',
					'length'                    => 'shipping_length',
					'width'                     => 'shipping_width',
					'height'                    => 'shipping_height',
					'ships_from_country'        => 'ships_from_country',
					'shipping_label'            => 'shipping_label',
					'shipping_country'          => 'shipping_country',
					'shipping_service'          => 'shipping_service',
					'shipping_price'            => 'shipping_price',
					'shipping_region'           => 'shipping_region',
					'multipack'                 => 'multipack',
					'is_bundle'                 => 'is_bundle',
					'adult'                     => 'adult',
					'adwords_redirect'          => 'adwords_redirect',
					'custom_label_0'            => 'custom_label_0',
					'custom_label_1'            => 'custom_label_1',
					'custom_label_2'            => 'custom_label_2',
					'custom_label_3'            => 'custom_label_3',
					'custom_label_4'            => 'custom_label_4',
					'excluded_destination'      => 'excluded_destination',
					'included_destination'      => 'included_destination',
					'expiration_date'           => 'expiration_date',
					'unit_pricing_measure'      => 'unit_pricing_measure',
					'unit_pricing_base_measure' => 'unit_pricing_base_measure',
					'energy_efficiency_class'   => 'energy_efficiency_class',
					'loyalty_points'            => 'loyalty_points',
					'installment'               => 'installment',
					'promotion_id'              => 'promotion_id',
					'cost_of_goods_sold'        => 'cost_of_goods_sold',
				),
				'JSON' => array(),
			),
			'skroutz'   => array(
				'XML'  => array(
					'id'                     => 'id',
					'name'                   => 'name',
					'description'            => 'description',
					'link'                   => 'link',
					'image'                  => 'image',
					'category'               => 'category',
					'price'                  => 'price',
					'price_with_vat'         => 'price_with_vat',
					'manufacturer'           => 'manufacturer',
					'mpn'                    => 'mpn',
					'ean'                    => 'ean',
					'instock'                => 'instock',
					'availability'           => 'availability',
					'color'                  => 'color',
					'size'                   => 'size',
					'weight'                 => 'weight',
					'quantity'               => 'quantity',
					'additional_imageurl'    => 'additional_imageurl',
					'additional_imageurl_1'  => 'additional_imageurl',
					'additional_imageurl_2'  => 'additional_imageurl',
					'additional_imageurl_3'  => 'additional_imageurl',
					'additional_imageurl_4'  => 'additional_imageurl',
					'additional_imageurl_5'  => 'additional_imageurl',
					'additional_imageurl_6'  => 'additional_imageurl',
					'additional_imageurl_7'  => 'additional_imageurl',
					'additional_imageurl_8'  => 'additional_imageurl',
					'additional_imageurl_9'  => 'additional_imageurl',
					'additional_imageurl_10' => 'additional_imageurl',
					'lifestyle_image'        => 'lifestyle_imageurl',
					'vat'                    => 'vat',
					'shipping'               => 'shipping',
					'wholesale_price'        => 'wholesale_price',
					'season'                 => 'season',
					'size_fit'               => 'size_fit',
					'outlet'                 => 'outlet',
					'author'                 => 'author',
					'country_of_origin'      => 'country_of_origin',
					'expiration_date'        => 'expiration_date',
				),
				'CSV'  => array(),
				'JSON' => array(),
			),
			'tiktok'    => array(
				'XML' => array(
					'id'                                  => 'g:id',
					'sku_id'                              => 'g:sku_id',
					'webitemid'                           => 'g:webitemid',
					'region_id'                           => 'g:region_id',
					'title'                               => 'g:title',
					'description'                         => 'g:description',
					'link'                                => 'g:link',
					'canonical_link'                      => 'g:canonical_link',
					'mobile_link'                         => 'mobile_link',
					'product_type'                        => 'g:product_type',
					'google_product_category'             => 'g:google_product_category',
					'image_link'                          => 'g:image_link',
					'additional_image_link'               => 'g:additional_image_link',
					'images_1'                            => 'g:additional_image_link',
					'images_2'                            => 'g:additional_image_link',
					'images_3'                            => 'g:additional_image_link',
					'images_4'                            => 'g:additional_image_link',
					'images_5'                            => 'g:additional_image_link',
					'images_6'                            => 'g:additional_image_link',
					'images_7'                            => 'g:additional_image_link',
					'images_8'                            => 'g:additional_image_link',
					'images_9'                            => 'g:additional_image_link',
					'images_10'                           => 'g:additional_image_link',
					'lifestyle_image'                     => 'g:lifestyle_image_link',
					'condition'                           => 'g:condition',
					'availability'                        => 'g:availability',
					'availability_date'                   => 'g:availability_date',
					'inventory'                           => 'g:inventory',
					'price'                               => 'g:price',
					'sale_price'                          => 'g:sale_price',
					'sale_price_effective_date'           => 'g:sale_price_effective_date',
					'auto_pricing_min_price'              => 'g:auto_pricing_min_price',
					'brand'                               => 'g:brand',
					'sku'                                 => 'g:mpn',
					'upc'                                 => 'g:gtin',
					'identifier_exists'                   => 'g:identifier_exists',
					'item_group_id'                       => 'g:item_group_id',
					'item_group_title'                    => 'g:item_group_title',
					'external_seller_id'                  => 'g:external_seller_id',
					'product_length'                      => 'g:product_length',
					'product_width'                       => 'g:product_width',
					'product_height'                      => 'g:product_height',
					'product_weight'                      => 'g:product_weight',
					'product_highlight_1'                 => 'g:product_highlight',
					'product_highlight_2'                 => 'g:product_highlight',
					'product_highlight_3'                 => 'g:product_highlight',
					'product_highlight_4'                 => 'g:product_highlight',
					'product_highlight_5'                 => 'g:product_highlight',
					'product_highlight_6'                 => 'g:product_highlight',
					'product_highlight_7'                 => 'g:product_highlight',
					'product_highlight_8'                 => 'g:product_highlight',
					'product_highlight_9'                 => 'g:product_highlight',
					'product_highlight_10'                => 'g:product_highlight',
					'color'                               => 'g:color',
					'gender'                              => 'g:gender',
					'age_group'                           => 'g:age_group',
					'material'                            => 'g:material',
					'pattern'                             => 'g:pattern',
					'size'                                => 'g:size',
					'size_type'                           => 'g:size_type',
					'size_system'                         => 'g:size_system',
					'tax'                                 => 'g:tax',
					'country'                             => 'g:country',
					'region'                              => 'g:region',
					'postal_code'                         => 'g:postal_code',
					'rate'                                => 'g:rate',
					'tax_ship'                            => 'g:tax_ship',
					'tax_category'                        => 'g:tax_category',
					'free_shipping_label'                 => 'g:free_shipping_label',
					'free_shipping_limit'                 => 'g:free_shipping_limit',
					'average_review_rating'               => 'g:average_review_rating',
					'number_of_ratings'                   => 'g:number_of_ratings',
					'number_of_reviews'                   => 'g:number_of_reviews',
					'shipping'                            => 'g:shipping',
					'weight'                              => 'g:shipping_weight',
					'length'                              => 'g:shipping_length',
					'width'                               => 'g:shipping_width',
					'height'                              => 'g:shipping_height',
					'ships_from_country'                  => 'g:ships_from_country',
					'shipping_label'                      => 'g:shipping_label',
					'multipack'                           => 'g:multipack',
					'is_bundle'                           => 'g:is_bundle',
					'adult'                               => 'g:adult',
					'ads_redirect'                        => 'g:ads_redirect',
					'pause'                               => 'g:pause',
					'custom_label_0'                      => 'g:custom_label_0',
					'custom_label_1'                      => 'g:custom_label_1',
					'custom_label_2'                      => 'g:custom_label_2',
					'custom_label_3'                      => 'g:custom_label_3',
					'custom_label_4'                      => 'g:custom_label_4',
					'excluded_destination'                => 'g:excluded_destination',
					'shopping_ads_excluded_country'       => 'g:shopping_ads_excluded_country',
					'included_destination'                => 'g:included_destination',
					'expiration_date'                     => 'g:expiration_date',
					'unit_pricing_measure'                => 'g:unit_pricing_measure',
					'unit_pricing_base_measure'           => 'g:unit_pricing_base_measure',
					'subscription_cost'                   => 'g:subscription_cost',
					'months'                              => 'g:months',
					'amount'                              => 'g:amount',
					'period'                              => 'g:period',
					'product_detail'                      => 'g:product_detail',
					'section_name'                        => 'g:section_name',
					'attribute_name'                      => 'g:attribute_name',
					'attribute_value'                     => 'g:attribute_value',
					'product_highlight'                   => 'g:product_highlight',
					'material_1'                          => 'g:material',
					'material_2'                          => 'g:material',
					'energy_efficiency_class'             => 'g:energy_efficiency_class',
					'min_energy_efficiency_class'         => 'g:min_energy_efficiency_class',
					'max_energy_efficiency_class'         => 'g:max_energy_efficiency_class',
					'loyalty_points'                      => 'g:loyalty_points',
					'installment'                         => 'g:installment',
					'promotion_id'                        => 'g:promotion_id',
					'product_applicability'               => 'g:product_applicability',
					'offer_type'                          => 'g:offer_type',
					'long_title'                          => 'g:long_title',
					'promotion_effective_dates'           => 'g:promotion_effective_dates',
					'redemption_channel'                  => 'g:redemption_channel',
					'promotion_destination'               => 'g:promotion_destination',
					'percent_off'                         => 'g:percent_off',
					'money_off_amount'                    => 'g:money_off_amount',
					'get_this_quantity_discounted'        => 'g:get_this_quantity_discounted',
					'free_shipping'                       => 'g:free_shipping',
					'free_gift_value'                     => 'g:free_gift_value',
					'free_gift_description'               => 'g:free_gift_description',
					'promotion_display_dates'             => 'g:promotion_display_dates',
					'generic_redemption_code'             => 'g:generic_redemption_code',
					'fine_print'                          => 'g:fine_print',
					'promotion_price'                     => 'g:promotion_price',
					'coupon_value_type'                   => 'g:coupon_value_type',
					'limit_quantity'                      => 'g:limit_quantity',
					'limit_value'                         => 'g:limit_value',
					'minimum_purchase_amount'             => 'g:minimum_purchase_amount',
					'item_id_exclusion'                   => 'g:item_id_exclusion',
					'product_type_exclusion'              => 'g:product_type_exclusion',
					'brand_exclusion'                     => 'g:brand_exclusion',
					'item_group_id_exclusion'             => 'g:item_group_id_exclusion',
					'cost_of_goods_sold'                  => 'g:cost_of_goods_sold',
					'sell_on_google_quantity'             => 'g:sell_on_google_quantity',
					'location_id'                         => 'g:location_id',
					'location_group_name'                 => 'g:location_group_name',
					'min_handling_time'                   => 'g:min_handling_time',
					'max_handling_time'                   => 'g:max_handling_time',
					'max_transit_time'                    => 'g:max_transit_time',
					'min_transit_time'                    => 'g:min_transit_time',
					'transit_time_label'                  => 'g:transit_time_label',
					'return_address_label'                => 'g:return_address_label',
					'return_policy_label'                 => 'g:return_policy_label',
					'store_code'                          => 'g:store_code',
					'quantity'                            => 'g:quantity',
					'pickup_method'                       => 'g:pickup_method',
					'pickup_sla'                          => 'g:pickup_sla',
					'pickup_link_template'                => 'g:pickup_link_template',
					'link_template'                       => 'g:link_template',
					'mobile_link_template'                => 'g:mobile_link_template',
					'mobile_pickup_link_template'         => 'g:mobile_pickup_link_template',
					'capacity'                            => 'g:capacity',
					'google_funded_promotion_eligibility' => 'g:google_funded_promotion_eligibility',
				),
				'CSV' => array(),
			),
		);

		// Normalize and return mappings.
		$mappings = array();
		foreach ( $v5_data as $provider => $formats ) {
			$normalized_provider              = $this->normalize_provider( $provider );
			$mappings[ $normalized_provider ] = array();

			foreach ( $formats as $format => $attributes ) {
				$normalized_format                                      = $this->normalize_feed_type( $format );
				$mappings[ $normalized_provider ][ $normalized_format ] = $attributes;
			}
		}

		return $mappings;
	}

	/**
	 * Normalizes feed type to standard uppercase format.
	 *
	 * - 'xml' -> 'XML'
	 * - 'csv'/'tsv'/'txt' -> 'CSV' (V5 treats TSV/TXT as CSV format)
	 * - 'json' -> 'JSON'
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_type Feed format string.
	 *
	 * @return string Normalized format string.
	 */
	private function normalize_feed_type( $feed_type ) {
		$feed_type = strtolower( trim( $feed_type ) );

		if ( in_array( $feed_type, array( 'csv', 'tsv', 'txt' ), true ) ) {
			return 'CSV';
		}

		if ( 'xml' === $feed_type ) {
			return 'XML';
		}

		if ( 'json' === $feed_type ) {
			return 'JSON';
		}

		// Fallback to uppercase.
		return strtoupper( $feed_type );
	}

	/**
	 * Normalizes provider slug for template grouping.
	 *
	 * Groups similar providers:
	 * - 'google_shopping_action', 'google_local', 'google_local_inventory' -> 'google'
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Provider slug.
	 *
	 * @return string Normalized provider slug.
	 */
	private function normalize_provider( $provider ) {
		$provider = strtolower( trim( $provider ) );

		// Group classes mapping (from V5 MerchantAttributeReplaceFactory::replace_template).
		$group_map = array(
			'google_shopping_action' => 'google',
			'google_local'           => 'google',
			'google_local_inventory' => 'google',
			// Perplexity Merchant Program = Google Shopping spec verbatim.
			'perplexity'             => 'google',
		);

		if ( isset( $group_map[ $provider ] ) ) {
			return $group_map[ $provider ];
		}

		return $provider;
	}
}
