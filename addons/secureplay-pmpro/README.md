# SecurePlay — Paid Memberships Pro

Optional add-on for [SecurePlay](../../README.md). Requires both SecurePlay
and [Paid Memberships Pro](https://www.paidmembershipspro.com/) active —
shows an admin notice and does nothing otherwise.

## What it does

On any `sply_video` post's edit screen, a **Membership Tiers** box lets you
pick a different video per PMPro membership level:

- A visitor with **Level A** sees video A on the `[secureplay]` shortcode.
- A visitor with **Level B** sees video B on the exact same shortcode.
- A visitor with no matching level (including logged-out visitors) sees a
  message plus **Log In** / **Get \<Level Name\>** buttons instead of a
  player — no video info leaks to them at all.

This isn't just hiding the player on the page: a video used as a level's
target is gated at the actual decryption-key level too. Guessing or
sharing the direct key-server URL for a tier-specific video still gets
rejected for a visitor without that level, the same way SecurePlay's key
server rejects a logged-out visitor by default.

## Setup

1. Install and activate both SecurePlay and Paid Memberships Pro.
2. Install and activate this add-on.
3. Go to **Videos → PMPro Add-on** and activate this add-on's own license
   key — it's a separate product from the main SecurePlay plugin, so it
   has its own key from its own purchase receipt.
4. Upload each tier's video normally under **Videos → Add Video** (one
   `sply_video` post per video, same as always).
5. Open the video whose shortcode you'll actually place on the page, and
   in **Membership Tiers**, map each PMPro level to the video it should
   show. Leave a level unmapped to fall back to the locked message for
   visitors who only have that level.
6. Drop `[secureplay id="123"]` (the ID of the video you configured tiers
   on) into the page — everything else happens automatically per viewer.

## Licensing

Same model as the main plugin: a [Gumroad License Key](https://help.gumroad.com/article/76-license-keys),
activated under **Videos → PMPro Add-on**, no separate license server.
Licensing only gates the ability to add or change tier mappings — an
already-configured site keeps working exactly as set up even if this
add-on's own license lapses, it just can't accept new changes until
reactivated.

## How it fits together

This add-on doesn't touch SecurePlay's code at all — it only uses three
hooks SecurePlay exposes for exactly this purpose:

- `sply_resolve_video_id` — swaps in the right video ID for the current
  viewer's level, or returns `0` to signal "no video for this viewer."
- `sply_locked_html` — supplies the message + buttons shown when the
  previous hook returned `0`.
- `sply_can_view_video` — the real security gate: rejects a key request
  for a tier-specific video from anyone without the required level.

Any other membership or e-commerce plugin can hook the same three filters
to build an equivalent integration without needing changes to SecurePlay
itself.
