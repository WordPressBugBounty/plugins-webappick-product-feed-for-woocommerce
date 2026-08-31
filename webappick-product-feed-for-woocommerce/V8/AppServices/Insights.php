<?php
/**
 * WebAppick Insights
 *
 * @version 1.0.1
 * @package CTXFeed
 * @subpackage AppServices
 *
 * This is a tracker class to track plugin usage based on if the customer has opted in.
 * No personal information is being tracked by this class, only general settings, active plugins, environment details
 * and admin email.
 */

namespace CTXFeed\AppServices;

use Exception;
use WP_Theme;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Insights
 */
class Insights {

	/**
	 * The notice text
	 *
	 * @var string
	 */
	protected $notice;

	/**
	 * Whether to the notice or not
	 *
	 * @var boolean
	 */
	protected $show_notice = true;

	/**
	 * If extra data needs to be sent
	 *
	 * @var array
	 */
	protected $extra_data = array();

	/**
	 * CTXFeed\AppServices\Client
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * Flag for checking if the init method is already called.
	 *
	 * @var bool
	 */
	private $didInit = false;

	/**
	 * Email Message Template For sending Support Ticket
	 *
	 * @var string
	 */
	protected $ticketTemplate = '';

	/**
	 * Ticket Email Recipient
	 *
	 * @var string
	 */
	protected $ticketRecipient = '';

	/**
	 * Response to show after support ticket submitted.
	 *
	 * @var string
	 */
	protected $supportResponse = '';

	/**
	 * Error Response for the support ticket
	 *
	 * @var string
	 */
	protected $supportErrorResponse = '';

	/**
	 * Support Page URL
	 *
	 * @var string
	 */
	protected $supportURL = '';

	/**
	 * Initialize the class
	 *
	 * @param Client $client    The client.
	 * @param string $name      readable name of the Plugin/Theme.
	 * @param string $file      main Plugin/Theme file path.
	 */
	public function __construct( $client, $name = null, $file = null ) {
		if ( is_string( $client ) && ! empty( $name ) && ! empty( $file ) ) {
			$client = new Client( $client, $name, $file );
		}

		if ( is_object( $client ) && is_a( $client, 'CTXFeed\AppServices\Client' ) ) {
			$this->client = $client;
		}
	}

	/**
	 * Don't show the notice
	 *
	 * @return Insights
	 */
	public function hide_notice() {
		$this->show_notice = false;
		return $this;
	}

	/**
	 * Add extra data if needed
	 *
	 * @param array $data   Extra data.
	 *
	 * @return Insights
	 */
	public function add_extra( $data = array() ) {
		$this->extra_data = $data;

		return $this;
	}

	/**
	 * Set custom notice text
	 *
	 * @param string $text  Admin Notice Test.
	 *
	 * @return Insights
	 */
	public function notice( $text ) {
		$this->notice = $text;

		return $this;
	}

	/**
	 * Initialize insights
	 *
	 * @return void
	 */
	public function init() {
		// Env Setup.
		$projectSlug = $this->client->getSlug();
		/**
		 * Support Page URL
		 *
		 * @param string $supportURL
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase -- "WebAppick_" is the vendor prefix of the shared WebAppick services SDK and the mixed-case hook name is its published contract; renaming it would silently drop every existing integration.
		$supportURL = apply_filters( "WebAppick_{$projectSlug}_Support_Page_URL", false );
		if ( false !== $supportURL ) {
			$supportURL = esc_url_raw( $supportURL, array( 'http', 'https' ) );
			if ( ! empty( $supportURL ) ) {
				$this->supportURL = $supportURL;
			}
		}
		/**
		 * Set Ticket Recipient Email
		 *
		 * @param string $ticketRecipient
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase -- "WebAppick_" is the vendor prefix of the shared WebAppick services SDK and the mixed-case hook name is its published contract; renaming it would silently drop every existing integration.
		$ticketRecipient = apply_filters( "WebAppick_{$projectSlug}_Support_Ticket_Recipient_Email", false );
		if ( false !== $ticketRecipient && is_email( $ticketRecipient ) ) {
			$this->ticketRecipient = sanitize_email( $ticketRecipient );
		}
		/**
		 * Set Support Ticket Template For sending the email query.
		 *
		 * @param string $ticketTemplate
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase -- "WebAppick_" is the vendor prefix of the shared WebAppick services SDK and the mixed-case hook name is its published contract; renaming it would silently drop every existing integration.
		$ticketTemplate = apply_filters( "WebAppick_{$projectSlug}_Support_Ticket_Email_Template", false );
		if ( false !== $ticketTemplate ) {
			$this->ticketTemplate = $ticketTemplate;
		}

		// initialize.
		if ( 'plugin' === $this->client->getType() ) {
			$this->init_plugin();
		} elseif ( 'theme' === $this->client->getType() ) {
			$this->init_theme();
		}
		$this->didInit = true;
	}

	/**
	 * Initialize theme hooks
	 *
	 * @return void
	 */
	private function init_theme() {
		$this->init_common();

		add_action( 'switch_theme', array( $this, 'deactivation_cleanup' ) );
		add_action( 'switch_theme', array( $this, 'theme_deactivated' ), 12, 3 );
	}

	/**
	 * Initialize plugin hooks
	 *
	 * @return void
	 */
	private function init_plugin() {
		// plugin deactivate popup.
		if ( ! $this->__is_local_server() ) {
			add_action( 'plugin_action_links_' . $this->client->getBasename(), array( $this, 'plugin_action_links' ) );
			add_action( 'admin_footer', array( $this, 'deactivate_scripts' ) );
		}

		$this->init_common();

		register_activation_hook( $this->client->getFile(), array( $this, 'activate_plugin' ) );
		register_deactivation_hook( $this->client->getFile(), array( $this, 'deactivation_cleanup' ) );
	}

	/**
	 * Initialize common hooks
	 *
	 * @return void
	 */
	protected function init_common() {
		if ( $this->show_notice ) {
			// tracking notice.
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		}
		add_action( 'admin_init', array( $this, 'handle_optIn_optOut' ) );
		add_action( 'removable_query_args', array( $this, 'add_removable_query_args' ), 10, 1 );
		// uninstall reason.
		add_action( 'wp_ajax_' . $this->client->getSlug() . '_submit-uninstall-reason', array( $this, 'uninstall_reason_submission' ) );
		add_action( 'wp_ajax_' . $this->client->getSlug() . '_submit-support-ticket', array( $this, 'support_ticket_submission' ) );
		// cron events.
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( $this->client->getSlug() . '_tracker_send_event', array( $this, 'send_tracking_data' ) );
	}

	/**
	 * Send tracking data to WebAppick server
	 *
	 * @param boolean $override override current settings.
	 *
	 * @return void
	 */
	public function send_tracking_data( $override = false ) {
		// skip on AJAX Requests.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! $this->is_tracking_allowed() && ! $override ) {
			return;
		}
		// Send a maximum of once per week.
		$last_send = $this->__get_last_send();

		/**
		 * Tracking interval
		 *
		 * @param string $interval A valid date/time string
		 *
		 * @see strtotime()
		 * @link https://www.php.net/manual/en/function.strtotime.php
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is prefixed at runtime with the plugin folder slug; it is part of the published WebAppick services SDK contract and cannot be renamed.
		$trackingInterval = apply_filters( $this->client->getSlug() . '_tracking_interval', '-1 week' );
		try {
			$intervalCheck = strtotime( $trackingInterval );
		} catch ( Exception $e ) {
			// fallback to default 1 week if filter returned unusable data.
			$intervalCheck = strtotime( '-1 week' );
		}
		if ( $last_send && $last_send > $intervalCheck && ! $override ) {
			return;
		}

		$this->client->send_request( $this->get_tracking_data(), 'track' );
		update_option( $this->client->getSlug() . '_tracking_last_send', time(), false );
	}

	/**
	 * Get the tracking data points
	 *
	 * @return array
	 */
	protected function get_tracking_data() {
		$all_plugins   = $this->__get_all_plugins();
		$admin_user    = $this->__get_admin();
		$store_country = get_option( 'woocommerce_default_country' );
		$admin_emails  = array( get_option( 'admin_email' ), $admin_user->user_email );
		$admin_emails  = array_filter( $admin_emails );
		$admin_emails  = array_unique( $admin_emails );
		$data          = array(
			'version'          => $this->client->getProjectVersion(),
			'url'              => esc_url( home_url() ),
			'site'             => $this->__get_site_name(),
			'admin_email'      => implode( ',', $admin_emails ),
			'first_name'       => $admin_user->first_name ? $admin_user->first_name : $admin_user->display_name,
			'last_name'        => $admin_user->last_name,
			'plugin'           => $this->client->getName(),
			'hash'             => $this->client->getHash(),
			'server'           => $this->__get_server_info(),
			'wp'               => $this->__get_wp_info(),
			'active_plugins'   => $all_plugins['active_plugins'],
			'inactive_plugins' => $all_plugins['inactive_plugins'],
			'ip_address'       => $this->__get_user_ip_address(),
			'theme'            => get_stylesheet(),
			'country'          => $store_country,
		);
		// for child classes.
		$extra = $this->get_extra_data();

		if ( ! empty( $extra ) ) {
			$data['extra'] = $extra;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is prefixed at runtime with the plugin folder slug; it is part of the published WebAppick services SDK contract and cannot be renamed.
		return apply_filters( $this->client->getSlug() . '_tracker_data', $data );
	}

	/**
	 * If a child class wants to send extra data
	 *
	 * @return mixed
	 */
	protected function get_extra_data() {

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off roll-up of the wf_feed_* option rows for the weekly telemetry payload; WordPress has no API for a LIKE query over wp_options and caching a once-a-week read would only add a stale copy.
		$result = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->options WHERE option_name LIKE %s;", 'wf_feed_%' ), 'ARRAY_A' );
		if ( ! is_array( $result ) ) {
			$result = array();
		}
		$catCount = wp_count_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);
		if ( is_wp_error( $catCount ) ) {
			$catCount = 0;
		}

		/*
		 * @TODO count products by type.
		 * @see wc_get_product_types();
		 */

		$hidden_notices = array();
		foreach ( array( 'rp-wcdpd', 'wpml', 'rating', 'product_limit' ) as $which ) {
			$hidden_notices[ $which ] = (int) get_option( sprintf( 'woo_feed_%s_notice_hidden', $which ), 0 );
		}

		$this->is_add_extra = apply_filters( 'woo_feed_woocommerce_add_extra', true );

		if ( $this->is_add_extra ) {

			$tracker_extra = array(
				'products'        => $this->get_post_count( 'product' ),
				'variations'      => $this->get_post_count( 'product_variation' ),
				'batch_limit'     => get_option( 'woo_feed_per_batch' ),
				'feed_configs'    => wp_json_encode( $result ),
				'product_cat_num' => $catCount,
				'review_notice'   => wp_json_encode( get_option( 'woo_feed_review_notice', array() ) ),
				'hidden_notices'  => $hidden_notices,
			);
			$this->add_extra( $tracker_extra );
		}


		return $this->extra_data;
	}

	/**
	 * Explain the user which data we collect
	 *
	 * @return array
	 */
	protected function data_we_collect() {
		$data = array(
			esc_html__( 'Server environment details (php, mysql, server, WordPress versions).', 'woo-feed' ),
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is prefixed at runtime with the plugin folder slug; it is part of the published WebAppick services SDK contract and cannot be renamed.
		$data = apply_filters( $this->client->getSlug() . '_what_tracked', $data );

		return $data;
	}

	/**
	 * Get the message array of what data being collected
	 *
	 * @return array
	 */
	public function get_data_collection_description() {
		return $this->data_we_collect();
	}

	/**
	 * Get Site SuperAdmin
	 * Returns Empty WP_User instance if fails
	 *
	 * @return WP_User
	 */
	private function __get_admin() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'paged'   => 1,
			)
		);

		return ( is_array( $admins ) && ! empty( $admins ) ) ? $admins[0] : new WP_User();
	}

	/**
	 * Check if the user has opted into tracking
	 *
	 * @return bool
	 */
	public function is_tracking_allowed() {
		return 'yes' === get_option( $this->client->getSlug() . '_allow_tracking', 'no' );
	}

	/**
	 * Get the last time a tracking was sent
	 *
	 * @return false|int
	 */
	private function __get_last_send() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		return get_option( $this->client->getSlug() . '_tracking_last_send', false );
	}

	/**
	 * Check if the notice has been dismissed or enabled
	 *
	 * @return boolean
	 */
	private function __notice_dismissed() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$hide_notice = get_option( $this->client->getSlug() . '_tracking_notice', 'no' );
		if ( 'hide' === $hide_notice ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the current server is localhost
	 *
	 * @return boolean
	 */
	public function __is_local_server() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface and is exposed to child classes; renaming it would diverge from upstream.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- "WebAppick_is_local" is the published hook name of the shared services SDK and cannot be renamed; REMOTE_ADDR is set by the web server rather than the client, and this loopback check only decides whether to skip telemetry locally, so a cached value would be harmless.
		return apply_filters( 'WebAppick_is_local', isset( $_SERVER['REMOTE_ADDR'] ) ? in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) : true );
	}

	/**
	 * Schedule the event weekly
	 *
	 * @return void
	 */
	private function __schedule_event() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$hook_name = $this->client->getSlug() . '_tracker_send_event';
		if ( ! wp_next_scheduled( $hook_name ) ) {
			wp_schedule_event( time(), 'weekly', $hook_name );
		}
	}

	/**
	 * Clear any scheduled hook
	 *
	 * @return void
	 */
	private function __clear_schedule_event() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		wp_clear_scheduled_hook( $this->client->getSlug() . '_tracker_send_event' );
	}

	/**
	 * Display the admin notice to users that have not opted-in or out
	 *
	 * @return void
	 */
	public function admin_notice() {
		if ( $this->__notice_dismissed() ) {
			return;
		}

		if ( $this->is_tracking_allowed() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// don't show tracking if a local server.
		if ( ! $this->__is_local_server() ) {

			if ( empty( $this->notice ) ) {
				$notice = sprintf(
					apply_filters(
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is prefixed at runtime with the plugin folder slug; it is part of the published WebAppick services SDK contract and cannot be renamed.
						$this->client->getSlug() . '_tracking_default_notice_message',
						/* translators: 1: plugin name */
						esc_html__( 'Want to help make %1$s even more awesome? Allow %1$s to collect non-sensitive diagnostic data and usage information.', 'woo-feed' )
					),
					'<strong>' . esc_html( $this->client->getName() ) . '</strong>'
				);
			} else {
				$notice = $this->notice;
			}

			$notice .= ' (<a class="' . $this->client->getSlug() . '-insights-data-we-collect" href="#">' . esc_html__( 'what we collect', 'woo-feed' ) . '</a>)';
			$notice .= '<p class="description" style="display:none;">' . implode( ', ', $this->data_we_collect() ) . '. ' . esc_html__( 'No sensitive data is tracked.', 'woo-feed' ) . '</p>';
			echo '<div class="updated"><p>';
			echo wp_kses_post( $notice );
			echo '</p><p class="submit">';
			echo '&nbsp;<a href="' . esc_url( $this->get_opt_out_url() ) . '" class="button button-secondary">' . esc_html__( 'No thanks', 'woo-feed' ) . '</a>';
			echo '&nbsp;<a href="' . esc_url( $this->get_opt_in_url() ) . '" class="button button-primary">' . esc_html__( 'Allow', 'woo-feed' ) . '</a>';
			echo '</p></div>';
			echo "<script type='text/javascript'>jQuery('." . esc_attr( $this->client->getSlug() ) . "-insights-data-we-collect').on('click', function(e) {
                    e.preventDefault();
                    jQuery(this).parents('.updated').find('p.description').slideToggle('fast');
            });</script>";
		}
	}

	/**
	 * Tracking Opt In URL
	 *
	 * @return string
	 */
	public function get_opt_in_url() {
		return add_query_arg(
			array(
				$this->client->getSlug() . '_tracker_optIn' => 'true',
				'_wpnonce' => wp_create_nonce( $this->client->getSlug() . '_insight_action' ),
			)
		);
	}

	/**
	 * Tracking Opt Out URL
	 *
	 * @return string
	 */
	public function get_opt_out_url() {
		return add_query_arg(
			array(
				$this->client->getSlug() . '_tracker_optOut' => 'true',
				'_wpnonce' => wp_create_nonce( $this->client->getSlug() . '_insight_action' ),
			)
		);
	}

	/**
	 * Handle the optIn/optOut.
	 *
	 * @return void
	 */
	public function handle_optIn_optOut() {
		if ( isset( $_REQUEST['_wpnonce'] ) && ( isset( $_GET[ $this->client->getSlug() . '_tracker_optIn' ] ) || isset( $_GET[ $this->client->getSlug() . '_tracker_optOut' ] ) ) ) {
			// Verify user has permission to manage options.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			check_admin_referer( $this->client->getSlug() . '_insight_action' );
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Raw $_GET value of unguaranteed type; a strict check would reject legitimate opt-in/opt-out links.
			if ( isset( $_GET[ $this->client->getSlug() . '_tracker_optIn' ] ) && 'true' == $_GET[ $this->client->getSlug() . '_tracker_optIn' ] ) {
				$this->optIn();
				wp_safe_redirect( remove_query_arg( $this->client->getSlug() . '_tracker_optIn' ) );
				exit;
			}
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Raw $_GET value of unguaranteed type; a strict check would reject legitimate opt-in/opt-out links.
			if ( isset( $_GET[ $this->client->getSlug() . '_tracker_optOut' ] ) && 'true' == $_GET[ $this->client->getSlug() . '_tracker_optOut' ] ) {
				$this->optOut();
				wp_safe_redirect( remove_query_arg( $this->client->getSlug() . '_tracker_optOut' ) );
				exit;
			}
		}
	}

	/**
	 * Add query vars to removable query args array
	 *
	 * @param array $removable_query_args   array of removable args.
	 *
	 * @return array
	 */
	public function add_removable_query_args( $removable_query_args ) {
		return array_merge(
			$removable_query_args,
			array( $this->client->getSlug() . '_tracker_optIn', $this->client->getSlug() . '_tracker_optOut', '_wpnonce' )
		);
	}

	/**
	 * Tracking optIn
	 *
	 * @param bool $override optional. set send tracking data override setting, ignore last send datetime setting if true.
	 *
	 * @return void
	 * @see Insights::send_tracking_data()
	 */
	public function optIn( $override = false ) {
		update_option( $this->client->getSlug() . '_allow_tracking', 'yes', false );
		update_option( $this->client->getSlug() . '_tracking_notice', 'hide', false );
		$this->__clear_schedule_event();
		$this->__schedule_event();
		$this->send_tracking_data( $override );
	}

	/**
	 * Opt out from tracking.
	 *
	 * @return void
	 */
	public function optOut() {
		update_option( $this->client->getSlug() . '_allow_tracking', 'no', false );
		update_option( $this->client->getSlug() . '_tracking_notice', 'hide', false );
		$this->__clear_schedule_event();
	}

	/**
	 * Get the number of post counts
	 *
	 * @param string $post_type PostType name to get count for.
	 *
	 * @return integer
	 */
	public function get_post_count( $post_type ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Published-post count for the weekly telemetry payload; wp_count_posts() cannot be trusted here because it is itself cached per post type, and this runs at most once a week from the tracker cron.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count(ID) FROM $wpdb->posts WHERE post_type = %s and post_status = 'publish'",
				$post_type
			)
		);
	}

	/**
	 * Get server related info.
	 *
	 * @return array
	 */
	private function __get_server_info() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		global $wpdb;
		$server_data = array(
			'software'             => ( isset( $_SERVER['SERVER_SOFTWARE'] ) && ! empty( $_SERVER['SERVER_SOFTWARE'] ) ) ? sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) : 'N/A',
			'php_version'          => ( function_exists( 'phpversion' ) ) ? phpversion() : 'N/A',
			'mysql_version'        => $wpdb->db_version(),
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- Deliberate: hosts that add ini_get to disable_functions emit a warning here, and a diagnostic value in the telemetry payload must never surface as a notice on the merchant's screen.
			'php_execution_time'   => @ini_get( 'max_execution_time' ),
			'php_max_upload_size'  => size_format( wp_max_upload_size() ),
			'php_default_timezone' => date_default_timezone_get(),
			'php_soap'             => class_exists( 'SoapClient' ) ? 'Yes' : 'No',
			'php_fsockopen'        => function_exists( 'fsockopen' ) ? 'Yes' : 'No',
			'php_curl'             => function_exists( 'curl_init' ) ? 'Yes' : 'No',
			'php_ftp'              => function_exists( 'ftp_connect' ) ? 'Yes' : 'No',
			'php_sftp'             => function_exists( 'ssh2_connect' ) ? 'Yes' : 'No',
		);

		return $server_data;
	}

	/**
	 * Get WordPress related data.
	 *
	 * @return array
	 */
	private function __get_wp_info() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$wp_data = array(
			'memory_limit' => WP_MEMORY_LIMIT,
			'debug_mode'   => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'Yes' : 'No',
			'locale'       => get_locale(),
			'version'      => get_bloginfo( 'version' ),
			'multisite'    => is_multisite() ? 'Yes' : 'No',
		);

		return $wp_data;
	}

	/**
	 * Get the list of active and inactive plugins
	 *
	 * @return array
	 */
	private function __get_all_plugins() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		if ( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}
		$plugins             = get_plugins();
		$active_plugins      = array();
		$active_plugins_keys = get_option( 'active_plugins', array() );
		foreach ( $plugins as $k => $v ) {
			// Take care of formatting the data how we want it.
			$formatted = array(
				'name'       => isset( $v['Name'] ) ? wp_strip_all_tags( $v['Name'] ) : '',
				'version'    => isset( $v['Version'] ) ? wp_strip_all_tags( $v['Version'] ) : 'N/A',
				'author'     => isset( $v['Author'] ) ? wp_strip_all_tags( $v['Author'] ) : 'N/A',
				'network'    => isset( $v['Network'] ) ? wp_strip_all_tags( $v['Network'] ) : 'N/A',
				'plugin_uri' => isset( $v['PluginURI'] ) ? wp_strip_all_tags( $v['PluginURI'] ) : 'N/A',
			);
			if ( in_array( $k, $active_plugins_keys, true ) ) {
				unset( $plugins[ $k ] ); // Remove active plugins from list so we can show active and inactive separately.
				$active_plugins[ $k ] = $formatted;
			} else {
				$plugins[ $k ] = $formatted;
			}
		}

		return array(
			'active_plugins'   => $active_plugins,
			'inactive_plugins' => $plugins,
		);
	}

	/**
	 * Get user totals based on user role.
	 *
	 * @return array
	 */
	public function __get_user_counts() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface and is exposed to child classes; renaming it would diverge from upstream.
		$user_count          = array();
		$user_count_data     = count_users();
		$user_count['total'] = $user_count_data['total_users'];
		// Get user count based on user role.
		foreach ( $user_count_data['avail_roles'] as $role => $count ) {
			$user_count[ $role ] = $count;
		}

		return $user_count;
	}

	/**
	 * Add weekly cron schedule
	 *
	 * @param array $schedules Cron Schedules.
	 *
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => DAY_IN_SECONDS * 7,
			'display'  => __( 'Once Weekly', 'woo-feed' ),
		);

		return $schedules;
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public function activate_plugin() {

		$allowed = get_option( $this->client->getSlug() . '_allow_tracking', 'no' );
		// if it wasn't allowed before, do nothing.
		if ( 'yes' !== $allowed ) {
			return;
		}
		// re-schedule and delete the last sent time so we could force send again.
		wp_schedule_event( time(), 'weekly', $this->client->getSlug() . '_tracker_send_event' );
		delete_option( $this->client->getSlug() . '_tracking_last_send' );
		$this->send_tracking_data( true );
	}

	/**
	 * Clear our options upon deactivation
	 *
	 * @return void
	 */
	public function deactivation_cleanup() {
		$this->__clear_schedule_event();
		if ( 'theme' === $this->client->getType() ) {
			delete_option( $this->client->getSlug() . '_tracking_last_send' );
			delete_option( $this->client->getSlug() . '_allow_tracking' );
		}
		delete_option( $this->client->getSlug() . '_tracking_notice' );
	}

	/**
	 * Hook into action links and modify the deactivate link
	 *
	 * @param array $links Plugin Action Links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {

		if ( array_key_exists( 'deactivate', $links ) ) {
			$links['deactivate'] = str_replace( '<a', '<a class="' . $this->client->getSlug() . '-deactivate-link"', $links['deactivate'] );
		}

		return $links;
	}

	/**
	 * Deactivation reasons
	 *
	 * @return array
	 */
	private function __get_uninstall_reasons() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.

		$reasons = array(
			array(
				'id'          => 'could-not-understand',
				'text'        => esc_html__( 'I couldn\'t understand how to make it work', 'woo-feed' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Would you like us to assist you?', 'woo-feed' ),
			),
			array(
				'id'          => 'found-better-plugin',
				'text'        => esc_html__( 'I found a better plugin', 'woo-feed' ),
				'type'        => 'text',
				'placeholder' => esc_html__( 'Which plugin?', 'woo-feed' ),
			),
			array(
				'id'          => 'not-have-that-feature',
				'text'        => esc_html__( 'The plugin is great, but I need specific feature that you don\'t support', 'woo-feed' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you tell us more about that feature?', 'woo-feed' ),
			),
			array(
				'id'          => 'is-not-working',
				'text'        => esc_html__( 'The plugin is not working', 'woo-feed' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you tell us a bit more whats not working?', 'woo-feed' ),
			),
			array(
				'id'          => 'looking-for-other',
				'text'        => esc_html__( 'It\'s not what I was looking for', 'woo-feed' ),
				'type'        => '',
				'placeholder' => '',
			),
			array(
				'id'          => 'did-not-work-as-expected',
				'text'        => esc_html__( 'The plugin didn\'t work as expected', 'woo-feed' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'What did you expect?', 'woo-feed' ),
			),
			array(
				'id'          => 'debugging',
				'text'        => esc_html__( 'Temporary deactivation for debugging', 'woo-feed' ),
				'type'        => '',
				'placeholder' => '',
			),
			array(
				'id'          => 'other',
				'text'        => esc_html__( 'Other', 'woo-feed' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you tell us a bit more?', 'woo-feed' ),
			),
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is prefixed at runtime with the plugin folder slug; it is part of the published WebAppick services SDK contract and cannot be renamed.
		$extra = apply_filters( $this->client->getSlug() . '_extra_uninstall_reasons', array(), $reasons );
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			// extract the last (other) reason and add after extras.
			$other   = array_pop( $reasons );
			$reasons = array_merge( $reasons, $extra, array( $other ) );
		}
		return $reasons;
	}

	/**
	 * Plugin deactivation uninstall reason submission
	 *
	 * @return void
	 */
	public function uninstall_reason_submission() {

		if ( ! current_user_can( 'manage_options' ) ) {
			woo_feed_log_debug_message( 'User doesnt have enough permission.' );
			wp_send_json_error( esc_html__( 'Unauthorized Action.', 'woo-feed' ), 403 );
			die();
		}

		check_ajax_referer( $this->client->getSlug() . '_insight_action' );
		if ( ! isset( $_POST['reason_id'] ) ) {
			wp_send_json_error( esc_html__( 'Invalid Request', 'woo-feed' ) );
			wp_die();
		}
		$current_user = wp_get_current_user();
		global $wpdb;
		// @TODO remove deprecated data after server update
		$data = array(
			'hash'          => $this->client->getHash(),
			'reason_id'     => isset( $_REQUEST['reason_id'] ) && ! empty( $_REQUEST['reason_id'] ) ? sanitize_text_field( $_REQUEST['reason_id'] ) : '',
			'reason_info'   => isset( $_REQUEST['reason_info'] ) ? trim( sanitize_textarea_field( $_REQUEST['reason_info'] ) ) : '',
			'plugin'        => $this->client->getName(),
			'site'          => $this->__get_site_name(),
			'url'           => esc_url( home_url() ),
			'admin_email'   => get_option( 'admin_email' ),
			'user_email'    => $current_user->user_email,
			'user_name'     => $current_user->display_name,
			// deprecated.
			'first_name'    => ( ! empty( $current_user->first_name ) ) ? $current_user->first_name : $current_user->display_name,
			'last_name'     => $current_user->last_name,
			'server'        => $this->__get_server_info(),
			'software'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) : 'Generic', // deprecated, using $data['server'] for wp info.
			'php_version'   => phpversion(), // deprecated, using $data['server'] for wp info.
			'mysql_version' => $wpdb->db_version(), // deprecated, using $data['server'] for wp info.
			'wp'            => $this->__get_wp_info(),
			'wp_version'    => get_bloginfo( 'version' ), // deprecated, using $data['wp'] for wp info.
			'locale'        => get_locale(), // deprecated, using $data['wp'] for wp info.
			'multisite'     => is_multisite() ? 'Yes' : 'No', // deprecated, using $data['wp'] for wp info.
			'ip_address'    => $this->__get_user_ip_address(),
			'version'       => $this->client->getProjectVersion(),
		);
		// Add extra data.
		$extra = $this->get_extra_data();
		if ( ! empty( $extra ) ) {
			$data['extra'] = $extra;
		}
		$this->client->send_request( $data, 'reason' );
		wp_send_json_success();
		wp_die();
	}

	/**
	 * Handle Support Ticket Submission
	 *
	 * @return void
	 */
	public function support_ticket_submission() {

		if ( ! current_user_can( 'manage_options' ) ) {
			woo_feed_log_debug_message( 'User doesnt have enough permission.' );
			wp_send_json_error( esc_html__( 'Unauthorized Action.', 'woo-feed' ), 403 );
			die();
		}

		check_ajax_referer( $this->client->getSlug() . '_insight_action' );
		if ( empty( $this->ticketTemplate ) || empty( $this->ticketRecipient ) || empty( $this->supportURL ) ) {
			wp_send_json_error(
				sprintf(
					'<p class="mui-error">%s<br>%s</p>',
					esc_html__( 'Something Went Wrong.', 'woo-feed' ),
					esc_html__( 'Please try again after sometime.', 'woo-feed' )
				)
			);
			wp_die();
		}
		if (
			isset( $_REQUEST['name'], $_REQUEST['email'], $_REQUEST['subject'], $_REQUEST['website'], $_REQUEST['message'] ) &&
			(
				! empty( sanitize_text_field( $_REQUEST['name'] ) ) &&
				! empty( sanitize_email( $_REQUEST['email'] ) ) &&
				! empty( sanitize_text_field( $_REQUEST['subject'] ) ) &&
				! empty( sanitize_text_field( $_REQUEST['website'] ) ) &&
				! empty( sanitize_text_field( $_REQUEST['message'] ) )
			)
		) {
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				sprintf(
					'From: %s <%s>',
					sanitize_text_field( $_REQUEST['name'] ),
					sanitize_email( $_REQUEST['email'] )
				),
				sprintf(
					'Reply-To: %s <%s>',
					sanitize_text_field( $_REQUEST['name'] ),
					sanitize_text_field( $_REQUEST['email'] )
				),
			);

			foreach ( $_REQUEST as $k => $v ) {
				$sanitizer = 'sanitize_text_field';
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- $k is an array key, so it can be an int; a strict check would change which sanitiser is picked on PHP 7.4.
				if ( 'email' == $k ) {
					$sanitizer = 'sanitize_email';
				}
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- $k is an array key, so it can be an int; a strict check would change which sanitiser is picked on PHP 7.4.
				if ( 'website' == $k ) {
					$sanitizer = 'esc_url';
				}
				$v                    = call_user_func_array( $sanitizer, array( $v ) );
				$_REQUEST[ $k ]       = $v; // phpcs: sanitize ok.
				$k                    = '__' . strtoupper( $k ) . '__';
				$this->ticketTemplate = str_replace( array( $k ), array( $v ), $this->ticketTemplate );
			}
			$projectSlug = $this->client->getSlug();
			$isSent      = wp_mail( $this->ticketRecipient, sanitize_text_field( $_REQUEST['subject'] ), sprintf( '<div>%s</div>', $this->ticketTemplate ), $headers );// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- vip_safe_wp_mail() exists only on VIP; this ships to self-hosted stores and the recipient is the plugin's own support address.
			if ( $isSent ) {
				/**
				 * Set Ajax Success Response for Support Ticket Submission
				 *
				 * @param string $supportResponse
				 * @param array $_REQUEST
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase -- "WebAppick_" is the vendor prefix of the shared WebAppick services SDK and the mixed-case hook name is its published contract; renaming it would silently drop every existing integration.
				$supportResponse = apply_filters( "WebAppick_{$projectSlug}_Support_Request_Ajax_Success_Response", false, $_REQUEST );
				if ( false !== $supportResponse ) {
					$this->supportResponse = $supportResponse;
				} else {
					$this->supportResponse = sprintf(
						'<h3>%s</h3>',
						esc_html__( 'Thank you -- Support Ticket Submitted.', 'woo-feed' )
					);
				}
				wp_send_json_success( $this->supportResponse );
				wp_die();
			} else {
				/**
				 * Set Support Ticket Ajax Error Response.
				 *
				 * @param string $supportErrorResponse
				 * @param array $_REQUEST
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.NotLowercase -- "WebAppick_" is the vendor prefix of the shared WebAppick services SDK and the mixed-case hook name is its published contract; renaming it would silently drop every existing integration.
				$supportErrorResponse = apply_filters( "WebAppick_{$projectSlug}_Support_Request_Ajax_Error_Response", false, $_REQUEST );
				if ( false !== $supportErrorResponse ) {
					$this->supportErrorResponse = $supportErrorResponse;
				} else {
					$this->supportErrorResponse = sprintf(
						'<div class="mui-error"><p>%s</p></div>',
						esc_html__( 'Something Went Wrong. Please Try Again After Sometime.', 'woo-feed' )
					);
				}
				wp_send_json_error( $this->supportErrorResponse );
			}
		} else {
			wp_send_json_error( sprintf( '<p class="mui-error">%s</p>', esc_html__( 'Missing Required Fields.', 'woo-feed' ) ) );
		}
		wp_die();
	}

	/**
	 * Handle the plugin deactivation feedback
	 *
	 * @return void
	 */
	public function deactivate_scripts() {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}
		$reasons           = $this->__get_uninstall_reasons();
		$admin_user        = $this->__get_admin();
		$displayName       = ( ! empty( $admin_user->first_name ) && ! empty( $admin_user->last_name ) ) ? $admin_user->first_name . ' ' . $admin_user->last_name : $admin_user->display_name;
		$showSupportTicket = ( ! empty( $this->ticketTemplate ) && ! empty( $this->ticketRecipient ) && ! empty( $this->supportURL ) );
		?>
		<?php $slug = $this->client->getSlug(); ?>
		<div class="ctxf-dr" id="<?php echo esc_attr( $slug ); ?>-ctxf-dr" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php /* translators: 1: Plugin Name */ printf( esc_attr__( '&ldquo;%s&rdquo; Deactivation', 'woo-feed' ), esc_attr( $this->client->getName() ) ); ?>">

			<?php if ( $showSupportTicket ) { ?>
			<!-- Panel: help prompt -->
			<div class="ctxf-panel ctxf-panel--help" data-panel="help">
				<div class="ctxf-head">
					<div class="ctxf-head-tx"><h3 class="ctxf-title"><?php esc_html_e( 'Do you need help with anything?', 'woo-feed' ); ?></h3></div>
					<button type="button" class="ctxf-x" data-close aria-label="<?php esc_attr_e( 'Close', 'woo-feed' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
				</div>
				<div class="ctxf-help-body">
					<span class="ctxf-ic-lg ctxf-ic-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.9 48.9 0 01-3.476.383.39.39 0 00-.297.17l-2.755 4.133a.75.75 0 01-1.248 0l-2.755-4.133a.39.39 0 00-.297-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z"/></svg></span>
					<div class="ctxf-help-h"><?php esc_html_e( 'In trouble? Please submit a support request.', 'woo-feed' ); ?></div>
					<p class="ctxf-help-p"><?php esc_html_e( 'Most feed issues are fixed the same day. Your feeds and settings stay untouched while the plugin is active.', 'woo-feed' ); ?></p>
				</div>
				<div class="ctxf-foot ctxf-help-foot">
					<button type="button" class="ctxf-btn ctxf-btn--danger-o" data-go="reason"><?php esc_html_e( 'Not Interested', 'woo-feed' ); ?></button>
					<button type="button" class="ctxf-btn ctxf-btn--yellow" data-go="contact"><?php esc_html_e( 'Contact support', 'woo-feed' ); ?></button>
				</div>
			</div>
			<?php } ?>

			<!-- Panel: reason survey -->
			<div class="ctxf-panel ctxf-panel--reason<?php echo $showSupportTicket ? '' : ' is-active'; ?>" data-panel="reason">
				<div class="ctxf-head">
					<div class="ctxf-head-tx">
						<h3 class="ctxf-title"><?php esc_html_e( 'If you have a moment, please let us know why you are deactivating', 'woo-feed' ); ?></h3>
						<p class="ctxf-sub"><?php esc_html_e( 'Anonymous, and it takes one click.', 'woo-feed' ); ?></p>
					</div>
					<button type="button" class="ctxf-x" data-close aria-label="<?php esc_attr_e( 'Close', 'woo-feed' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
				</div>
				<div class="ctxf-body">
					<ul class="ctxf-reasons">
						<?php foreach ( $reasons as $reason ) { ?>
							<li><label><input type="radio" name="ctxf-reason" value="<?php echo esc_attr( $reason['id'] ); ?>" data-placeholder="<?php echo esc_attr( $reason['placeholder'] ); ?>"> <span><?php echo esc_html( $reason['text'] ); ?></span></label></li>
						<?php } ?>
					</ul>
					<div class="ctxf-field">
						<label class="ctxf-label" for="<?php echo esc_attr( $slug ); ?>-ctxf-reason-msg"><?php esc_html_e( 'Tell us more', 'woo-feed' ); ?></label>
						<textarea class="ctxf-textarea ctxf-reason-msg" id="<?php echo esc_attr( $slug ); ?>-ctxf-reason-msg" rows="3" placeholder="<?php esc_attr_e( 'Pick a reason above, or write anything you want us to know', 'woo-feed' ); ?>"></textarea>
					</div>
				</div>
				<div class="ctxf-foot ctxf-reason-foot">
					<a href="#" class="ctxf-btn ctxf-btn--link ctxf-skip"><?php esc_html_e( 'I\'d rather not say', 'woo-feed' ); ?></a>
					<div class="ctxf-foot-right">
						<button type="button" class="ctxf-btn ctxf-btn--ghost" data-close><?php esc_html_e( 'Cancel', 'woo-feed' ); ?></button>
						<button type="button" class="ctxf-btn ctxf-btn--primary ctxf-submit-deact" disabled><?php esc_html_e( 'Submit & deactivate', 'woo-feed' ); ?></button>
					</div>
				</div>
			</div>

			<?php if ( $showSupportTicket ) { ?>
			<!-- Panel: contact form -->
			<div class="ctxf-panel ctxf-panel--contact" data-panel="contact">
				<div class="ctxf-head">
					<div class="ctxf-head-ic"><span class="ctxf-ic-lg ctxf-ic-yellow"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.9 48.9 0 01-3.476.383.39.39 0 00-.297.17l-2.755 4.133a.75.75 0 01-1.248 0l-2.755-4.133a.39.39 0 00-.297-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z"/></svg></span></div>
					<div class="ctxf-head-tx">
						<h3 class="ctxf-title"><?php esc_html_e( 'Contact support', 'woo-feed' ); ?></h3>
						<p class="ctxf-sub"><?php esc_html_e( 'One business day reply. Your system report and feed list are attached automatically.', 'woo-feed' ); ?></p>
					</div>
					<button type="button" class="ctxf-x" data-close aria-label="<?php esc_attr_e( 'Close', 'woo-feed' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
				</div>
				<div class="ctxf-body">
					<div class="ctxf-fields">
						<div>
							<label class="ctxf-label" for="<?php echo esc_attr( $slug ); ?>-ctxf-name"><?php esc_html_e( 'Name', 'woo-feed' ); ?> <span class="ctxf-req">*</span></label>
							<input class="ctxf-input ctxf-c-name" id="<?php echo esc_attr( $slug ); ?>-ctxf-name" type="text" value="<?php echo esc_attr( $displayName ); ?>" placeholder="<?php esc_attr_e( 'Your name', 'woo-feed' ); ?>" required>
						</div>
						<div>
							<label class="ctxf-label" for="<?php echo esc_attr( $slug ); ?>-ctxf-email"><?php esc_html_e( 'Email', 'woo-feed' ); ?> <span class="ctxf-req">*</span></label>
							<input class="ctxf-input ctxf-c-email" id="<?php echo esc_attr( $slug ); ?>-ctxf-email" type="email" value="<?php echo esc_attr( $admin_user->user_email ); ?>" placeholder="<?php esc_attr_e( 'you@store.com', 'woo-feed' ); ?>" required>
							<p class="ctxf-hint ctxf-email-hint"><?php esc_html_e( 'We reply to this address.', 'woo-feed' ); ?></p>
						</div>
						<div>
							<label class="ctxf-label" for="<?php echo esc_attr( $slug ); ?>-ctxf-message"><?php esc_html_e( 'Message', 'woo-feed' ); ?> <span class="ctxf-req">*</span></label>
							<textarea class="ctxf-textarea ctxf-c-msg" id="<?php echo esc_attr( $slug ); ?>-ctxf-message" rows="5" maxlength="2000" placeholder="<?php esc_attr_e( 'What went wrong, and which feed is affected?', 'woo-feed' ); ?>" required></textarea>
							<p class="ctxf-hint"><?php esc_html_e( 'System status and the feed list are attached.', 'woo-feed' ); ?><span class="ctxf-count">0 / 2000</span></p>
						</div>
						<input type="hidden" class="ctxf-c-subject" value="<?php /* translators: 1: site name */ printf( esc_attr__( 'Deactivation support request from %s', 'woo-feed' ), esc_attr( $this->__get_site_name() ) ); ?>">
						<input type="hidden" class="ctxf-c-website" value="<?php echo esc_url( site_url() ); ?>">
					</div>
				</div>
				<div class="ctxf-foot ctxf-contact-foot">
					<div class="ctxf-foot-note"><?php esc_html_e( 'Name, email and a short message are required.', 'woo-feed' ); ?></div>
					<button type="button" class="ctxf-btn ctxf-btn--link" data-close><?php esc_html_e( 'Cancel', 'woo-feed' ); ?></button>
					<button type="button" class="ctxf-btn ctxf-btn--yellow ctxf-send" disabled><?php esc_html_e( 'Send message', 'woo-feed' ); ?></button>
				</div>
			</div>

			<!-- Panel: sent -->
			<div class="ctxf-panel ctxf-panel--sent" data-panel="sent">
				<div class="ctxf-sent">
					<span class="ctxf-ic-ok"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M19.916 4.626a.75.75 0 01.208 1.04l-9 13.5a.75.75 0 01-1.154.114l-6-6a.75.75 0 011.06-1.06l5.353 5.353 8.493-12.739a.75.75 0 011.04-.208z"/></svg></span>
					<div class="ctxf-sent-h"><?php esc_html_e( 'Message sent', 'woo-feed' ); ?></div>
					<p class="ctxf-sent-p"><?php esc_html_e( 'We reply within one business day — a copy went to your email address.', 'woo-feed' ); ?></p>
					<button type="button" class="ctxf-btn ctxf-btn--yellow" data-close><?php esc_html_e( 'Done', 'woo-feed' ); ?></button>
				</div>
			</div>
			<?php } ?>

		</div>
		<style type="text/css">
			#<?php echo esc_attr( $slug ); ?>-ctxf-dr.ctxf-dr, #<?php echo esc_attr( $slug ); ?>-ctxf-dr.ctxf-dr * { box-sizing: border-box; }
			.ctxf-dr {
				--ctxf-ink:#141c26; --ctxf-body:#465768; --ctxf-muted:#74839a; --ctxf-faint:#98a5b5;
				--ctxf-line:#dbe2ec; --ctxf-hair:#eaeef5; --ctxf-wash:#eef2f8; --ctxf-paper:#fbfcfe;
				--ctxf-primary:#1570ef; --ctxf-primary-dark:#0f56bf; --ctxf-tint:#eaf3ff;
				--ctxf-danger:#e02b20; --ctxf-danger-line:#f7cfc9; --ctxf-danger-tint:#fdecea;
				--ctxf-yellow:#f7c948; --ctxf-yellow-hover:#eebc32; --ctxf-yellow-dim:#f6e7b6; --ctxf-yellow-dim-line:#eadfba; --ctxf-yellow-dim-text:#b6a878;
				position:fixed; inset:0; top:0; right:0; bottom:0; left:0; z-index:99999; display:none;
				overflow-y:auto;
				background:rgba(20,28,38,.55);
				font-family:'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				-webkit-font-smoothing:antialiased;
			}
			.ctxf-dr.is-open { display:block; }
			.ctxf-dr .ctxf-panel { display:none; position:relative; width:460px; max-width:calc(100vw - 32px); background:#fff; border-radius:16px; box-shadow:0 24px 60px rgba(20,28,38,.28); margin:8vh auto; overflow:hidden; }
			.ctxf-dr .ctxf-panel.is-active { display:block; }
			.ctxf-dr .ctxf-panel--reason { width:560px; }
			.ctxf-dr .ctxf-head { display:flex; align-items:flex-start; gap:14px; padding:20px 22px 16px; border-bottom:1px solid var(--ctxf-hair); }
			.ctxf-dr .ctxf-head-ic { flex:none; }
			.ctxf-dr .ctxf-head-tx { flex:1; min-width:0; }
			.ctxf-dr .ctxf-title { margin:0; padding:0; font-size:18px; line-height:1.35; font-weight:700; color:var(--ctxf-ink); letter-spacing:-.01em; }
			.ctxf-dr .ctxf-sub { margin:5px 0 0; padding:0; font-size:13.5px; line-height:1.4; color:var(--ctxf-muted); }
			.ctxf-dr .ctxf-x { flex:none; width:30px; height:30px; border-radius:8px; border:1px solid var(--ctxf-line); background:#fff; color:var(--ctxf-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; transition:background .15s, color .15s; }
			.ctxf-dr .ctxf-x:hover { background:var(--ctxf-wash); color:var(--ctxf-ink); }
			.ctxf-dr .ctxf-x svg { width:16px; height:16px; fill:currentColor; }
			.ctxf-dr .ctxf-body { padding:24px 22px; }
			.ctxf-dr .ctxf-foot { display:flex; align-items:center; gap:10px; padding:16px 22px; border-top:1px solid var(--ctxf-hair); margin:0; }
			.ctxf-dr .ctxf-btn { display:inline-block; font-family:inherit; font-size:14px; font-weight:600; line-height:1.2; border-radius:8px; padding:10px 18px; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:background .15s, color .15s, box-shadow .15s; }
			.ctxf-dr .ctxf-btn:focus { outline:2px solid var(--ctxf-primary); outline-offset:2px; box-shadow:none; }
			.ctxf-dr .ctxf-btn--yellow { background:var(--ctxf-yellow); border-color:var(--ctxf-ink); color:var(--ctxf-ink); box-shadow:0 2px 4px rgba(20,28,38,.12); }
			.ctxf-dr .ctxf-btn--yellow:hover { background:var(--ctxf-yellow-hover); color:var(--ctxf-ink); }
			.ctxf-dr .ctxf-btn--yellow[disabled] { background:var(--ctxf-yellow-dim); border-color:var(--ctxf-yellow-dim-line); color:var(--ctxf-yellow-dim-text); cursor:not-allowed; box-shadow:none; }
			.ctxf-dr .ctxf-btn--danger-o { background:#fff; border-color:var(--ctxf-danger-line); color:var(--ctxf-danger); }
			.ctxf-dr .ctxf-btn--danger-o:hover { background:var(--ctxf-danger-tint); color:var(--ctxf-danger); }
			.ctxf-dr .ctxf-btn--ghost { background:#fff; border-color:var(--ctxf-line); color:var(--ctxf-body); }
			.ctxf-dr .ctxf-btn--ghost:hover { background:var(--ctxf-wash); color:var(--ctxf-body); }
			.ctxf-dr .ctxf-btn--primary { background:var(--ctxf-primary); border-color:var(--ctxf-primary); color:#fff; box-shadow:0 3px 8px rgba(21,112,239,.3); }
			.ctxf-dr .ctxf-btn--primary:hover { background:var(--ctxf-primary-dark); color:#fff; }
			.ctxf-dr .ctxf-btn--primary[disabled] { background:var(--ctxf-wash); border-color:var(--ctxf-wash); color:var(--ctxf-faint); cursor:not-allowed; box-shadow:none; }
			.ctxf-dr .ctxf-btn--link { background:none; border:0; color:var(--ctxf-muted); padding:10px 6px; font-weight:500; }
			.ctxf-dr .ctxf-btn--link:hover { color:var(--ctxf-body); text-decoration:underline; }
			.ctxf-dr .ctxf-ic-lg { width:56px; height:56px; border-radius:15px; display:flex; align-items:center; justify-content:center; }
			.ctxf-dr .ctxf-ic-lg svg { width:26px; height:26px; fill:currentColor; }
			.ctxf-dr .ctxf-ic-blue { background:var(--ctxf-tint); color:var(--ctxf-primary); }
			.ctxf-dr .ctxf-ic-yellow { width:34px; height:34px; border-radius:9px; background:#fdf3d7; border:1px solid #f0dca0; color:#8a6320; }
			.ctxf-dr .ctxf-ic-yellow svg { width:17px; height:17px; }
			.ctxf-dr .ctxf-help-body { text-align:center; display:flex; flex-direction:column; align-items:center; padding:30px 24px 26px; }
			.ctxf-dr .ctxf-help-h { margin:18px 0 0; font-size:17px; font-weight:600; color:var(--ctxf-ink); }
			.ctxf-dr .ctxf-help-p { margin:8px 0 0; font-size:13.5px; line-height:1.55; color:var(--ctxf-muted); max-width:360px; }
			.ctxf-dr .ctxf-help-foot { justify-content:center; }
			.ctxf-dr .ctxf-reasons { list-style:none; margin:0 0 4px; padding:0; }
			.ctxf-dr .ctxf-reasons li { margin:0; padding:0; }
			.ctxf-dr .ctxf-reasons label { display:flex; align-items:flex-start; gap:11px; padding:9px 8px; border-radius:9px; cursor:pointer; font-size:14.5px; line-height:1.4; color:var(--ctxf-body); transition:background .12s; }
			.ctxf-dr .ctxf-reasons label:hover { background:var(--ctxf-wash); }
			.ctxf-dr .ctxf-reasons input[type=radio] { appearance:none; -webkit-appearance:none; flex:none; width:18px; height:18px; margin:1px 0 0; border:1.5px solid var(--ctxf-line); border-radius:50%; cursor:pointer; position:relative; transition:border-color .12s; background:#fff; }
			.ctxf-dr .ctxf-reasons input[type=radio]:checked { border-color:var(--ctxf-primary); }
			.ctxf-dr .ctxf-reasons input[type=radio]:checked::after { content:''; position:absolute; top:3px; left:3px; right:3px; bottom:3px; border-radius:50%; background:var(--ctxf-primary); }
			.ctxf-dr .ctxf-reasons input[type=radio]:focus { outline:2px solid var(--ctxf-primary); outline-offset:2px; box-shadow:none; }
			.ctxf-dr .ctxf-field { margin-top:12px; }
			.ctxf-dr .ctxf-label { display:block; font-size:12.5px; font-weight:600; color:var(--ctxf-body); margin-bottom:6px; }
			.ctxf-dr .ctxf-req { color:var(--ctxf-danger); }
			.ctxf-dr .ctxf-input, .ctxf-dr .ctxf-textarea { width:100%; font-family:inherit; font-size:14px; color:var(--ctxf-ink); background:#fff; border:1px solid var(--ctxf-line); border-radius:10px; padding:10px 12px; margin:0; box-shadow:none; transition:border-color .12s, box-shadow .12s; }
			.ctxf-dr .ctxf-textarea { resize:vertical; min-height:82px; line-height:1.5; }
			.ctxf-dr .ctxf-input::placeholder, .ctxf-dr .ctxf-textarea::placeholder { color:var(--ctxf-faint); }
			.ctxf-dr .ctxf-input:focus, .ctxf-dr .ctxf-textarea:focus { outline:none; border-color:var(--ctxf-primary); box-shadow:0 0 0 3px rgba(21,112,239,.14); }
			.ctxf-dr .ctxf-reason-foot { justify-content:space-between; }
			.ctxf-dr .ctxf-foot-right { display:flex; align-items:center; gap:10px; }
			.ctxf-dr .ctxf-fields { display:flex; flex-direction:column; gap:13px; }
			.ctxf-dr .ctxf-hint { margin:6px 0 0; font-size:11.5px; color:var(--ctxf-muted); }
			.ctxf-dr .ctxf-hint.is-success { color:var(--ctxf-success, #2fa365); }
			.ctxf-dr .ctxf-hint.is-error { color:var(--ctxf-danger); }
			.ctxf-dr .ctxf-count { float:right; color:var(--ctxf-faint); }
			.ctxf-dr .ctxf-contact-foot { background:var(--ctxf-paper); border-top-color:var(--ctxf-wash); }
			.ctxf-dr .ctxf-foot-note { flex:1; min-width:120px; font-size:11px; color:var(--ctxf-faint); }
			.ctxf-dr .ctxf-foot-note.is-error { color:#9d3b3f; }
			.ctxf-dr .ctxf-sent { text-align:center; display:flex; flex-direction:column; align-items:center; padding:32px 24px 34px; }
			.ctxf-dr .ctxf-ic-ok { width:44px; height:44px; border-radius:12px; background:#f1f8ee; border:1px solid #d7ead0; color:#2f8f4e; display:flex; align-items:center; justify-content:center; }
			.ctxf-dr .ctxf-ic-ok svg { width:22px; height:22px; fill:currentColor; }
			.ctxf-dr .ctxf-sent-h { margin:13px 0 0; font-size:15.5px; font-weight:600; color:var(--ctxf-ink); }
			.ctxf-dr .ctxf-sent-p { margin:5px 0 18px; font-size:13.5px; line-height:1.5; color:var(--ctxf-muted); max-width:340px; }
			/* BUG-0027: on short laptop viewports the 8vh top/bottom margin pushed the
				panel (esp. the taller Reason/Contact panels) partly off-screen and the
				footer buttons were unreachable. The overlay now scrolls (overflow-y:auto
				above); trim the margin here so little/no scrolling is needed. */
			@media (max-height:760px){ .ctxf-dr .ctxf-panel { margin:16px auto; } }
		</style>
		<script type="text/javascript">
			(function ($) {
				$(function () {
					var slug  = '<?php echo esc_js( $slug ); ?>',
						nonce = '<?php echo esc_js( wp_create_nonce( $this->client->getSlug() . '_insight_action' ) ); ?>',
						$modal = $('#' + slug + '-ctxf-dr');
					if (!$modal.length) { return; }
					var deactivateLink = '',
						$panels    = $modal.find('.ctxf-panel'),
						firstPanel = <?php echo $showSupportTicket ? "'help'" : "'reason'"; ?>;

					function showPanel(name) {
						$panels.removeClass('is-active');
						$modal.find('[data-panel="' + name + '"]').addClass('is-active');
					}
					function openModal(link) {
						deactivateLink = link;
						$modal.addClass('is-open').attr('aria-hidden', 'false');
						showPanel(firstPanel);
					}
					function closeModal() {
						$modal.removeClass('is-open').attr('aria-hidden', 'true');
						showPanel(firstPanel);
					}

					/**
					 * Post to the insight AJAX endpoint. When cb is a string the
					 * browser navigates there (the deactivate URL) on complete;
					 * when it is a function it receives the jqXHR wrapper.
					 */
					function ajaxSubmit(data, $btn, cb) {
						if ($btn.data('busy')) { return; }
						var original = $btn.text();
						$btn.data('busy', true).prop('disabled', true).text('<?php echo esc_js( __( 'Processing…', 'woo-feed' ) ); ?>');
						return $.ajax({
							url: ajaxurl,
							type: 'POST',
							data: $.extend({}, { action: slug + '_submit-uninstall-reason', _wpnonce: nonce }, data),
							complete: function (event, xhr, options) {
								if ('string' === typeof cb) {
									window.location.href = cb;
								} else if ('function' === typeof cb) {
									$btn.data('busy', false).prop('disabled', false).text(original);
									cb({ event: event, xhr: xhr, options: options });
								}
							}
						});
					}

					// Intercept the plugin's deactivate link on the plugins list.
					$('#the-list').on('click', 'a.' + slug + '-deactivate-link', function (e) {
						e.preventDefault();
						openModal($(this).attr('href'));
					});

					$modal
						.on('click', '[data-close]', function (e) { e.preventDefault(); closeModal(); })
						.on('click', '[data-go="reason"]', function (e) { e.preventDefault(); showPanel('reason'); })
						.on('click', '[data-go="contact"]', function (e) { e.preventDefault(); showPanel('contact'); revalidate(); })
						.on('change', 'input[name="ctxf-reason"]', function () {
							$modal.find('.ctxf-submit-deact').prop('disabled', !$modal.find('input[name="ctxf-reason"]:checked').length);
						})
						.on('click', '.ctxf-skip', function (e) {
							e.preventDefault();
							ajaxSubmit({ reason_id: 'no-comment', reason_info: '<?php echo esc_js( __( 'I rather wouldn\'t say', 'woo-feed' ) ); ?>' }, $(this), deactivateLink);
						})
						.on('click', '.ctxf-submit-deact', function (e) {
							e.preventDefault();
							var $radio = $modal.find('input[name="ctxf-reason"]:checked');
							ajaxSubmit({
								reason_id: $radio.length ? $radio.val() : 'none',
								reason_info: $.trim($modal.find('.ctxf-reason-msg').val())
							}, $(this), deactivateLink);
						});

					// Contact form.
					var EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/,
						$name = $modal.find('.ctxf-c-name'),
						$email = $modal.find('.ctxf-c-email'),
						$message = $modal.find('.ctxf-c-msg'),
						$send = $modal.find('.ctxf-send'),
						$hint = $modal.find('.ctxf-email-hint'),
						$count = $modal.find('.ctxf-count'),
						$note = $modal.find('.ctxf-foot-note');

					function revalidate() {
						var val = $.trim($email.val()), ok = EMAIL_RE.test(val), empty = '' === val;
						$hint.removeClass('is-success is-error');
						if (!empty) { $hint.addClass(ok ? 'is-success' : 'is-error'); }
						$hint.text(empty ? '<?php echo esc_js( __( 'We reply to this address.', 'woo-feed' ) ); ?>' : (ok ? '<?php echo esc_js( __( 'Looks right.', 'woo-feed' ) ); ?>' : '<?php echo esc_js( __( 'Enter a full email address.', 'woo-feed' ) ); ?>'));
						$count.text($message.val().length + ' / 2000');
						$send.prop('disabled', !($.trim($name.val()) && ok && $.trim($message.val())));
					}
					$name.add($email).add($message).on('input', revalidate);

					$modal.on('click', '.ctxf-send', function (e) {
						e.preventDefault();
						if ($send.prop('disabled')) { return; }
						ajaxSubmit({
							action: slug + '_submit-support-ticket',
							name: $name.val(),
							email: $email.val(),
							subject: $modal.find('.ctxf-c-subject').val(),
							website: $modal.find('.ctxf-c-website').val(),
							message: $message.val()
						}, $(this), function (jqXhr) {
							var response = jqXhr.event.responseJSON || {};
							if (response.success) {
								showPanel('sent');
							} else {
								var msg = (response && response.data) ? $('<div>').html(response.data).text() : '<?php echo esc_js( __( 'Could not send your message. Please try again.', 'woo-feed' ) ); ?>';
								$note.addClass('is-error').text(msg);
							}
						});
					});
				});
			}(jQuery));
		</script>
		<?php
	}

	/**
	 * Run after theme deactivated
	 *
	 * @param string   $new_name    New Theme Name.
	 * @param WP_Theme $new_theme   New Theme WP_Theme Object.
	 * @param WP_Theme $old_theme   Old Theme WP_Theme Object.
	 *
	 * @return void
	 */
	public function theme_deactivated( $new_name, $new_theme, $old_theme ) {
		// Make sure this is WebAppick theme.
		if ( $old_theme->get_template() === $this->client->getSlug() ) {
			$current_user = wp_get_current_user();
			/* @noinspection PhpUndefinedFieldInspection */
			$data = array(
				'hash'        => $this->client->getHash(),
				'reason_id'   => 'none',
				'reason_info' => wp_json_encode(
					array(
						'new_theme' => array(
							'name'         => $new_name,
							'version'      => $new_theme->version,
							'parent_theme' => $new_name->parent_theme,
							'author'       => $new_name->parent_theme,
						),
					)
				),
				'site'        => $this->__get_site_name(),
				'url'         => esc_url( home_url() ),
				'admin_email' => get_option( 'admin_email' ),
				'user_email'  => $current_user->user_email,
				'first_name'  => $current_user->first_name,
				'last_name'   => $current_user->last_name,
				'server'      => $this->__get_server_info(),
				'wp'          => $this->__get_wp_info(),
				'ip_address'  => $this->__get_user_ip_address(),
				'version'     => $this->client->getProjectVersion(),
			);
			$this->client->send_request( $data, 'reason' );
		}
	}

	/**
	 * Get user IP Address
	 *
	 * @return string
	 */
	private function __get_user_ip_address() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$response = wp_safe_remote_get( 'https://icanhazip.com/' );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$ip = trim( wp_remote_retrieve_body( $response ) );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $ip;
	}

	/**
	 * Get site name
	 *
	 * @return string
	 */
	private function __get_site_name() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.MethodDoubleUnderscore -- Method name is part of the vendored WebAppick services SDK surface; renaming it would diverge from upstream.
		$site_name = get_bloginfo( 'name' );
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'description' );
			$site_name = wp_trim_words( $site_name, 3, '' );
		}
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'url' );
		}

		return $site_name;
	}
}
// End of file Insights.php.
