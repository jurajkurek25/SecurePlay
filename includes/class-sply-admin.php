<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Settings page: license activation + general options, plus admin notices. */
final class SPLY_Admin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'license_notice']);

        add_action('wp_ajax_sply_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_sply_deactivate_license', [$this, 'ajax_deactivate_license']);
        add_action('wp_ajax_sply_save_settings', [$this, 'ajax_save_settings']);
    }

    public function add_settings_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . SPLY_Post_Type::POST_TYPE,
            __('SecurePlay Settings', 'secureplay'),
            __('Settings', 'secureplay'),
            'manage_options',
            'sply-settings',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        $isCptScreen = isset($_GET['post_type']) && $_GET['post_type'] === SPLY_Post_Type::POST_TYPE;
        $isSettingsScreen = $hook === (SPLY_Post_Type::POST_TYPE . '_page_sply-settings');

        if (!$isCptScreen && !$isSettingsScreen && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('sply-admin', SPLY_URL . 'assets/css/admin.css', [], SPLY_VERSION);

        wp_enqueue_script('sply-admin', SPLY_URL . 'assets/js/admin.js', ['jquery', 'wp-color-picker'], SPLY_VERSION, true);
        wp_localize_script('sply-admin', 'splyAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sply_admin'),
        ]);
    }

    public function license_notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sply') === false) {
            return;
        }
        if (SPLY_License::is_active()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('SecurePlay: the license is not active. New videos won\'t be processed until you activate the license below.', 'secureplay') .
            '</p></div>';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $licenseActive = SPLY_License::is_active();
        $licenseKey = SPLY_License::key();
        $settings = SPLY_Settings::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SecurePlay — Settings', 'secureplay'); ?></h1>

            <h2><?php esc_html_e('License', 'secureplay'); ?></h2>
            <p>
                <?php esc_html_e('Status:', 'secureplay'); ?>
                <strong style="color: <?php echo $licenseActive ? '#1a7d3a' : '#b32d2e'; ?>">
                    <?php echo $licenseActive ? esc_html__('Active', 'secureplay') : esc_html__('Inactive', 'secureplay'); ?>
                </strong>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="sply_license_key"><?php esc_html_e('License key', 'secureplay'); ?></label></th>
                    <td>
                        <input type="text" id="sply_license_key" class="regular-text" value="<?php echo esc_attr($licenseKey); ?>" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: Gumroad product URL */
                                esc_html__('You can find your license key in the purchase receipt from %s.', 'secureplay'),
                                '<a href="https://jurajkurek.gumroad.com/l/hfghzi" target="_blank" rel="noopener">Gumroad</a>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="sply-activate-license"><?php esc_html_e('Activate', 'secureplay'); ?></button>
                <?php if ($licenseActive) : ?>
                    <button type="button" class="button" id="sply-deactivate-license"><?php esc_html_e('Deactivate', 'secureplay'); ?></button>
                <?php endif; ?>
                <span id="sply-license-message"></span>
            </p>

            <hr />

            <h2><?php esc_html_e('General', 'secureplay'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="sply_default_color"><?php esc_html_e('Default player color', 'secureplay'); ?></label></th>
                    <td><input type="text" id="sply_default_color" class="sply-color-field" value="<?php echo esc_attr($settings['default_color']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="sply_segment_duration"><?php esc_html_e('HLS segment length (seconds)', 'secureplay'); ?></label></th>
                    <td><input type="number" id="sply_segment_duration" min="2" max="30" value="<?php echo esc_attr($settings['segment_duration']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="sply_ffmpeg_path"><?php esc_html_e('Path to ffmpeg', 'secureplay'); ?></label></th>
                    <td>
                        <input type="text" id="sply_ffmpeg_path" class="regular-text" value="<?php echo esc_attr($settings['ffmpeg_path']); ?>" />
                        <p class="description">
                            <?php echo SPLY_Encoder::ffmpeg_available()
                                ? '<span style="color:#1a7d3a">' . esc_html__('ffmpeg is available.', 'secureplay') . '</span>'
                                : '<span style="color:#b32d2e">' . esc_html__('Could not run ffmpeg at this path.', 'secureplay') . '</span>'; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sply_watermark_enabled"><?php esc_html_e('Viewer email watermark', 'secureplay'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="sply_watermark_enabled" <?php checked($settings['watermark_enabled']); ?> />
                            <?php esc_html_e('Overlay the logged-in viewer\'s email on the video, at a shifting position', 'secureplay'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('A leak deterrent, not a download blocker — it doesn\'t stop screen recording, but a leaked video can be traced back to whoever watched it.', 'secureplay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="sply-save-settings"><?php esc_html_e('Save settings', 'secureplay'); ?></button>
                <span id="sply-settings-message"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_activate_license(): void
    {
        check_ajax_referer('sply_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'secureplay')], 403);
        }

        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = SPLY_License::instance()->activate($key);

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    public function ajax_deactivate_license(): void
    {
        check_ajax_referer('sply_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'secureplay')], 403);
        }

        SPLY_License::instance()->deactivate();
        wp_send_json_success(['message' => __('License deactivated.', 'secureplay')]);
    }

    public function ajax_save_settings(): void
    {
        check_ajax_referer('sply_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'secureplay')], 403);
        }

        $color = isset($_POST['default_color']) ? sanitize_hex_color(wp_unslash($_POST['default_color'])) : '';
        $duration = isset($_POST['segment_duration']) ? (int) $_POST['segment_duration'] : 6;
        $ffmpegPath = isset($_POST['ffmpeg_path']) ? sanitize_text_field(wp_unslash($_POST['ffmpeg_path'])) : 'ffmpeg';
        $watermarkEnabled = isset($_POST['watermark_enabled']) && $_POST['watermark_enabled'] === '1';

        SPLY_Settings::update([
            'default_color' => $color ?: SPLY_Settings::defaults()['default_color'],
            'segment_duration' => max(2, min(30, $duration)),
            'ffmpeg_path' => $ffmpegPath ?: 'ffmpeg',
            'watermark_enabled' => $watermarkEnabled,
        ]);

        wp_send_json_success(['message' => __('Settings saved.', 'secureplay')]);
    }
}
