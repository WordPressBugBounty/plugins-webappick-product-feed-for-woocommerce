<?php
/**
 * DropdownRegistry — Central registry for all UI dropdown/select data.
 *
 * Replaces V5's DropDownOptions class with a clean, JSON-only approach.
 * No HTML generation — V8 React admin consumes pure arrays via REST API.
 *
 * Static dropdowns return hardcoded arrays.
 * Dynamic dropdowns query WordPress/WooCommerce at runtime.
 *
 * @package    CTXFeed
 * @subpackage V8/Common
 * @since      8.0.0
 */

namespace CTXFeed\V8\Common;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dropdown data registry.
 *
 * @since 8.0.0
 */
class DropdownRegistry {

	/**
	 * Supported dropdown types and their method mappings.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private const TYPE_MAP = array(
		// Static dropdowns.
		'feed_country'              => 'get_countries',
		'channels'                  => 'get_channels',
		'file_types'                => 'get_file_types',
		'include_variation'         => 'get_variation_options',
		'variable_product_price'    => 'get_variable_price_options',
		'variable_product_quantity' => 'get_variable_quantity_options',
		'delimiters'                => 'get_delimiters',
		'enclosure'                 => 'get_enclosures',
		'conditions'                => 'get_conditions',
		'output_types'              => 'get_output_types',
		'post_statuses'             => 'get_post_statuses',
		'product_categories'        => 'get_product_categories',
		'variation_query_type'      => 'get_variation_query_types',
		'product_query_type'        => 'get_product_query_types',
		'store_country'             => 'get_store_country',
		'store_currency'            => 'get_store_currency',
		// Dynamic dropdowns.
		'languages'                 => 'get_active_languages',
		'currencies'                => 'get_active_currencies',
		'vendors'                   => 'get_vendors',
		'wp_options'                => 'get_wp_options',
	);

	/**
	 * Get dropdown data by type.
	 *
	 * @since 8.0.0
	 *
	 * @param string $type Dropdown type key.
	 * @return array|null Dropdown data or null if type not found.
	 */
	public static function get( string $type ): ?array {
		if ( ! isset( self::TYPE_MAP[ $type ] ) ) {
			return null;
		}

		$method = self::TYPE_MAP[ $type ];

		return self::$method();
	}

	/**
	 * Get all available dropdown type keys.
	 *
	 * @since 8.0.0
	 *
	 * @return array List of supported type keys.
	 */
	public static function get_types(): array {
		return array_keys( self::TYPE_MAP );
	}

	/**
	 * Get multiple dropdown types in one call.
	 *
	 * Useful for initial admin page load to batch-fetch all needed selects.
	 *
	 * @since 8.0.0
	 *
	 * @param array $types List of type keys to fetch.
	 * @return array Associative array of type => data.
	 */
	public static function get_multiple( array $types ): array {
		$result = array();

		foreach ( $types as $type ) {
			$data = self::get( $type );
			if ( null !== $data ) {
				$result[ $type ] = $data;
			}
		}

		return $result;
	}

	/**
	 * Get all dropdown data at once.
	 *
	 * @since 8.0.0
	 *
	 * @return array Associative array of all type => data.
	 */
	public static function get_all(): array {
		return self::get_multiple( self::get_types() );
	}

	// =================================================================
	// Static dropdown methods — pure data, no DB queries.
	// =================================================================

	/**
	 * Feed file type options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_file_types(): array {
		return apply_filters(
			'ctxfeed_dropdown_file_types',
			// XLS/XLSX are intentionally NOT offered: the V8 engine has no
			// spreadsheet writer, so selecting them threw "Unsupported template
			// format" and left a 0-byte file. TemplateEngine additionally maps
			// any legacy stored feedType=xls/xlsx to csv for upgrade safety.
			array(
				'xml'  => 'XML',
				'csv'  => 'CSV',
				'tsv'  => 'TSV',
				'txt'  => 'TXT',
				'json' => 'JSON',
			)
		);
	}

	/**
	 * Variation inclusion options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_variation_options(): array {
		return apply_filters(
			'ctxfeed_dropdown_variation_options',
			array(
				'y'         => __( 'All Variations', 'woo-feed' ),
				'n'         => __( 'Variable Products (Parent)', 'woo-feed' ),
				'default'   => __( 'Default Variation', 'woo-feed' ),
				'cheap'     => __( 'Cheapest Variation', 'woo-feed' ),
				'expensive' => __( 'Expensive Variation', 'woo-feed' ),
				'first'     => __( 'First Variation', 'woo-feed' ),
				'last'      => __( 'Last Variation', 'woo-feed' ),
				'both'      => __( 'Variable + Variations', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Variable product price strategy options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_variable_price_options(): array {
		return apply_filters(
			'ctxfeed_dropdown_variable_price',
			array(
				'first' => __( 'First Variation Price', 'woo-feed' ),
				'max'   => __( 'Max Variation Price', 'woo-feed' ),
				'min'   => __( 'Min Variation Price', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Variable product quantity strategy options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_variable_quantity_options(): array {
		return apply_filters(
			'ctxfeed_dropdown_variable_quantity',
			array(
				'first' => __( 'First Variation Quantity', 'woo-feed' ),
				'max'   => __( 'Max Variation Quantity', 'woo-feed' ),
				'min'   => __( 'Min Variation Quantity', 'woo-feed' ),
				'sum'   => __( 'Sum of Variation Quantity', 'woo-feed' ),
			) 
		);
	}

	/**
	 * CSV delimiter options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_delimiters(): array {
		return apply_filters(
			'ctxfeed_dropdown_delimiters',
			array(
				','  => 'Comma',
				':'  => 'Colon',
				' '  => 'Space',
				'|'  => 'Pipe',
				';'  => 'Semi Colon',
				"\t" => 'TAB',
			) 
		);
	}

	/**
	 * CSV enclosure options.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_enclosures(): array {
		return apply_filters(
			'ctxfeed_dropdown_enclosures',
			array(
				'double' => '"',
				'single' => "'",
				' '      => 'None',
			) 
		);
	}

	/**
	 * Filter/rule condition operators.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_conditions(): array {
		return apply_filters(
			'ctxfeed_dropdown_conditions',
			array(
				'=='        => __( 'is / equal', 'woo-feed' ),
				'!='        => __( 'is not / not equal', 'woo-feed' ),
				'>='        => __( 'equals or greater than', 'woo-feed' ),
				'>'         => __( 'greater than', 'woo-feed' ),
				'<='        => __( 'equals or less than', 'woo-feed' ),
				'<'         => __( 'less than', 'woo-feed' ),
				'contains'  => __( 'contains', 'woo-feed' ),
				'nContains' => __( 'does not contain', 'woo-feed' ),
				'between'   => __( 'between', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Variation query type options (settings).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_variation_query_types(): array {
		return apply_filters(
			'ctxfeed_dropdown_variation_query_type',
			array(
				'individual' => __( 'Individual', 'woo-feed' ),
				'variable'   => __( 'Variable Dependable', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Product query type options (settings).
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_product_query_types(): array {
		return apply_filters(
			'ctxfeed_dropdown_product_query_type',
			array(
				'wc'   => __( 'WC_Product_Query', 'woo-feed' ),
				'wp'   => __( 'WP_Query', 'woo-feed' ),
				'both' => __( 'Both', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Output type / transform options.
	 *
	 * Dynamically adds translation-related types when WPML/Polylang/TranslatePress active.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_output_types(): array {
		$types = array(
			'1'  => 'Default',
			'2'  => 'Strip Tags',
			'3'  => 'UTF-8 Encode',
			'4'  => 'htmlentities',
			'5'  => 'Integer',
			'6'  => 'Price',
			'7'  => 'Rounded Price',
			'8'  => 'Remove Space',
			'9'  => 'CDATA',
			'10' => 'Remove Special Character',
			'11' => 'Remove ShortCodes',
			'12' => 'ucwords',
			'13' => 'ucfirst',
			'14' => 'strtoupper',
			'15' => 'strtolower',
			'16' => 'urlToSecure',
			'17' => 'urlToUnsecure',
			'18' => 'only_parent',
			'19' => 'parent',
			'20' => 'parent_if_empty',
			'21' => 'htmlspecialchars',
			'22' => 'htmlspecialchars_decode',
		);

		// Add translation-related output types when multilingual plugin active.
		if (
			class_exists( 'SitePress', false )
			|| defined( 'POLYLANG_BASENAME' )
			|| function_exists( 'PLL' )
			|| ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'translatepress-multilingual/index.php' ) )
		) {
			$types['23'] = 'parent_lang';
			$types['24'] = 'parent_lang_if_empty';
		}

		/**
		 * Filter output types.
		 *
		 * V5 compat: also fires legacy filter name.
		 *
		 * @since 8.0.0
		 *
		 * @param array $types Output type options.
		 */
		return apply_filters( 'ctxfeed_dropdown_output_types', apply_filters( 'woo_feed_output_types', $types ) );
	}

	/**
	 * WordPress post statuses.
	 *
	 * @since 8.0.0
	 * @return array
	 */
	public static function get_post_statuses(): array {
		/** Legacy V5 filter kept for backward compat. */
		return (array) apply_filters(
			'ctxfeed_dropdown_post_statuses',
			apply_filters( 'woo_feed_product_statuses', get_post_statuses() )
		);
	}

	/**
	 * Product categories (product_cat taxonomy).
	 *
	 * Returns slug => name map (V5-compatible — V5 stores selected
	 * categories by slug in feedrules).
	 *
	 * @since 8.0.0
	 * @return array Slug => name.
	 */
	public static function get_product_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) 
		);

		$categories = array();

		if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[ $term->slug ] = $term->name;
			}
		}

		/** Legacy V5 filter kept for backward compat. */
		return (array) apply_filters(
			'ctxfeed_dropdown_product_categories',
			apply_filters( 'woo_feed_product_categories', $categories )
		);
	}

	// =================================================================
	// Placeholder methods — will be implemented in next batches.
	// =================================================================

	/**
	 * Country list for feed targeting.
	 *
	 * Returns ISO 3166-1 alpha-2 codes mapped to country names.
	 * Same 247 countries as V5 DropDownOptions::feed_country().
	 *
	 * @since 8.0.0
	 * @return array ISO code => country name.
	 */
	public static function get_countries(): array {
		$countries = array(
			'AF' => 'Afghanistan',
			'AX' => 'Aland Islands',
			'AL' => 'Albania',
			'DZ' => 'Algeria',
			'AS' => 'American Samoa',
			'AD' => 'Andorra',
			'AO' => 'Angola',
			'AI' => 'Anguilla',
			'AQ' => 'Antarctica',
			'AG' => 'Antigua and Barbuda',
			'AR' => 'Argentina',
			'AM' => 'Armenia',
			'AW' => 'Aruba',
			'AU' => 'Australia',
			'AT' => 'Austria',
			'AZ' => 'Azerbaijan',
			'BS' => 'Bahamas the',
			'BH' => 'Bahrain',
			'BD' => 'Bangladesh',
			'BB' => 'Barbados',
			'BY' => 'Belarus',
			'BE' => 'Belgium',
			'BZ' => 'Belize',
			'BJ' => 'Benin',
			'BM' => 'Bermuda',
			'BT' => 'Bhutan',
			'BO' => 'Bolivia',
			'BA' => 'Bosnia and Herzegovina',
			'BW' => 'Botswana',
			'BV' => 'Bouvet Island (Bouvetoya)',
			'BR' => 'Brazil',
			'IO' => 'British Indian Ocean Territory (Chagos Archipelago)',
			'VG' => 'British Virgin Islands',
			'BN' => 'Brunei Darussalam',
			'BG' => 'Bulgaria',
			'BF' => 'Burkina Faso',
			'BI' => 'Burundi',
			'KH' => 'Cambodia',
			'CM' => 'Cameroon',
			'CA' => 'Canada',
			'CV' => 'Cape Verde',
			'KY' => 'Cayman Islands',
			'CF' => 'Central African Republic',
			'TD' => 'Chad',
			'CL' => 'Chile',
			'CN' => 'China',
			'CX' => 'Christmas Island',
			'CC' => 'Cocos (Keeling) Islands',
			'CO' => 'Colombia',
			'KM' => 'Comoros the',
			'CD' => 'Congo',
			'CG' => 'Congo the',
			'CK' => 'Cook Islands',
			'CR' => 'Costa Rica',
			'CI' => 'Cote d\'Ivoire',
			'HR' => 'Croatia',
			'CU' => 'Cuba',
			'CY' => 'Cyprus',
			'CZ' => 'Czech Republic',
			'DK' => 'Denmark',
			'DJ' => 'Djibouti',
			'DM' => 'Dominica',
			'DO' => 'Dominican Republic',
			'EC' => 'Ecuador',
			'EG' => 'Egypt',
			'SV' => 'El Salvador',
			'GQ' => 'Equatorial Guinea',
			'ER' => 'Eritrea',
			'EE' => 'Estonia',
			'ET' => 'Ethiopia',
			'FO' => 'Faroe Islands',
			'FK' => 'Falkland Islands (Malvinas)',
			'FJ' => 'Fiji the Fiji Islands',
			'FI' => 'Finland',
			'FR' => 'France',
			'GF' => 'French Guiana',
			'PF' => 'French Polynesia',
			'TF' => 'French Southern Territories',
			'GA' => 'Gabon',
			'GM' => 'Gambia the',
			'GE' => 'Georgia',
			'DE' => 'Germany',
			'GH' => 'Ghana',
			'GI' => 'Gibraltar',
			'GR' => 'Greece',
			'GL' => 'Greenland',
			'GD' => 'Grenada',
			'GP' => 'Guadeloupe',
			'GU' => 'Guam',
			'GT' => 'Guatemala',
			'GG' => 'Guernsey',
			'GN' => 'Guinea',
			'GW' => 'Guinea-Bissau',
			'GY' => 'Guyana',
			'HT' => 'Haiti',
			'HM' => 'Heard Island and McDonald Islands',
			'VA' => 'Holy See (Vatican City State)',
			'HN' => 'Honduras',
			'HK' => 'Hong Kong',
			'HU' => 'Hungary',
			'IS' => 'Iceland',
			'IN' => 'India',
			'ID' => 'Indonesia',
			'IR' => 'Iran',
			'IQ' => 'Iraq',
			'IE' => 'Ireland',
			'IM' => 'Isle of Man',
			'IL' => 'Israel',
			'IT' => 'Italy',
			'JM' => 'Jamaica',
			'JP' => 'Japan',
			'JE' => 'Jersey',
			'JO' => 'Jordan',
			'KZ' => 'Kazakhstan',
			'KE' => 'Kenya',
			'KI' => 'Kiribati',
			'KP' => 'Korea',
			'KR' => 'Korea',
			'KW' => 'Kuwait',
			'KG' => 'Kyrgyz Republic',
			'LA' => 'Lao',
			'LV' => 'Latvia',
			'LB' => 'Lebanon',
			'LS' => 'Lesotho',
			'LR' => 'Liberia',
			'LY' => 'Libyan Arab Jamahiriya',
			'LI' => 'Liechtenstein',
			'LT' => 'Lithuania',
			'LU' => 'Luxembourg',
			'MO' => 'Macao',
			'MK' => 'Macedonia',
			'MG' => 'Madagascar',
			'MW' => 'Malawi',
			'MY' => 'Malaysia',
			'MV' => 'Maldives',
			'ML' => 'Mali',
			'MT' => 'Malta',
			'MH' => 'Marshall Islands',
			'MQ' => 'Martinique',
			'MR' => 'Mauritania',
			'MU' => 'Mauritius',
			'YT' => 'Mayotte',
			'MX' => 'Mexico',
			'FM' => 'Micronesia',
			'MD' => 'Moldova',
			'MC' => 'Monaco',
			'MN' => 'Mongolia',
			'ME' => 'Montenegro',
			'MS' => 'Montserrat',
			'MA' => 'Morocco',
			'MZ' => 'Mozambique',
			'MM' => 'Myanmar',
			'NA' => 'Namibia',
			'NR' => 'Nauru',
			'NP' => 'Nepal',
			'AN' => 'Netherlands Antilles',
			'NL' => 'Netherlands',
			'NC' => 'New Caledonia',
			'NZ' => 'New Zealand',
			'NI' => 'Nicaragua',
			'NE' => 'Niger',
			'NG' => 'Nigeria',
			'NU' => 'Niue',
			'NF' => 'Norfolk Island',
			'MP' => 'Northern Mariana Islands',
			'NO' => 'Norway',
			'OM' => 'Oman',
			'PK' => 'Pakistan',
			'PW' => 'Palau',
			'PS' => 'Palestinian Territory',
			'PA' => 'Panama',
			'PG' => 'Papua New Guinea',
			'PY' => 'Paraguay',
			'PE' => 'Peru',
			'PH' => 'Philippines',
			'PN' => 'Pitcairn Islands',
			'PL' => 'Poland',
			'PT' => 'Portugal, Portuguese Republic',
			'PR' => 'Puerto Rico',
			'QA' => 'Qatar',
			'RE' => 'Reunion',
			'RO' => 'Romania',
			'RU' => 'Russian Federation',
			'RW' => 'Rwanda',
			'BL' => 'Saint Barthelemy',
			'SH' => 'Saint Helena',
			'KN' => 'Saint Kitts and Nevis',
			'LC' => 'Saint Lucia',
			'MF' => 'Saint Martin',
			'PM' => 'Saint Pierre and Miquelon',
			'VC' => 'Saint Vincent and the Grenadines',
			'WS' => 'Samoa',
			'SM' => 'San Marino',
			'ST' => 'Sao Tome and Principe',
			'SA' => 'Saudi Arabia',
			'SN' => 'Senegal',
			'RS' => 'Serbia',
			'SC' => 'Seychelles',
			'SL' => 'Sierra Leone',
			'SG' => 'Singapore',
			'SK' => 'Slovakia (Slovak Republic)',
			'SI' => 'Slovenia',
			'SB' => 'Solomon Islands',
			'SO' => 'Somalia, Somali Republic',
			'ZA' => 'South Africa',
			'GS' => 'South Georgia and the South Sandwich Islands',
			'ES' => 'Spain',
			'LK' => 'Sri Lanka',
			'SD' => 'Sudan',
			'SR' => 'Suriname',
			'SJ' => 'Svalbard & Jan Mayen Islands',
			'SZ' => 'Swaziland',
			'SE' => 'Sweden',
			'CH' => 'Switzerland',
			'SY' => 'Syrian Arab Republic',
			'TW' => 'Taiwan',
			'TJ' => 'Tajikistan',
			'TZ' => 'Tanzania',
			'TH' => 'Thailand',
			'TL' => 'Timor-Leste',
			'TG' => 'Togo',
			'TK' => 'Tokelau',
			'TO' => 'Tonga',
			'TT' => 'Trinidad and Tobago',
			'TN' => 'Tunisia',
			'TR' => 'Turkey',
			'TM' => 'Turkmenistan',
			'TC' => 'Turks and Caicos Islands',
			'TV' => 'Tuvalu',
			'UG' => 'Uganda',
			'UA' => 'Ukraine',
			'AE' => 'United Arab Emirates',
			'GB' => 'United Kingdom',
			'US' => 'United States',
			'UM' => 'United States Minor Outlying Islands',
			'VI' => 'United States Virgin Islands',
			'UY' => 'Uruguay, Eastern Republic of',
			'UZ' => 'Uzbekistan',
			'VU' => 'Vanuatu',
			'VE' => 'Venezuela',
			'VN' => 'Vietnam',
			'WF' => 'Wallis and Futuna',
			'EH' => 'Western Sahara',
			'YE' => 'Yemen',
			'ZM' => 'Zambia',
			'ZW' => 'Zimbabwe',
		);

		/**
		 * Filter the feed country list.
		 *
		 * @since 8.0.0
		 *
		 * @param array $countries ISO code => country name.
		 */
		return apply_filters( 'ctxfeed_dropdown_countries', $countries );
	}

	/**
	 * Get the WooCommerce store's default country code.
	 *
	 * Reads the `woocommerce_default_country` option which stores
	 * the country (and optionally state) as "CC:STATE" format.
	 * Returns just the 2-letter ISO country code.
	 *
	 * @since 8.0.0
	 *
	 * @return array { country_code: string }
	 */
	public static function get_store_country(): array {
		$wc_country = get_option( 'woocommerce_default_country', 'US' );

		// WooCommerce stores country as "US:CA" (country:state).
		// Extract just the country code.
		$parts        = explode( ':', $wc_country );
		$country_code = ! empty( $parts[0] ) ? $parts[0] : 'US';

		return array(
			'country_code' => $country_code,
		);
	}

	/**
	 * Get the WooCommerce store default currency.
	 *
	 * @since 8.0.0
	 * @return array { currency_code: string }
	 */
	public static function get_store_currency(): array {
		$currency = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: 'USD';

		return array(
			'currency_code' => $currency,
		);
	}

	/**
	 * Feed channel/merchant template list.
	 *
	 * Returns grouped array matching V5 DropDownOptions::provider() structure.
	 * Three groups: Custom Templates, Popular Templates, Other Templates.
	 *
	 * @since 8.0.0
	 * @return array Grouped merchant templates with optionGroup and options keys.
	 */
	public static function get_channels(): array {
		// Custom Template 2 (XML) is a Pro feature — the entry only joins
		// the picker when the Pro plugin has unlocked its gate.
		$custom_templates = array(
			'custom' => esc_html__( 'Custom Template 1', 'woo-feed' ),
		);
		if ( \CTXFeed\V8\Core\FeatureGate::has( 'custom_template_2' ) ) {
			$custom_templates['custom2'] = esc_html__( 'Custom Template 2 (XML)', 'woo-feed' );
		}

		$channels = array(
			1 => array(
				'optionGroup' => 'Custom Templates',
				'options'     => $custom_templates,
			),
			2 => array(
				'optionGroup' => 'Popular Templates',
				'options'     => array(
					'chatgpt'                => esc_html__( 'ChatGPT (OpenAI)', 'woo-feed' ),
					'perplexity'             => esc_html__( 'Perplexity Shopping', 'woo-feed' ),
					'reddit'                 => esc_html__( 'Reddit Dynamic Product Ads', 'woo-feed' ),
					'x'                      => esc_html__( 'X (Twitter) Shopping', 'woo-feed' ),
					'google'                 => esc_html__( 'Google Shopping', 'woo-feed' ),
					'google_local'           => esc_html__( 'Google Local Inventory Ads', 'woo-feed' ),
					'google_local_inventory' => esc_html__( 'Google Local Product Inventory', 'woo-feed' ),
					'googlereview'           => esc_html__( 'Google Product Review', 'woo-feed' ),
					'google_shopping_action' => esc_html__( 'Google Shopping Action', 'woo-feed' ),
					'google_promotions'      => esc_html__( 'Google Promotions', 'woo-feed' ),
					'google_dynamic_ads'     => esc_html__( 'Google Dynamic Search Ads', 'woo-feed' ),
					'adwords'                => esc_html__( 'Google Ads', 'woo-feed' ),
					'adwords_local_product'  => esc_html__( 'Google Ads Local Product', 'woo-feed' ),
					'facebook'               => esc_html__( 'Facebook Catalog / Instagram', 'woo-feed' ),
					'pinterest'              => esc_html__( 'Pinterest Catalog', 'woo-feed' ),
					'pinterest_rss'          => esc_html__( 'Pinterest RSS Board', 'woo-feed' ),
					'bing'                   => esc_html__( 'Bing Shopping', 'woo-feed' ),
					'bing_local_inventory'   => esc_html__( 'Bing Local Inventory', 'woo-feed' ),
					'snapchat'               => esc_html__( 'Snapchat', 'woo-feed' ),
					'tiktok'                 => esc_html__( 'TikTok Ads Manager', 'woo-feed' ),
					'idealo'                 => esc_html__( 'Idealo', 'woo-feed' ),
					'pricespy'               => esc_html__( 'PriceSpy', 'woo-feed' ),
					'pricerunner'            => esc_html__( 'Price Runner', 'woo-feed' ),
					'yandex_csv'             => esc_html__( 'Yandex (CSV)', 'woo-feed' ),
					'yandex_xml'             => esc_html__( 'Yandex (XML)', 'woo-feed' ),
				),
			),
			3 => array(
				'optionGroup' => 'Other Templates',
				'options'     => array(
					'adform'               => esc_html__( 'AdForm', 'woo-feed' ),
					'adroll'               => esc_html__( 'AdRoll', 'woo-feed' ),
					'avantlink'            => esc_html__( 'Avantlink', 'woo-feed' ),
					'beslist.nl'           => esc_html__( 'Beslist.nl', 'woo-feed' ),
					'bestprice'            => esc_html__( 'Bestprice', 'woo-feed' ),
					'billiger.de'          => esc_html__( 'Billiger.de', 'woo-feed' ),
					'bol'                  => esc_html__( 'Bol.com', 'woo-feed' ),
					'bonanza'              => esc_html__( 'Bonanza', 'woo-feed' ),
					'catchdotcom'          => esc_html__( 'Catch.com.au', 'woo-feed' ),
					'cdiscount.fr'         => esc_html__( 'CDiscount.fr', 'woo-feed' ),
					'comparer.be'          => esc_html__( 'Comparer.be', 'woo-feed' ),
					'connexity'            => esc_html__( 'Connexity', 'woo-feed' ),
					'criteo'               => esc_html__( 'Criteo', 'woo-feed' ),
					'daisycon_cosmetics'   => esc_html__( 'Daisycon Advertiser (Cosmetics)', 'woo-feed' ),
					'daisycon_electronics' => esc_html__( 'Daisycon Advertiser (Electronics)', 'woo-feed' ),
					'daisycon_fashion'     => esc_html__( 'Daisycon Advertiser (Fashion)', 'woo-feed' ),
					'daisycon_food_drinks' => esc_html__( 'Daisycon Advertiser (Food & Drinks)', 'woo-feed' ),
					'ecommerceit'          => esc_html__( 'Ecommerce.it', 'woo-feed' ),
					'etsy'                 => esc_html__( 'Etsy', 'woo-feed' ),
					'fruugo'               => esc_html__( 'Fruugo', 'woo-feed' ),
					'fashionchick'         => esc_html__( 'Fashionchick.nl', 'woo-feed' ),
					'fruugo.au'            => esc_html__( 'Fruugoaustralia.com', 'woo-feed' ),
					'fyndiq.se'            => esc_html__( 'Fyndiq.se', 'woo-feed' ),
					'goedgeplaatst'        => esc_html__( 'GoedGeplaatst.nl', 'woo-feed' ),
					'heureka.sk'           => esc_html__( 'Heureka.sk', 'woo-feed' ),
					'hintaseuranta.fi'     => esc_html__( 'Hintaseuranta.fi', 'woo-feed' ),
					'incurvy'              => esc_html__( 'Incurvy', 'woo-feed' ),
					'kelkoo'               => esc_html__( 'Kelkoo', 'woo-feed' ),
					'kieskeurig.nl'        => esc_html__( 'Kieskeurig.nl', 'woo-feed' ),
					'kijiji.ca'            => esc_html__( 'Kijiji.ca', 'woo-feed' ),
					'leguide'              => esc_html__( 'LeGuide', 'woo-feed' ),
					'marktplaats.nl'       => esc_html__( 'Marktplaats.nl', 'woo-feed' ),
					'miinto.de'            => esc_html__( 'Miinto.de', 'woo-feed' ),
					'miinto.nl'            => esc_html__( 'Miinto.nl', 'woo-feed' ),
					'modalova'             => esc_html__( 'Modalova', 'woo-feed' ),
					'modina.de'            => esc_html__( 'Modina.de', 'woo-feed' ),
					'moebel.de'            => esc_html__( 'Moebel.de', 'woo-feed' ),
					'myshopping.com.au'    => esc_html__( 'Myshopping.com.au', 'woo-feed' ),
					'nextad'               => esc_html__( 'TheNextAd', 'woo-feed' ),
					'prisjakt'             => esc_html__( 'Prisjakt', 'woo-feed' ),
					'profit_share'         => esc_html__( 'Profit Share', 'woo-feed' ),
					'rakuten.de'           => esc_html__( 'Rakuten.de', 'woo-feed' ),
					'shareasale'           => esc_html__( 'ShareASale', 'woo-feed' ),
					'shopalike.fr'         => esc_html__( 'Shopalike.fr', 'woo-feed' ),
					'shopmania'            => esc_html__( 'Shopmania', 'woo-feed' ),
					'shopflix'             => esc_html__( 'Shopflix (WellComm)', 'woo-feed' ),
					'shopzilla'            => esc_html__( 'Shopzilla', 'woo-feed' ),
					'skinflint.co.uk'      => esc_html__( 'SkinFlint.co.uk', 'woo-feed' ),
					'skroutz'              => esc_html__( 'Skroutz.gr', 'woo-feed' ),
					'smartly.io'           => esc_html__( 'Smartly.io', 'woo-feed' ),
					'spartoo.fi'           => esc_html__( 'Spartoo.fi', 'woo-feed' ),
					'shopee'               => esc_html__( 'Shopee', 'woo-feed' ),
					'stylight.com'         => esc_html__( 'Stylight.com', 'woo-feed' ),
					'trovaprezzi'          => esc_html__( 'Trovaprezzi.it', 'woo-feed' ),
					'twenga'               => esc_html__( 'Twenga', 'woo-feed' ),
					'tweaker_xml'          => esc_html__( 'Tweakers (XML)', 'woo-feed' ),
					'tweaker_csv'          => esc_html__( 'Tweakers (CSV)', 'woo-feed' ),
					'vertaa.fi'            => esc_html__( 'Vertaa.fi', 'woo-feed' ),
					'walmart'              => esc_html__( 'Walmart', 'woo-feed' ),
					'webmarchand'          => esc_html__( 'Webmarchand', 'woo-feed' ),
					'wine_searcher'        => esc_html__( 'Wine Searcher', 'woo-feed' ),
					'wish'                 => esc_html__( 'Wish.com', 'woo-feed' ),
					'zap.co.il'            => esc_html__( 'Zap.co.il', 'woo-feed' ),
					'zbozi.cz'             => esc_html__( 'Zbozi.cz', 'woo-feed' ),
					'zalando'              => esc_html__( 'Zalando', 'woo-feed' ),
					'admarkt'              => esc_html__( 'Admarkt(marktplaats)', 'woo-feed' ),
					'glami'                => esc_html__( 'GLAMI', 'woo-feed' ),
				),
			),
		);

		/**
		 * Filter the channel/merchant template list.
		 *
		 * @since 8.0.0
		 *
		 * @param array $channels Grouped merchant templates.
		 */
		return apply_filters( 'ctxfeed_dropdown_channels', $channels );
	}

	// =================================================================
	// Dynamic dropdown methods — query WP/WC at runtime.
	// =================================================================

	/**
	 * Active languages from WPML/Polylang/TranslatePress.
	 *
	 * Detects which multilingual plugin is active and returns
	 * its configured languages. Same logic as V5 DropDownOptions::getActiveLanguages().
	 *
	 * @since 8.0.0
	 * @return array Language code => translated name.
	 */
	public static function get_active_languages(): array {
		$languages = array();

		// WPML.
		if ( class_exists( 'SitePress' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party read-only hook: `wpml_active_languages` is WPML's own public API for listing site languages. It is consumed here, not declared by CTX Feed, so it cannot carry our prefix.
			$wpml_langs = apply_filters( 'wpml_active_languages', null, 'orderby=id&order=desc' );
			if ( ! empty( $wpml_langs ) && is_array( $wpml_langs ) ) {
				foreach ( $wpml_langs as $code => $lang ) {
					$languages[ $code ] = $lang['translated_name'];
				}
			}
		}

		// Polylang.
		if ( empty( $languages ) && ( defined( 'POLYLANG_BASENAME' ) || function_exists( 'PLL' ) ) ) {
			if ( function_exists( 'pll_languages_list' ) ) {
				$names = pll_languages_list( array( 'fields' => 'name' ) );
				$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
				if ( ! empty( $slugs ) && ! empty( $names ) ) {
					$languages = array_combine( $slugs, $names );
				}
			}
		}

		// TranslatePress.
		if ( empty( $languages ) && function_exists( 'is_plugin_active' ) && is_plugin_active( 'translatepress-multilingual/index.php' ) ) {
			if ( function_exists( 'trp_get_languages' ) ) {
				$trp_langs = trp_get_languages( 'default' );
				if ( ! empty( $trp_langs ) && is_array( $trp_langs ) ) {
					$languages = $trp_langs;
				}
			}
		}

		return apply_filters( 'ctxfeed_dropdown_languages', $languages );
	}

	/**
	 * Active currencies from multi-currency plugins.
	 *
	 * Detects WCML, Aelia, WOOCS, ALG, WooCommerce MultiCurrency,
	 * and Woo Multi Currency. Same logic as V5 DropDownOptions::getActiveCurrencies().
	 *
	 * @since 8.0.0
	 * @return array Currency code => currency code.
	 */
	public static function get_active_currencies(): array {
		/**
		 * Active currencies from multi-currency plugins (code => code).
		 *
		 * Supplied by the compat layer (CurrencyCompatibilityProvider), which
		 * detects WCML, Aelia, WOOCS, ALG, WooCommerce MultiCurrency, and Woo
		 * Multi Currency. Returns an empty array when none is active. No
		 * currency-plugin-specific logic remains in core.
		 *
		 * @since 8.0.0
		 * @param array $currencies Currency code => currency code.
		 */
		return (array) apply_filters( 'ctxfeed_dropdown_currencies', array() );
	}

	/**
	 * Multi-vendor shop vendor list.
	 *
	 * Detects vendor role via Helper and returns vendor users.
	 * Same logic as V5 DropDownOptions::get_vendors().
	 *
	 * @since 8.0.0
	 * @return array Vendor ID => display name.
	 */
	public static function get_vendors(): array {
		/**
		 * Multi-vendor dropdown data (vendor ID => display name).
		 *
		 * Supplied by the compat layer (MultiVendorCompatibilityProvider),
		 * which detects the active vendor plugin (Dokan / WC Vendors / YITH /
		 * MVX / WCFM), queries the vendor users, and fires the legacy
		 * `woo_feed_get_vendors_args` + `woo_feed_product_vendors` filters.
		 * Returns an empty array when no vendor plugin is active. No
		 * vendor-plugin-specific logic (nor the former `V5\Common\Helper` call)
		 * remains in core.
		 *
		 * @since 8.0.0
		 * @param array $vendors Vendor ID => display name.
		 */
		return (array) apply_filters( 'ctxfeed_dropdown_vendors', array() );
	}

	/**
	 * All WordPress options (for advanced mapping).
	 *
	 * Same logic as V5 DropDownOptions::get_options().
	 *
	 * @since 8.0.0
	 * @return array Option name => option name.
	 */
	public static function get_wp_options(): array {
		$options     = array();
		$all_options = wp_load_alloptions();

		if ( ! empty( $all_options ) ) {
			foreach ( $all_options as $key => $value ) {
				$options[ $key ] = $key;
			}
		}

		return apply_filters( 'ctxfeed_dropdown_wp_options', $options );
	}
}
