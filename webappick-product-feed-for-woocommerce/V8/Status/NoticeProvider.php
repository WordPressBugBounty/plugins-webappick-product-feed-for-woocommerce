<?php
/**
 * NoticeProvider — aggregates the admin notices shown in the React app.
 *
 * The React admin renders its own notice slider (and a full list on the
 * System status page) instead of the wp-admin notice area, which is
 * suppressed on our screen. This provider is the single server-side source
 * of that list: it collects notices from free "sources" plus everything
 * hooked onto the `ctxfeed_notices` registry filter (Pro contributes the
 * License-Expired notice there), normalizes the shape, drops the notices the
 * current user has dismissed, and sorts them for the slider.
 *
 * Notice shape:
 *   [
 *     'id'          => 'ctxfeed_debug_disabled', // stable, unique — dismissal key
 *     'severity'    => 'error'|'warning'|'info'|'upgrade'|'success',
 *     'title'       => 'Debug logging is off',
 *     'message'     => 'Turn on error & debug logging …',
 *     'action'      => [ 'label' => 'Open settings', 'url' => '/settings', 'target' => '' ], // optional
 *     'dismissible' => true,   // false = pinned (e.g. License expired)
 *     'priority'    => 40,     // lower shows first; default 50
 *   ]
 *
 * @package    CTXFeed
 * @subpackage V8/Status
 * @since      8.0.0
 */

namespace CTXFeed\V8\Status;

use CTXFeed\V8\Admin\Notices;
use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects, normalizes, and filters the admin notices for the React app.
 *
 * @since 8.0.0
 */
class NoticeProvider {

	/**
	 * Allowed severity values.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	const SEVERITIES = array( 'error', 'warning', 'info', 'upgrade', 'success' );

	/**
	 * Default priority when a notice omits one.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const DEFAULT_PRIORITY = 50;

	/**
	 * The Pro plugin basename — used to detect installed-but-inactive Pro so
	 * the free plugin can prompt to activate it (Pro's own code can't run then).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PRO_BASENAME = 'webappick-product-feed-for-woocommerce-pro/webappick-product-feed-for-woocommerce-pro.php';

	/**
	 * Build the notice list for the current user.
	 *
	 * @since 8.0.0
	 *
	 * @return array List of normalized notice arrays, sorted by priority.
	 */
	public function collect(): array {
		$notices = array();

		$notices = array_merge( $notices, $this->debug_logging_notice() );
		$notices = array_merge( $notices, $this->pro_inactive_notice() );
		$notices = array_merge( $notices, $this->pro_integration_notices() );

		/**
		 * Registry filter for admin notices shown in the React app.
		 *
		 * Pro contributes the License-Expired notice here (only Pro can read
		 * the license); extensions can add their own. Each entry follows the
		 * notice shape documented on this class.
		 *
		 * @since 8.0.0
		 *
		 * @param array $notices List of notice arrays.
		 */
		$notices = apply_filters( 'ctxfeed_notices', $notices );

		$notices = $this->normalize( $notices );
		$notices = $this->drop_dismissed( $notices );

		usort(
			$notices,
			static function ( $a, $b ) {
				return array( $a['priority'], $a['id'] ) <=> array( $b['priority'], $b['id'] );
			}
		);

		return $notices;
	}

	/**
	 * Normalize raw notices: fill defaults, coerce severity, dedupe by id.
	 *
	 * @since 8.0.0
	 *
	 * @param array $notices Raw notice arrays.
	 * @return array Normalized notices (values, keyed-dedupe applied).
	 */
	private function normalize( array $notices ): array {
		$out = array();

		foreach ( $notices as $notice ) {
			if ( empty( $notice['id'] ) ) {
				continue;
			}

			// `severity` is canonical; `type` is the legacy alias.
			$severity = '';
			if ( ! empty( $notice['severity'] ) ) {
				$severity = (string) $notice['severity'];
			} elseif ( ! empty( $notice['type'] ) ) {
				$severity = (string) $notice['type'];
			}
			if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
				$severity = 'info';
			}

			$id = (string) $notice['id'];

			// Last writer wins on duplicate ids.
			$out[ $id ] = array(
				'id'          => $id,
				'severity'    => $severity,
				'type'        => $severity, // Back-compat alias.
				'title'       => isset( $notice['title'] ) ? (string) $notice['title'] : '',
				'message'     => isset( $notice['message'] ) ? (string) $notice['message'] : '',
				'action'      => $this->normalize_action( isset( $notice['action'] ) ? $notice['action'] : null ),
				'dismissible' => array_key_exists( 'dismissible', $notice ) ? (bool) $notice['dismissible'] : true,
				'priority'    => isset( $notice['priority'] ) ? (int) $notice['priority'] : self::DEFAULT_PRIORITY,
			);
		}

		return array_values( $out );
	}

	/**
	 * Normalize a notice action, or return null when incomplete.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $action Raw action value.
	 * @return array|null { label, url, target } or null.
	 */
	private function normalize_action( $action ): ?array {
		if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
			return null;
		}

		return array(
			'label'  => (string) $action['label'],
			'url'    => (string) $action['url'],
			'target' => isset( $action['target'] ) ? (string) $action['target'] : '',
		);
	}

	/**
	 * Remove notices the current user has dismissed.
	 *
	 * Non-dismissible notices (e.g. License expired) are never removed, even
	 * if their id somehow appears in the dismissed list.
	 *
	 * @since 8.0.0
	 *
	 * @param array $notices Normalized notices.
	 * @return array Notices minus this user's dismissed, dismissible ones.
	 */
	private function drop_dismissed( array $notices ): array {
		$dismissed = ( new Notices() )->get_dismissed_notice_ids();
		if ( empty( $dismissed ) ) {
			return $notices;
		}

		return array_values(
			array_filter(
				$notices,
				static function ( $notice ) use ( $dismissed ) {
					if ( empty( $notice['dismissible'] ) ) {
						return true;
					}
					return ! in_array( $notice['id'], $dismissed, true );
				}
			)
		);
	}

	/**
	 * Free source: warn when error/debug logging is disabled.
	 *
	 * @since 8.0.0
	 *
	 * @return array Zero or one notice.
	 */
	private function debug_logging_notice(): array {
		$settings = get_option( 'woo_feed_settings', array() );
		$enabled  = is_array( $settings )
			&& isset( $settings['enable_error_debugging'] )
			&& 'on' === $settings['enable_error_debugging'];

		if ( $enabled ) {
			return array();
		}

		return array(
			array(
				'id'          => 'ctxfeed_debug_disabled',
				'severity'    => 'warning',
				'priority'    => 40,
				'title'       => __( 'Debug logging is off', 'woo-feed' ),
				'message'     => __( 'Turn on error & debug logging so feed problems can be diagnosed from the System status page.', 'woo-feed' ),
				'action'      => array(
					'label' => __( 'Open settings', 'woo-feed' ),
					'url'   => '/settings',
				),
				'dismissible' => true,
			),
		);
	}

	/**
	 * Free source: one upsell per active plugin whose CTX integration is Pro-only.
	 *
	 * Skipped entirely when Pro is active — the integration then runs and there
	 * is nothing to upsell.
	 *
	 * @since 8.0.0
	 *
	 * @return array Zero or more notices.
	 */
	private function pro_integration_notices(): array {
		// Skip when Pro is present at all. Active + licensed runs the
		// integrations; installed-but-inactive (or unlicensed) is covered by the
		// "activate" notices instead of a misleading "upgrade to Pro" upsell for
		// a store that already owns Pro.
		if ( FeatureGate::is_pro() || $this->pro_installed() ) {
			return array();
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();

		foreach ( $this->pro_gated_plugins() as $path => $label ) {
			if ( ! is_plugin_active( $path ) ) {
				continue;
			}

			$slug  = sanitize_key( dirname( $path ) );
			$out[] = array(
				'id'          => 'ctxfeed_pro_integration_' . $slug,
				'severity'    => 'upgrade',
				'priority'    => 30,
				/* translators: %s: third-party plugin name. */
				'title'       => sprintf( __( '%s integration is a Pro feature', 'woo-feed' ), $label ),
				/* translators: %s: third-party plugin name. */
				'message'     => sprintf( __( '%s is active, but CTX Feed’s integration for it is only available in Pro.', 'woo-feed' ), $label ),
				'action'      => array(
					'label' => __( 'Upgrade to Pro', 'woo-feed' ),
					'url'   => '/premium',
				),
				'dismissible' => true,
			);
		}

		return $out;
	}

	/**
	 * The plugin-path → display-name map of Pro-only integrations.
	 *
	 * Seeded from the Pro CompatibilityFactory map so the free plugin can name
	 * the plugin without Pro loaded. Filterable so Pro/extensions can extend it.
	 *
	 * @since 8.0.0
	 *
	 * @return array<string,string> Plugin file path => human label.
	 */
	private function pro_gated_plugins(): array {
		$plugins = array(
			'woocommerce-currency-switcher/index.php'   => 'WOOCS Currency Switcher',
			'currency-switcher-woocommerce/currency-switcher-woocommerce.php' => 'Currency Switcher for WooCommerce',
			'woocommerce-aelia-currencyswitcher/woocommerce-aelia-currencyswitcher.php' => 'Aelia Currency Switcher',
			'woo-multi-currency/woo-multi-currency.php' => 'Woo Multi Currency',
			'sitepress-multilingual-cms/sitepress.php'  => 'WPML',
			'polylang/polylang.php'                     => 'Polylang',
			'translatepress-multilingual/index.php'     => 'TranslatePress',
			'woocommerce-subscriptions/woocommerce-subscriptions.php' => 'WooCommerce Subscriptions',
			'woocommerce-composite-products/woocommerce-composite-products.php' => 'WooCommerce Composite Products',
			'woocommerce-product-bundles/woocommerce-product-bundles.php' => 'WooCommerce Product Bundles',
			'woocommerce-germanized/woocommerce-germanized.php' => 'WooCommerce Germanized',
		);

		/**
		 * Filter the Pro-only integration plugin map.
		 *
		 * @since 8.0.0
		 *
		 * @param array<string,string> $plugins Plugin file path => human label.
		 */
		return apply_filters( 'ctxfeed_pro_gated_plugins', $plugins );
	}

	/**
	 * Free source: prompt to ACTIVATE the Pro plugin when it is installed but
	 * inactive. Pro's own code can't run to say this, so the free plugin does.
	 *
	 * @since 8.0.0
	 *
	 * @return array Zero or one notice.
	 */
	private function pro_inactive_notice(): array {
		if ( ! $this->pro_installed() || $this->pro_active() ) {
			return array();
		}

		$activate_url = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::PRO_BASENAME ) ),
			'activate-plugin_' . self::PRO_BASENAME
		);

		return array(
			array(
				'id'          => 'ctxfeed_pro_inactive',
				'severity'    => 'warning',
				'priority'    => 20,
				'title'       => __( 'Activate CTX Feed Pro', 'woo-feed' ),
				'message'     => __( 'CTX Feed Pro is installed but not active. Activate it to unlock Pro features and channel integrations.', 'woo-feed' ),
				'action'      => array(
					'label'  => __( 'Activate CTX Feed Pro', 'woo-feed' ),
					'url'    => $activate_url,
					'target' => '_self',
				),
				'dismissible' => false,
			),
		);
	}

	/**
	 * Whether the Pro plugin file is installed (active or not).
	 *
	 * @since 8.0.0
	 * @return bool
	 */
	private function pro_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_key_exists( self::PRO_BASENAME, get_plugins() );
	}

	/**
	 * Whether the Pro plugin is active.
	 *
	 * @since 8.0.0
	 * @return bool
	 */
	private function pro_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::PRO_BASENAME );
	}
}
