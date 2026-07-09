# support-ai

A generic, production-grade AI **support agent** you can deploy standalone and embed on any website with a single `<script>` shortcode. PHP + MySQL, no Node build step — it runs on plain shared hosting. Talks to **Gemini / OpenAI / Anthropic**, does **RAG** over your docs, manages **memory**, and is built to stay cheap (target ~$2/month) via a budget-aware evaluation loop.

> Status: **Phase 0–1 complete** — clean architecture, full schema, provider + vector layers, streaming chat, embeddable widget, and the admin panel. RAG ingestion, the pre-answer eval loop, and long-term memory are the next phases (seams already in place).

---

## Why the design looks like this

`PHP + shared hosting + no node_modules` is the dominant constraint. Everything follows from it:

- **Providers do the heavy lifting** (LLM + embeddings) over HTTP — no local models.
- **Streaming is SSE**, not WebSockets, because SSE works on plain HTTP.
- **Vectors have a three-tier ladder**, auto-selected per host:

  ```
  MySQL 9 native VECTOR   →   Pinecone (if key configured)   →   PHP cosine (always works)
  ```

- **Ingestion uses a cron-drained job queue**, not a worker daemon.
- **One shortcode**, isolated in a Shadow DOM so it never fights the host site's CSS.

## Architecture (clean / layered)

```
src/
├─ Domain/            # contracts + DTOs (no framework, no I/O)
│  ├─ LLM/            # LLMProvider, EmbeddingProvider, Message, Usage, Completion
│  └─ Vector/         # VectorStore, VectorMatch
├─ Application/       # use-cases / orchestration
│  └─ Chat/           # ChatService, ContextRetriever seam (RAG plugs in here)
├─ Infrastructure/    # adapters (the outside world)
│  ├─ LLM/            # Gemini/OpenAI/Anthropic clients, embeddings, Pricing, factory
│  ├─ Vector/         # MySQL9 / Pinecone / PHP-cosine stores + capability factory
│  ├─ Persistence/    # PDO repositories
│  └─ Database/       # connection + capability probes
├─ Http/              # Request/Response/Router/SSE, controllers, middleware
└─ Support/           # Config, Container (DI), Crypto, Logger, View, helpers
```

The whole app depends on the `LLMProvider` / `VectorStore` **interfaces**, so swapping providers or vector backends is a factory decision, never a code change.

## Run it locally (Docker)

```bash
cp .env.example .env          # then add your provider keys (or set them in docker-compose.yml)
docker compose up -d --build
docker compose exec app php bin/console install     # create schema + seed the agent
# open http://localhost:8080/admin  → first visit creates the owner account
```

- Admin panel: `http://localhost:8080/admin`
- Live widget preview: `http://localhost:8080/demo`
- Check which vector tier your host picked: `docker compose exec app php bin/console vector:info`

## Deploy on shared hosting

1. Upload the project; point the domain's document root at **`/public`** (a root `.htaccess` redirects into `public/` if you can't).
2. `composer install --no-dev --optimize-autoloader` (or upload `vendor/`).
3. Create a MySQL database, fill `.env`, then run `php bin/console install`.
4. Add a cron job: `* * * * * php /path/to/bin/console cron` (drives ingestion — Phase 2).
5. Visit `/admin`, configure the agent, and copy the embed snippet.

## Embed on any site

```html
<script src="https://your-host/widget.js" data-agent="AGENT_PUBLIC_ID" defer></script>
```

## Cost control (the ~$2/month target)

- Default chat model **Gemini Flash**; utility tasks on the cheapest model.
- **Budget gate**: when the month's spend hits the ceiling, the agent politely declines and offers a human hand-off instead of spending more.
- Prompt caching on the stable persona + knowledge; per-call usage recorded in `usage_log` and shown on the cost dashboard.
- Coming next: retrieve-don't-stuff RAG, single-call self-eval, and an answer cache.

## Roadmap

- **Phase 2** — ingestion pipeline (PDF/DOCX/URL/text → chunk → embed via cron) + hybrid retrieval + the pre-answer evaluation loop.
- **Phase 3** — long-term memory (summaries + extracted facts).
- **Phase 4** — offline golden-Q&A eval harness in the admin.
- **Phase 5** — rate limiting, domain allowlist, key encryption at rest, prompt-injection hardening.

## License

MIT.
