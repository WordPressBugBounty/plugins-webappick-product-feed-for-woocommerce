<?php
/**
 * PriceStage — the fixed order of the Legacy Bridge price pipeline.
 *
 * Several compat shims change the same feed price on
 * `woo_feed_filter_product_{regular_price,price,sale_price}` (+ `_with_tax`
 * twins). Their order decides the result, so it is fixed here (CBT-795):
 *
 *   1. MODEL    — bundle / composite / grouped shims compute the product's
 *                 own base price from its items (Pro).
 *   2. DISCOUNT — discount / dynamic-pricing shims (free).
 *   3. CURRENCY — currency switchers convert the final amount (Pro); always
 *                 last, so nothing replaces a converted price afterwards.
 *
 * Before, the priorities were ad hoc (9 / 10 / 99 / 999 / 99999999): a
 * discount recomputed after the conversion shipped a store-currency number
 * as the feed currency, and a currency shim ahead of a discount judged a
 * discounted price as "already converted" (CBT-773, CBT-791, CBT-793).
 *
 * @package CTXFeed\Compat\Support
 * @since   8.0.36
 */

namespace CTXFeed\Compat\Support;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Price pipeline stage priorities.
 *
 * @since 8.0.36
 */
final class PriceStage {

	/**
	 * Bundle / composite / grouped price models.
	 */
	const MODEL = 10;

	/**
	 * Discount and dynamic-pricing plugins.
	 */
	const DISCOUNT = 50;

	/**
	 * Currency conversion — after every other price change.
	 */
	const CURRENCY = 100;

	/**
	 * Price hooks of the pipeline (the bridge PriceResolver fires).
	 *
	 * @var string[]
	 */
	const HOOKS = array(
		'woo_feed_filter_product_regular_price',
		'woo_feed_filter_product_price',
		'woo_feed_filter_product_sale_price',
		'woo_feed_filter_product_regular_price_with_tax',
		'woo_feed_filter_product_price_with_tax',
		'woo_feed_filter_product_sale_price_with_tax',
	);
}
