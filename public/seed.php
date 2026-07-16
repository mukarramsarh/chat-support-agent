<?php

declare(strict_types=1);

/**
 * Web-runnable seeder — for shared hosting with no CLI/terminal.
 *
 *   https://your-host/chatbot/seed.php            (while logged into /admin)
 *   https://your-host/chatbot/seed.php?token=XXX  (SEED_TOKEN from .env)
 *
 * Seeds the ProcurementHub configuration: bilingual master prompt, knowledge
 * base (company + Saudi procurement/local-content context), and the eval set.
 *
 * ⚠ This REPLACES the existing knowledge base for the agent, so it is guarded by
 * an admin session or a secret token. Delete this file (or clear SEED_TOKEN)
 * once you are done if you want to be extra safe.
 */

use SupportAI\Application\Demo\DemoSeeder;
use SupportAI\Support\Container;
use SupportAI\Support\Env;

/** @var Container $c */
$c = require dirname(__DIR__) . '/bootstrap.php';

// ── Auth: an active admin session OR the SEED_TOKEN ─────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$loggedIn = !empty($_SESSION['admin_id']);
$expected = (string) Env::get('SEED_TOKEN', '');
$given = (string) ($_GET['token'] ?? '');
$tokenOk = $expected !== '' && hash_equals($expected, $given);

if (!$loggedIn && !$tokenOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n\n";
    echo "Either sign in at /admin first, then reload this page,\n";
    echo "or append ?token=YOUR_SEED_TOKEN (set SEED_TOKEN in .env).\n";
    return;
}

// Embedding every document takes a while on shared hosting — give it room.
@set_time_limit(0);
@ini_set('max_execution_time', '0');

header('Content-Type: text/plain; charset=utf-8');
while (ob_get_level() > 0) {
    ob_end_flush();
}

echo "Seeding ProcurementHub data — this can take a minute (each document is embedded)...\n\n";
@flush();

try {
    $result = $c->get(DemoSeeder::class)->seed();
    echo "✓ Done.\n";
    echo "  Knowledge documents: {$result['docs']}\n";
    echo "  Eval set id:         {$result['eval_set']}\n\n";
    echo "Next: open /admin/knowledge to see the sources, /admin/evals to run the test set,\n";
    echo "and /demo to chat (try Arabic and English).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "✗ Seeding failed: " . $e->getMessage() . "\n\n";
    echo "Common causes: missing/invalid OPENAI_API_KEY (embeddings) or GEMINI_API_KEY in .env,\n";
    echo "or the database not being reachable. Check storage/logs/app.log for details.\n";
}
