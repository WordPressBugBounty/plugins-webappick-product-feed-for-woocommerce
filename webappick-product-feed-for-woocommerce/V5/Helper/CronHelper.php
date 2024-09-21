<?php
/**
 * Cron Helper
 *
 * @package CTXFeed\V5\Helper
 */

namespace CTXFeed\V5\Helper;

use CTXFeed\V5\Common\Helper;
use CTXFeed\V5\Feed\Feed;
use CTXFeed\V5\Product\AttributeValueByType;
use CTXFeed\V5\Query\QueryFactory;
use CTXFeed\V5\Utility\Cache;
use CTXFeed\V5\Utility\Config;
use CTXFeed\V5\Utility\Logs;
use CTXFeed\V5\Utility\Settings;

/**
 * Developer written Documentation for Woo Feed Update Cron Job
 *
 * Write a developer doc comment for CTX Feed cron job which I build. So that, other developer can understand it. And can modify it if needed.
 * Write the documentation as function doc comment. So that, it can be used as a documentation whole process.
 *
 * The functionality of this suppose, I have 100000 woo commerce products
 * and with my ctx feed plugin I create an XML file for Google shop/Facebook catalog etc,
 * after XML/CSV/txt feed generation I register a cron job named "woo_feed_update_${option_name}"
 * as products are 100000 during cron job action it will not be able to generate feed because of
 * memory limit and maximum execution time. So, I have created sub batches for the feed which is
 * also basically a cron job. The sub-batch name will be
 * wf_store_auto_feed_body_info_${option_name}_0,
 * wf_store_auto_feed_body_info_${option_name}_1,
 * wf_store_auto_feed_body_info_${option_name}_2
 * .........
 * during feed creation parent batch which is "woo_feed_update_${option_name}"
 * time will be the default interval time only if products are less than $cron_job_per_batch (5000 products).
 * if more than that then the interval will be 1 hour.  Though our products are 100000 then interval will be 1 hour.
 * Now sub-batch time interval will 1 year. So it can't be run by cron job automatically, actually be before parent cron job.
 * The name of parent cron job "woo_feed_update_${option_name}" will be added to transient cache for 1 year. The cache name will be "woo_feed_cron_list".
 * "woo_feed_cron_list" will an array of all the parent cron job names.
 *
 * And another cache will be "woo_feed_update_${option_name}" here all data will be stored for the parent cron job. Example
 *                                                                 array(
 *                                                                      'product_ids' => [1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
 *                                                                      'feed_name'   => ${option_name},
 *                                                                      'hook_name'   => "woo_feed_update_${option_name}"
 *                                                                  )
 *
 * And another cache will be "woo_feed_sub_cron_list" which will be an array of all the sub-batch cron job names.
 * Example array(
 *            "wf_store_auto_feed_body_info_${option_name}_0",
 *            "wf_store_auto_feed_body_info_${option_name}_1",
 *            "wf_store_auto_feed_body_info_${option_name}_2",
 *            "wf_store_auto_feed_body_info_${option_name}_3",
 *            "wf_store_auto_feed_body_info_${option_name}_4",
 *  )
 *
 * Sub bath cron job  cache will be like this "wf_store_auto_feed_body_info_${option_name}_0" => array(
 *                                                                                          'feed_name'        => "${option_name}",
 *                                                                                          'option'           => "",
 *                                                                                          'total_offset'     => 1,
 *                                                                                          'parent_hook_name' => "woo_feed_update_${option_name}",
 *                                                                                          'product_ids' => [1, 3, 4, 5, ... ],
 *                                                                                          'offset' => 0,
 *                                                                                          'hook_name' => "wf_store_auto_feed_body_info_${option_name}_0",
 *                                                                                        );
 *
 * Now when the parent cron job will be executed it will get the product ids from the cache "woo_feed_update_${option_name}".
 * Then it will create sub-batches for the product ids. And will create sub-batch cron jobs. Batch will be created depending on the product ids.
 * If product ids are 100000 then 20 sub-batches will be created. Because the product batch safe limit is 5000. The execution time sub batches are
 * 1rst sub-batch will be executed immediately. And the 2nd the sub-batches will be executed after 1 minute. And the 3rd sub-batches will be executed
 * 2 minutes later. And so on. And the last sub-batch will be executed after 19 minutes. When finally the last sub-batch will be executed then all
 * files will be merged with header and footer into 1 file named "${option_name}.${file_extentsion}". And all the sub-batch files will be deleted.
 *
 * Now all sub-batch cron jobs will be executed after 1 year. And the parent cron job will be executed after 1 hour.
 */


/**
 * AI Generated Feed Cron Job Documentation for CTX Feed Plugin Feed Generation.
 *
 * WordPress Cron Job Documentation for CTX Feed Plugin Feed Generation.
 *
 * This cron job automates the generation of feed files (XML, CSV, TXT, etc.) for Google Shopping, Facebook Catalog, etc.,
 * from a vast pool of 100,000 Woo Commerce products. The process is designed to overcome memory and execution time
 * constraints by splitting it into a parent cron job and sub-batch cron jobs.
 *
 * @since 7.0.0
 * @see woo_feed_update_${option_name}
 * @see wf_store_auto_feed_body_info_${option_name}_0, wf_store_auto_feed_body_info_${option_name}_1, ...
 *
 * ## Parent Cron Job:
 * - **Name:** `woo_feed_update_${option_name}`
 * - **Interval:** Default if products < $cron_job_per_batch, else 1 hour.
 * - **Cache:** Stored in `woo_feed_cron_list` for 1 year.
 * - **Cache Content (Example):**
 *   ```
 *   array(
 *       'product_ids' => [1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
 *       'feed_name'   => ${option_name},
 *       'hook_name'   => "woo_feed_update_${option_name}"
 *   )
 *   ```
 *
 * ## Sub-Batch Cron Jobs:
 * - **Name Format:** `wf_store_auto_feed_body_info_${option_name}_0`, ...
 * - **Interval:** 1 year (manually triggered before the parent cron job).
 * - **Cache:** Stored in `woo_feed_sub_cron_list`.
 * - **Cache Content (Example):**
 *   ```
 *   array(
 *       "wf_store_auto_feed_body_info_${option_name}_0",
 *       "wf_store_auto_feed_body_info_${option_name}_1",
 *       ...
 *   )
 *   ```
 *
 * ## Sub-Batch Cron Job Cache:
 * - **Cache Format:** `wf_store_auto_feed_body_info_${option_name}_0`
 * - **Content (Example):**
 *   ```
 *   array(
 *       'feed_name'        => "${option_name}",
 *       'option'           => "",
 *       'total_offset'     => 1,
 *       'parent_hook_name' => "woo_feed_update_${option_name}",
 *       'product_ids'      => [1, 3, 4, 5, ... ],
 *       'offset'           => 0,
 *       'hook_name'        => "wf_store_auto_feed_body_info_${option_name}_0",
 *   )
 *   ```
 *
 * ## Execution Flow:
 * - Parent cron job fetches product IDs from either `woo_feed_update_${option_name}` cache or QueryFactor class based on $config object.
 * - Sub-batches are created with staggered execution times.
 * - Sub-batch cron jobs are scheduled for immediate and 1-minute interval execution.
 * - The last sub-batch executes after 19 minutes.
 * - Files are merged into `${option_name}.${file_extension}`, and sub-batch files are deleted.
 * - Sub-batch cron jobs run after 1 year, and the parent cron job runs after 1 hour.
 */

/**
 * Class CronHelper
 * @package CTXFeed\V5\Helper
 *
 * @since 7.3.0
 */
class CronHelper { // phpcs:ignore

	/**
	 * Cron Settings.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Product Batch Safe Limit.
	 *
	 * @var int
	 */
	private static $cron_job_per_batch = 5000;

	/**
	 * Cache Expiration.
	 *
	 * @var int
	 */
	private static $cache_expiration = YEAR_IN_SECONDS;

	/**
	 * Cache Prefix.
	 *
	 * @var string
	 */
	private static $cache_prefix = '__woo_feed_auto_update_cache_';

	/**
	 * Constructor
	 */
	public function __construct() {
		$cron_per_batch_setting = Settings::get( 'cron_job__per_batch__safe_limit' );
		if($cron_per_batch_setting) {
			self::$cron_job_per_batch = $cron_per_batch_setting;
		}

		if (!isset(self::$settings['sub_feed_body_prefix'])) {
			self::set_cron_settings();
		}

		// Delete current feed cron jobs.
		add_action( 'woo_feed_before_product_loop', array( $this, 'clear_cron_schedule_before_product_query' ), 10, 3 );


		add_action( 'woo_feed_after_product_loop', array( $this, 'schedule_cron_job_after_product_query' ), 10, 3 );

		// Add cron job for all feeds.
		$feed_cron_list = self::get_cache( 'woo_feed_cron_list' );

		if ( ! empty( $feed_cron_list ) ) {
			foreach ( $feed_cron_list as $feed_cron_hook_name ) {
				add_action( $feed_cron_hook_name, array( $this, 'woo_feed_cron_update_single_feed' ), 10, 1 );
			}
		}

		// Add cron job for all feeds sub batches.
		$feed_sub_cron_list = self::get_cache( 'woo_feed_sub_cron_list' );

		if ( ! empty( $feed_sub_cron_list ) ) {
			foreach ( $feed_sub_cron_list as $feed_sub_cron_hook_name ) {
				add_action( $feed_sub_cron_hook_name, array( $this, 'woo_feed_cron_update_batched_feed' ), 10, 1 );
			}
		}

		// Handle old feed.
		add_action( 'woo_feed_update_single_feed', array( $this, 'handle_old_single_feed' ), 10, 1 );

		// Delete old woo_feed_update cron job.
		if ( ! self::get_cache( 'is_deleted__woo_feed_update__cron_job' ) || self::is_cron_exits( 'woo_feed_update' ) ) {
			self::delete_cron_job( 'woo_feed_update' );

			return;
		}

		self::set_cache( 'is_deleted__woo_feed_update__cron_job', true );
	}

	/**
	 * Get Cron Hook Name.
	 *
	 * @param string $feed_name Feed Name.
	 * @param bool $is_single_hook Is Single Hook.
	 *
	 * @return string
	 */
	public static function get_cron_hook_name( $feed_name, $is_single_hook = false ) {
		$feed_name = str_replace( 'wf_feed_', '', $feed_name );
		$feed_name = str_replace( 'wf_config', '', $feed_name );
		if(!isset(self::$settings['single_feed_hook_prefix'])) {
			self::set_cron_settings();
		}
		if ( $is_single_hook ) {
			if ( strpos( $feed_name, self::$settings['single_feed_hook_prefix'] ) === false ) {
				$hook_name = self::$settings['single_feed_hook_prefix'] . $feed_name;
			} else {
				$hook_name = $feed_name;
			}
		} elseif ( strpos( $feed_name, self::$settings['sub_feed_body_prefix'] ) === false ) {
			$hook_name = self::$settings['sub_feed_body_prefix'] . $feed_name;
		} else {
			$hook_name = $feed_name;
		}

		return $hook_name;
	}

	/**
	 * Delete Cron Job.
	 *
	 * @param string $hook_name Cron Hook Name.
	 *
	 * @return void
	 */
	public static function delete_cron_job( $hook_name, $arg_value = false ) {
		// !IMPORTANT:: delete also sub hooks.
		$crons   = _get_cron_array();
		$updated = array_filter(
			$crons,
			function ( $v ) use ( $hook_name, $arg_value ) {
				// If arg value is set then check with arg value.
				if ( $arg_value && array_key_exists( $hook_name, $v ) ) {
					$cron_job = $v[ $hook_name ];
					$args     = array_values( $cron_job );
					foreach ( $args as $arg ) {
						if ( isset( $arg['args'] ) && in_array( $arg_value, $arg['args'] ) ) {
							return false;
						}
					}

					return true;
				} else {
					return ! array_key_exists( $hook_name, $v );
				}
			}
		);

		_set_cron_array( $updated );
	}


	/**
	 * Delete Cron Job.
	 *
	 * @param string $hook_name Cron Hook Name.
	 *
	 * @return bool
	 */
	public static function is_cron_exits( $hook_name ) {
		// !IMPORTANT:: delete also sub hooks.
		$crons          = _get_cron_array();
		$is_cron_exists = array_filter(
			$crons,
			function ( $v ) use ( $hook_name ) {
				return array_key_exists( $hook_name, $v );
			}
		);

		return ! empty( $is_cron_exists );
	}

	/**
	 * Add Cron Job.
	 *
	 * @param string $hook_name Cron Hook Name.
	 * @param bool $is_single_hook Is Single Hook.
	 *
	 * @return void
	 */
	public static function add_cron_job( $hook_name, $is_single_hook ) {
		$hook_name = self::get_cron_hook_name( $hook_name, $is_single_hook );
		self::delete_cron_job( $hook_name );

		if ( wp_next_scheduled( $hook_name, array( $hook_name ) ) ) {
			return;
		}

		$interval = self::get_feed_cron_interval();
		wp_schedule_event( time() + $interval, 'woo_feed_corn', $hook_name, array( $hook_name ) );
	}

	/**
	 * Schedule Cron Job For Sub Batches.
	 *
	 * @param string $feed_name Feed Name.
	 * @param array $cron_batched_ids Cron Batched Ids.
	 * @param array $batch_data Batch Data.
	 * @param array $option Feed Option.
	 * @param bool $execute_now Execute Now or Not.
	 *
	 * @return void
	 */
	private function schedule_cron_job_for_sub_batches( // phpcs:ignore
		$feed_name,
		$cron_batched_ids,
		$batch_data,
		$option,
		$execute_now = false
	) { // phpcs:ignore
		$feed_name          = str_replace( 'wf_config', 'wf_feed_', $feed_name );
		$time               = 0;
		$current_batch_data = array(
			'feed_name'        => $feed_name,
			'option'           => $option,
			'total_offset'     => count( $cron_batched_ids ) - 1,
			'parent_hook_name' => $batch_data['hook_name'],
		);


		if (!isset(self::$settings['sub_feed_body_prefix'])) {
			self::set_cron_settings();
		}

		foreach ( $cron_batched_ids as $index => $batch_ids ) {
			$current_batch_name                = $feed_name . '_' . $index;
			$current_batch_feed_cron_hook_name = self::$settings['sub_feed_body_prefix'] . $current_batch_name;
			$current_batch_data['product_ids'] = $batch_ids;
			$current_batch_data['offset']      = $index;
			$current_batch_data['hook_name']   = $current_batch_feed_cron_hook_name;

			// Delete sub batch cron job. And reschedule it.
			self::delete_cron_job( $current_batch_feed_cron_hook_name );

			if ( wp_next_scheduled( $current_batch_feed_cron_hook_name, array( $current_batch_feed_cron_hook_name ) ) ) {
				continue;
			}

			// Delete sub batch data if exists. And set new data.
			if ( self::get_cache( $current_batch_feed_cron_hook_name ) ) {
				self::delete_cache( $current_batch_feed_cron_hook_name );
			}

			self::set_cache( $current_batch_feed_cron_hook_name, wp_json_encode( $current_batch_data ) );

			if ( $execute_now ) {
				if ( $index > 0 ) {
					$time += self::$settings['sub_batch_update_interval'];
				}

				wp_schedule_event( time() + $time, 'woo_feed_corn', $current_batch_feed_cron_hook_name, array( $current_batch_feed_cron_hook_name ) );
			} else {
				wp_schedule_event( time() + YEAR_IN_SECONDS, 'woo_feed_corn', $current_batch_feed_cron_hook_name, array( $current_batch_feed_cron_hook_name ) );
			}
		}
	}


	/**
	 * Clear Cron Schedule Before Update Config.
	 *
	 * @param array $product_ids Product Ids.
	 * @param array $feed_rules Feed Rules.
	 * @param \CTXFeed\V5\Utility\Config $config Config.
	 *
	 * @return void
	 */
	public function clear_cron_schedule_before_product_query( $product_ids, $feed_rules, $config ) { // phpcs:ignore
		$feed_name = $config->get_feed_option_name();

		if ( ! $feed_name ) {
			return;
		}

		if (!isset(self::$settings['single_feed_hook_prefix'])) {
			self::set_cron_settings();
		}

		self::delete_cron_job( self::$settings['single_feed_hook_prefix'] . $feed_name );
	}

	/**
	 * Schedule Cron Job After Product Query.
	 *
	 * @param array $product_ids Product Ids.
	 * @param array $feed_rules Feed Rules.
	 * @param \CTXFeed\V5\Utility\Config $config Config.
	 *
	 * @return void
	 */
	public function schedule_cron_job_after_product_query( $product_ids, $feed_rules, $config ) { // phpcs:ignore
		$cron_batched_ids = $this->get_cron_batches( $product_ids );
		$interval         = self::get_feed_cron_interval( $product_ids );
		$feed_name        = $config->get_feed_option_name();

		if ( ! $feed_name ) {
			return;
		}

		if (!isset(self::$settings['single_feed_hook_prefix'])) {
			self::set_cron_settings();
		}

		$feed_cron_hook_name = self::$settings['single_feed_hook_prefix'] . $feed_name;

		$batch_data = array(
			'product_ids' => $product_ids,
			'feed_name'   => $feed_name,
			'hook_name'   => $feed_cron_hook_name,
		);

		if ( ! wp_next_scheduled( $feed_cron_hook_name, array( $feed_cron_hook_name ) ) ) {
			if ( self::get_cache( $feed_cron_hook_name ) ) {
				self::delete_cache( $feed_cron_hook_name );
			}

			self::set_cache( $feed_cron_hook_name, wp_json_encode( $batch_data ) );

			if ( self::get_cache( 'woo_feed_cron_list' ) ) {
				$feed_cron_list = self::get_cache( 'woo_feed_cron_list' );

				if ( is_array( $feed_cron_list ) && ! in_array( $feed_cron_hook_name, $feed_cron_list ) ) { // phpcs:ignore
					$feed_cron_list[] = $feed_cron_hook_name;
					self::set_cache( 'woo_feed_cron_list', $feed_cron_list );
				}
			} else {
				self::set_cache( 'woo_feed_cron_list', array( $feed_cron_hook_name ) );
			}

			wp_schedule_event( time() + $interval, 'woo_feed_corn', $feed_cron_hook_name, array( $feed_cron_hook_name ) );
		}

		if ( ! count( $cron_batched_ids ) ) {
			return;
		}

		foreach ( $cron_batched_ids as $index => $batch_ids ) { // phpcs:ignore
			$current_batch_name                = $feed_name . '_' . $index;
			$current_batch_feed_cron_hook_name = self::$settings['sub_feed_body_prefix'] . $current_batch_name;

			self::delete_cron_job( $current_batch_feed_cron_hook_name );

			if ( wp_next_scheduled( $current_batch_feed_cron_hook_name, array( $current_batch_feed_cron_hook_name ) ) ) {
				continue;
			}

			if ( self::get_cache( 'woo_feed_sub_cron_list' ) ) {
				$feed_sub_cron_list = self::get_cache( 'woo_feed_sub_cron_list' );

				if (
					is_array( $feed_sub_cron_list )
					&& ! in_array( $current_batch_feed_cron_hook_name, $feed_sub_cron_list ) // phpcs:ignore
				) { // phpcs:ignore
					$feed_sub_cron_list[] = $current_batch_feed_cron_hook_name;
					self::set_cache( 'woo_feed_sub_cron_list', $feed_sub_cron_list );
				}
			} else {
				self::set_cache( 'woo_feed_sub_cron_list', array( $current_batch_feed_cron_hook_name ) );
			}

			wp_schedule_event( time() + YEAR_IN_SECONDS, 'woo_feed_corn', $current_batch_feed_cron_hook_name, array( $current_batch_feed_cron_hook_name ) );
		}
	}


	/**
	 * Scheduled Action Hook For Old Single Cron Jobs.
	 *
	 * @param string $option_name Old single cron job name which will be 'woo_feed_update_single_feed'.
	 *
	 * @return void
	 */
	public function handle_old_single_feed( $option_name ) {
		$results = Feed::get_single_feed( $option_name ); // phpcs:ignore
		// If results variable is empty then check with "AttributeValueByType::FEED_RULES_OPTION_PREFIX" prefix.
		if ( empty( $results ) ) {
			$option_name_with_prefix = AttributeValueByType::FEED_RULES_OPTION_PREFIX . $option_name;
			$results                 = Feed::get_single_feed( $option_name_with_prefix ); // phpcs:ignore
			// If results variable is empty then check with "wf_config" prefix.
			if ( empty( $results ) ) {
				$option_name_with_prefix = 'wf_config' . $option_name;
				$results                 = Feed::get_single_feed( $option_name_with_prefix ); // phpcs:ignore
				// If results variable is empty then delete the "woo_feed_update_single_feed".
				if ( empty( $results ) ) {
					self::delete_cron_job( 'woo_feed_update_single_feed', $option_name );

					return;
				}
			}
		}

		$feed_info = $results[0];

		if ( ! isset( $feed_info['option_value']['feedrules'] ) ) {
			self::delete_cron_job( 'woo_feed_update_single_feed', $option_name );

			return;
		}

		$config = new Config( $feed_info );

		// Hook Before Query Products
		do_action( 'before_woo_feed_get_product_information', $config );

		// Get Product Ids
		$ids = QueryFactory::get_ids( $config, array() );

		// Hook After Query Products
		do_action( 'after_woo_feed_get_product_information', $config );


		if ( ! empty( $ids ) ) {
			$this->schedule_cron_job_after_product_query( $ids, $feed_info['option_value']['feedrules'], $config );
		}

		self::delete_cron_job( 'woo_feed_update_single_feed', $option_name );
	}

	/**
	 * Execute Single Feed Cron Job And Schedule Sub Batch Cron Jobs.
	 *
	 * @param string $cache_key Single cron job key.
	 *
	 * @return void
	 */
	public function woo_feed_cron_update_single_feed( $cache_key ) {
		$batch_data = self::get_cache( $cache_key );
		$batch_data = json_decode( $batch_data, true );

		if (
			empty( $batch_data )
			|| ! isset( $batch_data['feed_name'] )
			|| ! isset( $batch_data['hook_name'] )
			|| ! isset( $batch_data['product_ids'] )
		) {
			return;
		}

		$feed_name = $batch_data['feed_name'];

		$feed_option_name = AttributeValueByType::FEED_RULES_OPTION_PREFIX . $feed_name;


		$results = Feed::get_single_feed( $feed_option_name ); // phpcs:ignore

		if ( empty( $results ) ) {
			$hook_name = $batch_data['hook_name'];
			self::delete_cron_job( $hook_name );

			return;
		}

		$option    = $results[0];
		$feed_info = $option['option_value'];

		if ( ! isset( $feed_info['feedrules'] ) || isset( $feed_info['status'] ) && '0' === $feed_info['status'] ) {
			return;
		}

		$config = new Config( $option );

		// Hook Before Query Products
		do_action( 'before_woo_feed_get_product_information', $config );

		// Get Product Ids
		$ids = QueryFactory::get_ids( $config, array() );

		// Get Cron Batches
		$batch_data['product_ids'] = $ids;
		self::delete_cache( $batch_data['hook_name'] );
		self::set_cache( $batch_data['hook_name'], wp_json_encode( $batch_data ) );

		$cron_batched_ids = $this->get_cron_batches( $ids );

		// Hook After Query Products
		do_action( 'after_woo_feed_get_product_information', $config );

		if ( ! count( $cron_batched_ids ) ) {
			return;
		}

		$this->schedule_cron_job_for_sub_batches( $feed_name, $cron_batched_ids, $batch_data, $option, true );
	}

	/**
	 * Execute Sub Batch Cron Job And Merge All Sub Batched Feed Files Into A Single Feed File.
	 *
	 * @param string $cache_key Sub batch cache key.
	 *
	 * @return void
	 */
	public function woo_feed_cron_update_batched_feed( $cache_key ) { // phpcs:ignore
		$batch_data = self::get_cache( $cache_key );

		$batch_data = json_decode( $batch_data, true );

		// Check if the batch data is valid.
		if (
			empty( $batch_data )
			|| ! isset( $batch_data['product_ids'] )
			|| ! isset( $batch_data['offset'] )
			|| ! isset( $batch_data['option'] )
			|| ! isset( $batch_data['total_offset'] )
			|| ! isset( $batch_data['parent_hook_name'] )
		) {
			return;
		}

		$product_ids = $batch_data['product_ids'];
		$offset      = $batch_data['offset'];
		$option      = $batch_data['option'];

		$feed_info                      = $option['option_value'];
		$should_update_last_update_time = false;

		if ( $offset === $batch_data['total_offset'] ) {
			$should_update_last_update_time = true;
		}

		try {
			$option = FeedHelper::validate_feed( $option );
			// Create a new prefix for the current feed body.
			add_filter(
				'woo_feed_temp_feed_body_prefix',
				function ( $prefix ) use ( $offset ) {
					if ( $offset === 0 ) {
						$prefix_arr = $prefix . $offset . '_';
					} else {
						$prefix_arr = explode( '_', $prefix );

						foreach ( $prefix_arr as $key => $value ) {
							if ( ! is_numeric( $value ) ) {
								continue;
							}

							unset( $prefix_arr[ $key ] );
						}

						$prefix_arr = implode( '_', $prefix_arr ) . $offset . '_';
					}

					return $prefix_arr;
				},
				999,
				1
			);

			// Generate feed for the current sub batch.
			FeedHelper::generate_cron_batched_feed( $option, $offset, $should_update_last_update_time, $product_ids );
		} catch ( \CTXFeed\V5\Helper\Exception $e ) {
			$message = 'Error Updating Feed Via CRON Job' . PHP_EOL . 'Caught Exception :: ' . $e->getMessage();
			Logs::write_log( $feed_info['feedrules']['filename'], $message, 'critical', $e, true );
			Logs::write_fatal_log( $message, $e );
		}

		/**
		 * IF current sub batch is last batch for the feed, then update the last update time.
		 * And merge all the sub batched feed files into a single feed file.
		 * Then delete all the sub batched feed files.
		 */
		if ( ! $should_update_last_update_time ) {
			return;
		}

		FeedHelper::save_cron_batched_feed_files( $option, $should_update_last_update_time, true );
		$parent_hook_name  = $batch_data['parent_hook_name'];
		$parent_batch_data = self::get_cache( $parent_hook_name );
		$parent_batch_data = json_decode( $parent_batch_data, true );
		$cron_batches      = $this->get_cron_batches( $parent_batch_data['product_ids'] );

		// Reschedule parent cron job.
		self::delete_cron_job( $parent_hook_name );
		$interval = self::get_feed_cron_interval( $parent_batch_data['product_ids'] );
		wp_schedule_event( time() + $interval, 'woo_feed_corn', $parent_hook_name, array( $parent_hook_name ) );


		$this->schedule_cron_job_for_sub_batches( $batch_data['feed_name'], $cron_batches, $parent_batch_data, $option, false );
	}

	/**
	 * Get Cron Batches
	 *
	 * @param array $product_ids of product ids.
	 *
	 * @return array
	 */
	public function get_cron_batches( $product_ids ) {
		return array_chunk( $product_ids, self::$settings['product_batch_safe_limit'] );
	}

	/**
	 *  Get Feed Cron Interval
	 *
	 * !IMPORTANT Feed update interval should be single feed based. Currently it is global.
	 *
	 * @param array $product_ids of product ids.
	 *
	 * @return int
	 */
	private static function get_feed_cron_interval( $product_ids = array() ) {
		/**
		 * !IMPORTANT: Feed update interval should be single feed based. Currently it is global.
		 * If a single feed has 10000 products and another feed has 100 products, then the feed with 10000 products will take more time to update.
		 * So, the feed with 100 products will be updated more frequently than the feed with 10000 products.
		 */
		$interval = absint( get_option( 'wf_schedule' ) );

		if ( ! $interval ) {
			$interval = 3600;
		}

		if (!isset(self::$settings['product_batch_safe_limit'])) {
			self::set_cron_settings();
		}

		$cron_job_per_batch = self::$cron_job_per_batch;

		if ( isset( self::$settings['product_batch_safe_limit'] ) ) {
			$cron_job_per_batch = self::$settings['product_batch_safe_limit'];
		}

		if ( count( $product_ids ) > $cron_job_per_batch && $interval < 3600 ) {
			$interval = 3600;
		}

		return apply_filters( 'woo_feed_cron_interval', $interval );
	}

	/**
	 * Set Cache Data For Cron Job.
	 *
	 * @param string $key Cache Key.
	 * @param mixed $value Cache Value.
	 *
	 * @return void
	 */
	private static function set_cache( $key, $value ) {
		update_option( self::$cache_prefix . $key, $value );
	}

	/**
	 * Get Cache Data For Cron Job.
	 *
	 * @param string $key Cache Key.
	 *
	 * @return mixed
	 */
	private static function get_cache( $key ) {
		$cache = Cache::get( $key, self::$cache_prefix );
		if ( $cache ) {
			self::set_cache( $key, $cache );
			Cache::delete( $key, self::$cache_prefix );
		}

		return get_option( self::$cache_prefix . $key );
	}

	/**
	 * Delete Cache Data For Cron Job.
	 *
	 * @param string $key Cache Key.
	 */
	private static function delete_cache( $key ) {
		Cache::delete( $key, self::$cache_prefix );
		delete_option( self::$cache_prefix . $key );
	}

	/**
	 * Set Cron Settings.
	 *
	 * @return void
	 */
	public static function set_cron_settings()
	{
		self::$settings = apply_filters(
			'woo_feed_cron_settings',
			array(
				'product_batch_safe_limit' => self::$cron_job_per_batch,
				'sub_batch_update_interval' => 10,
				'sub_feed_body_prefix' => FeedHelper::get_feed_body_temp_prefix(true),
				'single_feed_hook_prefix' => 'woo_feed_update_',
			)
		);
	}

	/**
	 * Is Cron Enabled.
	 *
	 * @return bool
	 */
	private static function is_cron_enabled()
	{
		return apply_filters('ctx_feed_cron_enabled', Helper::should_init_new_cron_system());
	}

}
