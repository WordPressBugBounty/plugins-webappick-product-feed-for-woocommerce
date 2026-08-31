<?php
/**
 * SeoCompatibilityProvider — SEO plugin integration (Yoast / Rank Math / AIOSEO).
 *
 * Holds the logic that previously lived hardcoded in V8 core
 * (`V8/Product/SEOResolver.php` + the SEO branches of
 * `V8/Product/AttributeRegistry.php`). Core now fires neutral hook seams and
 * this provider answers them, so no SEO-plugin-specific branch remains in core.
 *
 * Hooks answered (registered from Bootstrap::init()):
 *   • `ctxfeed_resolve_seo_attribute`          — resolve an SEO attribute value.
 *   • `ctxfeed_register_simple_seo_attributes` — the flat "SEO" attribute group.
 *   • `ctxfeed_register_seo_attributes`        — the dropdown SEO option group.
 *
 * V5 parity notes (carried over verbatim from the former core resolver):
 *   • Accepts BOTH V5 attribute names (`yoast_wpseo_title`,
 *     `yoast_wpseo_metadesc`) and V8 short names (`yoast_title`,
 *     `yoast_description`). V5 configs remain valid without migration.
 *   • For variations, meta is read from the PARENT product — SEO plugins store
 *     product-level meta only on the parent.
 *   • Yoast title/description support `%%variable%%` replacement via
 *     WPSEO_Replace_Vars when present.
 *   • Empty meta falls back to the product's own title / description so the
 *     feed always ships a value.
 *
 * @package CTXFeed\Compat\Providers
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Providers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO plugin compatibility provider.
 *
 * @since 8.0.0
 */
class SeoCompatibilityProvider {

	/**
	 * Detected SEO plugin identifier. Cached per instance.
	 *
	 * @since 8.0.0
	 * @var string|null
	 */
	private $detected_plugin = null;

	/**
	 * Meta key lookup: canonical attribute → SEO-plugin meta key. Both V5
	 * attribute names (`yoast_wpseo_title`) and V8 short names (`yoast_title`)
	 * map to the same underlying meta.
	 *
	 * @since 8.0.0
	 * @var array<string, array<string, string>>
	 */
	private static $meta_keys = array(
		'yoast'     => array(
			'yoast_title'          => '_yoast_wpseo_title',
			'yoast_description'    => '_yoast_wpseo_metadesc',
			// V5 attribute names.
			'yoast_wpseo_title'    => '_yoast_wpseo_title',
			'yoast_wpseo_metadesc' => '_yoast_wpseo_metadesc',
		),
		'rank_math' => array(
			'rank_math_title'       => 'rank_math_title',
			'rank_math_desc'        => 'rank_math_description',
			'rank_math_description' => 'rank_math_description',
		),
		'aioseo'    => array(
			'aioseo_title'       => '_aioseo_title',
			'aioseo_desc'        => '_aioseo_description',
			'aioseo_description' => '_aioseo_description',
		),
	);

	/**
	 * Attributes that fall back to the product's title on empty meta.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $title_attrs = array(
		'yoast_title',
		'yoast_wpseo_title',
		'rank_math_title',
		'aioseo_title',
	);

	/**
	 * Attributes that fall back to the product's description on empty meta.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $description_attrs = array(
		'yoast_description',
		'yoast_wpseo_metadesc',
		'rank_math_desc',
		'rank_math_description',
		'aioseo_desc',
		'aioseo_description',
	);

	/**
	 * Register the hook seams this provider answers.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ctxfeed_resolve_seo_attribute', array( $this, 'resolve_attribute' ), 10, 4 );
		add_filter( 'ctxfeed_register_simple_seo_attributes', array( $this, 'simple_attributes' ) );
		add_filter( 'ctxfeed_register_seo_attributes', array( $this, 'dropdown_attributes' ) );
	}

	/**
	 * Resolve an SEO attribute for a product.
	 *
	 * Answers `ctxfeed_resolve_seo_attribute`. The seam passes `null` as the
	 * default; a non-null value means an earlier provider already resolved it,
	 * so we defer.
	 *
	 * @since 8.0.0
	 *
	 * @param string|null $value   Current resolved value (null = unresolved).
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    SEO attribute name (V5 or V8 shape).
	 * @param mixed       $config  Feed configuration (unused; kept for signature parity).
	 * @return string
	 */
	public function resolve_attribute( $value, $product, $attr, $config = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( null !== $value ) {
			return $value;
		}

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$plugin = $this->detect_plugin();

		// Meta reads happen against the parent product for variations —
		// SEO plugins store product-level meta only on the parent.
		$meta_product_id = $product->is_type( 'variation' )
			? (int) $product->get_parent_id()
			: (int) $product->get_id();

		$resolved = '';
		if ( ! empty( $plugin ) && isset( self::$meta_keys[ $plugin ][ $attr ] ) ) {
			$meta_key = self::$meta_keys[ $plugin ][ $attr ];
			$resolved = (string) get_post_meta( $meta_product_id, $meta_key, true );

			// V5 parity: Yoast supports %%placeholder%% variables like
			// %%sitename%%, %%title%%, etc. Feeds should ship the replaced
			// string, not the raw template.
			if ( 'yoast' === $plugin && '' !== $resolved && class_exists( '\\WPSEO_Replace_Vars' ) ) {
				$post = get_post( $meta_product_id );
				if ( $post ) {
					$replacer = new \WPSEO_Replace_Vars();
					$resolved = (string) $replacer->replace( $resolved, $post );
				}
			}
		}

		// V5 fallback: when meta is empty (or no SEO plugin installed) fall
		// through to the product's own title / description so the feed still
		// ships a value.
		if ( '' === $resolved ) {
			if ( in_array( $attr, self::$title_attrs, true ) ) {
				$resolved = (string) $product->get_name();
			} elseif ( in_array( $attr, self::$description_attrs, true ) ) {
				$resolved = (string) $product->get_description();
			}
		}

		return $resolved;
	}

	/**
	 * Provide the flat "SEO" attribute group (V5 simple format).
	 *
	 * Answers `ctxfeed_register_simple_seo_attributes`. Returned unconditionally,
	 * matching the former `AttributeRegistry::get_simple_seo_attributes()`.
	 *
	 * @since 8.0.0
	 *
	 * @param array<string, string> $attributes Incoming attributes (default empty).
	 * @return array<string, string>
	 */
	public function simple_attributes( $attributes = array() ): array {
		return array_merge(
			(array) $attributes,
			array(
				'yoast_title'       => 'Yoast SEO Title',
				'yoast_description' => 'Yoast Meta Description',
				'rank_math_title'   => 'Rank Math Title',
				'rank_math_desc'    => 'Rank Math Description',
			)
		);
	}

	/**
	 * Provide the dropdown SEO option group for the active SEO plugin.
	 *
	 * Answers `ctxfeed_register_seo_attributes`. Detects Yoast → RankMath →
	 * AIOSEO (first match wins), mirroring the former
	 * `AttributeRegistry::get_seo_attributes()`.
	 *
	 * @since 8.0.0
	 *
	 * @param array $group Incoming group (default empty).
	 * @return array{optionGroup:string, options:array<string,string>}
	 */
	public function dropdown_attributes( $group = array() ): array {
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

		return is_array( $group ) && ! empty( $group ) ? $group : array(
			'optionGroup' => '',
			'options'     => array(),
		);
	}

	/**
	 * Detect the active SEO plugin. Result is cached per instance.
	 *
	 * @since 8.0.0
	 *
	 * @return string One of 'yoast', 'rank_math', 'aioseo', or '' when none.
	 */
	private function detect_plugin(): string {
		if ( null !== $this->detected_plugin ) {
			return $this->detected_plugin;
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' ) ) {
			$this->detected_plugin = 'yoast';
			return $this->detected_plugin;
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$this->detected_plugin = 'rank_math';
			return $this->detected_plugin;
		}

		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$this->detected_plugin = 'aioseo';
			return $this->detected_plugin;
		}

		$this->detected_plugin = '';
		return $this->detected_plugin;
	}
}
