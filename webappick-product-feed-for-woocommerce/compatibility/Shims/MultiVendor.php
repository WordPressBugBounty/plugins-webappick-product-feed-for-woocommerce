<?php
/**
 * Compatibility class for the multi-vendor marketplace plugins.
 *
 * Adds a "Product Feed" tab to the WooCommerce My Account area so a vendor
 * can reach the feeds that were generated for them.
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MultiVendor
 *
 * @package CTXFeed\Compat\Shims
 */
class MultiVendor {

	/**
	 * MultiVendor Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_account_menu_items', array( $this, 'woo_feed_add_view_feeds_tab_menu' ) );
		add_action( 'init', array( $this, 'woo_feed_add_endpoint_for_view_feeds_menu' ) );
		add_action( 'woocommerce_account_view-feeds_endpoint', array( $this, 'woo_feed_view_vendor_feeds_endpoint_add_content' ) );
		add_filter( 'generate_rewrite_rules', array( $this, 'woo_feed_add_rewrite_rules_for_view_feeds_tab' ) );
	}
	/**
	 * Add View Feed Tabs to My Account
	 *
	 * @param array $menu_links Registered My Account menu items.
	 *
	 * @return array
	 */
	public function woo_feed_add_view_feeds_tab_menu( $menu_links ) {
		$user = wp_get_current_user();
		if ( self::woo_feed_is_multi_vendor() && ! in_array( 'customer', $user->roles, true ) ) {
			$menu_links = array_slice( $menu_links, 0, 5, true ) + array( 'view-feeds' => 'Product Feed' ) + array_slice( $menu_links, 5, 1, true );
		}

		return $menu_links;
	}

	/**
	 * Register the `view-feeds` My Account endpoint.
	 *
	 * @return void
	 */
	public function woo_feed_add_endpoint_for_view_feeds_menu() {
		add_rewrite_endpoint( 'view-feeds', EP_PAGES );
	}

	/**
	 * Render the vendor's feed list under the `view-feeds` endpoint.
	 *
	 * @return void
	 */
	public function woo_feed_view_vendor_feeds_endpoint_add_content() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Feeds are stored as individual `wf_feed_*` options; enumerating them needs a LIKE lookup that no WP option API exposes. This renders a logged-in vendor's own account screen, so a cache layer would only serve stale feed lists.
		$result  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->options WHERE option_name LIKE %s", 'wf_feed_%' ), 'ARRAY_A' );
		$user_id = get_current_user_id();
		?>
		<div>
			<table class="table table-responsive">
				<thead>
				<tr>
					<th style="width: 20%;"><?php esc_html_e( 'Feed Name', 'woo-feed' ); ?></th>
					<th><?php esc_html_e( 'Feed Link', 'woo-feed' ); ?></th>
					<th style="width: 30%;"><?php esc_html_e( 'Actions', 'woo-feed' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result ) || ! is_array( $result ) ) { ?>
					<tr>
						<td colspan="3"
							style="text-align: center;"><?php esc_html_e( 'No Feed Available', 'woo-feed' ); ?></td>
					</tr>
					<?php
				} else {
					foreach ( $result as $feed ) {
						$info = maybe_unserialize( get_option( $feed['option_name'] ) );
						if ( isset( $info['feedrules']['vendors'] ) ) {
							// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- Vendor IDs arrive from the saved feed rules as form-posted strings while get_current_user_id() returns an int; a strict compare would stop matching every existing feed.
							if ( in_array( $user_id, $info['feedrules']['vendors'] ) ) {
								$fileName = $info['feedrules']['filename'];
								$fileURL  = $info['url'];
								?>
								<tr>
									<td><?php echo esc_html( $fileName ); ?></td>
									<td style="color: rgb(0, 135, 121); font-weight: bold;"><?php echo esc_html( $fileURL ); ?></td>
									<td><a href="<?php echo esc_url( $fileURL ); ?>" class="button button-primary"
											target="_blank"><?php esc_html_e( 'View/Download', 'woo-feed' ); ?></a>
									</td>
								</tr>
								<?php
							}
						}
					}
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Prepend the My Account rewrite rule so the endpoint resolves.
	 *
	 * @param \WP_Rewrite $wp_rewrite Rewrite API instance.
	 *
	 * @return array
	 */
	public function woo_feed_add_rewrite_rules_for_view_feeds_tab( $wp_rewrite ) {
		$feed_rules = array(
			'my-account/?$' => 'index.php?account-page=true',
		);

		$wp_rewrite->rules = $wp_rewrite->rules + $feed_rules;

		return $wp_rewrite->rules;
	}

	/**
	 * Read a CTX Feed transient cache entry.
	 *
	 * @param string $key Cache key, without the plugin prefix.
	 *
	 * @return mixed False when the key is empty or the transient is unset.
	 */
	public function woo_feed_get_cached_data( $key ) {
		if ( empty( $key ) ) {
			return false;
		}

		return get_transient( '__woo_feed_cache_' . $key );
	}

	/**
	 * Check any multi-vendor plugin installed or not
	 * Check if any of following multi-vendor plugin class exists
	 *
	 * @link https://wedevs.com/dokan/
	 * @link https://www.wcvendors.com/
	 * @link https://yithemes.com/themes/plugins/yith-woocommerce-multi-vendor/
	 * @link https://multivendorx.com/
	 * @link https://wordpress.org/plugins/wc-multivendor-marketplace/
	 * @return bool
	 */
	public static function woo_feed_is_multi_vendor() {
		return apply_filters(
			'woo_feed_is_multi_vendor',
			(
						class_exists( 'WeDevs_Dokan' ) ||
						class_exists( 'WC_Vendors' ) ||
						class_exists( 'YITH_Vendor' ) ||
						class_exists( 'MVX' ) ||
						class_exists( 'WCMp' ) ||
						class_exists( 'WCFMmp' )
				)
		);
	}
}
