<?php
/**
 * Support AI Admin SSO — WordPress side
 *
 * Paste this into your theme's functions.php (or drop it in wp-content/mu-plugins/).
 * It mirrors the Assessment Admin SSO snippet, so both panels sit side by side in
 * the same WordPress dashboard.
 *
 * IMPORTANT: Replace PH_SUPPORTAI_SSO_SECRET below with the EXACT same value you
 *            put in the support-ai .env under SSO_SECRET.
 *
 * HOW IT WORKS:
 *   - Adds a "Support AI Admin" button to the WP Admin Bar (top menu bar).
 *   - Also adds a dashboard widget on the main WP dashboard page.
 *   - When clicked, opens the support-ai admin panel in a new tab — no password.
 *   - The link is valid for 60 seconds; a new one is generated each page load.
 *   - Only visible to WordPress administrators (manage_options capability).
 */

defined('ABSPATH') || exit;

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

// Must match SSO_SECRET in the support-ai .env.
define('PH_SUPPORTAI_SSO_SECRET', 'REPLACE_WITH_YOUR_SHARED_SECRET');

// Base URL of the support-ai app (no trailing slash). Include the sub-folder if
// it is installed under one, e.g. https://staging-web.procurementhub.sa/chatbot
define('PH_SUPPORTAI_URL', 'https://staging-web.procurementhub.sa/chatbot');

// ─── SHARED TOKEN BUILDER ─────────────────────────────────────────────────────

function ph_supportai_sso_url(): string {
    $ts   = time();
    $hmac = hash_hmac('sha256', (string) $ts, PH_SUPPORTAI_SSO_SECRET);
    return PH_SUPPORTAI_URL . '/admin/sso?' . http_build_query(['t' => $ts, 'h' => $hmac]);
}

// ─── 1. ADMIN BAR LINK ────────────────────────────────────────────────────────

add_action('admin_bar_menu', function (WP_Admin_Bar $bar): void {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    $bar->add_node([
        'id'    => 'ph-supportai-admin',
        'title' => '&#128172; Support AI Admin',
        'href'  => ph_supportai_sso_url(),
        'meta'  => [
            'target' => '_blank',
            'rel'    => 'noopener noreferrer',
            'title'  => 'Open Procurement Hub Support AI Admin (auto-login)',
        ],
    ]);
}, 100);

// ─── 2. DASHBOARD WIDGET ─────────────────────────────────────────────────────

add_action('wp_dashboard_setup', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    wp_add_dashboard_widget(
        'ph_supportai_sso_widget',
        'Procurement Hub — Support AI Admin',
        'ph_supportai_sso_widget_render'
    );
});

function ph_supportai_sso_widget_render(): void {
    $url = ph_supportai_sso_url();
    ?>
    <p>
        <a href="<?php echo esc_url($url); ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="button button-primary"
           style="font-size:14px;padding:6px 16px;">
            Open Support AI Admin &nearr;
        </a>
    </p>
    <p style="color:#666;font-size:12px;margin-top:8px;">
        Logs you in automatically. Each link expires in 60&nbsp;seconds — click it fresh from this page.
    </p>
    <?php
}
