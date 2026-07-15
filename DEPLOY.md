# Deploying to cPanel (shared hosting)

Target example: **`https://staging-dev.procurementhub.sa/chatbot`** — the app in a
`/chatbot` sub-folder of the `staging-dev` subdomain. No Docker or Node needed —
pure PHP + MySQL.

> The chatbot backend can live anywhere; your website just loads a `<script>` from
> it. Hosting it under `/chatbot` works because the app supports `APP_BASE_PATH`.

## 0. Prerequisites
- cPanel with **PHP 8.1+**, and the extensions: `pdo_mysql`, `curl`, `mbstring`, `json`, `zip`.
- A way to run one CLI command (either **cPanel → Terminal**, or a one-off **Cron Job**).
- Your API keys: **Gemini** (chat) + **OpenAI** (embeddings). Anthropic & Pinecone optional.

## 1. Upload the code
Put the app in a `chatbot` folder inside the `staging-dev` subdomain's document root, e.g.
`~/staging-dev.procurementhub.sa/chatbot/`.

- **Terminal:** `git clone https://github.com/mukarramsarh/chat-support-agent.git chatbot`
- **or** download the repo ZIP and upload/extract via **File Manager**.

The included root `.htaccess` forwards requests into `public/`, so you do NOT need to
change the subdomain's document root.

## 2. Install dependencies
- **Terminal:** `cd chatbot && composer install --no-dev --optimize-autoloader`
- **No composer?** Run `composer install` locally and upload the resulting `vendor/` folder.

## 3. Create the database
cPanel → **MySQL Databases** → create a database + user, give the user all privileges.
Note the DB name, user, and password.

## 4. Run the web installer
Open **`https://staging-dev.procurementhub.sa/chatbot/install`** and fill in:
- **Database:** host `localhost`, the DB name / user / password from step 3.
- **Public URL:** `https://staging-dev.procurementhub.sa/chatbot`
- **Sub-directory path:** `/chatbot`
- **API keys:** Gemini + OpenAI (paste your keys).
- **Embedding dimensions:** `1536` (default) — or `1024` if you use an existing 1024-dim Pinecone index.

Leave Pinecone blank to keep all vector data in your own MySQL (recommended for KSA data
residency). The installer tests the DB, runs the schema, writes `.env`, and self-locks.

If it can't write `.env` (permissions), it shows the file contents — create `.env` in the
`chatbot/` root with that text, then continue.

> Data residency tip: after install, set `VECTOR_DRIVER=php` in `.env` so embeddings are
> stored as vectors in your MySQL (nothing goes to Pinecone). Chat + embedding text still
> go to Google/OpenAI — keep PII redaction ON (see step 7).

## 5. Permissions
Ensure `chatbot/storage/` is writable (`755` or `775`). The installer's pre-flight page
flags this.

## 6. Seed the ProcurementHub data + context
Run once (this creates the bilingual persona, 20 knowledge docs, and the eval set):
- **Terminal:** `php bin/console demo`
- **or** cPanel → **Cron Jobs** → add a one-off job `php /home/USER/staging-dev.procurementhub.sa/chatbot/bin/console demo`, let it run once, then delete it.

## 7. Configure in the admin
Open **`/chatbot/admin`** and create the owner account (first visit).
- **Agent** page → *Allowed embed domains*: add `procurementhub.sa` and `staging-dev.procurementhub.sa`.
- **Privacy & form** page → enable **RTL (Arabic)** if you want Arabic-first chrome; keep
  **PII redaction** ON; set a **retention** period if desired.
- Sidebar language switcher: **EN / العربية**.

## 8. Recurring cron (recrawl + memory + retention)
cPanel → **Cron Jobs** → every 5 minutes:
```
php /home/USER/staging-dev.procurementhub.sa/chatbot/bin/console cron
```
No shell cron? Set `CRON_TOKEN=somesecret` in `.env` and schedule:
```
curl -s "https://staging-dev.procurementhub.sa/chatbot/cron.php?token=somesecret"
```

## 9. Embed the widget on your website
Copy the exact snippet from the **Agent** page (it has your agent id). It looks like:
```html
<script src="https://staging-dev.procurementhub.sa/chatbot/widget.js"
  data-agent="YOUR_AGENT_PUBLIC_ID" defer></script>
```
Or open chat from your own button:
```html
<script src="https://staging-dev.procurementhub.sa/chatbot/widget.js"
  data-agent="YOUR_AGENT_PUBLIC_ID" data-launcher="off" defer></script>
<a href="#" data-support-ai-open>Chat with us</a>
```

## Checklist
- [ ] HTTPS/AutoSSL active on `staging-dev.procurementhub.sa`
- [ ] `/chatbot/install` completed & self-locked
- [ ] `php bin/console demo` seeded (20 docs, eval set)
- [ ] Owner account created; allowed domains set
- [ ] Cron scheduled
- [ ] Widget embedded and tested in EN + AR
- [ ] DPO review of cross-border processing (Gemini/OpenAI) — see item 11 in checklist.md
