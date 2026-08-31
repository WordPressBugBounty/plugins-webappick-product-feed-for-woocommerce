<?php
/**
 * GroupedAttributeBuilder — Restructures flat product data into nested groups.
 *
 * Google Shopping and other XML feeds require certain attributes to be
 * rendered as nested XML structures (e.g., g:tax, g:shipping, g:installment).
 * This class transforms flat product data arrays into nested arrays that
 * XMLTemplate::render_elements() can render recursively.
 *
 * Ported from V5's GoogleStructure::get_xml_structure() grouping logic,
 * but as a generic, channel-configurable builder.
 *
 * Group types:
 * - Nested: sub-elements wrapped under a parent tag (tax, shipping, installment).
 * - Repeating: multiple elements with the same tag name (product_highlight, images).
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-4.5
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restructures flat product attributes into nested XML groups.
 *
 * @since 8.0.0
 */
class GroupedAttributeBuilder {

	/**
	 * Nested group definitions per provider.
	 *
	 * Structure:
	 *   provider => [
	 *       wrapper_name => [
	 *           internal_attr => sub_element_name,
	 *           ...
	 *       ],
	 *   ]
	 *
	 * The wrapper_name becomes the parent XML element.
	 * Each internal_attr is matched against flat product data keys.
	 * The sub_element_name becomes the child XML element within the group.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $nested_groups = array();

	/**
	 * Repeating attribute definitions per provider.
	 *
	 * Structure:
	 *   provider => [
	 *       element_name => [ internal_attr_1, internal_attr_2, ... ],
	 *   ]
	 *
	 * Each internal_attr resolves to an individual <element_name>value</element_name>
	 * entry. Multiple entries create repeating XML elements.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $repeating_groups = array();

	/**
	 * Aggregate group definitions per provider.
	 *
	 * Structure:
	 *   provider => [
	 *       internal_attr => [
	 *           'wrapper' => wrapper_name,
	 *           'subs'    => [ entry_key => sub_element_name, ... ],
	 *       ],
	 *   ]
	 *
	 * An aggregate attribute (`shipping`, `tax`) resolves to a LIST of
	 * entry arrays sourced from live WooCommerce data. build() expands
	 * each entry into one nested wrapper block. V5 parity:
	 * GoogleShipping::get_xml() rendered these blocks as raw strings.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $aggregate_groups = array();

	/**
	 * Delimited JOINED-LIST columns per provider.
	 *
	 * Structure: provider => [ column_name => [ member_attr, ... ] ]
	 *
	 * All member values collapse into ONE delimited column, comma-joined
	 * — e.g. Meta's `additional_image_link` column carries up to 20 URLs
	 * "separated by comma". Repeated same-named columns (the Google CSV
	 * convention) lose all but one value at Meta ingestion.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $delimited_joined_groups = array();

	/**
	 * Nested groups rendered as JSON in DELIMITED formats, per provider.
	 *
	 * Meta's `unit_price` CSV column expects a JSON object
	 * ({"value":"3.60","currency":"USD","unit":"100 ml"}), not the
	 * colon-positional encoding used by Google-style groups.
	 *
	 * @since 8.0.0
	 * @var array<string,string[]> provider => group names
	 */
	private $delimited_json_groups = array();

	/**
	 * Shipping sub-attributes V5 nests INSIDE every g:shipping block.
	 *
	 * Mirrors V5 GoogleShipping::$shipping_attrs. For google XML these
	 * merchant attributes never render top-level — GoogleStructure
	 * strips them from mattributes and GoogleShipping::get_xml() emits
	 * them inside each shipping instance instead (Google recognises
	 * them only as shipping sub-attributes).
	 *
	 * @since 8.0.0
	 * @var array<string,string> internal_attr => sub_element_name
	 */
	private const SHIPPING_NESTED_EXTRAS = array(
		'location_id'         => 'g:location_id',
		'location_group_name' => 'g:location_group_name',
		'min_handling_time'   => 'g:min_handling_time',
		'max_handling_time'   => 'g:max_handling_time',
		'min_transit_time'    => 'g:min_transit_time',
		'max_transit_time'    => 'g:max_transit_time',
	);

	/**
	 * Constructor — initializes group definitions for all supported channels.
	 *
	 * @since 8.0.0
	 */
	public function __construct() {
		$this->init_google_groups();
		$this->init_facebook_groups();
		$this->init_pinterest_groups();
		$this->init_bing_groups();
		$this->init_skroutz_groups();

		/**
		 * Filter to register additional channel group definitions.
		 *
		 * Pro and third-party extensions can add grouped attribute
		 * definitions for custom channels.
		 *
		 * @since 8.0.0
		 *
		 * @param array $nested_groups    Nested group definitions.
		 * @param array $repeating_groups Repeating group definitions.
		 */
		$this->nested_groups    = apply_filters( 'ctxfeed_grouped_attribute_nested', $this->nested_groups );
		$this->repeating_groups = apply_filters( 'ctxfeed_grouped_attribute_repeating', $this->repeating_groups );
	}

	/**
	 * Build nested structure from flat product data.
	 *
	 * Extracts grouped attributes from flat data, builds nested arrays
	 * for the provider, and leaves non-grouped attributes untouched.
	 *
	 * This method operates on INTERNAL attribute names (pre-mapping).
	 * Group wrappers and sub-elements use merchant-specific mapped names.
	 * Non-grouped attributes retain their internal names for subsequent
	 * mapping by AttributeNameMapper.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Flat product data (internal attribute names).
	 * @param string $provider     Channel provider slug (e.g., 'google').
	 *
	 * @return array Product data with grouped attributes as nested arrays.
	 */
	public function build( array $product_data, string $provider ): array {
		$nested     = isset( $this->nested_groups[ $provider ] ) ? $this->nested_groups[ $provider ] : array();
		$repeating  = isset( $this->repeating_groups[ $provider ] ) ? $this->repeating_groups[ $provider ] : array();
		$aggregates = isset( $this->aggregate_groups[ $provider ] ) ? $this->aggregate_groups[ $provider ] : array();

		if ( empty( $nested ) && empty( $repeating ) && empty( $aggregates ) ) {
			return $product_data;
		}

		// V5 parity: the handling/transit/location shipping sub-attrs are
		// stripped from the top level and nested into every aggregate
		// shipping block instead (GoogleStructure removes them from
		// mattributes; GoogleShipping::get_xml() re-emits them inside
		// each <g:shipping>). Without an aggregate shipping mapping they
		// simply don't render — same as V5.
		// Only for g:-namespaced channels — bing's raw unprefixed blocks
		// can't host the g:-named extras, so those render flat (V5
		// BingStructure parity).
		$shipping_extras = array();
		if ( isset( $aggregates['shipping'] ) && 0 === strpos( $aggregates['shipping']['wrapper'], 'g:' ) ) {
			foreach ( $product_data as $attr => $value ) {
				$base = ProductRepository::strip_dup_suffix( (string) $attr );
				if ( isset( self::SHIPPING_NESTED_EXTRAS[ $base ] ) ) {
					if ( '' !== $value && null !== $value ) {
						$shipping_extras[ self::SHIPPING_NESTED_EXTRAS[ $base ] ] = $value;
					}
					unset( $product_data[ $attr ] );
				}
			}
		}

		// Build reverse lookup: internal_attr → [ wrapper_name, sub_element_name ].
		$attr_to_group = array();
		foreach ( $nested as $wrapper => $members ) {
			foreach ( $members as $internal_attr => $sub_element ) {
				$attr_to_group[ $internal_attr ] = array(
					'type'    => 'nested',
					'wrapper' => $wrapper,
					'sub'     => $sub_element,
				);
			}
		}

		// Build reverse lookup for repeating: internal_attr → element_name.
		$attr_to_repeating = array();
		foreach ( $repeating as $element_name => $internal_attrs ) {
			foreach ( $internal_attrs as $internal_attr ) {
				$attr_to_repeating[ $internal_attr ] = $element_name;
			}
		}

		$result        = array();
		$group_buckets = array(); // wrapper => [ sub => value, ... ] in-flight bucket.

		// Pre-compute full member count for each group. Used to detect
		// when a bucket has collected every possible sub-element and can
		// be flushed eagerly.
		$group_full_sizes = array();
		foreach ( $nested as $wrapper => $members ) {
			$group_full_sizes[ $wrapper ] = count( $members );
		}

		// Count how many members of each group are actually present in
		// product data. Only counts distinct sub-elements (not repeat
		// instances), so a single partial group still knows when it's
		// "complete" enough to flush. Suffixed keys (##N) are stripped
		// before lookup so second/third instances are also counted.
		$present_counts = array();
		foreach ( $nested as $wrapper => $members ) {
			$seen_subs = array();
			foreach ( $product_data as $attr => $_v ) {
				$base = ProductRepository::strip_dup_suffix( (string) $attr );
				if ( isset( $members[ $base ] ) ) {
					$seen_subs[ $members[ $base ] ] = true;
				}
			}
			$present_counts[ $wrapper ] = count( $seen_subs );
		}

		$flush_bucket = static function ( $wrapper ) use ( &$group_buckets, &$result ) {
			if ( ! empty( $group_buckets[ $wrapper ] ) ) {
				$result[]                  = array( $wrapper => $group_buckets[ $wrapper ] );
				$group_buckets[ $wrapper ] = array();
			}
		};

		foreach ( $product_data as $attr => $value ) {
			// Strip the ##N duplicate-position suffix so lookups keyed
			// by the base merchant attribute name still work for the
			// second, third, … occurrences of the same triplet.
			$base_attr = ProductRepository::strip_dup_suffix( (string) $attr );

			// Check if attribute belongs to a nested group.
			if ( isset( $attr_to_group[ $base_attr ] ) ) {
				$info    = $attr_to_group[ $base_attr ];
				$wrapper = $info['wrapper'];
				$sub     = $info['sub'];

				// If this sub-element slot is already filled, that means
				// we've reached a fresh instance of the group (e.g., a
				// second `section_name`). Flush the in-flight bucket
				// first so both instances are preserved.
				if ( isset( $group_buckets[ $wrapper ][ $sub ] ) ) {
					$flush_bucket( $wrapper );
				}

				if ( ! isset( $group_buckets[ $wrapper ] ) ) {
					$group_buckets[ $wrapper ] = array();
				}

				$group_buckets[ $wrapper ][ $sub ] = $value;

				// When every distinct sub-element that's actually mapped
				// for this group has been collected, flush the group at
				// this position in the output stream. Preserves ordering
				// relative to surrounding non-grouped attributes.
				if ( count( $group_buckets[ $wrapper ] ) >= $present_counts[ $wrapper ] ) {
					$flush_bucket( $wrapper );
				}

				continue;
			}

			// Check if attribute belongs to a repeating group.
			if ( isset( $attr_to_repeating[ $base_attr ] ) ) {
				$element_name = $attr_to_repeating[ $base_attr ];

				// Skip empty values for repeating elements.
				if ( '' !== $value && null !== $value ) {
					$result[] = array( $element_name => $value );
				}

				continue;
			}

			// Aggregate attribute (`shipping`, `tax`) — the resolver
			// returned a LIST of entry arrays built from live WC data.
			// Expand each entry into one nested wrapper block, exactly
			// like a flushed member group. V5: GoogleShipping::get_xml().
			if ( isset( $aggregates[ $base_attr ] ) && is_array( $value ) ) {
				$wrapper = $aggregates[ $base_attr ]['wrapper'];
				$subs    = $aggregates[ $base_attr ]['subs'];

				if ( empty( $value ) ) {
					// No zones/rates survived filtering — keep the tag
					// present-but-empty (customers read a missing tag as
					// a bug; V5's placeholder rendered empty here too).
					$result[ $attr ] = '';
					continue;
				}

				foreach ( $value as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					$block = array();
					foreach ( $subs as $entry_key => $sub_element ) {
						// V5 omits empty region/service sub-tags inside
						// shipping blocks — keep that behaviour.
						if ( isset( $entry[ $entry_key ] ) && '' !== (string) $entry[ $entry_key ] ) {
							$block[ $sub_element ] = $entry[ $entry_key ];
						}
					}

					// Handling/transit/location extras nest into EVERY
					// shipping block (V5 GoogleShipping.php:116). The
					// extras carry g:-prefixed names — only valid inside
					// g:-namespaced wrappers (google/facebook/pinterest),
					// never bing's raw unprefixed blocks.
					if ( 'shipping' === $base_attr && ! empty( $shipping_extras ) && 0 === strpos( $wrapper, 'g:' ) ) {
						$block = array_merge( $block, $shipping_extras );
					}

					if ( ! empty( $block ) ) {
						$result[] = array( $wrapper => $block );
					}
				}

				continue;
			}

			// Non-grouped attribute — pass through with the ORIGINAL key
			// (including any ##N suffix). AttributeNameMapper strips the
			// suffix defensively when it maps to merchant-specific names.
			$result[ $attr ] = $value;
		}

		// Flush any partially collected groups (incomplete sets still
		// get rendered as a wrapper containing whichever subs were set).
		foreach ( array_keys( $group_buckets ) as $wrapper ) {
			$flush_bucket( $wrapper );
		}

		return $result;
	}

	/**
	 * Check if a provider has any grouped attribute definitions.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug.
	 *
	 * @return bool True if provider has grouped attributes.
	 */
	public function has_groups( string $provider ): bool {
		return ! empty( $this->nested_groups[ $provider ] ) || ! empty( $this->repeating_groups[ $provider ] );
	}

	/**
	 * Collapse grouped members in a merchant-attribute list for
	 * DELIMITED formats (CSV/TSV/TXT) headers.
	 *
	 * Google's delimited spec puts each nested group in ONE column
	 * (e.g. `tax(country:region:rate:postal_code:tax_ship)`), so the
	 * per-member entries (tax_country, tax_region, ...) collapse into a
	 * single group-name entry at the position of the FIRST member.
	 * Repeated instances (product_detail triplets mapped twice) still
	 * collapse to one column — instances are comma-joined in the value.
	 *
	 * Repeating groups (images_N, product_highlight_N) are untouched:
	 * they map to legitimately repeated columns. V5 ref:
	 * GoogleStructure::get_csv_structure().
	 *
	 * @since 8.0.0
	 *
	 * @param string[] $mattributes Merchant attribute list (feed config order).
	 * @param string   $provider    Channel provider slug.
	 *
	 * @return string[] Collapsed attribute list.
	 */
	public function collapse_delimited_headers( array $mattributes, string $provider ): array {
		$member_to_group  = $this->delimited_member_lookup( $provider );
		$member_to_joined = $this->joined_member_lookup( $provider );

		if ( empty( $member_to_group ) && empty( $member_to_joined ) ) {
			return $mattributes;
		}

		$collapsed = array();
		$emitted   = array();

		foreach ( $mattributes as $attribute ) {
			$base = ProductRepository::strip_dup_suffix( (string) $attribute );

			$group = $member_to_group[ $base ] ?? $member_to_joined[ $base ] ?? null;

			if ( null === $group ) {
				$collapsed[] = $attribute;
				continue;
			}

			if ( ! isset( $emitted[ $group ] ) ) {
				$emitted[ $group ] = true;
				$collapsed[]       = $group;
			}
		}

		return $collapsed;
	}

	/**
	 * Member attr → joined-list column name for a provider.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug.
	 * @return array<string,string>
	 */
	private function joined_member_lookup( string $provider ): array {
		if ( empty( $this->delimited_joined_groups[ $provider ] ) ) {
			return array();
		}

		$lookup = array();
		foreach ( $this->delimited_joined_groups[ $provider ] as $column => $members ) {
			foreach ( $members as $member ) {
				$lookup[ $member ] = $column;
			}
		}

		return $lookup;
	}

	/**
	 * Collapse grouped members in product data for DELIMITED formats.
	 *
	 * Each nested-group instance becomes a colon-joined string of its
	 * member values in the group's DEFINITION order, with missing
	 * members padded as empty positions (Google's delimited sub-attribute
	 * format is positional). Trailing empty positions are trimmed —
	 * everything before them is already unambiguous. Multiple instances
	 * are comma-joined into the single group column:
	 *
	 *   tax_country=US, tax_region=CA, tax_rate=8.25, tax_ship=y
	 *     → tax = "US:CA:8.25:y"
	 *   shipping_country=US, shipping_price=6.95 USD (partial group)
	 *     → shipping = "US:::6.95 USD"   (region/service padded)
	 *   two product_detail triplets
	 *     → product_detail = "General:Material:Cotton,Fit:Style:Slim"
	 *
	 * NOTE: the positional padding intentionally diverges from V5
	 * (which imploded insertion order without padding) — product
	 * decision (2026-07): partial groups must stay colon-aligned so
	 * Google reads each value in its spec-defined position.
	 *
	 * Column order stays aligned with collapse_delimited_headers()
	 * because the group value lands at the FIRST member's position.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Flat product data (internal names, ##N suffixed dups).
	 * @param string $provider     Channel provider slug.
	 *
	 * @return array Product data with grouped attrs collapsed to single keys.
	 */
	public function build_delimited( array $product_data, string $provider ): array {
		$member_to_group  = $this->delimited_member_lookup( $provider );
		$member_to_joined = $this->joined_member_lookup( $provider );

		if ( empty( $member_to_group ) && empty( $member_to_joined ) ) {
			return $product_data;
		}

		$group_members = $this->delimited_group_members( $provider );
		$json_groups   = isset( $this->delimited_json_groups[ $provider ] )
			? $this->delimited_json_groups[ $provider ]
			: array();

		$result        = array();
		$buckets       = array(); // group => [ member_base => value ] in-flight instance.
		$instances     = array(); // group => [ 'a:b:c', ... ] flushed instances.
		$joined_values = array(); // joined column => [ url, url, ... ].

		$flush = function ( $group ) use ( &$buckets, &$instances, $group_members, $json_groups, $provider ) {
			if ( empty( $buckets[ $group ] ) ) {
				return;
			}

			// JSON-format groups (Meta unit_price): render the instance
			// as an object keyed by the sub-element names.
			if ( in_array( $group, $json_groups, true ) ) {
				$object = array();
				foreach ( $this->nested_groups[ $provider ] as $wrapper => $members ) {
					if ( preg_replace( '/^g:/', '', (string) $wrapper ) !== $group ) {
						continue;
					}
					foreach ( $members as $member => $sub_name ) {
						if ( isset( $buckets[ $group ][ $member ] ) && '' !== $buckets[ $group ][ $member ] ) {
							$object[ $sub_name ] = $buckets[ $group ][ $member ];
						}
					}
				}

				$instances[ $group ][] = (string) wp_json_encode( $object );
				$buckets[ $group ]     = array();
				return;
			}

			// Render in DEFINITION order with empty padding for missing
			// members, then trim trailing empty positions.
			$ordered = array();
			foreach ( $group_members[ $group ] as $member ) {
				$ordered[] = isset( $buckets[ $group ][ $member ] ) ? $buckets[ $group ][ $member ] : '';
			}
			while ( ! empty( $ordered ) && '' === end( $ordered ) ) {
				array_pop( $ordered );
			}

			$instances[ $group ][] = implode( ':', $ordered );
			$buckets[ $group ]     = array();
		};

		foreach ( $product_data as $attr => $value ) {
			$base = ProductRepository::strip_dup_suffix( (string) $attr );

			// Joined-list column member (Meta additional_image_link):
			// every value lands in ONE comma-joined column.
			if ( isset( $member_to_joined[ $base ] ) ) {
				$column = $member_to_joined[ $base ];

				foreach ( (array) $value as $item ) {
					if ( '' !== (string) $item ) {
						$joined_values[ $column ][] = (string) $item;
					}
				}

				// Reserve the column at the first member's position.
				if ( ! array_key_exists( $column, $result ) ) {
					$result[ $column ] = '';
				}
				continue;
			}

			if ( ! isset( $member_to_group[ $base ] ) ) {
				$result[ $attr ] = $value;
				continue;
			}

			$group = $member_to_group[ $base ];

			// Repeated member = a fresh instance of the group.
			if ( isset( $buckets[ $group ][ $base ] ) ) {
				$flush( $group );
			}

			$buckets[ $group ][ $base ] = (string) $value;

			// Reserve the column at the first member's position.
			if ( ! array_key_exists( $group, $result ) ) {
				$result[ $group ] = '';
			}
		}

		foreach ( array_keys( $buckets ) as $group ) {
			$flush( $group );
		}

		foreach ( $instances as $group => $rows ) {
			$result[ $group ] = implode( ',', $rows );
		}

		foreach ( $joined_values as $column => $values ) {
			$result[ $column ] = implode( ',', $values );
		}

		return $result;
	}

	/**
	 * Build the member → group-name lookup for delimited collapsing.
	 *
	 * Group names derive from the nested wrapper with the `g:` XML
	 * prefix stripped (g:tax → tax, g:product_detail → product_detail),
	 * matching the keys the CSV name map / V5 CSV structure use.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug.
	 *
	 * @return array<string,string> internal member attr → group name.
	 */
	private function delimited_member_lookup( string $provider ): array {
		if ( empty( $this->nested_groups[ $provider ] ) ) {
			return array();
		}

		$lookup = array();
		foreach ( $this->nested_groups[ $provider ] as $wrapper => $members ) {
			$group = preg_replace( '/^g:/', '', (string) $wrapper );
			foreach ( array_keys( $members ) as $member ) {
				$lookup[ $member ] = $group;
			}
		}

		return $lookup;
	}

	/**
	 * Ordered member lists per group for delimited positional rendering.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider Channel provider slug.
	 *
	 * @return array<string,string[]> group name → member attrs in definition order.
	 */
	private function delimited_group_members( string $provider ): array {
		if ( empty( $this->nested_groups[ $provider ] ) ) {
			return array();
		}

		$groups = array();
		foreach ( $this->nested_groups[ $provider ] as $wrapper => $members ) {
			$group            = preg_replace( '/^g:/', '', (string) $wrapper );
			$groups[ $group ] = array_keys( $members );
		}

		return $groups;
	}

	/**
	 * Initialize Google Shopping grouped attribute definitions.
	 *
	 * Ported from V5 GoogleStructure::get_grouped_attributes().
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function init_google_groups(): void {
		// Aggregate groups: a single merchant attribute (e.g. `shipping`)
		// that resolves to a LIST of entry arrays built from live
		// WooCommerce data (ShippingResolver zones / TaxResolver rates).
		// Each entry expands into one nested wrapper block in XML —
		// V5 parity: GoogleShipping::get_xml() / Tax::get_xml().
		$this->aggregate_groups['google'] = array(
			'shipping' => array(
				'wrapper' => 'g:shipping',
				'subs'    => array(
					'country' => 'g:country',
					'region'  => 'g:region',
					'service' => 'g:service',
					'price'   => 'g:price',
				),
			),
			'tax'      => array(
				'wrapper' => 'g:tax',
				'subs'    => array(
					'country'  => 'g:country',
					'region'   => 'g:region',
					'rate'     => 'g:rate',
					'tax_ship' => 'g:tax_ship',
				),
			),
		);

		// Nested groups: wrapper → [ internal_attr → sub_element_name ].
		$this->nested_groups['google'] = array(
			'g:tax'                 => array(
				'tax_country' => 'g:country',
				'tax_region'  => 'g:region',
				'tax_rate'    => 'g:rate',
				'tax_ship'    => 'g:tax_ship',
			),
			'g:shipping'            => array(
				'shipping_country' => 'g:country',
				'shipping_region'  => 'g:region',
				'shipping_service' => 'g:service',
				'shipping_price'   => 'g:price',
			),
			'g:installment'         => array(
				'installment_months' => 'g:months',
				'installment_amount' => 'g:amount',
			),
			'g:subscription_cost'   => array(
				'subscription_period'        => 'g:period',
				'subscription_period_length' => 'g:period_length',
				'subscription_amount'        => 'g:amount',
			),
			'g:product_detail'      => array(
				'section_name'    => 'g:section_name',
				'attribute_name'  => 'g:attribute_name',
				'attribute_value' => 'g:attribute_value',
			),
			// EU energy-label / EPREL certification. Google requires this as
			// a nested block: <g:certification> holds authority, name and
			// code sub-attributes. Delimited feeds collapse it to a single
			// positional `certification` column ("EC:EPREL:123456").
			'g:certification'       => array(
				'certification_authority' => 'g:certification_authority',
				'certification_name'      => 'g:certification_name',
				'certification_code'      => 'g:certification_code',
			),
			// Loyalty program member pricing/benefits (2024+).
			'g:loyalty_program'     => array(
				'loyalty_program_label'          => 'g:program_label',
				'loyalty_tier_label'             => 'g:tier_label',
				'loyalty_program_price'          => 'g:price',
				'loyalty_cashback'               => 'g:cashback_for_future_use',
				'loyalty_program_points'         => 'g:loyalty_points',
				'loyalty_member_price_date'      => 'g:member_price_effective_date',
				'loyalty_program_shipping_label' => 'g:shipping_label',
			),
			// Product Q&A (up to 30 pairs; repeat the member group).
			'g:question_and_answer' => array(
				'qa_question' => 'g:question',
				'qa_answer'   => 'g:answer',
			),
			// Related products (accessory/variant relationships).
			'g:related_product'     => array(
				'related_relationship_type' => 'g:relationship_type',
				'related_identifier_type'   => 'g:identifier_type',
				'related_identifier'        => 'g:identifier',
			),
			// Variant-identifying dimension name/value pairs.
			'g:variant_option'      => array(
				'variant_option_name'  => 'g:name',
				'variant_option_value' => 'g:value',
			),
		);

		// Repeating groups: element_name → [ internal_attr_1, internal_attr_2, ... ].
		$this->nested_groups['google_shopping_action'] = $this->nested_groups['google'];
		$this->nested_groups['google_local']           = $this->nested_groups['google'];
		$this->nested_groups['google_local_inventory'] = $this->nested_groups['google'];

		$this->aggregate_groups['google_shopping_action'] = $this->aggregate_groups['google'];
		$this->aggregate_groups['google_local']           = $this->aggregate_groups['google'];
		$this->aggregate_groups['google_local_inventory'] = $this->aggregate_groups['google'];

		// Facebook catalogs consume the Google RSS format — aggregate
		// shipping/tax expand into the same g:-prefixed nested blocks
		// (V5 embeds GoogleShipping's XML string for facebook too).
		$this->aggregate_groups['facebook'] = $this->aggregate_groups['google'];

		// Repeating elements (multiple elements with same tag name).
		$this->repeating_groups['google'] = array(
			'g:product_highlight'     => array(
				'product_highlight_1',
				'product_highlight_2',
				'product_highlight_3',
				'product_highlight_4',
				'product_highlight_5',
				'product_highlight_6',
				'product_highlight_7',
				'product_highlight_8',
				'product_highlight_9',
				'product_highlight_10',
			),
			'g:additional_image_link' => array(
				'images_1',
				'images_2',
				'images_3',
				'images_4',
				'images_5',
				'images_6',
				'images_7',
				'images_8',
				'images_9',
				'images_10',
			),
		);

		$this->repeating_groups['google_shopping_action'] = $this->repeating_groups['google'];
		$this->repeating_groups['google_local']           = $this->repeating_groups['google'];
		$this->repeating_groups['google_local_inventory'] = $this->repeating_groups['google'];
	}

	/**
	 * Initialize Facebook/Meta grouped attribute definitions.
	 *
	 * Facebook XML feeds may use similar grouping for certain attributes.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function init_facebook_groups(): void {
		// @implements F-03 — Facebook unit_price nested group.
		// @implements F-04 — Facebook additional_variant_attribute nested group.
		$this->nested_groups['facebook'] = array(
			'unit_price'                   => array(
				'unit_price_value'    => 'value',
				'unit_price_currency' => 'currency',
				'unit_price_unit'     => 'unit',
			),
			'additional_variant_attribute' => array(
				'additional_variant_label' => 'label',
				'additional_variant_value' => 'value',
			),
		);

		// Meta CSV spec: ONE additional_image_link column, up to 20 URLs
		// comma-joined. images_N therefore collapse into a single
		// delimited column for facebook (unlike Google's repeated
		// same-named columns).
		$this->delimited_joined_groups['facebook'] = array(
			'additional_image_link' => array(
				'images',
				'images_1',
				'images_2',
				'images_3',
				'images_4',
				'images_5',
				'images_6',
				'images_7',
				'images_8',
				'images_9',
				'images_10',
			),
		);

		// Meta CSV spec: unit_price is a JSON object, not colon-positional.
		$this->delimited_json_groups['facebook'] = array( 'unit_price' );

		// Facebook uses similar repeating elements for additional images.
		// The element name IS g:-prefixed — Facebook catalogs consume the
		// Google RSS format (V5 facebook XML map: images_N →
		// g:additional_image_link).
		$this->repeating_groups['facebook'] = array(
			'g:additional_image_link' => array(
				'images_1',
				'images_2',
				'images_3',
				'images_4',
				'images_5',
				'images_6',
				'images_7',
				'images_8',
				'images_9',
				'images_10',
			),
		);
	}

	/**
	 * Initialize Skroutz grouped attribute definitions.
	 *
	 * Skroutz XML allows MULTIPLE <additional_imageurl> tags. The base
	 * additional_imageurl plus additional_imageurl_1..10 each render as a
	 * separate repeated <additional_imageurl> element (spec: multiple
	 * additional images allowed).
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function init_skroutz_groups(): void {
		$this->repeating_groups['skroutz'] = array(
			'additional_imageurl' => array(
				'additional_imageurl',
				'additional_imageurl_1',
				'additional_imageurl_2',
				'additional_imageurl_3',
				'additional_imageurl_4',
				'additional_imageurl_5',
				'additional_imageurl_6',
				'additional_imageurl_7',
				'additional_imageurl_8',
				'additional_imageurl_9',
				'additional_imageurl_10',
			),
		);
	}

	/**
	 * Initialize Pinterest grouped attribute definitions.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	/**
	 * Initialize Bing / Microsoft Merchant Center definitions.
	 *
	 * Bing feeds are FLAT (V5 BingStructure renders XML and CSV
	 * identically, no nested member groups) and their XML uses the
	 * generic wrapper with RAW tag names — no xmlns:g declaration, so
	 * aggregate blocks must use UNPREFIXED sub-elements. Only the
	 * aggregate shipping/tax expansion is registered; its default
	 * template even maps aggregate `shipping` out of the box.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function init_bing_groups(): void {
		$this->aggregate_groups['bing'] = array(
			'shipping' => array(
				'wrapper' => 'shipping',
				'subs'    => array(
					'country' => 'country',
					'region'  => 'region',
					'service' => 'service',
					'price'   => 'price',
				),
			),
			'tax'      => array(
				'wrapper' => 'tax',
				'subs'    => array(
					'country'  => 'country',
					'region'   => 'region',
					'rate'     => 'rate',
					'tax_ship' => 'tax_ship',
				),
			),
		);
	}

	/**
	 * Register Pinterest / Perplexity grouped-attribute definitions.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function init_pinterest_groups(): void {
		// Pinterest CATALOG feeds use Google-compatible grouped
		// attributes and aggregate shipping/tax expansion. The
		// pinterest_rss feed type deliberately gets NOTHING here — V5
		// routes it through CustomStructure (flat rendering, no groups).
		$this->nested_groups['pinterest']    = $this->nested_groups['google'];
		$this->repeating_groups['pinterest'] = $this->repeating_groups['google'];
		$this->aggregate_groups['pinterest'] = $this->aggregate_groups['google'];

		// Perplexity = Google Shopping spec verbatim.
		$this->nested_groups['perplexity']    = $this->nested_groups['google'];
		$this->repeating_groups['perplexity'] = $this->repeating_groups['google'];
		$this->aggregate_groups['perplexity'] = $this->aggregate_groups['google'];
	}
}
