<?php
/**
 * BrandDefaultHeal — one-time upgrade sweep clearing the hidden brand
 * fallback that 8.0.17–8.0.22 template defaults wrote into saved feeds.
 *
 * Those releases converted every brand/manufacturer template row into an
 * Attribute row sourcing `wc_brand_parent` while KEEPING the static store
 * brand (site title) in the row's `default`. The mapping table only renders
 * `default` for Text rows, so the fallback was invisible and uncleared:
 * every product without a Brands term shipped the site title as its brand
 * — literally "localhost" on shops provisioned from a local template
 * (wp.org "Brand exported as Localhost", HelpScout #69131, CBT-594).
 *
 * Only that exact 8.0.17 template signature is touched (type=attribute,
 * source=wc_brand_parent, brand-ish merchant name, non-empty default): the
 * UI could never write it, so nothing a user typed is lost.
 *
 * @package CTXFeed\V8\Feed
 * @since   8.0.23
 */

namespace CTXFeed\V8\Feed;

defined( 'ABSPATH' ) || exit;

use CTXFeed\V8\Channel\TemplateDefaults;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Utility\Sanitizer;

/**
 * Class BrandDefaultHeal
 *
 * @since 8.0.23
 */
class BrandDefaultHeal {

	/**
	 * Flag option marking the sweep as done (bump the suffix to force a re-run).
	 *
	 * @var string
	 */
	const FLAG_OPTION = 'ctxfeed_brand_default_healed_v1';

	/**
	 * Clear the hidden brand fallback on the 8.0.17-shaped rows of one
	 * feedrules array. Pure — no WordPress calls.
	 *
	 * @since 8.0.23
	 *
	 * @param array $rules Parallel feedrules arrays (mattributes / attributes / type / default …).
	 * @return array{rules: array, changed: int} Healed rules and the number of rows cleared.
	 */
	public static function heal_rules( array $rules ): array {
		$changed = 0;

		$mattributes = isset( $rules['mattributes'] ) && is_array( $rules['mattributes'] ) ? $rules['mattributes'] : array();
		if ( empty( $mattributes ) || ! isset( $rules['default'] ) || ! is_array( $rules['default'] ) ) {
			return array(
				'rules'   => $rules,
				'changed' => 0,
			);
		}

		foreach ( $mattributes as $i => $mattr ) {
			if ( '' === trim( (string) ( $rules['default'][ $i ] ?? '' ) ) ) {
				continue;
			}
			if ( 'attribute' !== (string) ( $rules['type'][ $i ] ?? 'attribute' ) ) {
				continue;
			}
			if ( 'wc_brand_parent' !== (string) ( $rules['attributes'][ $i ] ?? '' ) ) {
				continue;
			}
			if ( ! TemplateDefaults::is_brand_column( (string) $mattr ) ) {
				continue;
			}

			$rules['default'][ $i ] = '';
			++$changed;
		}

		return array(
			'rules'   => $rules,
			'changed' => $changed,
		);
	}

	/**
	 * Sweep every saved feed once. Idempotent; guarded by {@see FLAG_OPTION}.
	 *
	 * Reads and writes the raw `wf_feed_{slug}` option rather than going
	 * through Config → save_config, so the stored shape (V5 `feedrules`
	 * wrapper or bare rules, serialized or array) is preserved byte-for-byte
	 * apart from the cleared defaults and Config's render-time row dropping
	 * never gets persisted as a side effect.
	 *
	 * @since 8.0.23
	 *
	 * @param FeedManager $manager Feed manager (enumerates the feed slugs).
	 * @return int Number of feeds rewritten.
	 */
	public function run( FeedManager $manager ): int {
		if ( get_option( self::FLAG_OPTION ) ) {
			return 0;
		}

		$feeds_changed = 0;
		$rows_changed  = 0;

		foreach ( $manager->get_all_feed_names() as $name ) {
			$option_key = 'wf_feed_' . $name;
			$data       = Sanitizer::safe_unserialize( get_option( $option_key ) );

			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}

			$wrapped = isset( $data['feedrules'] ) && is_array( $data['feedrules'] );
			$rules   = $wrapped ? $data['feedrules'] : $data;

			$healed = self::heal_rules( $rules );
			if ( 0 === $healed['changed'] ) {
				continue;
			}

			if ( $wrapped ) {
				$data['feedrules'] = $healed['rules'];
			} else {
				$data = $healed['rules'];
			}

			update_option( $option_key, $data, false );
			++$feeds_changed;
			$rows_changed += $healed['changed'];
		}

		update_option( self::FLAG_OPTION, time(), false );

		if ( $feeds_changed > 0 ) {
			Logger::info(
				sprintf( 'CBT-594: cleared the hidden brand fallback on %d row(s) across %d feed(s).', $rows_changed, $feeds_changed ),
				array(
					'feeds' => $feeds_changed,
					'rows'  => $rows_changed,
				)
			);
		}

		return $feeds_changed;
	}
}
