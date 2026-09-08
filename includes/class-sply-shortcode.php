<?php

if (!defined('ABSPATH')) {
    exit;
}

/** `[secureplay id="123" color="#hex"]` */
final class SPLY_Shortcode
{
    private static ?self $instance = null;
    private bool $assetsEnqueued = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_shortcode('secureplay', [$this, 'render']);
    }

    public function render(array $atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
            'color' => '',
        ], $atts, 'secureplay');

        $postId = (int) $atts['id'];
        $post = $postId ? get_post($postId) : null;

        if (!$post || $post->post_type !== SPLY_Post_Type::POST_TYPE) {
            return current_user_can('edit_posts')
                ? '<p>' . esc_html__('SecurePlay: video not found.', 'secureplay') . '</p>'
                : '';
        }

        $status = get_post_meta($postId, SPLY_Post_Type::META_STATUS, true);
        if ($status !== 'ready') {
            return current_user_can('edit_posts')
                ? '<p>' . esc_html__('SecurePlay: video is not ready yet.', 'secureplay') . '</p>'
                : '';
        }

        $manifestUrl = SPLY_Post_Type::manifest_url($postId);
        if (!$manifestUrl) {
            return '';
        }

        $this->enqueue_assets();

        $color = $atts['color'] ?: get_post_meta($postId, SPLY_Post_Type::META_COLOR, true);
        $color = $color ?: SPLY_Settings::get('default_color');
        $color = sanitize_hex_color($color) ?: SPLY_Settings::get('default_color');

        $thumbnailUrl = SPLY_Post_Type::thumbnail_url($postId);
        $elementId = 'sply-player-' . $postId;
        $watermark = SPLY_Settings::get('watermark_enabled') ? $this->watermark_identity($postId) : '';
        $chapters = SPLY_Post_Type::get_chapters($postId);

        ob_start();
        ?>
        <div class="sply-player-wrap" style="--sply-color: <?php echo esc_attr($color); ?>" <?php if ($chapters) : ?>data-sply-chapters="<?php echo esc_attr(wp_json_encode($chapters)); ?>"<?php endif; ?>>
            <video
                id="<?php echo esc_attr($elementId); ?>"
                class="sply-player"
                playsinline
                controls
                controlsList="nodownload noremoteplayback"
                disablePictureInPicture
                oncontextmenu="return false;"
                <?php if ($thumbnailUrl) : ?>poster="<?php echo esc_url($thumbnailUrl); ?>"<?php endif; ?>
                data-sply-src="<?php echo esc_url($manifestUrl); ?>"
                <?php if ($watermark !== '') : ?>data-sply-watermark="<?php echo esc_attr($watermark); ?>"<?php endif; ?>
            ></video>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The identity burned into the on-screen watermark. Defaults to the
     * logged-in viewer's email; returns '' (no watermark) for anonymous
     * visitors unless a site owner supplies one — e.g. a WooCommerce
     * order email for a non-account checkout — via the
     * `sply_watermark_identity` filter.
     *
     * This is a leak deterrent, not a download blocker: it doesn't stop
     * screen recording, it just makes a leaked video traceable back to
     * whoever watched it.
     */
    private function watermark_identity(int $postId): string
    {
        $identity = '';
        $userId = get_current_user_id();
        if ($userId) {
            $user = get_userdata($userId);
            $identity = $user ? $user->user_email : '';
        }

        return (string) apply_filters('sply_watermark_identity', $identity, $postId, $userId);
    }

    private function enqueue_assets(): void
    {
        if ($this->assetsEnqueued) {
            return;
        }
        $this->assetsEnqueued = true;

        wp_enqueue_style('sply-plyr', SPLY_URL . 'assets/vendor/plyr/plyr.css', [], SPLY_VERSION);
        wp_enqueue_style('sply-player', SPLY_URL . 'assets/css/player.css', ['sply-plyr'], SPLY_VERSION);

        wp_enqueue_script('sply-hlsjs', SPLY_URL . 'assets/vendor/hlsjs/hls.min.js', [], SPLY_VERSION, true);
        wp_enqueue_script('sply-plyr', SPLY_URL . 'assets/vendor/plyr/plyr.min.js', [], SPLY_VERSION, true);
        wp_enqueue_script('sply-player', SPLY_URL . 'assets/js/player.js', ['sply-hlsjs', 'sply-plyr'], SPLY_VERSION, true);
    }
}
