<?php
/**
 * Memory — PHP memory limit / usage helpers.
 *
 * One place that answers "how much memory may this request use, and how
 * much has it used?", so the cache primer and the batch loop bound
 * themselves against the same numbers without depending on WordPress
 * helpers (they run inside pure unit tests too).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.26
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory limit helpers.
 *
 * @since 8.0.26
 */
class Memory {

	/**
	 * PHP's memory_limit in bytes.
	 *
	 * Unlimited (`-1`) and unreadable values return 0, which callers read as
	 * "no ceiling to stay inside" — never as "no memory available".
	 *
	 * @since 8.0.26
	 *
	 * @return float Bytes, or 0 when unlimited/unreadable.
	 */
	public static function limit_bytes(): float {
		$raw = ini_get( 'memory_limit' );

		if ( false === $raw ) {
			return 0.0;
		}

		$raw = trim( (string) $raw );

		if ( '' === $raw || '-1' === $raw ) {
			return 0.0;
		}

		$bytes = (float) $raw;

		switch ( strtolower( substr( $raw, -1 ) ) ) {
			case 'g':
				$bytes *= 1073741824; // 1024^3.
				break;
			case 'm':
				$bytes *= 1048576; // 1024^2.
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}

		return $bytes > 0 ? $bytes : 0.0;
	}

	/**
	 * Memory currently allocated to this request, as PHP counts it against
	 * the limit (real usage, including unused pool).
	 *
	 * @since 8.0.26
	 *
	 * @return float Bytes.
	 */
	public static function usage(): float {
		return (float) memory_get_usage( true );
	}
}
