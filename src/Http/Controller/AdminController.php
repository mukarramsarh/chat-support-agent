<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\Persistence\AdminUserRepository;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Config;
use SupportAI\Support\View;

/**
 * The admin panel controller. Renders server-side views (no build step) and
 * handles auth. On a fresh install with zero admins, the login screen doubles
 * as a one-time "create owner account" form so no CLI step is required.
 */
final class AdminController
{
    private View $view;

    public function __construct(
        private AdminUserRepository $admins,
        private AgentRepository $agents,
        private UsageRepository $usage,
        private VectorStoreFactory $vectors,
        private Database $db,
        private Config $config,
    ) {
        $this->view = new View(base_path('admin/views'));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    // ── Auth ──────────────────────────────────────────────────────────────

    public function loginForm(Request $request): void
    {
        if (!empty($_SESSION['admin_id'])) {
            Response::redirect('/admin');
            return;
        }
        Response::html($this->view->render('login', [
            'firstRun' => $this->admins->count() === 0,
            'error'    => null,
        ], null));
    }

    public function login(Request $request): void
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $firstRun = $this->admins->count() === 0;

        if ($firstRun) {
            // Bootstrap the owner account from the first submitted credentials.
            if (mb_strlen($password) < 8) {
                $this->loginError($firstRun, 'Choose a password of at least 8 characters.');
                return;
            }
            $id = $this->admins->create($email, 'Owner', password_hash($password, PASSWORD_DEFAULT), 'owner');
            $_SESSION['admin_id'] = $id;
            Response::redirect('/admin');
            return;
        }

        $user = $this->admins->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->loginError($firstRun, 'Invalid email or password.');
            return;
        }
        $_SESSION['admin_id'] = (int) $user['id'];
        $this->admins->touchLogin((int) $user['id']);
        Response::redirect('/admin');
    }

    public function logout(Request $request): void
    {
        $_SESSION = [];
        session_destroy();
        Response::redirect('/admin/login');
    }

    // ── Pages ─────────────────────────────────────────────────────────────

    public function dashboard(Request $request): void
    {
        $agent = $this->agents->find();
        $agentId = $agent ? (int) $agent['id'] : null;
        $budget = $agent ? (float) $agent['monthly_budget_usd'] : $this->config->float('budget.monthly_usd', 2.0);
        $spend = $this->usage->monthToDateSpend($agentId);

        $stats = [
            'spend'        => $spend,
            'budget'       => $budget,
            'budget_pct'   => $budget > 0 ? min(100, ($spend / $budget) * 100) : 0,
            'conversations'=> (int) ($this->db->first('SELECT COUNT(*) c FROM conversations')['c'] ?? 0),
            'messages'     => (int) ($this->db->first("SELECT COUNT(*) c FROM messages WHERE role='assistant'")['c'] ?? 0),
            'documents'    => (int) ($this->db->first("SELECT COUNT(*) c FROM documents WHERE status='ready'")['c'] ?? 0),
            'chunks'       => (int) ($this->db->first('SELECT COUNT(*) c FROM chunks')['c'] ?? 0),
            'vector_driver'=> $this->vectors->make()->driver(),
            'daily'        => $this->usage->dailySpend(14),
        ];

        $this->page('dashboard', 'Dashboard', [
            'agent'   => $agent,
            'stats'   => $stats,
            'app_url' => $this->config->string('app.url'),
        ]);
    }

    public function agent(Request $request): void
    {
        $this->page('agent', 'Agent settings', [
            'agent' => $this->agents->findOrFail(),
            'saved' => (bool) $request->input('saved'),
        ]);
    }

    public function saveAgent(Request $request): void
    {
        $agent = $this->agents->findOrFail();
        $theme = [
            'primary'  => (string) $request->input('theme_primary', '#4f46e5'),
            'accent'   => (string) $request->input('theme_accent', '#7c3aed'),
            'position' => $request->input('theme_position') === 'left' ? 'left' : 'right',
            'launcher' => (string) $request->input('theme_launcher', '💬'),
            'subtitle' => (string) $request->input('theme_subtitle', 'Typically replies instantly'),
        ];
        $this->agents->update((int) $agent['id'], [
            'name'              => (string) $request->input('name', $agent['name']),
            'persona'           => (string) $request->input('persona', ''),
            'welcome_message'   => (string) $request->input('welcome_message', ''),
            'fallback_message'  => (string) $request->input('fallback_message', ''),
            'chat_provider'     => (string) $request->input('chat_provider', 'gemini'),
            'chat_model'        => (string) $request->input('chat_model', ''),
            'temperature'       => (float) $request->input('temperature', 0.3),
            'monthly_budget_usd'=> (float) $request->input('monthly_budget_usd', 2.0),
            'theme'             => $theme,
        ]);
        Response::redirect('/admin/agent?saved=1');
    }

    public function knowledge(Request $request): void
    {
        $docs = $this->db->all('SELECT * FROM documents ORDER BY id DESC LIMIT 100');
        $this->page('knowledge', 'Knowledge base', ['documents' => $docs]);
    }

    public function conversations(Request $request): void
    {
        $rows = $this->db->all(
            'SELECT c.*, (SELECT content FROM messages m WHERE m.conversation_id=c.id AND m.role=\'user\' ORDER BY m.id ASC LIMIT 1) AS first_message
               FROM conversations c ORDER BY c.updated_at DESC LIMIT 100'
        );
        $this->page('conversations', 'Conversations', ['conversations' => $rows]);
    }

    public function costs(Request $request): void
    {
        $this->page('costs', 'Cost & usage', [
            'daily'       => $this->usage->dailySpend(30),
            'byOperation' => $this->usage->spendByOperation(30),
            'monthSpend'  => $this->usage->monthToDateSpend(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function page(string $view, string $title, array $data): void
    {
        Response::html($this->view->render($view, $data + [
            'title'  => $title,
            'active' => $view,
        ]));
    }

    private function loginError(bool $firstRun, string $message): void
    {
        Response::html($this->view->render('login', ['firstRun' => $firstRun, 'error' => $message], null));
    }
}
