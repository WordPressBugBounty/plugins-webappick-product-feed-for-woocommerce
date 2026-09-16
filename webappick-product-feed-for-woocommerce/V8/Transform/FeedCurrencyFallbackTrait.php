<?php
/**
 * FeedCurrencyFallbackTrait — the ONLY channel-side currency rule (CBT-602).
 *
 * The price attribute is the bare number; the mapping row's configured
 * suffix is appended exactly as written by PrefixSuffix and is never
 * rewritten. This trait covers one gap the owner defined: when the row
 * suffix is EMPTY and the feed currency was actually PROVIDED — the Feed
 * Currency field exists only on stores where a multi-currency plugin
 * supplies the currency list — the feed currency is appended to a bare
 * price so the merchant's chosen currency still reaches the channel.
 *
 * Without a multi-currency plugin the stored feedCurrency is just the
 * auto-filled store currency (the save path never stores it empty for the
 * compat shims' sake), which the owner does not count as "provided": a
 * bare price then ships bare.
 *
 * @package CTXFeed\V8\Transform
 * @since   8.0.23
 */

namespace CTXFeed\V8\Transform;

defined( 'ABSPATH' ) || exit;

use CTXFeed\V8\Common\DropdownRegistry;
use CTXFeed\V8\Core\Config;

/**
 * Appends the provided feed currency to bare price values.
 */
trait FeedCurrencyFallbackTrait {

	/**
	 * Append the feed currency to each listed attribute whose value is a
	 * bare number — only when the feed currency was provided (see class
	 * doc). Values that already carry ANY suffix are never touched.
	 *
	 * @since 8.0.23
	 *
	 * @param array    $data   Product data.
	 * @param Config   $config Feed configuration.
	 * @param string[] $attrs  Money attribute keys.
	 * @return array Product data with the fallback applied.
	 */
	private function apply_feed_currency_fallback( array $data, Config $config, array $attrs ): array {
		$currency = $this->provided_feed_currency( $config );
		if ( '' === $currency ) {
			return $data;
		}

		foreach ( $attrs as $attr ) {
			if ( empty( $data[ $attr ] ) || ! is_scalar( $data[ $attr ] ) ) {
				continue;
			}

			$value = trim( (string) $data[ $attr ] );

			// Bare number (US "1,299.00" or EU "1 299,00" grouping) → append.
			if ( 1 === preg_match( '/^\d[\d.,\s]*$/', $value ) ) {
				$data[ $attr ] = $value . ' ' . $currency;
			}
		}

		return $data;
	}

	/**
	 * The feed currency, or '' unless a multi-currency plugin supplies the
	 * currency list (`ctxfeed_dropdown_currencies`) — the only case in
	 * which the Feed Currency field is shown and its value was chosen.
	 *
	 * @since 8.0.23
	 *
	 * @param Config $config Feed configuration.
	 * @return string ISO-4217 code or ''.
	 */
	private function provided_feed_currency( Config $config ): string {
		$available = DropdownRegistry::get_active_currencies();
		if ( empty( $available ) ) {
			return '';
		}

		foreach ( array( 'feed_currency', 'feedCurrency', 'currency' ) as $key ) {
			$currency = trim( (string) $config->get( $key, '' ) );
			if ( '' !== $currency ) {
				return $currency;
			}
		}

		return '';
	}
}
