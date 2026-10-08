<?php
/**
 * ChatGptFeedMigrator — one-time update of saved ChatGPT feeds to OpenAI's
 * own product feed format (owner 2026-10-07, CBT-712).
 *
 * Feeds saved before 8.0.33 used Google-style names (id, link, image_link,
 * item_group_id) and often no seller_name, which OpenAI's own format
 * requires. On the first request after the update every saved ChatGPT feed
 * gets the OpenAI names, the required rows it lacks (seller_name = the
 * store name), the variant rows (group_id, listing_has_variations,
 * variant_dict) and a weight unit when weight is mapped. Nothing the
 * merchant mapped is removed; the original rules are backed up.
 *
 * Spec: developers.openai.com/commerce/specs/file-upload/products.
 *
 * @package    CTXFeed
 * @subpackage V8/Migration
 * @since      8.0.33
 */

namespace CTXFeed\V8\Migration;

use CTXFeed\V8\Channel\TemplateDefaults;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Product\ProductRepository;
use CTXFeed\V8\Utility\Sanitizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ChatGptFeedMigrator
 *
 * @since 8.0.33
 */
class ChatGptFeedMigrator {

	/**
	 * Done flag.
	 *
	 * @var string
	 */
	const FLAG_OPTION = 'ctxfeed_chatgpt_openai_migrated_v1';

	/**
	 * Original rules per option name, kept for support/rollback.
	 *
	 * @var string
	 */
	const BACKUP_OPTION = 'ctxfeed_chatgpt_feed_backup';

	/**
	 * Old Google-style name → OpenAI name.
	 *
	 * @var array<string,string>
	 */
	const RENAMES = array(
		'id'                    => 'item_id',
		'link'                  => 'url',
		'image_link'            => 'image_url',
		'item_group_id'         => 'group_id',
		'additional_image_link' => 'additional_image_urls',
		'return_window'         => 'return_deadline_in_days',
		'enable_search'         => 'is_eligible_search',
		'enable_checkout'       => 'is_eligible_checkout',
	);

	/**
	 * The parallel row arrays of a feed's rules.
	 *
	 * @var string[]
	 */
	const ROW_KEYS = array( 'mattributes', 'prefix', 'type', 'attributes', 'default', 'suffix', 'output_type', 'limit' );

	/**
	 * Bring one ChatGPT feed's rules to OpenAI's own format (pure).
	 *
	 * @since 8.0.33
	 *
	 * @param array  $rules    Feed rules.
	 * @param string $currency Currency for an added price row.
	 * @param string $seller   Store name for an added seller_name row.
	 * @return array{rules:array,changed:bool}
	 */
	public static function migrate_rules( array $rules, string $currency, string $seller ): array {
		if ( 'chatgpt' !== ( $rules['provider'] ?? '' ) || ! isset( $rules['mattributes'] ) || ! is_array( $rules['mattributes'] ) ) {
			return array(
				'rules'   => $rules,
				'changed' => false,
			);
		}

		$changed = false;
		foreach ( self::ROW_KEYS as $key ) {
			if ( ! isset( $rules[ $key ] ) || ! is_array( $rules[ $key ] ) ) {
				$rules[ $key ] = array();
			}
			$rules[ $key ] = array_values( $rules[ $key ] );
		}

		// 1. Rename, keeping any "##N" duplicate-position suffix.
		foreach ( $rules['mattributes'] as $i => $name ) {
			$name = (string) $name;
			$base = ProductRepository::strip_dup_suffix( $name );
			if ( isset( self::RENAMES[ $base ] ) ) {
				$rules['mattributes'][ $i ] = self::RENAMES[ $base ] . substr( $name, strlen( $base ) );
				$changed                    = true;
			}
		}

		$present = array_map(
			static function ( $n ) {
				return ProductRepository::strip_dup_suffix( (string) $n );
			},
			$rules['mattributes']
		);
		// Output-type shape: V5 per-row multiselect arrays or plain strings.
		$as_list = ! empty( $rules['output_type'] ) && is_array( reset( $rules['output_type'] ) );

		$add = function ( string $name, string $type, string $attribute, string $fallback, string $suffix, string $output ) use ( &$rules, &$present, &$changed, $as_list ): void {
			if ( in_array( $name, $present, true ) ) {
				return;
			}
			$rows = count( $rules['mattributes'] );
			foreach ( self::ROW_KEYS as $key ) {
				// Keep every row array aligned before appending.
				$rules[ $key ] = array_pad( $rules[ $key ], $rows, 'output_type' === $key ? ( $as_list ? array( '1' ) : '1' ) : '' );
			}
			$rules['mattributes'][] = $name;
			$rules['prefix'][]      = '';
			$rules['type'][]        = $type;
			$rules['attributes'][]  = $attribute;
			$rules['default'][]     = $fallback;
			$rules['suffix'][]      = $suffix;
			$rules['output_type'][] = $as_list ? array( $output ) : $output;
			$rules['limit'][]       = '';
			$present[]              = $name;
			$changed                = true;
		};

		// 2. OpenAI's required fields.
		$add( 'item_id', 'attribute', 'id', '', '', '1' );
		$add( 'title', 'attribute', 'title', '', '', '1' );
		$add( 'description', 'attribute', 'description', '', '', '11' );
		$add( 'url', 'attribute', 'link', '', '', '1' );
		$add( 'brand', 'pattern', '', $seller, '', '1' );
		$add( 'seller_name', 'pattern', '', $seller, '', '1' );
		$add( 'image_url', 'attribute', 'image', '', '', '1' );
		$add( 'availability', 'attribute', 'availability', '', '', '1' );
		$add( 'price', 'attribute', 'price', '', '' !== $currency ? ' ' . $currency : '', '6' );

		// 3. Variants and the weight unit.
		$add( 'group_id', 'attribute', 'item_group_id', '', '', '1' );
		$add( 'listing_has_variations', 'attribute', 'is_variation', '', '', '1' );
		$add( 'variant_dict', 'attribute', 'variant_options', '', '', '1' );
		if ( in_array( 'weight', $present, true ) ) {
			$add( 'item_weight_unit', 'attribute', 'weight_unit', '', '', '1' );
		}

		return array(
			'rules'   => $rules,
			'changed' => $changed,
		);
	}

	/**
	 * Migrate every saved ChatGPT feed once. Fired on plugin update.
	 *
	 * @since 8.0.33
	 * @return int Feeds migrated.
	 */
	public function run(): int {
		if ( get_option( self::FLAG_OPTION ) ) {
			return 0;
		}

		/**
		 * Kill-switch for the one-time ChatGPT → OpenAI format migration.
		 *
		 * @since 8.0.33
		 *
		 * @param bool $migrate Default true.
		 */
		if ( ! apply_filters( 'ctxfeed_chatgpt_migrate_openai_format', true ) ) {
			return 0;
		}

		$currency = TemplateDefaults::get_currency();
		$seller   = TemplateDefaults::default_brand();
		$backups  = get_option( self::BACKUP_OPTION, array() );
		$backups  = is_array( $backups ) ? $backups : array();
		$migrated = 0;

		foreach ( $this->feed_option_names() as $option_name ) {
			$feed = Sanitizer::safe_unserialize( Sanitizer::safe_unserialize( get_option( $option_name ) ) );
			if ( ! is_array( $feed ) || ! is_array( $feed['feedrules'] ?? null ) ) {
				continue;
			}

			$result = self::migrate_rules( $feed['feedrules'], $currency, $seller );
			if ( ! $result['changed'] ) {
				continue;
			}

			if ( ! isset( $backups[ $option_name ] ) ) {
				$backups[ $option_name ] = $feed['feedrules'];
				update_option( self::BACKUP_OPTION, $backups, false );
			}

			$feed['feedrules'] = $result['rules'];
			update_option( $option_name, $feed, false );

			// The wf_config mirror holds the same rules (shared V5/V8 config).
			$slug   = substr( $option_name, strlen( 'wf_feed_' ) );
			$mirror = Sanitizer::safe_unserialize( get_option( 'wf_config' . $slug ) );
			if ( is_array( $mirror ) && 'chatgpt' === ( $mirror['provider'] ?? '' ) ) {
				update_option( 'wf_config' . $slug, $result['rules'], false );
			}

			++$migrated;
		}

		update_option( self::FLAG_OPTION, time(), false );

		if ( $migrated > 0 ) {
			Logger::info( sprintf( 'CBT-712: %d ChatGPT feed(s) moved to OpenAI\'s own product feed format; originals kept in %s.', $migrated, self::BACKUP_OPTION ) );
		}

		return $migrated;
	}

	/**
	 * Every saved feed option name.
	 *
	 * @return string[]
	 */
	protected function feed_option_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Feeds live one per non-autoloaded wp_options row under a shared prefix; get_option() cannot enumerate by prefix. Runs once per update.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'wf_feed_' ) . '%'
			)
		);

		return is_array( $names ) ? $names : array();
	}
}
