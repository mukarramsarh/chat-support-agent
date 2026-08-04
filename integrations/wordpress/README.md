# WordPress → Support AI admin SSO

One-click login to the support-ai admin panel from the WordPress dashboard — the
same scheme already used for the Assessment admin, so ProcurementHub is driven
from one place.

## How it works

```
WP admin clicks button  →  /admin/sso?t=<unix_ts>&h=<hmac_sha256(secret, ts)>
                             support-ai verifies signature + freshness
                             → starts the admin session → /admin
```

The button is only shown to WordPress administrators (`manage_options`). The link
carries no password — just a timestamp and an HMAC signature over it, computed
with a secret shared between the two apps. It is valid for 60 seconds and is
regenerated on every dashboard page load.

## Setup (2 steps)

1. **Pick a shared secret** — a long random string, e.g.
   ```
   openssl rand -hex 24
   ```
   Put the SAME value in both places:
   - support-ai `.env` → `SSO_SECRET=...` (the installer generates one automatically;
     reuse that value, or set your own and keep both sides identical)
   - `support-ai-sso.php` → `PH_SUPPORTAI_SSO_SECRET`

2. **Install the WordPress side** — copy `support-ai-sso.php` into
   `wp-content/mu-plugins/` (create the folder if needed) or paste its body into
   your theme's `functions.php`. Set `PH_SUPPORTAI_URL` to your support-ai base
   URL (include the sub-folder, e.g. `.../chatbot`).

That's it. A **Support AI Admin** button appears in the top admin bar and as a
dashboard widget.

## Security notes

- **Signature required** — without the shared secret no valid link can be forged
  (`hash_hmac` + `hash_equals` constant-time compare).
- **Short-lived** — links expire after `SSO_TTL_SECONDS` (default 60s), with a
  small clock-skew tolerance.
- **Single-use** — support-ai rejects a token the second time it is seen, so a
  link captured from history/logs cannot be replayed.
- **Rate-limited** — the `/admin/sso` endpoint is throttled per IP against
  brute-force.
- **Audited** — every SSO login (and failure) is written to `audit_log`.
- **HTTPS only** in production — the link should never travel over plain HTTP.
- Rotate `SSO_SECRET` (both sides) if it is ever exposed; existing links stop
  working immediately.

The token proves "a holder of the shared secret asked, just now" and signs into
the owner admin account. If you need per-person attribution instead of one shared
login, extend the token to carry the WP user's email and map it to a matching
`admin_users` row.
