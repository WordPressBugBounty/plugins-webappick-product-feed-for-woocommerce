<?php
/**
 * CTX Feed — Plugin lifecycle installer (activation, deactivation, upgrade).
 *
 * The V8 engine's installer. Self-contained: depends only on WordPress core
 * plus the WOO_FEED_* constants (defined by CTXFeed\V8\Bootstrap), so it keeps
 * working after the V5 engine, includes/, and libs/ are removed. It preserves
 * the same activation/deactivation/upgrade lifecycle as the V5-era
 * includes/class-woo-feed-installer.php so updating an existing install never
 * deactivates the plugin or errors.
 *
 * Intentionally dropped vs. the V5 installer:
 *  - create_cron_jobs() / cron_schedules(): V8 schedules feed generation via
 *    Action Scheduler, not WP-Cron.
 *  - migrate_db(): a settings migration for pre-3.3.10 installs that relied on
 *    a V5 helper (woo_feed_save_options); irrelevant at 8.x.
 *
 * @package CTXFeed
 * @since   8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die(); // Silence.
}

if ( ! class_exists( 'CTXFeed_Installer' ) ) {

	/**
	 * V8 plugin lifecycle handler.
	 *
	 * @since 8.0.0
	 */
	class CTXFeed_Installer {

		/**
		 * Register activation, deactivation, and upgrade-detection hooks.
		 *
		 * @return void
		 */
		public static function init() {
			register_activation_hook( WOO_FEED_FREE_FILE, array( __CLASS__, 'install' ) );
			register_deactivation_hook( WOO_FEED_FREE_FILE, array( __CLASS__, 'deactivate' ) );
			// Runs on every request; triggers install() when the stored version
			// is behind — this is what makes a plugin *update* self-heal.
			add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
			// One-time post-activation redirect into the Setup wizard
			// (fresh installs only — see maybe_flag_fresh_install()).
			add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_setup' ) );
		}

		/**
		 * Run the install/upgrade routine when the stored version is behind.
		 *
		 * @return void
		 */
		public static function check_version() {
			if ( defined( 'IFRAME_REQUEST' ) ) {
				return;
			}
			if ( version_compare( (string) get_option( 'woo_feed_free_version' ), WOO_FEED_FREE_VERSION, '<' ) ) {
				if ( ! defined( 'WOO_FEED_UPDATING' ) ) {
					define( 'WOO_FEED_UPDATING', true );
				}
				self::install();
				do_action( 'woo_feed_plugin_updated' );
			}
		}

		/**
		 * Activation + upgrade routine.
		 *
		 * @return void
		 */
		public static function install() {
			if ( ! is_blog_installed() ) {
				return;
			}

			// Minimum PHP guard — abort activation with a readable notice.
			if ( version_compare( PHP_VERSION, WOO_FEED_MIN_PHP_VERSION, '<' ) ) {
				/* translators: 1: minimum required php version, 2: server php version */
				echo '<div class="notice error"><p>' . sprintf( esc_html__( 'The Minimum PHP Version Requirement for CTX Feed is %1$s. You are Running PHP %2$s', 'woo-feed' ), esc_html( WOO_FEED_MIN_PHP_VERSION ), esc_html( PHP_VERSION ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Every interpolated value is individually escaped (esc_html__ / esc_html); only the static markup wrapper is unescaped, which the sniff cannot see through the sprintf().
				die();
			}

			// Re-entrancy guard.
			if ( 'yes' === get_transient( 'woo_feed_installing' ) ) {
				return;
			}
			set_transient( 'woo_feed_installing', 'yes', 10 * MINUTE_IN_SECONDS );

			// Must run BEFORE update_version() writes the version option —
			// the option's absence is what identifies a genuinely fresh install.
			self::maybe_flag_fresh_install();

			self::pro_version_warning();
			self::create_files();
			self::update_version();

			delete_transient( 'woo_feed_installing' );
		}

		/**
		 * Flag a genuinely fresh install for the Setup wizard.
		 *
		 * A fresh install is one where the version option has never been
		 * written AND no feed configs exist. Upgrading users (100K+ installs)
		 * always have `woo_feed_free_version` from a previous run, so the
		 * flag is never set for them; the feed-config check additionally
		 * protects reinstalls where the option was cleaned but feeds remain.
		 *
		 * Sets two markers:
		 *  - `ctxfeed_setup_pending` option — read by Admin\Assets and cleared
		 *    by the SetupEndpoint when the wizard finishes or is skipped.
		 *  - `ctxfeed_activation_redirect` transient — consumed once by
		 *    maybe_redirect_to_setup() on the next admin page load.
		 *
		 * @since 8.0.0
		 *
		 * @return void
		 */
		private static function maybe_flag_fresh_install() {
			if ( false !== get_option( 'woo_feed_free_version' ) ) {
				return;
			}

			if ( self::has_existing_feeds() ) {
				return;
			}

			update_option( 'ctxfeed_setup_pending', 'yes', false );
			set_transient( 'ctxfeed_activation_redirect', 'yes', MINUTE_IN_SECONDS );
		}

		/**
		 * Whether any feed config exists (cheapest possible check).
		 *
		 * Feeds are stored as `wf_feed_{slug}` options (shared V5/V8 storage,
		 * same source FeedEndpoint::get_feeds() enumerates); a single-row
		 * LIKE lookup answers existence without loading any of them.
		 *
		 * @since 8.0.0
		 *
		 * @return bool
		 */
		private static function has_existing_feeds() {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existence probe on wp_options; no WP API offers a LIKE lookup, and the answer is used once per activation so caching would only add a stale layer.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
					$wpdb->esc_like( 'wf_feed_' ) . '%'
				)
			);

			return ! empty( $found );
		}

		/**
		 * One-time redirect into the Setup wizard after a fresh activation.
		 *
		 * Standard WP activation-redirect pattern: the transient is deleted
		 * FIRST so the redirect can never loop, then the redirect only fires
		 * for a normal, single-plugin, capable-user admin request.
		 *
		 * @since 8.0.0
		 *
		 * @return void
		 */
		public static function maybe_redirect_to_setup() {
			if ( 'yes' !== get_transient( 'ctxfeed_activation_redirect' ) ) {
				return;
			}

			// Delete before any guard so a skipped redirect never re-fires.
			delete_transient( 'ctxfeed_activation_redirect' );

			if ( wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only (bulk plugin activation), no data is used.
			if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
				return;
			}

			// `manage_woocommerce` is the plugin's admin capability; it does
			// not exist before WooCommerce has ever been installed, so fall
			// back to `manage_options` (administrators) — the wizard's first
			// step is exactly the surface that installs WooCommerce.
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=webappick-manage-feeds#/setup' ) );
			exit;
		}

		/**
		 * Deactivation — flush CTX Feed transient caches (V5 + V8 prefixes).
		 *
		 * Mirrors the V5 woo_feed_deactivate()/woo_feed_flush_cache_data()
		 * behaviour without depending on the (removed) V5 helper.
		 *
		 * @return void
		 */
		public static function deactivate() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bulk transient purge on deactivation; no WP API deletes transients by LIKE pattern, and caching a DELETE is meaningless.
			$wpdb->query(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_timeout___woo_feed_cache_%'
				    OR option_name LIKE '_transient___woo_feed_cache_%'
				    OR option_name LIKE '_transient_timeout_ctxfeed_%'
				    OR option_name LIKE '_transient_ctxfeed_%'"
			);
		}

		/**
		 * Legacy guard — now a NO-OP for the V8 thin-unlock model.
		 *
		 * The old CTX Feed Pro bundled its own copy of Free, so activating
		 * standalone Free while Pro was active double-loaded the code and this
		 * blocked it (die()). The new Pro (CTXFeed\Pro) bundles no Free and is
		 * designed to run ALONGSIDE it — Free must NOT refuse activation when
		 * Pro is present.
		 *
		 * @return void
		 */
		private static function pro_version_warning() {
			// Intentionally empty — Free and Pro coexist in V8.
		}

		/**
		 * Create the log directory and hardening files (deny-all .htaccess +
		 * empty index.html) to prevent listing/hotlinking of feed logs.
		 *
		 * @return void
		 */
		private static function create_files() {
			if ( apply_filters( 'woo_feed_install_skip_create_files', false ) ) {
				return;
			}
			$files = array(
				array(
					'base'    => WOO_FEED_LOG_DIR,
					'file'    => '.htaccess',
					'content' => 'deny from all',
				),
				array(
					'base'    => WOO_FEED_LOG_DIR,
					'file'    => 'index.html',
					'content' => '',
				),
			);

			foreach ( $files as $file ) {
				if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
					// phpcs:ignore Generic.PHP.NoSilencedErrors, WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runs during activation, before WP_Filesystem credentials can be requested; the `@` suppresses the open warning on hosts where the uploads dir is not writable, and the `false !== $handle` check below is the real error handling.
					$handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' );
					if ( false !== $handle ) {
						// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writes the index.html / .htaccess guards that keep the feed and log directories from being listed; WP_Filesystem is not available this early in activation.
						fwrite( $handle, $file['content'] );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above; paired with the direct fopen() call.
						fclose( $handle );
					}
				}
			}
		}

		/**
		 * Persist the current version and the first-activation timestamp.
		 *
		 * @return void
		 */
		private static function update_version() {
			delete_option( 'woo_feed_version' ); // Legacy option name.
			update_option( 'woo_feed_free_version', WOO_FEED_FREE_VERSION, false );

			$is_update = defined( 'WOO_FEED_UPDATING' ) && WOO_FEED_UPDATING;
			if ( ! $is_update ) {
				update_option( 'woo-feed-free-activation-time', get_option( 'woo-feed-activation-time', time() ), false );
			}
		}
	}
}

CTXFeed_Installer::init();
