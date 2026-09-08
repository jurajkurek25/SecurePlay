<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps a `sply_video` post (the one placed via [secureplay id="..."]) to a
 * different video per Paid Memberships Pro level, with a login/upgrade
 * prompt for anyone who doesn't have a matching level.
 *
 * This only ever plugs into SecurePlay through its public extension
 * points — `sply_resolve_video_id`, `sply_locked_html`, and
 * `sply_can_view_video` — so it works entirely as an add-on with no
 * changes needed inside SecurePlay itself for a different membership
 * plugin later.
 *
 * A video used as a level's target is intentionally single-purpose: it
 * carries a `_sply_pmpro_required_levels` meta pointing back at whichever
 * level(s) currently map to it, kept in sync whenever the parent's
 * mapping is saved. That's what actually gates the decryption key, not
 * just the on-page display — a visitor who guesses the tier-specific
 * video's own shortcode still can't fetch its key without the right
 * level.
 */
final class SPLY_PMPRO_Addon
{
    const META_VARIANTS = '_sply_pmpro_variants';
    const META_LOCKED_MESSAGE = '_sply_pmpro_locked_message';
    const META_REQUIRED_LEVELS = '_sply_pmpro_required_levels';

    private static ?self $instance = null;
    private bool $frontStylesPrinted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post_' . SPLY_Post_Type::POST_TYPE, [$this, 'handle_save'], 20, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_filter('sply_resolve_video_id', [$this, 'resolve_video_id'], 10, 2);
        add_filter('sply_locked_html', [$this, 'locked_html'], 10, 3);
        add_filter('sply_can_view_video', [$this, 'can_view_video'], 10, 3);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'sply_pmpro',
            __('Membership Tiers (Paid Memberships Pro)', 'secureplay-pmpro'),
            [$this, 'render_meta_box'],
            SPLY_Post_Type::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field('sply_pmpro_save', 'sply_pmpro_nonce');

        $levels = pmpro_getAllLevels(true, true);
        $variants = $this->get_variants($post->ID);
        $message = get_post_meta($post->ID, self::META_LOCKED_MESSAGE, true);

        $otherVideos = get_posts([
            'post_type' => SPLY_Post_Type::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'exclude' => [$post->ID],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if (!$levels) {
            echo '<p>' . esc_html__('No Paid Memberships Pro levels found yet — create one under Memberships → Membership Levels first.', 'secureplay-pmpro') . '</p>';
            return;
        }

        echo '<p class="description">' . esc_html__('Pick which video plays for each level. Leave a level blank to give it no special video — visitors with only that level (or no level) will see the message below instead of a player.', 'secureplay-pmpro') . '</p>';

        echo '<table class="widefat sply-pmpro-table"><thead><tr>';
        echo '<th>' . esc_html__('Level', 'secureplay-pmpro') . '</th>';
        echo '<th>' . esc_html__('Video for this level', 'secureplay-pmpro') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($levels as $level) {
            $selected = $variants[$level->id] ?? 0;
            echo '<tr><td>' . esc_html($level->name) . '</td><td>';
            echo '<select name="sply_pmpro_video[' . (int) $level->id . ']">';
            echo '<option value="0">' . esc_html__('— No video for this level —', 'secureplay-pmpro') . '</option>';
            foreach ($otherVideos as $video) {
                echo '<option value="' . (int) $video->ID . '" ' . selected($selected, $video->ID, false) . '>' .
                    esc_html($video->post_title ?: ('#' . $video->ID)) . '</option>';
            }
            echo '</select></td></tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:14px"><label for="sply_pmpro_locked_message"><strong>' .
            esc_html__('Message for visitors without a matching level', 'secureplay-pmpro') . '</strong></label><br />';
        echo '<textarea id="sply_pmpro_locked_message" name="sply_pmpro_locked_message" rows="2" class="large-text" placeholder="' .
            esc_attr__('This video is available to members. Log in or choose a plan to watch it.', 'secureplay-pmpro') . '">' .
            esc_textarea($message) . '</textarea></p>';
    }

    public function handle_save(int $postId): void
    {
        if (!isset($_POST['sply_pmpro_nonce']) || !wp_verify_nonce($_POST['sply_pmpro_nonce'], 'sply_pmpro_save')) {
            return;
        }
        if (!current_user_can('edit_post', $postId) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $previousVariants = $this->get_variants($postId);

        $rawVideoByLevel = isset($_POST['sply_pmpro_video']) && is_array($_POST['sply_pmpro_video'])
            ? wp_unslash($_POST['sply_pmpro_video'])
            : [];

        $variants = [];
        foreach ($rawVideoByLevel as $levelId => $videoId) {
            $levelId = (int) $levelId;
            $videoId = (int) $videoId;
            if ($levelId > 0 && $videoId > 0) {
                $variants[$levelId] = $videoId;
            }
        }

        // Drop the reverse-index from videos no longer used as anyone's
        // target for this parent, so an unmapped video doesn't stay
        // gated behind a level it's no longer assigned to.
        foreach ($previousVariants as $oldLevelId => $oldVideoId) {
            $stillUsed = ($variants[$oldLevelId] ?? null) === $oldVideoId;
            if (!$stillUsed) {
                delete_post_meta($oldVideoId, self::META_REQUIRED_LEVELS);
            }
        }

        foreach ($variants as $levelId => $videoId) {
            update_post_meta($videoId, self::META_REQUIRED_LEVELS, [$levelId]);
        }

        if ($variants) {
            update_post_meta($postId, self::META_VARIANTS, $variants);
        } else {
            delete_post_meta($postId, self::META_VARIANTS);
        }

        $message = isset($_POST['sply_pmpro_locked_message']) ? sanitize_textarea_field(wp_unslash($_POST['sply_pmpro_locked_message'])) : '';
        if ($message !== '') {
            update_post_meta($postId, self::META_LOCKED_MESSAGE, $message);
        } else {
            delete_post_meta($postId, self::META_LOCKED_MESSAGE);
        }
    }

    /** @return array<int,int> level_id => video_id */
    private function get_variants(int $postId): array
    {
        $variants = get_post_meta($postId, self::META_VARIANTS, true);
        return is_array($variants) ? $variants : [];
    }

    public function resolve_video_id(int $postId, array $atts): int
    {
        $variants = $this->get_variants($postId);
        if (!$variants) {
            return $postId;
        }

        $userId = get_current_user_id();
        if ($userId) {
            foreach (array_keys($variants) as $levelId) {
                if (pmpro_hasMembershipLevel($levelId, $userId)) {
                    return (int) $variants[$levelId];
                }
            }
        }

        return 0;
    }

    public function locked_html(string $html, int $postId, array $atts): string
    {
        $variants = $this->get_variants($postId);
        if (!$variants) {
            return $html;
        }

        $message = get_post_meta($postId, self::META_LOCKED_MESSAGE, true);
        if ($message === '') {
            $message = __('This video is available to members. Log in or choose a plan to watch it.', 'secureplay-pmpro');
        }

        ob_start();
        // Printed inline rather than via a normally-enqueued stylesheet:
        // shortcodes render during the_content, well after wp_head has
        // already output enqueued <link> tags for most themes, so a
        // wp_enqueue_style() call made from here would silently never
        // reach the page. A one-time inline <style> block is small enough
        // that this is the more reliable choice.
        if (!$this->frontStylesPrinted) {
            $this->frontStylesPrinted = true;
            ?>
            <style>
                .sply-pmpro-locked { padding: 24px; border-radius: 8px; background: rgba(127, 127, 127, 0.08); text-align: center; }
                .sply-pmpro-locked-message { margin: 0 0 14px; }
                .sply-pmpro-locked-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
                .sply-pmpro-btn { display: inline-block; padding: 9px 18px; border-radius: 6px; background: #333; color: #fff; text-decoration: none; font-weight: 600; }
                .sply-pmpro-btn-buy { background: var(--sply-color, #c9a130); color: #1a1a1a; }
            </style>
            <?php
        }
        ?>
        <div class="sply-pmpro-locked">
            <p class="sply-pmpro-locked-message"><?php echo esc_html($message); ?></p>
            <div class="sply-pmpro-locked-actions">
                <?php if (!is_user_logged_in()) : ?>
                    <a class="sply-pmpro-btn" href="<?php echo esc_url(pmpro_url('login')); ?>">
                        <?php esc_html_e('Log In', 'secureplay-pmpro'); ?>
                    </a>
                <?php endif; ?>
                <?php foreach (array_keys($variants) as $levelId) :
                    $level = pmpro_getLevel((int) $levelId);
                    if (!$level) {
                        continue;
                    }
                    ?>
                    <a class="sply-pmpro-btn sply-pmpro-btn-buy" href="<?php echo esc_url(pmpro_url('checkout', '?level=' . (int) $levelId)); ?>">
                        <?php
                        printf(
                            /* translators: %s: membership level name */
                            esc_html__('Get %s', 'secureplay-pmpro'),
                            esc_html($level->name)
                        );
                        ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function can_view_video(bool $canView, int $postId, int $userId): bool
    {
        $requiredLevels = get_post_meta($postId, self::META_REQUIRED_LEVELS, true);
        if (!is_array($requiredLevels) || !$requiredLevels) {
            return $canView;
        }

        if (!$userId) {
            return false;
        }

        return (bool) pmpro_hasMembershipLevel($requiredLevels, $userId);
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $isCptScreen = ($hook === 'post.php' || $hook === 'post-new.php')
            && isset($_GET['post_type']) && $_GET['post_type'] === SPLY_Post_Type::POST_TYPE;
        // On post.php the post_type query var usually isn't present; fall
        // back to checking the current post's type instead.
        if (!$isCptScreen && $hook === 'post.php' && isset($_GET['post'])) {
            $isCptScreen = get_post_type((int) $_GET['post']) === SPLY_Post_Type::POST_TYPE;
        }
        if (!$isCptScreen) {
            return;
        }

        wp_enqueue_style('sply-pmpro-admin', SPLY_PMPRO_URL . 'assets/css/admin.css', [], SPLY_PMPRO_VERSION);
    }
}
