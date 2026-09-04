<?php
/**
 * VariationResolver — Handles variation-specific resolution with parent fallback.
 *
 * When a variation product has an empty attribute value, this resolver
 * fetches the value from the parent variable product. Uses setter
 * injection for the AttributeResolver reference to break the
 * circular dependency (AD-PROD-003).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-11.1, PROD-FRD-11.2
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variation resolver with parent product fallback.
 *
 * @since 8.0.0
 */
class VariationResolver {

	/**
	 * Shadow-verify every Nth memo hit against a fresh resolution.
	 *
	 * @since 8.0.12
	 * @var int
	 */
	const SHADOW_SAMPLE_EVERY = 50;

	/**
	 * Attribute resolver reference (set via setter injection).
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $attribute_resolver = null;

	/**
	 * Memo-hit counter driving the shadow-verification sampling.
	 *
	 * @since 8.0.12
	 * @var int
	 */
	private $hit_count = 0;

	/**
	 * Set the AttributeResolver instance.
	 *
	 * Called during ProductServiceProvider::boot() to break the
	 * circular dependency between VariationResolver and AttributeResolver.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-11.2
	 *
	 * @param AttributeResolver $resolver Attribute resolver instance.
	 *
	 * @return void
	 */
	public function set_attribute_resolver( AttributeResolver $resolver ): void {
		$this->attribute_resolver = $resolver;
	}

	/**
	 * Resolve an attribute from the parent product.
	 *
	 * Fetches the parent variable product and re-resolves the same
	 * attribute mapping against it via AttributeResolver.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-11.1
	 *
	 * @param \WC_Product $variation Variation product.
	 * @param array       $mapping   Attribute mapping configuration.
	 * @param Config      $config    Feed configuration.
	 *
	 * @return mixed Parent product's resolved value or empty string.
	 */
	public function resolve_from_parent( \WC_Product $variation, array $mapping, Config $config ) {
		$parent_id = $variation->get_parent_id();

		if ( 0 === $parent_id ) {
			return '';
		}

		$parent = ProductMemo::get( (int) $parent_id );

		if ( ! $parent ) {
			return '';
		}

		// Re-resolve the same mapping against the parent product.
		if ( null === $this->attribute_resolver ) {
			return '';
		}

		/**
		 * Kill-switch for the parent resolved-VALUE memo.
		 *
		 * The value for a (parent, mapping) pair is identical for every
		 * variation of that parent within a run, so it is resolved once and
		 * reused. Sites with a nondeterministic resolver filter can turn the
		 * memo off here; the shadow verifier also disables it automatically
		 * for the rest of a batch when a sampled hit disagrees with a fresh
		 * resolution.
		 *
		 * @since 8.0.12
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'ctxfeed_parent_value_memo', true ) ) {
			return $this->attribute_resolver->resolve( $parent, $mapping, $config );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- mapping identity hash for the memo key; never unserialized.
		$key = $parent_id . '|' . md5( serialize( $mapping ) );

		if ( ProductMemo::has_value( $key ) ) {
			$memoized = ProductMemo::get_value( $key );

			// Shadow verification: every Nth hit is recomputed fresh. The
			// sampled call always RETURNS the fresh value; a mismatch is
			// logged and fails the memo open for the rest of the batch.
			++$this->hit_count;
			if ( 1 === $this->hit_count % self::SHADOW_SAMPLE_EVERY ) {
				$fresh = $this->attribute_resolver->resolve( $parent, $mapping, $config );
				if ( $fresh !== $memoized ) {
					ProductMemo::disable_values();
					\CTXFeed\V8\Core\Logger::error(
						sprintf(
							'Parent value memo mismatch for parent #%d attr "%s" (variation #%d) — memo disabled for this batch.',
							$parent_id,
							(string) ( $mapping['wc_attr'] ?? '' ),
							$variation->get_id()
						)
					);
				}
				return $fresh;
			}

			return $memoized;
		}

		$fresh = $this->attribute_resolver->resolve( $parent, $mapping, $config );
		ProductMemo::set_value( $key, $fresh );

		return $fresh;
	}
}
