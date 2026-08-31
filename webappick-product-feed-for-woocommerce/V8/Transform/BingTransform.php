<?php
/**
 * BingTransform — Applies Microsoft Bing/Merchant Center formatting rules.
 *
 * - Availability date: ISO 8601 format
 * - Availability: underscore format (in_stock, out_of_stock)
 * - Shipping: simplified format for CSV/TXT (country:service:price, no region)
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bing transform.
 *
 * @since 8.0.0
 */
class BingTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	/**
	 * Transform product data for Bing compliance.
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

		if ( 'bing' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_availability_date( $product_data );
		$product_data = $this->transform_shipping( $product_data, $config );

		// MSC money format "<number> <ISO-4217>" (e.g. "34.00 USD") —
		// same as Google. PriceResolver emits bare numbers; the channel
		// owns the format (V5 appended the currency universally).
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Normalize availability to underscore format (same as Google).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_availability( array $data ): array {
		if ( empty( $data['availability'] ) ) {
			return $data;
		}

		$availability = strtolower( trim( $data['availability'] ) );
		$availability = str_replace( ' ', '_', $availability );

		$map = array(
			'instock'      => 'in_stock',
			'outofstock'   => 'out_of_stock',
			'in_stock'     => 'in_stock',
			'out_of_stock' => 'out_of_stock',
			'preorder'     => 'preorder',
			'backorder'    => 'backorder',
		);

		$normalized = str_replace( '-', '_', $availability );

		if ( isset( $map[ $normalized ] ) ) {
			$availability = $map[ $normalized ];
		}

		$data['availability'] = $availability;

		return $data;
	}

	/**
	 * Convert availability date to ISO 8601.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_availability_date( array $data ): array {
		if ( empty( $data['availability_date'] ) ) {
			return $data;
		}

		$date = $data['availability_date'];

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $date ) ) {
			return $data;
		}

		$timestamp = strtotime( $date );

		if ( false !== $timestamp ) {
			$data['availability_date'] = gmdate( 'c', $timestamp );
		}

		return $data;
	}

	/**
	 * Simplify shipping format for Bing.
	 *
	 * Bing uses country:service:price (no region field).
	 * Strips region from composite shipping strings in CSV/TXT feeds.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_shipping( array $data, Config $config ): array {
		if ( empty( $data['shipping'] ) || is_array( $data['shipping'] ) ) {
			return $data;
		}

		// NB: the feedrules key is camelCase `feedType` — reading the
		// snake_case key returned the default forever, leaving this
		// whole reformat dead.
		$feed_type = strtolower( $config->get( 'feedType', 'xml' ) );

		// Only modify for CSV/TXT/TSV (string format). XML uses nested elements.
		if ( ! in_array( $feed_type, array( 'csv', 'txt', 'tsv' ), true ) ) {
			return $data;
		}

		// Parse composite shipping string: "country:region:service:price"
		// Convert to Bing format: "country:service:price".
		$shipping = $data['shipping'];
		$entries  = explode( ',', $shipping );
		$result   = array();

		foreach ( $entries as $entry ) {
			$parts = explode( ':', trim( $entry ) );

			if ( count( $parts ) >= 4 ) {
				// Remove region (index 1): country:service:price.
				$result[] = $parts[0] . ':' . $parts[2] . ':' . $parts[3];
			} else {
				$result[] = trim( $entry );
			}
		}

		$data['shipping'] = implode( ',', $result );

		return $data;
	}
}
