# Secure Player

Encrypted, key-gated HLS video player for WordPress. Upload a video, it's
packaged as AES-128 encrypted HLS, and the decryption key is only ever
handed to visitors your site allows — the `.m3u8`/`.ts` files themselves
can sit in public `wp-content/uploads/` and be useless without the key.

This mirrors the same protection model used by [strhnidav](https://github.com/jurajkurek25/strhnidav)'s
lesson player, adapted to a distributable WordPress plugin.

## What this protects against, and what it doesn't

This is **protection against casual downloading and link-sharing**, not
absolute DRM:

- ✅ Right-clicking "Save video" does nothing useful — there's no single
  downloadable file, only encrypted segments.
- ✅ Copying the page URL or the `.m3u8`/`.ts` URLs to someone else gets
  them nothing without a valid session on your site.
- ✅ The AES-128 key is encrypted at rest in the database and is served
  only to visitors your `sdsp_can_view_video` gating logic approves.
- ❌ It does **not** stop screen recording — no browser-based video
  protection can. True hardware-backed DRM (Widevine/FairPlay/PlayReady)
  requires a paid license from Google/Apple/Microsoft and studio-grade
  infrastructure; that's a different product tier, not something an
  independent WordPress plugin can offer.

Market it honestly as "protects against casual downloading," not
"unbreakable" — that claim doesn't exist for any web video player.

## Requirements

- WordPress 5.8+, PHP 7.4+
- `ffmpeg` available on the server (shell `exec()` must be enabled). Check
  under **Videá → Nastavenia** — the settings page tells you if ffmpeg was
  found.
- A [Gumroad](https://gumroad.com) product with **license keys** enabled,
  used to activate the plugin (see Licensing below).

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip
   through **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Videá → Nastavenia** and activate your license key (from your
   Gumroad purchase receipt).
4. Go to **Videá → Pridať video**, upload a video file, optionally set a
   per-video player color, and save. Encoding runs in the background via
   WP-Cron — refresh the page after a bit to see the status change to
   "Pripravené" (ready).
5. Drop `[secure_player id="123"]` into any post or page.

### Shortcode

```
[secure_player id="123"]
[secure_player id="123" color="#c9a130"]
```

`color` overrides both the video's own color setting and the plugin's
global default (**Videá → Nastavenia**).

## Gating who can watch

By default, any logged-in WordPress user can play a video once they have
the key. To restrict it further — a specific membership level, a
WooCommerce order, a custom capability — hook into the
`sdsp_can_view_video` filter:

```php
add_filter('sdsp_can_view_video', function (bool $canView, int $postId, int $userId) {
    // Example: only users with a specific membership meta flag.
    return $canView && get_user_meta($userId, 'has_premium', true) === '1';
}, 10, 3);
```

Return `false` to deny — the key endpoint responds with a 403 and the
player simply never starts.

## Licensing

Activation uses [Gumroad's native License Key API](https://help.gumroad.com/article/76-license-keys) —
there is no separate license server to run or maintain. One license key
activates on one site at a time; activating it elsewhere requires
deactivating it here first (**Videá → Nastavenia → Deaktivovať**). A daily
background check re-verifies the key so a refund or subscription
cancellation on Gumroad's side is caught automatically — it never breaks
already-published videos, it only blocks processing new ones until the
license is renewed.

## Architecture

- `includes/class-sdsp-encoder.php` — shells out to ffmpeg with
  `-hls_key_info_file` to produce AES-128 HLS output; the raw key file and
  key-info file are deleted from disk immediately after encoding.
- `includes/class-sdsp-crypto.php` — encrypts the HLS key at rest (AES-256-CBC,
  keyed off the site's `AUTH_KEY`) before it's stored in post meta.
- `includes/class-sdsp-key-server.php` — serves the raw key over
  `admin-ajax.php` (not the REST API — hls.js/Safari need a raw binary
  response, which doesn't fit the REST JSON envelope), gated by the
  `sdsp_can_view_video` filter.
- `includes/class-sdsp-post-type.php` — the `sdsp_video` CPT: upload
  handling, background encoding via WP-Cron, status tracking.
- `includes/class-sdsp-shortcode.php` — renders the player, lazily loading
  bundled hls.js and Plyr only on pages that use it.
- `includes/class-sdsp-license.php` — Gumroad license activation and daily
  re-verification.
- `includes/class-sdsp-admin.php` — the **Videá → Nastavenia** settings
  screen (license + general options).

## Development

```
php -l secure-player.php includes/*.php   # syntax check
```

The bundled vendor assets (`assets/vendor/hlsjs`, `assets/vendor/plyr`)
are the official prebuilt `dist/` files from the `hls.js` and `plyr` npm
packages, vendored directly since the plugin has no build step of its
own.
