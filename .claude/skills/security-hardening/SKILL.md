---
name: security-hardening
description: >
  Security checklist and audit runbook for support-ai. Use before every release
  and whenever touching a public endpoint, auth, uploads, URL ingestion, or the
  LLM call path. The overriding concern is TOKEN-BURN ABUSE — a public chat
  endpoint is a direct line to the AI budget, so every change must keep a bot,
  a rival, or a bad prompt from draining tokens. Shared-hosting rule still
  applies: pure PHP + MySQL, no Redis/node/docker in production.
---

# support-ai · security hardening

Single source of truth for the project's security posture. Each item lists the
threat, the control, and where it lives in the code. Re-run the audit steps at
the bottom before shipping.

Priorities, in order:
1. **Token / budget abuse** — the money threat. Nothing else matters if a bot can burn the month's budget in an hour.
2. **Data protection (KSA PDPL)** — visitor PII must stay encrypted, redacted before external LLMs, and access-controlled.
3. **App integrity** — auth, CSRF, uploads, SSRF, error hygiene.

---

## 1. Rate limiting  ·  `src/Support/RateLimiter.php`, `src/Support/Throttle.php`, `ChatController`
Threat: a scripted bot (ignoring browser CORS) hammers `/api/chat/*` to burn tokens or brute-forces `/admin/login`.

- [ ] **Strict on auth**: `/admin/login` locked at 8 fails / IP / 15 min, constant-time password check, generic error. (done)
- [ ] **Layered on chat**: every LLM-triggering request is gated on THREE keys at once — per-IP, per-visitor, and a global ceiling — so rotating IPs or many visitors still can't exceed a fleet-wide cap.
- [ ] **Exponential backoff, not hard lockout**: repeat offenders get a growing `Retry-After` (2→4→8…·capped), never a permanent block that would also hurt a real refreshing user.
- [ ] **Smart LLM limiting**: the daily-spend circuit breaker (§7) is the backstop when rate limits are evaded by a botnet.
- [ ] Public JSON endpoints stay CSRF-exempt (cross-origin by design) but are covered by the domain allowlist + the throttles above.

## 2. Input validation  ·  `src/Support/Validator.php`
Threat: oversized prompts (token burn), malformed IDs, injection into downstream systems.

- [ ] **Reject, don't just sanitize**: validate type, length, format, and allowed values on every input surface; fail closed with a generic 422.
- [ ] Chat: `message` length-capped; `visitor_id` / `conversation_id` format-checked; `page_url` length-capped and scheme-checked.
- [ ] Lead form: only admin-configured, enabled fields accepted; email format enforced; per-field length caps.
- [ ] Admin forms: server-side validation in addition to CSRF (never trust the browser).
- [ ] Prompt-injection defense lives in the system prompt (`ChatService::buildMessages`): KNOWLEDGE / memory / visitor text are labelled UNTRUSTED DATA and fenced. Keep that guard intact.

## 3. Secrets  ·  `src/Support/Env.php`, `.env`, `Crypto`
Threat: leaked API keys → someone else spends your tokens; leaked APP_KEY → visitor PII decryptable.

- [ ] **No hardcoded secrets** — all via `Env::get()` / DB (encrypted). Audit: see runbook. (clean as of last audit)
- [ ] **APP_KEY fail-safe**: in production the app refuses to boot on a missing/default key rather than encrypting PII with a known one.
- [ ] Stored provider keys are AEAD-encrypted (`Crypto`, libsodium→AES-GCM) and never logged.
- [ ] `.env`, `.sql`, `.log`, archives blocked from web access (root + `storage/` `.htaccess`); docroot should be `public/`.
- [ ] Rotate any key that ever appeared in a commit, screenshot, or chat.

## 4. Dependency audit  ·  `composer.json`
- [ ] Run `composer audit` each release; upgrade/replace by severity. Dependency surface is deliberately tiny (pdfparser, readability). (clean as of last audit)

## 5. Error handling  ·  `public/index.php`, `Response`, `Logger`
Threat: stack traces / paths / SQL errors leaking to visitors.

- [ ] Production: `display_errors=0`; exceptions AND fatals caught → generic 500 to client, full detail to `storage/logs`.
- [ ] Client messages are generic ("temporarily unavailable"); details only server-side.
- [ ] CSRF failure → 403 (not 419, which Apache remaps to 500).

## 6. File-upload safety  ·  `DocumentController::upload`
Threat: a disguised executable, an oversized file, or a path-traversal write.

- [ ] Size cap (10 MB) enforced before parsing.
- [ ] Extension allowlist (pdf, docx) AND content-based MIME sniff (`finfo`) — not extension alone.
- [ ] Stored with a random name under `storage/uploads/` (outside docroot, non-executable), unlinked after ingest.
- [ ] `storage/` denied at the web server as defense-in-depth.

## 7. Token-burn circuit breaker  ·  `ChatService` budget gate, `UsageRepository`
Threat: abuse that slips past rate limits still shouldn't drain the whole budget.

- [ ] **Monthly** cap per agent (existing) → graceful fallback, no LLM call past it.
- [ ] **Daily** cap (`DAILY_BUDGET_USD`) → same graceful decline; bounds worst-case daily loss to a few cents even under sustained abuse.
- [ ] Answer cache + prompt cache reduce spend on repeats; keep them on.
- [ ] `max_tokens` per answer bounded; retrieval top_k bounded.

## 8. SSRF  ·  URL ingestion (`TextExtractor`, `HttpClient`, `src/Support/Http/UrlGuard.php`)
Threat: an admin-supplied URL makes the server fetch cloud metadata (169.254.169.254) or internal services.

- [ ] Ingestion fetches go through `UrlGuard`: http/https only, and the resolved host must not be loopback/private/link-local/reserved.
- [ ] Redirects restricted to http/https; residual DNS-rebinding risk documented (admin-only surface).
- [ ] LLM/provider calls are exempt (fixed trusted hosts).

## 9. Sessions & auth  ·  `session_boot()` helper, `AdminController`
- [ ] Session cookie: HttpOnly, SameSite=Lax, Secure when HTTPS.
- [ ] `session_regenerate_id(true)` on successful login (anti-fixation).
- [ ] Passwords: `password_hash`/`password_verify` (bcrypt); min length enforced on create.

## 10. Transport & headers  ·  `public/index.php`
- [ ] `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP `frame-ancestors 'none'` set. (done)
- [ ] Force HTTPS at the host; never put PII in query strings.

---

## Audit runbook

```bash
# 1. Secrets — expect no hits outside vendor/tests/.env
grep -rnE "(sk-[A-Za-z0-9]{20,}|AIza[A-Za-z0-9_-]{20,}|-----BEGIN .*PRIVATE KEY-----)" \
  --include=*.php --include=*.js . | grep -viE "vendor/|/tests/"

# 2. Dependencies
composer audit

# 3. Static analysis + tests
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/phpunit

# 4. Manual: confirm docroot = public/, .env not web-reachable,
#    APP_KEY set & non-default, allowed_domains configured, budgets set.
```

Keep this file updated when a control moves or a new surface is added. When a
box here is unchecked in the code, it is a release blocker, not a nice-to-have.
