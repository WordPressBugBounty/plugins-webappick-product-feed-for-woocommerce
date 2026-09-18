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
	 * Yoast WooCommerce SEO identifier attributes → key inside Yoast's
	 * `wpseo_global_identifier_values` meta (CBT-614). V5 resolved these in
	 * `ProductInfos::yoast_gtin8()` … `yoast_mpn()` through the
	 * `woo_feed_get_yoast_identifiers_value()` helper; the V8 refactor
	 * re-wired title/description but left these six on a dead filter and
	 * a helper that no longer exists, so they exported empty since 8.0.0.
	 *
	 * @since 8.0.24
	 * @var array<string,string>
	 */
	private static $yoast_identifiers = array(
		'yoast_gtin8'  => 'gtin8',
		'yoast_gtin12' => 'gtin12',
		'yoast_gtin13' => 'gtin13',
		'yoast_gtin14' => 'gtin14',
		'yoast_isbn'   => 'isbn',
		'yoast_mpn'    => 'mpn',
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

		// Feed ROWS resolve a picker key as raw meta and are completed by the
		// Legacy Bridge `woo_feed_filter_product_{attr}` filter (see
		// AttributeResolver::SEO_ATTRIBUTES); the title-family keys are
		// answered there by WPSEO_FrontendCompatibility, but nothing ever
		// answered the six identifier keys (CBT-614). Hook them here — the
		// provider is registered unconditionally, the lookup itself gates
		// on Yoast WooCommerce SEO — so rows, Attribute Mapping and Dynamic
		// Attributes (resolve_seo_bridged) all reach the same lookup.
		foreach ( array_keys( self::$yoast_identifiers ) as $attr ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built as "woo_feed_filter_product_{$attr}", the registered woo_feed Legacy Bridge prefix; the sniff cannot resolve the variable.
			add_filter( 'woo_feed_filter_product_' . $attr, array( $this, 'bridge_yoast_identifier' ), 10, 2 );
		}
	}

	/**
	 * Legacy Bridge callback for the six Yoast identifier keys.
	 *
	 * Defers to a non-empty incoming value (a third-party filter at a
	 * lower priority, or a real `yoast_mpn` post meta) like every bridge
	 * getter; otherwise resolves the identifier. The attribute key is read
	 * from the filter name so one callback serves all six.
	 *
	 * @since 8.0.24
	 *
	 * @param mixed       $value   Incoming value.
	 * @param \WC_Product $product Product or variation.
	 * @return mixed
	 */
	public function bridge_yoast_identifier( $value, $product ) {
		if ( ( is_string( $value ) && '' !== trim( $value ) ) || ! $product instanceof \WC_Product ) {
			return $value;
		}
		$attr = str_replace( 'woo_feed_filter_product_', '', (string) current_filter() );
		if ( ! isset( self::$yoast_identifiers[ $attr ] ) ) {
			return $value;
		}

		return $this->resolve_yoast_identifier( $product, $attr );
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

		// Yoast WooCommerce SEO identifiers (V5 parity, CBT-614).
		if ( isset( self::$yoast_identifiers[ $attr ] ) ) {
			return $this->resolve_yoast_identifier( $product, $attr );
		}

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
	 * A Yoast WooCommerce SEO identifier (GTIN8/12/13/14, ISBN, MPN).
	 *
	 * Port of V5's `woo_feed_get_yoast_identifiers_value()` +
	 * `ProductInfos::yoast_*()`: Yoast stores the panel's identifiers as
	 * one array in `wpseo_global_identifier_values` on the product and
	 * `wpseo_variation_global_identifiers_values` on a variation; a
	 * variation with no value of its own inherits the parent's. Requires
	 * Yoast WooCommerce SEO (the class that owns that meta) like V5 did.
	 * The V5 per-identifier filter (`yoast_mpn_attribute_value`, two args)
	 * still fires so existing extensions keep working.
	 *
	 * @since 8.0.24
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $attr    Attribute key (e.g. `yoast_mpn`).
	 * @return string
	 */
	private function resolve_yoast_identifier( \WC_Product $product, string $attr ): string {
		$key   = self::$yoast_identifiers[ $attr ];
		$value = '';

		if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
			$value = self::yoast_identifier_meta( (int) $product->get_id(), $key, $product->is_type( 'variation' ) );
			if ( '' === $value && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
				$value = self::yoast_identifier_meta( (int) $product->get_parent_id(), $key, false );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- V5 hook names (yoast_gtin8_attribute_value … yoast_mpn_attribute_value) preserved for third-party listeners.
		return (string) apply_filters( $attr . '_attribute_value', $value, $product );
	}

	/**
	 * One identifier from Yoast's identifier meta on a post.
	 *
	 * @since 8.0.24
	 *
	 * @param int    $post_id      Product or variation id.
	 * @param string $key          gtin8 | gtin12 | gtin13 | gtin14 | isbn | mpn.
	 * @param bool   $is_variation Read the variation-shaped meta key.
	 * @return string
	 */
	private static function yoast_identifier_meta( int $post_id, string $key, bool $is_variation ): string {
		$meta = get_post_meta( $post_id, $is_variation ? 'wpseo_variation_global_identifiers_values' : 'wpseo_global_identifier_values', true );
		if ( ! is_array( $meta ) || ! isset( $meta[ $key ] ) || ! is_scalar( $meta[ $key ] ) ) {
			return '';
		}

		return trim( (string) $meta[ $key ] );
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
