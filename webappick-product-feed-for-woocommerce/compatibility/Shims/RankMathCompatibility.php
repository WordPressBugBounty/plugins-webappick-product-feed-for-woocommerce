<?php
/**
 * Compatibility class for RankMath SEO plugin.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/seo-by-rank-math/
 */

namespace CTXFeed\Compat\Shims;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\Compat\Support\CommonHelper;
use CTXFeed\V8\Utility\Utf8;
use RankMath\Helper;

/**
 * Class RankMathCompatibility
 *
 * Handles RankMath SEO product attributes.
 *
 * @package CTXFeed\Compat\Shims
 */
class RankMathCompatibility {

	/**
	 * RankMathCompatibility Constructor.
	 */
	public function __construct() {
		// SEO plugin attributes for dropdown.
		add_filter( 'woo_feed_seo_plugin_attributes', array( $this, 'get_seo_attributes' ), 10, 1 );

		// RankMath active check.
		add_filter( 'woo_feed_is_rank_math_active', array( $this, 'is_rank_math_active' ), 10, 1 );

		// RankMath primary category filter.
		add_filter( 'woo_feed_filter_rank_math_primary_category', array( $this, 'get_primary_category_name' ), 10, 3 );

		// RankMath title hook.
		add_filter( 'woo_feed_filter_product_rank_math_title', array( $this, 'rank_math_title' ), 10, 3 );

		// RankMath description hook.
		add_filter( 'woo_feed_filter_product_rank_math_description', array( $this, 'rank_math_description' ), 10, 3 );

		// RankMath canonical URL hook.
		add_filter( 'woo_feed_filter_product_rank_math_canonical_url', array( $this, 'rank_math_canonical_url' ), 10, 3 );

		// RankMath GTIN hook.
		add_filter( 'rankmath_gtin_attribute_value', array( $this, 'rank_math_gtin' ), 10, 3 );

		// V8 resolves the "GTIN [RankMath Pro SEO]" row through the Legacy
		// Bridge filter, which nothing answered (CBT-760): route it to the
		// V5 hook above so the GTIN is read and V5 listeners still run.
		add_filter( 'woo_feed_filter_product_rank_math_gtin', array( $this, 'bridge_rank_math_gtin' ), 10, 3 );
	}

	/**
	 * Legacy Bridge answer for the `rank_math_gtin` attribute (CBT-760).
	 *
	 * Fires the V5 `rankmath_gtin_attribute_value` filter exactly as V5's
	 * ProductInfo::rank_math_gtin() did, so rank_math_gtin() below and any
	 * third-party listener resolve the value.
	 *
	 * @since 8.0.36
	 *
	 * @param mixed       $value   Value resolved so far.
	 * @param \WC_Product $product Product object.
	 * @param mixed       $config  Feed config.
	 *
	 * @return string
	 */
	public function bridge_rank_math_gtin( $value, $product, $config = null ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- V5 hook name preserved for third-party listeners.
		$gtin = apply_filters( 'rankmath_gtin_attribute_value', $value, $product, $config );

		return is_scalar( $gtin ) ? (string) $gtin : '';
	}

	/**
	 * Check if RankMath plugin is active.
	 *
	 * @param bool $is_active Current active status.
	 *
	 * @return bool
	 */
	public function is_rank_math_active( $is_active ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		return class_exists( 'RankMath' ) || class_exists( 'RankMathPro' );
	}

	/**
	 * Convert RankMath primary category term ID to term name.
	 *
	 * @param mixed       $value The term ID.
	 * @param \WC_Product $product The product object.
	 * @param mixed       $config The feed config.
	 *
	 * @return mixed The term name or original value.
	 */
	public function get_primary_category_name( $value, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$term = get_term( $value );

		return ( $term instanceof \WP_Term ) ? $term->name : $value;
	}

	/**
	 * Get RankMath SEO attributes for dropdown.
	 *
	 * @param array $attributes Current SEO attributes.
	 *
	 * @return array
	 */
	public function get_seo_attributes( $attributes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		$seoAttributes = array(
			'optionGroup' => esc_html__( 'RANK MATH SEO', 'woo-feed' ),
			'options'     => array(
				'rank_math_title'         => esc_html__( 'Title [RankMath SEO]', 'woo-feed' ),
				'rank_math_description'   => esc_html__( 'Description [RankMath SEO]', 'woo-feed' ),
				'rank_math_canonical_url' => esc_html__( 'Canonical URL [RankMath SEO]', 'woo-feed' ),
			),
		);

		// Add GTIN if RankMath Pro is available.
		if ( class_exists( 'RankMathPro' ) ) {
			$seoAttributes['options'] += array(
				'rank_math_gtin' => esc_html__( 'GTIN [RankMath Pro SEO]', 'woo-feed' ),
			);
		}

		return $seoAttributes;
	}

	/**
	 * Whether Rank Math's settings API (RankMath\Helper::get_settings) is
	 * loaded — never assume it from the RankMath class alone (CBT-699).
	 *
	 * @since 8.0.31
	 *
	 * @return bool
	 */
	private static function has_settings_api(): bool {
		return class_exists( Helper::class ) && method_exists( Helper::class, 'get_settings' );
	}

	/**
	 * Register Rank Math's replacement variables when it hasn't yet.
	 *
	 * Rank Math registers them on `admin_enqueue_scripts` / `wp`, which never
	 * fire during REST or cron feed generation, so its replacer knew no
	 * variables there. Its own setup() is idempotent (is_setup flag).
	 *
	 * @since 8.0.36
	 *
	 * @return void
	 */
	private static function setup_rank_math_variables(): void {
		if ( ! function_exists( 'rank_math' ) ) {
			return;
		}
		$rank_math = rank_math();
		if ( ! is_object( $rank_math ) ) {
			return;
		}
		// Rank Math's container is read through __get (no __isset), so
		// isset() would always say no. It is null when Rank Math skipped
		// booting its modules (e.g. registration not completed).
		$variables = $rank_math->variables;
		if ( is_object( $variables ) && method_exists( $variables, 'setup' ) ) {
			$variables->setup();
		}
	}

	/**
	 * Expand Rank Math `%variables%` in a title / description (CBT-759).
	 *
	 * %title%, %sep%, %page%, %sitename% and %excerpt% are filled here (the
	 * product's own title, so a variation keeps its name); anything left is
	 * passed to Rank Math's own replacer when its API is loaded. A missing
	 * or failing replacer leaves those variables as they are — never fatal.
	 *
	 * @since 8.0.36
	 *
	 * @param string      $text    Title or description, possibly with variables.
	 * @param \WC_Product $product Product the feed row is for.
	 * @return string
	 */
	private static function expand_vars( string $text, $product ): string {
		if ( false === strpos( $text, '%' ) || ! $product instanceof \WC_Product ) {
			return $text;
		}

		$sep = self::has_settings_api() ? (string) Helper::get_settings( 'titles.title_separator' ) : '';

		$replace = array(
			'%title%'    => $product->get_title(),
			'%sep%'      => '' !== $sep ? $sep : '-',
			'%page%'     => '',
			'%sitename%' => get_bloginfo( 'name' ),
		);
		if ( false !== strpos( $text, '%excerpt%' ) ) {
			$excerpt              = get_the_excerpt( CommonHelper::parent_product_id( $product ) );
			$replace['%excerpt%'] = is_string( $excerpt ) ? $excerpt : '';
		}
		$text = strtr( $text, $replace );

		if ( false !== strpos( $text, '%' ) && class_exists( Helper::class ) && method_exists( Helper::class, 'replace_vars' ) ) {
			$post = get_post( CommonHelper::parent_product_id( $product ) );
			if ( $post ) {
				try {
					self::setup_rank_math_variables();
					$replaced = Helper::replace_vars( $text, $post );
					if ( is_string( $replaced ) ) {
						$text = $replaced;
					}
				} catch ( \Throwable $e ) {
					// Keep the partly expanded text — the feed must not break.
					unset( $e );
				}
			}
		}

		// Invalid UTF-8 made the /u collapse return null and the (string)
		// cast shipped '' for the whole SEO text; a PCRE error now keeps the
		// text (CBT-817 D7).
		$text      = Utf8::scrub( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$collapsed = preg_replace( '/\s{2,}/u', ' ', $text );

		return trim( null === $collapsed ? $text : $collapsed );
	}

	/**
	 * Get RankMath SEO title.
	 *
	 * @param string      $title   Current title value.
	 * @param \WC_Product $product Product object.
	 * @param mixed       $config  Config object.
	 *
	 * @return string
	 */
	public function rank_math_title( $title, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! empty( $title ) ) {
			// V8 resolves the picker key to the raw `rank_math_title` meta
			// before this filter, so variables arrive here (CBT-759).
			return is_string( $title ) && class_exists( 'RankMath' ) ? self::expand_vars( $title, $product ) : $title;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $title;
		}

		$rank_title = get_post_meta( CommonHelper::parent_product_id( $product ), 'rank_math_title', true );

		if ( empty( $rank_title ) || ! is_string( $rank_title ) ) {
			$title_format = self::has_settings_api() ? Helper::get_settings( 'titles.pt_product_title' ) : '';
			$rank_title   = $title_format ? (string) $title_format : '%title%';
		}

		// Rank Math's snippet editor saves variables in the per-product
		// title too (CBT-759: they shipped raw, e.g. "%title% %sep% …").
		return self::expand_vars( $rank_title, $product );
	}

	/**
	 * Get RankMath SEO description.
	 *
	 * @param string      $description Current description value.
	 * @param \WC_Product $product     Product object.
	 * @param mixed       $config      Config object.
	 *
	 * @return string
	 */
	public function rank_math_description( $description, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! empty( $description ) ) {
			// Raw `rank_math_description` meta arrives here (CBT-759).
			return is_string( $description ) && class_exists( 'RankMath' ) ? self::expand_vars( $description, $product ) : $description;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $description;
		}

		$rank_description = get_post_meta( CommonHelper::parent_product_id( $product ), 'rank_math_description', true );
		// The PRODUCT description template (CBT-759: the Posts template
		// `pt_post_description` was read before).
		$desc_format = self::has_settings_api() ? Helper::get_settings( 'titles.pt_product_description' ) : '';

		if ( ! empty( $rank_description ) && is_string( $rank_description ) ) {
			$rank_description = self::expand_vars( $rank_description, $product );
		} else {
			$rank_description = '';
			if ( ! empty( $desc_format ) && strpos( (string) $desc_format, 'excerpt' ) !== false ) {
				$rank_description = self::expand_vars( (string) $desc_format, $product );
			}

			// Get Variation Description.
			if ( empty( $rank_description ) && $product->is_type( 'variation' ) ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				if ( $parent_product ) {
					$rank_description = $parent_product->get_description();
				}
			}
		}

		if ( is_array( $rank_description ) ) {
			$rank_description = reset( $rank_description );
		}

		$rank_description  = CommonHelper::remove_shortcodes( $rank_description );
		$strip_description = CommonHelper::strip_all_tags( wp_specialchars_decode( $rank_description ) );
		$rank_description  = ! empty( strlen( $strip_description ) ) && 0 < strlen( $strip_description ) ? $strip_description : $rank_description;

		return $rank_description;
	}

	/**
	 * Get RankMath canonical URL.
	 *
	 * @param string      $canonical_url Current canonical URL.
	 * @param \WC_Product $product       Product object.
	 * @param mixed       $config        Config object.
	 *
	 * @return string
	 */
	public function rank_math_canonical_url( $canonical_url, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! empty( $canonical_url ) ) {
			return $canonical_url;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return $canonical_url;
		}

		$post_canonical_url = get_post_meta( $product->get_id(), 'rank_math_canonical_url', true );

		if ( empty( $post_canonical_url ) ) {
			$post_canonical_url = get_the_permalink( $product->get_id() );
		}

		if ( is_array( $post_canonical_url ) ) {
			$post_canonical_url = reset( $post_canonical_url );
		}

		return $post_canonical_url;
	}

	/**
	 * Get RankMath GTIN.
	 *
	 * @param string      $gtin    Current GTIN value.
	 * @param \WC_Product $product Product object.
	 * @param mixed       $config  Config object.
	 *
	 * @return string
	 */
	public function rank_math_gtin( $gtin, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! empty( $gtin ) ) {
			return $gtin;
		}

		if ( ! $product instanceof \WC_Product ) {
			return $gtin;
		}

		// Rank Math Pro stores a GTIN per variation too; use the variation's
		// own code first, then the parent's (V5 read the parent only).
		$rankmath_gtin = '';
		if ( $product->is_type( 'variation' ) ) {
			$rankmath_gtin = get_post_meta( $product->get_id(), '_rank_math_gtin_code', true );
		}
		if ( empty( $rankmath_gtin ) ) {
			$rankmath_gtin = get_post_meta( CommonHelper::parent_product_id( $product ), '_rank_math_gtin_code', true );
		}

		return ! empty( $rankmath_gtin ) && is_scalar( $rankmath_gtin ) ? (string) $rankmath_gtin : $gtin;
	}
}
