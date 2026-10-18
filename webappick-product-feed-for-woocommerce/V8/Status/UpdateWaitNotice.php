<?php
/**
 * UpdateWaitNotice — the CTX Feed screens' notice for feed runs that wait
 * for CTX Feed Pro (or CTX Feed's own compatibility layer) to load (CBT-807).
 *
 * Pro's own "paused" and boot-failure notices are hidden on CTX Feed
 * screens, so without this a run held for Pro — or stopped after the wait
 * cap — would leave Manage Feeds with nothing that explains it. Two states:
 * - while FeedScheduler::wait_reason() is not '': feeds are waiting;
 * - for 24 hours after a run (or a scheduled trigger) gave up waiting:
 *   which feeds were stopped and why. One entry per feed — the stop
 *   record — cleared when THAT feed completes a run. FeedScheduler also
 *   reads it to skip a feed's next occurrences at once while the same
 *   wait would only stop again.
 *
 * @package    CTXFeed
 * @subpackage V8/Status
 * @since      8.0.38
 */

namespace CTXFeed\V8\Status;

use CTXFeed\V8\Feed\FeedScheduler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notice source for held / stopped feed runs.
 *
 * @since 8.0.38
 */
final class UpdateWaitNotice {

	/**
	 * Option (not autoloaded) holding the stop records, one per feed:
	 * `{ <feed slug>: {time, reason} }`. Never a FeedScheduler wait-clock
	 * name (those start `ctxfeed_update_wait_clock_`).
	 *
	 * @since 8.0.38
	 * @var string
	 */
	const OPTION = 'ctxfeed_update_wait_notice';

	/**
	 * How long a stop stays on screen — and keeps the feed's next
	 * occurrences from waiting again (seconds).
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const SHOW_FOR = 86400;

	/**
	 * Most stop records kept (the newest win).
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const MAX_RECORDS = 50;

	/**
	 * Remember that a feed's run (or scheduled trigger) stopped waiting.
	 * Records older than SHOW_FOR are pruned on the way.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	public static function record( string $feed_name, string $reason ): void {
		if ( '' === $feed_name || ! function_exists( 'update_option' ) ) {
			return;
		}
		$records               = self::records();
		$records[ $feed_name ] = array(
			'time'   => time(),
			'reason' => 'free' === $reason ? 'free' : 'pro',
		);
		uasort(
			$records,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		update_option( self::OPTION, array_slice( $records, 0, self::MAX_RECORDS, true ), false );
	}

	/**
	 * Forget a feed's stop (it completed a run, or it was deleted). Other
	 * feeds' records stay. One read; a write only when the feed had one.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	public static function clear( string $feed_name ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$stored = get_option( self::OPTION, false );
		if ( ! is_array( $stored ) || ! array_key_exists( $feed_name, $stored ) ) {
			return;
		}
		unset( $stored[ $feed_name ] );
		if ( array() === $stored ) {
			if ( function_exists( 'delete_option' ) ) {
				delete_option( self::OPTION );
			}
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * A feed's stop record when it is under SHOW_FOR old, else null.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return array{time:int,reason:string}|null
	 */
	public static function stopped_for( string $feed_name ): ?array {
		$records = self::records();

		return $records[ $feed_name ] ?? null;
	}

	/**
	 * The notices to show now: zero or one, in NoticeProvider's shape.
	 * Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function notices(): array {
		try {
			$reason = class_exists( FeedScheduler::class ) ? FeedScheduler::wait_reason() : 'free';
			if ( '' !== $reason ) {
				return array( self::waiting_notice( $reason ) );
			}

			$stopped = array();
			foreach ( self::records() as $feed_name => $record ) {
				$auto = self::auto_update( (string) $feed_name );
				if ( null !== $auto ) { // A deleted feed is not reported.
					$stopped[] = $record + array(
						'feed' => (string) $feed_name,
						'auto' => $auto,
					);
				}
			}

			return array() === $stopped ? array() : array( self::stopped_notice( $stopped ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * The well-formed stop records under SHOW_FOR old, newest first.
	 *
	 * @since 8.0.38
	 *
	 * @return array<string, array{time:int,reason:string}>
	 */
	private static function records(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$stored = get_option( self::OPTION, false );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now     = time();
		$records = array();
		foreach ( $stored as $feed_name => $record ) {
			if ( ! is_string( $feed_name ) || '' === $feed_name || ! is_array( $record ) || empty( $record['time'] ) ) {
				continue;
			}
			$time = (int) $record['time'];
			if ( $now - $time > self::SHOW_FOR ) {
				continue;
			}
			$records[ $feed_name ] = array(
				'time'   => $time,
				'reason' => isset( $record['reason'] ) && 'free' === $record['reason'] ? 'free' : 'pro',
			);
		}
		uasort(
			$records,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return $records;
	}

	/**
	 * Whether the feed updates on a schedule (true), manually (false), or
	 * no longer exists (null).
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return bool|null
	 */
	private static function auto_update( string $feed_name ): ?bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}
		$feed = get_option( 'wf_feed_' . $feed_name, false );
		if ( false === $feed ) {
			return null;
		}
		if ( is_string( $feed ) && function_exists( 'maybe_unserialize' ) ) {
			$feed = maybe_unserialize( $feed );
		}

		return is_array( $feed ) && 1 === (int) ( $feed['status'] ?? 0 );
	}

	/**
	 * Feeds are waiting right now.
	 *
	 * @since 8.0.38
	 *
	 * @param string $reason 'pro' or 'free'.
	 * @return array<string, mixed>
	 */
	private static function waiting_notice( string $reason ): array {
		$minutes = self::minutes();

		if ( 'free' === $reason ) {
			$title   = __( 'Feed generation is waiting for CTX Feed', 'woo-feed' );
			$message = sprintf(
				/* translators: %d: minutes a feed run waits before it stops. */
				__( 'CTX Feed is being updated or part of it could not start. Feeds are not generated until it has loaded completely; waiting runs continue on their own. If this message stays for more than %d minutes, reinstall CTX Feed.', 'woo-feed' ),
				$minutes
			);
		} elseif ( ProState::recovery_mode() || ( method_exists( ProState::class, 'not_loaded' ) && ProState::not_loaded() ) ) {
			$title   = __( 'Feed generation is waiting for CTX Feed Pro', 'woo-feed' );
			$message = ProState::reason_message( 'pro' );
		} else {
			$title   = __( 'Feed generation is waiting for CTX Feed Pro', 'woo-feed' );
			$message = sprintf(
				/* translators: %d: minutes a feed run waits before it stops. */
				__( 'CTX Feed Pro is being updated or could not start. Feeds are not generated without it, so they never go out without your Pro settings; waiting runs continue on their own once it has loaded. If this message stays for more than %d minutes, update or reinstall CTX Feed Pro.', 'woo-feed' ),
				$minutes
			);
		}

		return array(
			'id'          => 'ctxfeed_update_wait',
			'severity'    => 'warning',
			'priority'    => 2,
			'title'       => $title,
			'message'     => $message,
			'dismissible' => false,
		);
	}

	/**
	 * Feeds whose run (or scheduled trigger) stopped waiting within the last
	 * 24 h. What happens next depends on each feed's auto-update: a
	 * scheduled feed tries again on its own, a manual one only when it is
	 * generated again.
	 *
	 * @since 8.0.38
	 *
	 * @param array<int, array{time:int,reason:string,feed:string,auto:bool}> $stopped Stop records, newest first.
	 * @return array<string, mixed>
	 */
	private static function stopped_notice( array $stopped ): array {
		$subject = 'free' === $stopped[0]['reason'] ? __( 'CTX Feed', 'woo-feed' ) : __( 'CTX Feed Pro', 'woo-feed' );
		$names   = array();
		$auto    = 0;
		foreach ( $stopped as $record ) {
			/* translators: %s: feed name. */
			$names[] = sprintf( __( '“%s”', 'woo-feed' ), $record['feed'] );
			$auto   += $record['auto'] ? 1 : 0;
		}
		$count = count( $stopped );

		if ( 1 === $count ) {
			$what = sprintf(
				/* translators: 1: feed name, 2: plugin name (CTX Feed Pro or CTX Feed), 3: minutes waited. */
				__( 'The update of feed %1$s was stopped because %2$s was not available for %3$d minutes. The previous feed file was kept.', 'woo-feed' ),
				$names[0],
				$subject,
				self::minutes()
			);
		} else {
			$what = sprintf(
				/* translators: 1: comma-separated feed names, 2: plugin name (CTX Feed Pro or CTX Feed), 3: minutes waited. */
				__( 'The updates of feeds %1$s were stopped because %2$s was not available for %3$d minutes. The previous feed files were kept.', 'woo-feed' ),
				implode( ', ', $names ),
				$subject,
				self::minutes()
			);
		}

		if ( $auto === $count ) {
			$next = 1 === $count
				? __( 'The next scheduled update tries again.', 'woo-feed' )
				: __( 'Each feed’s next scheduled update tries again.', 'woo-feed' );
		} elseif ( 0 === $auto ) {
			/* translators: %s: plugin name (CTX Feed Pro or CTX Feed). */
			$next = sprintf( 1 === $count ? __( 'Generate the feed again once %s is available.', 'woo-feed' ) : __( 'Generate the feeds again once %s is available.', 'woo-feed' ), $subject );
		} else {
			/* translators: %s: plugin name (CTX Feed Pro or CTX Feed). */
			$next = sprintf( __( 'Feeds with auto-update on try again at their next scheduled update; generate the others again once %s is available.', 'woo-feed' ), $subject );
		}

		return array(
			'id'          => 'ctxfeed_update_wait_stopped',
			'severity'    => 'error',
			'priority'    => 2,
			'title'       => 1 === $count ? __( 'A feed update was stopped', 'woo-feed' ) : __( 'Feed updates were stopped', 'woo-feed' ),
			'message'     => sprintf(
				/* translators: 1: what was stopped, 2: what happens next, 3: plugin name (CTX Feed Pro or CTX Feed). */
				__( '%1$s %2$s If it keeps happening, update or reinstall %3$s.', 'woo-feed' ),
				$what,
				$next,
				$subject
			),
			'dismissible' => false,
		);
	}

	/**
	 * The wait cap in whole minutes (at least 1).
	 *
	 * @since 8.0.38
	 *
	 * @return int
	 */
	private static function minutes(): int {
		$seconds = class_exists( FeedScheduler::class ) ? FeedScheduler::wait_max() : 600;

		return max( 1, (int) round( $seconds / 60 ) );
	}
}
