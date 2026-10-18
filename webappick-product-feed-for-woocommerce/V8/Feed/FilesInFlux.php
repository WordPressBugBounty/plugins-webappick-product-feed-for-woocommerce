<?php
/**
 * FilesInFlux — a product failed because a plugin's files are being
 * replaced right now (CBT-807).
 *
 * Thrown from FeedGenerator's per-product catch when the error is a class
 * or file of CTX Feed Pro (or CTX Feed) that went missing during an update.
 * Skipping the product would publish a feed without it; FeedScheduler holds
 * the batch at the same offset instead and re-runs it once the plugin has
 * loaded. Deliberately NOT a PermanentFailure.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.38
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A plugin's files are being replaced: the batch must wait, not skip.
 *
 * @since 8.0.38
 */
class FilesInFlux extends \RuntimeException {

	/**
	 * Which plugin is in flux: 'pro' or 'free'.
	 *
	 * @since 8.0.38
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @since 8.0.38
	 *
	 * @param string          $reason   'pro' (CTX Feed Pro) or 'free' (CTX Feed).
	 * @param \Throwable|null $previous The product's original error.
	 */
	public function __construct( string $reason, ?\Throwable $previous = null ) {
		$this->reason = 'free' === $reason ? 'free' : 'pro';
		parent::__construct(
			'free' === $this->reason
				? 'CTX Feed files are being replaced; the batch waits for the update to finish.'
				: 'CTX Feed Pro files are being replaced; the batch waits for the update to finish.',
			0,
			$previous
		);
	}

	/**
	 * Which plugin is in flux.
	 *
	 * @since 8.0.38
	 *
	 * @return string 'pro' or 'free'.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
