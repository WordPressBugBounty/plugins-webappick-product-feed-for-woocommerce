<?php
/**
 * FeatureGate — Controls Free/Pro/Extension feature access via WordPress filters.
 *
 * All feature checks are boolean only (no numeric limits). The Free plugin
 * defines no filters (everything defaults to false). Pro and AI Extension
 * plugins hook `__return_true` to their respective feature filters.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-3.1, CORE-FRD-3.2, CORE-FRD-3.3, CORE-FRD-3.4
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static feature gating class.
 *
 * @since 8.0.0
 */
class FeatureGate {

	/**
	 * All known feature identifiers.
	 *
	 * Pro features (7): attribute_mapping, dynamic_attribute, product_filter,
	 * conditional_transform, custom_template_2, compat_adapters, ftp_export.
	 *
	 * AI Extension features (5): ai_store_analysis, ai_auto_config,
	 * ai_category_suggest, ai_content_optimize, ai_routing.
	 *
	 * Meta flags (2): pro, extension_ai.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	const KNOWN_FEATURES = array(
		'pro',
		'attribute_mapping',
		'dynamic_attribute',
		'product_filter',
		'conditional_transform',
		// Output-formatting command execution (Config-tab command box +
		// Custom Template 2 formatters) — Pro-only. XFRM-FRD-9.4.
		'output_commands',
		'custom_template_2',
		'compat_adapters',
		'ftp_export',
		// Pro-only translation gate. WPML support ships via the
		// ctx-compatibility submodule (free + Pro). Polylang's per-attribute
		// parent-language resolution (V5 output_type codes 23/24) is
		// Pro-only — same gating V5 applies via CompatibilityFactory.
		'polylang_translation',
		// ACF field enumeration into the product-attribute picker is Pro-only.
		// The `acf_fields_` prefix + value resolution stay in Free (existing
		// feeds keep resolving); only listing ACF fields to map is Pro. PROD-FRD-10.1.
		'acf_attributes',
		'extension_ai',
		'ai_store_analysis',
		'ai_auto_config',
		'ai_category_suggest',
		'ai_content_optimize',
		'ai_routing',
		// WordPress Abilities API / MCP exposure (Pro). `mcp_abilities` gates
		// the read abilities (unlocked for licensed Pro); `mcp_abilities_write`
		// gates writes and is a kill-switch left OFF even on Pro until the owner
		// opts in — so it is a KNOWN key here but Pro does not unlock it.
		'mcp_abilities',
		'mcp_abilities_write',
	);

	/**
	 * Check if a feature is enabled.
	 *
	 * Default is `false` for all features unless CTXFEED_DEV_MODE is defined
	 * and true, in which case all features default to `true` (useful during
	 * development without the Pro plugin). Pro plugin enables features by
	 * hooking `__return_true` to `ctxfeed_feature_{$feature}`.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-3.1
	 * @hook ctxfeed_feature_{$feature}
	 *
	 * @param string $feature Feature identifier.
	 *
	 * @return bool True if the feature is enabled, false otherwise.
	 */
	public static function has( string $feature ): bool {
		$default = defined( 'CTXFEED_DEV_MODE' ) && CTXFEED_DEV_MODE;

		return (bool) apply_filters( "ctxfeed_feature_{$feature}", $default );
	}

	/**
	 * Check if the Pro version is active.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-3.2
	 *
	 * @return bool True if Pro is active.
	 */
	public static function is_pro(): bool {

		return self::has( 'pro' );
	}

	/**
	 * Check if a named extension is active.
	 *
	 * AI Extension hooks: `add_filter( 'ctxfeed_feature_extension_ai', '__return_true' )`.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-3.3
	 *
	 * @param string $name Extension name (e.g., 'ai').
	 *
	 * @return bool True if the extension is active.
	 */
	public static function has_extension( string $name ): bool {

		return self::has( "extension_{$name}" );
	}

	/**
	 * Get all known features with their current boolean state.
	 *
	 * The list is filterable so extensions can register their own features.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-3.4
	 * @hook ctxfeed_known_features
	 *
	 * @return array Associative array of feature => bool.
	 */
	public static function get_all_features(): array {

		$features = array();

		foreach ( self::KNOWN_FEATURES as $feature ) {
			$features[ $feature ] = self::has( $feature );
		}

		/**
		 * Filter the known features list.
		 *
		 * Allows extensions to register their own features for admin display.
		 *
		 * @since 8.0.0
		 *
		 * @param array $features Associative array of feature => bool.
		 */
		return apply_filters( 'ctxfeed_known_features', $features );
	}
}
