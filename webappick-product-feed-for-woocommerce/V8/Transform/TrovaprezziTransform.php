<?php
/**
 * TrovaprezziTransform — Applies Trovaprezzi-specific formatting rules.
 *
 * Trovaprezzi (Italian price comparison):
 * - Category separator: comma instead of " > "
 * - XML wrappers: itemsWrapper = "Products", itemWrapper = "Offer"
 *
 * Prices are NOT rewritten (V5 parity — V5 had no Trovaprezzi price
 * override). The `Price` and `ShippingCost` rows ship exactly what their
 * Output Type (Price, by default) and suffix produce, so both use the same
 * number format, as the Trovaprezzi technical requirements demand for
 * Shipping Cost. Until 8.0.38 a V8-only `transform_price()` rewrote
 * `ShippingCost` (and lowercase keys no template uses) to a comma decimal
 * while `Price` kept the dot — `1299.50` next to `4,90` (CBT-809 C4, owner
 * decision #11, CBT-811). Removed in 8.0.39.
 *
 * Availability ("disponibilità") is intentionally NOT rewritten. Per the
 * official Trovaprezzi feed spec the field is optional and treats ANY number
 * ≥ 2 as in-stock, so the old v8 mapping (in stock → "2", everything else →
 * "3") flagged out-of-stock products as in-stock (green icon). It also
 * clobbered whatever the user mapped. The resolved value now passes through
 * unchanged (V5 parity); users who want the icon map "disponibile"/
 * "non disponibile" or the numeric codes themselves.
 *
 * The end-of-record marker (`<endrecord>`) is handled by CSVTemplate
 * via the `ctxfeed_csv_row_suffix` filter, not in this transform.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @see        https://www.trovaprezzi.it
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trovaprezzi transform.
 *
 * @since 8.0.0
 */
class TrovaprezziTransform implements TransformInterface {

	/**
	 * Transform product data for Trovaprezzi compliance.
	 *
	 * No-ops for non-Trovaprezzi providers.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		if ( 'trovaprezzi' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_category( $product_data );

		return $product_data;
	}

	/**
	 * Convert category path separator to comma.
	 *
	 * V5 parity: Trovaprezzi uses comma instead of " > " for category hierarchy.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified data.
	 */
	private function transform_category( array $data ): array {
		$cat_keys = array(
			'product_type',
			'product_cat',
			'categories',
			'product_full_cat',
			'Categories',
		);

		foreach ( $cat_keys as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$data[ $key ] = str_replace( ' > ', ', ', $data[ $key ] );
			}
		}

		return $data;
	}
}
