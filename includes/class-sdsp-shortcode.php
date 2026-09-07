<?php

if (!defined('ABSPATH')) {
    exit;
}

/** `[secure_player id="123" color="#hex"]` */
final class SDSP_Shortcode
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
        add_shortcode('secure_player', [$this, 'render']);
    }

    public function render(array $atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
            'color' => '',
        ], $atts, 'secure_player');

        $postId = (int) $atts['id'];
        $post = $postId ? get_post($postId) : null;

        if (!$post || $post->post_type !== SDSP_Post_Type::POST_TYPE) {
            return current_user_can('edit_posts')
                ? '<p>' . esc_html__('Secure Player: video sa nenašlo.', 'secure-player') . '</p>'
                : '';
        }

        $status = get_post_meta($postId, SDSP_Post_Type::META_STATUS, true);
        if ($status !== 'ready') {
            return current_user_can('edit_posts')
                ? '<p>' . esc_html__('Secure Player: video ešte nie je pripravené.', 'secure-player') . '</p>'
                : '';
        }

        $manifestUrl = SDSP_Post_Type::manifest_url($postId);
        if (!$manifestUrl) {
            return '';
        }

        $this->enqueue_assets();

        $color = $atts['color'] ?: get_post_meta($postId, SDSP_Post_Type::META_COLOR, true);
        $color = $color ?: SDSP_Settings::get('default_color');
        $color = sanitize_hex_color($color) ?: SDSP_Settings::get('default_color');

        $thumbnailUrl = SDSP_Post_Type::thumbnail_url($postId);
        $elementId = 'sdsp-player-' . $postId;

        ob_start();
        ?>
        <div class="sdsp-player-wrap" style="--sdsp-color: <?php echo esc_attr($color); ?>">
            <video
                id="<?php echo esc_attr($elementId); ?>"
                class="sdsp-player"
                playsinline
                controls
                controlsList="nodownload noremoteplayback"
                disablePictureInPicture
                oncontextmenu="return false;"
                <?php if ($thumbnailUrl) : ?>poster="<?php echo esc_url($thumbnailUrl); ?>"<?php endif; ?>
                data-sdsp-src="<?php echo esc_url($manifestUrl); ?>"
            ></video>
        </div>
        <?php
        return ob_get_clean();
    }

    private function enqueue_assets(): void
    {
        if ($this->assetsEnqueued) {
            return;
        }
        $this->assetsEnqueued = true;

        wp_enqueue_style('sdsp-plyr', SDSP_URL . 'assets/vendor/plyr/plyr.css', [], SDSP_VERSION);
        wp_enqueue_style('sdsp-player', SDSP_URL . 'assets/css/player.css', ['sdsp-plyr'], SDSP_VERSION);

        wp_enqueue_script('sdsp-hlsjs', SDSP_URL . 'assets/vendor/hlsjs/hls.min.js', [], SDSP_VERSION, true);
        wp_enqueue_script('sdsp-plyr', SDSP_URL . 'assets/vendor/plyr/plyr.min.js', [], SDSP_VERSION, true);
        wp_enqueue_script('sdsp-player', SDSP_URL . 'assets/js/player.js', ['sdsp-hlsjs', 'sdsp-plyr'], SDSP_VERSION, true);
    }
}
