<?php
/**
 * CustomFieldServiceProvider — Registers custom field services.
 *
 * Binds the CustomFieldRegistrar and TaxonomyRegistrar to the DI container,
 * then initializes them during boot to hook into WooCommerce product pages
 * and WordPress init for taxonomy registration.
 *
 * @package    CTXFeed
 * @subpackage V8/CustomFields
 * @since      8.0.0
 */

namespace CTXFeed\V8\CustomFields;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom fields service provider.
 *
 * @since 8.0.0
 */
class CustomFieldServiceProvider extends ServiceProvider {

	/**
	 * Register custom field services in the container.
	 *
	 * Phase 1: Only bind factories — no resolution.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'custom_fields.registrar',
			function () {
				return new CustomFieldRegistrar();
			} 
		);

		$container->register(
			'custom_fields.taxonomy',
			function () {
				return new TaxonomyRegistrar();
			} 
		);

		$container->register(
			'custom_fields.installer',
			function () {
				return new CustomFieldInstaller();
			} 
		);
	}

	/**
	 * Boot the custom fields module.
	 *
	 * Phase 3: Resolve services and initialize hooks.
	 * The TaxonomyRegistrar hooks into 'init' at priority 5 for early
	 * taxonomy registration, while CustomFieldRegistrar hooks into
	 * WooCommerce product edit page actions.
	 *
	 * @since 8.0.0
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Seed `woo_feed_settings` with default custom-field toggles BEFORE
		// the registrar reads the option. Idempotent: only fills missing
		// keys, never overwrites user preferences. PROD-FRD-10.3.
		//
		// Deferred to `init`: the defaults carry translated labels, and
		// translating while the container boots on `plugins_loaded` makes
		// WordPress ≥ 6.7 log the "_load_textdomain_just_in_time called
		// incorrectly" notice on every page load (wp.org report). Every
		// reader of the option (admin product screens, REST) runs after
		// `init`, so priority 5 seeding stays "before the registrar reads".
		/** @var CustomFieldInstaller $installer */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$installer = $container->resolve( 'custom_fields.installer' );
		if ( did_action( 'init' ) ) {
			$installer->seed_defaults();
		} else {
			add_action( 'init', array( $installer, 'seed_defaults' ), 5 );
		}

		/** @var TaxonomyRegistrar $taxonomy */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$taxonomy = $container->resolve( 'custom_fields.taxonomy' );
		$taxonomy->init();

		/** @var CustomFieldRegistrar $registrar */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline @var type annotation for IDE/static analysis, not a documentation block.
		$registrar = $container->resolve( 'custom_fields.registrar' );
		$registrar->init();
	}
}
