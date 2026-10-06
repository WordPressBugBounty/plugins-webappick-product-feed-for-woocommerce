<?php
/**
 * ProPricing — CTX Feed Pro prices from webappick.com (CBT-688).
 *
 * The Premium page, the right-rail promo card and the "Upgrade to Pro"
 * buttons show the live Pro prices. They come from webappick.com's public
 * WooCommerce Store API — the same products the Buy buttons add to the
 * cart — so a price change or sale on webappick.com shows up here without
 * a plugin release.
 *
 * Nothing about the site or its users is sent: two anonymous GET requests
 * for six fixed product IDs, at most every 12 hours, only while an admin
 * has a CTX Feed screen that shows a price open. The last good answer is
 * kept for outages; before the first one, the prices bundled with this
 * release are used.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.31
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPricing
 *
 * @since 8.0.31
 */
class ProPricing {

	/**
	 * The webappick.com WooCommerce Store API products collection.
	 *
	 * @var string
	 */
	const API_URL = 'https://webappick.com/wp-json/wc/store/v1/products';

	/**
	 * Fresh copy (transient).
	 *
	 * @var string
	 */
	const CACHE_KEY = 'ctxfeed_pro_pricing';

	/**
	 * Last complete answer from webappick.com (option, not autoloaded).
	 *
	 * @var string
	 */
	const LAST_GOOD_OPTION = 'ctxfeed_pro_pricing_last_good';

	/**
	 * How long a successful answer is reused (12 hours).
	 *
	 * @var int
	 */
	const FRESH_TTL = 43200;

	/**
	 * How long to wait before retrying after a failed or partial answer (1 hour).
	 *
	 * @var int
	 */
	const RETRY_TTL = 3600;

	/**
	 * Seconds per request.
	 *
	 * @var int
	 */
	const TIMEOUT = 5;

	/**
	 * The webappick.com product IDs per licence size: the yearly subscription
	 * variation and the lifetime product (the Premium page's Buy buttons).
	 *
	 * @var array<string,array{year:int,life:int}>
	 */
	const PRODUCTS = array(
		'single' => array(
			'year' => 45660,
			'life' => 106128,
		),
		'five'   => array(
			'year' => 45659,
			'life' => 106132,
		),
		'ten'    => array(
			'year' => 45658,
			'life' => 106133,
		),
	);

	/**
	 * Prices bundled with this release (webappick.com, 2026-09-30): used until
	 * webappick.com has answered once. [ price, regular price ].
	 *
	 * @var array<string,array<string,array{0:float,1:float}>>
	 */
	const BUILTIN = array(
		'single' => array(
			'year' => array( 119, 119 ),
			'life' => array( 599, 749 ),
		),
		'five'   => array(
			'year' => array( 199, 199 ),
			'life' => array( 879, 1099 ),
		),
		'ten'    => array(
			'year' => array( 229, 229 ),
			'life' => array( 1279, 1599 ),
		),
	);

	/**
	 * Current prices, refreshing from webappick.com when the fresh copy has
	 * expired (the REST route, GET /ctxfeed/v8/pricing).
	 *
	 * @since 8.0.31
	 *
	 * @return array Pricing payload (see payload()).
	 */
	public static function get(): array {
		$fresh = get_transient( self::CACHE_KEY );
		if ( self::valid( $fresh ) ) {
			return $fresh;
		}

		$known = self::snapshot();
		$live  = self::fetch();

		if ( null !== $live && count( $live['prices'] ) === 2 * count( self::PRODUCTS ) ) {
			$payload = self::payload( 'live', $live['prices'], $live['currency'] );
			update_option( self::LAST_GOOD_OPTION, $payload, false );
			set_transient( self::CACHE_KEY, $payload, self::FRESH_TTL );

			return $payload;
		}

		// Failed or partial answer: keep what is known, overlay any prices
		// that did arrive, and retry in an hour instead of on every request.
		$payload = $known;
		if ( null !== $live && $live['currency']['code'] === $known['currency']['code'] ) {
			foreach ( self::PRODUCTS as $tier => $ids ) {
				foreach ( $ids as $mode => $id ) {
					if ( isset( $live['prices'][ $id ] ) ) {
						$payload['tiers'][ $tier ][ $mode ] = $live['prices'][ $id ];
					}
				}
			}
		}
		set_transient( self::CACHE_KEY, $payload, self::RETRY_TTL );

		return $payload;
	}

	/**
	 * Prices without contacting webappick.com: the fresh copy, else the last
	 * good answer, else the bundled prices. Used for the admin boot data so
	 * pages render prices instantly.
	 *
	 * @since 8.0.31
	 *
	 * @return array Pricing payload (see payload()).
	 */
	public static function snapshot(): array {
		$fresh = get_transient( self::CACHE_KEY );
		if ( self::valid( $fresh ) ) {
			return $fresh;
		}

		$last = get_option( self::LAST_GOOD_OPTION, array() );
		if ( self::valid( $last ) ) {
			$last['source'] = 'cached';
			return $last;
		}

		return self::builtin();
	}

	/**
	 * The bundled prices as a payload.
	 *
	 * @since 8.0.31
	 *
	 * @return array
	 */
	public static function builtin(): array {
		$prices = array();
		foreach ( self::PRODUCTS as $tier => $ids ) {
			foreach ( $ids as $mode => $id ) {
				$prices[ $id ] = array(
					'price'   => (float) self::BUILTIN[ $tier ][ $mode ][0],
					'regular' => (float) self::BUILTIN[ $tier ][ $mode ][1],
				);
			}
		}

		return self::payload(
			'builtin',
			$prices,
			array(
				'code'   => 'USD',
				'prefix' => '$',
				'suffix' => '',
			)
		);
	}

	/**
	 * Ask webappick.com for the six products: lifetime products and yearly
	 * subscription variations need one request each.
	 *
	 * @since 8.0.31
	 *
	 * @return array{prices:array<int,array{price:float,regular:float}>,currency:array}|null
	 *         Null when neither request produced a price.
	 */
	public static function fetch(): ?array {
		$life = array();
		$year = array();
		foreach ( self::PRODUCTS as $ids ) {
			$life[] = $ids['life'];
			$year[] = $ids['year'];
		}

		$items = array_merge(
			self::request( array( 'include' => implode( ',', $life ) ) ),
			self::request(
				array(
					'include' => implode( ',', $year ),
					'type'    => 'variation',
				)
			)
		);

		$prices   = array();
		$currency = null;
		$wanted   = array_merge( $life, $year );
		foreach ( $items as $item ) {
			$parsed = self::parse( $item );
			if ( null === $parsed || ! in_array( $parsed['id'], $wanted, true ) ) {
				continue;
			}
			// All prices must share one currency; the first one sets it.
			if ( null === $currency ) {
				$currency = $parsed['currency'];
			} elseif ( $currency['code'] !== $parsed['currency']['code'] ) {
				continue;
			}
			$prices[ $parsed['id'] ] = array(
				'price'   => $parsed['price'],
				'regular' => $parsed['regular'],
			);
		}

		if ( empty( $prices ) ) {
			return null;
		}

		return array(
			'prices'   => $prices,
			'currency' => $currency,
		);
	}

	/**
	 * One GET to the Store API; [] on any failure.
	 *
	 * @param array<string,string> $args Query args.
	 *
	 * @return array[] Product objects.
	 */
	private static function request( array $args ): array {
		$args['per_page'] = '10';
		$response         = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() only exists on WordPress VIP; failure is handled below.
			add_query_arg( $args, self::API_URL ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? array_values( array_filter( $body, 'is_array' ) ) : array();
	}

	/**
	 * Read one Store API product: amounts arrive as integer strings in minor
	 * units ("11900" with currency_minor_unit 2 = 119.00).
	 *
	 * @since 8.0.31
	 *
	 * @param array $item Store API product.
	 *
	 * @return array{id:int,price:float,regular:float,currency:array{code:string,prefix:string,suffix:string}}|null
	 */
	public static function parse( array $item ): ?array {
		$prices = isset( $item['prices'] ) && is_array( $item['prices'] ) ? $item['prices'] : array();
		if ( empty( $item['id'] ) || ! isset( $prices['price'] ) || ! is_numeric( $prices['price'] ) ) {
			return null;
		}

		$minor   = isset( $prices['currency_minor_unit'] ) ? max( 0, min( 4, (int) $prices['currency_minor_unit'] ) ) : 0;
		$divisor = pow( 10, $minor );
		$price   = (float) $prices['price'] / $divisor;
		$regular = isset( $prices['regular_price'] ) && is_numeric( $prices['regular_price'] )
			? (float) $prices['regular_price'] / $divisor
			: $price;

		if ( $price <= 0 ) {
			return null;
		}

		return array(
			'id'       => (int) $item['id'],
			'price'    => $price,
			'regular'  => max( $price, $regular ),
			'currency' => array(
				'code'   => isset( $prices['currency_code'] ) ? (string) $prices['currency_code'] : '',
				'prefix' => isset( $prices['currency_prefix'] ) ? html_entity_decode( (string) $prices['currency_prefix'], ENT_QUOTES, 'UTF-8' ) : '',
				'suffix' => isset( $prices['currency_suffix'] ) ? html_entity_decode( (string) $prices['currency_suffix'], ENT_QUOTES, 'UTF-8' ) : '',
			),
		);
	}

	/**
	 * Build the payload the admin app reads.
	 *
	 * @param string $source   'live' | 'cached' | 'builtin'.
	 * @param array  $prices   Product ID => { price, regular }.
	 * @param array  $currency { code, prefix, suffix }.
	 *
	 * @return array{source:string,currency:array,tiers:array<string,array<string,array{price:float,regular:float}>>}
	 */
	private static function payload( string $source, array $prices, array $currency ): array {
		$tiers = array();
		foreach ( self::PRODUCTS as $tier => $ids ) {
			foreach ( $ids as $mode => $id ) {
				$tiers[ $tier ][ $mode ] = $prices[ $id ];
			}
		}

		return array(
			'source'   => $source,
			'currency' => $currency,
			'tiers'    => $tiers,
		);
	}

	/**
	 * Whether a stored payload has every tier and mode.
	 *
	 * @param mixed $payload Stored value.
	 *
	 * @return bool
	 */
	private static function valid( $payload ): bool {
		if ( ! is_array( $payload ) || empty( $payload['tiers'] ) || ! is_array( $payload['tiers'] ) || empty( $payload['currency'] ) ) {
			return false;
		}
		foreach ( self::PRODUCTS as $tier => $ids ) {
			foreach ( array_keys( $ids ) as $mode ) {
				if ( ! isset( $payload['tiers'][ $tier ][ $mode ]['price'] ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
