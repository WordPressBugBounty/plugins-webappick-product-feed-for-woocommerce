<?php
/**
 * PermanentBatchFailure — a \RuntimeException the batch retry must not halve.
 *
 * See {@see PermanentFailure} for the contract. Throw this for a
 * configuration-class error raised on the runtime-exception path (the
 * Custom-Template-2-needs-Pro guard, CBT-659). Extends \RuntimeException so
 * every existing `catch ( \RuntimeException )` / `\Throwable` site keeps
 * working unchanged.
 *
 * @package    CTXFeed\V8
 * @subpackage Feed
 * @since      8.0.27
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic batch failure (runtime-exception flavour).
 *
 * @since 8.0.27
 */
class PermanentBatchFailure extends \RuntimeException implements PermanentFailure {
}
