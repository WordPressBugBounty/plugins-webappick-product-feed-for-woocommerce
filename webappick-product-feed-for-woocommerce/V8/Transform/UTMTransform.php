<?php
/**
 * UTMTransform — Appends the user's configured UTM tracking parameters to
 * product click-destination URLs in feed output.
 *
 * The UTM values come solely from the feed's `campaign_parameters` config
 * (the Campaign URL card) — nothing is auto-generated from the channel,
 * feed name or product ID. Following V5 (CommonHelper::add_utm_parameter),
 * parameters are appended only when Source, Medium and Campaign are all
 * filled; a partial/empty configuration leaves every URL untouched. The
 * canonical URL is intentionally never tagged. Existing UTM params are
 * replaced in place (a stale or unconfigured one is dropped), every other
 * byte of the URL is kept (Url::with_query_args, CBT-818), and the whole
 * pass respects the enable/disable toggle + filters.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-9.1, XFRM-FRD-9.2, XFRM-FRD-9.3, XFRM-FRD-9.4
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Utility\Url;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UTM tracking transform.
 *
 * @since 8.0.0
 */
class UTMTransform implements TransformInterface {

	/**
	 * URL attributes that receive UTM parameters.
	 *
	 * `canonical_link` is deliberately EXCLUDED: a canonical URL must point
	 * at the clean, authoritative product page, so appending tracking params
	 * to it is an SEO anti-pattern (and V5 never did — it applied UTMs only
	 * to the click-destination link). These are all click-through
	 * destinations where campaign tracking belongs.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	private static $url_attributes = array(
		'link',
		'url',
		'product_url',
		'mobile_link',
		'ads_redirect',
	);

	/**
	 * Append the user-configured UTM tracking parameters to product URLs.
	 *
	 * The UTM values come ENTIRELY from the feed's Campaign URL config
	 * (`campaign_parameters`); nothing is invented from the channel or feed
	 * name. Following V5 (CommonHelper::add_utm_parameter), parameters are
	 * appended only when Source, Medium and Campaign are all filled — a
	 * partial/empty configuration leaves every URL untouched. The Campaign
	 * URL card pre-fills sensible defaults for new feeds so the user still
	 * gets tracking out of the box, but always sees and can remove them.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.2, XFRM-FRD-9.3
	 * @hook ctxfeed_utm_params          Filter to override UTM params.
	 * @hook ctxfeed_utm_url_attributes  Filter to override URL attribute list.
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration containing channel and feed info.
	 *
	 * @return array Product data with UTM-appended URLs.
	 */
	public function transform( array $product_data, Config $config ): array {
		if ( ! $this->is_enabled( $config ) ) {
			return $product_data;
		}

		$utm_params = $this->build_utm_params( $config );

		/**
		 * Filter UTM parameters before appending to URLs.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $utm_params   UTM parameter key-value pairs.
		 * @param array  $product_data Resolved product attributes.
		 * @param Config $config       Feed configuration.
		 */
		$utm_params = apply_filters( 'ctxfeed_utm_params', $utm_params, $product_data, $config );

		// V5 parity (CommonHelper::add_utm_parameter): UTM parameters are
		// appended ONLY when the user has filled Source, Medium AND Campaign.
		// A partial or empty Campaign URL configuration adds NOTHING — the
		// plugin must never invent utm_source/medium/campaign from the
		// channel or feed name behind the user's back (that silent behaviour
		// contradicted the Campaign URL card, which promises "no parameters
		// are added until Source, Medium and Campaign are filled"). Users who
		// want tracking see the pre-filled, editable values in that card.
		if (
			empty( $utm_params['utm_source'] )
			|| empty( $utm_params['utm_medium'] )
			|| empty( $utm_params['utm_campaign'] )
		) {
			return $product_data;
		}

		/**
		 * Filter the list of URL attributes that receive UTM params.
		 *
		 * @since 8.0.0
		 *
		 * @param string[] $url_attributes Attribute names to process.
		 * @param Config   $config         Feed configuration.
		 */
		$url_attributes = apply_filters( 'ctxfeed_utm_url_attributes', self::$url_attributes, $config );

		// Url::is_http_url(), not FILTER_VALIDATE_URL: the filter rejects
		// every byte >= 0x80 and an '_' in the host, so a store with a raw
		// UTF-8 product base (/товар/), an IDN host or an underscore host
		// silently lost the UTMs it configured (CBT-818, a V5 regression).
		foreach ( $url_attributes as $attr ) {
			if ( ! empty( $product_data[ $attr ] ) && Url::is_http_url( $product_data[ $attr ] ) ) {
				$product_data[ $attr ] = $this->append_utm_to_url( $product_data[ $attr ], $utm_params );
			}
		}

		return $product_data;
	}

	/**
	 * Check if UTM tracking is enabled for this feed.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 * @hook ctxfeed_utm_enabled Filter to toggle UTM tracking.
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return bool True if UTM tracking should be applied.
	 */
	private function is_enabled( Config $config ): bool {
		$enabled = $config->get( 'utm_enabled', true );

		/**
		 * Filter whether UTM tracking is enabled.
		 *
		 * @since 8.0.0
		 *
		 * @param bool   $enabled Whether UTM is enabled.
		 * @param Config $config  Feed configuration.
		 */
		return apply_filters( 'ctxfeed_utm_enabled', $enabled, $config );
	}

	/**
	 * Read the UTM parameters straight from the feed's Campaign URL config.
	 *
	 * Nothing is auto-generated: the returned values are exactly what the
	 * user entered in the Campaign URL card (persisted under the V5
	 * `campaign_parameters` feedrule). Missing keys resolve to empty strings.
	 * The transform() gate then decides — V5-parity — whether the set is
	 * complete enough (Source + Medium + Campaign) to append anything.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.3
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array Associative array of the 5 UTM parameters (user values).
	 */
	private function build_utm_params( Config $config ): array {
		$user_campaign = (array) $config->get( 'campaign_parameters', array() );

		$params = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			$params[ $key ] = isset( $user_campaign[ $key ] ) ? (string) $user_campaign[ $key ] : '';
		}

		return $params;
	}

	/**
	 * Append UTM parameters to a URL, preserving every other byte of it.
	 *
	 * Existing UTM params are replaced in place (a configured key) or dropped
	 * (a key whose value is empty). Empty values are not appended.
	 *
	 * @since 8.0.0
	 * @since 8.0.39 Url::with_query_args() instead of remove_query_arg() +
	 *               add_query_arg() (CBT-818).
	 * @implements XFRM-FRD-9.4
	 *
	 * @param string $url        Original product URL.
	 * @param array  $utm_params UTM parameters to append.
	 *
	 * @return string URL with UTM parameters appended.
	 */
	private function append_utm_to_url( string $url, array $utm_params ): string {
		// Falsy values are dropped (default array_filter, so '' and a literal
		// '0' both go — V5 parity); their keys are still removed from the URL
		// so a stale value never survives.
		$values = array_filter(
			array_map(
				static function ( $value ) {
					return (string) $value;
				},
				$utm_params
			)
		);

		// Url::with_query_args() encodes each value exactly once —
		// rawurlencode() with %20 restored to '+' (V5's space → '+') — so '#'
		// and '&' stay INSIDE the query (BUG-0036: a raw '#' started a
		// fragment and dropped every later parameter). Unlike
		// remove_query_arg()/add_query_arg() it never decodes the URL's
		// existing keys: the five WordPress passes turned WooCommerce's own
		// variation key `attribute_%25d1%2580…` into raw UTF-8 (CBT-818).
		return Url::with_query_args( $url, $values, array_keys( $utm_params ) );
	}
}
