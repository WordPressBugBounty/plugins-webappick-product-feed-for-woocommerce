<?php
/**
 * FacebookTransform — Applies Facebook/Meta Catalog-specific formatting rules.
 *
 * Enforces Facebook Catalog requirements:
 * - Title max 150 characters
 * - Description max 10,000 characters
 * - Availability with spaces (in stock, out of stock) — NO underscores
 * - Variation description extension with attribute values
 *
 * Fires legacy V5 hooks for backward compatibility.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements F-01, F-02
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductMemo;
use CTXFeed\V8\Utility\Ascii;
use CTXFeed\V8\Utility\Mb;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facebook/Meta Catalog transform.
 *
 * @since 8.0.0
 */
class FacebookTransform implements TransformInterface {

	/**
	 * Term-name memo for variation descriptions (taxonomy|slug => name).
	 *
	 * @var array<string,string>
	 */
	private $term_names = array();

	use FeedCurrencyFallbackTrait;


	/**
	 * Facebook title max length.
	 *
	 * @var int
	 */
	// Meta allows 200 chars (65 recommended for display) — truncating
	// earlier than the platform limit loses data for no reason.
	const TITLE_MAX_LENGTH = 200;

	/**
	 * Facebook description max length.
	 *
	 * @var int
	 */
	// Meta's documented maximum is 9,999 characters — 10,000 was one
	// over the limit.
	const DESCRIPTION_MAX_LENGTH = 9999;

	/**
	 * Transform product data for Facebook Catalog compliance.
	 *
	 * No-ops for non-Facebook providers.
	 *
	 * @since 8.0.0
	 * @implements F-01, F-02
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		// Only apply to Facebook providers.
		if ( 'facebook' !== substr( $provider, 0, 8 ) ) {
			return $product_data;
		}

		$product_data = $this->transform_title( $product_data, $config );
		$product_data = $this->transform_description( $product_data, $config );
		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_internal_label( $product_data );
		$product_data = $this->transform_importer_address( $product_data );

		// Price / sale_price = bare number + the row's configured suffix as
		// written; the feed currency is appended ONLY when the suffix is empty
		// and a multi-currency plugin provided that currency (CBT-602, owner).
		$product_data = $this->apply_feed_currency_fallback( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Truncate title to Facebook max length.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_title( array $data, Config $config ): array {
		if ( empty( $data['title'] ) ) {
			return $data;
		}

		$title = $data['title'];

		// Fire legacy V5 hook for backward compatibility.
		$title = apply_filters( 'woo_feed_filter_product_title', $title, $data, $config );

		if ( Mb::strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			$title = Mb::substr( $title, 0, self::TITLE_MAX_LENGTH );
		}

		$data['title'] = $title;

		return $data;
	}

	/**
	 * Transform description for Facebook.
	 *
	 * For variation products, appends variation attribute values to description.
	 * Truncates to Facebook max 10,000 characters.
	 *
	 * @since 8.0.0
	 * @implements F-01
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_description( array $data, Config $config ): array {
		if ( empty( $data['description'] ) ) {
			return $data;
		}

		$description = $data['description'];

		// Fire legacy V5 hook.
		$description = apply_filters( 'woo_feed_filter_product_description', $description, $data, $config );

		// @implements F-01 — Append variation attributes for variation products.
		$description = $this->append_variation_attributes( $description, $data );

		// Truncate to Facebook max.
		if ( Mb::strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			$description = Mb::substr( $description, 0, self::DESCRIPTION_MAX_LENGTH );
		}

		$data['description'] = $description;

		return $data;
	}

	/**
	 * Append variation attribute values to description.
	 *
	 * For variation products, appends a human-readable list of variation
	 * attributes. E.g., "Description — Color: Red, Size: L"
	 *
	 * @since 8.0.0
	 * @implements F-01
	 *
	 * @param string $description Current description text.
	 * @param array  $data        Product data.
	 *
	 * @return string Description with appended variation info.
	 */
	private function append_variation_attributes( string $description, array $data ): string {
		// Check if this is a variation product via product_type or item_group_id.
		$is_variation = false;

		if ( ! empty( $data['product_type'] ) && 'variation' === $data['product_type'] ) {
			$is_variation = true;
		} elseif ( ! empty( $data['item_group_id'] ) ) {
			$is_variation = true;
		}

		if ( ! $is_variation ) {
			return $description;
		}

		// Try to get the product to read variation attributes.
		$product_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;

		if ( empty( $product_id ) ) {
			return $description;
		}

		$product = ProductMemo::get( $product_id );

		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return $description;
		}

		$attributes = $product->get_variation_attributes();

		if ( empty( $attributes ) ) {
			return $description;
		}

		$attr_parts = array();

		foreach ( $attributes as $attr_name => $attr_value ) {
			if ( empty( $attr_value ) ) {
				continue;
			}

			$attr_parts[] = $this->attribute_label( (string) $attr_name, $product ) . ': ' . $this->attribute_value_name( (string) $attr_name, (string) $attr_value );
		}

		if ( ! empty( $attr_parts ) ) {
			$description .= ' — ' . implode( ', ', $attr_parts );
		}

		return $description;
	}

	/**
	 * Human label of a variation attribute ("Color", "Länge").
	 *
	 * WooCommerce's own label when available (global attribute label, or the
	 * custom attribute's name on the product); otherwise the cleaned key.
	 *
	 * @since 8.0.40
	 *
	 * @param string      $attr_name Variation attribute key (`attribute_pa_color`).
	 * @param \WC_Product $product   Variation.
	 * @return string
	 */
	private function attribute_label( string $attr_name, \WC_Product $product ): string {
		$taxonomy = 0 === strpos( $attr_name, 'attribute_' ) ? substr( $attr_name, 10 ) : $attr_name;
		if ( function_exists( 'wc_attribute_label' ) ) {
			$label = (string) wc_attribute_label( $taxonomy, $product );
			if ( '' !== $label && $label !== $taxonomy ) {
				return $label;
			}
		}
		$clean = 0 === strpos( $taxonomy, 'pa_' ) ? substr( $taxonomy, 3 ) : $taxonomy;
		return Ascii::ucfirst( str_replace( array( '-', '_' ), ' ', $clean ) );
	}

	/**
	 * Display name of a variation's attribute value.
	 *
	 * A global attribute (`pa_*`) stores the term SLUG on the variation
	 * ("dark-blue"); the feed shows the term NAME ("Dark Blue"), as V5's
	 * variation title did (CBT-832). Custom attributes already store text.
	 * Memoised per taxonomy + slug.
	 *
	 * @since 8.0.40
	 *
	 * @param string $attr_name  Variation attribute key.
	 * @param string $attr_value Stored value.
	 * @return string
	 */
	private function attribute_value_name( string $attr_name, string $attr_value ): string {
		$taxonomy = 0 === strpos( $attr_name, 'attribute_' ) ? substr( $attr_name, 10 ) : $attr_name;
		if ( 0 !== strpos( $taxonomy, 'pa_' ) || ! function_exists( 'get_term_by' ) || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
			return $attr_value;
		}

		$memo_key = $taxonomy . '|' . $attr_value;
		if ( ! isset( $this->term_names[ $memo_key ] ) ) {
			$term                          = get_term_by( 'slug', $attr_value, $taxonomy );
			$this->term_names[ $memo_key ] = ( $term instanceof \WP_Term && '' !== (string) $term->name ) ? (string) $term->name : $attr_value;
		}
		return $this->term_names[ $memo_key ];
	}

	/**
	 * Normalize availability to Facebook space format.
	 *
	 * Facebook uses spaces: "in stock", "out of stock".
	 * Removes underscores that Google format would add.
	 *
	 * @since 8.0.0
	 * @implements F-02
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	/**
	 * Maximum labels per product / characters per label — Meta spec.
	 *
	 * @since 8.0.0
	 */
	const INTERNAL_LABEL_MAX_COUNT  = 5000;
	const INTERNAL_LABEL_MAX_LENGTH = 110;

	/**
	 * Shape internal_label into Meta's documented list format.
	 *
	 * Meta spec: "Enclose each label in single quotes (') and separate
	 * multiple labels with commas. Don't include white space at the
	 * beginning or end of a label. Character limit: 5,000 labels per
	 * product and 110 characters per label. Example: ['summer','trending']"
	 *
	 * Accepts any of the shapes merchants actually map — a plain comma
	 * list, a JSON array, or the Meta format itself — and emits the
	 * canonical single-quoted form with the limits enforced.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 * @return array Modified product data.
	 */
	private function transform_internal_label( array $data ): array {
		if ( empty( $data['internal_label'] ) || ! is_string( $data['internal_label'] ) ) {
			return $data;
		}

		$raw = trim( $data['internal_label'] );

		// Bracket-wrapped input (Meta format or a JSON array): strip the
		// outer brackets so the comma split below sees only the labels.
		if ( strlen( $raw ) >= 2 && '[' === $raw[0] && ']' === substr( $raw, -1 ) ) {
			$raw = substr( $raw, 1, -1 );
		}

		$labels = array();
		foreach ( explode( ',', $raw ) as $part ) {
			// Outer whitespace, then surrounding quotes (either style),
			// then whitespace that was INSIDE the quotes — Meta forbids
			// leading/trailing whitespace within a label.
			$label = trim( trim( trim( $part ), "'\"" ) );
			// An embedded single quote would break Meta's delimiter.
			$label = str_replace( "'", '', $label );

			if ( '' === $label ) {
				continue;
			}

			$labels[] = Mb::substr( $label, 0, self::INTERNAL_LABEL_MAX_LENGTH );

			if ( count( $labels ) >= self::INTERNAL_LABEL_MAX_COUNT ) {
				break;
			}
		}

		if ( ! empty( $labels ) ) {
			$data['internal_label'] = "['" . implode( "','", $labels ) . "']";
		}

		return $data;
	}

	/**
	 * Shape importer_address into Meta's JSON object.
	 *
	 * Meta spec (India commerce): a JSON structure with street1 / city /
	 * region / postal_code / country. A merchant-supplied JSON object
	 * passes through untouched; plain address text is wrapped as
	 * {"street1": "<text>"} so the column is at least well-formed JSON
	 * instead of an unparseable string.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 * @return array Modified product data.
	 */
	private function transform_importer_address( array $data ): array {
		if ( empty( $data['importer_address'] ) || ! is_string( $data['importer_address'] ) ) {
			return $data;
		}

		$raw = trim( $data['importer_address'] );

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $data;
		}

		$data['importer_address'] = (string) wp_json_encode( array( 'street1' => $raw ) );

		return $data;
	}

	/**
	 * Normalize the availability value to Facebook's accepted set.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Product data with normalized availability.
	 */
	private function transform_availability( array $data ): array {
		if ( empty( $data['availability'] ) ) {
			return $data;
		}

		$availability = Ascii::lower( trim( $data['availability'] ) ); // CBT-815: never the locale's fold.

		// Replace underscores with spaces for Facebook format.
		$availability = str_replace( '_', ' ', $availability );

		// Normalize common variants.
		$map = array(
			'in stock'     => 'in stock',
			'out of stock' => 'out of stock',
			'instock'      => 'in stock',
			'outofstock'   => 'out of stock',
			'preorder'     => 'preorder',
			'backorder'    => 'available for order',
		);

		if ( isset( $map[ $availability ] ) ) {
			$availability = $map[ $availability ];
		}

		$data['availability'] = $availability;

		return $data;
	}
}
