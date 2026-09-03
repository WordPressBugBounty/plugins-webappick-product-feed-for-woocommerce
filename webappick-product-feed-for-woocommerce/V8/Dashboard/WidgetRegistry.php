<?php
/**
 * Widget Registry — Manages dashboard widget definitions.
 * Extensible via filters for Pro features and third-party widgets.
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-1.2
 */

namespace CTXFeed\V8\Dashboard;

/**
 * Class WidgetRegistry
 *
 * Holds the dashboard widget definitions rendered by the admin UI and
 * exposes them for extension through the `ctxfeed_dashboard_widgets` filter.
 *
 * @since 8.0.0
 */
class WidgetRegistry {

	/**
	 * Registered widgets.
	 *
	 * @var array[]
	 */
	private array $widgets = array();

	/**
	 * Whether the default widgets have been registered yet.
	 *
	 * @var bool
	 */
	private bool $defaults_registered = false;

	/**
	 * Register default (free) widgets — LAZILY, on first access.
	 *
	 * Deliberately NOT called from the constructor: the container
	 * instantiates this class while booting on `plugins_loaded`, and the
	 * default titles are translated. Translating before `init` makes
	 * WordPress ≥ 6.7 load the woo-feed textdomain just-in-time and log
	 * "_load_textdomain_just_in_time was called incorrectly" on every
	 * page load (wp.org report). Widgets are only ever READ from REST
	 * requests, which run after `init` — so translating at first access
	 * is both correct and notice-free.
	 */
	private function registerDefaults(): void {
		if ( $this->defaults_registered ) {
			return;
		}
		$this->defaults_registered = true;
		$this->register(
			'overview_cards',
			array(
				'title'    => __( 'Overview', 'woo-feed' ),
				'type'     => 'stats',
				'position' => 'top',
				'priority' => 10,
				'pro'      => false,
			) 
		);

		$this->register(
			'channel_grid',
			array(
				'title'    => __( 'Channel Performance', 'woo-feed' ),
				'type'     => 'grid',
				'position' => 'main',
				'priority' => 20,
				'pro'      => false,
			) 
		);

		$this->register(
			'activity_timeline',
			array(
				'title'    => __( 'Recent Activity', 'woo-feed' ),
				'type'     => 'timeline',
				'position' => 'main',
				'priority' => 30,
				'pro'      => false,
			) 
		);

		$this->register(
			'health_alerts',
			array(
				'title'    => __( 'Health Alerts', 'woo-feed' ),
				'type'     => 'alerts',
				'position' => 'sidebar',
				'priority' => 10,
				'pro'      => false,
			) 
		);

		$this->register(
			'quick_actions',
			array(
				'title'    => __( 'Quick Actions', 'woo-feed' ),
				'type'     => 'actions',
				'position' => 'sidebar',
				'priority' => 20,
				'pro'      => false,
			) 
		);

		$this->register(
			'performance_chart',
			array(
				'title'    => __( 'Performance Over Time', 'woo-feed' ),
				'type'     => 'chart',
				'position' => 'bottom',
				'priority' => 10,
				'pro'      => false,
			) 
		);
	}

	/**
	 * Register a widget.
	 *
	 * @param string $id     Unique widget identifier.
	 * @param array  $config Widget configuration.
	 */
	public function register( string $id, array $config ): void {
		// Defaults first, so external registrations layer on top of them
		// exactly as they did when the constructor registered defaults.
		$this->registerDefaults();

		$this->widgets[ $id ] = array_merge(
			array(
				'id'       => $id,
				'title'    => '',
				'type'     => 'custom',
				'position' => 'main',
				'priority' => 50,
				'pro'      => false,
			),
			$config 
		);
	}

	/**
	 * Deregister a widget.
	 *
	 * @param string $id Widget identifier to remove.
	 */
	public function deregister( string $id ): void {
		$this->registerDefaults();
		unset( $this->widgets[ $id ] );
	}

	/**
	 * Get all registered widgets, sorted by position and priority.
	 * Filters out Pro widgets if FeatureGate is not active.
	 *
	 * @return array[] Widgets grouped by position.
	 */
	public function getWidgets(): array {
		$this->registerDefaults();

		$widgets = apply_filters( 'ctxfeed_dashboard_widgets', $this->widgets );

		// Filter out Pro widgets if not licensed.
		$widgets = array_filter(
			$widgets,
			function ( $widget ) {
				if ( ! empty( $widget['pro'] ) ) {
					return apply_filters( 'ctxfeed_feature_dashboard_pro_widgets', false );
				}
				return true;
			} 
		);

		// Sort by priority within each position.
		uasort(
			$widgets,
			function ( $a, $b ) {
				$pos_order = array(
					'top'     => 1,
					'main'    => 2,
					'sidebar' => 3,
					'bottom'  => 4,
				);
				$pos_a     = $pos_order[ $a['position'] ] ?? 5;
				$pos_b     = $pos_order[ $b['position'] ] ?? 5;

				if ( $pos_a !== $pos_b ) {
					return $pos_a <=> $pos_b;
				}

				return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
			} 
		);

		return array_values( $widgets );
	}
}
