<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `sply_video` custom post type: one post per uploaded video. Not public —
 * videos are only ever displayed through the [secureplay] shortcode, never
 * via their own front-end permalink.
 */
final class SPLY_Post_Type
{
    const POST_TYPE = 'sply_video';
    const META_STATUS = '_sply_status';
    const META_ENCRYPTED_KEY = '_sply_encrypted_key';
    const META_OUTPUT_DIR = '_sply_output_dir';
    const META_COLOR = '_sply_color';
    const META_ERROR = '_sply_error';
    const META_CHAPTERS = '_sply_chapters';

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
        add_action('post_edit_form_tag', [$this, 'add_upload_enctype']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'handle_save'], 10, 2);
        add_action('sply_process_video', [$this, 'process_video']);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Videos', 'secureplay'),
                'singular_name' => __('Video', 'secureplay'),
                'add_new_item' => __('Add Video', 'secureplay'),
                'edit_item' => __('Edit Video', 'secureplay'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-lock',
            'capability_type' => 'post',
            'supports' => ['title'],
        ]);
    }

    /**
     * WordPress's post editor form doesn't send multipart/form-data by
     * default — a meta box with a file input has to add that itself, or
     * the browser silently submits the file field as empty and $_FILES
     * never gets populated.
     */
    public function add_upload_enctype(WP_Post $post): void
    {
        if ($post->post_type === self::POST_TYPE) {
            echo ' enctype="multipart/form-data"';
        }
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('sply_upload', __('Video File', 'secureplay'), [$this, 'render_upload_box'], self::POST_TYPE, 'normal', 'high');
        add_meta_box('sply_chapters', __('Chapters', 'secureplay'), [$this, 'render_chapters_box'], self::POST_TYPE, 'normal', 'default');
        add_meta_box('sply_appearance', __('Appearance', 'secureplay'), [$this, 'render_appearance_box'], self::POST_TYPE, 'side');
        add_meta_box('sply_embed', __('Embed', 'secureplay'), [$this, 'render_embed_box'], self::POST_TYPE, 'side');
    }

    public function render_upload_box(WP_Post $post): void
    {
        wp_nonce_field('sply_save_video', 'sply_nonce');
        $status = get_post_meta($post->ID, self::META_STATUS, true) ?: 'empty';
        $error = get_post_meta($post->ID, self::META_ERROR, true);

        echo '<p><input type="file" name="sply_video_file" accept="video/*" /></p>';
        echo '<p class="description">' . esc_html__('Upload a new file to replace the video. Supported formats depend on ffmpeg on this server.', 'secureplay') . '</p>';

        $labels = [
            'empty' => __('Not uploaded yet', 'secureplay'),
            'processing' => __('Processing…', 'secureplay'),
            'ready' => __('Ready', 'secureplay'),
            'error' => __('Error', 'secureplay'),
        ];
        echo '<p><strong>' . esc_html__('Status:', 'secureplay') . '</strong> ' . esc_html($labels[$status] ?? $status) . '</p>';

        if ($status === 'error' && $error) {
            echo '<p style="color:#b32d2e">' . esc_html($error) . '</p>';
        }

        if (!SPLY_License::is_active()) {
            echo '<p style="color:#b32d2e">' . esc_html__('The plugin license is not active — new videos won\'t be processed until you activate it under Settings.', 'secureplay') . '</p>';
        }
    }

    /**
     * A YouTube-style chapter list: timestamp + title pairs, shown to
     * viewers as a clickable list under the player with matching markers
     * on the progress bar.
     */
    public function render_chapters_box(WP_Post $post): void
    {
        $chapters = self::get_chapters($post->ID);
        echo '<div id="sply-chapters-rows">';
        if ($chapters) {
            foreach ($chapters as $chapter) {
                $this->render_chapter_row((int) $chapter['time'], (string) $chapter['title']);
            }
        }
        echo '</div>';

        echo '<template id="sply-chapter-row-template">';
        $this->render_chapter_row(0, '');
        echo '</template>';

        echo '<p><button type="button" class="button" id="sply-add-chapter">' . esc_html__('+ Add chapter', 'secureplay') . '</button></p>';
        echo '<p class="description">' . esc_html__('Format: mm:ss or h:mm:ss (e.g. 1:23 or 1:02:15). Rows with no title are ignored.', 'secureplay') . '</p>';
    }

    private function render_chapter_row(int $seconds, string $title): void
    {
        $timeStr = ($seconds === 0 && $title === '') ? '' : self::format_seconds($seconds);
        echo '<div class="sply-chapter-row">';
        echo '<input type="text" class="small-text" name="sply_chapter_time[]" value="' . esc_attr($timeStr) . '" placeholder="0:00" />';
        echo '<input type="text" name="sply_chapter_title[]" value="' . esc_attr($title) . '" placeholder="' . esc_attr__('Chapter title', 'secureplay') . '" />';
        echo '<button type="button" class="button-link sply-remove-chapter" aria-label="' . esc_attr__('Remove chapter', 'secureplay') . '">&times;</button>';
        echo '</div>';
    }

    public function render_appearance_box(WP_Post $post): void
    {
        $color = get_post_meta($post->ID, self::META_COLOR, true);
        echo '<p><label>' . esc_html__('Player color (optional, otherwise the default is used)', 'secureplay') . '</label><br />';
        echo '<input type="text" name="sply_color" class="sply-color-field" value="' . esc_attr($color) . '" placeholder="#c9a130" /></p>';
    }

    public function render_embed_box(WP_Post $post): void
    {
        echo '<p>' . esc_html__('Paste this shortcode into any post or page:', 'secureplay') . '</p>';
        echo '<p><code>[secureplay id="' . (int) $post->ID . '"]</code></p>';
    }

    public function handle_save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST['sply_nonce']) || !wp_verify_nonce($_POST['sply_nonce'], 'sply_save_video')) {
            return;
        }
        if (!current_user_can('edit_post', $postId) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $color = isset($_POST['sply_color']) ? sanitize_hex_color(wp_unslash($_POST['sply_color'])) : '';
        update_post_meta($postId, self::META_COLOR, $color ?: '');
        update_post_meta($postId, self::META_CHAPTERS, $this->collect_chapters_from_request());

        if (empty($_FILES['sply_video_file']['name'])) {
            return;
        }

        if (!SPLY_License::is_active()) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, __('The plugin license is not active.', 'secureplay'));
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = ['test_form' => false];
        $uploaded = wp_handle_upload($_FILES['sply_video_file'], $overrides);

        if (isset($uploaded['error'])) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, $uploaded['error']);
            return;
        }

        // Any previously encoded output for this video is now stale.
        $existingDir = get_post_meta($postId, self::META_OUTPUT_DIR, true);
        if ($existingDir) {
            SPLY_Encoder::cleanup_dir($existingDir);
        }

        update_post_meta($postId, self::META_STATUS, 'processing');
        update_post_meta($postId, '_sply_source_file', $uploaded['file']);
        delete_post_meta($postId, self::META_ERROR);

        // Encoding can run well past a typical PHP request timeout on
        // shared hosting, so it's handed off to WP-Cron and the admin
        // screen just shows "processing" until the next page load.
        wp_schedule_single_event(time(), 'sply_process_video', [$postId]);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public function process_video(int $postId): void
    {
        $sourceFile = get_post_meta($postId, '_sply_source_file', true);
        if (!$sourceFile || !file_exists($sourceFile)) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, __('The source file could not be found.', 'secureplay'));
            return;
        }

        $uploadDir = wp_upload_dir();
        $outputDir = trailingslashit($uploadDir['basedir']) . 'sply/' . $postId;

        $result = SPLY_Encoder::encode($sourceFile, $outputDir, $postId);

        @unlink($sourceFile);
        delete_post_meta($postId, '_sply_source_file');

        if (is_wp_error($result)) {
            update_post_meta($postId, self::META_STATUS, 'error');
            update_post_meta($postId, self::META_ERROR, $result->get_error_message());
            return;
        }

        update_post_meta($postId, self::META_ENCRYPTED_KEY, SPLY_Crypto::encrypt($result['key']));
        update_post_meta($postId, self::META_OUTPUT_DIR, $result['output_dir']);
        update_post_meta($postId, self::META_STATUS, 'ready');
    }

    public function admin_columns(array $columns): array
    {
        $columns['sply_status'] = __('Status', 'secureplay');
        $columns['sply_shortcode'] = __('Shortcode', 'secureplay');
        return $columns;
    }

    public function render_admin_column(string $column, int $postId): void
    {
        if ($column === 'sply_status') {
            $status = get_post_meta($postId, self::META_STATUS, true) ?: 'empty';
            echo esc_html($status);
        }
        if ($column === 'sply_shortcode') {
            echo '<code>[secureplay id="' . (int) $postId . '"]</code>';
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

    /** @return array<int, array{time:int,title:string}> */
    public static function get_chapters(int $postId): array
    {
        $chapters = get_post_meta($postId, self::META_CHAPTERS, true);
        return is_array($chapters) ? $chapters : [];
    }

    /** @return array<int, array{time:int,title:string}> parsed, sorted, title-less rows dropped */
    private function collect_chapters_from_request(): array
    {
        $rawTimes = isset($_POST['sply_chapter_time']) ? (array) wp_unslash($_POST['sply_chapter_time']) : [];
        $rawTitles = isset($_POST['sply_chapter_title']) ? (array) wp_unslash($_POST['sply_chapter_title']) : [];

        $chapters = [];
        foreach ($rawTimes as $i => $rawTime) {
            $title = isset($rawTitles[$i]) ? sanitize_text_field($rawTitles[$i]) : '';
            $seconds = self::parse_time($rawTime);
            if ($title === '' || $seconds === null) {
                continue;
            }
            $chapters[] = ['time' => $seconds, 'title' => $title];
        }

        usort($chapters, fn($a, $b) => $a['time'] <=> $b['time']);

        return $chapters;
    }

    private static function parse_time(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        $rawParts = explode(':', $raw);
        if (count($rawParts) < 2 || count($rawParts) > 3) {
            return null;
        }
        foreach ($rawParts as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
        }

        $seconds = 0;
        foreach ($rawParts as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }

        return max(0, $seconds);
    }

    private static function format_seconds(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
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
