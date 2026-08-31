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

	use AppendsCurrencyTrait;

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

		// Facebook money format "<number> <ISO-4217>" (e.g. "48.00 USD").
		// PriceResolver emits bare numbers — the channel owns the format.
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

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

		if ( mb_strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			$title = mb_substr( $title, 0, self::TITLE_MAX_LENGTH );
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
		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			$description = mb_substr( $description, 0, self::DESCRIPTION_MAX_LENGTH );
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

		$product = wc_get_product( $product_id );

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

			// Clean up attribute name: remove "attribute_pa_" or "attribute_" prefix.
			$clean_name = str_replace( array( 'attribute_pa_', 'attribute_' ), '', $attr_name );
			$clean_name = ucfirst( str_replace( array( '-', '_' ), ' ', $clean_name ) );

			$attr_parts[] = $clean_name . ': ' . $attr_value;
		}

		if ( ! empty( $attr_parts ) ) {
			$description .= ' — ' . implode( ', ', $attr_parts );
		}

		return $description;
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

			$labels[] = mb_substr( $label, 0, self::INTERNAL_LABEL_MAX_LENGTH );

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

		$availability = strtolower( trim( $data['availability'] ) );

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
