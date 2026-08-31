<?php
/**
 * MultiVendorCompatibilityProvider — multi-vendor plugin integration.
 *
 * Holds the vendor logic relocated OUT of V8 core:
 *   • `V8/Common/DropdownRegistry::get_vendors()` — vendor-role detection
 *     (formerly via `V5\Common\Helper::get_multi_vendor_user_role()`) + the
 *     vendor-user query.
 *   • The `vendor_store_name` attribute group formerly in
 *     `V8/Product/AttributeRegistry::get_multi_vendor_attributes()`.
 *
 * Supports Dokan, WC Vendors, YITH Multi Vendor, MultiVendorX (MVX), and WCFM.
 *
 * Hooks answered (registered from Bootstrap::init()):
 *   • `ctxfeed_dropdown_vendors`           — vendor ID => display name.
 *   • `ctxfeed_register_vendor_attributes` — the Multi Vendor dropdown group.
 *
 * @package CTXFeed\Compat\Providers
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Providers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-vendor compatibility provider.
 *
 * @since 8.0.0
 */
class MultiVendorCompatibilityProvider {

	/**
	 * Vendor plugin main-class → user role.
	 *
	 * @since 8.0.0
	 * @var array<string, string>
	 */
	private static $role_map = array(
		'WeDevs_Dokan' => 'seller',
		'WC_Vendors'   => 'vendor',
		'YITH_Vendor'  => 'yith_vendor',
		'MVX'          => 'dc_vendor',
		'WCFMmp'       => 'wcfm_vendor',
	);

	/**
	 * Register the hook seams this provider answers.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ctxfeed_dropdown_vendors', array( $this, 'vendors' ) );
		add_filter( 'ctxfeed_register_vendor_attributes', array( $this, 'vendor_attributes' ) );
	}

	/**
	 * Build the vendor dropdown list (vendor ID => display name).
	 *
	 * Answers `ctxfeed_dropdown_vendors`. Returns the incoming value untouched
	 * when already populated by an earlier provider.
	 *
	 * @since 8.0.0
	 *
	 * @param array $vendors Incoming vendor list (default empty).
	 * @return array
	 */
	public function vendors( $vendors = array() ): array {
		if ( ! empty( $vendors ) ) {
			return (array) $vendors;
		}

		$vendors     = array();
		$vendor_role = $this->vendor_user_role();

		if ( empty( $vendor_role ) ) {
			return $vendors;
		}

		/** Legacy V5 filter for vendor query args. */
		$args = apply_filters( 'woo_feed_get_vendors_args', array( 'role' => $vendor_role ) );

		if ( ! is_array( $args ) || empty( $args ) ) {
			return $vendors;
		}

		$users = get_users( $args );

		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$vendors[ $user->ID ] = $user->display_name;
			}
		}

		/** Legacy V5 filter. */
		return (array) apply_filters( 'woo_feed_product_vendors', $vendors );
	}

	/**
	 * Detect the active vendor plugin's user role.
	 *
	 * Native replacement for `V5\Common\Helper::get_multi_vendor_user_role()`.
	 *
	 * @since 8.0.0
	 *
	 * @return string Role slug, or '' when no vendor plugin is active.
	 */
	public function vendor_user_role(): string {
		$vendor_role = '';
		foreach ( self::$role_map as $class => $role ) {
			if ( class_exists( $class, false ) ) {
				$vendor_role = $role;
				break;
			}
		}

		/**
		 * Filter the multi-vendor user role.
		 *
		 * @since 3.4.0
		 *
		 * @param string $vendor_role Detected vendor role slug.
		 */
		return (string) apply_filters( 'woo_feed_multi_vendor_user_role', $vendor_role );
	}

	/**
	 * Provide the Multi Vendor dropdown attribute group.
	 *
	 * Answers `ctxfeed_register_vendor_attributes`. Only fires inside the
	 * Pro-gated call site in AttributeRegistry, matching prior behaviour.
	 *
	 * @since 8.0.0
	 *
	 * @param array $group Incoming group (default empty).
	 * @return array{optionGroup:string, options:array<string,string>}
	 */
	public function vendor_attributes( $group = array() ): array {
		if ( ! function_exists( 'woo_feed_is_multi_vendor' ) || ! woo_feed_is_multi_vendor() ) {
			return is_array( $group ) && ! empty( $group ) ? $group : array(
				'optionGroup' => '',
				'options'     => array(),
			);
		}

		return array(
			'optionGroup' => __( 'Multi Vendor Attributes', 'woo-feed' ),
			'options'     => array(
				'vendor_store_name' => __( 'Vendor Store Name', 'woo-feed' ),
			),
		);
	}
}
