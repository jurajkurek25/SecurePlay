<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gumroad License Key integration. Gumroad itself is the license
 * issuer/verifier (POST /v2/licenses/verify) — this plugin never runs its
 * own license server.
 *
 * Gumroad's API has no native per-seat/domain lock: every verify call
 * returns a cumulative `uses` counter that increments once per call made
 * with increment_uses_count=true. So a genuinely new activation increments
 * and is rejected if `uses` exceeds MAX_SEATS; a re-check of a key this
 * exact site already activated must NOT increment (or every daily cron
 * tick would burn a "seat" and eventually lock the paying customer out of
 * their own site).
 */
final class SPLY_License
{
    const PRODUCT_ID = '5kO1gjKF7HCv2QARDaMh9g==';
    const VERIFY_URL = 'https://api.gumroad.com/v2/licenses/verify';
    const MAX_SEATS = 1;

    const OPTION_KEY = 'sply_license_key';
    const OPTION_STATUS = 'sply_license_status';
    const OPTION_EMAIL = 'sply_license_email';

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
        add_action('sply_license_cron_check', [$this, 'recheck']);
    }

    public static function key(): string
    {
        return (string) get_option(self::OPTION_KEY, '');
    }

    public static function is_active(): bool
    {
        return get_option(self::OPTION_STATUS, 'inactive') === 'active';
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function activate(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['success' => false, 'message' => __('Enter a license key.', 'secureplay')];
        }

        $isOwnPriorActivation = ($licenseKey === self::key()) && self::is_active();
        $result = $this->verify_with_gumroad($licenseKey, !$isOwnPriorActivation);

        if (!$result['success']) {
            return $result;
        }

        update_option(self::OPTION_KEY, $licenseKey);
        update_option(self::OPTION_STATUS, 'active');
        update_option(self::OPTION_EMAIL, $result['email'] ?? '');

        return ['success' => true, 'message' => __('License activated.', 'secureplay')];
    }

    public function deactivate(): void
    {
        update_option(self::OPTION_STATUS, 'inactive');
    }

    /** Daily cron re-check: never increments uses, only degrades status on failure. */
    public function recheck(): void
    {
        $key = self::key();
        if ($key === '') {
            return;
        }

        $result = $this->verify_with_gumroad($key, false);
        if (!$result['success']) {
            update_option(self::OPTION_STATUS, 'inactive');
        }
    }

    /**
     * @return array{success:bool,message:string,email?:string}
     */
    private function verify_with_gumroad(string $licenseKey, bool $increment): array
    {
        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 15,
            'body' => [
                'product_id' => self::PRODUCT_ID,
                'license_key' => $licenseKey,
                'increment_uses_count' => $increment ? 'true' : 'false',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            $message = is_array($body) && !empty($body['message'])
                ? $body['message']
                : __('Could not verify this license key.', 'secureplay');
            return ['success' => false, 'message' => $message];
        }

        $purchase = $body['purchase'] ?? [];
        if (!empty($purchase['refunded']) || !empty($purchase['chargebacked'])) {
            return ['success' => false, 'message' => __('This license has been refunded or charged back.', 'secureplay')];
        }
        if (!empty($purchase['subscription_cancelled_at'])) {
            return ['success' => false, 'message' => __('The subscription for this license has been cancelled.', 'secureplay')];
        }

        $uses = isset($body['uses']) ? (int) $body['uses'] : 1;
        $isOwnPriorActivation = ($licenseKey === self::key()) && self::is_active();
        if ($uses > self::MAX_SEATS && !$isOwnPriorActivation) {
            return ['success' => false, 'message' => __('This license is already active on another site.', 'secureplay')];
        }

        return ['success' => true, 'message' => 'ok', 'email' => $purchase['email'] ?? ''];
    }
}
