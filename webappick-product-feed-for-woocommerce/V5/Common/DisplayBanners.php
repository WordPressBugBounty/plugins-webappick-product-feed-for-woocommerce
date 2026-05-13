<?php

namespace CTXFeed\V5\Common;

/**
 * Class DisplayBanners
 *
 * Handles banner display functionality including lifetime banner.
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Common
 * @since      6.2
 */
class DisplayBanners {

	/**
	 * User meta key for lifetime banner dismissal.
	 *
	 * @var string
	 */
	const LIFETIME_BANNER_META_KEY = 'ctx_feed_lifetime_banner_dismissed';

	/**
	 * Number of days to hide the banner after dismissal.
	 *
	 * @var int
	 */
	const BANNER_DISMISS_DAYS = 15;

	/**
	 * Lifetime product IDs.
	 *
	 * @var array
	 */
	const LIFETIME_PRODUCT_IDS = array( 63687, 63686, 63685, 106128, 106132, 106133 );

	/**
	 * Get plugin slug name.
	 *
	 * @return string
	 */
	public static function get_slugname() {
		$plugins_all = get_plugins();
		$plugin_slug = explode( '/', dirname( plugin_basename( __FILE__ ) ) );
		$slug        = '';

		foreach ( $plugins_all as $key => $value ) {
			if ( $plugin_slug[0] == explode( '/', $key )[0] ) {
				$slug = explode( '/', $key )[0];
			}
		}

		return $slug;
	}

	/**
	 * Check if user has a lifetime subscription.
	 *
	 * @return bool True if user has lifetime subscription, false otherwise.
	 */
	public static function is_lifetime_user() {
		$slug       = self::get_slugname();
		$key        = md5( $slug );
		$option_key = 'WebAppick_' . $key . '_manage_license';

		$license_data = get_option( $option_key );

		if ( ! empty( $license_data ) && is_array( $license_data ) ) {
			if (
				strtolower( $license_data['status'] ) === 'active' &&
				isset( $license_data['product_id'] ) &&
				in_array( $license_data['product_id'], self::LIFETIME_PRODUCT_IDS )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lifetime Banner should be shown.
	 *
	 * @return bool True if user has lifetime subscription.
	 * @since  6.2
	 * @deprecated Use is_lifetime_user() instead.
	 */
	public static function life_time_banner_should_shown() {
		return self::is_lifetime_user();
	}

	/**
	 * Check if the lifetime banner should be shown for the current user.
	 *
	 * @return bool True if banner should be shown, false if dismissed or user already has lifetime subscription.
	 */
	public static function should_show_lifetime_banner() {
		// Don't show banner if user already has a Lifetime subscription.
		if ( self::is_lifetime_user() ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return true;
		}

		$dismissed_until = get_user_meta( $user_id, self::LIFETIME_BANNER_META_KEY, true );

		if ( empty( $dismissed_until ) ) {
			return true;
		}

		$dismissed_until = absint( $dismissed_until );

		// If current time is past the dismissal period, show the banner.
		if ( time() > $dismissed_until ) {
			// Clean up expired meta.
			delete_user_meta( $user_id, self::LIFETIME_BANNER_META_KEY );
			return true;
		}

		return false;
	}

	/**
	 * Dismiss the lifetime banner for the current user.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function dismiss_lifetime_banner() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$dismiss_until = time() + ( self::BANNER_DISMISS_DAYS * DAY_IN_SECONDS );

		return update_user_meta( $user_id, self::LIFETIME_BANNER_META_KEY, $dismiss_until );
	}

	/**
	 * Get the lifetime banner status for the current user.
	 *
	 * @return array Banner status data.
	 */
	public static function get_lifetime_banner_status() {
		$user_id          = get_current_user_id();
		$is_lifetime_user = self::is_lifetime_user();
		$show_banner      = self::should_show_lifetime_banner();
		$dismissed_until  = 0;

		if ( $user_id ) {
			$dismissed_until = get_user_meta( $user_id, self::LIFETIME_BANNER_META_KEY, true );
			$dismissed_until = ! empty( $dismissed_until ) ? absint( $dismissed_until ) : 0;
		}

		return array(
			'show_banner'      => $show_banner,
			'is_lifetime_user' => $is_lifetime_user,
			'dismissed_until'  => $dismissed_until,
			'dismiss_days'     => self::BANNER_DISMISS_DAYS,
		);
	}
}
