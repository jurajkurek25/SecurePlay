<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gumroad License Key integration for this add-on specifically — it's
 * sold as its own product, so it needs its own key, independent of the
 * core SecurePlay license. Mirrors includes/class-sply-license.php in the
 * parent plugin (same verify endpoint, same "no single-site enforcement"
 * reasoning — see that file's docblock for why), just scoped to a
 * different Gumroad product and its own options.
 */
final class SPLY_PMPRO_License
{
    const PRODUCT_PERMALINK = 'secureplay-pmpro';
    const VERIFY_URL = 'https://api.gumroad.com/v2/licenses/verify';

    const OPTION_KEY = 'sply_pmpro_license_key';
    const OPTION_STATUS = 'sply_pmpro_license_status';
    const OPTION_EMAIL = 'sply_pmpro_license_email';

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
        add_action('sply_pmpro_license_cron_check', [$this, 'recheck']);
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
            return ['success' => false, 'message' => __('Enter a license key.', 'secureplay-pmpro')];
        }

        $isOwnPriorActivation = ($licenseKey === self::key()) && self::is_active();
        $result = $this->verify_with_gumroad($licenseKey, !$isOwnPriorActivation);

        if (!$result['success']) {
            return $result;
        }

        update_option(self::OPTION_KEY, $licenseKey);
        update_option(self::OPTION_STATUS, 'active');
        update_option(self::OPTION_EMAIL, $result['email'] ?? '');

        return ['success' => true, 'message' => __('License activated.', 'secureplay-pmpro')];
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
                'product_permalink' => self::PRODUCT_PERMALINK,
                'license_key' => $licenseKey,
                'increment_uses_count' => $increment ? 'true' : 'false',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            // If Gumroad rejects product_permalink and asks for product_id
            // instead (it did for the parent plugin's own product), that
            // exact message surfaces here — swap PRODUCT_PERMALINK above
            // for a PRODUCT_ID constant + 'product_id' body key the same
            // way class-sply-license.php does, using the ID the error
            // names.
            $message = is_array($body) && !empty($body['message'])
                ? $body['message']
                : __('Could not verify this license key.', 'secureplay-pmpro');
            return ['success' => false, 'message' => $message];
        }

        $purchase = $body['purchase'] ?? [];
        if (!empty($purchase['refunded']) || !empty($purchase['chargebacked'])) {
            return ['success' => false, 'message' => __('This license has been refunded or charged back.', 'secureplay-pmpro')];
        }
        if (!empty($purchase['subscription_cancelled_at'])) {
            return ['success' => false, 'message' => __('The subscription for this license has been cancelled.', 'secureplay-pmpro')];
        }

        return ['success' => true, 'message' => 'ok', 'email' => $purchase['email'] ?? ''];
    }
}
