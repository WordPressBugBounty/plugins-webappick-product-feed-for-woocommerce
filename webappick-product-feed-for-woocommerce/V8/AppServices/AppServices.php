<?php
/**
 * AppServices — V8 telemetry wiring for the WebAppick AppServices SDK.
 *
 * Native V8 rebuild of the telemetry core previously wired by the V5-era
 * includes/classes/class-woo-feed-webappick-api.php. Instantiates the SDK
 * (relocated to V8/AppServices/), initialises usage Insights + Promotions, and
 * registers the tracking-data description and support-ticket filters.
 *
 * Scope: telemetry core only. The V5 wiring's upsell admin UI (review-nag
 * notice, WPML/RP-WCDPD compatibility notices, Premium/Our-Plugins menu and
 * marketing pages) is intentionally NOT ported here — that is React-admin
 * territory and depends on the removed admin/ partials; it is a separate task.
 *
 * @package    CTXFeed
 * @subpackage V8/AppServices
 * @since      8.0.0
 */

namespace CTXFeed\V8\AppServices;

use CTXFeed\AppServices\Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V8 telemetry manager (usage insights + promotions).
 *
 * @since 8.0.0
 */
final class AppServices {

	/**
	 * Singleton instance.
	 *
	 * @since 8.0.0
	 * @var AppServices|null
	 */
	private static $instance = null;

	/**
	 * SDK client.
	 *
	 * @since 8.0.0
	 * @var Client
	 */
	private $client;

	/**
	 * Insights (usage tracking) service.
	 *
	 * @since 8.0.0
	 * @var \CTXFeed\AppServices\Insights
	 */
	private $insights;

	/**
	 * Promotions service.
	 *
	 * @since 8.0.0
	 * @var \CTXFeed\AppServices\Promotions
	 */
	private $promotion;

	/**
	 * Get (and lazily boot) the singleton.
	 *
	 * @since 8.0.0
	 *
	 * @return AppServices
	 */
	public static function instance(): AppServices {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the SDK: client → insights + promotions, filters, init.
	 *
	 * @since 8.0.0
	 */
	private function __construct() {
		$this->client    = new Client( '4e68acba-cbdc-476b-b4bf-eab176ac6a16', 'CTX Feed', WOO_FEED_FREE_FILE );
		$this->insights  = $this->client->insights();
		$this->promotion = $this->client->promotions();

		$this->promotion->set_source( 'https://api.bitbucket.org/2.0/snippets/woofeed/RLbyop/files/woo-feed-notice.json' );

		$this->insight_init();
		$this->promotion->init();
	}

	/**
	 * Register the Insights tracking-data + support-ticket filters, then init.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function insight_init(): void {
		$slug = $this->client->getSlug();

		add_filter( $slug . '_what_tracked', array( $this, 'data_we_collect' ), 10, 1 );
		add_filter(
			"WebAppick_{$slug}_Support_Ticket_Recipient_Email",
			static function () {
				return 'sales@webappick.com';
			},
			10
		);
		add_filter( "WebAppick_{$slug}_Support_Ticket_Email_Template", array( $this, 'support_ticket_template' ), 10 );
		add_filter( "WebAppick_{$slug}_Support_Request_Ajax_Success_Response", array( $this, 'support_response' ), 10 );
		add_filter( "WebAppick_{$slug}_Support_Request_Ajax_Error_Response", array( $this, 'support_error_response' ), 10 );
		add_filter(
			"WebAppick_{$slug}_Support_Page_URL",
			static function () {
				return 'https://wordpress.org/support/plugin/webappick-product-feed-for-woocommerce/#new-topic-0';
			},
			10
		);

		$this->insights->init();
	}

	/**
	 * Describe the data the tracker collects (shown on the opt-in screen).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Existing description lines.
	 * @return array
	 */
	public function data_we_collect( $data ) {
		return array_merge(
			(array) $data,
			array(
				esc_html__( 'Number of products in your site.', 'woo-feed' ),
				esc_html__( 'Number of product categories in your site.', 'woo-feed' ),
				esc_html__( 'Feed Configuration.', 'woo-feed' ),
				esc_html__( 'Site name, language and url.', 'woo-feed' ),
				esc_html__( 'Number of active and inactive plugins.', 'woo-feed' ),
				esc_html__( 'Your name and email address.', 'woo-feed' ),
			)
		);
	}

	/**
	 * Support-ticket email template.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	public function support_ticket_template() {
		$template  = '<div style="margin: 10px auto;"><p>Website : <a href="__WEBSITE__">__WEBSITE__</a><br>Plugin : %s (v.%s)</p></div>';
		$template  = sprintf( $template, $this->client->getName(), $this->client->getProjectVersion() );
		$template .= '<div style="margin: 10px auto;"><hr></div>';
		$template .= '<div style="margin: 10px auto;"><h3>__SUBJECT__</h3></div>';
		$template .= '<div style="margin: 10px auto;">__MESSAGE__</div>';
		$template .= '<div style="margin: 10px auto;"><hr></div>';
		$template .= sprintf(
			'<div style="margin: 50px auto 10px auto;"><p style="font-size: 12px;color: #009688">%s</p></div>',
			'Message Processed With WebAppick Service Library (v.' . $this->client->getClientVersion() . ')'
		);
		return $template;
	}

	/**
	 * Support-ticket AJAX success response markup.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	public function support_response() {
		$response        = '';
		$response       .= sprintf( '<h3>%s</h3>', esc_html__( 'Thank you -- Support Ticket Submitted.', 'woo-feed' ) );
		$ticketSubmitted = esc_html__( 'Your ticket has been successfully submitted.', 'woo-feed' );
		$twenty4Hours    = sprintf( '<strong>%s</strong>', esc_html__( '24 hours', 'woo-feed' ) );
		/* translators: %s: Approx. time to response after ticket submission. */
		$notification = sprintf( esc_html__( 'You will receive an email notification from "support@webappick.com" in your inbox within %s.', 'woo-feed' ), $twenty4Hours );
		$followUp     = esc_html__( 'Please Follow the email and WebAppick Support Team will get back with you shortly.', 'woo-feed' );
		$response    .= sprintf( '<p>%s %s %s</p>', $ticketSubmitted, $notification, $followUp );
		$docLink      = sprintf( '<a class="button button-primary" href="https://webappick.helpscoutdocs.com/" target="_blank"><span class="dashicons dashicons-media-document" aria-hidden="true"></span> %s</a>', esc_html__( 'Documentation', 'woo-feed' ) );
		$vidLink      = sprintf( '<a class="button button-primary" href="https://youtube.com/playlist?list=PLapCcXJAoEenI-35wc6YnnsAAgoYRxDr7" target="_blank"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span> %s</a>', esc_html__( 'Video Tutorials', 'woo-feed' ) );
		$response    .= sprintf( '<p>%s %s</p>', $docLink, $vidLink );
		$response    .= '<br><br><br>';
		$toc          = sprintf( '<a href="https://webappick.com/terms-and-conditions/" target="_blank">%s</a>', esc_html__( 'Terms & Conditions', 'woo-feed' ) );
		$pp           = sprintf( '<a href="https://webappick.com/privacy-policy/" target="_blank">%s</a>', esc_html__( 'Privacy Policy', 'woo-feed' ) );
		/* translators: 1: Link to the Terms And Condition Page, 2: Link to the Privacy Policy Page */
		$policy    = sprintf( esc_html__( 'Please read our %1$s and %2$s', 'woo-feed' ), $toc, $pp );
		$response .= sprintf( '<p style="font-size: 12px;">%s</p>', $policy );
		return $response;
	}

	/**
	 * Support-ticket AJAX error response markup.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	public function support_error_response() {
		return sprintf(
			'<div class="mui-error"><p>%s</p><p>%s</p><br><br><p style="font-size: 12px;">%s</p></div>',
			esc_html__( 'Something Went Wrong. Please Try The Support Ticket Form On Our Website.', 'woo-feed' ),
			sprintf( '<a class="button button-primary woo-feed-btn-bg-gradient-blue" href="https://wordpress.org/support/plugin/webappick-product-feed-for-woocommerce/#new-topic-0" target="_blank">%s</a>', esc_html__( 'Get Support', 'woo-feed' ) ),
			esc_html__( 'Support Ticket form will open in new tab in 5 seconds.', 'woo-feed' )
		);
	}

	// ── Public opt-in API (for a V8 settings REST endpoint) ───────────────

	/**
	 * Whether usage tracking is currently allowed (opted in).
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	public function is_tracking_allowed(): bool {
		return (bool) $this->insights->is_tracking_allowed();
	}

	/**
	 * Opt in to usage tracking.
	 *
	 * @since 8.0.0
	 *
	 * @param bool $override Ignore the last-send throttle when true.
	 * @return void
	 */
	public function opt_in( bool $override = false ): void {
		$this->insights->optIn( $override );
	}

	/**
	 * Opt out of usage tracking.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function opt_out(): void {
		$this->insights->optOut();
	}

	/**
	 * Human-readable list of collected data points.
	 *
	 * @since 8.0.0
	 *
	 * @return array
	 */
	public function get_data_collection_description(): array {
		return (array) $this->insights->get_data_collection_description();
	}
}
