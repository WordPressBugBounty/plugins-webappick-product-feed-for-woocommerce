<?php
/**
 * Analytics Connector — Interface for external analytics data sources.
 * Phase 1: Defines the contract. Phase 2: GA4Connector, GoogleMerchantConnector.
 *
 * All concrete implementations gated behind FeatureGate::has('dashboard_analytics').
 *
 * @package    CTXFeed
 * @subpackage V8\Dashboard
 * @since      8.0.0
 * @implements DASH-FRD-7.1
 */

namespace CTXFeed\V8\Dashboard;

interface AnalyticsConnector {

	/**
	 * Get click count for a specific feed over a date range.
	 *
	 * @param string $feed_name Feed identifier.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return int Total clicks attributed to this feed via UTM params.
	 */
	public function getClicksForFeed( string $feed_name, string $start_date, string $end_date ): int;

	/**
	 * Get impression count for a channel over a date range.
	 *
	 * @param string $channel    Channel slug.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return int Total impressions for this channel.
	 */
	public function getImpressionsForChannel( string $channel, string $start_date, string $end_date ): int;

	/**
	 * Get conversion data for a feed.
	 *
	 * @param string $feed_name  Feed identifier.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return array{conversions: int, revenue: float, avg_order_value: float}
	 */
	public function getConversionsForFeed( string $feed_name, string $start_date, string $end_date ): array;

	/**
	 * Get aggregated channel performance metrics.
	 *
	 * @param string $channel    Channel slug.
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return array{clicks: int, impressions: int, ctr: float, conversions: int, revenue: float}
	 */
	public function getChannelPerformance( string $channel, string $start_date, string $end_date ): array;

	/**
	 * Check if this connector is configured and ready.
	 *
	 * @return bool True if API credentials are set and valid.
	 */
	public function isConnected(): bool;

	/**
	 * Get the connector identifier.
	 *
	 * @return string Unique slug (e.g., 'ga4', 'google_merchant', 'meta_commerce').
	 */
	public function getSlug(): string;
}
