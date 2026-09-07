<?php
/**
 * Plugin Name: Secure Player
 * Plugin URI: https://jurajkurek.gumroad.com/l/hfghzi
 * Description: Encrypted, key-gated HLS video player for WordPress. Videos are packaged as AES-128 HLS and the decryption key is only ever handed to visitors your site allows.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author: Juraj Kurek
 * Text Domain: secure-player
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDSP_VERSION', '1.0.0');
define('SDSP_FILE', __FILE__);
define('SDSP_DIR', plugin_dir_path(__FILE__));
define('SDSP_URL', plugin_dir_url(__FILE__));

require_once SDSP_DIR . 'includes/class-sdsp-settings.php';
require_once SDSP_DIR . 'includes/class-sdsp-crypto.php';
require_once SDSP_DIR . 'includes/class-sdsp-license.php';
require_once SDSP_DIR . 'includes/class-sdsp-encoder.php';
require_once SDSP_DIR . 'includes/class-sdsp-post-type.php';
require_once SDSP_DIR . 'includes/class-sdsp-key-server.php';
require_once SDSP_DIR . 'includes/class-sdsp-shortcode.php';
require_once SDSP_DIR . 'includes/class-sdsp-admin.php';

final class SDSP_Plugin
{
    public static function init(): void
    {
        SDSP_Post_Type::instance()->register_hooks();
        SDSP_Key_Server::instance()->register_hooks();
        SDSP_Shortcode::instance()->register_hooks();
        SDSP_Admin::instance()->register_hooks();
        SDSP_License::instance()->register_hooks();
    }

    public static function activate(): void
    {
        SDSP_Post_Type::instance()->register_post_type();
        flush_rewrite_rules();

        if (!wp_next_scheduled('sdsp_license_cron_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sdsp_license_cron_check');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('sdsp_license_cron_check');
        flush_rewrite_rules();
    }
}

add_action('plugins_loaded', ['SDSP_Plugin', 'init']);
register_activation_hook(__FILE__, ['SDSP_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SDSP_Plugin', 'deactivate']);
