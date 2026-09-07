<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Settings page: license activation + general options, plus admin notices. */
final class SDSP_Admin
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

        add_action('wp_ajax_sdsp_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_sdsp_deactivate_license', [$this, 'ajax_deactivate_license']);
        add_action('wp_ajax_sdsp_save_settings', [$this, 'ajax_save_settings']);
    }

    public function add_settings_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . SDSP_Post_Type::POST_TYPE,
            __('Nastavenia Secure Player', 'secure-player'),
            __('Nastavenia', 'secure-player'),
            'manage_options',
            'sdsp-settings',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        $isCptScreen = isset($_GET['post_type']) && $_GET['post_type'] === SDSP_Post_Type::POST_TYPE;
        $isSettingsScreen = $hook === (SDSP_Post_Type::POST_TYPE . '_page_sdsp-settings');

        if (!$isCptScreen && !$isSettingsScreen && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_script('sdsp-admin', SDSP_URL . 'assets/js/admin.js', ['jquery', 'wp-color-picker'], SDSP_VERSION, true);
        wp_localize_script('sdsp-admin', 'sdspAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sdsp_admin'),
        ]);
    }

    public function license_notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sdsp') === false) {
            return;
        }
        if (SDSP_License::is_active()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('Secure Player: licencia nie je aktívna. Nové videá sa nebudú spracovávať, kým licenciu neaktivuješ nižšie.', 'secure-player') .
            '</p></div>';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $licenseActive = SDSP_License::is_active();
        $licenseKey = SDSP_License::key();
        $settings = SDSP_Settings::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Secure Player — Nastavenia', 'secure-player'); ?></h1>

            <h2><?php esc_html_e('Licencia', 'secure-player'); ?></h2>
            <p>
                <?php esc_html_e('Stav:', 'secure-player'); ?>
                <strong style="color: <?php echo $licenseActive ? '#1a7d3a' : '#b32d2e'; ?>">
                    <?php echo $licenseActive ? esc_html__('Aktívna', 'secure-player') : esc_html__('Neaktívna', 'secure-player'); ?>
                </strong>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="sdsp_license_key"><?php esc_html_e('Licenčný kľúč', 'secure-player'); ?></label></th>
                    <td>
                        <input type="text" id="sdsp_license_key" class="regular-text" value="<?php echo esc_attr($licenseKey); ?>" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: Gumroad product URL */
                                esc_html__('Licenčný kľúč nájdeš v potvrdení nákupu z %s.', 'secure-player'),
                                '<a href="https://jurajkurek.gumroad.com/l/hfghzi" target="_blank" rel="noopener">Gumroad</a>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="sdsp-activate-license"><?php esc_html_e('Aktivovať', 'secure-player'); ?></button>
                <?php if ($licenseActive) : ?>
                    <button type="button" class="button" id="sdsp-deactivate-license"><?php esc_html_e('Deaktivovať', 'secure-player'); ?></button>
                <?php endif; ?>
                <span id="sdsp-license-message"></span>
            </p>

            <hr />

            <h2><?php esc_html_e('Všeobecné', 'secure-player'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="sdsp_default_color"><?php esc_html_e('Predvolená farba prehrávača', 'secure-player'); ?></label></th>
                    <td><input type="text" id="sdsp_default_color" class="sdsp-color-field" value="<?php echo esc_attr($settings['default_color']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="sdsp_segment_duration"><?php esc_html_e('Dĺžka segmentu HLS (sekundy)', 'secure-player'); ?></label></th>
                    <td><input type="number" id="sdsp_segment_duration" min="2" max="30" value="<?php echo esc_attr($settings['segment_duration']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="sdsp_ffmpeg_path"><?php esc_html_e('Cesta k ffmpeg', 'secure-player'); ?></label></th>
                    <td>
                        <input type="text" id="sdsp_ffmpeg_path" class="regular-text" value="<?php echo esc_attr($settings['ffmpeg_path']); ?>" />
                        <p class="description">
                            <?php echo SDSP_Encoder::ffmpeg_available()
                                ? '<span style="color:#1a7d3a">' . esc_html__('ffmpeg je dostupné.', 'secure-player') . '</span>'
                                : '<span style="color:#b32d2e">' . esc_html__('ffmpeg sa nepodarilo spustiť touto cestou.', 'secure-player') . '</span>'; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="sdsp-save-settings"><?php esc_html_e('Uložiť nastavenia', 'secure-player'); ?></button>
                <span id="sdsp-settings-message"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_activate_license(): void
    {
        check_ajax_referer('sdsp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nemáš oprávnenie.', 'secure-player')], 403);
        }

        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = SDSP_License::instance()->activate($key);

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    public function ajax_deactivate_license(): void
    {
        check_ajax_referer('sdsp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nemáš oprávnenie.', 'secure-player')], 403);
        }

        SDSP_License::instance()->deactivate();
        wp_send_json_success(['message' => __('Licencia bola deaktivovaná.', 'secure-player')]);
    }

    public function ajax_save_settings(): void
    {
        check_ajax_referer('sdsp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nemáš oprávnenie.', 'secure-player')], 403);
        }

        $color = isset($_POST['default_color']) ? sanitize_hex_color(wp_unslash($_POST['default_color'])) : '';
        $duration = isset($_POST['segment_duration']) ? (int) $_POST['segment_duration'] : 6;
        $ffmpegPath = isset($_POST['ffmpeg_path']) ? sanitize_text_field(wp_unslash($_POST['ffmpeg_path'])) : 'ffmpeg';

        SDSP_Settings::update([
            'default_color' => $color ?: SDSP_Settings::defaults()['default_color'],
            'segment_duration' => max(2, min(30, $duration)),
            'ffmpeg_path' => $ffmpegPath ?: 'ffmpeg',
        ]);

        wp_send_json_success(['message' => __('Nastavenia boli uložené.', 'secure-player')]);
    }
}
