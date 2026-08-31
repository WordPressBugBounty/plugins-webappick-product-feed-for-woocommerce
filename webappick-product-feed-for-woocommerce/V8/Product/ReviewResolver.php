<?php
/**
 * ReviewResolver — builds Google Product Review entries from WooCommerce
 * product reviews (comments).
 *
 * V8 equivalent of V5's GooglereviewStructure: a review feed iterates a
 * product's APPROVED review comments, not the product itself — each
 * approved review with content and a star rating becomes one <review>
 * entry.
 *
 * Only the product IDENTIFIERS that link a review to a product in Merchant
 * Center are merchant-mappable (SKU / GTIN / brand — which field holds each
 * varies per store). Everything else is auto-configured in the backend:
 *   • review id / reviewer / timestamp / rating / content — from the comment;
 *   • is_verified_purchase — from WooCommerce's "verified owner" flag;
 *   • review_language / review_country — from the feed's configured locale;
 *   • title / pros / cons / reviewer_images / transaction_id /
 *     collection_method / is_incentivized_review / is_spam — optional
 *     enrichment a review plugin (or the Pro module) supplies through the
 *     `ctxfeed_review_*` filters (empty by default).
 *
 * Element order follows Google's product_reviews.xsd 2.4 sequence — the XSD
 * is a strict <xs:sequence>, so every optional element must slot into its
 * exact position or the feed fails validation.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @see        https://developers.google.com/product-review-feeds/schema
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product review resolver.
 *
 * @since 8.0.0
 */
class ReviewResolver {

	/**
	 * Mapping of review_temp_* merchant rows to product_ids sub-elements.
	 *
	 * Only the identifiers that match a review to a product are mappable —
	 * SKU, GTIN and brand (the store decides which field holds each).
	 *
	 * @since 8.0.0
	 * @var array<string,array{wrapper:string,tag:string}>
	 */
	private const ID_ROWS = array(
		'review_temp_gtin'  => array(
			'wrapper' => 'gtins',
			'tag'     => 'gtin',
		),
		'review_temp_sku'   => array(
			'wrapper' => 'skus',
			'tag'     => 'sku',
		),
		'review_temp_brand' => array(
			'wrapper' => 'brands',
			'tag'     => 'brand',
		),
	);

	/**
	 * Attribute resolver for the review_temp_* mapping rows.
	 *
	 * @since 8.0.0
	 * @var AttributeResolver
	 */
	private $attribute_resolver;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param AttributeResolver $attribute_resolver Attribute resolver.
	 */
	public function __construct( AttributeResolver $attribute_resolver ) {
		$this->attribute_resolver = $attribute_resolver;
	}

	/**
	 * Build review entries for one product.
	 *
	 * Each entry is a nested array in XMLTemplate's render shape — element
	 * order follows Google's product_reviews.xsd 2.4 sequence:
	 *
	 *   review_id, reviewer, review_language, review_country,
	 *   review_timestamp, title, content, pros, cons, review_url,
	 *   reviewer_images, ratings, products, is_spam, is_verified_purchase,
	 *   is_incentivized_review, collection_method, transaction_id
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array<int,array> One nested array per approved review.
	 */
	public function resolve( \WC_Product $product, Config $config ): array {
		$args = array(
			'post_id'     => $product->get_id(),
			'status'      => 'approve',
			'post_status' => 'publish',
			'post_type'   => 'product',
			'parent'      => 0,
		);

		/**
		 * Filter the number of reviews exported per product.
		 *
		 * V5 hook preserved verbatim. 0 (default) exports every
		 * approved review.
		 *
		 * @since 8.0.0
		 *
		 * @param int $limit Reviews per product; 0 = unlimited.
		 */
		$limit = (int) apply_filters( 'woo_feed_get_review_limit_per_product', 0 );
		if ( $limit > 0 ) {
			$args['number']  = $limit;
			$args['orderby'] = 'comment_date';
			$args['order']   = 'DESC';
		}

		$comments = get_comments( $args );

		if ( empty( $comments ) || ! is_array( $comments ) ) {
			return array();
		}

		$product_url = (string) $product->get_permalink();
		$product_ids = $this->resolve_product_ids( $product, $config );
		$entries     = array();

		/**
		 * Filter the review rating scale (Google requires min/max on <overall>).
		 *
		 * WooCommerce star ratings are 1–5; a store using a different scale can
		 * adjust here so the emitted min/max attributes match.
		 *
		 * @since 8.0.0
		 *
		 * @param int    $value  Rating bound.
		 * @param Config $config Feed configuration.
		 */
		$rating_min = (int) apply_filters( 'ctxfeed_review_rating_min', 1, $config );
		$rating_max = (int) apply_filters( 'ctxfeed_review_rating_max', 5, $config );

		foreach ( $comments as $comment ) {
			// Google requires a star rating — WC stores it as comment
			// meta `rating`; comments without one (e.g. plain replies)
			// are not reviews.
			$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( empty( $rating ) ) {
				continue;
			}

			// <content> (required, nonEmptyStringType) is the review body —
			// always the WooCommerce comment text. Strip HTML but keep the
			// original when stripping empties it; skip the review when the
			// body ends up empty.
			$content  = (string) $comment->comment_content;
			$stripped = wp_strip_all_tags( wp_specialchars_decode( $content ) );
			if ( strlen( $stripped ) > 0 ) {
				$content = $stripped;
			}
			if ( '' === trim( $content ) ) {
				continue;
			}

			$timestamp = ! empty( $comment->comment_date_gmt )
				? gmdate( 'c', strtotime( $comment->comment_date_gmt ) )
				: '';

			// Reviewer: a blank author is an anonymous review — XSD marks
			// <name is_anonymous>; a WP account (user_id > 0) supplies the
			// optional reviewer_id.
			$author = (string) $comment->comment_author;
			if ( '' === trim( $author ) ) {
				$reviewer = array(
					'name' => array(
						'@attributes' => array( 'is_anonymous' => 'true' ),
						'@value'      => '',
					),
				);
			} else {
				$reviewer = array( 'name' => $author );
			}
			if ( (int) $comment->user_id > 0 ) {
				$reviewer['reviewer_id'] = (string) $comment->user_id;
			}

			// Clamp the star rating into the declared [min, max] range.
			$rating_value = (string) max( $rating_min, min( $rating_max, (int) $rating ) );

			// Auto-configured optional fields (locale + enrichment filters).
			$fields = $this->auto_fields( $comment, $product, $config );

			// Assemble in strict XSD 2.4 sequence order (verified against
			// product_reviews.xsd 2.4; the full sequence is listed on this
			// method's docblock). Optional elements are inserted only when
			// present so their absence never breaks the sequence for the
			// elements that follow.
			$entry = array( 'review_id' => (string) $comment->comment_ID );

			$entry['reviewer'] = $reviewer;

			if ( isset( $fields['review_language'] ) ) {
				$entry['review_language'] = $fields['review_language'];
			}
			if ( isset( $fields['review_country'] ) ) {
				$entry['review_country'] = $fields['review_country'];
			}

			$entry['review_timestamp'] = $timestamp;

			if ( isset( $fields['title'] ) ) {
				$entry['title'] = $fields['title'];
			}

			$entry['content'] = $content;

			if ( isset( $fields['pros'] ) ) {
				$entry['pros'] = $fields['pros'];
			}
			if ( isset( $fields['cons'] ) ) {
				$entry['cons'] = $fields['cons'];
			}

			// XSD 2.4: <review_url> REQUIRES a `type` attribute (singleton =
			// a single review at this URL; group = a page of reviews). Each
			// WC comment is its own page → singleton.
			$entry['review_url'] = array(
				'@attributes' => array( 'type' => 'singleton' ),
				'@value'      => $product_url,
			);

			if ( isset( $fields['reviewer_images'] ) ) {
				$entry['reviewer_images'] = $fields['reviewer_images'];
			}

			// XSD 2.4: <overall> REQUIRES `min` and `max` attributes.
			$entry['ratings'] = array(
				'overall' => array(
					'@attributes' => array(
						'min' => (string) $rating_min,
						'max' => (string) $rating_max,
					),
					'@value'      => $rating_value,
				),
			);

			$entry['products'] = array(
				'product' => array_merge(
					empty( $product_ids ) ? array() : array( 'product_ids' => $product_ids ),
					array(
						'product_name' => (string) $product->get_name(),
						'product_url'  => $product_url,
					)
				),
			);

			if ( isset( $fields['is_spam'] ) ) {
				$entry['is_spam'] = $fields['is_spam'];
			}
			if ( isset( $fields['is_verified_purchase'] ) ) {
				$entry['is_verified_purchase'] = $fields['is_verified_purchase'];
			}
			if ( isset( $fields['is_incentivized_review'] ) ) {
				$entry['is_incentivized_review'] = $fields['is_incentivized_review'];
			}
			if ( isset( $fields['collection_method'] ) ) {
				$entry['collection_method'] = $fields['collection_method'];
			}
			if ( isset( $fields['transaction_id'] ) ) {
				$entry['transaction_id'] = $fields['transaction_id'];
			}

			/**
			 * Filter one resolved review entry.
			 *
			 * @since 8.0.0
			 *
			 * @param array       $entry   Nested review entry.
			 * @param \WP_Comment $comment Source review comment.
			 * @param \WC_Product $product Reviewed product.
			 * @param Config      $config  Feed configuration.
			 */
			$entries[] = apply_filters( 'ctxfeed_review_entry', $entry, $comment, $product, $config );
		}

		return $entries;
	}

	/**
	 * Build the auto-configured optional review fields for one comment.
	 *
	 * `review_language`/`review_country` default to the feed's configured
	 * locale; `is_verified_purchase` reflects WooCommerce's verified-owner
	 * flag; the remaining enrichment (title / pros / cons / reviewer_images /
	 * transaction_id / collection_method / is_incentivized_review / is_spam)
	 * is empty unless a review plugin or the Pro module supplies it through
	 * the matching `ctxfeed_review_*` filter.
	 *
	 * @since 8.0.0
	 *
	 * @param object      $comment Review comment.
	 * @param \WC_Product $product Reviewed product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array<string,mixed> Tag => rendered value/structure.
	 */
	private function auto_fields( $comment, \WC_Product $product, Config $config ): array {
		$out = array();

		// Locale — every review carries the store's language/country by
		// default (BCP 47 / ISO 3166-1), from the feed's own settings.
		// The feed language is stored under 'feedLanguage' (get_feed_language),
		// not 'feed_language' — reading the wrong key meant the review_language
		// locale was never taken from the feed settings.
		$language = trim( (string) apply_filters( 'ctxfeed_review_language', $config->get_feed_language(), $comment, $config ) );
		if ( '' !== $language ) {
			$out['review_language'] = $language;
		}
		$country = trim( (string) apply_filters( 'ctxfeed_review_country', (string) $config->get( 'feed_country', '' ), $comment, $config ) );
		if ( '' !== $country ) {
			$out['review_country'] = $country;
		}

		// Review title — no WooCommerce equivalent; opt-in via filter.
		$title = trim( (string) apply_filters( 'ctxfeed_review_title', '', $comment, $config ) );
		if ( '' !== $title ) {
			$out['title'] = $title;
		}

		// Pros / cons — filters may return an array or a comma-separated
		// string; each member becomes a repeated <pro>/<con>.
		$pros = $this->to_list( apply_filters( 'ctxfeed_review_pros', array(), $comment, $config ) );
		if ( ! empty( $pros ) ) {
			$out['pros'] = $this->wrap_list( $pros, 'pro' );
		}
		$cons = $this->to_list( apply_filters( 'ctxfeed_review_cons', array(), $comment, $config ) );
		if ( ! empty( $cons ) ) {
			$out['cons'] = $this->wrap_list( $cons, 'con' );
		}

		// Reviewer images — <reviewer_image><url>…</url></reviewer_image>.
		$images = $this->to_list( apply_filters( 'ctxfeed_review_images', array(), $comment, $config ) );
		if ( ! empty( $images ) ) {
			$wrapped = array();
			foreach ( $images as $url ) {
				$wrapped[] = array( 'reviewer_image' => array( 'url' => $url ) );
			}
			$out['reviewer_images'] = $wrapped;
		}

		// is_verified_purchase — WooCommerce's "verified owner" flag.
		$verified_meta    = get_comment_meta( (int) $comment->comment_ID, 'verified', true );
		$default_verified = ( '' === (string) $verified_meta ) ? null : $this->to_bool_string( (string) $verified_meta );
		$verified         = apply_filters( 'ctxfeed_review_is_verified_purchase', $default_verified, $comment, $config );
		if ( null !== $verified ) {
			$out['is_verified_purchase'] = $this->to_bool_string( (string) $verified );
		}

		// Incentivized / spam — null (default) omits the element.
		$incentivized = apply_filters( 'ctxfeed_review_is_incentivized', null, $comment, $config );
		if ( null !== $incentivized ) {
			$out['is_incentivized_review'] = $this->to_bool_string( (string) $incentivized );
		}
		$spam = apply_filters( 'ctxfeed_review_is_spam', null, $comment, $config );
		if ( null !== $spam ) {
			$out['is_spam'] = $this->to_bool_string( (string) $spam );
		}

		// Collection method (unsolicited / post_fulfillment) + transaction id.
		$method = trim( (string) apply_filters( 'ctxfeed_review_collection_method', '', $comment, $config ) );
		if ( '' !== $method ) {
			$out['collection_method'] = $method;
		}
		$transaction = trim( (string) apply_filters( 'ctxfeed_review_transaction_id', '', $comment, $config ) );
		if ( '' !== $transaction ) {
			$out['transaction_id'] = $transaction;
		}

		return $out;
	}

	/**
	 * Wrap each member of a list in a single-key array (repeating element).
	 *
	 * @since 8.0.0
	 *
	 * @param array<int,string> $members List members.
	 * @param string            $tag     Child element name (e.g. 'pro').
	 *
	 * @return array<int,array<string,string>>
	 */
	private function wrap_list( array $members, string $tag ): array {
		$out = array();
		foreach ( $members as $member ) {
			$out[] = array( $tag => $member );
		}

		return $out;
	}

	/**
	 * Normalise a filter value (array or separator-joined string) to a
	 * clean list of non-empty members.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Array or string.
	 *
	 * @return array<int,string>
	 */
	private function to_list( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			/**
			 * Filter the separator that splits multi-value review fields.
			 *
			 * @since 8.0.0
			 *
			 * @param string $separator Default comma.
			 */
			$separator = (string) apply_filters( 'ctxfeed_review_multi_separator', ',' );
			$parts     = explode( $separator, (string) $value );
		}

		$members = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$members[] = $part;
			}
		}

		return $members;
	}

	/**
	 * Normalise a raw value to an xs:boolean string ("true"/"false").
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string "true" or "false".
	 */
	private function to_bool_string( string $value ): string {
		$truthy = in_array(
			strtolower( trim( $value ) ),
			array( '1', 'true', 'yes', 'y', 'on' ),
			true
		);

		return $truthy ? 'true' : 'false';
	}

	/**
	 * Build the product_ids block from the feed's review_temp_* rows.
	 *
	 * Pattern rows use their static default; attribute rows resolve
	 * against the product. Prefix/suffix apply like any mapping row.
	 * Variable products list every variation's value (V5 parity).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return array product_ids sub-structure, possibly empty.
	 */
	private function resolve_product_ids( \WC_Product $product, Config $config ): array {
		$mattributes = $config->get_merchant_attributes();
		$wc_attrs    = $config->get_attributes();
		$types       = $config->get_type();
		$defaults    = $config->get_default();
		$prefixes    = $config->get_prefix();
		$suffixes    = $config->get_suffix();

		$product_ids = array();

		foreach ( $mattributes as $index => $merchant_attr ) {
			if ( ! isset( self::ID_ROWS[ $merchant_attr ] ) ) {
				continue;
			}

			$wrapper = self::ID_ROWS[ $merchant_attr ]['wrapper'];
			$tag     = self::ID_ROWS[ $merchant_attr ]['tag'];
			$prefix  = isset( $prefixes[ $index ] ) ? (string) $prefixes[ $index ] : '';
			$suffix  = isset( $suffixes[ $index ] ) ? (string) $suffixes[ $index ] : '';

			$row = array(
				'type'          => isset( $types[ $index ] ) ? $types[ $index ] : 'attribute',
				'wc_attr'       => isset( $wc_attrs[ $index ] ) ? $wc_attrs[ $index ] : '',
				'default'       => isset( $defaults[ $index ] ) ? $defaults[ $index ] : '',
				'merchant_attr' => (string) $merchant_attr,
				'index'         => (int) $index,
			);

			// Variable parents list each variation's value — a review of
			// the parent applies to every purchasable variant.
			if ( $product->is_type( 'variable' ) ) {
				$members = array();
				$seen    = array();
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( ! $variation ) {
						continue;
					}
					$value = $this->resolve_row_value( $variation, $row, $config, $prefix, $suffix );
					// De-duplicate: a single-value row (e.g. brand or a shared
					// GTIN) resolves to the same value for every variation —
					// Google expects each identifier listed once, not repeated.
					if ( '' !== $value && ! isset( $seen[ $value ] ) ) {
						$seen[ $value ] = true;
						$members[]      = array( $tag => $value );
					}
				}
				if ( ! empty( $members ) ) {
					$product_ids[ $wrapper ] = $members;
				}
				continue;
			}

			$value = $this->resolve_row_value( $product, $row, $config, $prefix, $suffix );
			if ( '' !== $value ) {
				$product_ids[ $wrapper ] = array( $tag => $value );
			}
		}

		// Google's productIdsType is a strict sequence — gtins, mpns, skus,
		// brands, asins — so emit the wrappers in that canonical order
		// regardless of the order the mapping rows were saved in.
		$ordered = array();
		foreach ( array( 'gtins', 'mpns', 'skus', 'brands', 'asins' ) as $wrap ) {
			if ( isset( $product_ids[ $wrap ] ) ) {
				$ordered[ $wrap ] = $product_ids[ $wrap ];
			}
		}

		return $ordered;
	}

	/**
	 * Resolve one review_temp_* row against a product.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $row     Mapping row (type/wc_attr/default/…).
	 * @param Config      $config  Feed configuration.
	 * @param string      $prefix  Row prefix.
	 * @param string      $suffix  Row suffix.
	 *
	 * @return string Trimmed value with prefix/suffix applied.
	 */
	private function resolve_row_value( \WC_Product $product, array $row, Config $config, string $prefix, string $suffix ): string {
		if ( 'pattern' === $row['type'] ) {
			$value = (string) $row['default'];
		} else {
			$value = (string) $this->attribute_resolver->resolve( $product, $row, $config );
		}

		return trim( trim( $prefix ) . ' ' . trim( $value ) . ' ' . trim( $suffix ) );
	}
}
