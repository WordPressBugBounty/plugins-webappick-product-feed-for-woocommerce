<?php
/**
 * CommandTransform — pipeline stage executing the Config-tab command box
 * (feedrules `limit[i]`) via the shared CommandProcessor.
 *
 * ORDERING (deliberate, matches V5): V5's
 * `AttributeValueByType::process_output()` ran
 *
 *   str_replace rules → output_types → COMMANDS → prefix/suffix
 *
 * so this transform is registered in TransformServiceProvider directly
 * AFTER OutputTypeTransform and BEFORE PrefixSuffix — commands see the
 * output_type-formatted value and prefix/suffix wraps the command result,
 * exactly like V5.
 *
 * The parent trio (only_parent / parent / parent_if_empty) needs a live
 * WC_Product to re-resolve against the parent; the pipeline operates on
 * already-resolved data without product context, so those commands are
 * applied UPSTREAM in `ProductRepository::apply_parent_command()` (reusing
 * the same machinery as output_type codes 18/19/20) and no-op here. All
 * string commands run at this stage. XFRM-FRD-9.5.
 *
 * Storage format is V5-verbatim: `limit` is a parallel array zipped with
 * `mattributes` (same shape OutputTypeTransform consumes for
 * `output_type`). The stored strings are untouched — no key renames, no
 * format changes.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-9.1, XFRM-FRD-9.5
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config-tab output-command pipeline stage.
 *
 * @since 8.0.0
 */
class CommandTransform implements TransformInterface {

	/**
	 * Shared command executor.
	 *
	 * @since 8.0.0
	 * @var CommandProcessor
	 */
	private $processor;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param CommandProcessor|null $processor Command executor (no resolver —
	 *                                         the parent trio runs upstream in
	 *                                         ProductRepository).
	 */
	public function __construct( ?CommandProcessor $processor = null ) {
		$this->processor = $processor ? $processor : new CommandProcessor();
	}

	/**
	 * Run each attribute's configured command chain.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1, XFRM-FRD-9.4
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with commands applied per attribute.
	 */
	public function transform( array $product_data, Config $config ): array {
		// Pro gate — cheap early exit (CommandProcessor re-checks, but
		// skipping the lookup build avoids per-product work on Free).
		if ( ! FeatureGate::has( CommandProcessor::FEATURE ) ) {
			return $product_data;
		}

		$lookup = $this->build_lookup( $config );
		if ( empty( $lookup ) ) {
			return $product_data;
		}

		foreach ( $product_data as $attr => &$value ) {
			// Strip the ##N duplicate-position suffix (PROD-FRD-10.11) so
			// repeat merchant attributes share the base name's commands.
			$lookup_attr = ProductRepository::strip_dup_suffix( (string) $attr );
			if ( ! isset( $lookup[ $lookup_attr ] ) ) {
				continue;
			}

			$commands = $lookup[ $lookup_attr ];
			if ( ! is_string( $commands ) || '' === trim( $commands ) ) {
				continue;
			}

			$value = $this->processor->process( $value, $commands, null, $config, $lookup_attr );
		}

		return $product_data;
	}

	/**
	 * Build a `merchant_attribute => command string` lookup from feed config.
	 *
	 * Same dual-shape handling as OutputTypeTransform::build_lookup():
	 * V5 stores `limit` as a positional array zipped with `mattributes`;
	 * string-keyed fixtures are accepted as-is.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return array<string,string> Map of merchant attr → command string.
	 */
	private function build_lookup( Config $config ): array {
		$limits = $config->get_limit();
		if ( empty( $limits ) ) {
			return array();
		}

		// String-keyed (keyed-by-attribute fixture style) — return as-is.
		$keys            = array_keys( $limits );
		$is_zero_indexed = range( 0, count( $limits ) - 1 ) === $keys;
		if ( ! $is_zero_indexed ) {
			return $limits;
		}

		// Parallel-array shape — zip with mattributes.
		$mattributes = (array) $config->get_merchant_attributes();
		$lookup      = array();
		foreach ( $mattributes as $i => $attr ) {
			if ( ! isset( $limits[ $i ] ) || '' === $attr ) {
				continue;
			}
			$lookup[ (string) $attr ] = $limits[ $i ];
		}

		return $lookup;
	}
}
