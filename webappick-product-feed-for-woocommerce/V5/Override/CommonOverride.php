<?php
/**
 * Initiate Common Override for all merchants.
 *
 * CTX Feed old version compatibility. Previous version of CTX Feed use to save feed config in different way.
 * Previous version of CTX Feed use to save feed config without mandatory fields. Like : post_status, product_ids etc.
 *
 * This override will help to generate feed for old version of CTX Feed.
 *
 * Also, we can use this override to add any common filter for all merchants.
 *
 * @since 7.3..0
 *
 */

namespace CTXFeed\V5\Override;

use CTXFeed\V5\Helper\FeedHelper;

/**
 * Class CommonOverride
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Override
 */
class CommonOverride {
	/**
	 * The single instance of the class
	 *
	 * @var CommonOverride
	 *
	 */
	protected static $_instance = null;

	/**
	 * Main CommonOverride Instance.
	 *
	 * Ensures only one instance of CommonOverride is loaded or can be loaded.
	 *
	 * @return CommonOverride Main instance
	 */
	public function __construct() {
		add_filter( 'woo_feed_prepare_item_for_response', array(
			$this,
			'woo_feed_prepare_item_for_response_callback'
		), 10, 2 );

//		add_filter( 'woo_feed_insert_feed_data', array(
//			$this,
//			'woo_feed_insert_feed_data_callback'
//		), 10, 3 );

		add_filter( 'woo_feed_filter_product_description',
			array( $this, 'remove_enclosure_from_description' ),
			10, 4 );

		add_filter( 'woo_feed_filter_product_short_description',
			array( $this, 'remove_enclosure_from_description' ),
			10, 4 );

		add_filter( 'woo_feed_filter_product_title',
			array( $this, 'remove_enclosure_from_title' ),
			10, 3 );
		add_filter( 'woo_feed_filter_product_parent_title',
			array( $this, 'remove_enclosure_from_title' ),
			10, 3 );


	}

	/**
	 * Main RestController Instance.
	 *
	 * Ensures only one instance of RestController is loaded or can be loaded.
	 *
	 * @return CommonOverride Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Prepare feed item for response.
	 *
	 * @param $item array of feed item.
	 * @param $actual_value_from_db array of feed item.
	 *
	 * @return mixed
	 */
	public function woo_feed_prepare_item_for_response_callback( $item, $actual_value_from_db ) {
		$parsed_rules = [];
		if(isset($item['option_value'], $item['option_value']['feedrules'])) {
			$parsed_rules = FeedHelper::parse_feed_rules( $item['option_value']['feedrules'] );
		}else{
			$parsed_rules = FeedHelper::parse_feed_rules( [] );
			$item['option_value'] = [ 'feedrules' => []];
		}

		$item['option_value']['feedrules'] = $parsed_rules;

		return $item;
	}

	public function woo_feed_insert_feed_data_callback( $feed_rules, $old_feed, $feed_option_name ) {

		$parsed_rules = FeedHelper::parse_feed_rules( $feed_rules );

		return $parsed_rules;
	}

	/**
	 * Function to remove single and double quotes from product description if the feed type is CSV.
	 *
	 * @param string $description The original product description.
	 * @param object $product The product object (not used in the function).
	 * @param object $config The configuration object containing feed information.
	 * @param object $parent_product The parent product object (not used in the function).
	 * @return string The sanitized product description without single and double quotes.
	 */
	function remove_enclosure_from_description( $description, $product, $config, $parent_product ){
		if( $config->feed_info['option_value']['feedrules']['feedType'] === 'csv' ){

			if( $config->feed_info['option_value']['feedrules']['enclosure'] === 'single' ){
				$description = str_replace(["'"], '"', $description);
			}elseif ( $config->feed_info['option_value']['feedrules']['enclosure'] === 'double' ){
				$description = str_replace(['"'], "'", $description);
			}

		}

		return $description;
	}

	/**
	 * Function to remove single and double quotes from product title if the feed type is CSV.
	 *
	 * @param string $title The original product title.
	 * @param object $product The product object (not used in the function).
	 * @param object $config The configuration object containing feed information.
	 * @return string The sanitized product title without single and double quotes.
	 */
	function remove_enclosure_from_title( $title, $product, $config ){
		if( $config->feed_info['option_value']['feedrules']['feedType'] === 'csv' ){
			$title = str_replace(["'", '"'], '', $title);
		}

		return $title;
	}

}
