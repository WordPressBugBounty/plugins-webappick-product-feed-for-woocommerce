<?php
/**
 * Validator — Feed configuration and mapping validation.
 *
 * Validates feed configurations and attribute mappings before generation.
 * Returns structured error arrays for display in the admin UI.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-4.1, UTIL-FRD-4.2
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed validation service.
 *
 * @since 8.0.0
 */
class Validator {

	/**
	 * Validate a feed configuration array before generation.
	 *
	 * Checks that required fields (provider, feedType, filename) are present
	 * and non-empty. Each missing field produces a structured error entry.
	 * The errors array is filterable for custom validation rules.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-4.1
	 * @hook ctxfeed_validate_feed_config
	 *
	 * @param array $config Feed configuration array.
	 *
	 * @return array Array of error entries. Empty if valid. Each entry:
	 *               array( 'field' => string, 'message' => string ).
	 */
	public function validate_feed_config( array $config ): array {

		$errors = array();

		if ( empty( $config['provider'] ) ) {
			$errors[] = array(
				'field'   => 'provider',
				'message' => 'Channel/provider is required.',
			);
		}

		if ( empty( $config['feedType'] ) ) {
			$errors[] = array(
				'field'   => 'feedType',
				'message' => 'Feed format is required.',
			);
		}

		if ( empty( $config['filename'] ) ) {
			$errors[] = array(
				'field'   => 'filename',
				'message' => 'Feed filename is required.',
			);
		}

		/**
		 * Filters feed configuration validation errors.
		 *
		 * Allows Pro and Extension plugins to add custom validation rules.
		 *
		 * @since 8.0.0
		 *
		 * @param array $errors Validation errors so far.
		 * @param array $config The feed configuration being validated.
		 */
		return apply_filters( 'ctxfeed_validate_feed_config', $errors, $config );
	}

	/**
	 * Validate attribute mappings against channel requirements.
	 *
	 * Checks that all required channel attributes are mapped to a product
	 * attribute. Missing or empty mappings produce structured error entries.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-4.2
	 *
	 * @param array $mappings            Current attribute mappings. Keys are attribute names.
	 * @param array $required_attributes Required attributes. Format: array( 'key' => 'Label' ).
	 *
	 * @return array Array of error entries. Empty if all required mapped.
	 */
	public function validate_mappings( array $mappings, array $required_attributes ): array {

		$errors = array();

		foreach ( $required_attributes as $attr => $label ) {
			if ( empty( $mappings[ $attr ] ) ) {
				$errors[] = array(
					'field'   => $attr,
					'message' => sprintf( "Required attribute '%s' is not mapped.", $label ),
				);
			}
		}

		return $errors;
	}
}
