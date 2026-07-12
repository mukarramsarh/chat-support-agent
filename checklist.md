# support-ai — Build & Compliance Checklist

> **Living document.** Update the status boxes on every modification.
> Status legend: ✅ done · 🟡 partial / in progress · ⬜ not started
>
> **Two non-negotiable constraints govern everything below:**
> 1. **Shared-hosting only** — pure PHP + MySQL. No Docker, no node_modules, no build step, no guaranteed shell/binaries. Any binary/extension use must degrade to a pure-PHP fallback.
> 2. **KSA compliance (PDPL/SDAIA) — 0% compromise.** Personal data handling must follow Saudi PDPL. See item 11.

---

## 1. Easy setup on production (no node, no Docker) — 🟡
- [x] Pure-PHP app, docroot → `/public`, root `.htaccess` fallback
- [x] Portable schema; `bin/console install`
- [x] Docker is **dev-only** (never required in prod)
- [ ] **Web installer** (`/install`): DB check, run schema, create owner, write `.env` — for hosts with no shell/composer
- [ ] Ship a `vendor/` bundle or document upload (composer may be absent)
- [ ] Pre-flight checks page: PHP version, ext-pdo/curl/mbstring/zip, writable `storage/`

## 2. Easy to use — 🟡
- [x] Clean admin UI, live widget preview, one-line embed shortcode
- [ ] First-run onboarding wizard (keys → agent → first knowledge → embed)
- [ ] Inline help/tooltips; empty-state guidance on every page
- [ ] Test-chat sandbox inside admin

## 3. Code standards, OOP & SOLID — 🟡
- [x] Layered clean architecture (Domain/Application/Infrastructure/Http/Support)
- [x] DI container; depend on interfaces (LLMProvider, VectorStore, ContextRetriever, EmbeddingProvider)
- [x] `declare(strict_types=1)`, typed signatures, PSR-4
- [ ] Adopt PSR-12 + static analysis (PHPStan/Psalm) config
- [ ] Refactor any fat controllers; keep methods small
- [ ] CI workflow (lint + analyse + test) — runs off-host, fine

## 4. Unit tests — 🟡
- [x] PHPUnit set up (phpunit.xml, dev-only dep, `composer test`)
- [x] Pure-logic units: Chunker, VectorCodec (pack/cosine), Pricing, ModelHint, Config, Crypto, PiiRedactor — **32 tests, 72 assertions, green** (caught a real ModelHint bug)
- [ ] Provider adapters via mocked HttpClient
- [ ] RAG retriever + eval gate with fakes
- [ ] Repository/integration tests against a test DB
- [ ] CI workflow to run on push (off-host)

## 5. Detailed conversation history (per-session) in admin — 🟡
- [x] Conversations list page (clickable rows → detail)
- [x] Session detail view: full transcript, citations, per-message cost/tokens, eval telemetry, latency, model
- [x] Status filter chips + counts
- [ ] Date/cost filters + free-text search
- [ ] Export a session (JSON/CSV)

## 6. Per-session status labels — ✅
- [x] Status enum: `incomplete`, `ai_answered`, `needs_attention`, `escalated`, `resolved`, `abandoned`
- [x] Auto-derive: fallback / budget-declined / ungrounded → `needs_attention`; normal → `ai_answered`
- [x] Manual override in admin (session detail); colored pills; list counts
- [ ] Dashboard tile for `needs_attention` count (nice-to-have)

## 7. Intelligent chat memory (last ~3 turns + relevant old messages) — 🟡
- [x] Recent verbatim window (last ~3 turns) via MemoryService
- [x] Retrieve relevant OLDER messages across the visitor's sessions (FULLTEXT recall, zero token cost; degrades safely)
- [x] Injected into prompt as recall block; rolling summary slot wired
- [ ] Upgrade recall to embedding-based (semantic) for small histories where FULLTEXT underperforms
- [ ] Long-term fact extraction into `memories`, retrieved like knowledge
- [ ] Summarize + drop old turns to cap tokens

## 8. Solid RAG flow — 🟡
- [x] Ingestion → chunks + embeddings stored (text/URL/PDF/DOCX)
- [x] **RagRetriever wired into chat** (embed query → vector search → min-score gate → cited context)
- [x] **Pre-answer evaluation loop** — single structured call (answer/grounded/confidence/answered),
      free deterministic gates (min-score + citation-existence), ONE capped corrective retry, human-handoff
      fallback; vetted answer streamed in chunks. Eval telemetry stored per message + drives session status.
      Toggle via `ENABLE_EVAL`; true streaming preserved when off.
- [ ] Hybrid retrieval (FULLTEXT prefilter + vector) on the MySQL/PHP path
- [ ] Optional rerank (cheap model) when scores are ambiguous
- [ ] Answer cache (skip LLM on repeat FAQs)

## 9. Context uploading & update flow — 🟡
- [x] Add text / URL / PDF / DOCX; parse → chunk → embed → store; delete source
- [x] Embedding-model lock; `kb_version` bump invalidates cache
- [ ] Re-index / update a source (change detection via content_hash)
- [ ] Background cron ingestion for large files (job_queue) — keep sync path as fallback
- [ ] Per-source visibility toggle; recrawl URLs on a schedule

## 10. Security — 🟡
- [x] Encrypted secrets at rest (Crypto: sodium→openssl)
- [x] Admin session auth; prepared statements (no SQL injection); output escaping
- [x] Secrets never committed (.gitignore/.dockerignore)
- [x] CSRF tokens on all admin/state-changing forms (VerifyCsrf middleware, 403)
- [x] Rate limiting on public chat + lead (DB-backed RateLimiter)
- [x] Widget domain allowlist enforced in CORS (agent settings; open only until configured)
- [x] Security headers (X-Frame-Options, nosniff, Referrer-Policy, CSP frame-ancestors)
- [x] Brute-force login lockout (8/15min per IP)
- [x] Upload MIME sniff (fileinfo) + size + extension
- [ ] Prompt-injection hardening for ingested content + tool boundaries
- [ ] Password strength policy; optional 2FA

## 11. KSA compliance (PDPL / SDAIA) — 0% compromise — 🟡
- [x] **Consent**: startup form + widget show privacy notice + explicit consent before collecting PII; consent + timestamp + exact text stored
- [x] **Cross-border transfer**: **PII redaction before external LLM/embeddings** (PrivacyFilter + PiiRedactor: email/phone/ID/card/IBAN), toggle in admin
- [x] **Data residency option**: `VECTOR_DRIVER=php` keeps all vectors in local MySQL (no external vector store); documented
- [x] **Data subject rights**: erase-by-visitor + export-my-data (ComplianceService), audited
- [x] **Retention policy**: configurable auto-purge (cron / web-cron)
- [x] **Audit log** of erasures/exports (audit_log table)
- [x] **PII encrypted at rest** (leads via Crypto)
- [x] **RTL (Arabic)** widget layout toggle
- [ ] Admin RTL/Arabic localisation; Hijri dates
- [ ] Content safety aligned with local norms
- [ ] ⚠ Legal/DPO review required — code enables compliance but is not legal advice

## 12. Smart token usage — 🟡
- [x] Cheap default model (Gemini Flash); per-call cost logging; monthly budget gate
- [x] Keyword-based pricing matcher (real versioned ids resolve; unknown → non-zero default so budget can't be bypassed)
- [x] Retrieve-don't-stuff (only top-k cited chunks injected)
- [x] Prompt-cache markers on stable persona/knowledge blocks
- [ ] Token-budget-aware prompt assembly (trim history/knowledge to a ceiling)
- [ ] Summarize old turns instead of resending
- [ ] Answer cache for repeat questions
- [ ] Skip/downgrade model for trivial messages

## 13. Startup user form (admin-designed, enable/disable) — ✅
- [x] Admin builder (/admin/privacy): toggle on/off; per-field enable + required (name/email/phone/company) + labels
- [x] Consent checkbox tied to KSA notice + privacy URL
- [x] Widget renders the form before chat when enabled; stored as a lead on the conversation
- [x] Validation (required, email); PII stored encrypted; respects retention/erasure
- [ ] Show decrypted lead in the session detail view (nice-to-have)

## 14. Second shortcode — launch chat from a link/button — ✅
Two ways to embed, sharing one widget instance:
- [x] Shortcode A: auto floating launcher — `<script src="…/widget.js" data-agent="…">`
- [x] Shortcode B: clickable **link/button** opens chat inline (`<a data-support-ai-open>` or `onclick="supportAI.open()"`)
- [x] Global API — `window.supportAI.open()` / `.close()` / `.toggle()`
- [x] Any element is a trigger via `data-support-ai-open` (delegated click handler)
- [x] Hide the floating launcher with `data-launcher="off"`
- [x] Admin embed section documents BOTH options; demo page shows a live trigger

---

### Delivery phases (original plan → current status)
- **Phase 2** — ingestion pipeline (PDF/DOCX/URL/text → chunk → embed via cron) + hybrid retrieval + pre-answer evaluation loop.
  → ingestion ✅ (synchronous; **cron/job_queue path ⬜**); retrieval ✅ vector-only (**hybrid FULLTEXT+vector ⬜**); **pre-answer eval loop ⬜**
- **Phase 3** — long-term memory (summaries + extracted facts).
  → relevant-message recall ✅; **rolling summaries ⬜**, **fact extraction into `memories` ⬜**
- **Phase 4** — offline golden-Q&A eval harness in the admin.
  → DB tables exist (`eval_sets`/`eval_cases`/`eval_runs`/`eval_results`) ✅; **admin UI + runner ⬜**
- **Phase 5** — rate limiting, domain allowlist, key encryption at rest, prompt-injection hardening.
  → rate limiting ✅, domain allowlist ✅, key encryption at rest ✅; **prompt-injection hardening ⬜**

---

### Suggested build order (remaining)
Done so far: RAG (#8), memory recall (#7), history+statuses (#5/#6), startup form (#13),
KSA compliance core (#11), security pass (#10), unit tests (#4).

Next up:
1. **Pre-answer evaluation loop** (Phase 2) — grounded/confidence self-check, one capped retry, human-handoff fallback
2. **Hybrid retrieval** (Phase 2) — FULLTEXT prefilter + vector; optional cheap rerank
3. **Second shortcode: launch-by-link/button (#14)** + `window.supportAI.open()` API
4. **Long-term memory** (Phase 3) — rolling summaries + fact extraction into `memories`
5. **Cron/job_queue ingestion** (Phase 2) — background parse/embed for large files
6. **Offline eval harness UI** (Phase 4, #4-adjacent)
7. **Prompt-injection hardening** (Phase 5, #10)
8. **Web installer + onboarding wizard** (#1, #2); static analysis + CI (#3)
