<?php
/**
 * UnsupportedTemplateFormat — a template format nobody registered.
 *
 * Thrown by {@see TemplateEngine::get_template()} when the feed asks for a
 * format with no renderer. Extends \InvalidArgumentException (the exception
 * the engine always threw here) so existing catch sites are untouched, and
 * implements {@see \CTXFeed\V8\Feed\PermanentFailure} so the batch retry
 * fails the run on the first attempt instead of halving the batch five
 * times (CBT-659) — a missing renderer does not depend on batch size.
 *
 * @package    CTXFeed\V8
 * @subpackage Template
 * @since      8.0.27
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Feed\PermanentFailure;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic template-format failure (invalid-argument flavour).
 *
 * @since 8.0.27
 */
class UnsupportedTemplateFormat extends \InvalidArgumentException implements PermanentFailure {
}
