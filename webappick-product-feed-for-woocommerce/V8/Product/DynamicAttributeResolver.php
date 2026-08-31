<?php
/**
 * DynamicAttributeResolver — Resolves user-defined dynamic attributes.
 *
 * Handles attributes prefixed with "wf_dattribute_" that produce
 * conditional output based on product data. Each dynamic attribute
 * stores multiple condition rows evaluated against product attributes,
 * with support for AND/OR logic, arithmetic operations on price/weight,
 * and a default fallback.
 *
 * Example: A dynamic attribute "sale_label" might output "On Sale!"
 * when sale_price != empty, or "Regular" otherwise.
 *
 * Dynamic attribute configs are stored in wp_options as:
 *   wf_dattribute_sale_label → {
 *     wfDAttributeCode: "sale_label",
 *     attribute: ["sale_price"],
 *     condition: ["!="],
 *     compare: [""],
 *     type: ["pattern"],
 *     value_pattern: ["On Sale!"],
 *     value_attribute: [""],
 *     prefix: [""],
 *     suffix: [""],
 *     logical_condition: [],
 *     default_type: "pattern",
 *     default_value_pattern: "Regular"
 *   }
 *
 * Uses setter injection for AttributeResolver to break the circular
 * dependency (same pattern as VariationResolver, AttributeMappingResolver).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use DateTime;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic attribute resolver.
 *
 * @since 8.0.0
 */
class DynamicAttributeResolver {

	/**
	 * Prefix used to identify dynamic attribute keys.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PREFIX = 'wf_dattribute_';

	/**
	 * Attribute resolver reference (set via setter injection).
	 *
	 * Used to resolve source attributes and output attribute values
	 * within condition rows.
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver = null;

	/**
	 * Internal cache of dynamic attribute configurations fetched from wp_options.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $config_cache = array();

	/**
	 * Attribute names currently being resolved — recursion cycle detector.
	 *
	 * Keyed by full attribute name. An entry means the name is on the current
	 * resolution stack; being asked to resolve a name already present is a
	 * circular reference (direct, or via another dynamic attribute / mapping
	 * that points back here). Added on entry, removed in `finally`, so
	 * sequential reuse is not mistaken for a cycle.
	 *
	 * @since 8.0.0
	 * @var array<string,bool>
	 */
	private $resolving = array();

	/**
	 * Set the AttributeResolver instance.
	 *
	 * Called during ProductServiceProvider::boot() to break the
	 * circular dependency: AttributeResolver → DynamicAttributeResolver → AttributeResolver.
	 *
	 * @since 8.0.0
	 *
	 * @param AttributeResolver $resolver Attribute resolver instance.
	 *
	 * @return void
	 */
	public function set_attribute_resolver( AttributeResolver $resolver ): void {
		$this->attribute_resolver = $resolver;
	}

	/**
	 * Check if an attribute key is a dynamic attribute.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute key.
	 *
	 * @return bool True if the attribute starts with the dynamic attribute prefix.
	 */
	public function is_dynamic_attribute( string $attr ): bool {
		return 0 === strpos( $attr, self::PREFIX );
	}

	/**
	 * Resolve a dynamic attribute value for a product.
	 *
	 * Fetches the dynamic attribute config from wp_options, evaluates each
	 * condition row against the product data, combines results using AND/OR
	 * logical operators, and applies the default fallback if needed.
	 *
	 * Mirrors V5 DynamicAttributes::getDynamicAttributeValue() exactly.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $attr          Attribute key (e.g., "wf_dattribute_sale_label").
	 * @param Config      $config        Feed configuration.
	 * @param string      $merchant_attr Channel field name (e.g., "g:price").
	 *                                   Optional for back-compat with older
	 *                                   callers and tests; production wiring
	 *                                   threads it through from feed config.
	 *                                   Used as positional arg #4 of the
	 *                                   `woo_feed_after_dynamic_attribute_value`
	 *                                   filter for V5 hook parity.
	 *                                   PROD-FRD-10.5.
	 *
	 * @return string Resolved value or empty string.
	 */
	public function resolve( \WC_Product $product, string $attr, Config $config, string $merchant_attr = '' ): string {
		if ( null === $this->attribute_resolver ) {
			return '';
		}

		// Recursion cycle guard. A dynamic attribute that references itself —
		// directly, or through another dynamic attribute / mapping that points
		// back here — would recurse until PHP exhausts the stack and fatals
		// the whole feed run. Detect re-entry for the same attribute still on
		// the resolution stack and resolve it to empty instead.
		if ( isset( $this->resolving[ $attr ] ) ) {
			Logger::warning(
				'Circular dynamic attribute reference detected; resolved to empty.',
				array( 'attribute' => $attr )
			);
			return '';
		}

		$this->resolving[ $attr ] = true;

		try {
			return $this->evaluate( $product, $attr, $config, $merchant_attr );
		} finally {
			// Always clear the in-progress marker, even on an early return or
			// a thrown error, so a later resolve of the same name is not
			// wrongly treated as a cycle.
			unset( $this->resolving[ $attr ] );
		}
	}

	/**
	 * Evaluate a dynamic attribute's condition rows against a product.
	 *
	 * The full V5-parity evaluation, extracted from {@see resolve()} so the
	 * public entry point can wrap it in the recursion cycle guard. Not called
	 * directly — always reached through resolve() so the guard is in effect.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product       WooCommerce product.
	 * @param string      $attr          Attribute key (e.g., "wf_dattribute_sale_label").
	 * @param Config      $config        Feed configuration.
	 * @param string      $merchant_attr Channel field name for the V5 hook (arg #4).
	 *
	 * @return string Resolved value or empty string.
	 */
	private function evaluate( \WC_Product $product, string $attr, Config $config, string $merchant_attr ): string {
		// Fetch and cache the dynamic attribute configuration.
		$da_config = $this->get_da_config( $attr );

		if ( empty( $da_config ) ) {
			return '';
		}

		$da_code   = isset( $da_config['wfDAttributeCode'] ) ? $da_config['wfDAttributeCode'] : '';
		$attribute = isset( $da_config['attribute'] ) ? (array) $da_config['attribute'] : array();
		$condition = isset( $da_config['condition'] ) ? (array) $da_config['condition'] : array();
		$compare   = isset( $da_config['compare'] ) ? (array) $da_config['compare'] : array();
		$type      = isset( $da_config['type'] ) ? (array) $da_config['type'] : array();

		$logical_condition = isset( $da_config['logical_condition'] ) ? (array) $da_config['logical_condition'] : array();

		$prefix = isset( $da_config['prefix'] ) ? (array) $da_config['prefix'] : array();
		$suffix = isset( $da_config['suffix'] ) ? (array) $da_config['suffix'] : array();

		$value_attribute = isset( $da_config['value_attribute'] ) ? (array) $da_config['value_attribute'] : array();
		$value_pattern   = isset( $da_config['value_pattern'] ) ? (array) $da_config['value_pattern'] : array();

		$default_type            = isset( $da_config['default_type'] ) ? $da_config['default_type'] : 'attribute';
		$default_value_attribute = isset( $da_config['default_value_attribute'] ) ? $da_config['default_value_attribute'] : '';
		$default_value_pattern   = isset( $da_config['default_value_pattern'] ) ? $da_config['default_value_pattern'] : '';

		$result       = '';
		$result_array = array();

		// Check if attribute code exists and has condition rows.
		if ( $da_code && count( $attribute ) ) {
			foreach ( $attribute as $key => $name ) {
				if ( ! empty( $name ) ) {

					// Reset result for logical conditions (V5 exact behavior).
					if ( ! empty( $logical_condition ) || in_array( '&&', $logical_condition ) ) { // phpcs:ignore WordPress.PHP.StrictInArray -- V5 parity, pinned by unit tests: $logical_condition is built from raw feedrules values, so its elements are not guaranteed to be strings. Adding the strict flag would change which dynamic-attribute rules match and alter live feed output.
						$result = '';
					}

					// Step 1: Resolve the source attribute (left operand).
					$conditionName = $this->resolve_attribute_value( $product, $name, $config );

					// Step 2: Strip weight unit for numeric comparison.
					if ( 'weight' === $name ) {
						$unit = ' ' . get_option( 'woocommerce_weight_unit' );
						if ( ! empty( $unit ) ) {
							$conditionName = (float) str_replace( $unit, '', $conditionName );
						}
					}

					// Step 3: Get comparison value and operator.
					$conditionCompare  = isset( $compare[ $key ] ) ? $compare[ $key ] : '';
					$conditionOperator = isset( $condition[ $key ] ) ? $condition[ $key ] : '';

					if ( ! empty( $conditionCompare ) ) {
						$conditionCompare = trim( $conditionCompare );
					}

					// Step 4: Get the output value based on type.
					$conditionValue = '';
					$rowType        = isset( $type[ $key ] ) ? $type[ $key ] : '';

					if ( 'pattern' === $rowType ) {
						$conditionValue = isset( $value_pattern[ $key ] ) ? $value_pattern[ $key ] : '';
					} elseif ( 'attribute' === $rowType ) {
						$output_attr    = isset( $value_attribute[ $key ] ) ? $value_attribute[ $key ] : '';
						$conditionValue = $this->resolve_attribute_value( $product, $output_attr, $config );
					} elseif ( 'remove' === $rowType ) {
						$conditionValue = '';
					}

					// Step 5: Evaluate condition inline (V5 exact behavior).
					// V5 modifies $conditionName for date comparisons, so we
					// keep this inline instead of extracting to a method.
					switch ( $conditionOperator ) {
						case '==':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName == $conditionCompare ) { // phpcs:ignore WordPress.PHP.StrictComparisons, Universal.Operators.StrictComparisons.LooseEqual -- V5 parity, pinned by unit tests: the operands are loosely-typed rule values. $conditionName can be a string, a (float) cast from a unit-stripped number, or an int timestamp from strtotime() a few lines above, while $conditionCompare stays the raw string the merchant typed. A strict comparison would make every numeric and date rule stop matching.
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case '!=':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName != $conditionCompare ) { // phpcs:ignore WordPress.PHP.StrictComparisons, Universal.Operators.StrictComparisons.LooseNotEqual -- V5 parity, pinned by unit tests: the operands are loosely-typed rule values. $conditionName can be a string, a (float) cast from a unit-stripped number, or an int timestamp from strtotime() a few lines above, while $conditionCompare stays the raw string the merchant typed. A strict comparison would make every numeric and date rule stop matching.
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case '>=':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName >= $conditionCompare ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case '<=':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName <= $conditionCompare ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case '>':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName > $conditionCompare ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case '<':
							if ( $this->validate_date( $conditionName ) && $this->validate_date( $conditionCompare ) ) {
								$conditionName    = strtotime( $conditionName );
								$conditionCompare = strtotime( $conditionCompare );
							}
							if ( $conditionName < $conditionCompare ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case 'contains':
							if ( false !== stripos( (string) $conditionName, (string) $conditionCompare ) ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case 'nContains':
							if ( false === stripos( (string) $conditionName, (string) $conditionCompare ) ) {
								$result = $this->price_format( $name, $conditionName, $conditionValue );
								if ( '' !== $result ) {
									$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
								}
							}
							break;

						case 'between':
							$compare_items = explode( ',', $conditionCompare );

							if ( isset( $compare_items[1] ) && is_numeric( $compare_items[0] ) && is_numeric( $compare_items[1] ) ) {
								if ( $conditionName >= $compare_items[0] && $conditionName <= $compare_items[1] ) {
									$result = $this->price_format( $name, $conditionName, $conditionValue );
									if ( '' !== $result ) {
										$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
									}
								}
							} elseif ( isset( $compare_items[1] ) && $this->validate_date( $compare_items[0] ) && $this->validate_date( $compare_items[1] ) ) {
								if ( $conditionName >= $compare_items[0] && $conditionName <= $compare_items[1] ) {
									$result = $this->price_format( $name, $conditionName, $conditionValue );
									if ( '' !== $result ) {
										$result = ( isset( $prefix[ $key ] ) ? $prefix[ $key ] : '' ) . $result . ( isset( $suffix[ $key ] ) ? $suffix[ $key ] : '' );
									}
								}
							} else {
								$result = '';
							}
							break;

						default:
							break;
					}               
				}

				// Store row result for logical combination.
				$result_array[ $key ] = array(
					'conditionName'     => isset( $conditionName ) ? $conditionName : '',
					'result'            => $result,
					'condition'         => isset( $logical_condition[ $key ] ) ? $logical_condition[ $key ] : '',
					'name'              => $name,
					'conditionOperator' => isset( $conditionOperator ) ? $conditionOperator : '',
					'conditionCompare'  => isset( $conditionCompare ) ? $conditionCompare : '',
				);
			}
		}

		// Step 6: Combine results using logical conditions.
		if ( empty( $logical_condition ) || ! in_array( '&&', $logical_condition ) ) { // phpcs:ignore WordPress.PHP.StrictInArray -- V5 parity, pinned by unit tests: $logical_condition is built from raw feedrules values, so its elements are not guaranteed to be strings. Adding the strict flag would change which dynamic-attribute rules match and alter live feed output.
			// Pure OR or no logical conditions.
			// V5 bug: $result only holds the last iteration's value because
			// the loop resets it. Fix: scan result_array for first match.
			$result = '';
			foreach ( $result_array as $value ) {
				if ( '' !== $value['result'] ) {
					$result = $value['result'];
					break;
				}
			}
		} else {
			$new_logical_condition = $logical_condition;
			array_shift( $new_logical_condition );

			if ( ! in_array( '||', $new_logical_condition ) ) { // phpcs:ignore WordPress.PHP.StrictInArray -- V5 parity, pinned by unit tests: $logical_condition is built from raw feedrules values, so its elements are not guaranteed to be strings. Adding the strict flag would change which dynamic-attribute rules match and alter live feed output.
				// Pure AND — all rows must match.
				foreach ( $result_array as $key => $value ) {
					if ( '' === $value['result'] ) {
						$result = '';
						break;
					} else {
						$result = $value['result'];
					}
				}
			} else {
				// Mixed AND/OR — left-to-right evaluation.
				foreach ( $result_array as $key => $value ) {
					if ( 0 === $key ) {
						continue;
					} elseif ( '&&' === $value['condition'] ) {
						if ( '' !== $value['result'] && '' !== $result_array[ $key - 1 ]['result'] ) {
							$result = $value['result'];
						} else {
							$result = '';
						}
					} elseif ( '||' === $value['condition'] ) {
						if ( '' !== $value['result'] ) {
							$result = $value['result'];
						}
					}
				}
			}
		}

		// Step 7: Apply default fallback if result is empty.
		if ( '' === $result ) {
			if ( 'pattern' === $default_type ) {
				$result = $default_value_pattern;
			} elseif ( 'attribute' === $default_type ) {
				if ( ! empty( $default_value_attribute ) ) {
					$result = $this->resolve_attribute_value( $product, $default_value_attribute, $config );
				}
			} elseif ( 'remove' === $default_type ) {
				$result = '';
			}
		}

		/**
		 * Filter the resolved dynamic attribute value.
		 *
		 * Compatible with V5 hook: woo_feed_after_dynamic_attribute_value.
		 *
		 * Positional argument shape pinned to V5:
		 *   (string) $result            — resolved value
		 *   (\WC_Product) $product       — product object
		 *   (array) $attribute           — source attribute names array
		 *   (string) $merchant_attribute — channel field name (e.g. "g:price")
		 *   (Config) $config             — feed configuration
		 *
		 * V5 line 288 passed `$merchant_attribute` (channel field) as arg #4;
		 * a V8 version of this resolver previously passed `$attr` (the full
		 * `wf_dattribute_*` key) which broke third-party filters that
		 * introspected the channel name. Restored to V5 semantics in
		 * PROD-FRD-10.5. When the caller doesn't have a merchant attr (e.g.
		 * standalone tests), an empty string is passed — V5 also accepts
		 * an empty channel name.
		 *
		 * @since 8.0.0
		 */
		$result = apply_filters(
			'woo_feed_after_dynamic_attribute_value',
			$result,
			$product,
			$attribute,
			$merchant_attr,
			$config
		);

		return (string) $result;
	}

	/**
	 * Resolve a single attribute value using the AttributeResolver.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param string      $attr    Attribute name to resolve.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return string Resolved attribute value.
	 */
	private function resolve_attribute_value( \WC_Product $product, string $attr, Config $config ): string {
		$mapping = array(
			'type'    => 'attribute',
			'wc_attr' => $attr,
			'default' => '',
		);

		return (string) $this->attribute_resolver->resolve( $product, $mapping, $config );
	}

	/**
	 * Apply price/weight arithmetic formatting.
	 *
	 * When the source attribute is a price or weight field, the output
	 * value can contain arithmetic operators:
	 * - "+10%" → add 10% to source value
	 * - "-5"   → subtract 5 from source value
	 * - "*2"   → multiply source by 2
	 * - "/3"   → divide source by 3
	 *
	 * For non-price/weight attributes, returns the value as-is.
	 * Mirrors V5 ProductHelper::price_format() exactly.
	 *
	 * @since 8.0.0
	 *
	 * @param string $name            Source attribute name.
	 * @param mixed  $condition_name  Resolved source attribute value.
	 * @param string $result          Output value (may contain operators).
	 *
	 * @return string Formatted result.
	 */
	private function price_format( string $name, $condition_name, $result ) {
		// Only apply arithmetic for price/weight attributes.
		if ( false !== strpos( $name, 'price' ) || false !== strpos( $name, 'weight' ) ) {
			// Percentage operations (check % first).
			if ( false !== strpos( $result, '+' ) && false !== strpos( $result, '%' ) ) {
				$result = str_replace( '+', '', $result );
				$result = str_replace( '%', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name + ( $condition_name * $result / 100 );
				}
			} elseif ( false !== strpos( $result, '-' ) && false !== strpos( $result, '%' ) ) {
				$result = str_replace( '-', '', $result );
				$result = str_replace( '%', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name - ( $condition_name * $result / 100 );
				}
			} elseif ( false !== strpos( $result, '*' ) && false !== strpos( $result, '%' ) ) {
				$result = str_replace( '*', '', $result );
				$result = str_replace( '%', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name * $result / 100;
				}
			} elseif ( false !== strpos( $result, '+' ) ) {
				$result = str_replace( '+', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name + $result;
				}
			} elseif ( false !== strpos( $result, '-' ) ) {
				$result = str_replace( '-', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name - $result;
				}
			} elseif ( false !== strpos( $result, '*' ) ) {
				$result = str_replace( '*', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name * $result;
				}
			} elseif ( false !== strpos( $result, '/' ) ) {
				$result = str_replace( '/', '', $result );
				$result = trim( $result );
				if ( is_numeric( $result ) ) {
					$result = $condition_name / $result;
				}
			}
		}

		return $result;
	}

	/**
	 * Get the dynamic attribute configuration from wp_options, with caching.
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Full attribute key (e.g., "wf_dattribute_sale_label").
	 *
	 * @return array|false Config array or false if not found.
	 */
	private function get_da_config( string $attr ) {
		if ( isset( $this->config_cache[ $attr ] ) ) {
			return $this->config_cache[ $attr ];
		}

		$da_config = \CTXFeed\V8\Utility\Sanitizer::safe_unserialize( get_option( $attr, false ) );

		$this->config_cache[ $attr ] = $da_config;

		return $da_config;
	}

	/**
	 * Validate a date string against Y-m-d format.
	 *
	 * Used to auto-detect dates for timestamp comparison in conditions.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed  $date   Value to validate.
	 * @param string $format Date format to check against.
	 *
	 * @return bool True if the value is a valid date string.
	 */
	private function validate_date( $date, string $format = 'Y-m-d' ): bool {
		if ( ! is_string( $date ) ) {
			return false;
		}

		$date_time = DateTime::createFromFormat( $format, $date );

		return $date_time && $date_time->format( $format ) === $date;
	}

	/**
	 * Clear the internal caches.
	 *
	 * Called between feed generation runs if the resolver is reused.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->config_cache = array();
	}
}
