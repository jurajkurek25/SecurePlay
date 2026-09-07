<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `sdsp_video` custom post type: one post per uploaded video. Not public —
 * videos are only ever displayed through the [secure_player] shortcode,
 * never via their own front-end permalink.
 */
final class SDSP_Post_Type
{
    const POST_TYPE = 'sdsp_video';
    const META_STATUS = '_sdsp_status';
    const META_ENCRYPTED_KEY = '_sdsp_encrypted_key';
    const META_OUTPUT_DIR = '_sdsp_output_dir';
    const META_COLOR = '_sdsp_color';
    const META_ERROR = '_sdsp_error';

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
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'handle_save'], 10, 2);
        add_action('sdsp_process_video', [$this, 'process_video']);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Videá', 'secure-player'),
                'singular_name' => __('Video', 'secure-player'),
                'add_new_item' => __('Pridať video', 'secure-player'),
                'edit_item' => __('Upraviť video', 'secure-player'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-lock',
            'capability_type' => 'post',
            'supports' => ['title'],
        ]);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('sdsp_upload', __('Súbor videa', 'secure-player'), [$this, 'render_upload_box'], self::POST_TYPE, 'normal', 'high');
        add_meta_box('sdsp_appearance', __('Vzhľad', 'secure-player'), [$this, 'render_appearance_box'], self::POST_TYPE, 'side');
        add_meta_box('sdsp_embed', __('Vloženie', 'secure-player'), [$this, 'render_embed_box'], self::POST_TYPE, 'side');
    }

    public function render_upload_box(WP_Post $post): void
    {
        wp_nonce_field('sdsp_save_video', 'sdsp_nonce');
        $status = get_post_meta($post->ID, self::META_STATUS, true) ?: 'empty';
        $error = get_post_meta($post->ID, self::META_ERROR, true);

        echo '<p><input type="file" name="sdsp_video_file" accept="video/*" /></p>';
        echo '<p class="description">' . esc_html__('Nahraj nový súbor, ak chceš video nahradiť. Podporované formáty závisia od ffmpeg na tomto serveri.', 'secure-player') . '</p>';

        $labels = [
            'empty' => __('Zatiaľ nenahrané', 'secure-player'),
            'processing' => __('Spracováva sa…', 'secure-player'),
            'ready' => __('Pripravené', 'secure-player'),
            'error' => __('Chyba', 'secure-player'),
        ];
        echo '<p><strong>' . esc_html__('Stav:', 'secure-player') . '</strong> ' . esc_html($labels[$status] ?? $status) . '</p>';

        if ($status === 'error' && $error) {
            echo '<p style="color:#b32d2e">' . esc_html($error) . '</p>';
        }

        if (!SDSP_License::is_active()) {
            echo '<p style="color:#b32d2e">' . esc_html__('Licencia pluginu nie je aktívna — nové videá sa nespracujú, kým ju neaktivuješ v Nastaveniach.', 'secure-player') . '</p>';
        }
    }

    public function render_appearance_box(WP_Post $post): void
    {
        $color = get_post_meta($post->ID, self::META_COLOR, true);
        echo '<p><label>' . esc_html__('Farba prehrávača (voliteľné, inak sa použije predvolená)', 'secure-player') . '</label><br />';
        echo '<input type="text" name="sdsp_color" class="sdsp-color-field" value="' . esc_attr($color) . '" placeholder="#c9a130" /></p>';
    }

    public function render_embed_box(WP_Post $post): void
    {
        echo '<p>' . esc_html__('Vlož tento shortcode do stránky alebo príspevku:', 'secure-player') . '</p>';
        echo '<p><code>[secure_player id="' . (int) $post->ID . '"]</code></p>';
    }

    public function handle_save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST['sdsp_nonce']) || !wp_verify_nonce($_POST['sdsp_nonce'], 'sdsp_save_video')) {
            return;
        }
        if (!current_user_can('edit_post', $postId) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $color = isset($_POST['sdsp_color']) ? sanitize_hex_color(wp_unslash($_POST['sdsp_color'])) : '';
        update_post_meta($postId, self::META_COLOR, $color ?: '');

        if (empty($_FILES['sdsp_video_file']['name'])) {
            return;
        }

        if (!SDSP_License::is_active()) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, __('Licencia pluginu nie je aktívna.', 'secure-player'));
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = ['test_form' => false];
        $uploaded = wp_handle_upload($_FILES['sdsp_video_file'], $overrides);

        if (isset($uploaded['error'])) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, $uploaded['error']);
            return;
        }

        // Any previously encoded output for this video is now stale.
        $existingDir = get_post_meta($postId, self::META_OUTPUT_DIR, true);
        if ($existingDir) {
            SDSP_Encoder::cleanup_dir($existingDir);
        }

        update_post_meta($postId, self::META_STATUS, 'processing');
        update_post_meta($postId, '_sdsp_source_file', $uploaded['file']);
        delete_post_meta($postId, self::META_ERROR);

        // Encoding can run well past a typical PHP request timeout on
        // shared hosting, so it's handed off to WP-Cron and the admin
        // screen just shows "processing" until the next page load.
        wp_schedule_single_event(time(), 'sdsp_process_video', [$postId]);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public function process_video(int $postId): void
    {
        $sourceFile = get_post_meta($postId, '_sdsp_source_file', true);
        if (!$sourceFile || !file_exists($sourceFile)) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, __('Zdrojový súbor sa nenašiel.', 'secure-player'));
            return;
        }

        $uploadDir = wp_upload_dir();
        $outputDir = trailingslashit($uploadDir['basedir']) . 'sdsp/' . $postId;

        $result = SDSP_Encoder::encode($sourceFile, $outputDir, $postId);

        @unlink($sourceFile);
        delete_post_meta($postId, '_sdsp_source_file');

        if (is_wp_error($result)) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, $result->get_error_message());
            return;
        }

        update_post_meta($postId, self::META_ENCRYPTED_KEY, SDSP_Crypto::encrypt($result['key']));
        update_post_meta($postId, self::META_OUTPUT_DIR, $result['output_dir']);
        update_post_meta($postId, self::META_STATUS, 'ready');
    }

    public function admin_columns(array $columns): array
    {
        $columns['sdsp_status'] = __('Stav', 'secure-player');
        $columns['sdsp_shortcode'] = __('Shortcode', 'secure-player');
        return $columns;
    }

    public function render_admin_column(string $column, int $postId): void
    {
        if ($column === 'sdsp_status') {
            $status = get_post_meta($postId, self::META_STATUS, true) ?: 'empty';
            echo esc_html($status);
        }
        if ($column === 'sdsp_shortcode') {
            echo '<code>[secure_player id="' . (int) $postId . '"]</code>';
        }
    }

    public static function manifest_url(int $postId): ?string
    {
        $dir = get_post_meta($postId, self::META_OUTPUT_DIR, true);
        if (!$dir || !file_exists($dir . '/index.m3u8')) {
            return null;
        }
        $uploadDir = wp_upload_dir();
        $relative = str_replace($uploadDir['basedir'], '', $dir);
        return trailingslashit($uploadDir['baseurl']) . ltrim($relative, '/') . '/index.m3u8';
    }

    public static function thumbnail_url(int $postId): ?string
    {
        $dir = get_post_meta($postId, self::META_OUTPUT_DIR, true);
        if (!$dir || !file_exists($dir . '/thumbnail.jpg')) {
            return null;
        }
        $uploadDir = wp_upload_dir();
        $relative = str_replace($uploadDir['basedir'], '', $dir);
        return trailingslashit($uploadDir['baseurl']) . ltrim($relative, '/') . '/thumbnail.jpg';
    }
}
