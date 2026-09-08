<?php
/**
 * Plugin Name: SecurePlay — Paid Memberships Pro
 * Plugin URI: https://jurajkurek.gumroad.com/l/secureplay-pmpro
 * Description: Show a different video per Paid Memberships Pro membership level, or a login/upgrade prompt instead of the player, on the same [secureplay] shortcode.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author: Juraj Augustín Kurek
 * Text Domain: secureplay-pmpro
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPLY_PMPRO_VERSION', '1.0.0');
define('SPLY_PMPRO_DIR', plugin_dir_path(__FILE__));
define('SPLY_PMPRO_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('SPLY_Post_Type') || !function_exists('pmpro_hasMembershipLevel')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('SecurePlay — Paid Memberships Pro requires both the SecurePlay and Paid Memberships Pro plugins to be active.', 'secureplay-pmpro') .
                '</p></div>';
        });
        return;
    }

    require_once SPLY_PMPRO_DIR . 'includes/class-sply-pmpro-license.php';
    require_once SPLY_PMPRO_DIR . 'includes/class-sply-pmpro-admin.php';
    require_once SPLY_PMPRO_DIR . 'includes/class-sply-pmpro-addon.php';

    SPLY_PMPRO_License::instance()->register_hooks();
    SPLY_PMPRO_Admin::instance()->register_hooks();
    SPLY_PMPRO_Addon::instance()->register_hooks();
}, 20); // after SecurePlay's own plugins_loaded init (default priority 10)

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('sply_pmpro_license_cron_check')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sply_pmpro_license_cron_check');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('sply_pmpro_license_cron_check');
});
