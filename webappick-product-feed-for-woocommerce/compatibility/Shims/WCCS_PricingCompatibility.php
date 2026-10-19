<?php
/**
 * Compatibility class for WCCS_PricingCompatibility plugin
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCCS_PricingCompatibility
 *
 * PLUGIN: Easy woo-commerce discount plugin
 * URL: https://wordpress.org/plugins/easy-woocommerce-discounts/
 *
 * @package CTXFeed\Compat\Shims
 */
class WCCS_PricingCompatibility {

	/**
	 * The WCCS price-replace object whose hooks THIS shim enabled for the
	 * running batch; null when it enabled nothing (CBT-813).
	 *
	 * @since 8.0.39
	 * @var object|null
	 */
	private $enabled_replace = null;

	/**
	 * WCCS's `should_replace_prices` flag before this shim set it (CBT-813).
	 *
	 * @since 8.0.39
	 * @var mixed
	 */
	private $previous_should_replace = false;

	/**
	 * Hooks that turn the price hooks off again when a batch ends WITHOUT its
	 * after-hook (a throw between before_ and
	 * after_woo_feed_generate_batch_data), as hook => priority (CBT-813).
	 * Action Scheduler fires one of the first two after every action (cron,
	 * the browser runner); shutdown covers the synchronous Regenerate
	 * request. Armed only while this shim holds the hooks on, disarmed by the
	 * regular restore.
	 *
	 * @since 8.0.39
	 * @var array<string,int>
	 */
	const BATCH_END_FALLBACK_HOOKS = array(
		'action_scheduler_after_execute'    => 10,
		'action_scheduler_failed_execution' => 10,
		'shutdown'                          => 1,
	);

	/**
	 * WCCS_PricingCompatibility Constructor.
	 */
	public function __construct() {
		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'apply_discount' ) );
		// Turn WCCS's price hooks off again after every batch (CBT-813): they
		// used to stay on for the rest of the PHP process.
		add_action( 'after_woo_feed_generate_batch_data', array( $this, 'remove_discount' ) );
	}

	/**
	 * Apply discount for WCCS_PricingCompatibility plugin
	 *
	 * @return void
	 */
	public function apply_discount() {
		// Guard against a breaking plugin update: skip silently if the plugin's
		// price-replace API is not available as this shim expects.
		if ( ! function_exists( 'WCCS' ) ) {
			return;
		}
		$wccs = WCCS();
		if ( ! is_object( $wccs ) || ! isset( $wccs->WCCS_Product_Price_Replace ) ) {
			return;
		}
		$replace = $wccs->WCCS_Product_Price_Replace;
		if ( ! is_object( $replace ) || ! method_exists( $replace, 'set_should_replace_prices' ) || ! method_exists( $replace, 'set_change_regular_price' ) || ! method_exists( $replace, 'enable_hooks' ) ) {
			return;
		}

		// Already enabled — by this shim (a before-hook without its after-hook)
		// or by WCCS itself for this request: leave WCCS's own state alone, so
		// the after-hook never turns off hooks WCCS switched on (CBT-813).
		if ( null !== $this->enabled_replace || false !== has_filter( 'woocommerce_product_get_price', array( $replace, 'replace_price' ) ) ) {
			return;
		}

		$this->previous_should_replace = isset( $replace->should_replace_prices ) ? $replace->should_replace_prices : false;
		$replace->set_should_replace_prices( true )->set_change_regular_price( false )->enable_hooks();
		$this->enabled_replace = $replace;
		$this->arm_batch_end_fallback();
	}

	/**
	 * Undo apply_discount() after the batch (after_woo_feed_generate_batch_data,
	 * CBT-813): remove the price hooks this shim enabled — disable_hooks()
	 * first, while WCCS's flag is still on (WCCS skips the removal otherwise)
	 * — then restore the flag. Hooks WCCS enabled itself are never touched.
	 *
	 * @since 8.0.39
	 * @return void
	 */
	public function remove_discount() {
		$this->disarm_batch_end_fallback();
		if ( null === $this->enabled_replace ) {
			return;
		}
		$replace               = $this->enabled_replace;
		$this->enabled_replace = null;

		if ( method_exists( $replace, 'disable_hooks' ) ) {
			$replace->disable_hooks();
		}
		if ( method_exists( $replace, 'set_should_replace_prices' ) ) {
			$replace->set_should_replace_prices( $this->previous_should_replace );
		}
	}

	/**
	 * Batch-end fallback (BATCH_END_FALLBACK_HOOKS, CBT-813): a batch that
	 * threw before after_woo_feed_generate_batch_data still has the price
	 * hooks this shim enabled turned off. No-op once the regular restore ran.
	 *
	 * @since 8.0.39
	 * @return void
	 */
	public function restore_unfinished_batch() {
		$this->remove_discount();
	}

	/**
	 * Register restore_unfinished_batch() on the batch-end fallback hooks.
	 *
	 * @since 8.0.39
	 * @return void
	 */
	private function arm_batch_end_fallback() {
		foreach ( self::BATCH_END_FALLBACK_HOOKS as $hook => $priority ) {
			add_action( $hook, array( $this, 'restore_unfinished_batch' ), $priority, 0 );
		}
	}

	/**
	 * Remove restore_unfinished_batch() from the batch-end fallback hooks.
	 *
	 * @since 8.0.39
	 * @return void
	 */
	private function disarm_batch_end_fallback() {
		foreach ( self::BATCH_END_FALLBACK_HOOKS as $hook => $priority ) {
			remove_action( $hook, array( $this, 'restore_unfinished_batch' ), $priority );
		}
	}
}
