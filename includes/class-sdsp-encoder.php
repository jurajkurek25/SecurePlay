<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Packages a source video into AES-128 encrypted HLS via ffmpeg, the same
 * approach the sister Next.js app uses for its lesson videos. The raw key
 * and the key-info file ffmpeg needs are both written to a temp location
 * and deleted immediately after encoding — neither ever needs to persist
 * on disk once the .m3u8/.ts segments exist.
 */
final class SDSP_Encoder
{
    public static function ffmpeg_binary(): string
    {
        return (string) SDSP_Settings::get('ffmpeg_path');
    }

    public static function ffmpeg_available(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $binary = escapeshellcmd(self::ffmpeg_binary());
        exec($binary . ' -version 2>&1', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * @return array{key:string,output_dir:string}|WP_Error key is base64-encoded raw 16-byte AES key
     */
    public static function encode(string $sourcePath, string $outputDir, int $postId)
    {
        if (!self::ffmpeg_available()) {
            return new WP_Error('sdsp_no_ffmpeg', __('ffmpeg nie je na tomto serveri dostupný.', 'secure-player'));
        }

        if (!wp_mkdir_p($outputDir)) {
            return new WP_Error('sdsp_mkdir_failed', __('Nepodarilo sa vytvoriť pracovný priečinok.', 'secure-player'));
        }

        $rawKey = random_bytes(16);
        $iv = bin2hex(random_bytes(16));

        $keyPath = $outputDir . '/key.bin';
        $keyInfoPath = $outputDir . '/key.info';
        $placeholderUri = 'sdsp://key-placeholder/' . $postId;

        file_put_contents($keyPath, $rawKey);
        file_put_contents($keyInfoPath, $placeholderUri . "\n" . $keyPath . "\n" . $iv . "\n");

        $manifestPath = $outputDir . '/index.m3u8';
        $segmentDuration = max(2, (int) SDSP_Settings::get('segment_duration'));

        $cmd = sprintf(
            '%s -y -i %s -c:v libx264 -c:a aac -hls_time %d -hls_key_info_file %s -hls_playlist_type vod -hls_segment_filename %s %s 2>&1',
            escapeshellcmd(self::ffmpeg_binary()),
            escapeshellarg($sourcePath),
            $segmentDuration,
            escapeshellarg($keyInfoPath),
            escapeshellarg($outputDir . '/segment_%03d.ts'),
            escapeshellarg($manifestPath)
        );

        exec($cmd, $output, $exitCode);

        // The key and key-info files only ever needed to exist for this
        // ffmpeg invocation; nothing after this point should read them
        // from disk again.
        @unlink($keyPath);
        @unlink($keyInfoPath);

        if ($exitCode !== 0 || !file_exists($manifestPath)) {
            self::cleanup_dir($outputDir);
            return new WP_Error('sdsp_encode_failed', __('Kódovanie videa zlyhalo.', 'secure-player') . ' ' . implode("\n", array_slice($output, -10)));
        }

        $manifest = file_get_contents($manifestPath);
        $manifest = str_replace($placeholderUri, admin_url('admin-ajax.php') . '?action=sdsp_key&post=' . $postId, $manifest);
        file_put_contents($manifestPath, $manifest);

        self::extract_thumbnail($sourcePath, $outputDir . '/thumbnail.jpg');

        return [
            'key' => base64_encode($rawKey),
            'output_dir' => $outputDir,
        ];
    }

    /** Best-effort — a missing thumbnail is not fatal to a working player. */
    private static function extract_thumbnail(string $sourcePath, string $thumbnailPath): void
    {
        $cmd = sprintf(
            '%s -y -i %s -ss 00:00:01 -vframes 1 %s 2>&1',
            escapeshellcmd(self::ffmpeg_binary()),
            escapeshellarg($sourcePath),
            escapeshellarg($thumbnailPath)
        );
        @exec($cmd);
    }

    public static function cleanup_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob(rtrim($dir, '/') . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
