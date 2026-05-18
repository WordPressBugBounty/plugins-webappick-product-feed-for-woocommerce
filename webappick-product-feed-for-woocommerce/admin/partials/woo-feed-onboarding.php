<?php

/**
 * Onboarding Wizard
 *
 * @link       https://webappick.com/
 * @since      6.6.33
 *
 * @package    Woo_Feed
 * @subpackage Woo_Feed/admin/partial
 * @author     WebAppick
 */

if (! defined('ABSPATH')) {
    exit;
}

// Security check
if (! current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woo-feed'));
}

// Plugin data
$plugins = array(
    array(
        'slug'        => 'disco',
        'name'        => 'Disco',
        'title'       => __('Disco &ndash; Discount Rules for WooCommerce', 'woo-feed'),
        'description' => __('WooCommerce discount rules plugin to create automatic product and cart discounts, bulk pricing, BOGO deals, shipping and dynamic pricing.', 'woo-feed'),
        'icon'        => WOO_FEED_PLUGIN_URL . 'admin/images/our_plugins/disco-icon.png',
        'file'        => 'disco/disco.php',
    ),
    array(
        'slug'        => 'webappick-pdf-invoice-for-woocommerce',
        'name'        => 'Challan',
        'title'       => __('Challan - PDF Invoices &amp; Packing Slips', 'woo-feed'),
        'description' => __('WooCommerce PDF invoice generator with automatic email attachment. Create packing slips, shipping labels, credit notes, multilingual.', 'woo-feed'),
        'icon'        => WOO_FEED_PLUGIN_URL . 'admin/images/our_plugins/challan-logo.png',
        'file'        => 'webappick-pdf-invoice-for-woocommerce/woo-invoice.php',
    ),
);

// Check which plugins are already installed/active.
// Theme rule: already-active plugins start "green" (done); the rest start "blue" (pending install).
foreach ($plugins as $key => $plugin) {
    $is_active                       = is_plugin_active($plugin['file']);
    $plugins[$key]['is_active']    = $is_active;
    $plugins[$key]['is_installed'] = file_exists(WP_PLUGIN_DIR . '/' . $plugin['file']);
    $plugins[$key]['theme']        = $is_active ? 'green' : 'blue';
}

$total_plugins = count($plugins);
?>
<div class="ctx-feed-onboarding-wrapper" id="ctx-feed-onboarding">
    <div class="ctx-feed-onboarding-container">

        <!-- Header: logo + version badge -->
        <div class="ctx-feed-onboarding-header">
            <div class="ctx-feed-onboarding-logo">
                <img src="<?php echo esc_url(WOO_FEED_PLUGIN_URL); ?>admin/images/v5_images/woo-feed-icon.svg" alt="<?php esc_attr_e('CTX Feed', 'woo-feed'); ?>">
                <span class="ctx-feed-onboarding-logo-text">CTX<span>Feed</span></span>
            </div>
            <div class="ctx-feed-onboarding-version">
                <span class="ctx-feed-version-badge">
                    <?php /* translators: %s: plugin version */ ?>
                    <?php printf(esc_html__('v%s · Activated', 'woo-feed'), esc_html(WOO_FEED_FREE_VERSION)); ?>
                </span>
            </div>
        </div>

        <!-- Main Content -->
        <div class="ctx-feed-onboarding-content">
            <div class="ctx-feed-onboarding-status">
                <span class="ctx-feed-status-dot"></span>
                <span class="ctx-feed-status-text"><?php esc_html_e('SETUP COMPLETE', 'woo-feed'); ?></span>
            </div>

            <h1 class="ctx-feed-onboarding-title">
                <span class="ctx-feed-title-line"><?php esc_html_e("You're Set Up. Now Make Your", 'woo-feed'); ?></span>
                <span class="ctx-feed-title-highlight"><?php esc_html_e('Store Unstoppable.', 'woo-feed'); ?></span>
            </h1>

            <p class="ctx-feed-onboarding-description">
                <?php esc_html_e('We recommend some plugins specifically for CTX Feed users fully compatible and trusted by 80,000+ WooCommerce stores. Install them now, it\'s free.', 'woo-feed'); ?>
            </p>

            <!-- Trust Bar -->
            <div class="ctx-feed-trust-bar">
                <div class="ctx-feed-trust-item">
                    <span class="ctx-feed-trust-icon ctx-feed-trust-icon--star" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="#f59e0b">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.14l-5-4.87 6.91-1.01L12 2z" />
                        </svg>
                    </span>
                    <span class="ctx-feed-trust-text"><?php esc_html_e('80,000+ active stores', 'woo-feed'); ?></span>
                </div>
                <span class="ctx-feed-trust-divider" aria-hidden="true"></span>
                <div class="ctx-feed-trust-item">
                    <span class="ctx-feed-trust-icon ctx-feed-trust-icon--check" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="#22c55e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M8.5 12.5l2.5 2.5 4.5-5" />
                        </svg>
                    </span>
                    <span class="ctx-feed-trust-text"><?php esc_html_e('100% free to install', 'woo-feed'); ?></span>
                </div>
                <span class="ctx-feed-trust-divider" aria-hidden="true"></span>
                <div class="ctx-feed-trust-item">
                    <span class="ctx-feed-trust-icon ctx-feed-trust-icon--bolt" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                        </svg>
                    </span>
                    <span class="ctx-feed-trust-text"><?php esc_html_e('One-click install', 'woo-feed'); ?></span>
                </div>
            </div>

            <!-- Plugin Cards -->
            <div class="ctx-feed-onboarding-plugins">
                <?php foreach ($plugins as $plugin) : ?>
                    <?php
                    $theme        = isset($plugin['theme']) ? $plugin['theme'] : 'green';
                    $card_classes = 'ctx-feed-plugin-card ctx-feed-plugin-card--' . sanitize_html_class($theme);
                    if ($plugin['is_active']) {
                        $card_classes .= ' is-active is-selected';
                    }
                    ?>
                    <div class="<?php echo esc_attr($card_classes); ?>" data-plugin-slug="<?php echo esc_attr($plugin['slug']); ?>">
                        <label class="ctx-feed-plugin-checkbox-wrapper" aria-label="<?php echo esc_attr($plugin['name']); ?>">
                            <input type="checkbox"
                                class="ctx-feed-plugin-checkbox"
                                name="woo_feed_onboarding_plugins[]"
                                value="<?php echo esc_attr($plugin['slug']); ?>"
                                <?php checked(true, ! $plugin['is_active']); ?>
                                <?php disabled($plugin['is_active']); ?>>
                            <span class="ctx-feed-checkbox-custom" aria-hidden="true">
                                <svg viewBox="0 0 12 12" width="10" height="10" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2.5 6.2l2.4 2.4L9.5 3.6" />
                                </svg>
                            </span>
                        </label>

                        <div class="ctx-feed-plugin-body">
                            <div class="ctx-feed-plugin-icon">
                                <img src="<?php echo esc_url($plugin['icon']); ?>" alt="<?php echo esc_attr($plugin['name']); ?>">
                            </div>
                            <div class="ctx-feed-plugin-info">
                                <h3 class="ctx-feed-plugin-title">
                                    <?php echo esc_html($plugin['title']); ?>
                                </h3>
                                <p class="ctx-feed-plugin-description"><?php echo esc_html($plugin['description']); ?></p>
                                <span class="ctx-feed-plugin-author"><?php esc_html_e('FREE · By WebAppick', 'woo-feed'); ?></span>
                            </div>
                        </div>

                        <span class="ctx-feed-plugin-status" aria-hidden="true"></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Progress Section -->
            <div class="ctx-feed-onboarding-progress" style="display:none;">
                <div class="ctx-feed-progress-meta">
                    <span class="ctx-feed-progress-text"></span>
                    <span class="ctx-feed-progress-count">0/<?php echo (int) $total_plugins; ?></span>
                </div>
                <div class="ctx-feed-progress-track">
                    <div class="ctx-feed-progress-fill" style="width:0%"></div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="ctx-feed-onboarding-footer">
            <a href="<?php echo esc_url(admin_url('admin.php?page=webappick-new-feed')); ?>" class="ctx-feed-onboarding-skip">
                <span><?php esc_html_e('Skip', 'woo-feed'); ?></span>
                <svg viewBox="0 0 16 16" width="12" height="12" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 4l4 4-4 4" />
                </svg>
            </a>
            <div class="ctx-feed-onboarding-actions">
                <button type="button" class="ctx-feed-onboarding-continue" id="ctx-feed-onboarding-continue">
                    <span><?php esc_html_e('Continue', 'woo-feed'); ?></span>
                    <svg viewBox="0 0 16 16" width="14" height="14" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 4l4 4-4 4" />
                    </svg>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=webappick-new-feed')); ?>" class="ctx-feed-onboarding-generate" id="ctx-feed-onboarding-generate" style="display: none;">
                    <span><?php esc_html_e('Generate New Feed', 'woo-feed'); ?></span>
                    <svg viewBox="0 0 16 16" width="14" height="14" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 4l4 4-4 4" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</div>

<?php wp_nonce_field('woo_feed_onboarding_nonce', 'woo_feed_onboarding_nonce'); ?>
