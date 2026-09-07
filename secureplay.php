<?php
/**
 * Plugin Name: SecurePlay
 * Plugin URI: https://jurajkurek.gumroad.com/l/hfghzi
 * Description: Encrypted, key-gated HLS video player for WordPress. Videos are packaged as AES-128 HLS and the decryption key is only ever handed to visitors your site allows.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author: Juraj Augustín Kurek
 * Text Domain: secureplay
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPLY_VERSION', '1.0.0');
define('SPLY_FILE', __FILE__);
define('SPLY_DIR', plugin_dir_path(__FILE__));
define('SPLY_URL', plugin_dir_url(__FILE__));

require_once SPLY_DIR . 'includes/class-sply-settings.php';
require_once SPLY_DIR . 'includes/class-sply-crypto.php';
require_once SPLY_DIR . 'includes/class-sply-license.php';
require_once SPLY_DIR . 'includes/class-sply-encoder.php';
require_once SPLY_DIR . 'includes/class-sply-post-type.php';
require_once SPLY_DIR . 'includes/class-sply-key-server.php';
require_once SPLY_DIR . 'includes/class-sply-shortcode.php';
require_once SPLY_DIR . 'includes/class-sply-admin.php';

final class SPLY_Plugin
{
    public static function init(): void
    {
        SPLY_Post_Type::instance()->register_hooks();
        SPLY_Key_Server::instance()->register_hooks();
        SPLY_Shortcode::instance()->register_hooks();
        SPLY_Admin::instance()->register_hooks();
        SPLY_License::instance()->register_hooks();
    }

    public static function activate(): void
    {
        SPLY_Post_Type::instance()->register_post_type();
        flush_rewrite_rules();

        if (!wp_next_scheduled('sply_license_cron_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sply_license_cron_check');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('sply_license_cron_check');
        flush_rewrite_rules();
    }
}

add_action('plugins_loaded', ['SPLY_Plugin', 'init']);
register_activation_hook(__FILE__, ['SPLY_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SPLY_Plugin', 'deactivate']);
