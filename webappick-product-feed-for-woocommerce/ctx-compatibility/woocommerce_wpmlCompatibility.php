<?php
/**
 * WPML Multi currency.
 *
 * @package CTXFeed\V5\Price
 */

namespace CTXFeed\Compatibility;

use WCML\MultiCurrency\Settings;
use CTXFeed\V5\Utility\Cache;

/**
 * Class woocommerce_wpmlCompatibility
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Compatibility
 * @author     Ohidul Islam <wahid0003@gmail.com>
 * @link       https://webappick.com
 * @license    https://opensource.org/licenses/gpl-license.php GNU Public License
 * @category   MyCategory
 */
class woocommerce_wpmlCompatibility {

	/**
	 * WCMLCurrency constructor.
	 */
	private $sitepress_compatibility;
	public function __construct() {
		$this->sitepress_compatibility = new SitePressCompatibility();

		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'wcml_currency_convert' ), 99, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'wcml_currency_convert' ), 99, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'wcml_currency_convert' ), 99, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array(
			$this,
			'wcml_currency_convert'
		), 99, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'wcml_currency_convert' ), 99, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'wcml_currency_convert' ), 99, 5 );

		add_action( 'before_woo_feed_get_product_information', function ( $config ) {
			if ( ! class_exists( 'WCML\MultiCurrency\Settings' ) ) {
				include_once WP_CONTENT_DIR . '/plugins/woocommerce-multilingual/classes/Multicurrency/Settings.php';
			}
			$currency_mode = Settings::getMode();
			if ( 'by_language' != $currency_mode ) {
				Cache::set( 'wpml_currency_mode', $currency_mode );
				global $woocommerce_wpml;
				$woocommerce_wpml->update_setting( 'currency_mode', 'by_language' );
			}
		} );

		add_action( 'ctx_feed_after_save_feed_file', function () {
			if ( ! class_exists( 'WCML\MultiCurrency\Settings' ) ) {
				include_once WP_CONTENT_DIR . '/plugins/woocommerce-multilingual/classes/Multicurrency/Settings.php';
			}

			$currency_mode = Cache::get( 'wpml_currency_mode' );
			if ( $currency_mode ) {
				global $woocommerce_wpml;
				$woocommerce_wpml->update_setting( 'currency_mode', $currency_mode );
				Cache::delete( 'wpml_currency_mode' );
			}

		} );

	}

	/**
	 * Convert the price to the feed currency.
	 *
	 * @param float $price Price.
	 * @param object $product Product.
	 * @param \CTXFeed\V5\Utility\Config $config Config.
	 *
	 * @return float
	 */
	public function wcml_currency_convert( $price, $product, $config, $with_tax, $price_type ) {//phpcs:ignore

		$original_price = $price;
//		if ( class_exists( 'SitePress', false ) ) {
//			// WPML restore Language.
//			$request_uri = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' ;
//			if( $request_uri !== '' ){
//				$parsed_url = parse_url( $request_uri );
//				$query = $parsed_url['query'];
//				parse_str($query, $params);
//				$admin_lang = isset( $params['lang'] ) ? $params['lang'] : null;
//				global $sitepress;
//				$default_language = $sitepress->get_default_language();
//				if( $default_language !== $admin_lang ){
//					return $original_price;
//				}
//			}
//		}

		$currency       = $config->get_feed_currency(); //phpcs:ignore
		// Use WPML's function to convert the price
		$converted_price = apply_filters( 'wcml_raw_price_amount', $price, $currency );
		if ( empty( $converted_price ) ) {
			$converted_price = $original_price;
		}

		// If product has custom price, use that instead.

		$original_product_id = $this->sitepress_compatibility->original_post_id( $product->get_id() );
		if ( get_post_meta( $original_product_id, '_wcml_custom_prices_status', true ) ) {
			$custom_price = get_post_meta( $original_product_id, '_' . $price_type . '_' . $currency, true );
			if ( ! empty( $custom_price ) ) {
				$converted_price = $custom_price;
			}
		}

		return $converted_price;
	}

}
