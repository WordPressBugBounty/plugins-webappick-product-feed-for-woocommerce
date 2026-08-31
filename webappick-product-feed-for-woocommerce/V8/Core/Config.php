<?php
/**
 * Config — Immutable feed configuration value object.
 *
 * Wraps the feedrules array into a typed, read-only object with
 * dot-notation access. Backward compatible with V5 feedrules format.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-4.1, CORE-FRD-4.2, CORE-FRD-4.3, CORE-FRD-4.4, CORE-FRD-4.5
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable feed configuration value object.
 *
 * No setter methods — constructor is the only way to set data.
 *
 * @since 8.0.0
 */
class Config {

	/**
	 * Raw feedrules data.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param array $rules Feedrules data array.
	 */
	public function __construct( array $rules ) {

		$this->rules = $rules;

		// BUG-0061: precompute which mapping rows to keep. A row whose merchant
		// (mattributes) AND source (attributes) are both empty produces broken
		// output — an empty XML element (<></>), or a phantom CSV column that
		// shifts every following column. Dropping such rows once here keeps the
		// parallel getters (and therefore the per-product row builder AND the
		// delimited-header builder, which both index them positionally) in sync
		// across every format.
		$this->kept_row_keys = $this->compute_kept_row_keys();
	}

	/**
	 * Original indices of the mapping rows to keep, flipped for O(1) lookup, or
	 * null when no row is dropped (the common case — filtering is skipped).
	 *
	 * @var array<int,int>|null
	 */
	private $kept_row_keys = null;

	/**
	 * Compute the mapping-row indices to keep (drop wholly-empty rows).
	 *
	 * A row is dropped when BOTH its merchant attribute and its source attribute
	 * are empty — UNLESS it is a static row (type `pattern`/`text`) carrying a
	 * non-empty default, which legitimately has no source attribute.
	 *
	 * @since 8.0.0
	 *
	 * @return array<int,int>|null Flipped kept-index map, or null if nothing dropped.
	 */
	private function compute_kept_row_keys(): ?array {
		$mattributes = (array) ( $this->rules['mattributes'] ?? array() );
		if ( empty( $mattributes ) ) {
			return null;
		}

		$attributes = (array) ( $this->rules['attributes'] ?? array() );
		$types      = (array) ( $this->rules['type'] ?? array() );
		$defaults   = (array) ( $this->rules['default'] ?? array() );

		$kept    = array();
		$dropped = false;

		foreach ( $mattributes as $index => $mattr ) {
			$mattr_empty = '' === trim( (string) $mattr );
			$wc_empty    = '' === trim( (string) ( $attributes[ $index ] ?? '' ) );
			$type        = (string) ( $types[ $index ] ?? 'attribute' );
			$has_default = '' !== trim( (string) ( $defaults[ $index ] ?? '' ) );
			$static_row  = in_array( $type, array( 'pattern', 'text' ), true ) && $has_default;

			if ( $mattr_empty && $wc_empty && ! $static_row ) {
				$dropped = true;
				continue;
			}

			$kept[] = $index;
		}

		return $dropped ? array_flip( $kept ) : null;
	}

	/**
	 * Filter a parallel mapping array down to the kept rows and re-index it.
	 *
	 * A no-op when no row was dropped, so unfiltered feeds pay nothing.
	 *
	 * @since 8.0.0
	 *
	 * @param array $values One of the parallel mapping arrays.
	 *
	 * @return array Filtered, re-indexed values.
	 */
	private function filter_mapping_row( array $values ): array {
		if ( null === $this->kept_row_keys ) {
			return $values;
		}

		return array_values( array_intersect_key( $values, $this->kept_row_keys ) );
	}

	/**
	 * Create Config from a stored feed option.
	 *
	 * Loads from `wf_feed_{$name}` (primary) or `wf_config{$name}` (legacy V5 key).
	 * Extracts the `feedrules` key if present; otherwise treats the root array as feedrules.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.1
	 *
	 * @param string $name Feed name identifier.
	 *
	 * @return Config|null Config instance or null if data not found/invalid.
	 */
	public static function from_feed_name( string $name ): ?self {

		// Primary key. Object-injection-safe unserialize — a poisoned
		// serialized-object payload in the option must never
		// instantiate (CTX-908 parity).
		$data = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( get_option( 'wf_feed_' . $name ) );

		// Legacy fallback.
		if ( empty( $data ) ) {
			$data = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( get_option( 'wf_config' . $name ) );
		}

		if ( empty( $data ) ) {
			return null;
		}

		// V5 stores as ['feedrules' => [...], 'url' => '...', 'status' => 1].
		// Extract feedrules if nested, otherwise use root array.
		$rules = isset( $data['feedrules'] ) ? $data['feedrules'] : $data;

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return null;
		}

		return new self( $rules );
	}

	// -------------------------------------------------------------------------
	// Typed Getters (CORE-FRD-4.2)
	// -------------------------------------------------------------------------

	/**
	 * Get the channel provider identifier.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Provider identifier (e.g., 'google', 'facebook').
	 */
	public function get_provider(): string {

		return (string) ( $this->rules['provider'] ?? '' );
	}

	/**
	 * Get the feed output type.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Feed type (xml, csv, tsv, json, txt).
	 */
	public function get_feed_type(): string {

		return (string) ( $this->rules['feedType'] ?? 'xml' );
	}

	/**
	 * Get the feed country code.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Country code.
	 */
	public function get_feed_country(): string {

		return (string) ( $this->rules['feed_country'] ?? '' );
	}

	/**
	 * Get the feed language.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Language identifier.
	 */
	public function get_feed_language(): string {

		return (string) ( $this->rules['feedLanguage'] ?? '' );
	}

	/**
	 * Get the feed currency.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Currency code (e.g., 'USD', 'EUR').
	 */
	public function get_feed_currency(): string {

		return (string) ( $this->rules['feedCurrency'] ?? '' );
	}

	/**
	 * Whether out-of-stock products are kept visible in the feed.
	 *
	 * V5-compatibility accessor. The Legacy Bridge compat shims (currency
	 * switchers etc. in the Pro `compatibility/` layer) call
	 * `$config->get_outofstock_visibility()` — mirror V5's method exactly
	 * (Utility\Config::get_outofstock_visibility): the raw `outofstock_visibility`
	 * feedrule, defaulting to false. Same key V8's VisibilityFilter reads.
	 *
	 * @since 8.0.0
	 *
	 * @return mixed The stored `outofstock_visibility` value, or false.
	 */
	public function get_outofstock_visibility() {

		return $this->rules['outofstock_visibility'] ?? false;
	}

	/**
	 * The full feedrules array (V5-compatibility accessor).
	 *
	 * V5 exposed the raw config array via `Config::get_config()`; some Pro
	 * compat shims (e.g. WC_Composite_Products, WPCleverWooco) still call it.
	 * Alias of {@see self::to_array()} so those shims keep working unmodified.
	 *
	 * @since 8.0.0
	 *
	 * @return array The feedrules array.
	 */
	public function get_config(): array {

		return $this->rules;
	}

	/**
	 * Get the filename for the generated feed.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Feed filename.
	 */
	public function get_filename(): string {

		return (string) ( $this->rules['filename'] ?? '' );
	}

	/**
	 * Get merchant attribute names.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Merchant attribute identifiers.
	 */
	public function get_merchant_attributes(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['mattributes'] ?? array() ) );
	}

	/**
	 * Get product attribute mappings.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Attribute mappings.
	 */
	public function get_attributes(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['attributes'] ?? array() ) );
	}

	/**
	 * Get prefix values for attributes.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Prefix values.
	 */
	public function get_prefix(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['prefix'] ?? array() ) );
	}

	/**
	 * Get suffix values for attributes.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Suffix values.
	 */
	public function get_suffix(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['suffix'] ?? array() ) );
	}

	/**
	 * Get attribute type definitions.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Type definitions.
	 */
	public function get_type(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['type'] ?? array() ) );
	}

	/**
	 * Get default values for attributes.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Default values.
	 */
	public function get_default(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['default'] ?? array() ) );
	}

	/**
	 * Get output type definitions for attributes.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Output type definitions.
	 */
	public function get_output_type(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['output_type'] ?? array() ) );
	}

	/**
	 * Get limit values for attributes.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Limit values.
	 */
	public function get_limit(): array {

		return $this->filter_mapping_row( (array) ( $this->rules['limit'] ?? array() ) );
	}

	/**
	 * Get the output-command string for a single attribute index.
	 *
	 * The feedrules `limit` array is positional (parallel to `mattributes`)
	 * and stores the per-attribute command chain, e.g.
	 * '[strip_tags], [substr 0 80]'. Used by ProductRepository (parent trio)
	 * and CommandTransform (string commands). The stored payload is read
	 * as-is — no renames, no format changes. XFRM-FRD-9.1.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2, XFRM-FRD-9.1
	 *
	 * @param int $index Parallel-array position of the attribute.
	 *
	 * @return string Raw command string ('' when unset or non-scalar).
	 */
	public function get_attribute_command( int $index ): string {
		$limits = $this->get_limit();

		if ( ! isset( $limits[ $index ] ) || ! is_scalar( $limits[ $index ] ) ) {
			return '';
		}

		return (string) $limits[ $index ];
	}

	/**
	 * Get product filter rules.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Filter rules.
	 */
	public function get_filter_rules(): array {

		return (array) ( $this->rules['filter_rules'] ?? array() );
	}

	/**
	 * Get campaign/UTM parameters.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return array Campaign parameters.
	 */
	public function get_campaign_parameters(): array {

		return (array) ( $this->rules['campaign_parameters'] ?? array() );
	}

	/**
	 * Get the batch size for feed generation.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return int Batch size (default 200).
	 */
	public function get_batch_size(): int {

		return (int) ( $this->rules['batch_size'] ?? 200 );
	}

	/**
	 * Check whether product variations should be included.
	 *
	 * V5 stores this as 'y'/'n' strings. Maps 'y' to true, 'n' to false.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return bool True if variations should be included (default true).
	 */
	public function is_variations(): bool {

		$value = $this->rules['is_variations'] ?? 'y';

		return 'n' !== $value;
	}

	/**
	 * Get the XML item wrapper element name.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Item wrapper (default 'product').
	 */
	public function get_item_wrapper(): string {

		return (string) ( $this->rules['itemWrapper'] ?? 'product' );
	}

	/**
	 * Get the XML items (root) wrapper element name.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Items wrapper (default 'products').
	 */
	public function get_items_wrapper(): string {

		return (string) ( $this->rules['itemsWrapper'] ?? 'products' );
	}

	/**
	 * Get the CSV/TSV delimiter character.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Delimiter character (default ',').
	 */
	public function get_delimiter(): string {

		return (string) ( $this->rules['delimiter'] ?? ',' );
	}

	/**
	 * Get the CSV enclosure type.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string Enclosure type (default 'double').
	 */
	public function get_enclosure(): string {

		return (string) ( $this->rules['enclosure'] ?? 'double' );
	}

	/**
	 * Get the end-of-record marker for CSV/TXT feeds.
	 *
	 * Some channels (e.g., Trovaprezzi) require a special marker appended
	 * after each data row. The channel's TemplateDefaults sets this value.
	 * Empty string means no marker.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.2
	 *
	 * @return string EOL marker (default '').
	 */
	public function get_eol_marker(): string {

		return (string) ( $this->rules['eolMarker'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Generic Access (CORE-FRD-4.3, CORE-FRD-4.4)
	// -------------------------------------------------------------------------

	/**
	 * Get a value using dot notation for nested access.
	 *
	 * Examples:
	 *   $config->get( 'provider' )                       → top-level key
	 *   $config->get( 'campaign_parameters.utm_source' ) → nested key
	 *   $config->get( 'attributes.0.value' )             → array index
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.3
	 *
	 * @param string $key           Dot-notated key path.
	 * @param mixed  $default_value Default value if key not found.
	 *
	 * @return mixed The value at the key path, or $default_value.
	 */
	public function get( string $key, $default_value = null ) {

		$segments = explode( '.', $key );
		$data     = $this->rules;

		foreach ( $segments as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$data = $data[ $segment ];
			} else {
				return $default_value;
			}
		}

		return $data;
	}

	/**
	 * Export the full feedrules array.
	 *
	 * Provides backward compatibility with V5 code and Legacy Bridge
	 * that expects raw arrays.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-4.4
	 *
	 * @return array The full feedrules array.
	 */
	public function to_array(): array {

		return $this->rules;
	}
}
