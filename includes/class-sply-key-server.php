<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves the raw 16-byte AES-128 key hls.js/Safari expect for HLS key
 * loading. This deliberately uses admin-ajax.php rather than the WP REST
 * API: REST responses are JSON-enveloped, and there's no clean way to hand
 * back raw binary through that envelope without every client having to
 * unwrap it — plain admin-ajax with a manual Content-Type + echo is the
 * simpler, spec-correct fit here.
 */
final class SPLY_Key_Server
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
        add_action('wp_ajax_sply_key', [$this, 'serve_key']);
        add_action('wp_ajax_nopriv_sply_key', [$this, 'serve_key']);
    }

    public function serve_key(): void
    {
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $post = $postId ? get_post($postId) : null;

        if (!$post || $post->post_type !== SPLY_Post_Type::POST_TYPE) {
            status_header(404);
            exit;
        }

        /**
         * Filters whether the current visitor may fetch this video's key.
         * Defaults to "must be logged in" — hook into this to check
         * WooCommerce order status, a membership plugin, or anything else.
         *
         * @param bool $canView
         * @param int  $postId
         * @param int  $userId 0 when not logged in
         */
        $canView = apply_filters('sply_can_view_video', is_user_logged_in(), $postId, get_current_user_id());

        if (!$canView) {
            status_header(403);
            exit;
        }

        $encrypted = get_post_meta($postId, SPLY_Post_Type::META_ENCRYPTED_KEY, true);
        $status = get_post_meta($postId, SPLY_Post_Type::META_STATUS, true);

        if (!$encrypted || $status !== 'ready') {
            status_header(404);
            exit;
        }

        $rawKeyBase64 = SPLY_Crypto::decrypt($encrypted);
        if ($rawKeyBase64 === null) {
            status_header(500);
            exit;
        }

        $rawKey = base64_decode($rawKeyBase64, true);
        if ($rawKey === false) {
            status_header(500);
            exit;
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($rawKey));
        echo $rawKey;
        exit;
    }
}
