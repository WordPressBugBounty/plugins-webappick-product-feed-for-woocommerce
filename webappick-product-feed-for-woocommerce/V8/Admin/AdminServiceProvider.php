<?php
/**
 * Admin Service Provider.
 *
 * Registers admin page, asset, and notice singletons in the DI container,
 * then wires WordPress hooks for menu registration, script enqueuing,
 * admin notices, and AJAX dismiss handling.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 * @implements ADM-FRD-4.1, ADM-FRD-4.2
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

/**
 * Class AdminServiceProvider
 *
 * Centralises admin hook wiring. Registers three singletons (admin.page,
 * admin.assets, admin.notices) and boots them by attaching to WordPress
 * admin hooks.
 *
 * @since 8.0.0
 */
class AdminServiceProvider extends ServiceProvider {

	/**
	 * Register admin services in the container.
	 *
	 * Phase 1: binds factories — no cross-module resolution here.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'admin.page',
			function () {
				return new AdminPage();
			}
		);

		$container->register(
			'admin.assets',
			function () {
				return new Assets();
			}
		);

		$container->register(
			'admin.notices',
			function () {
				return new Notices();
			}
		);

		$container->register(
			'admin.dashboard_widget',
			function () {
				return new DashboardWidget();
			}
		);

		$container->register(
			'admin.plugin_suggestions',
			function () {
				return new PluginSuggestions();
			}
		);
	}

	/**
	 * Boot admin services by wiring WordPress hooks.
	 *
	 * Phase 3: resolves singletons and attaches callbacks to admin_menu,
	 * admin_enqueue_scripts, admin_notices, and the AJAX dismiss handler.
	 * Fires ctxfeed_admin_registered action after all hooks are wired.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container DI container.
	 * @return void
	 *
	 * @hook action admin_menu              → AdminPage::register_pages()
	 * @hook action admin_enqueue_scripts   → Assets::enqueue()
	 * @hook action admin_notices           → Notices::display_notices()
	 * @hook action wp_ajax_ctxfeed_dismiss_notice → Notices::handle_dismiss()
	 * @hook action wp_dashboard_setup             → DashboardWidget::register_widget() (admin only)
	 * @hook filter install_plugins_table_api_args_featured → PluginSuggestions::featured_plugins_tab() (admin only)
	 * @hook action ctxfeed_admin_registered (fired)
	 */
	public function boot( Container $container ): void {
		$page    = $container->resolve( 'admin.page' );
		$assets  = $container->resolve( 'admin.assets' );
		$notices = $container->resolve( 'admin.notices' );

		add_action( 'admin_menu', array( $page, 'register_pages' ) );
		add_action( 'admin_head', array( $page, 'print_menu_icon_css' ) ); // Custom menu logo (global).
		add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );
		add_action( 'admin_notices', array( $notices, 'display_notices' ) );
		add_action( 'wp_ajax_ctxfeed_dismiss_notice', array( $notices, 'handle_dismiss' ) );

		// On the CTX Feed screen, silence the wp-admin notice area entirely —
		// third-party, core, and our own notices are surfaced inside the React
		// app's notice slider instead. `in_admin_header` runs after all notices
		// are attached but before any output, so registration order is moot.
		add_action( 'in_admin_header', array( $notices, 'suppress_foreign_notices' ), 1000 );

		// Premium + Our Plugins marketing menu pages (ported from V5). Self-
		// contained under V8/Admin/ (views/css/js/images); registers its own
		// submenus, asset enqueue, and plugin-install AJAX handler.
		( new UpsellPages() )->register();

		// Dashboard widget ("Latest News from WebAppick Blog") and the
		// Featured-tab plugin suggestions, both ported from V5 where they had
		// become unreachable. Their surfaces exist only inside wp-admin, so
		// they are wired for admin requests only and add nothing to a
		// front-end page load.
		if ( is_admin() ) {
			$container->resolve( 'admin.dashboard_widget' )->register();
			$container->resolve( 'admin.plugin_suggestions' )->register();
		}

		/**
		 * Fires after all admin classes have been wired to WordPress hooks.
		 *
		 * @since 8.0.0
		 *
		 * @param AdminPage $page    Admin page instance.
		 * @param Assets    $assets  Assets instance.
		 * @param Notices   $notices Notices instance.
		 */
		do_action( 'ctxfeed_admin_registered', $page, $assets, $notices );
	}
}
