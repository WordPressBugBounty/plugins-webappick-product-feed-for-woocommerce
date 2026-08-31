<?php
/**
 * Schedule Migrator — Converts V5 WP-Cron schedules to V8 Action Scheduler.
 * Re-registers feed generation schedules using Action Scheduler.
 *
 * @package CTXFeed\V8\Migration
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Feed\FeedScheduler;

/**
 * Moves per-feed V5 WP-Cron events (`woo_feed_update_single_feed`) over
 * to V8's Action Scheduler recurring actions.
 */
class ScheduleMigrator {

	/**
	 * Migrate all V5 cron schedules to Action Scheduler.
	 */
	public function migrateAll(): array {
		$schedules = $this->getV5Schedules();
		$results   = array();

		foreach ( $schedules as $feed_name => $schedule ) {
			$results[ $feed_name ] = $this->migrateSchedule( $feed_name, $schedule );
		}

		return $results;
	}

	/**
	 * Migrate one feed's V5 cron schedule to an Action Scheduler recurring action.
	 *
	 * @param string $feed_name Feed name.
	 * @param array  $schedule  V5 schedule data with `interval` and `next_run`.
	 * @return array Migration result with `status` and optional `reason`/`interval`.
	 */
	private function migrateSchedule( string $feed_name, array $schedule ): array {
		$interval = $schedule['interval'] ?? 0;

		if ( $interval <= 0 ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no interval',
			);
		}

		// Remove V5 WP-Cron event.
		wp_clear_scheduled_hook( 'woo_feed_update_single_feed', array( $feed_name ) );

		// Register with Action Scheduler.
		//
		// We delegate to FeedScheduler::schedule_recurring() rather than
		// calling as_schedule_recurring_action directly so the migration
		// uses the correct action name (RECURRING_ACTION → handle_recurring,
		// which gates on lock + already-running + config-exists), the
		// correct named-arg shape (`['feed_name' => $name]`), and the
		// staggered first-run window. The previous implementation enqueued
		// 'ctxfeed_generate_batch' with positional args `[$name, 0, 0, []]`,
		// which bypassed all guards and called handle_batch with batch_size=0
		// — silently emitting empty feeds on every interval.
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$scheduler = new FeedScheduler();
			$scheduler->schedule_recurring( $feed_name, (int) $interval );
		}

		Logger::debug( "Migrated schedule for feed: {$feed_name}, interval: {$interval}s" );

		return array(
			'status'   => 'migrated',
			'interval' => $interval,
		);
	}

	/**
	 * Read all V5 per-feed cron events from the WP-Cron array.
	 *
	 * @return array Map of feed name => array with `interval` and `next_run`.
	 */
	private function getV5Schedules(): array {
		$cron_array = _get_cron_array();
		$schedules  = array();

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( isset( $hooks['woo_feed_update_single_feed'] ) ) {
				foreach ( $hooks['woo_feed_update_single_feed'] as $event ) {
					$feed_name = $event['args'][0] ?? '';
					if ( $feed_name ) {
						$schedules[ $feed_name ] = array(
							'interval' => $event['schedule'] ?? 0,
							'next_run' => $timestamp,
						);
					}
				}
			}
		}

		return $schedules;
	}
}
