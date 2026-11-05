<?php
/**
 * Custom2CodeApproval — which Custom Template 2 code blocks may run.
 *
 * A `{( … )}` block is PHP that the Pro engine evaluates when the feed
 * generates. Only someone who may already run code on the site may author one:
 * an administrator, or a super admin on multisite (CVE-2026-87964, CBT-863).
 * Saving a template as such a user records a hash of each expression; Pro runs
 * an expression only when its exact text is recorded here or ships in a stock
 * template. Anything this class fails to recognise therefore does not run.
 *
 * @package    CTXFeed\V8
 * @subpackage Feed
 * @since      8.0.42
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\V8\Template\TemplateLocator;
use CTXFeed\V8\Utility\Sanitizer;

/**
 * Approval store and template guard for Custom Template 2 code blocks.
 *
 * @since 8.0.42
 */
final class Custom2CodeApproval {

	/**
	 * Option holding approved expression hashes (hash => 1).
	 *
	 * @var string
	 */
	const OPTION = 'ctxfeed_ct2_approved_code';

	/**
	 * Option written once by the upgrade scan; its presence means the scan ran.
	 *
	 * @var string
	 */
	const REVIEW_OPTION = 'ctxfeed_ct2_code_review';

	/**
	 * Characters the parser removes from a template before it reads a block,
	 * which could otherwise hide `{(` from the guard.
	 *
	 * @var string
	 */
	const NOISE = '(?:[\\\\+\'"]|&quot;|<!\[CDATA\[|\]\]>)*';

	/**
	 * Stock templates that ship with the plugin.
	 *
	 * @var string[]
	 */
	const STOCK_TEMPLATES = array( 'custom2', 'admarkt', 'glami', 'yandex_xml' );

	/**
	 * Hashes of the stock templates' expressions, once read.
	 *
	 * @var array<string,int>|null
	 */
	private static $stock = null;

	/**
	 * Guards the upgrade scan against re-entry.
	 *
	 * @var bool
	 */
	private static $scanning = false;

	/**
	 * Whether the current user may author code blocks.
	 *
	 * @since 8.0.42
	 *
	 * @return bool
	 */
	public static function can_author(): bool {
		if ( is_multisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether an expression, exactly as the engine would evaluate it, may run.
	 *
	 * @since 8.0.42
	 *
	 * @param string $expression Expression body, without the `{(` and `)}`.
	 * @return bool
	 */
	public static function is_approved( string $expression ): bool {
		self::maybe_scan_existing();

		$hash = self::hash( $expression );
		if ( isset( self::stored()[ $hash ] ) ) {
			return true;
		}

		return isset( self::stock()[ $hash ] );
	}

	/**
	 * Approve every expression in a template, if the current user may author
	 * code. Does nothing for anyone else.
	 *
	 * @since 8.0.42
	 *
	 * @param string $template Template body.
	 * @return void
	 */
	public static function approve_template( string $template ): void {
		if ( ! self::can_author() ) {
			return;
		}

		self::maybe_scan_existing();
		self::store( self::template_hashes( $template ) );
	}

	/**
	 * Remove every block that is not approved, including forms that would only
	 * become a block once the parser removes characters or an inner block is
	 * taken out. Approved blocks stay.
	 *
	 * @since 8.0.42
	 *
	 * @param string $template Template body (modified in place).
	 * @return int Blocks removed.
	 */
	public static function strip_unapproved( string &$template ): int {
		if ( false === strpos( $template, '{' ) ) {
			return 0;
		}

		$pattern = '/\{' . self::NOISE . '\((.*?)\)' . self::NOISE . '\}|\{' . self::NOISE . '\(/s';
		$removed = 0;

		do {
			$pass     = 0;
			$template = (string) preg_replace_callback(
				$pattern,
				static function ( array $found ) use ( &$pass ): string {
					if ( isset( $found[1] ) && self::is_approved( $found[1] ) ) {
						return $found[0];
					}
					++$pass;
					return '';
				},
				$template
			);
			$removed += $pass;
		} while ( $pass > 0 );

		return $removed;
	}

	/**
	 * Whether a template holds code that is not approved.
	 *
	 * @since 8.0.42
	 *
	 * @param string $template Template body.
	 * @return bool
	 */
	public static function has_unapproved( string $template ): bool {
		return self::strip_unapproved( $template ) > 0;
	}

	/**
	 * What the upgrade scan approved, for the one-time review notice.
	 *
	 * @since 8.0.42
	 *
	 * @return array{count:int, feeds:string[]}
	 */
	public static function review(): array {
		self::maybe_scan_existing();

		$review = get_option( self::REVIEW_OPTION, array() );

		return array(
			'count' => is_array( $review ) ? (int) ( $review['count'] ?? 0 ) : 0,
			'feeds' => is_array( $review ) && is_array( $review['feeds'] ?? null ) ? $review['feeds'] : array(),
		);
	}

	/**
	 * Approve the code in feeds saved before this guard existed, once per site,
	 * so they keep generating. Nothing tells a planted template from a real one
	 * here, which is why the feeds are also listed for an administrator to review.
	 *
	 * @since 8.0.42
	 *
	 * @return void
	 */
	private static function maybe_scan_existing(): void {
		global $wpdb;

		if ( self::$scanning || false !== get_option( self::REVIEW_OPTION, false ) ) {
			return;
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return;
		}

		self::$scanning = true;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time upgrade scan of feed options, not product data; there is no WP API to list options by prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'wf_feed_' ) . '%',
				$wpdb->esc_like( 'wf_config' ) . '%'
			),
			ARRAY_A
		);

		// A failed read must not mark the scan done: approved feeds would stop.
		// wpdb returns an empty array when the query fails, so check the error too.
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			self::$scanning = false;
			return;
		}

		$hashes = array();
		$feeds  = array();
		foreach ( $rows as $row ) {
			$config = Sanitizer::safe_unserialize( Sanitizer::safe_unserialize( $row['option_value'] ) );
			$rules  = is_array( $config ) && is_array( $config['feedrules'] ?? null ) ? $config['feedrules'] : $config;
			$body   = is_array( $rules ) ? ( $rules['feed_config_custom2'] ?? '' ) : '';
			if ( ! is_string( $body ) || '' === $body ) {
				continue;
			}

			$found   = array_diff_key( self::template_hashes( $body ), self::stock() );
			$hashes += $found;
			if ( ! empty( $found ) && 0 === strpos( (string) $row['option_name'], 'wf_feed_' ) ) {
				$feeds[] = sanitize_text_field( (string) ( $rules['filename'] ?? substr( (string) $row['option_name'], 8 ) ) );
			}
		}

		self::store( $hashes );
		update_option(
			self::REVIEW_OPTION,
			array(
				'count' => count( $feeds ),
				'feeds' => array_slice( $feeds, 0, 5 ),
			),
			false
		);

		self::$scanning = false;
	}

	/**
	 * Recorded hashes.
	 *
	 * @since 8.0.42
	 *
	 * @return array<string,int>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Add hashes to the record.
	 *
	 * @since 8.0.42
	 *
	 * @param array<string,int> $hashes Hashes to add.
	 * @return void
	 */
	private static function store( array $hashes ): void {
		$stored = self::stored();
		$merged = $stored + $hashes;
		if ( count( $merged ) !== count( $stored ) ) {
			update_option( self::OPTION, $merged, false );
		}
	}

	/**
	 * Hashes of the expressions in the stock templates.
	 *
	 * @since 8.0.42
	 *
	 * @return array<string,int>
	 */
	private static function stock(): array {
		if ( null !== self::$stock ) {
			return self::$stock;
		}

		self::$stock = array();
		foreach ( self::STOCK_TEMPLATES as $name ) {
			$file = TemplateLocator::path( 'custom2/' . $name . '.txt' );
			if ( '' === $file || ! is_readable( $file ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file that ships with the plugin.
			$body = file_get_contents( $file );
			if ( is_string( $body ) ) {
				self::$stock += self::template_hashes( $body );
			}
		}

		return self::$stock;
	}

	/**
	 * Hashes of every expression in a template, in each form the parser can
	 * hand to the engine: as written, with `+` removed (template bodies), and
	 * with CDATA markers and quotes removed (element text and start tags).
	 *
	 * @since 8.0.42
	 *
	 * @param string $template Template body.
	 * @return array<string,int>
	 */
	private static function template_hashes( string $template ): array {
		$template = str_replace( '\\', '', $template );
		if ( false === strpos( $template, '(' ) ) {
			return array();
		}

		$quote = (string) apply_filters( 'woo_feed_template_2_quotation', '' );

		$forms = array( $template, str_replace( '+', '', $template ) );
		foreach ( $forms as $form ) {
			$forms[] = str_replace( array( '<![CDATA[', ']]>' ), '', $form );
		}
		foreach ( $forms as $form ) {
			$forms[] = str_replace( array( "'", '"', '&quot;' ), $quote, $form );
		}

		$hashes = array();
		foreach ( array_unique( $forms ) as $form ) {
			if ( preg_match_all( '/\{\((.*?)\)\}/', $form, $matches ) ) {
				foreach ( $matches[1] as $expression ) {
					$hashes[ self::hash( $expression ) ] = 1;
				}
			}
		}

		return $hashes;
	}

	/**
	 * Hash of an expression, in the form the engine evaluates.
	 *
	 * @since 8.0.42
	 *
	 * @param string $expression Expression body.
	 * @return string
	 */
	private static function hash( string $expression ): string {
		return hash( 'sha256', trim( str_replace( '\\', '', $expression ) ) );
	}
}
