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

## 4. Unit tests — ⬜
- [ ] PHPUnit set up (dev-only dependency)
- [ ] Pure-logic units first: Chunker, VectorCodec (pack/cosine), Pricing, ModelHint, Config, Env, Crypto
- [ ] Provider adapters via mocked HttpClient
- [ ] RAG retriever + eval gate with fakes
- [ ] Target meaningful coverage on Domain/Application layers

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
- [ ] Hybrid retrieval (FULLTEXT prefilter + vector) on the MySQL/PHP path
- [ ] Optional rerank (cheap model) when scores are ambiguous
- [ ] Pre-answer evaluation loop (grounded/confidence self-check, one capped retry, human-handoff fallback)
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
- [ ] CSRF tokens on all admin/state-changing forms
- [ ] Rate limiting on public chat (schema exists — enforce it)
- [ ] Widget domain allowlist enforced in CORS (currently open `*`)
- [ ] Prompt-injection hardening for ingested content + tool boundaries
- [ ] Security headers (CSP for admin), password policy, brute-force lockout
- [ ] Upload validation (MIME sniff, size, extension) — partial

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

---

### Suggested build order
1. **RAG flow (#8)** ← in progress (makes knowledge actually work) + memory (#7) + smart tokens (#12)
2. Conversation history + statuses (#5, #6)
3. Startup form (#13) + its consent (part of #11)
4. Security pass (#10) + KSA compliance core (#11: consent, redaction, erasure, retention, RTL)
5. Unit tests (#4) + standards/CI (#3)
6. Web installer + onboarding (#1, #2)
