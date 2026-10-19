<?php
/**
 * Regex — PCRE calls whose failure never wipes a feed value (CBT-822).
 *
 * The preg_replace() call returns null when PCRE gives up at run time:
 * `pcre.backtrack_limit` or `pcre.recursion_limit` exhausted by a lazy or
 * overlapping quantifier on a long value, the JIT stack exhausted by a
 * repeated group, or malformed UTF-8 under /u. A `(string)` cast, a
 * strip_tags( null ) or a `?? ''` then turned the value into '' — the
 * product left the feed with an empty description, title or command
 * result, and nothing was logged. Hosts lower those limits; long
 * page-builder descriptions raise the step count.
 *
 * replace() returns the subject unchanged when PCRE fails (after one retry
 * without JIT for a JIT stack error) and logs the pattern once per request
 * to ctxfeed-system.log (info — never the WooCommerce `ctxfeed` log). While
 * a feed batch runs (begin_batch_tally() / end_batch_tally(), called by
 * FeedGenerator) every failure is also counted, and the batch writes one
 * line per site + pattern to the feed's own log (CBT-809 amendment 40). It
 * never touches the pcre.* ini values. without_jit(), is_limit_error() and
 * log_failure() also serve the merchant-regex sites in Pro
 * (pattern-replace, string-replace rules, extract) through
 * CTXFeed\Pro\Support\Text.
 *
 * Implements CBT-809 plan §1.2 (Regex row), §2 E4–E6.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.39
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Null-safe PCRE helpers.
 *
 * @since 8.0.39
 */
final class Regex {

	/**
	 * The start-of-pattern option that turns JIT off for one pattern.
	 */
	const NO_JIT = '(*NO_JIT)';

	/**
	 * V5's catch-all for leftover `[shortcode]` tokens
	 * (V5/Helper/CommonHelper.php:99, `/\[\/*[…]*\/*\]/m`) with possessive
	 * quantifiers (CBT-809 §2 E4). The class already contains "/" (the range
	 * " -=") and never "]", so a match is "[" + the longest run of class
	 * bytes + "]" either way — the same matches, byte for byte. The greedy
	 * original backtracks through three overlapping runs: "[" + 3000
	 * slashes took a second and returned null at the default
	 * pcre.backtrack_limit, and "[" + 500 slashes failed at a limit of
	 * 1000.
	 */
	const SHORTCODE_CATCH_ALL = '/\[\/*+[č?a-zA-Z1-90_| -=\'"\{\}]*+\/*\]/m';

	/**
	 * Bytes of a pattern quoted in a log line.
	 */
	const LOG_PATTERN_BYTES = 120;

	/**
	 * Distinct failures logged per request (the memo never grows past it).
	 */
	const LOG_MEMO_MAX = 200;

	/**
	 * Distinct failures (site + outcome + pattern) counted per feed batch;
	 * any more are added up in $batch_overflow.
	 */
	const BATCH_TALLY_MAX = 20;

	/**
	 * Failures already logged this request, keyed by site + pattern.
	 *
	 * @var array<string, true>
	 */
	private static $logged = array();

	/**
	 * Failures of the feed batch now running, keyed by site + outcome +
	 * pattern; null while no batch runs (previews, REST, admin), so nothing
	 * is counted there.
	 *
	 * @var array<string, array{where: string, pattern: string, error: int, outcome: string, count: int}>|null
	 */
	private static $batch_tally = null;

	/**
	 * Failures of the running batch past BATCH_TALLY_MAX distinct entries.
	 *
	 * @var int
	 */
	private static $batch_overflow = 0;

	/**
	 * A preg_replace() that never returns null.
	 *
	 * On a JIT stack error the pattern is run once more with (*NO_JIT) — the
	 * interpreter has no such stack and usually completes. Any other PCRE
	 * failure (or a failed retry) returns $subject unchanged: a value is
	 * better left uncleaned than emptied. Each failing pattern is logged once
	 * per request, and counted for the running feed batch (log_failure()).
	 *
	 * @since 8.0.39
	 *
	 * @param string $pattern     PCRE pattern (written by us, not a merchant).
	 * @param string $replacement Replacement.
	 * @param string $subject     Value.
	 * @param string $where       Site named in the log line.
	 * @return string
	 */
	public static function replace( string $pattern, string $replacement, string $subject, string $where = 'Regex::replace()' ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		if ( null !== $result ) {
			return $result;
		}

		$error = preg_last_error();
		if ( PREG_JIT_STACKLIMIT_ERROR === $error ) {
			$result = preg_replace( self::without_jit( $pattern ), $replacement, $subject );
			if ( null !== $result ) {
				self::log_failure( $where, $pattern, $error, 'ran again without JIT; the result is complete' );
				return $result;
			}
			$error = preg_last_error();
		}

		self::log_failure( $where, $pattern, $error, 'the value was kept unchanged' );

		return $subject;
	}

	/**
	 * The pattern with (*NO_JIT) right after its opening delimiter.
	 *
	 * (*NO_JIT) must be the very first thing in the pattern body; PHP allows
	 * whitespace before the delimiter, so that is skipped. A pattern that
	 * already starts with it, or has no usable delimiter, is returned as is.
	 *
	 * @since 8.0.39
	 *
	 * @param string $pattern PCRE pattern with delimiters.
	 * @return string
	 */
	public static function without_jit( string $pattern ): string {
		$skip = strspn( $pattern, " \t\n\r\x0B\f" );
		if ( strlen( $pattern ) < $skip + 2 ) {
			return $pattern;
		}

		$delimiter = $pattern[ $skip ];
		// PHP rejects an alphanumeric, backslash or NUL delimiter (ASCII test: no locale).
		if ( "\0" === $delimiter || '\\' === $delimiter || false !== strpos( 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $delimiter ) ) {
			return $pattern;
		}

		$body = (string) substr( $pattern, $skip + 1 );
		if ( 0 === strncmp( $body, self::NO_JIT, strlen( self::NO_JIT ) ) ) {
			return $pattern;
		}

		return (string) substr( $pattern, 0, $skip + 1 ) . self::NO_JIT . $body;
	}

	/**
	 * Whether a preg_last_error() code is a run-time limit (the pattern is
	 * valid; this subject made PCRE give up).
	 *
	 * @since 8.0.39
	 *
	 * @param int $error preg_last_error() code.
	 * @return bool
	 */
	public static function is_limit_error( int $error ): bool {
		return PREG_BACKTRACK_LIMIT_ERROR === $error
			|| PREG_RECURSION_LIMIT_ERROR === $error
			|| PREG_JIT_STACKLIMIT_ERROR === $error;
	}

	/**
	 * Whether $error (read with preg_last_error() right after the failed
	 * call) is a run-time limit of a pattern that compiles — the case where
	 * a merchant's valid pattern gave up on one long value, as opposed to a
	 * pattern that does not compile (on PHP 7.4 preg_last_error() is not
	 * reset by a compile failure, so the pattern is compiled once more to
	 * tell the two apart).
	 *
	 * @since 8.0.39
	 *
	 * @param string $pattern Pattern that failed.
	 * @param int    $error   preg_last_error() code of that failure.
	 * @return bool
	 */
	public static function is_runtime_limit( string $pattern, int $error ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- Compile probe on an empty subject; a pattern that does not compile already warned in the caller's own call.
		return self::is_limit_error( $error ) && false !== @preg_match( $pattern, '' );
	}

	/**
	 * Log a PCRE failure once per site + pattern per request
	 * (ctxfeed-system.log, info; written in debug mode like every system
	 * trace), and count it for the feed batch now running, if any (see
	 * end_batch_tally()). Logging never breaks generation.
	 *
	 * @since 8.0.39
	 *
	 * @param string $where   Site, e.g. "[pattern-replace] command".
	 * @param string $pattern Pattern that failed.
	 * @param int    $error   preg_last_error() code.
	 * @param string $outcome What the site did instead.
	 * @return void
	 */
	public static function log_failure( string $where, string $pattern, int $error, string $outcome ): void {
		if ( null !== self::$batch_tally ) {
			self::count_for_batch( $where, $pattern, $error, $outcome );
		}

		$key = $where . "\0" . $pattern;
		if ( isset( self::$logged[ $key ] ) || count( self::$logged ) >= self::LOG_MEMO_MAX ) {
			return;
		}
		self::$logged[ $key ] = true;

		try {
			if ( class_exists( \CTXFeed\V8\Core\Logger::class ) ) {
				\CTXFeed\V8\Core\Logger::info(
					sprintf(
						'[REGEX] %s: PCRE stopped (%s) on the pattern %s — %s. Logged once per pattern per request.',
						$where,
						self::error_name( $error ),
						self::quote( $pattern ),
						$outcome
					)
				);
			}
		} catch ( \Throwable $e ) {
			// A log line must never stop a feed batch.
			unset( $e );
		}
	}

	/**
	 * Start counting PCRE failures for one feed batch (FeedGenerator, once
	 * per batch). An earlier count that was never collected is discarded.
	 *
	 * @since 8.0.39
	 *
	 * @return void
	 */
	public static function begin_batch_tally(): void {
		self::$batch_tally    = array();
		self::$batch_overflow = 0;
	}

	/**
	 * Stop counting and return the batch's failures, one plain line per site
	 * + outcome + pattern, for the feed's own log (FeedGenerator writes them
	 * as info lines — CBT-809 amendment 40). Empty when nothing failed or no
	 * batch was counting.
	 *
	 * @since 8.0.39
	 *
	 * @return string[]
	 */
	public static function end_batch_tally(): array {
		$tally    = (array) self::$batch_tally;
		$overflow = self::$batch_overflow;

		self::$batch_tally    = null;
		self::$batch_overflow = 0;

		$lines = array();
		foreach ( $tally as $entry ) {
			$lines[] = sprintf(
				'%s: PCRE stopped (%s) on %s with the pattern %s — %s.',
				$entry['where'],
				self::error_name( $entry['error'] ),
				1 === $entry['count'] ? '1 value' : $entry['count'] . ' values',
				self::quote( $entry['pattern'] ),
				$entry['outcome']
			);
		}
		if ( $overflow > 0 ) {
			$lines[] = sprintf(
				'%d more pattern failures in this batch, at other sites or with other patterns (each pattern is named in ctxfeed-system.log while "Enable error debugging" is on).',
				$overflow
			);
		}

		return $lines;
	}

	/**
	 * Count one failure for the running batch (O(1); at most
	 * BATCH_TALLY_MAX entries).
	 *
	 * @param string $where   Site.
	 * @param string $pattern Pattern that failed.
	 * @param int    $error   preg_last_error() code.
	 * @param string $outcome What the site did instead.
	 * @return void
	 */
	private static function count_for_batch( string $where, string $pattern, int $error, string $outcome ): void {
		$key = $where . "\0" . $outcome . "\0" . $pattern;
		if ( isset( self::$batch_tally[ $key ] ) ) {
			++self::$batch_tally[ $key ]['count'];
			return;
		}
		if ( count( (array) self::$batch_tally ) >= self::BATCH_TALLY_MAX ) {
			++self::$batch_overflow;
			return;
		}
		self::$batch_tally[ $key ] = array(
			'where'   => $where,
			'pattern' => $pattern,
			'error'   => $error,
			'outcome' => $outcome,
			'count'   => 1,
		);
	}

	/**
	 * The first LOG_PATTERN_BYTES bytes of a pattern for a log line, valid
	 * UTF-8.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private static function quote( string $pattern ): string {
		$quoted = (string) substr( $pattern, 0, self::LOG_PATTERN_BYTES );
		if ( strlen( $pattern ) > self::LOG_PATTERN_BYTES ) {
			$quoted .= '…';
		}

		return Utf8::scrub( $quoted );
	}

	/**
	 * Readable name of a preg_last_error() code.
	 *
	 * @since 8.0.39
	 *
	 * @param int $error preg_last_error() code.
	 * @return string
	 */
	public static function error_name( int $error ): string {
		switch ( $error ) {
			case PREG_INTERNAL_ERROR:
				return 'internal error';
			case PREG_BACKTRACK_LIMIT_ERROR:
				return 'pcre.backtrack_limit reached';
			case PREG_RECURSION_LIMIT_ERROR:
				return 'pcre.recursion_limit reached';
			case PREG_BAD_UTF8_ERROR:
				return 'malformed UTF-8';
			case PREG_BAD_UTF8_OFFSET_ERROR:
				return 'bad UTF-8 offset';
			case PREG_JIT_STACKLIMIT_ERROR:
				return 'JIT stack limit reached';
			default:
				return 'error ' . $error;
		}
	}
}
