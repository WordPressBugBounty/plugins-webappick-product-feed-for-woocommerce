<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @since      1.0.0
 *
 * @package    WooFeed
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'woo_feed_cleanup_logs' );
wp_clear_scheduled_hook( 'woo_feed_update' );

// V8 runs everything through Action Scheduler (group "ctxfeed"): recurring
// feed updates, batch chains, cache invalidation. Left behind they fail as
// "no callbacks registered" forever (CBT-720). Action Scheduler ships with
// WooCommerce and may not be loaded — guard every call.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'ctxfeed' );
}
// End of file uninstall.php.
