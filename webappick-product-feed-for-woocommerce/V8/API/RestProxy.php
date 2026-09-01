<?php
/**
 * RestProxy — admin-ajax fallback for the plugin's REST API.
 *
 * Some sites sit behind a CDN/WAF (e.g. Cloudflare Managed Challenge) that
 * intermittently 403-challenges `/wp-json/` requests. A background fetch/XHR
 * can't solve an interactive challenge, so the React admin's REST calls
 * (status polling, license, settings, …) fail sporadically.
 *
 * `admin-ajax.php` lives under `/wp-admin/`, NOT under the challenged
 * `/wp-json/` path, so this proxy re-dispatches a `ctxfeed/v8` REST route
 * INTERNALLY via `rest_do_request()` — same route, same per-route permission
 * callbacks, no second HTTP hop — reached through admin-ajax instead.
 *
 * The React admin only falls back to this route when a `/wp-json/` call comes
 * back as a challenge, so there is zero overhead on the normal path, and feed
 * generation (server-side Action Scheduler batches) is completely unaffected.
 *
 * Security: it is NOT a generic REST proxy. It verifies the same `wp_rest`
 * nonce the REST API uses (CSRF) and only ever dispatches routes inside the
 * `/ctxfeed/v8` namespace; authorization is enforced by each route's own
 * permission callback (`manage_woocommerce`).
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.2
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-ajax → internal REST dispatch bridge for CDN-challenged sites.
 *
 * @since 8.0.2
 */
class RestProxy {

	/**
	 * The admin-ajax action name.
	 *
	 * @since 8.0.2
	 * @var string
	 */
	const ACTION = 'ctxfeed_rest_proxy';

	/**
	 * The only REST namespace this proxy will dispatch (never a generic proxy).
	 *
	 * @since 8.0.2
	 * @var string
	 */
	const NAMESPACE_PREFIX = '/ctxfeed/v8';

	/**
	 * HTTP methods the proxy will forward.
	 *
	 * @since 8.0.2
	 * @var string[]
	 */
	const ALLOWED_METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Register the logged-in admin-ajax handler.
	 *
	 * @since 8.0.2
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Dispatch the requested ctxfeed/v8 REST route internally and echo the
	 * REST response verbatim as JSON (with its real status code).
	 *
	 * @since 8.0.2
	 * @return void
	 */
	public function handle(): void {
		// Authenticate with the SAME nonce the REST API uses
		// (wp_create_nonce 'wp_rest'), so this route is exactly as protected
		// as /wp-json/ — this is the CSRF gate.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read here, verified on the next line.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			wp_send_json(
				array(
					'code'    => 'rest_cookie_invalid_nonce',
					'message' => __( 'Cookie check failed.', 'woo-feed' ),
				),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$route = isset( $_REQUEST['ctxfeed_route'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ctxfeed_route'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$method = isset( $_REQUEST['ctxfeed_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['ctxfeed_method'] ) ) ) : 'GET';

		$route = '/' . ltrim( $route, '/' );

		// Hard-restrict to our own namespace — never a generic REST proxy.
		if ( self::NAMESPACE_PREFIX !== $route && 0 !== strpos( $route, self::NAMESPACE_PREFIX . '/' ) ) {
			wp_send_json(
				array(
					'code'    => 'ctxfeed_proxy_forbidden_route',
					'message' => __( 'Route not allowed.', 'woo-feed' ),
				),
				403
			);
		}

		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			$method = 'GET';
		}

		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', $nonce );

		// Query args (channel-scoped filters, pagination, etc.).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		if ( isset( $_REQUEST['ctxfeed_query'] ) ) {
			$query = json_decode( sanitize_textarea_field( wp_unslash( $_REQUEST['ctxfeed_query'] ) ), true );
			if ( is_array( $query ) ) {
				$request->set_query_params( $query );
			}
		}

		// JSON body for write methods — reproduce a real application/json
		// request so each route reads its params exactly as it would over HTTP.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		if ( isset( $_REQUEST['ctxfeed_body'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- forwarded verbatim as the JSON body; the target REST route sanitises its own params.
			$raw = (string) wp_unslash( $_REQUEST['ctxfeed_body'] );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $raw );
		}

		$server   = rest_get_server();
		$response = rest_do_request( $request );
		$data     = $server->response_to_data( $response, false );

		wp_send_json( $data, $response->get_status() );
	}
}
