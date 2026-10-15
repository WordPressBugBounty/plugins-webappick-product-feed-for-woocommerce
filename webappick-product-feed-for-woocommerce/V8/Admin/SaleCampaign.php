<?php
/**
 * SaleCampaign — the Halloween 2026 sale banner on the WordPress dashboard.
 *
 * 30% off CTX Feed Pro with code HW2026 until the end of Nov 5 (US Pacific).
 * The code is not on webappick.com, only in the plugin (this banner, the
 * admin right rail — HalloweenOfferCard.jsx) and the marketing emails. The
 * banner sits at the top of the CTX Feed dashboard widget for sites without
 * an active Pro licence and disappears by itself when the offer ends.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.37
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Halloween 2026 sale banner.
 *
 * @since 8.0.37
 */
final class SaleCampaign {

	/**
	 * Coupon code.
	 */
	const CODE = 'HW2026';

	/**
	 * Discount in percent.
	 */
	const PERCENT = 30;

	/**
	 * End of Nov 5 in the LAST time zone (US Pacific, PST): 2026-11-06 07:59:59 UTC.
	 */
	const ENDS_AT = 1793951999;

	/**
	 * Checkout with the Single Site lifetime licence (product 106128) and the code.
	 */
	const CHECKOUT_URL = 'https://webappick.com/checkout/?add-to-cart=106128&coupon=HW2026';

	/**
	 * Whether the banner shows: offer running and no active Pro licence.
	 *
	 * @since 8.0.37
	 *
	 * @param int|null $now Unix time (tests); null = now.
	 * @return bool
	 */
	public static function active( ?int $now = null ): bool {
		$now = null === $now ? time() : $now;
		if ( $now > self::ENDS_AT ) {
			return false;
		}

		/**
		 * Filters whether CTX Feed shows its sale banner on the dashboard.
		 *
		 * @since 8.0.37
		 *
		 * @param bool $show Return false to hide it.
		 */
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'ctxfeed_show_sale_campaign', true ) ) {
			return false;
		}

		return ! self::licensed();
	}

	/**
	 * Whether CTX Feed Pro has an active licence (current Pro, or a pre-8 Pro).
	 *
	 * @since 8.0.37
	 *
	 * @return bool
	 */
	private static function licensed(): bool {
		if ( class_exists( '\CTXFeed\Pro\License\License' ) && is_callable( array( '\CTXFeed\Pro\License\License', 'is_active' ) ) ) {
			return (bool) \CTXFeed\Pro\License\License::is_active();
		}

		// A pre-8 Pro: only while that plugin is active (a licence record
		// left behind by an uninstalled Pro does not count).
		return class_exists( '\CTXFeed\V8\Status\LegacyPro' )
			&& \CTXFeed\V8\Status\LegacyPro::is_active()
			&& \CTXFeed\V8\Status\LegacyPro::license_active();
	}

	/**
	 * Checkout link with the campaign UTMs.
	 *
	 * @since 8.0.37
	 *
	 * @param string $medium Where the banner is (dashboard_widget).
	 * @return string
	 */
	public static function claim_url( string $medium ): string {
		return self::CHECKOUT_URL . '&utm_source=free_plugin&utm_medium=' . rawurlencode( $medium ) . '&utm_campaign=halloween_2026&utm_content=hwl_banner';
	}

	/**
	 * Single Site prices with the discount: [ mode => [ was, price ] ] as
	 * display strings, from the cached live prices (no remote request here).
	 *
	 * @since 8.0.37
	 *
	 * @return array<string,array{was:string,price:string}>
	 */
	public static function prices(): array {
		$payload = class_exists( __NAMESPACE__ . '\ProPricing' ) ? ProPricing::snapshot() : array();
		$out     = array();
		foreach ( array( 'year', 'life' ) as $mode ) {
			if ( ! isset( $payload['tiers']['single'][ $mode ]['price'] ) ) {
				return array();
			}
			$now          = (float) $payload['tiers']['single'][ $mode ]['price'];
			$out[ $mode ] = array(
				'was'   => self::format( $now, $payload['currency'] ?? array() ),
				'price' => self::format( round( $now * ( 100 - self::PERCENT ) ) / 100, $payload['currency'] ?? array() ),
			);
		}

		return $out;
	}

	/**
	 * "$119", "$83.30" — same as the admin's formatPrice().
	 *
	 * @param float $amount   Amount.
	 * @param array $currency { prefix, suffix }.
	 * @return string
	 */
	private static function format( float $amount, array $currency ): string {
		$whole = abs( $amount - round( $amount ) ) < 0.005;

		return ( $currency['prefix'] ?? '' ) . number_format( $amount, $whole ? 0 : 2 ) . ( $currency['suffix'] ?? '' );
	}

	/**
	 * Print the banner (dashboard widget). Escaped, inline styles only — the
	 * WordPress dashboard loads none of the admin app's CSS.
	 *
	 * @since 8.0.37
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		$prices = self::prices();
		$tile   = 'flex:1 1 110px;padding:9px 11px;border-radius:12px;display:flex;flex-direction:column;gap:2px;';
		$label  = 'font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;';
		$amount = 'font-size:22px;font-weight:800;line-height:1.1;color:#fff;';
		$was    = 'text-decoration:line-through;color:#a898d2;letter-spacing:0;font-size:13px;font-weight:600;';
		?>
		<div style="position:relative;overflow:hidden;margin:-11px -12px 14px;padding:16px 16px 14px;background:#170f2c;color:#f4efff;border-radius:0;" role="group" aria-label="<?php esc_attr_e( 'CTX Feed Pro Halloween sale', 'woo-feed' ); ?>">
			<svg aria-hidden="true" viewBox="0 0 78 78" width="54" height="54" style="position:absolute;top:10px;right:12px;">
				<circle cx="39" cy="39" r="30" fill="#FFE2A6"></circle>
				<circle cx="29" cy="31" r="4" fill="#F5CB7E"></circle>
				<circle cx="47" cy="47" r="6" fill="#F5CB7E"></circle>
			</svg>
			<div style="position:relative;font-size:10.5px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;">
				<span style="color:#ffb463;"><?php esc_html_e( 'CTX Feed Pro', 'woo-feed' ); ?></span>
				<span style="color:#6e5b9a;"> &middot; </span>
				<span style="color:#b9a9e0;"><?php esc_html_e( 'Halloween sale', 'woo-feed' ); ?></span>
			</div>
			<div style="position:relative;display:flex;align-items:flex-end;gap:8px;margin-top:10px;">
				<span style="font-size:56px;font-weight:800;line-height:0.85;letter-spacing:-3px;color:#ff8a2b;">
					<?php
					/* translators: %d: discount percentage. */
					echo esc_html( sprintf( __( '%d%%', 'woo-feed' ), self::PERCENT ) );
					?>
				</span>
				<span style="font-size:20px;font-weight:800;line-height:1;color:#fff;padding-bottom:3px;"><?php esc_html_e( 'off Pro', 'woo-feed' ); ?></span>
			</div>
			<?php if ( ! empty( $prices ) ) : ?>
				<div style="position:relative;display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">
					<div style="<?php echo esc_attr( $tile ); ?>background:#221840;border:1px solid #3a2c63;">
						<span style="<?php echo esc_attr( $label ); ?>color:#b9a9e0;"><?php esc_html_e( 'Yearly', 'woo-feed' ); ?> <span style="<?php echo esc_attr( $was ); ?>"><?php echo esc_html( $prices['year']['was'] ); ?></span></span>
						<span style="<?php echo esc_attr( $amount ); ?>"><?php echo esc_html( $prices['year']['price'] ); ?></span>
					</div>
					<div style="<?php echo esc_attr( $tile ); ?>background:#2e1e4f;border:1.5px solid #ff8a2b;">
						<span style="<?php echo esc_attr( $label ); ?>color:#ffd29e;"><?php esc_html_e( 'Lifetime', 'woo-feed' ); ?> <span style="<?php echo esc_attr( $was ); ?>"><?php echo esc_html( $prices['life']['was'] ); ?></span></span>
						<span style="<?php echo esc_attr( $amount ); ?>"><?php echo esc_html( $prices['life']['price'] ); ?></span>
					</div>
				</div>
			<?php endif; ?>
			<div style="position:relative;display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:12px;">
				<div style="flex:1 1 130px;border:1.5px dashed #6e5b9a;border-radius:12px;padding:6px 12px;">
					<div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#b9a9e0;"><?php esc_html_e( 'Coupon code', 'woo-feed' ); ?></div>
					<div style="font-size:18px;font-weight:800;letter-spacing:1px;color:#fff;user-select:all;"><?php echo esc_html( self::CODE ); ?></div>
				</div>
				<a href="<?php echo esc_url( self::claim_url( 'dashboard_widget' ) ); ?>" target="_blank" rel="noopener noreferrer" style="flex:1 1 150px;display:flex;align-items:center;justify-content:center;min-height:46px;border-radius:12px;background:#ff8a2b;color:#1a0f2e;font-size:15px;font-weight:700;text-decoration:none;box-shadow:0 6px 16px rgba(255,138,43,.35);">
					<?php
					/* translators: %d: discount percentage. */
					echo esc_html( sprintf( __( 'Claim %d%% off', 'woo-feed' ), self::PERCENT ) );
					?>
					&nbsp;&rarr;
				</a>
			</div>
			<div style="position:relative;margin-top:9px;font-size:12px;font-weight:700;color:#ffd29e;"><?php esc_html_e( 'Offer ends Nov 5', 'woo-feed' ); ?></div>
		</div>
		<?php
	}
}
