<?php
/**
 * DashboardWidget — the "Latest News from WebAppick Blog" WordPress dashboard
 * widget, ported natively into V8.
 *
 * Replaces the V5 procedural implementation in includes/widget.php, which was
 * only ever loaded from the (now dormant) V5 bootstrap and therefore stopped
 * rendering entirely once V8 became the sole engine.
 *
 * Registered by AdminServiceProvider in admin requests only.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress dashboard widget listing recent posts from the WebAppick blog.
 *
 * Behaviour preserved from V5: same widget title, same `side`/`high`
 * placement, same meta-box ID (so a merchant's existing dashboard ordering and
 * hidden-widget preferences carry over), same source endpoint, and the same
 * transient key.
 *
 * Deliberately hardened compared with V5:
 *  - the remote request carries an explicit, short timeout, so a slow or dead
 *    blog host can never stall a dashboard render;
 *  - a failed request, an empty body, or malformed JSON renders a single plain
 *    sentence — never fabricated or hard-coded placeholder posts;
 *  - failures are cached briefly, so a down blog is not re-polled on every
 *    single dashboard load;
 *  - only the fields actually rendered are cached (V5 stored the full HTML
 *    body of five posts in the transient);
 *  - every value interpolated into markup is escaped.
 *
 * @since 8.0.0
 */
final class DashboardWidget {

	/**
	 * Meta-box ID for the widget.
	 *
	 * Carries a sort-first `aaaaaa_` prefix (V5 used `aaaa_`), extended on
	 * purpose so the widget outranks other plugins that play the same
	 * alphabetical-prefix trick.
	 *
	 * Note the ID is also the key WordPress persists per-user dashboard order
	 * and visibility against, so changing it discards any saved position for
	 * the old ID — which is what lets a merchant who had previously dragged
	 * the widget down (or hidden it) see it back at the top. The `'side'` +
	 * `'high'` registration below is what actually places it above core
	 * widgets; the prefix only breaks ties.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const WIDGET_ID = 'aaaaaa_webappick_latest_news_dashboard_widget';

	/**
	 * Transient key holding the cached post list.
	 *
	 * Same key as V5. Reuse is safe because the cached payload is validated
	 * before use (see is_valid_cache()): a leftover V5 payload simply fails
	 * validation and is refetched, and reusing the key means the stale row is
	 * overwritten rather than orphaned in the options table.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const TRANSIENT_KEY = 'woo_feed_webappick_posts';

	/**
	 * Option name holding the cache lifetime, in seconds.
	 *
	 * V5 read the lifetime from an option whose name was the *same string* as
	 * the transient key (`woo_feed_webappick_posts`) — a copy/paste slip that
	 * made the setting undiscoverable and collision-prone. V8 uses a distinct,
	 * self-describing name. Nothing ever wrote the V5 option, so no stored
	 * value is lost.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const DURATION_OPTION = 'ctxfeed_dashboard_widget_cache_ttl';

	/**
	 * Default cache lifetime for a successful fetch, in seconds (V5 parity).
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Cache lifetime for a failed/empty fetch, in seconds.
	 *
	 * Short enough that the widget recovers quickly once the blog is back,
	 * long enough that an outage costs one request per 15 minutes rather than
	 * one per dashboard load.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const FAILURE_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Remote endpoint returning the five most recent posts.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const FEED_URL = 'https://webappick.com/wp-json/wp/v2/posts?per_page=5';

	/**
	 * Remote request timeout, in seconds.
	 *
	 * This request happens inline while WordPress renders wp-admin/index.php,
	 * so the budget is deliberately tiny — an unreachable blog must cost the
	 * merchant a few seconds at most, never a hung dashboard.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const REQUEST_TIMEOUT = 5;

	/**
	 * Number of words kept from each post body for the excerpt.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const EXCERPT_WORDS = 35;

	/**
	 * Attach WordPress hooks.
	 *
	 * Wired by AdminServiceProvider::boot() for admin requests only.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook action wp_dashboard_setup → register_widget()
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ), 1 );
	}

	/**
	 * Register the dashboard meta box.
	 *
	 * Skipped for users without the WooCommerce management capability (the gate
	 * used by every other CTX Feed admin surface), and skippable entirely by
	 * site owners through the `ctxfeed_show_dashboard_widget` filter.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook filter ctxfeed_show_dashboard_widget — Return false to remove the widget.
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		/**
		 * Filters whether the WebAppick news dashboard widget is shown.
		 *
		 * Defaults to true, matching V5 behaviour. Site owners who do not want
		 * plugin news on their dashboard can return false.
		 *
		 * @since 8.0.0
		 *
		 * @param bool $show Whether to register the widget.
		 */
		if ( ! apply_filters( 'ctxfeed_show_dashboard_widget', true ) ) {
			return;
		}

		add_meta_box(
			self::WIDGET_ID,
			__( 'Latest News from WebAppick Blog', 'woo-feed' ),
			array( $this, 'render' ),
			'dashboard',
			'side',
			'high'
		);
	}

	/**
	 * Render the widget body.
	 *
	 * When no posts are available — request failure, empty body, malformed
	 * JSON, or an empty feed — this prints one plain sentence saying so. It
	 * never invents posts.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		printf(
			'<p><a style="text-decoration:none;font-weight:bold;" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p><hr>',
			esc_url( 'https://webappick.com' ),
			esc_html__( 'WEBAPPICK.COM', 'woo-feed' )
		);

		$posts = $this->get_posts();

		if ( empty( $posts ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Blog posts could not be loaded right now.', 'woo-feed' )
			);

			return;
		}

		foreach ( $posts as $post ) {
			printf(
				'<p class="webappick-feeds"><a style="text-decoration:none;" href="%s" target="_blank" rel="noopener noreferrer">%s</a>%s</p><span>%s</span>',
				esc_url( $post['link'] ),
				esc_html( $post['title'] ),
				'' === $post['date'] ? '' : esc_html( ' - ' . $this->format_date( $post['date'] ) ),
				esc_html( $post['excerpt'] )
			);
		}

		printf(
			'<hr><p><a style="text-decoration:none;" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( 'https://webappick.com/blog/' ),
			esc_html__( 'Get more woocommerce tips & news on our blog...', 'woo-feed' )
		);
	}

	/**
	 * Get the post list, from cache when possible.
	 *
	 * Both outcomes are cached — a successful list for the configured lifetime,
	 * an empty list for a short one — so a single dashboard render performs at
	 * most one remote request and a broken blog is not re-polled every time.
	 *
	 * @since 8.0.0
	 *
	 * @return array<int,array<string,string>> Normalised post rows; empty when unavailable.
	 */
	private function get_posts(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && $this->is_valid_cache( $cached ) ) {
			return $cached;
		}

		$posts = $this->fetch_posts();

		set_transient(
			self::TRANSIENT_KEY,
			$posts,
			empty( $posts ) ? self::FAILURE_CACHE_TTL : $this->cache_duration()
		);

		return $posts;
	}

	/**
	 * Fetch and normalise the post list from the WebAppick blog.
	 *
	 * Every failure mode — transport error, non-200 status, empty body, JSON
	 * that does not decode to a list — returns an empty array. Nothing is
	 * substituted for missing data.
	 *
	 * @since 8.0.0
	 *
	 * @return array<int,array<string,string>> Normalised post rows; empty on any failure.
	 */
	private function fetch_posts(): array {
		$response = wp_safe_remote_get(
			self::FEED_URL,
			array(
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Deliberately above the 3s VIP guidance and deliberately far below PHP's default: webappick.com answers this endpoint in well under a second, and the result is cached for a day (15 minutes on failure), so this blocks a dashboard render at most once per cache period.
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === trim( $body ) ) {
			return array();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return $this->normalize_posts( $decoded );
	}

	/**
	 * Reduce a decoded wp/v2/posts payload to the fields the widget renders.
	 *
	 * Entries missing a link or a title are dropped rather than rendered with a
	 * placeholder.
	 *
	 * @since 8.0.0
	 *
	 * @param array $decoded Decoded REST payload.
	 * @return array<int,array<string,string>> Normalised post rows.
	 */
	private function normalize_posts( array $decoded ): array {
		$posts = array();

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$link  = isset( $item['link'] ) ? (string) $item['link'] : '';
			$title = isset( $item['title']['rendered'] ) ? (string) $item['title']['rendered'] : '';

			if ( '' === $link || '' === $title ) {
				continue;
			}

			$content = isset( $item['content']['rendered'] ) ? (string) $item['content']['rendered'] : '';

			$posts[] = array(
				'link'    => $link,
				'title'   => $this->plain_text( $title ),
				'date'    => isset( $item['modified'] ) ? (string) $item['modified'] : '',
				'excerpt' => '' === $content ? '' : $this->plain_text( wp_trim_words( $content, self::EXCERPT_WORDS, '...' ) ),
			);
		}

		return $posts;
	}

	/**
	 * Strip markup and resolve HTML entities so the value survives esc_html().
	 *
	 * The REST API returns `rendered` fields with entities already encoded
	 * (`Feeds &#038; Filters`). Escaping those again — as V5 did — double-
	 * encodes them and the raw entity shows up on screen.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Rendered HTML fragment from the REST API.
	 * @return string Plain text, ready to be escaped on output.
	 */
	private function plain_text( string $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Format a post's modification date using the site's date format.
	 *
	 * @since 8.0.0
	 *
	 * @param string $date Date string as returned by the REST API.
	 * @return string Localised date, or an empty string when unparsable.
	 */
	private function format_date( string $date ): string {
		$timestamp = strtotime( $date );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) date_i18n( (string) get_option( 'date_format', 'M j, Y' ), $timestamp );
	}

	/**
	 * Resolve the cache lifetime for a successful fetch.
	 *
	 * @since 8.0.0
	 *
	 * @return int Lifetime in seconds; the default when the option is unset or nonsensical.
	 */
	private function cache_duration(): int {
		$duration = (int) get_option( self::DURATION_OPTION, self::DEFAULT_CACHE_TTL );

		return $duration > 0 ? $duration : self::DEFAULT_CACHE_TTL;
	}

	/**
	 * Check that a cached value has the shape this class writes.
	 *
	 * An empty array is valid — that is the negative-result cache. Anything
	 * else must be a list of rows carrying all four rendered fields, which is
	 * what rejects a leftover V5 payload (a list of stdClass REST objects).
	 *
	 * @since 8.0.0
	 *
	 * @param array $cached Cached transient value.
	 * @return bool True when the value can be rendered as-is.
	 */
	private function is_valid_cache( array $cached ): bool {
		foreach ( $cached as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}

			foreach ( array( 'link', 'title', 'date', 'excerpt' ) as $key ) {
				if ( ! isset( $row[ $key ] ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
