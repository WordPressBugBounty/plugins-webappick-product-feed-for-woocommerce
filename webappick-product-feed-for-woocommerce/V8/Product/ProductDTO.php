<?php
/**
 * ProductDTO — Lightweight data transfer object for resolved product data.
 *
 * Holds the final attribute values for a single product, ready for
 * feed template rendering. Contains no WC_Product reference — just
 * resolved string values keyed by channel attribute names.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-12.1
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product data transfer object.
 *
 * @since 8.0.0
 */
class ProductDTO {

	/**
	 * Product ID.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	public int $id;

	/**
	 * Product type (simple, variable, variation, grouped, external).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	public string $type;

	/**
	 * Resolved attribute key-value pairs.
	 *
	 * @since 8.0.0
	 * @var array<string, mixed>
	 */
	public array $attributes = array();

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param int    $id   Product ID.
	 * @param string $type Product type.
	 */
	public function __construct( int $id, string $type ) {
		$this->id   = $id;
		$this->type = $type;
	}

	/**
	 * Set an attribute value.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-12.1
	 *
	 * @param string $key   Attribute key (channel attribute name).
	 * @param mixed  $value Resolved attribute value.
	 *
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->attributes[ $key ] = $value;
	}

	/**
	 * Get an attribute value.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-12.1
	 *
	 * @param string $key     Attribute key.
	 * @param mixed  $default Default value if key not found.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Public DTO accessor mirroring get_option()/get_post_meta() naming. Since PHP 8 the parameter name is part of the API (named arguments), so renaming it would be a breaking change for the Pro plugin and third-party integrations.
		return $this->attributes[ $key ] ?? $default;
	}

	/**
	 * Convert to array for template rendering.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-12.1
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->attributes;
	}
}
