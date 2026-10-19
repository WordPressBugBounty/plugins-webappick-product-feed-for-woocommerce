<?php
/**
 * OutputFormatter — Final formatting before writing to feed file.
 *
 * Handles encoding normalization (UTF-8 to target encoding) and
 * control character removal. Preserves newlines and tabs.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-8.1, XFRM-FRD-8.2, XFRM-FRD-8.3
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Utility\Utf8;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output formatter transform.
 *
 * @since 8.0.0
 */
class OutputFormatter implements TransformInterface {

	/**
	 * Apply output encoding normalization and control character removal.
	 *
	 * Drops invalid UTF-8 and control characters (C0 except tab, newline and
	 * carriage return; C1), then converts string values to the target
	 * encoding when not UTF-8.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-8.1, XFRM-FRD-8.2
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with encoding applied.
	 */
	public function transform( array $product_data, Config $config ): array {
		$encoding = $config->get( 'feed_encoding', 'UTF-8' );
		$controls = Utf8::CONTROL_PATTERN;

		foreach ( $product_data as &$value ) {
			// Skip non-string values. @implements XFRM-FRD-8.3.
			if ( ! is_string( $value ) ) {
				continue;
			}

			// Drop invalid UTF-8 byte sequences (CBT-694). A product field
			// imported in the wrong charset, or cut mid-character, otherwise
			// reaches the file as-is and libxml rejects the whole XML feed
			// ("Encoding error"). Then remove control characters except tab,
			// newline and carriage return (@implements XFRM-FRD-8.2) and the
			// C1 controls U+0080–U+009F (CBT-817 D10) — one pass, on UTF-8,
			// BEFORE any conversion: in the converted bytes the UTF-8 form
			// of a C1 control no longer exists. Everything after this
			// assumes UTF-8. Utf8::clean()'s two steps, inlined: this runs
			// for every value of every product.
			$value = Utf8::scrub( $value );
			$value = preg_replace( $controls, '', $value ) ?? $value;

			// Normalize encoding. @implements XFRM-FRD-8.1.
			if ( 'UTF-8' !== $encoding && function_exists( 'mb_convert_encoding' ) ) {
				$value = mb_convert_encoding( $value, $encoding, 'UTF-8' );
			}
		}

		return $product_data;
	}
}
