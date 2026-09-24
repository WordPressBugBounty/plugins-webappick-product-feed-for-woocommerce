<?php
/**
 * ProHandshake — free ↔ Pro engine compatibility (CBT-649).
 *
 * Since CBT-642 … CBT-648 the backend of every Pro feature lives in the Pro
 * plugin (`CTXFeed\Pro\Engine\*`) and plugs into this free engine through
 * hooks. A Pro build from before that migration (8.0.16 and older) still
 * flips the `ctxfeed_feature_*` gates but ships no engines, so the admin UI
 * would unlock features that nothing executes. This helper detects that
 * shape by CAPABILITY — an active V8 Pro without `Engine\EngineRegistry` —
 * not by version number, closes every gate, and NoticeProvider pins an
 * "update CTX Feed Pro" notice. Paired releases: free 8.0.27 ↔ Pro 8.0.17.
 *
 * @package    CTXFeed
 * @subpackage V8/Status
 * @since      8.0.27
 */

namespace CTXFeed\V8\Status;

use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a Pro plugin too old for this free engine and closes its gates.
 *
 * @since 8.0.27
 */
final class ProHandshake {

	/**
	 * First Pro release that ships the feature engines (paired with free 8.0.27).
	 *
	 * @since 8.0.27
	 * @var string
	 */
	const REQUIRED_PRO_VERSION = '8.0.17';

	/**
	 * Priority of the gate-closing filters — after Pro's own unlock (10).
	 *
	 * @since 8.0.27
	 * @var int
	 */
	const CLOSE_PRIORITY = 99;

	/**
	 * Whether an active V8 Pro plugin lacks the feature engines.
	 *
	 * Reads the constant Pro defines at include time and the engine registry
	 * class, so callers must run after all plugins are included
	 * (plugins_loaded and later).
	 *
	 * @since 8.0.27
	 *
	 * @return bool
	 */
	public static function pro_outdated(): bool {
		return self::is_outdated(
			defined( 'WOO_FEED_PRO_VERSION' ) ? (string) WOO_FEED_PRO_VERSION : null,
			class_exists( '\\CTXFeed\\Pro\\Engine\\EngineRegistry' )
		);
	}

	/**
	 * Pure decision: an active V8-era Pro (>= 8.0.0) without the engines.
	 *
	 * A pre-V8 Pro (< 8.0.0) is LegacyPro's case, not this one.
	 *
	 * @since 8.0.27
	 *
	 * @param string|null $pro_version     WOO_FEED_PRO_VERSION, or null when Pro is not loaded.
	 * @param bool        $engines_present Whether CTXFeed\Pro\Engine\EngineRegistry exists.
	 *
	 * @return bool
	 */
	public static function is_outdated( ?string $pro_version, bool $engines_present ): bool {
		if ( null === $pro_version || '' === $pro_version ) {
			return false;
		}
		if ( version_compare( $pro_version, '8.0.0', '<' ) ) {
			return false;
		}
		return ! $engines_present;
	}

	/**
	 * Close every known feature gate while Pro is outdated.
	 *
	 * Runs at free boot; the `__return_false` callbacks sit at priority 99
	 * so they win over Pro's unlock at 10. Without engines an open gate only
	 * exposes settings that nothing executes.
	 *
	 * @since 8.0.27
	 *
	 * @return void
	 */
	public static function close_gates(): void {
		if ( ! self::pro_outdated() ) {
			return;
		}
		foreach ( FeatureGate::KNOWN_FEATURES as $feature ) {
			add_filter( "ctxfeed_feature_{$feature}", '__return_false', self::CLOSE_PRIORITY );
		}
	}

	/**
	 * Loaded Pro version, '' when none.
	 *
	 * @since 8.0.27
	 *
	 * @return string
	 */
	public static function pro_version(): string {
		return defined( 'WOO_FEED_PRO_VERSION' ) ? (string) WOO_FEED_PRO_VERSION : '';
	}
}
