<?php
/**
 * FeedWrapper — canonical per-channel XML item/items wrapper (V5 parity).
 *
 * V8 renders every XML product inside the generic `<product>` element unless
 * the feedrules say otherwise, and feed creation never sets a channel-specific
 * value — so a Google feed emitted `<product>` where Merchant Center requires
 * `<item>`. V5 avoided this by forcing the wrapper per channel at generation:
 *
 *   - via its Structure classes (V5/Structure/*): GoogleStructure,
 *     FacebookStructure, PinterestStructure, TiktokStructure → `<item>`;
 *     GooglereviewStructure → `<review>` inside `<reviews>`;
 *   - via `woo_feed_filter_parsed_rules()` (helper.php): criteo & daisycon →
 *     `<item>` in `<channel>`, wine_searcher → `<row>` in `<product-list>`,
 *     trovaprezzi → `<Offer>` in `<Products>`, zbozi.cz & heureka.sk →
 *     `<SHOPITEM>`.
 *
 * This restores that map. Channels NOT listed here (the generic
 * `<products>`-rooted merchants, Custom, Custom Template 2, …) return null and
 * keep whatever wrapper the feedrules carry — exactly V5's CustomStructure
 * fallback. Most listed channels ship an XML template file (google.txt, …) that
 * supplies the outer `<channel>` header/footer, so their `items` wrapper here is
 * only the generic-fallback value; the `item` wrapper is what always renders per
 * product (XMLTemplate::render_row).
 *
 * @package    CTXFeed
 * @subpackage V8/Channel
 * @since      8.0.0
 * @implements TMPL-FRD-4.2
 */

namespace CTXFeed\V8\Channel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the canonical XML wrapper for a channel provider.
 *
 * @since 8.0.0
 */
class FeedWrapper {

	/**
	 * Exact provider slug → [ item wrapper, items wrapper ].
	 *
	 * @since 8.0.0
	 * @var array<string,array{0:string,1:string}>
	 */
	private const EXACT = array(
		// Google Shopping RSS 2.0 `<item>` family + every Google-spec channel
		// (social, marketplace, AI). These all wrap each product in `<item>`;
		// only the legacy `<products>`-template merchants and Custom templates
		// keep the generic `<product>`.
		'google'                 => array( 'item', 'items' ),
		'google_local'           => array( 'item', 'items' ),
		'google_local_inventory' => array( 'item', 'items' ),
		'google_shopping_action' => array( 'item', 'items' ),
		'facebook'               => array( 'item', 'items' ),
		'pinterest'              => array( 'item', 'items' ),
		'pinterest_rss'          => array( 'item', 'items' ),
		'tiktok'                 => array( 'item', 'items' ),
		'snapchat'               => array( 'item', 'items' ),
		'perplexity'             => array( 'item', 'items' ),
		'chatgpt'                => array( 'item', 'items' ),
		'reddit'                 => array( 'item', 'items' ),
		'x'                      => array( 'item', 'items' ),
		'bing'                   => array( 'item', 'items' ),
		'idealo'                 => array( 'item', 'items' ),
		// Skroutz XML spec (developer.skroutz.gr): <mywebstore><products>
		// <product>…</product></products> — item element is <product>,
		// matching the <products> container in skroutz.txt.
		'skroutz'                => array( 'product', 'products' ),
		'pricerunner'            => array( 'item', 'items' ),
		// V5 woo_feed_filter_parsed_rules(): criteo → <item> in <channel>.
		'criteo'                 => array( 'item', 'channel' ),
		// Google Product Reviews Atom feed → <review> in <reviews>.
		'googlereview'           => array( 'review', 'reviews' ),
		// Delimited/other XML merchants with a forced wrapper.
		'wine_searcher'          => array( 'row', 'product-list' ),
		'trovaprezzi'            => array( 'Offer', 'Products' ),
	);

	/**
	 * Canonical XML wrapper for a provider, or null to keep the stored value.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug (feedrules `provider`).
	 * @return array{item:string, items:string}|null
	 */
	public static function xml_wrapper( string $provider ): ?array {
		if ( isset( self::EXACT[ $provider ] ) ) {
			return array(
				'item'  => self::EXACT[ $provider ][0],
				'items' => self::EXACT[ $provider ][1],
			);
		}

		// Substring matches — V5 used strpos() so every category variant is
		// covered (daisycon_fashion, daisycon_telecom_gsm, …).
		if ( false !== strpos( $provider, 'daisycon' ) ) {
			return array(
				'item'  => 'item',
				'items' => 'channel',
			);
		}

		if ( false !== strpos( $provider, 'zbozi.cz' ) ) {
			return array(
				'item'  => 'SHOPITEM',
				'items' => 'SHOP xmlns="http://www.zbozi.cz/ns/offer/1.0"',
			);
		}

		if ( false !== strpos( $provider, 'heureka.sk' ) ) {
			// V5 only forces the item wrapper here; the outer stays default.
			return array(
				'item'  => 'SHOPITEM',
				'items' => 'products',
			);
		}

		return null;
	}
}
