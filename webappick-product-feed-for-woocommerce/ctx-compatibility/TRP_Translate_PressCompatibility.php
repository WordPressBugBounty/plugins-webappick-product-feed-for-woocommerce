<?php
/**
 * Compatibility class for TranslatePress
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

use TRP_Translate_Press;

/**
 * Class TranslatePress
 *
 * @package CTXFeed\V5\Compatibility
 */
class TRP_Translate_PressCompatibility {

	/**
	 * @var \TRP_Translation_Render
	 */
	private $translatepress_renderer;

	/**
	 * @var \TRP_Url_Converter
	 */
	private $translatepress_url_converter;

	/**
	 * @var \TRP_Settings
	 */
	private $trp_settings;

	/**
	 * TranslatePress constructor.
	 */
	public function __construct() {
		/**
		 * TRP_Settings and TRP_Translation_Render must be instantiated here because.
		 * If both class instantiated at AttributeValueByType class then for every attribute will create
		 * a new instance of TRP_Translation_Render which is unnecessary and time/memory consuming.
		 */
		if (
			! class_exists( 'TRP_Settings' )
			|| ! class_exists( 'TRP_Translation_Render' )
			|| ! class_exists( 'TRP_Url_Converter' )
		) {
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/external-functions.php';
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/class-settings.php';
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/class-translation-render.php';
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/class-url-converter.php';
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/queries/class-query.php';
			include_once WP_PLUGIN_DIR . '/translatepress-multilingual/includes/class-upgrade.php';
		}

		$settings = ( new \TRP_Settings )->get_settings();

		$this->trp_settings = $settings;

		$this->translatepress_renderer      = new \TRP_Translation_Render( $settings );
		$this->translatepress_url_converter = new \TRP_Url_Converter( $settings );

		// Add TranslatePress languages to dropdown options.
		add_filter( 'ctx_feed_active_languages', array( $this, 'get_active_languages' ), 10, 1 );

		// Add parent_lang output types for TranslatePress.
		add_filter( 'woo_feed_output_types', array( $this, 'add_parent_lang_output_types' ), 10, 1 );

		// Handle variation title with attributes translation.
		add_filter( 'woo_feed_filter_variation_title_with_attributes', array( $this, 'translate_variation_title_with_attributes' ), 10, 6 );

		// Skip description cleaning for TranslatePress (needs raw content for translation).
		add_filter( 'woo_feed_skip_description_cleaning', array( $this, 'should_skip_description_cleaning' ), 10, 3 );

		// Translate attribute via ProductHelper::get_tp_translate.
		add_filter( 'woo_feed_filter_translatepress_attribute', array( $this, 'translate_attribute' ), 10, 4 );


		/**
		 * Apply the filter if any of the attribute is not translated by translatepress.
		 * Just add the attribute filter name in the array applied in the class ProductInfo.php
		 */
		$filters_with_param_3 = apply_filters(
			'woo_feed_translatepress_attributes_filters_list',
			array(
				'woo_feed_filter_product_title',
				'woo_feed_filter_product_parent_title',
				'woo_feed_filter_product_yoast_wpseo_title',
				'woo_feed_filter_product_rank_math_title',
				'woo_feed_filter_product_aioseop_title',

				'woo_feed_get_google_g:color_attribute',
				'woo_feed_get_google_color_attribute',
				'woo_feed_get_google_g:brand_attribute',
				'woo_feed_get_google_brand_attribute',
				'woo_feed_get_google_size_attribute',

			)
		);

		$category = apply_filters(
			'woo_feed_translatepress_attributes_filters_list',
			array(
				'woo_feed_filter_product_categories',
				'woo_feed_filter_product_primary_category' )
			);

		foreach ( $category as $category_filter ) {
			add_filter( $category_filter, array( $this, 'trp_category_translate_strings' ), 999, 3 );
		}

		$filters_description_with_param_3 = apply_filters(
			'woo_feed_translatepress_attributes_filters_list',
			array(
				'woo_feed_filter_product_description',
				'woo_feed_filter_product_description_with_html',
				'woo_feed_filter_product_short_description',
				'woo_feed_filter_product_yoast_wpseo_metadesc',
				'woo_feed_filter_product_rank_math_description',
				'woo_feed_filter_product_aioseop_description',
			)
		);

		foreach ( $filters_with_param_3 as $filter ) {
			add_filter( $filter, array( $this, 'trp_translate_strings' ), 999, 3 );
		}

		foreach ( $filters_description_with_param_3 as $filter ) {
			add_filter( $filter, array( $this, 'trp_translate_strings' ), 999, 3 );
		}

//		add_filter( 'woo_feed_filter_product_categories', array( $this, 'trp_category_translate_strings' ), 999, 3 );

		/**
		 * Apply the filter if any of the attribute is not translated by translatepress.
		 * Just add the attribute filter name in the array applied in the class ProductInfo.php
		 */
		$urls = apply_filters(
			'woo_feed_translatepress_attributes_url_list',
			array(
				'woo_feed_filter_product_yoast_canonical_url',
				'woo_feed_filter_product_rank_math_canonical_url',
				'woo_feed_filter_product_aioseop_canonical_url',
				'woo_feed_filter_product_link',
				'woo_feed_filter_product_parent_link',
				'woo_feed_filter_product_canonical_link',
				'woo_feed_filter_product_ex_link',
				'woo_feed_filter_product_add_to_cart_link',
			)
		);

		foreach ( $urls as $filter ) {
			add_filter( $filter, array( $this, 'get_tp_translate_url' ), 999, 3 );
		}
	}

	/**
	 *  Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \WC_Product $product The product object.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 *
	 * @return string
	 */
	public function get_tp_translate( $output, $product, $config ) { // phpcs:ignore
		return $this->translate_string( $output, $config, $product );
	}

	/**
	 *  Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \WC_Product $product The product object.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 *
	 * @return string
	 */
	public function get_tp_translate_for_description( $output, $product, $config ) { // phpcs:ignore
		return $this->translate_desc_string( $output, $config, $product );
	}

	/**
	 * Sanitize language code for safe use in table names.
	 *
	 * @param string $language The language code.
	 *
	 * @return string Sanitized language code.
	 */
	private function sanitize_language_code( $language ) {
		// Only allow alphanumeric characters, underscores, and hyphens
		return preg_replace( '/[^a-zA-Z0-9_-]/', '', $language );
	}

	/**
	 * Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 * @param \WC_Product $product The product object.
	 *
	 * @return string
	 */
	public function translate_string( $output, $config, $product ) { // phpcs:ignore
		global $wpdb;

		$feed_language     = $config->get_feed_language();
		$default_language  = $this->sanitize_language_code( $this->trp_settings['default-language'] );
		$safe_feed_language = $this->sanitize_language_code( $feed_language );
		$table_name        = $wpdb->prefix . 'trp_dictionary_' . strtolower( $default_language ) . '_' . strtolower( $safe_feed_language );

		/**
		 * If the feed language is same as the default language or the table does not exist then return the output.
		 * If the table does not exist then it means the language is not translated.
		 * If the feed language is same as the default language then it means the language is not translated.
		 */
		if (
			! $feed_language
			|| $feed_language === $this->trp_settings['default-language']
			|| ! $this->table_exists( $table_name )
		) {
			return $output;
		}

		if ( $this->translatepress_renderer ) {
			// Remove empty strings.
			$translatable_strings = self::get_translatable_strings( $output );

			$translatable_strings = array_values( $translatable_strings );
			$translated_strings   = array();

			foreach ( $translatable_strings as $string ) { // phpcs:ignore
				// Prepare the SQL query
				// phpcs:ignore
				$query = $wpdb->prepare( // phpcs:ignore
					"SELECT translated, MATCH (original) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
        FROM {$table_name}
        WHERE `original`= %s AND status != 0 LIMIT 1",
					$string,
					$string
				);
				// phpcs:ignore
				// Execute the query
				$result = $wpdb->get_results( $query ); // phpcs:ignore

				// Check if there is a result and if it has a translated string
				if ( ! count( $result ) || ! isset( $result[0]->translated ) || trim( $result[0]->translated ) === '' ) {
					continue;
				}

				$translated_strings[] = $result[0]->translated;
			}

			// If the translated strings array is not empty then implode the array with space.
			if ( count( $translated_strings ) ) {
				$output = implode( ' ', $translated_strings );
			}
		}

		return $output;
	}

	/**
	 * Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 * @param \WC_Product $product The product object.
	 *
	 * @return string
	 */
	public function translate_desc_string( $output, $config, $product ) { // phpcs:ignore
		global $wpdb;
		$feed_language      = $config->get_feed_language();
		$default_language   = $this->sanitize_language_code( $this->trp_settings['default-language'] );
		$safe_feed_language = $this->sanitize_language_code( $feed_language );
		$table_name         = $wpdb->prefix . 'trp_dictionary_' . strtolower( $default_language ) . '_' . strtolower( $safe_feed_language );

		/**
		 * If the feed language is same as the default language or the table does not exist then return the output.
		 * If the table does not exist then it means the language is not translated.
		 * If the feed language is same as the default language then it means the language is not translated.
		 */
		if (
			! $feed_language
			|| $feed_language === $this->trp_settings['default-language']
			|| ! $this->table_exists( $table_name )
			|| $output == ''
		) {
			return $output;
		}

		$translated_strings_new = [];
		if ( $this->translatepress_renderer ) {
			// Remove empty strings.
			$product_id                = $product->get_id();
			$trp_original_meta_ids_sql = $wpdb->prepare( // phpcs:ignore
				"SELECT `original_id` FROM `{$wpdb->prefix}trp_original_meta` WHERE `meta_key` = %s AND `meta_value` =  %d ORDER BY `meta_id` ASC",
				'post_parent_id',
				$product_id
			);
			$trp_original_meta_ids     = $wpdb->get_results( $trp_original_meta_ids_sql, ARRAY_A ); // phpcs:ignore

			if ( count( $trp_original_meta_ids ) ) {
				$ids = array();
				foreach ( $trp_original_meta_ids as $value ) {
					// Sanitize: ensure only integers are added to the array
					$id = absint( $value['original_id'] );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
				if ( count( $ids ) < 1 ) {
					return $output;
				}
				// Use placeholders for safe SQL query
				$placeholders           = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				$trp_dictionary_sql     = $wpdb->prepare(
					"SELECT `original`,`translated` FROM {$table_name} WHERE `original_id` IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				);
				$trp_dictionary_strings = $wpdb->get_results( $trp_dictionary_sql ); // phpcs:ignore

				$translated_strings_new = array();
				foreach ( $trp_dictionary_strings as $key => $string ) {
					if ( $string->translated === '' ) {
						$translated_strings_new[] = $string->original;
					} else {
						$translated_strings_new[] = $string->translated;
					}
				}
			} else {
				$translated_strings_new[] = $this->translate_string( $output, $config, $product );
			}

			// If the translated strings array is not empty then implode the array with space.
			if ( count( $translated_strings_new ) ) {
				$output = implode( ' ', $translated_strings_new );
			}
		}

		return $output;
	}

	/**
	 * Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \WC_Product $product The product object.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 *
	 * @return string
	 */
	public function trp_translate_strings( $output, $product, $config ) { // phpcs:ignore

		if($config->get_feed_language()!='en_US') {
			$output = str_replace(' - ', ' &#8211; ', $output);
			$output = str_replace("'", '&#8217;', $output);
			$output = preg_replace('/(\d)x(\d)/', '$1&#215;$2', $output);
		}

		$original_output        = $output;
		$strings                = self::get_translatable_strings( $output );
		$strings                = array_map( 'trp_full_trim', $strings );
		$translated_strings_new = $this->translatepress_renderer->process_strings( array_values( $strings ), $config->get_feed_language() );
		// If the translated strings array is not empty then implode the array with space.
		if ( count( $translated_strings_new ) ) {
			$output = implode( ' ', $translated_strings_new );
			$output = trim( $output );
		}


		if ( $output == '' ) {
			$output = $original_output;
		}

		return $output;

	}
	public function trp_category_translate_strings( $output, $product, $config ) { // phpcs:ignore
		$original_output        = $output;
		$genreArray = explode(">", $output);
		if( is_array( $genreArray ) &&  count( $genreArray )> 0 ){
			$translated = [];
			foreach ( $genreArray as $values ){
				$strings               = self::get_translatable_strings( $values );
				$strings                = array_map( 'trp_full_trim', $strings );
				$translated_strings_new = $this->translatepress_renderer->process_strings( array_values( $strings ), $config->get_feed_language() );
				// If the translated strings array is not empty then implode the array with space.
				$get_output = '';
				if ( is_array( $translated_strings_new ) && count( $translated_strings_new ) ) {
					$values = implode( ' ', $translated_strings_new );
					$values = trim( $values );
				}
				$translated[] = $values;
			}
			if( count( $translated )){
				$output = implode(" > ", $translated );
			}
		}
		if ( $output == '' ) {
			$output = $original_output;
		}

		return $output;

	}


	/**
	 * Get the translated string.
	 *
	 * @param string $output The output string.
	 * @param \WC_Product $product The product object.
	 * @param \CTXFeed\V5\Utility\Config $config The config object.
	 *
	 * @return string
	 */
	public function get_tp_translate_url( $output, $product, $config ) { // phpcs:ignore

		$link = $product->get_permalink();
		$feed_language = $config->get_feed_language();

		$settings = get_option( 'trp_settings', false );
		if( is_array( $settings ) && isset( $settings['url-slugs'][ $feed_language ] ) && is_array( $settings['url-slugs'] ) ){

			$url_slug = $settings['url-slugs'][ $feed_language ];

			$trp           = TRP_Translate_Press::get_trp_instance();
			$url_converter = $trp->get_component( 'url_converter' );
			$link          = $url_converter->get_url_for_language( $feed_language, $link );

//			if ( $settings['default-language'] != $feed_language ) {
//				$link = str_replace( home_url() . '/', home_url() . '/' . $url_slug . '/', $link );
//			}
		}

		$link = $this->add_utm_tracker( $link, $config );

		return $link;
	}

	/**
	 * Check if the url should be modified.
	 *
	 * @param string $feed_language The feed language.
	 *
	 * @return bool
	 */
	private function should_modify_url( $feed_language ) {
		global $TRP_LANGUAGE;// phpcs:ignore

		return $feed_language !== $TRP_LANGUAGE;// phpcs:ignore
	}

	/**
	 * Get the modified url with the slug if the url is not translated.
	 *
	 * @param string $output The output string.
	 * @param string $feed_language The feed language.
	 *
	 * @return string
	 */
	private function get_modified_url( $output, $feed_language ) {
		$exploded_output = explode( home_url(), $output );
		$slug            = $this->get_url_slug( $feed_language );

		// If the url is not translated then add the slug at the end of the url.
		if ( is_array( $exploded_output ) && count( $exploded_output ) > 1 && false === strpos( $exploded_output[1], $slug ) ) {
			$output = home_url() . $this->get_url_slug( $feed_language ) . $exploded_output[1];
		}

		return $output;
	}

	/**
	 * Get the url slug for the feed language.
	 *
	 * @param string $feed_language The feed language.
	 *
	 * @return string
	 */
	private function get_url_slug( $feed_language ) {
		$slug = $this->translatepress_url_converter->get_url_slug( $feed_language );

		if ( $slug ) {
			$slug = '/' . $slug;
		}else{
			$slug = '';
		}

		return $slug;
	}

	/**
	 * Check if the table exists.
	 *
	 * @param string $table_name The table name.
	 *
	 * @return bool
	 */
	private function table_exists( $table_name ) { // phpcs:ignore
		global $wpdb;

		return $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name; // phpcs:ignore
	}

	private static function get_translatable_strings( $output ) {

		// Split the input string into an array using new lines and HTML entities as separators
		$lines = preg_split( '/(<[^>]+>|\\n)/', $output, - 1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );

		// Filter out empty lines and trim each line
		$result = array_filter( array_map( 'trim', $lines ) );

		return $result;
	}

	public function add_utm_tracker( $url, $config ) {
		$utm = $config->campaign_parameters;
		if ( ! empty( $utm['utm_source'] ) && ! empty( $utm['utm_medium'] ) && ! empty( $utm['utm_campaign'] ) ) {
			$utm = [
				'utm_source'   => str_replace( ' ', '+', $utm['utm_source'] ),
				'utm_medium'   => str_replace( ' ', '+', $utm['utm_medium'] ),
				'utm_campaign' => str_replace( ' ', '+', $utm['utm_campaign'] ),
				'utm_term'     => str_replace( ' ', '+', $utm['utm_term'] ),
				'utm_content'  => str_replace( ' ', '+', $utm['utm_content'] ),
			];
			$url = add_query_arg( array_filter( $utm ), $url );
		}

		return $url;
	}

	/**
	 * Get active TranslatePress languages for dropdown.
	 *
	 * @param array $languages Existing languages array.
	 *
	 * @return array
	 */
	public function get_active_languages( $languages ) {
		if ( ! function_exists( 'trp_get_languages' ) ) {
			return $languages;
		}

		$tr_press_languages = trp_get_languages( 'default' );

		if ( ! empty( $tr_press_languages ) ) {
			foreach ( $tr_press_languages as $key => $value ) {
				$languages[ $key ] = $value;
			}
		}

		return $languages;
	}

	/**
	 * Add parent_lang output types for translation plugins.
	 *
	 * @param array $output_types Existing output types array.
	 *
	 * @return array
	 */
	public function add_parent_lang_output_types( $output_types ) {
		if ( ! in_array( 'parent_lang', $output_types, true ) ) {
			$output_types[] = 'parent_lang';
		}
		if ( ! in_array( 'parent_lang_if_empty', $output_types, true ) ) {
			$output_types[] = 'parent_lang_if_empty';
		}

		return $output_types;
	}

	/**
	 * Handle variation title with attributes translation.
	 *
	 * @param string|null $filtered_title The filtered title (null to use default behavior).
	 * @param string $title The base title.
	 * @param string $variation_attributes The variation attributes string.
	 * @param string $merger The merger string.
	 * @param \WC_Product $product The product object.
	 * @param mixed $config The feed config.
	 *
	 * @return string|null The translated title or null.
	 */
	public function translate_variation_title_with_attributes( $filtered_title, $title, $variation_attributes, $merger, $product, $config ) {
		// Translate the base title first.
		$title = $this->trp_translate_strings( $title, $product, $config );

		// Merge product title with translated variation attributes.
		if ( ! empty( $variation_attributes ) ) {
			$variation_attrs = explode( '-', $variation_attributes );
			if ( ! empty( $variation_attrs ) ) {
				foreach ( $variation_attrs as $value ) {
					$translated_attr = $this->trp_translate_strings( $value, $product, $config );
					$title .= $merger . $translated_attr;
				}
			}
		}

		return $title;
	}

	/**
	 * Determine if description cleaning should be skipped for TranslatePress.
	 *
	 * @param bool $skip_cleaning Whether to skip content cleaning.
	 * @param \WC_Product $product The product object.
	 * @param mixed $config The feed config.
	 *
	 * @return bool
	 */
	public function should_skip_description_cleaning( $skip_cleaning, $product, $config ) {
		// TranslatePress needs raw content for proper translation.
		return true;
	}

	/**
	 * Translate an attribute value using TranslatePress.
	 *
	 * @param mixed $attribute_value The attribute value to translate.
	 * @param string $attribute The attribute name.
	 * @param \WC_Product $product The product object.
	 * @param mixed $config The feed config.
	 *
	 * @return mixed Translated attribute value.
	 */
	public function translate_attribute( $attribute_value, $attribute, $product, $config ) {
		$target_language = $config->get_feed_language( $attribute );

		if ( empty( $target_language ) ) {
			return $attribute_value;
		}

		if ( $this->translatepress_renderer ) {
			global $TRP_LANGUAGE;
			$default_language = $TRP_LANGUAGE;
			$TRP_LANGUAGE     = $target_language;
			$attribute_value  = $this->translatepress_renderer->translate_page( $attribute_value );

			// Resetting the global language to default
			$TRP_LANGUAGE = $default_language;
		}

		return $attribute_value;
	}

}
