<?php
/**
 * SchedulerHealth — is CTX Feed's background scheduler actually running?
 *
 * Single source of truth for "the Action Scheduler queue is stalled" — shared by
 * the all-pages notice (Status\NoticeProvider) and the System status page
 * (API\StatusEndpoint) so they never disagree. "Stalled" means at least one of
 * CTX Feed's own actions is PENDING and overdue by 5+ minutes: the definitive
 * signal that scheduled work isn't being drained (WP-Cron disabled with no real
 * cron, or a blocked loopback). It stays quiet on a healthy VPS that pairs
 * DISABLE_WP_CRON with a working system cron — there the queue drains and leaves
 * no overdue backlog — so a warning built on it never cries wolf.
 *
 * @package    CTXFeed
 * @subpackage V8/Status
 * @since      8.0.6
 */

namespace CTXFeed\V8\Status;

use CTXFeed\V8\Feed\FeedScheduler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background-scheduler health checks.
 *
 * @since 8.0.6
 */
class SchedulerHealth {

	/**
	 * Overdue threshold (seconds) before a pending action counts as stalled.
	 *
	 * @since 8.0.6
	 * @var int
	 */
	const OVERDUE_SECONDS = 300;

	/**
	 * Whether CTX Feed's Action Scheduler queue is genuinely stalled.
	 *
	 * True when at least one CTX Feed action is PENDING and overdue by
	 * OVERDUE_SECONDS — regardless of the cause (WP-Cron disabled with no system
	 * cron, or a blocked loopback). Cheap: an indexed existence check
	 * (per_page 1). Never fatals — any Action Scheduler / DB hiccup resolves to
	 * "not stalled".
	 *
	 * @since 8.0.6
	 *
	 * @return bool
	 */
	public static function is_stalled(): bool {
		if (
			! function_exists( 'as_get_scheduled_actions' )
			|| ! function_exists( 'as_get_datetime_object' )
			|| ! class_exists( '\ActionScheduler_Store' )
		) {
			return false;
		}

		try {
			$cutoff = as_get_datetime_object( gmdate( 'Y-m-d H:i:s', time() - self::OVERDUE_SECONDS ) );

			$overdue = as_get_scheduled_actions(
				array(
					'group'        => FeedScheduler::GROUP,
					'status'       => \ActionScheduler_Store::STATUS_PENDING,
					'date'         => $cutoff,
					'date_compare' => '<=',
					'per_page'     => 1,
				),
				'ids'
			);

			return ! empty( $overdue );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
