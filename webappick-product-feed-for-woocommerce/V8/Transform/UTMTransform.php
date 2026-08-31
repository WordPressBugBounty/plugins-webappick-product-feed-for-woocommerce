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
 * stripped before re-appending, and the whole pass respects the
 * enable/disable toggle + filters.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-9.1, XFRM-FRD-9.2, XFRM-FRD-9.3, XFRM-FRD-9.4
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

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

		foreach ( $url_attributes as $attr ) {
			if ( ! empty( $product_data[ $attr ] ) && filter_var( $product_data[ $attr ], FILTER_VALIDATE_URL ) ) {
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
	 * Append UTM parameters to a URL, preserving existing query params.
	 *
	 * Removes any existing UTM params first to avoid duplication.
	 * Filters out empty values before appending.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.4
	 *
	 * @param string $url        Original product URL.
	 * @param array  $utm_params UTM parameters to append.
	 *
	 * @return string URL with UTM parameters appended.
	 */
	private function append_utm_to_url( string $url, array $utm_params ): string {
		// Remove any existing UTM params to avoid duplication.
		$url = remove_query_arg( array_keys( $utm_params ), $url );

		// URL-encode every value so reserved characters stay INSIDE the query
		// string. This is NOT optional cosmetics: WordPress add_query_arg()
		// builds the query via build_query() → _http_build_query( …, false ),
		// i.e. with url-encoding DISABLED, so it appends whatever bytes we give
		// it verbatim. A raw '#' in e.g. utm_term therefore reached the feed as
		// a literal '#', which every URL parser reads as the start of a fragment
		// — silently dropping utm_term's tail and every parameter after it
		// (BUG-0036); a raw '&' split one value into a bogus extra parameter.
		// rawurlencode() escapes '#'→%23, '&'→%26, etc.; the one place we then
		// diverge from strict RFC 3986 is restoring space to '+' (rawurlencode
		// gives %20) to preserve V5's behaviour (CommonHelper::add_utm_parameter
		// used str_replace ' '→'+'). Because add_query_arg() does not re-encode,
		// there is no double-encoding. Falsy values are then dropped (default
		// array_filter, so '' and a literal '0' both go) before appending.
		$utm_params = array_map(
			static function ( $value ) {
				return str_replace( '%20', '+', rawurlencode( (string) $value ) );
			},
			$utm_params
		);
		$utm_params = array_filter( $utm_params );

		return add_query_arg( $utm_params, $url );
	}
}
