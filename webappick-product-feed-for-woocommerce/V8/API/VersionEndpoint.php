<?php
/**
 * VersionEndpoint — REST API for the Version Control page's upgrade and
 * rollback actions (owner-approved feature, 2026-07-28).
 *
 * POST /version/install installs an exact release of the free plugin from
 * WordPress.org over the current files — used both to update to the latest
 * release and to reinstall an earlier one. Requires the WordPress
 * `update_plugins` capability on top of the module-wide capability check.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Utility\PluginInstaller;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version REST endpoint.
 *
 * @since 8.0.0
 */
class VersionEndpoint extends RestController {

	/**
	 * Installer (constructor-injected; defaults for prod wiring).
	 *
	 * @since 8.0.0
	 * @var PluginInstaller
	 */
	private $installer;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param PluginInstaller|null $installer Installer instance.
	 */
	public function __construct( ?PluginInstaller $installer = null ) {
		$this->installer = $installer ?? new PluginInstaller();
	}

	/**
	 * Register the version route.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/version/install',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_version' ),
				'permission_callback' => array( $this, 'install_permission_check' ),
				'args'                => array(
					'version' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) 
		);
	}

	/**
	 * Replacing plugin files needs the core update capability on top of
	 * the module-wide check.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool
	 */
	public function install_permission_check( $request ): bool {
		return (bool) $this->permission_check( $request ) && current_user_can( 'update_plugins' );
	}

	/**
	 * Install an exact plugin release from WordPress.org.
	 *
	 * POST /ctxfeed/v8/version/install {version}
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function install_version( \WP_REST_Request $request ): \WP_REST_Response {
		$version = trim( (string) $request->get_param( 'version' ) );

		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return $this->error( __( 'Invalid version number.', 'woo-feed' ), 400 );
		}

		$result = $this->installer->install( $version );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message(), 500 );
		}

		return $this->success(
			array(
				'version' => $version,
				'message' => sprintf(
				/* translators: %s: installed version number. */
					__( 'Version %s installed.', 'woo-feed' ),
					$version
				),
			) 
		);
	}
}
