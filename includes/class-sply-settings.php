<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Thin wrapper around a single `sply_settings` option. */
final class SPLY_Settings
{
    const OPTION_KEY = 'sply_settings';

    public static function defaults(): array
    {
        return [
            'default_color' => '#c9a130',
            'ffmpeg_path' => 'ffmpeg',
            'segment_duration' => 6,
            'watermark_enabled' => false,
        ];
    }

    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return wp_parse_args($stored, self::defaults());
    }

    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? (self::defaults()[$key] ?? null);
    }

    public static function update(array $values): void
    {
        $current = self::all();
        update_option(self::OPTION_KEY, array_merge($current, $values));
    }
}
