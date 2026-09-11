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

			// Value carries a trailing ISO-4217 code (3 uppercase letters)
			// after a digit — WITH or WITHOUT the space. Two legacy shapes
			// land here: a ' USD' label frozen into the price row before the
			// merchant switched the feed currency (rewrite the label — the
			// VALUE is converted upstream by the currency-switcher compat),
			// and CBT-575's glued "35.90RON" from configs whose currency
			// suffix was trimmed by the pre-PROD-FRD-10.12 sanitizer —
			// OpenAI rejects the missing space, and Google feeds showed the
			// same shape ("62.25AUD", #69086). Either way the money format
			// every channel spec mandates is "<number> <CODE>": rejoin with
			// exactly one space, reconciling a stale label to the feed
			// currency. Requiring a digit before the code keeps deliberate
			// non-currency suffixes ("/kg") and plain text untouched;
			// already-correct values rewrite to themselves (idempotent).
			if ( 1 === preg_match( '/^(.*\d)\s*([A-Z]{3})$/', $value, $matches ) ) {
				$code          = ( 0 === strcasecmp( $matches[2], $currency ) ) ? $matches[2] : $currency;
				$data[ $attr ] = $matches[1] . ' ' . $code;
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
