<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use PDO;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Support\Config;
use SupportAI\Support\View;
use Throwable;

/**
 * Zero-shell web installer for shared hosting: pre-flight checks, DB test, schema
 * run, and .env write — all from the browser, no composer/CLI needed. Self-locks
 * once an agent exists so it can't be re-run.
 */
final class InstallController
{
    private View $view;

    public function __construct(private Database $db, private Config $config)
    {
        $this->view = new View(base_path('admin/views'));
    }

    public function show(Request $request): void
    {
        if ($this->isInstalled()) {
            Response::redirect('/admin');
            return;
        }
        $this->render();
    }

    public function run(Request $request): void
    {
        if ($this->isInstalled()) {
            Response::redirect('/admin');
            return;
        }

        $in = fn (string $k, string $d = '') => trim((string) $request->input($k, $d));
        $db = [
            'host' => $in('db_host', '127.0.0.1'),
            'port' => (int) ($in('db_port', '3306') ?: 3306),
            'name' => $in('db_name'),
            'user' => $in('db_user'),
            'pass' => (string) $request->input('db_pass', ''),
        ];
        if ($db['name'] === '' || $db['user'] === '') {
            $this->render('Database name and user are required.', $request->body);
            return;
        }

        // 1) Test the DB connection with the supplied credentials.
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $this->render('Could not connect to the database: ' . $e->getMessage(), $request->body);
            return;
        }

        // 2) Apply schema + seed.
        try {
            $this->runSqlFile($pdo, base_path('database/schema.sql'));
            $this->runSqlFile($pdo, base_path('database/seed.sql'));
            $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('installed_at', NOW())
                        ON DUPLICATE KEY UPDATE `value` = NOW()");
        } catch (Throwable $e) {
            $this->render('Schema installation failed: ' . $e->getMessage(), $request->body);
            return;
        }

        // 3) Write .env (or show it for manual copy if not writable).
        $env = $this->buildEnv($db, $request);
        $envPath = base_path('.env');
        if (@file_put_contents($envPath, $env) === false) {
            $this->render(null, $request->body, $env); // show content to paste manually
            return;
        }

        Response::redirect('/admin');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function isInstalled(): bool
    {
        if (!is_file(base_path('.env'))) {
            return false;
        }
        try {
            return (int) ($this->db->first('SELECT COUNT(*) c FROM agents')['c'] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function render(?string $error = null, array $values = [], ?string $manualEnv = null): void
    {
        Response::html($this->view->render('install', [
            'checks'    => $this->preflight(),
            'error'     => $error,
            'values'    => $values,
            'manualEnv' => $manualEnv,
            'ready'     => $this->ready(),
        ], null));
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private function preflight(): array
    {
        $ext = fn (string $e) => extension_loaded($e);
        return [
            ['label' => 'PHP 8.1+', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION],
            ['label' => 'ext-pdo_mysql', 'ok' => $ext('pdo_mysql'), 'detail' => 'database'],
            ['label' => 'ext-curl', 'ok' => $ext('curl'), 'detail' => 'provider calls'],
            ['label' => 'ext-mbstring', 'ok' => $ext('mbstring'), 'detail' => 'text handling'],
            ['label' => 'ext-json', 'ok' => $ext('json'), 'detail' => 'core'],
            ['label' => 'ext-zip', 'ok' => $ext('zip'), 'detail' => 'DOCX (optional)'],
            ['label' => 'storage/ writable', 'ok' => is_writable(base_path('storage')), 'detail' => 'logs/uploads'],
            ['label' => 'project root writable (.env)', 'ok' => is_writable(base_path()) || is_writable(base_path('.env')), 'detail' => 'config'],
        ];
    }

    private function ready(): bool
    {
        foreach ($this->preflight() as $c) {
            if (!$c['ok'] && !str_contains($c['label'], 'optional') && !str_contains($c['label'], '.env')) {
                return false;
            }
        }
        return true;
    }

    private function buildEnv(array $db, Request $request): string
    {
        $in = fn (string $k, string $d = '') => trim((string) $request->input($k, $d));
        $key = bin2hex(random_bytes(24));
        $lines = [
            'APP_ENV=production', 'APP_DEBUG=false',
            'APP_URL=' . ($in('app_url') ?: 'http://localhost'),
            'APP_KEY=' . $key, 'APP_TIMEZONE=UTC',
            '', 'DB_HOST=' . $db['host'], 'DB_PORT=' . $db['port'], 'DB_NAME=' . $db['name'],
            'DB_USER=' . $db['user'], 'DB_PASS=' . $db['pass'], 'DB_CHARSET=utf8mb4',
            '', 'VECTOR_DRIVER=auto',
            'PINECONE_API_KEY=' . $in('pinecone_key'), 'PINECONE_INDEX_HOST=' . $in('pinecone_host'),
            '', 'GEMINI_API_KEY=' . $in('gemini_key'), 'OPENAI_API_KEY=' . $in('openai_key'),
            'ANTHROPIC_API_KEY=' . $in('anthropic_key'),
            '', 'CHAT_PROVIDER=' . ($in('chat_provider') ?: 'gemini'),
            'CHAT_MODEL=gemini-flash-latest', 'UTILITY_MODEL=gemini-flash-lite-latest',
            'EMBEDDING_PROVIDER=' . ($in('embedding_provider') ?: 'openai'),
            'EMBEDDING_MODEL=text-embedding-3-small',
            'EMBEDDING_DIMENSIONS=' . ($in('embedding_dims') ?: '1536'),
            '', 'MONTHLY_BUDGET_USD=2.00', 'ENABLE_EVAL=true', 'INGEST_ASYNC=false',
        ];
        return implode("\n", $lines) . "\n";
    }

    private function runSqlFile(PDO $pdo, string $path): void
    {
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }
}
