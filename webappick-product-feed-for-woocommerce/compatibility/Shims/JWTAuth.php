<?php
/**
 * Compatibility class for the JWT Authentication for WP REST API plugin.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/jwt-auth/
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JWTAuth
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\Compat\Shims
 * @author     Nashir Uddin <nashirbabu@gmail.com>
 */
class JWTAuth {

	/**
	 * The REST API slug.
	 *
	 * @var string
	 */
	private $rest_api_slug = 'wp-json';

	/**
	 * JWTAuth Constructor.
	 */
	public function __construct() {
		add_filter( 'jwt_auth_default_whitelist', array( $this, 'woo_feed_jwt_auth_default_whitelist' ), 10, 1 );
	}

	/**
	 * Adjust the default whitelist for the jwt-auth plugin.
	 *
	 * Adds the CTX Feed REST namespace so feed URLs stay reachable without
	 * a JWT token.
	 *
	 * @param array $default_whitelist Routes jwt-auth leaves unauthenticated.
	 *
	 * @return array
	 */
	public function woo_feed_jwt_auth_default_whitelist( $default_whitelist ) {

		$this->rest_api_slug = get_option( 'permalink_structure' ) ? rest_get_url_prefix() : '?rest_route=/';

		array_push( $default_whitelist, '/' . $this->rest_api_slug . '/ctxfeed/v1/' );
		return $default_whitelist;
	}
}
