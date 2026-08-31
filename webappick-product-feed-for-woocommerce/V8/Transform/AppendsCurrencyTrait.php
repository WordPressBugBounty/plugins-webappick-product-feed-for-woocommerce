<?php
/**
 * AppendsCurrencyTrait — channel-side currency formatting for prices.
 *
 * PriceResolver returns bare numbers ("29.99") because currency
 * formatting is a per-channel requirement, not a product fact.
 * Channels that mandate the "<number> <ISO-4217>" money format
 * (Google, Facebook, Pinterest) mix this trait into their transform
 * and call append_currency() on the price attributes their spec
 * requires.
 *
 * A BARE NUMERIC value gets the feed currency appended. A value that
 * already ends in an ISO-4217 code has its label reconciled to the feed
 * currency: a matching code (or a non-currency suffix like "/kg") is left
 * alone — so the append stays idempotent and never double-formats — but a
 * DIFFERENT trailing code (a store-currency suffix frozen into the price
 * row at feed creation, before the merchant changed the feed currency) is
 * rewritten to the feed currency, so existing feeds render the right label
 * without a re-save.
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
 * Adds ISO-4217 currency suffixes to bare numeric price attributes.
 *
 * @since 8.0.0
 */
trait AppendsCurrencyTrait {

	/**
	 * Append the feed currency to each listed attribute when its value
	 * is a bare number.
	 *
	 * @since 8.0.0
	 *
	 * @param array    $data   Product data (merchant-attr keyed).
	 * @param Config   $config Feed configuration.
	 * @param string[] $attrs  Attribute keys to format (e.g. price, sale_price).
	 *
	 * @return array Product data with currency applied.
	 */
	private function append_currency( array $data, Config $config, array $attrs ): array {
		$currency = $this->resolve_feed_currency( $config );

		// No feed currency resolvable (and no store currency) → leave values as-is.
		if ( '' === $currency ) {
			return $data;
		}

		foreach ( $attrs as $attr ) {
			if ( empty( $data[ $attr ] ) || ! is_scalar( $data[ $attr ] ) ) {
				continue;
			}

			$value = trim( (string) $data[ $attr ] );

			// Bare numbers: digits with optional decimal/thousand separators
			// (both "1,299.00" and "1 299,00" styles). Append the feed currency.
			if ( 1 === preg_match( '/^\d[\d.,\s]*$/', $value ) ) {
				$data[ $attr ] = $value . ' ' . $currency;
				continue;
			}

			// Value already carries a trailing ISO-4217 code (3 uppercase
			// letters) that DIFFERS from the feed currency — e.g. a ' USD'
			// suffix baked into the price row when the feed was created, before
			// the merchant switched the feed currency to EUR. The price VALUE is
			// converted upstream by the currency-switcher compat; only the frozen
			// label is stale. Rewrite the label to the feed currency so existing
			// feeds render correctly WITHOUT a re-save (V5 parity: the price label
			// is always the feed currency). A matching code, or a non-currency
			// suffix like "/kg", is left untouched — this stays idempotent and
			// never double-formats or clobbers a deliberate unit.
			if ( 1 === preg_match( '/^(.*\S)\s+([A-Z]{3})$/', $value, $matches )
				&& 0 !== strcasecmp( $matches[2], $currency ) ) {
				$data[ $attr ] = $matches[1] . ' ' . $currency;
			}
		}

		return $data;
	}

	/**
	 * Resolve the feed's currency code with V5-compatible key fallbacks.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 * @return string ISO 4217 code (e.g. "USD").
	 */
	private function resolve_feed_currency( Config $config ): string {
		foreach ( array( 'feed_currency', 'feedCurrency', 'currency' ) as $key ) {
			$currency = (string) $config->get( $key, '' );
			if ( '' !== $currency ) {
				return $currency;
			}
		}

		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}
}
