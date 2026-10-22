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
	 * WCCS services this shim built for the running batch (CBT-836), set
	 * back to null by the restore.
	 *
	 * @since 8.0.40
	 * @var string[]
	 */
	private $created_services = array();

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
	 * Mirrors the plugin's own feed compatibility (Easy WooCommerce Discounts
	 * 9.x `WCCS_Compatibility_Product_Feed_Pro`): WCCS reads its services
	 * through `WCCS()->container()` — `WCCS()->WCCS_Product_Price_Replace` is
	 * a magic `__get()` with no `__isset()`, so the old `isset()` guard was
	 * always false and the discount never reached a feed (CBT-836). Outside
	 * the storefront WCCS builds neither its `pricing` service nor the
	 * `WCCS_Product_Price_Cache` its price hooks read, and its
	 * `should_replace()` refuses every non-frontend request; the shim builds
	 * the missing services for the batch and answers
	 * `wccs_product_price_replace_should_replace` for it.
	 *
	 * @return void
	 */
	public function apply_discount() {
		// Already enabled by this shim (a before-hook without its after-hook).
		if ( null !== $this->enabled_replace ) {
			return;
		}

		// Guard against a breaking plugin update: skip silently if the plugin's
		// container / price-replace API is not available as this shim expects.
		$services = self::services();
		if ( null === $services ) {
			return;
		}
		$replace = $services->get( 'WCCS_Product_Price_Replace' );
		if ( ! is_object( $replace ) || ! method_exists( $replace, 'set_should_replace_prices' ) || ! method_exists( $replace, 'set_change_regular_price' ) || ! method_exists( $replace, 'enable_hooks' ) ) {
			return;
		}

		// Enabled by WCCS itself for this request: leave WCCS's own state
		// alone, so the after-hook never turns off hooks WCCS switched on
		// (CBT-813).
		if ( false !== has_filter( 'woocommerce_product_get_price', array( $replace, 'replace_price' ) ) ) {
			return;
		}

		if ( ! $this->prepare_services( $services ) ) {
			return;
		}

		$this->previous_should_replace = property_exists( $replace, 'should_replace_prices' ) ? $replace->should_replace_prices : false;
		add_filter( 'wccs_product_price_replace_should_replace', array( $this, 'should_replace' ), 10, 4 );
		$replace->set_should_replace_prices( true )->set_change_regular_price( false )->enable_hooks();
		$this->enabled_replace = $replace;
		$this->arm_batch_end_fallback();
	}

	/**
	 * Undo apply_discount() after the batch (after_woo_feed_generate_batch_data,
	 * CBT-813): remove the price hooks this shim enabled — disable_hooks()
	 * first, while WCCS's flag is still on (WCCS skips the removal otherwise)
	 * — then restore the flag, drop the batch-only should-replace answer and
	 * the services the shim built. Hooks WCCS enabled itself are never
	 * touched.
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
		remove_filter( 'wccs_product_price_replace_should_replace', array( $this, 'should_replace' ), 10 );

		$services = self::services();
		if ( null !== $services ) {
			foreach ( $this->created_services as $key ) {
				$services->set( $key, null );
			}
		}
		$this->created_services = array();
	}

	/**
	 * Answer WCCS's `wccs_product_price_replace_should_replace` during a feed
	 * batch: WCCS says no to every request that is not the storefront (cron,
	 * Action Scheduler, the admin runner). Same rules as WCCS's own storefront
	 * check and its feed compatibility: never a variable parent (its range
	 * comes from the variations), never an empty price, never a cart item.
	 *
	 * @since 8.0.40 CBT-836.
	 *
	 * @param mixed  $value      WCCS's answer.
	 * @param mixed  $price      The price being read.
	 * @param mixed  $product    The product.
	 * @param string $price_type price | sale_price | regular_price.
	 *
	 * @return mixed
	 */
	public function should_replace( $value, $price, $product, $price_type = '' ) {
		if ( $value || null === $this->enabled_replace || ! $product instanceof \WC_Product ) {
			return $value;
		}
		if ( $product->is_type( 'variable' ) ) {
			return false;
		}
		if ( '' === $price && 'price' === $price_type ) {
			return false;
		}
		$wccs = WCCS();
		if ( is_object( $wccs ) && isset( $wccs->custom_props ) && is_object( $wccs->custom_props ) && method_exists( $wccs->custom_props, 'get_prop' )
			&& null !== $wccs->custom_props->get_prop( $product, 'wccs_is_cart_item' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * WCCS's service container, or null when the plugin (or the API this shim
	 * relies on) is missing.
	 *
	 * @since 8.0.40 CBT-836.
	 *
	 * @return object|null
	 */
	private static function services() {
		if ( ! function_exists( 'WCCS' ) ) {
			return null;
		}
		$wccs = WCCS();
		if ( ! is_object( $wccs ) || ! is_callable( array( $wccs, 'container' ) ) ) {
			return null;
		}
		$services = $wccs->container();

		return is_object( $services ) && method_exists( $services, 'get' ) && method_exists( $services, 'set' ) ? $services : null;
	}

	/**
	 * Build the `pricing` service and the price cache WCCS's price hooks read
	 * when WCCS did not (it builds them on the storefront only). False when
	 * they cannot be built — enabling the hooks without them would make WCCS
	 * read a price cache with no pricing rules.
	 *
	 * @since 8.0.40 CBT-836.
	 *
	 * @param object $services WCCS's service container.
	 *
	 * @return bool
	 */
	private function prepare_services( $services ) {
		$this->created_services = array();

		$pricing = $services->get( 'pricing', array(), null, false );
		if ( ! is_object( $pricing ) ) {
			if ( ! class_exists( 'WCCS_Pricing' ) || ! class_exists( 'WCCS_Conditions_Provider' ) || ! is_callable( array( 'WCCS_Conditions_Provider', 'get_pricings' ) ) ) {
				return false;
			}
			$pricing = new \WCCS_Pricing( \WCCS_Conditions_Provider::get_pricings( array( 'status' => 1 ) ) );
			$services->set( 'pricing', $pricing );
			$this->created_services[] = 'pricing';
		}

		$cache = $services->get( 'WCCS_Product_Price_Cache', array(), null, false );
		if ( ! is_object( $cache ) ) {
			if ( ! class_exists( 'WCCS_Product_Price_Cache' ) ) {
				foreach ( $this->created_services as $key ) {
					$services->set( $key, null );
				}
				$this->created_services = array();
				return false;
			}
			$services->set( 'WCCS_Product_Price_Cache', new \WCCS_Product_Price_Cache( $pricing ) );
			$this->created_services[] = 'WCCS_Product_Price_Cache';
		}

		return true;
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
