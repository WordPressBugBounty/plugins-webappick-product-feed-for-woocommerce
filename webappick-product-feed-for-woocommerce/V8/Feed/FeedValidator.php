<?php
/**
 * FeedValidator — Pre-generation validation.
 *
 * Checks config completeness, channel requirements, and format validity
 * before generation is allowed to start. Extensible via filter hook.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-4.1, FEED-FRD-4.2
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed configuration validator.
 *
 * @since 8.0.0
 */
class FeedValidator {

	/**
	 * Valid feed formats.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $valid_formats = array( 'xml', 'csv', 'tsv', 'json', 'txt' );

	/**
	 * Custom Template 2 providers.
	 *
	 * These channels map through the free-form `feed_config_custom2` markup
	 * editor rather than the attribute table (the admin UI hides that table
	 * for them), so they legitimately carry zero mapped attributes. Check 3
	 * validates the markup for these instead of the attribute mapping.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $custom2_providers = array( 'custom2', 'admarkt', 'yandex_xml', 'glami' );

	/**
	 * Validate feed configuration before generation.
	 *
	 * Performs 4 checks per FEED-FRD-4.1:
	 * 1. Provider is set and not empty.
	 * 2. Feed format is valid (xml, csv, tsv, json, txt).
	 * 3. At least one attribute is mapped.
	 * 4. Required attributes for the selected channel are present.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-4.1, FEED-FRD-4.2
	 * @hook ctxfeed_feed_validation Filter to extend validation rules.
	 *
	 * @param Config $config Feed configuration object.
	 *
	 * @return array{valid: bool, errors: string[]} Validation result.
	 */
	public function validate( Config $config ): array {
		$errors = array();

		// Check 1: Provider is set. @implements FEED-FRD-4.1.
		$provider = $config->get_provider();
		if ( empty( $provider ) ) {
			$errors[] = 'No channel/provider selected.';
		}

		// Check 2: Format is valid. @implements FEED-FRD-4.1.
		$format = $config->get( 'feedType', '' );
		if ( empty( $format ) || ! in_array( strtolower( $format ), self::$valid_formats, true ) ) {
			$errors[] = sprintf(
				'Invalid feed format: "%s". Must be one of: %s.',
				$format,
				implode( ', ', self::$valid_formats )
			);
		}

		// Check 3: At least one attribute mapped — OR, for Custom Template 2
		// providers, a non-empty custom markup, since that family maps through
		// `feed_config_custom2` instead of the attribute table. @implements FEED-FRD-4.1.
		$attributes = $config->get_merchant_attributes();
		if ( in_array( $provider, self::$custom2_providers, true ) ) {
			if ( '' === trim( (string) $config->get( 'feed_config_custom2', '' ) ) ) {
				$errors[] = 'Custom Template 2 markup is empty.';
			}
		} elseif ( empty( $attributes ) ) {
			$errors[] = 'No attribute mappings configured.';
		}

		// Check 4: Required channel attributes present. @implements FEED-FRD-4.1.
		if ( ! empty( $provider ) && ! empty( $attributes ) ) {
			$required = $config->get( 'requiredAttributes', array() );
			if ( ! empty( $required ) ) {
				$mapped_keys = array_keys( $attributes );
				$missing     = array_diff( $required, $mapped_keys );
				if ( ! empty( $missing ) ) {
					$errors[] = sprintf(
						'Missing required attributes for %s: %s.',
						$provider,
						implode( ', ', $missing )
					);
				}
			}
		}

		$result = array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);

		/**
		 * Filter feed validation result.
		 *
		 * Allows third-party code to add custom validation rules.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $result Validation result with 'valid' and 'errors' keys.
		 * @param Config $config Feed configuration object.
		 */
		$result = apply_filters( 'ctxfeed_feed_validation', $result, $config );

		return $result;
	}
}
