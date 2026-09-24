<?php
/**
 * PermanentFailure — marker for a batch error that no smaller batch can fix.
 *
 * The 8.0.7 batch-failure retry treats every catchable \Throwable as a
 * "too big" problem and re-runs the SAME offset at half the size, up to
 * MAX_BATCH_RETRIES times. That is right for memory / time-box failures
 * and wrong for configuration-class errors — a Custom Template 2 feed on a
 * site without Pro, an unsupported template format — which failed five
 * times over, at 200 → 100 → 54 → 27 products, logging six lines before the
 * run was marked failed (CBT-659). An exception implementing this interface
 * tells {@see FeedScheduler::handle_batch()} the error is deterministic for
 * the feed as configured: fail the run on the first attempt, one log line.
 *
 * Implement it on the exception class that fits the caller's existing
 * contract ({@see PermanentBatchFailure} for \RuntimeException,
 * {@see \CTXFeed\V8\Template\UnsupportedTemplateFormat} for
 * \InvalidArgumentException) so every existing catch site keeps working.
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
 * A batch failure that retrying at a smaller batch size cannot cure.
 *
 * @since 8.0.27
 */
interface PermanentFailure {
}
