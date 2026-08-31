<?php
/**
 * Notices.
 *
 * Displays admin notices for V8 engine status, migration prompts,
 * and queued error alerts. Handles AJAX-based notice dismissals
 * with per-user persistence via user meta.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 * @implements ADM-FRD-2.1, ADM-FRD-2.2, ADM-FRD-2.3
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Notices
 *
 * Manages admin notices including engine status, migration availability,
 * error queues, and persistent dismissals.
 *
 * @since 8.0.0
 */
class Notices {

	/**
	 * User meta key for dismissed notices.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const DISMISS_META_KEY = 'ctxfeed_dismissed_notices';

	/**
	 * Display all applicable admin notices.
	 *
	 * Hooked to `admin_notices` by AdminServiceProvider::boot().
	 * Collects notices, filters them, checks dismissals, then renders.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook filter ctxfeed_admin_notices — Filter notices before rendering.
	 */
	public function display_notices(): void {
		$notices = array();

		// Engine-switch notices removed — V8 is the sole engine.

		// Queued error notices from transient.
		// Implements ADM-FRD-2.1 (Error Notices).
		$errors = get_transient( 'ctxfeed_v8_admin_errors' );
		if ( ! empty( $errors ) ) {
			foreach ( (array) $errors as $error ) {
				$notices[] = array(
					'id'          => 'ctxfeed_v8_error_' . md5( $error ),
					'message'     => $error,
					'type'        => 'error',
					'dismissible' => false,
				);
			}
			delete_transient( 'ctxfeed_v8_admin_errors' );
		}

		/**
		 * Filter the notices before rendering.
		 *
		 * Pro/third-party plugins can add, remove, or modify notices.
		 *
		 * @since 8.0.0
		 *
		 * @param array $notices Array of notice arrays with keys: id, message, type, dismissible.
		 */
		$notices = apply_filters( 'ctxfeed_admin_notices', $notices );

		if ( empty( $notices ) ) {
			return;
		}

		// Get dismissed notices for the current user.
		// Implements ADM-FRD-2.2.
		$dismissed = $this->get_dismissed_notices();

		foreach ( $notices as $notice ) {
			// Skip dismissed notices.
			if ( ! empty( $notice['id'] ) && in_array( $notice['id'], $dismissed, true ) ) {
				continue;
			}

			$this->render_notice(
				$notice['message'],
				isset( $notice['type'] ) ? $notice['type'] : 'info',
				! empty( $notice['dismissible'] ),
				isset( $notice['id'] ) ? $notice['id'] : ''
			);
		}
	}

	/**
	 * Strip ALL admin notices on the CTX Feed screen.
	 *
	 * The React app renders its own notice slider (see Status\NoticeProvider),
	 * so wp-admin's notice area — third-party notices, WordPress core notices,
	 * and our own producers alike — is silenced on our page and surfaced inside
	 * the app instead. Gated to our screen only; every other admin screen is
	 * untouched.
	 *
	 * Hooked to `in_admin_header` (fires after get_current_screen() is set and
	 * all notices are attached, but before any notice bucket outputs), so the
	 * order callbacks registered in is irrelevant.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function suppress_foreign_notices(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, AdminPage::MENU_SLUG ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Handle AJAX notice dismiss request.
	 *
	 * Hooked to `wp_ajax_ctxfeed_dismiss_notice` by AdminServiceProvider::boot().
	 * Verifies nonce, stores notice ID in user meta.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( 'ctxfeed_dismiss_nonce', 'nonce' );

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';

		if ( empty( $notice_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing notice ID.', 'woo-feed' ) )
			);
		}

		$this->dismiss_notice( $notice_id );

		wp_send_json_success(
			array( 'dismissed' => $notice_id )
		);
	}

	/**
	 * Persist a per-user dismissal for a notice id.
	 *
	 * Shared by the legacy AJAX handler and the REST notices endpoint so a
	 * dismissal from either surface hides the notice everywhere for that user.
	 *
	 * @since 8.0.0
	 *
	 * @param string $notice_id Notice identifier.
	 * @return void
	 */
	public function dismiss_notice( string $notice_id ): void {
		if ( '' === $notice_id ) {
			return;
		}

		$user_id   = get_current_user_id();
		$dismissed = $this->get_dismissed_notices();

		if ( ! in_array( $notice_id, $dismissed, true ) ) {
			$dismissed[] = $notice_id;
			update_user_meta( $user_id, self::DISMISS_META_KEY, $dismissed );
		}
	}

	/**
	 * Public accessor for the current user's dismissed notice ids.
	 *
	 * Used by the NoticeProvider to filter the React-app notice list.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Dismissed notice ids.
	 */
	public function get_dismissed_notice_ids(): array {
		return $this->get_dismissed_notices();
	}

	/**
	 * Get the list of dismissed notice IDs for the current user.
	 *
	 * @since 8.0.0
	 *
	 * @return array List of dismissed notice ID strings.
	 */
	private function get_dismissed_notices(): array {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISS_META_KEY, true );

		if ( ! is_array( $dismissed ) ) {
			return array();
		}

		return $dismissed;
	}

	/**
	 * Render a single admin notice.
	 *
	 * @since 8.0.0
	 *
	 * @param string $message     Notice message text.
	 * @param string $type        Notice type: info, success, warning, error.
	 * @param bool   $dismissible Whether the notice can be dismissed.
	 * @param string $notice_id   Unique notice identifier for dismiss tracking.
	 * @return void
	 */
	private function render_notice( string $message, string $type = 'info', bool $dismissible = false, string $notice_id = '' ): void {
		$classes = sprintf( 'notice notice-%s', esc_attr( $type ) );

		if ( $dismissible ) {
			$classes .= ' is-dismissible';
		}

		$data_attr = '';
		if ( ! empty( $notice_id ) ) {
			$data_attr = sprintf( ' data-notice-id="%s"', esc_attr( $notice_id ) );
		}

		printf(
			'<div class="%s"%s><p>%s</p></div>',
			esc_attr( $classes ),
			$data_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html( $message )
		);
	}

	/**
	 * Check whether the V8 engine Bootstrap class is available.
	 *
	 * @since 8.0.0
	 *
	 * @return bool True if V8 Bootstrap class exists.
	 */
	private function is_v8_ready(): bool {
		return class_exists( \CTXFeed\V8\Bootstrap::class );
	}
}
