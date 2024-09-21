<?php

use CTXFeed\Compatibility\CompatibilityFactory;
use CTXFeed\Compatibility\JWTAuth;
use CTXFeed\Compatibility\MultiVendor;
use CTXFeed\V5\Common\DisplayNotices;
use CTXFeed\V5\Common\DownloadFiles;
use CTXFeed\V5\Common\ExportFeed;
use CTXFeed\V5\Common\Helper;
use CTXFeed\V5\Common\ImportFeed;
use CTXFeed\V5\CustomFields\CustomFieldFactory;
use CTXFeed\V5\Helper\CommonHelper;
use CTXFeed\V5\Helper\CronHelper;
use CTXFeed\V5\Helper\FeedHelper;
use CTXFeed\V5\Override\OverrideFactory;
use CTXFeed\V5\Utility\Config;
use CTXFeed\V5\Utility\Logs;

/*
 * Load Compatibility Factory.
 *
 * Based on installed and active plugins, all compatibility classes will be initialized.
 */
CompatibilityFactory::init();

/**
 * Exclude Feed URL from Caching
 */
OverrideFactory::excludeCache();

/**
 * Process Feed Config Import Request
 */
new ImportFeed;
/**
 * Process Export Feed Request
 */
new ExportFeed;

/**
 * Process File Download Request
 */
new DownloadFiles;

/**
 * Show Feed Link In MultiVendor Menu
 */
if ( MultiVendor::woo_feed_is_multi_vendor() ) {
	new MultiVendor;
}


/**
 * Process JWT-Auth Request
 */
if ( is_plugin_active( 'jwt-auth/jwt-auth.php' ) ) {
	new JWTAuth;
}

/**
 * Process Discount Request
 */
//new DynamicDiscount;

/**
 * Process Custom Identifier
 */
CustomFieldFactory::init();

/**
 * Display Notice
 */
DisplayNotices::init();

/**
 * Initiate Common Override for all merchants.
 *
 * CTX Feed old version compatibility. Previous version of CTX Feed use to save feed config in different way.
 * Previous version of CTX Feed use to save feed config without mandatory fields. Like : post_status, product_ids etc.
 *
 * This override will help to generate feed for old version of CTX Feed.
 *
 * Also, we can use this override to add any common filter for all merchants.
 *
 * @since 7.3..0
 *
 */
\CTXFeed\V5\Override\CommonOverride::instance();

/**
 * We've introduced a better system to handle the cron job. You can read more about it here
 * libs/webappick-product-feed-for-woocommerce/V5/Helper/CronHelper.php
 * But this feature works only if the WP_CRON is enabled.
 * That's why we've checked here if the WP_CRON is enabled or not.
 * If WP_Cron is disabled then initialize old cron system by including the cron-helper.php file.
 *
 * Some users are claiming that the new cron system is not working for them. So, we've added setting to enable/disable the new cron system.
 * When new cron system is disabled, the old cron system will be initialized.
 *
 * @link : https://webappick.atlassian.net/browse/CBT-363
 *
 * since 7.3.13
 */
if ( Helper::should_init_new_cron_system() ) {
	new CronHelper();
}


/**
 * Get Product Ids by Query Type and Feed Name
 * Ajax Request
 *
 * @return void
 */
if ( ! function_exists( 'woo_feed_get_product_information' ) ) {

	function woo_feed_get_product_information() {
		check_ajax_referer( 'wpf_feed_nonce' );

		// Check user permission
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			Logs::write_debug_log( 'User doesnt have enough permission.' );
			wp_send_json_error( esc_html__( 'Unauthorized Action.', 'woo-feed' ) );
			wp_die();
		}

		if ( ! isset( $_REQUEST['feed_info'] ) ) {
			Logs::write_debug_log( 'Feed name not submitted.' );
			wp_send_json_error( esc_html__( 'Invalid Request.', 'woo-feed' ) );
			wp_die();
		}

		$feed_info = $_REQUEST['feed_info'];
		$config = new Config( $feed_info );
		if ( CommonHelper::wc_version_check( 3.0 ) ) {
			Logs::delete_log( $config->get_feed_file_name() );
			Logs::write_log( $config->get_feed_file_name(), sprintf( 'Getting Data for %s feed.', $config->get_feed_file_name() ) );
			Logs::write_log( $config->get_feed_file_name(), 'Generating Feed VIA Ajax...' );
			Logs::write_log( $config->get_feed_file_name(), 'Feed Config::' . PHP_EOL . print_r( $config->get_config(), true ), 'info' );

			try {
				// Hook Before Query Products
				do_action( 'before_woo_feed_get_product_information', $config );

				// Get Product Ids
				$ids       = FeedHelper::get_product_ids( $feed_info );

				// Hook After Query Products
				do_action( 'after_woo_feed_get_product_information', $config );

				Logs::write_log( $config->get_feed_file_name(), sprintf( 'Total %d product found', is_array( $ids ) && ! empty( $ids ) ? count( $ids ) : 0 ) );

				if ( is_array( $ids ) && ! empty( $ids ) ) {
					rsort( $ids ); // sorting ids in descending order

					Logs::write_log( $config->get_feed_file_name(), sprintf( 'Total %d batches', count( $ids ) ) );

					wp_send_json_success(
						array(
							'product_ids' => $ids,
							'total'       => count( $ids ),
							'success'     => true,
							'extra'     => [
								'should_generate_feed_by_ajax' => FeedHelper::should_generate_feed_by_ajax(),
							],
						)
					);
				} else {
					wp_send_json_error(
						array(
							'message' => esc_html__( 'No products found. Add product or change feed config before generate the feed.', 'woo-feed' ),
							'success' => false,
						)
					);
				}

				wp_die();
			} catch ( \Throwable $e ) {
				$message = 'Error getting Product Ids.' . PHP_EOL . 'Caught Exception :: ' . $e->getMessage();
				Logs::write_log( $config->get_feed_file_name(), $message );
				Logs::write_fatal_log( $message, $e );

				wp_send_json_error(
					array(
						'message' => esc_html__( 'Failed to fetch products.', 'woo-feed' ),
						'success' => false,
					)
				);
				wp_die();
			}
		} else { // For Older version of WooCommerce
			do_action( 'before_woo_feed_get_product_information', $config );
			$products = wp_count_posts( 'product' );
			do_action( 'after_woo_feed_get_product_information', $config );

			if ( $products->publish > 0 ) {
				wp_send_json_success( array( 'product' => $products->publish, 'success' => true ) );
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'No products found. Add product or change feed config before generate the feed.', 'woo-feed' ),
						'success' => false,
					)
				);
			}

			wp_die();
		}
	}

	add_action( 'wp_ajax_get_product_information', 'woo_feed_get_product_information' );
}


/**
 * Make feed per batch
 * Ajax Request
 *
 * @return void
 */
if ( ! function_exists( 'make_per_batch_feed' ) ) {

	function make_per_batch_feed() {
		check_ajax_referer( 'wpf_feed_nonce' );

		// Check user permission
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			Logs::write_debug_log( 'User doesnt have enough permission.' );
			wp_send_json_error( esc_html__( 'Unauthorized Action.', 'woo-feed' ) );
			wp_die();
		}

		if ( ! isset( $_REQUEST['feed_info'] ) ) {
			Logs::write_debug_log( 'Feed name not submitted.' );
			wp_send_json_error( esc_html__( 'Invalid Request.', 'woo-feed' ) );
			wp_die();
		}
		if ( CommonHelper::wc_version_check( 3.0 ) ) {
			$feed_info = $_REQUEST['feed_info'];
			$offset      = (int) $_REQUEST['offset'];
			$product_ids = array_map( 'absint', $_REQUEST['product_ids'] );
			$feedrules   = $feed_info['option_value']['feedrules'];


			// Write log if debug log is enabled.
			if ( Helper::is_debugging_enabled() ) {
				FeedHelper::log_data( $feedrules, $offset, $product_ids );
			}


			try {

				$status = false;
				$status = FeedHelper::generate_temp_feed_body( $feed_info, $product_ids, $offset );

				wp_send_json_success(
					[
						'status'  => $status,
						'offset'  => $offset,
						'message' => $status ? __( 'Temporary Feed Generated', 'woo-feed' ) : __( 'Something went wrong.', 'woo-feed' )
					]
				);
				wp_die();

			} catch ( Exception $e ) {
				$message = 'Error Generating Product Data.' . PHP_EOL . 'Caught Exception :: ' . $e->getMessage();
				woo_feed_log( $feedrules['filename'], $message, 'critical', $e, true );
				woo_feed_log_fatal_error( $message, $e );


				wp_send_json_error(
					[ 'status' => false, 'offset' => $offset, 'message' => $message ]
				);

				wp_die();
			}
		}
	}

	add_action( 'wp_ajax_make_per_batch_feed', 'make_per_batch_feed' );
}
