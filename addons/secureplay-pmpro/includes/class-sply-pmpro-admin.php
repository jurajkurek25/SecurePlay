<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Its own small settings page for the add-on's own license, separate from core's. */
final class SPLY_PMPRO_Admin
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

        add_action('wp_ajax_sply_pmpro_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_sply_pmpro_deactivate_license', [$this, 'ajax_deactivate_license']);
    }

    public function add_settings_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . SPLY_Post_Type::POST_TYPE,
            __('PMPro Add-on License', 'secureplay-pmpro'),
            __('PMPro Add-on', 'secureplay-pmpro'),
            'manage_options',
            'sply-pmpro-license',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== (SPLY_Post_Type::POST_TYPE . '_page_sply-pmpro-license')) {
            return;
        }

        wp_enqueue_script('sply-pmpro-admin', SPLY_PMPRO_URL . 'assets/js/admin.js', ['jquery'], SPLY_PMPRO_VERSION, true);
        wp_localize_script('sply-pmpro-admin', 'splyPmproAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sply_pmpro_admin'),
        ]);
    }

    public function license_notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sply') === false) {
            return;
        }
        if (SPLY_PMPRO_License::is_active()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('SecurePlay for Paid Memberships Pro: the add-on license is not active. Existing tier mappings keep working, but you won\'t be able to add or change any until you activate it.', 'secureplay-pmpro') .
            '</p></div>';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $licenseActive = SPLY_PMPRO_License::is_active();
        $licenseKey = SPLY_PMPRO_License::key();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SecurePlay for Paid Memberships Pro — License', 'secureplay-pmpro'); ?></h1>
            <p>
                <?php esc_html_e('Status:', 'secureplay-pmpro'); ?>
                <strong style="color: <?php echo $licenseActive ? '#1a7d3a' : '#b32d2e'; ?>">
                    <?php echo $licenseActive ? esc_html__('Active', 'secureplay-pmpro') : esc_html__('Inactive', 'secureplay-pmpro'); ?>
                </strong>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="sply_pmpro_license_key"><?php esc_html_e('License key', 'secureplay-pmpro'); ?></label></th>
                    <td>
                        <input type="text" id="sply_pmpro_license_key" class="regular-text" value="<?php echo esc_attr($licenseKey); ?>" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: Gumroad product URL */
                                esc_html__('You can find your license key in the purchase receipt from %s. This is a separate license from the main SecurePlay plugin.', 'secureplay-pmpro'),
                                '<a href="https://jurajkurek.gumroad.com/l/secureplay-pmpro" target="_blank" rel="noopener">Gumroad</a>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="sply-pmpro-activate-license"><?php esc_html_e('Activate', 'secureplay-pmpro'); ?></button>
                <?php if ($licenseActive) : ?>
                    <button type="button" class="button" id="sply-pmpro-deactivate-license"><?php esc_html_e('Deactivate', 'secureplay-pmpro'); ?></button>
                <?php endif; ?>
                <span id="sply-pmpro-license-message"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_activate_license(): void
    {
        check_ajax_referer('sply_pmpro_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'secureplay-pmpro')], 403);
        }

        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = SPLY_PMPRO_License::instance()->activate($key);

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    public function ajax_deactivate_license(): void
    {
        check_ajax_referer('sply_pmpro_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'secureplay-pmpro')], 403);
        }

        SPLY_PMPRO_License::instance()->deactivate();
        wp_send_json_success(['message' => __('License deactivated.', 'secureplay-pmpro')]);
    }
}
