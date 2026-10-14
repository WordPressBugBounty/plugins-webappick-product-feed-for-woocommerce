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
		// Feed rows and Attribute Mapping parts resolve `vendor_store_name`
		// through the Legacy Bridge filter (CBT-754: nothing answered it).
		add_filter( 'woo_feed_filter_product_vendor_store_name', array( $this, 'vendor_store_name' ), 10, 2 );
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
		// The provider's own detection (CBT-754: the old check called a global
		// woo_feed_is_multi_vendor() that no plugin defines, so the group
		// never appeared).
		if ( '' === $this->vendor_user_role() ) {
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

	/**
	 * The product's vendor store name, from the active marketplace plugin.
	 *
	 * Answers `woo_feed_filter_product_vendor_store_name` (V5 parity — Pro
	 * 7.x `vendor_store_name()`). A variation belongs to its parent's vendor.
	 * Every marketplace API is existence-checked; anything missing yields ''.
	 *
	 * @since 8.0.36
	 *
	 * @param mixed       $value   Value resolved so far.
	 * @param \WC_Product $product Product being exported.
	 * @return string
	 */
	public function vendor_store_name( $value, $product = null ): string {
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return (string) $value;
		}
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$product_id = $product->is_type( 'variation' ) && $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		$store      = $this->store_name_for( $product_id );

		return trim( wp_strip_all_tags( html_entity_decode( (string) $store, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Store name lookup per marketplace plugin.
	 *
	 * @param int $product_id Parent product id.
	 * @return string
	 */
	private function store_name_for( int $product_id ): string {
		$author = (int) get_post_field( 'post_author', $product_id );

		// Dokan.
		if ( class_exists( 'WeDevs_Dokan', false ) ) {
			if ( function_exists( 'dokan_get_store_info' ) && $author > 0 ) {
				$info = dokan_get_store_info( $author );
				if ( is_array( $info ) && ! empty( $info['store_name'] ) ) {
					return (string) $info['store_name'];
				}
			}
			return '';
		}

		// WCFM Marketplace.
		if ( class_exists( 'WCFMmp', false ) ) {
			if ( function_exists( 'wcfm_get_vendor_id_by_post' ) && function_exists( 'wcfm_get_vendor_store_name' ) ) {
				$vendor_id = (int) wcfm_get_vendor_id_by_post( $product_id );
				$name      = $vendor_id > 0 ? wcfm_get_vendor_store_name( $vendor_id ) : '';
				return is_string( $name ) ? $name : '';
			}
			return '';
		}

		// WC Vendors.
		if ( class_exists( 'WC_Vendors', false ) ) {
			if ( class_exists( 'WCV_Vendors' ) && method_exists( 'WCV_Vendors', 'get_vendor_shop_name' ) && $author > 0 ) {
				return (string) \WCV_Vendors::get_vendor_shop_name( $author );
			}
			return '';
		}

		// YITH WooCommerce Multi Vendor: the vendor is a taxonomy term.
		if ( class_exists( 'YITH_Vendor', false ) ) {
			$terms = taxonomy_exists( 'yith_shop_vendor' ) ? get_the_terms( $product_id, 'yith_shop_vendor' ) : false;
			return is_array( $terms ) && isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
		}

		// MultiVendorX (formerly WCMp).
		if ( class_exists( 'MVX', false ) || class_exists( 'WCMp', false ) ) {
			$getter = function_exists( 'get_mvx_product_vendors' ) ? 'get_mvx_product_vendors' : ( function_exists( 'get_wcmp_product_vendors' ) ? 'get_wcmp_product_vendors' : '' );
			$vendor = '' !== $getter ? call_user_func( $getter, $product_id ) : null;
			if ( is_object( $vendor ) && isset( $vendor->id ) ) {
				$title = get_user_meta( (int) $vendor->id, '_vendor_page_title', true );
				return is_string( $title ) ? $title : '';
			}
			return '';
		}

		return '';
	}
}
