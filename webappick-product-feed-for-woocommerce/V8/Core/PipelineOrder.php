<?php
/**
 * PipelineOrder — position-aware edits of an ordered instance list.
 *
 * The free engine exposes its ordered chains (product filters, transforms)
 * through filters (`ctxfeed_product_filters`, `ctxfeed_transform_pipeline`).
 * The Pro plugin — and any third party — needs to place an instance
 * relative to an existing stage ("after OutputTypeTransform", "last")
 * without knowing the numeric index. These helpers locate a stage by class
 * name (`instanceof`) and splice around it (CBT-642).
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.27
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordered-list helpers keyed by class name.
 *
 * @since 8.0.27
 */
final class PipelineOrder {

	/**
	 * Insert an item immediately after the first instance of a class.
	 *
	 * Appends when no instance of the class is present.
	 *
	 * @since 8.0.27
	 *
	 * @param array  $items      Ordered instances.
	 * @param string $class_name Fully-qualified class or interface name to anchor on.
	 * @param mixed  $item       Instance to insert.
	 *
	 * @return array
	 */
	public static function insert_after( array $items, string $class_name, $item ): array {
		$index = self::index_of( $items, $class_name );
		if ( null === $index ) {
			$items[] = $item;
			return array_values( $items );
		}
		array_splice( $items, $index + 1, 0, array( $item ) );
		return array_values( $items );
	}

	/**
	 * Insert an item immediately before the first instance of a class.
	 *
	 * Prepends when no instance of the class is present.
	 *
	 * @since 8.0.27
	 *
	 * @param array  $items      Ordered instances.
	 * @param string $class_name Fully-qualified class or interface name to anchor on.
	 * @param mixed  $item       Instance to insert.
	 *
	 * @return array
	 */
	public static function insert_before( array $items, string $class_name, $item ): array {
		$index = self::index_of( $items, $class_name );
		if ( null === $index ) {
			array_unshift( $items, $item );
			return array_values( $items );
		}
		array_splice( $items, $index, 0, array( $item ) );
		return array_values( $items );
	}

	/**
	 * Remove every instance of a class.
	 *
	 * @since 8.0.27
	 *
	 * @param array  $items      Ordered instances.
	 * @param string $class_name Fully-qualified class or interface name.
	 *
	 * @return array
	 */
	public static function remove( array $items, string $class_name ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $class_name ) {
					return ! ( $item instanceof $class_name );
				}
			)
		);
	}

	/**
	 * Index of the first instance of a class, or null.
	 *
	 * @since 8.0.27
	 *
	 * @param array  $items      Ordered instances.
	 * @param string $class_name Fully-qualified class or interface name.
	 *
	 * @return int|null
	 */
	public static function index_of( array $items, string $class_name ): ?int {
		$i = 0;
		foreach ( array_values( $items ) as $item ) {
			if ( $item instanceof $class_name ) {
				return $i;
			}
			++$i;
		}
		return null;
	}
}
