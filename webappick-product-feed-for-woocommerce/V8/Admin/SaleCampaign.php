<?php
/**
 * SaleCampaign — the Black Friday + Cyber Monday 2026 sale banner on the
 * WordPress dashboard (CBT-876).
 *
 * Three deals from the campaign landing page, one per slide in its own
 * colour theme, rotating every 20 seconds (CSS only — the dashboard widget
 * loads no script): Lifetime first (50% off), Subscription (CTX Feed Pro
 * yearly, 40% off) and the Bundle (CTX Feed Pro + Challan Pro + Disco Pro,
 * up to 60% off, with the three plugin logos). There is no coupon: every link opens the landing page. Sites with an active Pro licence
 * see the Bundle slide only. The banner disappears by itself after
 * 2026-11-30 23:59:59 Asia/Dhaka, the landing page's deadline. The admin
 * right rail shows the same deals (BlackFridayOfferCard.jsx).
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.45
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Black Friday + Cyber Monday 2026 sale banner.
 *
 * @since 8.0.45
 */
final class SaleCampaign {

	/**
	 * Last second of the offer: 2026-11-30 23:59:59 +06:00 (Asia/Dhaka).
	 */
	const ENDS_AT = 1796061599;

	/**
	 * Campaign landing page; it applies the deals (no coupon).
	 */
	const LANDING_URL = 'https://webappick.com/wp-plugin-black-friday-cyber-monday-deals/';

	/**
	 * Discount per deal, in percent (the bundle's is the "up to" figure).
	 */
	const PERCENT = array(
		'subscription' => 40,
		'lifetime'     => 50,
		'bundle'       => 60,
	);

	/**
	 * One colour theme per deal (owner, 2026-11-17): background, corner glow, accent,
	 * CTA text, price tile, tile border, body text, muted text. Same as
	 * BFCM_THEMES in BlackFridayOfferCard.jsx.
	 */
	const THEMES = array(
		'lifetime'     => array(
			'bg'     => 'linear-gradient(160deg,#1a1a24 0%,#0b0b10 100%)',
			'glow'   => 'rgba(255,210,63,0.22)',
			'accent' => '#ffd23f',
			'ink'    => '#111118',
			'tile'   => '#1c1c26',
			'border' => '#34344a',
			'body'   => '#d4d4e0',
			'muted'  => '#a9a9bd',
		),
		'subscription' => array(
			'bg'     => 'linear-gradient(160deg,#123479 0%,#0a1838 100%)',
			'glow'   => 'rgba(79,209,255,0.26)',
			'accent' => '#4fd1ff',
			'ink'    => '#06182e',
			'tile'   => 'rgba(255,255,255,0.07)',
			'border' => 'rgba(255,255,255,0.18)',
			'body'   => '#d6e2f7',
			'muted'  => '#a9c1e8',
		),
		'bundle'       => array(
			'bg'     => 'linear-gradient(160deg,#0d2f1c 0%,#03110a 100%)',
			'glow'   => 'rgba(184,255,44,0.30)',
			'accent' => '#b8ff2c',
			'ink'    => '#0b1a02',
			'tile'   => 'rgba(255,255,255,0.06)',
			'border' => 'rgba(184,255,44,0.30)',
			'body'   => '#d9f5d0',
			'muted'  => '#a3c99c',
		),
	);

	/**
	 * Seconds each slide shows.
	 */
	const SLIDE_SECONDS = 20;

	/**
	 * Whether the offer runs. Site owners can hide it with the
	 * `ctxfeed_show_sale_campaign` filter.
	 *
	 * @since 8.0.45
	 *
	 * @param int|null $now Unix time; null = now.
	 * @return bool
	 */
	public static function active( ?int $now = null ): bool {
		$now = null === $now ? time() : $now;
		if ( $now > self::ENDS_AT ) {
			return false;
		}

		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'ctxfeed_show_sale_campaign', true );
	}

	/**
	 * Whether an active CTX Feed Pro licence is on this site (current Pro, or
	 * a legacy Pro with its licence active).
	 *
	 * @since 8.0.45
	 *
	 * @return bool
	 */
	public static function licensed(): bool {
		if ( class_exists( '\CTXFeed\Pro\License\License' ) && is_callable( array( '\CTXFeed\Pro\License\License', 'is_active' ) ) ) {
			return (bool) \CTXFeed\Pro\License\License::is_active();
		}

		return class_exists( '\CTXFeed\V8\Status\LegacyPro' )
			&& is_callable( array( '\CTXFeed\V8\Status\LegacyPro', 'is_active' ) )
			&& is_callable( array( '\CTXFeed\V8\Status\LegacyPro', 'license_active' ) )
			&& \CTXFeed\V8\Status\LegacyPro::is_active()
			&& \CTXFeed\V8\Status\LegacyPro::license_active();
	}

	/**
	 * The slides to show: all three deals, Lifetime first, or the Bundle only
	 * when licensed (owner, 2026-11-17).
	 *
	 * @since 8.0.45
	 *
	 * @param bool $licensed Active Pro licence.
	 * @return string[] Slide ids, in order.
	 */
	public static function slides( bool $licensed ): array {
		return $licensed ? array( 'bundle' ) : array( 'lifetime', 'subscription', 'bundle' );
	}

	/**
	 * Landing page link with the campaign UTMs. The single-plugin deals jump
	 * to the CTX Feed card (#pc-ctx); the bundle opens the top of the page.
	 *
	 * @since 8.0.45
	 *
	 * @param string $slide  subscription | lifetime | bundle.
	 * @param string $medium Banner placement (dashboard_widget, …).
	 * @return string
	 */
	public static function url( string $slide, string $medium ): string {
		return self::LANDING_URL
			. '?utm_source=free_plugin&utm_medium=' . rawurlencode( $medium )
			. '&utm_campaign=bfcm_2026&utm_content=' . rawurlencode( $slide )
			. ( 'bundle' === $slide ? '' : '#pc-ctx' );
	}

	/**
	 * Today's Single Site prices with the deal's percentage off, rounded to
	 * whole units like the landing page ($749 → $375, $119 → $71).
	 *
	 * @since 8.0.45
	 *
	 * @return array<string,array{was:string,price:string,save:string}> Keyed year|life; [] when unknown.
	 */
	public static function prices(): array {
		$payload = class_exists( __NAMESPACE__ . '\ProPricing' ) ? ProPricing::snapshot() : array();
		$out     = array();
		foreach ( array(
			'year' => 'subscription',
			'life' => 'lifetime',
		) as $mode => $deal ) {
			if ( ! isset( $payload['tiers']['single'][ $mode ]['price'] ) ) {
				return array();
			}
			$now          = (float) $payload['tiers']['single'][ $mode ]['price'];
			$out[ $mode ] = array(
				'was'   => self::format( $now, $payload['currency'] ?? array() ),
				'price' => self::format( round( $now * ( 100 - self::PERCENT[ $deal ] ) / 100 ), $payload['currency'] ?? array() ),
				'save'  => self::format( $now - round( $now * ( 100 - self::PERCENT[ $deal ] ) / 100 ), $payload['currency'] ?? array() ),
			);
		}

		return $out;
	}

	/**
	 * Format an amount with the store currency's prefix/suffix.
	 *
	 * @param float               $amount   Amount.
	 * @param array<string,mixed> $currency prefix / suffix.
	 * @return string
	 */
	private static function format( float $amount, array $currency ): string {
		$whole = abs( $amount - round( $amount ) ) < 0.005;

		return ( $currency['prefix'] ?? '' ) . number_format( $amount, $whole ? 0 : 2 ) . ( $currency['suffix'] ?? '' );
	}

	/**
	 * URL of an image under V8/Admin/images/, or '' when plugins_url() is
	 * not available.
	 *
	 * @param string $path Path below V8/Admin/.
	 * @return string
	 */
	private static function asset_url( string $path ): string {
		return function_exists( 'plugins_url' ) ? (string) plugins_url( $path, __FILE__ ) : '';
	}

	/**
	 * The banner stylesheet shared with the admin rail card: the
	 * BFCM_CSS string in BlackFridayOfferCard.jsx is byte-identical
	 * (SaleCampaignTest pins it). Each slide sets its theme as CSS custom
	 * properties (--a accent, --ink CTA text, --tile/--border card,
	 * --body/--muted text, --glow); motion stops under prefers-reduced-motion.
	 *
	 * @since 8.0.45
	 *
	 * @return string
	 */
	public static function base_css(): string {
		return <<<'CSS'
.ctxfeed-bfcm{position:relative;overflow:hidden;color:#fff;background:#0b0b10;font-family:inherit;line-height:1.4;}
.ctxfeed-bfcm *,.ctxfeed-bfcm *:before,.ctxfeed-bfcm *:after{box-sizing:border-box;}
.ctxfeed-bfcm--card{border-radius:16px;box-shadow:0 8px 22px rgba(14,14,20,.32);}
.ctxfeed-bfcm--widget{margin:-11px -12px 14px;}
.ctxfeed-bfcm__slides{display:grid;}
.ctxfeed-bfcm__slide{grid-area:1/1;position:relative;overflow:hidden;display:flex;flex-direction:column;min-height:300px;padding:16px 18px;}
.ctxfeed-bfcm__slide:before{content:"";position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.09) 1px,transparent 1.6px);background-size:16px 16px;-webkit-mask-image:linear-gradient(115deg,transparent 35%,#000 100%);mask-image:linear-gradient(115deg,transparent 35%,#000 100%);}
.ctxfeed-bfcm__slide:after{content:"";position:absolute;width:240px;height:240px;right:-90px;top:-100px;border-radius:50%;pointer-events:none;background:radial-gradient(circle,var(--glow) 0%,rgba(0,0,0,0) 70%);animation:ctxfeed-bfcm-orb 7s ease-in-out infinite alternate;}
.ctxfeed-bfcm__in{position:relative;z-index:1;display:flex;flex-direction:column;flex:1;}
.ctxfeed-bfcm__top{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.ctxfeed-bfcm__brand{display:inline-flex;align-items:center;gap:8px;font-size:15px;font-weight:800;letter-spacing:-.2px;color:#fff;}
.ctxfeed-bfcm__brand b{color:var(--a);font-weight:800;}
.ctxfeed-bfcm__brand-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25);}
.ctxfeed-bfcm__brand-icon img{width:16px;height:auto;display:block;}
.ctxfeed-bfcm__ends{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--a);white-space:nowrap;}
.ctxfeed-bfcm__live{width:7px;height:7px;border-radius:50%;background:var(--a);animation:ctxfeed-bfcm-ping 1.6s ease-out infinite;}
.ctxfeed-bfcm__title{margin-top:16px;font-size:22px;font-weight:900;line-height:1.05;letter-spacing:.2px;text-transform:uppercase;color:var(--a);background:linear-gradient(90deg,#fff 0%,var(--a) 35%,#fff 50%,var(--a) 65%,#fff 100%);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:ctxfeed-bfcm-sheen 6s linear infinite;}
.ctxfeed-bfcm__main{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 18px;margin-top:14px;}
.ctxfeed-bfcm__tag{display:inline-block;padding:3px 10px;border-radius:999px;background:var(--a);color:var(--ink);font-size:10.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;}
.ctxfeed-bfcm__upto{margin-left:8px;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--body);}
.ctxfeed-bfcm__pct{display:flex;align-items:flex-end;gap:8px;margin-top:10px;}
.ctxfeed-bfcm__num{display:inline-block;font-size:68px;font-weight:900;line-height:.8;letter-spacing:-3px;color:var(--a);transform-origin:left bottom;animation:ctxfeed-bfcm-pulse 2.4s ease-in-out infinite;}
.ctxfeed-bfcm__off{font-size:18px;font-weight:800;line-height:1.05;max-width:84px;padding-bottom:3px;color:#fff;}
.ctxfeed-bfcm__card{flex:1 1 165px;max-width:230px;padding:12px 14px;border-radius:14px;background:var(--tile);border:1px solid var(--border);box-shadow:inset 0 1px 0 rgba(255,255,255,.08),0 12px 26px rgba(0,0,0,.28);}
.ctxfeed-bfcm__was{font-size:13px;font-weight:600;text-decoration:line-through;color:var(--muted);}
.ctxfeed-bfcm__price{font-size:30px;font-weight:900;line-height:1.05;letter-spacing:-.5px;color:#fff;}
.ctxfeed-bfcm__per{font-size:11.5px;color:var(--muted);}
.ctxfeed-bfcm__save{display:inline-block;margin-top:7px;padding:2px 8px;border-radius:6px;background:rgba(255,255,255,.12);color:var(--a);font-size:11px;font-weight:800;}
.ctxfeed-bfcm--card:not(.ctxfeed-bfcm--wide) .ctxfeed-bfcm__card{flex:1 1 100%;max-width:none;}
.ctxfeed-bfcm--card:not(.ctxfeed-bfcm--wide) .ctxfeed-bfcm__card--price{display:grid;grid-template-columns:1fr auto;column-gap:12px;align-items:center;}
.ctxfeed-bfcm--card:not(.ctxfeed-bfcm--wide) .ctxfeed-bfcm__card--price>*{grid-column:1;}
.ctxfeed-bfcm--card:not(.ctxfeed-bfcm--wide) .ctxfeed-bfcm__card--price .ctxfeed-bfcm__save{grid-column:2;grid-row:1/4;margin:0;padding:10px 12px;border-radius:12px;background:var(--a);color:var(--ink);font-size:14px;line-height:1.15;text-align:center;box-shadow:0 0 18px var(--glow);}
.ctxfeed-bfcm__logos{display:flex;align-items:flex-start;justify-content:space-between;gap:4px;padding-bottom:8px;margin-bottom:2px;border-bottom:1px solid var(--border);}
.ctxfeed-bfcm__logo{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:9.5px;font-weight:700;color:var(--body);white-space:nowrap;}
.ctxfeed-bfcm__logo i{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.3);animation:ctxfeed-bfcm-bob 3s ease-in-out infinite;}
.ctxfeed-bfcm__logo:nth-child(3) i{animation-delay:.35s;}
.ctxfeed-bfcm__logo:nth-child(5) i{animation-delay:.7s;}
.ctxfeed-bfcm__logo img{width:22px;height:22px;object-fit:contain;display:block;}
.ctxfeed-bfcm__plus{align-self:center;margin-top:-14px;font-size:14px;font-weight:900;color:var(--a);}
.ctxfeed-bfcm__row{display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:5px 0 0;}
.ctxfeed-bfcm__row span{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);}
.ctxfeed-bfcm__row strong{font-size:16px;font-weight:900;line-height:1.15;color:#fff;}
.ctxfeed-bfcm__body{margin:14px 0 0;font-size:12.5px;line-height:1.5;color:var(--body);}
.ctxfeed-bfcm__cta{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;gap:8px;min-height:46px;margin-top:14px;border-radius:12px;font-size:15px;font-weight:800;text-decoration:none;background:var(--a);color:var(--ink);box-shadow:0 10px 24px var(--glow);}
.ctxfeed-bfcm__cta:hover,.ctxfeed-bfcm__cta:focus{filter:brightness(1.08);color:var(--ink);}
.ctxfeed-bfcm__cta:after{content:"";position:absolute;top:0;bottom:0;left:-60%;width:40%;background:linear-gradient(100deg,rgba(255,255,255,0) 0%,rgba(255,255,255,.55) 50%,rgba(255,255,255,0) 100%);transform:skewX(-20deg);animation:ctxfeed-bfcm-shine 3.2s ease-in-out infinite;}
.ctxfeed-bfcm__arrow{display:inline-block;animation:ctxfeed-bfcm-nudge 1.6s ease-in-out infinite;}
.ctxfeed-bfcm__foot{margin-top:10px;padding-right:56px;font-size:11.5px;line-height:16px;font-weight:600;color:var(--body);}
.ctxfeed-bfcm__dots{position:absolute;right:18px;bottom:21px;z-index:2;display:flex;align-items:center;gap:5px;}
.ctxfeed-bfcm__dot{display:block;width:6px;height:6px;margin:0;padding:0;border:0;border-radius:3px;background:#fff;}
.ctxfeed-bfcm--js .ctxfeed-bfcm__slide{opacity:0;visibility:hidden;transition:opacity .5s ease,visibility .5s;}
.ctxfeed-bfcm--js .ctxfeed-bfcm__slide.is-on{opacity:1;visibility:visible;}
.ctxfeed-bfcm--js .is-on .ctxfeed-bfcm__main,.ctxfeed-bfcm--js .is-on .ctxfeed-bfcm__body{animation:ctxfeed-bfcm-rise .6s ease-out both;}
.ctxfeed-bfcm--js .ctxfeed-bfcm__dot{cursor:pointer;opacity:.4;transition:width .3s,opacity .3s;}
.ctxfeed-bfcm--js .ctxfeed-bfcm__dot:hover{opacity:.75;}
.ctxfeed-bfcm--js .ctxfeed-bfcm__dot[aria-selected="true"]{width:16px;opacity:1;}
.ctxfeed-bfcm--wide .ctxfeed-bfcm__slide{min-height:0;padding:18px 24px;}
.ctxfeed-bfcm--wide .ctxfeed-bfcm__main{justify-content:flex-start;column-gap:40px;}
.ctxfeed-bfcm--wide .ctxfeed-bfcm__cta{max-width:380px;}
@keyframes ctxfeed-bfcm-orb{from{transform:translate(0,0) scale(1);}to{transform:translate(-40px,30px) scale(1.25);}}
@keyframes ctxfeed-bfcm-ping{0%{box-shadow:0 0 0 0 var(--glow);}100%{box-shadow:0 0 0 8px rgba(0,0,0,0);}}
@keyframes ctxfeed-bfcm-sheen{from{background-position:100% 0;}to{background-position:0 0;}}
@keyframes ctxfeed-bfcm-pulse{0%,100%{transform:scale(1);text-shadow:0 6px 22px var(--glow);}50%{transform:scale(1.045);text-shadow:0 8px 40px var(--glow);}}
@keyframes ctxfeed-bfcm-bob{0%,100%{transform:translateY(0);}50%{transform:translateY(-3px);}}
@keyframes ctxfeed-bfcm-shine{0%{left:-60%;}55%,100%{left:130%;}}
@keyframes ctxfeed-bfcm-nudge{0%,100%{transform:translateX(0);}50%{transform:translateX(4px);}}
@keyframes ctxfeed-bfcm-rise{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:none;}}
@media (prefers-reduced-motion: reduce){.ctxfeed-bfcm__slide:after,.ctxfeed-bfcm__live,.ctxfeed-bfcm__title,.ctxfeed-bfcm__num,.ctxfeed-bfcm__logo i,.ctxfeed-bfcm__cta:after,.ctxfeed-bfcm__arrow,.ctxfeed-bfcm--js .is-on .ctxfeed-bfcm__main,.ctxfeed-bfcm--js .is-on .ctxfeed-bfcm__body{animation:none;}.ctxfeed-bfcm__cta:after{display:none;}.ctxfeed-bfcm--js .ctxfeed-bfcm__slide{transition:none;}}
CSS;
	}

	/**
	 * Print the banner (the dashboard widget buffers and contains it).
	 *
	 * @since 8.0.45
	 *
	 * @param bool|null $licensed Active Pro licence; null = detect.
	 * @return void
	 */
	public static function render_banner( ?bool $licensed = null ): void {
		$slides  = self::slides( null === $licensed ? self::licensed() : $licensed );
		$count   = count( $slides );
		$cycle   = self::SLIDE_SECONDS * $count;
		$prices  = self::prices();
		$logo    = self::asset_url( 'images/woo-feed-icon.svg' );
		$deals   = array(
			'lifetime'     => array(
				'tag'      => __( 'Lifetime', 'woo-feed' ),
				'headline' => __( 'off lifetime', 'woo-feed' ),
				'mode'     => 'life',
				'body'     => __( 'Pay once and keep CTX Feed Pro for good, at half the regular price.', 'woo-feed' ),
				'per'      => __( 'one time · 1 site', 'woo-feed' ),
				'cta'      => __( 'Get the lifetime deal', 'woo-feed' ),
			),
			'subscription' => array(
				'tag'      => __( 'Subscription', 'woo-feed' ),
				'headline' => __( 'off yearly', 'woo-feed' ),
				'mode'     => 'year',
				'body'     => __( 'Every Pro feature: filters, attribute mapping, dynamic attributes.', 'woo-feed' ),
				'per'      => __( 'per year · 1 site', 'woo-feed' ),
				'cta'      => __( 'Get the yearly deal', 'woo-feed' ),
			),
			'bundle'       => array(
				'tag'      => __( 'Bundle of 3', 'woo-feed' ),
				'headline' => __( 'off the bundle', 'woo-feed' ),
				'mode'     => '',
				'body'     => __( 'Product feeds, invoices and discount rules, licensed for the same sites.', 'woo-feed' ),
				'per'      => '',
				'cta'      => __( 'See the bundle', 'woo-feed' ),
			),
		);
		$plugins = array(
			array( 'CTX Feed', $logo ),
			array( 'Challan', self::asset_url( 'images/bfcm/challan-icon.png' ) ),
			array( 'Disco', self::asset_url( 'images/bfcm/disco-icon.png' ) ),
		);
		$fade    = esc_html( (string) round( 100 / max( 1, $count ) - 1, 2 ) );
		$step    = esc_html( (string) round( 100 / max( 1, $count ), 2 ) );
		?>
		<style>
			<?php echo self::base_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet. ?>
			<?php if ( $count > 1 ) : ?>
			.ctxfeed-bfcm--widget .ctxfeed-bfcm__slide,.ctxfeed-bfcm--widget .ctxfeed-bfcm__dot{animation:ctxfeed-bfcm-<?php echo (int) $count; ?> <?php echo (int) $cycle; ?>s infinite;}
			.ctxfeed-bfcm--widget .ctxfeed-bfcm__slide{opacity:0;visibility:hidden;}
			.ctxfeed-bfcm--widget .ctxfeed-bfcm__dot{opacity:.35;animation-name:ctxfeed-bfcm-dot-<?php echo (int) $count; ?>;}
				<?php for ( $i = 1; $i < $count; $i++ ) : ?>
			.ctxfeed-bfcm--widget .ctxfeed-bfcm__slide:nth-child(<?php echo (int) ( $i + 1 ); ?>),.ctxfeed-bfcm--widget .ctxfeed-bfcm__dot:nth-child(<?php echo (int) ( $i + 1 ); ?>){animation-delay:<?php echo (int) ( $i * self::SLIDE_SECONDS ); ?>s;}
			<?php endfor; ?>
			@keyframes ctxfeed-bfcm-<?php echo (int) $count; ?>{0%{opacity:1;visibility:visible;}<?php echo $fade; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>%{opacity:1;visibility:visible;}<?php echo $step; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>%,100%{opacity:0;visibility:hidden;}}
			@keyframes ctxfeed-bfcm-dot-<?php echo (int) $count; ?>{0%,<?php echo $fade; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>%{opacity:1;}<?php echo $step; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>%,100%{opacity:.35;}}
			.ctxfeed-bfcm--widget:hover .ctxfeed-bfcm__slide,.ctxfeed-bfcm--widget:hover .ctxfeed-bfcm__dot,.ctxfeed-bfcm--widget:focus-within .ctxfeed-bfcm__slide,.ctxfeed-bfcm--widget:focus-within .ctxfeed-bfcm__dot{animation-play-state:paused;}
			@media (prefers-reduced-motion: reduce){.ctxfeed-bfcm--widget .ctxfeed-bfcm__slide,.ctxfeed-bfcm--widget .ctxfeed-bfcm__dot{animation:none;}.ctxfeed-bfcm--widget .ctxfeed-bfcm__slide:first-child{opacity:1;visibility:visible;}.ctxfeed-bfcm--widget .ctxfeed-bfcm__dot:first-child{opacity:1;}}
			<?php endif; ?>
		</style>
		<div class="ctxfeed-bfcm ctxfeed-bfcm--widget" role="group" aria-label="<?php esc_attr_e( 'Black Friday + Cyber Monday sale', 'woo-feed' ); ?>">
			<div class="ctxfeed-bfcm__slides">
				<?php foreach ( $slides as $id ) : ?>
					<?php
					$deal  = $deals[ $id ];
					$theme = self::THEMES[ $id ];
					$vars  = '--a:' . $theme['accent'] . ';--ink:' . $theme['ink'] . ';--tile:' . $theme['tile'] . ';--border:' . $theme['border'] . ';--body:' . $theme['body'] . ';--muted:' . $theme['muted'] . ';--glow:' . $theme['glow'] . ';'
						. 'background:radial-gradient(circle at 0% 100%,' . $theme['glow'] . ' 0%,rgba(0,0,0,0) 40%),' . $theme['bg'] . ';';
					?>
					<div class="ctxfeed-bfcm__slide" data-slide="<?php echo esc_attr( $id ); ?>" style="<?php echo esc_attr( $vars ); ?>">
						<div class="ctxfeed-bfcm__in">
							<div class="ctxfeed-bfcm__top">
								<span class="ctxfeed-bfcm__brand">
									<?php if ( '' !== $logo ) : ?>
										<span class="ctxfeed-bfcm__brand-icon"><img src="<?php echo esc_url( $logo ); ?>" alt="" width="16" height="21"></span>
									<?php endif; ?>
									<span>CTX<b>Feed</b> Pro</span>
								</span>
								<span class="ctxfeed-bfcm__ends"><span class="ctxfeed-bfcm__live" aria-hidden="true"></span><?php esc_html_e( 'Ends Nov 30', 'woo-feed' ); ?></span>
							</div>
							<div class="ctxfeed-bfcm__title"><?php esc_html_e( 'Black Friday + Cyber Monday', 'woo-feed' ); ?></div>
							<div class="ctxfeed-bfcm__main">
								<div>
									<span class="ctxfeed-bfcm__tag"><?php echo esc_html( $deal['tag'] ); ?></span>
									<?php if ( 'bundle' === $id ) : ?>
										<span class="ctxfeed-bfcm__upto"><?php esc_html_e( 'Up to', 'woo-feed' ); ?></span>
									<?php endif; ?>
									<div class="ctxfeed-bfcm__pct">
										<span class="ctxfeed-bfcm__num">
											<?php
											/* translators: %d: discount percentage. */
											echo esc_html( sprintf( __( '%d%%', 'woo-feed' ), self::PERCENT[ $id ] ) );
											?>
										</span>
										<span class="ctxfeed-bfcm__off"><?php echo esc_html( $deal['headline'] ); ?></span>
									</div>
								</div>
								<?php if ( 'bundle' === $id ) : ?>
									<div class="ctxfeed-bfcm__card">
										<div class="ctxfeed-bfcm__logos">
											<?php foreach ( $plugins as $n => $plugin ) : ?>
												<?php if ( $n > 0 ) : ?>
													<span class="ctxfeed-bfcm__plus" aria-hidden="true">+</span>
												<?php endif; ?>
												<span class="ctxfeed-bfcm__logo">
													<i>
														<?php if ( '' !== $plugin[1] ) : ?>
															<img src="<?php echo esc_url( $plugin[1] ); ?>" alt="" width="22" height="22">
														<?php endif; ?>
													</i>
													<?php echo esc_html( $plugin[0] ); ?>
												</span>
											<?php endforeach; ?>
										</div>
										<?php
										foreach ( array(
											array( __( 'Lifetime', 'woo-feed' ), 60 ),
											array( __( 'Annual', 'woo-feed' ), 40 ),
										) as $bundle_deal ) :
											?>
											<div class="ctxfeed-bfcm__row">
												<span><?php echo esc_html( $bundle_deal[0] ); ?></span>
												<strong>
													<?php
													/* translators: %d: discount percentage. */
													echo esc_html( sprintf( __( 'Save %d%%', 'woo-feed' ), $bundle_deal[1] ) );
													?>
												</strong>
											</div>
										<?php endforeach; ?>
									</div>
								<?php elseif ( isset( $prices[ $deal['mode'] ] ) ) : ?>
									<div class="ctxfeed-bfcm__card ctxfeed-bfcm__card--price">
										<div class="ctxfeed-bfcm__was"><?php echo esc_html( $prices[ $deal['mode'] ]['was'] ); ?></div>
										<div class="ctxfeed-bfcm__price"><?php echo esc_html( $prices[ $deal['mode'] ]['price'] ); ?></div>
										<div class="ctxfeed-bfcm__per"><?php echo esc_html( $deal['per'] ); ?></div>
										<span class="ctxfeed-bfcm__save">
											<?php
											/* translators: %s: amount saved, with currency. */
											echo esc_html( sprintf( __( 'Save %s', 'woo-feed' ), $prices[ $deal['mode'] ]['save'] ) );
											?>
										</span>
									</div>
								<?php endif; ?>
							</div>
							<p class="ctxfeed-bfcm__body"><?php echo esc_html( $deal['body'] ); ?></p>
							<div style="margin-top:auto;">
								<a class="ctxfeed-bfcm__cta" href="<?php echo esc_url( self::url( $id, 'dashboard_widget' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $deal['cta'] ); ?> <span class="ctxfeed-bfcm__arrow" aria-hidden="true">&rarr;</span></a>
								<div class="ctxfeed-bfcm__foot"><?php esc_html_e( 'No coupon needed · 30-day money-back guarantee', 'woo-feed' ); ?></div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $count > 1 ) : ?>
				<span class="ctxfeed-bfcm__dots" aria-hidden="true">
					<?php for ( $i = 0; $i < $count; $i++ ) : ?>
						<span class="ctxfeed-bfcm__dot"></span>
					<?php endfor; ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}
}
