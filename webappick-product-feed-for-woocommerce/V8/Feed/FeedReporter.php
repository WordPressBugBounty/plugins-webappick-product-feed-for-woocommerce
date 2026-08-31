<?php
/**
 * FeedReporter — On-demand feed report generator.
 *
 * Generates a Feed Summary + Attribute Completeness report when the
 * user clicks the Report action. Not part of the generation pipeline.
 *
 * Report data:
 * 1. Feed Summary: name, channel, file type, file size, product count,
 *    last updated, generation time, health score.
 * 2. Attribute Completeness: samples products and checks which mapped
 *    attributes resolve to empty values, returning per-attribute fill rates.
 *
 * Saves report as a .txt file in /wp-content/uploads/woo-feed/logs/
 * and returns structured data for the frontend modal.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductQuery;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-demand feed report generator.
 *
 * @since 8.0.0
 */
class FeedReporter {

	/**
	 * Max products to sample for attribute completeness.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const SAMPLE_SIZE = 50;

	/**
	 * Generate a report for a feed.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug identifier.
	 * @param array  $feed_data Full feed data from find_feed() (includes feedrules, config, etc.).
	 *
	 * @return array Report data with 'summary' and 'completeness' keys.
	 */
	public function generate( string $feed_slug, array $feed_data ): array {
		$summary      = $this->build_summary( $feed_slug, $feed_data );
		$completeness = $this->build_completeness( $feed_slug, $feed_data );

		$report = array(
			'summary'      => $summary,
			'completeness' => $completeness,
			'generated_at' => current_time( 'Y-m-d H:i:s' ),
		);

		// Save as .txt file.
		$this->save_report_file( $feed_slug, $report );

		return $report;
	}

	/**
	 * Build the Feed Summary section.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param array  $feed_data Feed data.
	 *
	 * @return array Summary key-value pairs.
	 */
	private function build_summary( string $feed_slug, array $feed_data ): array {
		$rules    = isset( $feed_data['feedrules'] ) ? $feed_data['feedrules'] : array();
		$config   = isset( $feed_data['config'] ) ? $feed_data['config'] : array();
		$provider = isset( $rules['provider'] ) ? $rules['provider'] : 'custom';

		// File info.
		$feed_url  = isset( $config['url'] ) ? $config['url'] : ( isset( $feed_data['feed_url'] ) ? $feed_data['feed_url'] : '' );
		$file_path = '';
		$file_size = 0;

		if ( ! empty( $feed_url ) ) {
			$upload_dir = wp_get_upload_dir();
			$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $feed_url );
			if ( file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
			}
		}

		// Product count from progress transient or re-count.
		$manager  = new FeedManager();
		$progress = $manager->get_progress( $feed_slug );

		$total_products = (int) $progress['total'];
		if ( 0 === $total_products ) {
			// Progress transient may have expired — re-count from config.
			$feed_config = Config::from_feed_name( $feed_slug );
			if ( $feed_config ) {
				$query          = new ProductQuery();
				$total_products = $query->get_total_count( $feed_config );
			}
		}

		// Generation timing.
		$started_at = $progress['started_at'] ?? '';
		$updated_at = $progress['updated_at'] ?? '';
		$duration   = '';
		if ( ! empty( $started_at ) && ! empty( $updated_at ) ) {
			$start_ts = strtotime( $started_at );
			$end_ts   = strtotime( $updated_at );
			if ( $start_ts && $end_ts ) {
				$diff     = $end_ts - $start_ts;
				$duration = $diff < 60 ? $diff . 's' : round( $diff / 60, 1 ) . 'min';
			}
		}

		// Health score.
		$health_score = 'N/A';
		$feed_config  = Config::from_feed_name( $feed_slug );
		if ( $feed_config ) {
			try {
				$health       = new FeedHealth();
				$health_data  = $health->analyze( $feed_slug, $feed_config );
				$health_score = $health_data['score'] . '%';
			} catch ( \Throwable $e ) {
				$health_score = 'Error';
			}
		}

		return array(
			'feed_name'       => isset( $rules['filename'] ) ? $rules['filename'] : $feed_slug,
			'channel'         => ucwords( str_replace( '_', ' ', $provider ) ),
			'file_type'       => isset( $rules['feedType'] ) ? strtoupper( $rules['feedType'] ) : 'XML',
			'file_size'       => $file_size > 0 ? size_format( $file_size ) : 'N/A',
			'total_products'  => $total_products,
			'last_updated'    => isset( $config['last_updated'] ) ? $config['last_updated'] : 'Never',
			'generation_time' => ! empty( $duration ) ? $duration : 'N/A',
			'health_score'    => $health_score,
			'status'          => $progress['status'] ?? 'unknown',
		);
	}

	/**
	 * Build the Attribute Completeness section.
	 *
	 * Samples up to SAMPLE_SIZE products, resolves each mapped attribute,
	 * and calculates fill rates (percentage of products with non-empty values).
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param array  $feed_data Feed data.
	 *
	 * @return array Per-attribute completeness data.
	 */
	private function build_completeness( string $feed_slug, array $feed_data ): array {
		$rules       = isset( $feed_data['feedrules'] ) ? $feed_data['feedrules'] : array();
		$feed_config = Config::from_feed_name( $feed_slug );

		if ( ! $feed_config ) {
			return array();
		}

		// V5/V8 stores parallel arrays: mattributes (merchant names),
		// attributes (WC attr keys), type, default — all indexed.
		$merchant_attrs = $feed_config->get_merchant_attributes();
		$wc_attrs       = $feed_config->get_attributes();
		$types          = (array) ( $rules['type'] ?? array() );
		$defaults       = (array) ( $rules['default'] ?? array() );

		if ( empty( $merchant_attrs ) || empty( $wc_attrs ) ) {
			return array();
		}

		// Sample product IDs.
		$query = new ProductQuery();
		$ids   = $query->get_paginated_ids( $feed_config, 0, self::SAMPLE_SIZE );

		if ( empty( $ids ) ) {
			return array();
		}

		// Resolve attribute resolver from DI container if available.
		$resolver = null;
		if ( class_exists( '\CTXFeed\V8\Core\Container' ) ) {
			$container = \CTXFeed\V8\Core\Container::get_instance();
			if ( $container->has( 'product.attribute_resolver' ) ) {
				$resolver = $container->resolve( 'product.attribute_resolver' );
			}
		}

		// Initialize fill counts per index.
		$fill_count = array_fill( 0, count( $merchant_attrs ), 0 );

		// Sample products and check attribute values.
		$sampled = 0;
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			++$sampled;

			foreach ( $merchant_attrs as $index => $channel_attr ) {
				$mapping = array(
					'wc_attr' => isset( $wc_attrs[ $index ] ) ? $wc_attrs[ $index ] : '',
					'type'    => isset( $types[ $index ] ) ? $types[ $index ] : 'attribute',
					'default' => isset( $defaults[ $index ] ) ? $defaults[ $index ] : '',
				);

				$value = '';
				if ( $resolver && ! empty( $mapping['wc_attr'] ) ) {
					try {
						$value = (string) $resolver->resolve( $product, $mapping, $feed_config );
					} catch ( \Throwable $e ) {
						$value = '';
					}
				}

				if ( '' !== $value ) {
					++$fill_count[ $index ];
				}
			}
		}

		// Build result with fill rates.
		$result = array();
		foreach ( $merchant_attrs as $index => $channel_attr ) {
			$wc_attr = isset( $wc_attrs[ $index ] ) ? $wc_attrs[ $index ] : '';
			$filled  = $fill_count[ $index ];
			$rate    = $sampled > 0 ? (int) round( ( $filled / $sampled ) * 100 ) : 0;

			$result[] = array(
				'attribute' => $channel_attr,
				'source'    => $wc_attr,
				'filled'    => $filled,
				'sampled'   => $sampled,
				'fill_rate' => $rate,
			);
		}

		return $result;
	}

	/**
	 * Save the report as a .txt file.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param array  $report    Report data.
	 *
	 * @return void
	 */
	private function save_report_file( string $feed_slug, array $report ): void {
		$upload_dir = wp_get_upload_dir();
		$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/logs/';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$file = $log_dir . sanitize_file_name( $feed_slug ) . '-report.txt';

		$lines   = array();
		$lines[] = str_repeat( '=', 60 );
		$lines[] = '  CTX Feed Report — ' . ( $report['summary']['feed_name'] ?? $feed_slug );
		$lines[] = '  Generated: ' . $report['generated_at'];
		$lines[] = str_repeat( '=', 60 );
		$lines[] = '';

		// Summary section.
		$lines[] = '--- Feed Summary ---';
		$lines[] = '';
		foreach ( $report['summary'] as $key => $value ) {
			$label   = ucwords( str_replace( '_', ' ', $key ) );
			$lines[] = sprintf( '  %-20s %s', $label . ':', $value );
		}
		$lines[] = '';

		// Attribute Completeness section.
		$lines[] = '--- Attribute Completeness ---';
		$lines[] = sprintf( '  (Sampled %d products)', ! empty( $report['completeness'] ) ? $report['completeness'][0]['sampled'] : 0 );
		$lines[] = '';
		$lines[] = sprintf( '  %-30s %-20s %s', 'Attribute', 'Source', 'Fill Rate' );
		$lines[] = '  ' . str_repeat( '-', 70 );

		if ( ! empty( $report['completeness'] ) ) {
			foreach ( $report['completeness'] as $attr ) {
				$bar     = $this->progress_bar( $attr['fill_rate'] );
				$lines[] = sprintf(
					'  %-30s %-20s %s %d%% (%d/%d)',
					$attr['attribute'],
					$attr['source'],
					$bar,
					$attr['fill_rate'],
					$attr['filled'],
					$attr['sampled']
				);
			}
		} else {
			$lines[] = '  No mapped attributes found.';
		}

		$lines[] = '';
		$lines[] = str_repeat( '=', 60 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Writing the feed diagnostics report to the plugin's own uploads directory is core functionality; WP_Filesystem adds credential prompts unusable in cron/Action Scheduler context.
		file_put_contents( $file, implode( PHP_EOL, $lines ) );
	}

	/**
	 * Build a simple ASCII progress bar.
	 *
	 * @since 8.0.0
	 *
	 * @param int $percent Fill percentage.
	 *
	 * @return string ASCII bar like [████████░░]
	 */
	private function progress_bar( int $percent ): string {
		$filled = (int) round( $percent / 10 );
		$empty  = 10 - $filled;

		return '[' . str_repeat( '#', $filled ) . str_repeat( '.', $empty ) . ']';
	}

	/**
	 * Get the report file path for a feed.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 *
	 * @return string Full file path.
	 */
	public function get_report_path( string $feed_slug ): string {
		$upload_dir = wp_get_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/logs/' . sanitize_file_name( $feed_slug ) . '-report.txt';
	}
}
