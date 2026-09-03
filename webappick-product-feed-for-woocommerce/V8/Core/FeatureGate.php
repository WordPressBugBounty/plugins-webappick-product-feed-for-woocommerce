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
	 * AI: extension_ai (the AI assistant / MCP connect page).
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
		// Advanced-filters tab rule engines (Filter\CustomFilter /
		// Filter\ProductTypeFilter) — checked in Free, unlocked by Pro.
		'custom_filters',
		'product_type_filter',
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
		// Dashboard Pro surfaces (analytics range + Pro-only widgets) —
		// checked by the Dashboard endpoints, unlocked by Pro.
		'dashboard_analytics',
		'dashboard_pro_widgets',
		// AI assistant page (the MCP connect screen). The native AI module's
		// five flow gates (ai_store_analysis/auto_config/category_suggest/
		// content_optimize/routing) were removed with the module — AI ships
		// via MCP + Abilities (owner decision 2026-09-02).
		'extension_ai',
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
	 * Default is `false` for every feature — the ONLY way to enable one is
	 * the `ctxfeed_feature_{$feature}` filter (the Pro plugin hooks
	 * `__return_true` for its licensed feature set). There is deliberately
	 * no constant-based override: a wp-config constant would be a
	 * production unlock anyone could copy from a blog post. Development
	 * setups unlock the same way Pro does — via the filters (see
	 * 04-testing/bootstrap-wp.php).
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
		return (bool) apply_filters( "ctxfeed_feature_{$feature}", false );
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
