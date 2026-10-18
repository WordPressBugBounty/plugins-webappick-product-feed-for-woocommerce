<?php
/**
 * RestDispatchGuard — a failing CTX Feed REST route answers with an error
 * instead of taking the request down (CBT-806).
 *
 * WordPress's REST server calls a route's callback with no try/catch, so any
 * uncaught Error in it ends the request with the critical-error page and the
 * "Your site is experiencing a technical issue" email. The CTX Feed admin
 * polls its routes while it is open, so the most common trigger is ordinary:
 * WordPress updates the plugin, moves its folder away for a moment, and the
 * next poll loads a class whose file is gone ("Class … not found", HelpScout
 * #69591).
 *
 * One rest_dispatch_request filter at the last priority runs the callback of
 * every route under /ctxfeed/v8 — the free and the Pro routes share that
 * namespace, and the admin-ajax REST fallback (RestProxy) dispatches through
 * the same server — inside a catch. A failure becomes a 500 WP_Error for that
 * call; Bootstrap::contain() records a file that could not be loaded for the
 * admin notice and writes any other error to the PHP error log. A successful
 * callback's return value is passed through untouched.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.38
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains failures of the CTX Feed REST route callbacks.
 *
 * @since 8.0.38
 */
final class RestDispatchGuard {

	/**
	 * Route prefix of the CTX Feed REST namespace (free and Pro).
	 *
	 * @since 8.0.38
	 */
	const ROUTE_PREFIX = '/ctxfeed/v8';

	/**
	 * WP_Error code of a contained failure.
	 *
	 * @since 8.0.38
	 */
	const ERROR_CODE = 'ctxfeed_request_failed';

	/**
	 * Hook the guard. Called from ApiServiceProvider::boot(), so this class
	 * is loaded during the guarded boot — never first loaded by a REST
	 * request that may overlap an update.
	 *
	 * Last priority: a plugin that answers the request itself (a non-null
	 * result from an earlier callback) keeps doing so.
	 *
	 * @since 8.0.38
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'dispatch' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Run a CTX Feed route's callback inside a catch.
	 *
	 * Hooked to: rest_dispatch_request (PHP_INT_MAX). WordPress uses a
	 * non-null result instead of calling the callback itself.
	 *
	 * @since 8.0.38
	 *
	 * @param mixed                 $result  Result from an earlier filter; null when none answered.
	 * @param \WP_REST_Request|null $request Request being dispatched.
	 * @param string                $route   Matched route regex.
	 * @param array|mixed           $handler Matched route handler.
	 * @return mixed The callback's own return value, a WP_Error on failure, or $result untouched.
	 */
	public static function dispatch( $result, $request = null, $route = '', $handler = array() ) {
		if ( null !== $result || ! is_array( $handler ) || ! isset( $handler['callback'] ) || ! self::is_own_route( $route, $request ) ) {
			return $result;
		}

		$callback = $handler['callback'];
		$failure  = null;
		$run      = static function () use ( $callback, $request, &$failure ) {
			try {
				return call_user_func( $callback, $request );
			} catch ( \Throwable $e ) {
				// Keep the error for the response, then let the recorder see it.
				$failure = $e;
				throw $e;
			}
		};

		// Unique marker: a callback can legitimately return null or false.
		$failed = new \stdClass();
		if ( class_exists( '\\CTXFeed\\V8\\Bootstrap', false ) && method_exists( '\\CTXFeed\\V8\\Bootstrap', 'contain' ) ) {
			// Records a file that could not be loaded for the admin notice
			// (not while the plugin's files are being updated), logs any
			// other error, and returns $failed.
			$response = \CTXFeed\V8\Bootstrap::contain( $run, $failed );
		} else {
			try {
				$response = $run();
			} catch ( \Throwable $e ) {
				$response = $failed;
			}
		}

		if ( $failed !== $response ) {
			// A null result would make WordPress call the callback a second
			// time. WordPress turns null into exactly this response anyway
			// (rest_ensure_response), so the client sees no difference.
			return null === $response ? new \WP_REST_Response( null ) : $response;
		}

		return self::error( $failure );
	}

	/**
	 * Whether the matched route belongs to the CTX Feed namespace.
	 *
	 * @since 8.0.38
	 *
	 * @param mixed $route   Matched route regex.
	 * @param mixed $request Request (its route is the fallback when none was passed).
	 * @return bool
	 */
	private static function is_own_route( $route, $request ): bool {
		$route = is_string( $route ) ? $route : '';
		if ( '' === $route && is_object( $request ) && method_exists( $request, 'get_route' ) ) {
			$route = (string) $request->get_route();
		}

		return self::ROUTE_PREFIX === $route || 0 === strpos( $route, self::ROUTE_PREFIX . '/' );
	}

	/**
	 * The error a failed callback answers with. The technical detail is shown
	 * only to store managers — it can name server paths.
	 *
	 * @since 8.0.38
	 *
	 * @param \Throwable|null $failure The caught error, when known.
	 * @return \WP_Error
	 */
	private static function error( $failure ): \WP_Error {
		$message = __( 'CTX Feed could not complete this request. Please try again in a moment.', 'woo-feed' );
		if ( $failure instanceof \Throwable && function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: technical error message */
				__( 'Details: %s', 'woo-feed' ),
				substr( $failure->getMessage(), 0, 300 )
			);
		}

		return new \WP_Error( self::ERROR_CODE, $message, array( 'status' => 500 ) );
	}
}
