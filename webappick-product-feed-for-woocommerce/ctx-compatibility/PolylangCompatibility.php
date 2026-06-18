<?php
/**
 * Polylang Compatibility
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Compatibility
 * @category   MyCategory
 */

namespace CTXFeed\Compatibility;

/**
 * Class PolylangCompatibility
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Compatibility
 * @author     Ohidul Islam <wahid0003@gmail.com>
 * @link       https://webappick.com
 * @license    https://opensource.org/licenses/gpl-license.php GNU Public License
 * @category   MyCategory
 */
class PolylangCompatibility {

	/**
	 * PolylangCompatibility Constructor.
	 */
	public function __construct() {
		add_action( 'before_woo_feed_get_product_information', array( $this, 'switch_language' ), 10, 1 );
		add_action( 'after_woo_feed_get_product_information', array( $this, 'restore_language' ), 10, 1 );

		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'switch_language' ), 10, 1 );
		add_action( 'after_woo_feed_generate_batch_data', array( $this, 'restore_language' ), 10, 1 );

		// Add Polylang languages to dropdown options.
		add_filter( 'ctx_feed_active_languages', array( $this, 'get_active_languages' ), 10, 1 );

		// Add parent_lang output types for Polylang.
		add_filter( 'woo_feed_output_types', array( $this, 'add_parent_lang_output_types' ), 10, 1 );
	}

	/**
	 * Get active Polylang languages for dropdown.
	 *
	 * @param array $languages Existing languages array.
	 *
	 * @return array
	 */
	public function get_active_languages( $languages ) {
		if ( ! defined( 'POLYLANG_BASENAME' ) && ! function_exists( 'PLL' ) ) {
			return $languages;
		}

		// Polylang language names.
		$poly_languages_names = pll_languages_list( [ 'fields' => 'name' ] );

		// Polylang language locales.
		$poly_languages_slugs = pll_languages_list( [ 'fields' => 'slug' ] );

		// Polylang language lists.
		$get_languages = array_combine( $poly_languages_slugs, $poly_languages_names );

		if ( ! empty( $get_languages ) ) {
			foreach ( $get_languages as $key => $value ) {
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
	 * Switch language before feed generation
	 *
	 * @param \CTXFeed\V5\Utility\Config $config Feed config.
	 * @param bool                       $cookie_lang Switch cookie language.
	 */
	public function switch_language( $config, $cookie_lang = true ) {// phpcs:ignore
		$language_code = $config->get_feed_language();

		if ( !defined( 'POLYLANG_BASENAME' ) && !function_exists( 'PLL' ) ) {
            return;
        }

        if ( pll_current_language() === $language_code ) {
            return;
        }

        PLL()->curlang = PLL()->model->get_language( $language_code );
	}

	/**
	 * Restore language after feed generation
	 *
	 * @param \CTXFeed\V5\Utility\Config $config Feed config.
	 * @param bool                       $cookie_lang Restore cookie language.
	 */
	public function restore_language( $config, $cookie_lang = true ) {// phpcs:ignore
		$language_code = pll_default_language();

		if ( !defined( 'POLYLANG_BASENAME' ) && !function_exists( 'PLL' ) ) {
            return;
        }

        if ( pll_current_language() === $language_code ) {
            return;
        }

        PLL()->curlang = PLL()->model->get_language( $language_code );
	}

}
