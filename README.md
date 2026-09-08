# SecurePlay

Encrypted, key-gated HLS video player for WordPress. Upload a video, it's
packaged as AES-128 encrypted HLS, and the decryption key is only ever
handed to visitors your site allows — the `.m3u8`/`.ts` files themselves
can sit in public `wp-content/uploads/` and be useless without the key.

## What this protects against, and what it doesn't

This is **protection against casual downloading and link-sharing**, not
absolute DRM:

- ✅ Right-clicking "Save video" does nothing useful — there's no single
  downloadable file, only encrypted segments.
- ✅ Copying the page URL or the `.m3u8`/`.ts` URLs to someone else gets
  them nothing without a valid session on your site.
- ✅ The AES-128 key is encrypted at rest in the database and is served
  only to visitors your `sply_can_view_video` gating logic approves.
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
  under **Videos → Settings** — the settings page tells you if ffmpeg was
  found.
- A [Gumroad](https://gumroad.com) product with **license keys** enabled,
  used to activate the plugin (see Licensing below).

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip
   through **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Videos → Settings** and activate your license key (from your
   Gumroad purchase receipt).
4. Go to **Videos → Add Video**, upload a video file, optionally set a
   per-video player color, and save. Encoding runs in the background via
   WP-Cron — refresh the page after a bit to see the status change to
   "Ready".
5. Drop `[secureplay id="123"]` into any post or page.

### Shortcode

```
[secureplay id="123"]
[secureplay id="123" color="#c9a130"]
```

`color` overrides both the video's own color setting and the plugin's
global default (**Videos → Settings**).

### Chapters

Each video's edit screen has a **Chapters** box — add timestamped rows
(`mm:ss` or `h:mm:ss`, e.g. `1:23` or `1:02:15`) with a title, the same
way YouTube chapters work. On the front end this shows as a clickable
chapter list under the player plus markers on the progress bar; clicking
either jumps playback to that point. Rows left without a title are
ignored, so you can add a few and fill them in later.

## Gating who can watch

By default, any logged-in WordPress user can play a video once they have
the key. To restrict it further — a specific membership level, a
WooCommerce order, a custom capability — hook into the
`sply_can_view_video` filter:

```php
add_filter('sply_can_view_video', function (bool $canView, int $postId, int $userId) {
    // Example: only users with a specific membership meta flag.
    return $canView && get_user_meta($userId, 'has_premium', true) === '1';
}, 10, 3);
```

Return `false` to deny — the key endpoint responds with a 403 and the
player simply never starts.

## Add-ons

- **[SecurePlay — Paid Memberships Pro](addons/secureplay-pmpro)** — shows
  a different video per PMPro membership level on the same shortcode, and
  a login/upgrade prompt for anyone without a matching level. Built
  entirely on the `sply_resolve_video_id`, `sply_locked_html`, and
  `sply_can_view_video` hooks above — a template for building the same
  kind of integration against any other membership or e-commerce plugin.

## Viewer email watermark

Turn on **Videos → Settings → Viewer email watermark** to overlay the
logged-in viewer's email on the video, at a shifting position that
changes every few seconds.

This is a **leak deterrent, not a download blocker**: nothing running in
a browser can detect or stop screen recording, and no video player on the
web — including Netflix — can either without licensed hardware DRM
(Widevine L1/FairPlay + HDCP), which is a different product tier entirely.
What the watermark does instead is make a leaked recording traceable back
to whoever watched it, which is usually enough of a deterrent on its own.

For anonymous visitors (no WordPress account, e.g. a WooCommerce guest
checkout) there's no email to show by default, so no watermark renders
unless you supply one via the `sply_watermark_identity` filter:

```php
add_filter('sply_watermark_identity', function (string $identity, int $postId, int $userId) {
    // Example: pull the email from a guest checkout stored elsewhere.
    return $identity ?: my_plugin_get_guest_email();
}, 10, 3);
```

## Licensing

Activation uses [Gumroad's native License Key API](https://help.gumroad.com/article/76-license-keys) —
there is no separate license server to run or maintain. Activating checks
that the key is genuine and still in good standing (not refunded,
charged back, or cancelled); it doesn't hard-enforce single-site use,
since Gumroad's API has no way to release a "seat" once used — a check
like that would permanently lock out anyone who legitimately migrates
domains or reinstalls. A daily background check re-verifies the key so a
refund or cancellation on Gumroad's side is caught automatically — it
never breaks already-published videos, it only blocks processing new
ones until the license is renewed.

## Architecture

- `includes/class-sply-encoder.php` — shells out to ffmpeg with
  `-hls_key_info_file` to produce AES-128 HLS output; the raw key file and
  key-info file are deleted from disk immediately after encoding.
- `includes/class-sply-crypto.php` — encrypts the HLS key at rest (AES-256-CBC,
  keyed off the site's `AUTH_KEY`) before it's stored in post meta.
- `includes/class-sply-key-server.php` — serves the raw key over
  `admin-ajax.php` (not the REST API — hls.js/Safari need a raw binary
  response, which doesn't fit the REST JSON envelope), gated by the
  `sply_can_view_video` filter.
- `includes/class-sply-post-type.php` — the `sply_video` CPT: upload
  handling, background encoding via WP-Cron, status tracking.
- `includes/class-sply-shortcode.php` — renders the player, lazily loading
  bundled hls.js and Plyr only on pages that use it.
- `includes/class-sply-license.php` — Gumroad license activation and daily
  re-verification.
- `includes/class-sply-admin.php` — the **Videos → Settings** settings
  screen (license + general options).

## Development

```
php -l secureplay.php includes/*.php   # syntax check
```

The bundled vendor assets (`assets/vendor/hlsjs`, `assets/vendor/plyr`)
are the official prebuilt `dist/` files from the `hls.js` and `plyr` npm
packages, vendored directly since the plugin has no build step of its
own.

## Author

Juraj Augustín Kurek
