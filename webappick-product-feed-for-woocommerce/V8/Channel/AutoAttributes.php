<?php
/**
 * AutoAttributes — channel attributes auto-added at generation time.
 *
 * V5 parity: GoogleStructure, FacebookStructure and BingStructure all
 * append `identifier_exists` to the feed mapping when the user has not
 * mapped it explicitly, so every Google/Facebook/Bing feed carries a
 * yes/no identifier signal without any user action. Google suppresses
 * the auto-add for the three sub-templates that share GoogleStructure
 * (see GoogleStructure::exclude_identifier_exists()):
 * google_shopping_action, google_local, google_local_inventory.
 *
 * The augmentation happens on the Config object right after it is
 * loaded for a generation run, so it flows through every downstream
 * consumer — XML rows, CSV/TSV/TXT headers and rows — from one place.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-added channel attribute rules.
 *
 * @since 8.0.0
 */
class AutoAttributes {

	/**
	 * Google sub-templates that must NOT receive the auto-add.
	 *
	 * Mirrors V5 GoogleStructure::exclude_identifier_exists().
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private const GOOGLE_EXCLUDED_TEMPLATES = array(
		'google_shopping_action',
		'google_local',
		'google_local_inventory',
	);

	/**
	 * Providers whose V5 structure classes auto-append identifier_exists.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private const IDENTIFIER_EXISTS_PROVIDERS = array(
		'google',
		'facebook',
		'bing',
		// V5 PinterestStructure appends it unconditionally too. NOT
		// pinterest_rss — that feed type routes through V5's
		// CustomStructure, which never auto-adds.
		'pinterest',
		// Perplexity reuses the Google Shopping spec — same treatment.
		'perplexity',
	);

	/**
	 * Return a Config augmented with the channel's auto-added attributes.
	 *
	 * Attributes already present in the user's mapping are never
	 * duplicated — an explicit mapping (including a static pattern)
	 * always wins. When nothing needs adding the original Config is
	 * returned untouched.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return Config Same instance, or a new augmented instance.
	 */
	public static function apply( Config $config ): Config {
		$provider = strtolower( $config->get_provider() );
		$auto     = self::for_provider( $provider );

		/**
		 * Filter the attributes auto-added to a channel's feed mapping.
		 *
		 * Return an empty array to disable the auto-add entirely, or
		 * append internal attribute keys to add more.
		 *
		 * @since 8.0.0
		 *
		 * @param string[] $auto     Internal attribute keys to auto-add.
		 * @param string   $provider Channel provider (lowercased).
		 * @param Config   $config   Feed configuration.
		 */
		$auto = (array) apply_filters( 'ctxfeed_channel_auto_attributes', $auto, $provider, $config );

		if ( empty( $auto ) ) {
			return $config;
		}

		$rules       = $config->to_array();
		$mattributes = isset( $rules['mattributes'] ) ? (array) $rules['mattributes'] : array();
		$changed     = false;

		foreach ( $auto as $attribute ) {
			$attribute = (string) $attribute;

			if ( '' === $attribute || in_array( $attribute, $mattributes, true ) ) {
				continue;
			}

			// V5 pushes onto mattributes/attributes/type only; the other
			// parallel arrays (prefix, suffix, output_type, limit, default)
			// stay short and every downstream consumer isset()-guards them.
			$rules['mattributes'][] = $attribute;
			$rules['attributes'][]  = $attribute;
			$rules['type'][]        = 'attribute';
			$changed                = true;
		}

		return $changed ? new Config( $rules ) : $config;
	}

	/**
	 * Attributes a provider auto-adds when they are not mapped.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider (lowercased).
	 * @return string[] Internal attribute keys.
	 */
	public static function for_provider( string $provider ): array {
		if ( in_array( $provider, self::GOOGLE_EXCLUDED_TEMPLATES, true ) ) {
			return array();
		}

		if ( in_array( $provider, self::IDENTIFIER_EXISTS_PROVIDERS, true ) ) {
			return array( 'identifier_exists' );
		}

		return array();
	}
}
