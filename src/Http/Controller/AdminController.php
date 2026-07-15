<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\Persistence\AdminUserRepository;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Application\Compliance\ComplianceService;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Infrastructure\Persistence\MessageRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Config;
use SupportAI\Support\Lang;
use SupportAI\Support\RateLimiter;
use SupportAI\Support\View;
use Throwable;

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
        private ProviderFactory $providers,
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private SettingsRepository $settings,
        private ComplianceService $compliance,
        private RateLimiter $rateLimiter,
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
            Response::redirect(u('/admin'));
            return;
        }
        Response::html($this->view->render('login', [
            'firstRun' => $this->admins->count() === 0,
            'error'    => null,
        ], null));
    }

    public function login(Request $request): void
    {
        // Brute-force lockout: max 8 failed attempts per IP per 15 minutes.
        $lockKey = 'login:' . $request->ip();
        if ($this->rateLimiter->current($lockKey, 900) > 8) {
            $this->loginError($this->admins->count() === 0, 'Too many attempts. Please wait 15 minutes and try again.');
            return;
        }

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
            Response::redirect(u('/admin'));
            return;
        }

        $user = $this->admins->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->rateLimiter->tooMany($lockKey, 8, 900); // count this failed attempt
            $this->loginError($firstRun, 'Invalid email or password.');
            return;
        }
        $this->rateLimiter->clear($lockKey, 900);
        $_SESSION['admin_id'] = (int) $user['id'];
        $this->admins->touchLogin((int) $user['id']);
        Response::redirect(u('/admin'));
    }

    public function logout(Request $request): void
    {
        $_SESSION = [];
        session_destroy();
        Response::redirect(u('/admin/login'));
    }

    /** Switch admin language (en|ar) and return to the previous page. */
    public function setLocale(Request $request): void
    {
        Lang::setLocale((string) $request->input('lang', 'en'));
        $ref = (string) $request->header('referer', '');
        // Only follow same-app admin paths to avoid open-redirects.
        $back = (str_contains($ref, '/admin') ? parse_url($ref, PHP_URL_PATH) : null) ?: u('/admin');
        Response::redirect($back);
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
            'needs_attention' => (int) ($this->db->first("SELECT COUNT(*) c FROM conversations WHERE status='needs_attention'")['c'] ?? 0),
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
            'agent'           => $this->agents->findOrFail(),
            'saved'           => (bool) $request->input('saved'),
            'app_url'         => $this->config->string('app.url'),
            'allowed_domains' => (string) $this->settings->get('allowed_domains', ''),
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
        $this->settings->set('allowed_domains', trim((string) $request->input('allowed_domains', '')));
        Response::redirect(u('/admin/agent?saved=1'));
    }

    /** JSON: live chat models for the chosen provider (drives the model dropdown). */
    public function models(Request $request): void
    {
        $provider = (string) $request->input('provider', 'gemini');
        if (!in_array($provider, ['gemini', 'openai', 'anthropic'], true)) {
            Response::json(['models' => [], 'error' => 'Unknown provider']);
            return;
        }
        try {
            Response::json(['provider' => $provider, 'models' => $this->providers->listModels($provider)]);
        } catch (Throwable $e) {
            // Missing/invalid key or network — return empty list + reason so the
            // UI can fall back to a free-text entry instead of breaking.
            Response::json(['models' => [], 'error' => $e->getMessage()]);
        }
    }

    public function knowledge(Request $request): void
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $docs = $this->db->all('SELECT * FROM documents ORDER BY id DESC LIMIT 100');
        $this->page('knowledge', 'Knowledge base', [
            'documents'    => $docs,
            'flash'        => $flash,
            'lockedModel'  => $this->db->first("SELECT `value` FROM settings WHERE `key`='embedding_locked_model'")['value'] ?? null,
        ]);
    }

    public function conversations(Request $request): void
    {
        $filter = (string) $request->input('status', '');
        $where = '';
        $params = [];
        if (in_array($filter, ConversationRepository::STATUSES, true)) {
            $where = 'WHERE c.status = :s';
            $params['s'] = $filter;
        }
        $rows = $this->db->all(
            "SELECT c.*, (SELECT content FROM messages m WHERE m.conversation_id=c.id AND m.role='user' ORDER BY m.id ASC LIMIT 1) AS first_message
               FROM conversations c {$where} ORDER BY c.updated_at DESC LIMIT 100",
            $params
        );
        $counts = [];
        foreach ($this->db->all('SELECT status, COUNT(*) c FROM conversations GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $this->page('conversations', 'Conversations', [
            'conversations' => $rows,
            'counts'        => $counts,
            'filter'        => $filter,
        ]);
    }

    public function conversationDetail(Request $request, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $conversation = $this->conversations->findById($id);
        if ($conversation === null) {
            Response::redirect(u('/admin/conversations'));
            return;
        }
        $this->page('conversation_detail', 'Session', [
            'conversation' => $conversation,
            'messages'     => $this->messages->allForConversation($id),
            'statuses'     => ConversationRepository::STATUSES,
        ]);
    }

    public function updateConversationStatus(Request $request, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->conversations->setStatus($id, (string) $request->input('status', ''));
        Response::redirect(u('/admin/conversations/' . $id));
    }

    /** Download one session's transcript + metadata as JSON. */
    public function exportConversation(Request $request, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $conversation = $this->conversations->findById($id);
        if ($conversation === null) {
            Response::redirect(u('/admin/conversations'));
            return;
        }
        $data = [
            'conversation' => [
                'id' => $conversation['public_id'], 'visitor_id' => $conversation['visitor_id'],
                'status' => $conversation['status'], 'created_at' => $conversation['created_at'],
                'summary' => $conversation['summary'], 'total_cost_usd' => $conversation['total_cost_usd'],
            ],
            'messages' => array_map(static fn ($m) => [
                'role' => $m['role'], 'content' => $m['content'], 'model' => $m['model'],
                'cost_usd' => $m['cost_usd'], 'eval' => $m['eval'] ? json_decode((string) $m['eval'], true) : null,
                'created_at' => $m['created_at'],
            ], $this->messages->allForConversation($id)),
        ];
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="session-' . $id . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function costs(Request $request): void
    {
        $this->page('costs', 'Cost & usage', [
            'daily'       => $this->usage->dailySpend(30),
            'byOperation' => $this->usage->spendByOperation(30),
            'monthSpend'  => $this->usage->monthToDateSpend(),
        ]);
    }

    // ── Privacy & compliance (KSA PDPL) ─────────────────────────────────────

    public function privacy(Request $request): void
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $this->page('privacy', 'Privacy & startup form', [
            'form'       => $this->settings->startupForm(),
            'compliance' => $this->settings->compliance(),
            'flash'      => $flash,
        ]);
    }

    public function savePrivacy(Request $request): void
    {
        // Rebuild the startup-form config from the posted controls.
        $fieldKeys = ['name', 'email', 'phone', 'company'];
        $fields = [];
        foreach ($fieldKeys as $k) {
            $fields[] = [
                'key'      => $k,
                'label'    => (string) $request->input("label_{$k}", ucfirst($k)),
                'enabled'  => (bool) $request->input("enabled_{$k}", false),
                'required' => (bool) $request->input("required_{$k}", false),
            ];
        }
        $this->settings->setJson('startup_form', [
            'enabled'          => (bool) $request->input('form_enabled', false),
            'title'            => (string) $request->input('form_title', 'Before we start'),
            'subtitle'         => (string) $request->input('form_subtitle', ''),
            'fields'           => $fields,
            'consent_required' => (bool) $request->input('consent_required', false),
            'consent_text'     => (string) $request->input('consent_text', ''),
        ]);
        $this->settings->setJson('compliance', [
            'pii_redaction'  => (bool) $request->input('pii_redaction', false),
            'retention_days' => max(0, (int) $request->input('retention_days', 0)),
            'rtl'            => (bool) $request->input('rtl', false),
            'privacy_url'    => (string) $request->input('privacy_url', ''),
        ]);
        $_SESSION['flash'] = ['type' => 'ok', 'message' => 'Privacy settings saved.'];
        Response::redirect(u('/admin/privacy'));
    }

    public function eraseVisitor(Request $request): void
    {
        $vid = trim((string) $request->input('visitor_id', ''));
        if ($vid !== '') {
            $counts = $this->compliance->erase($vid, (int) ($_SESSION['admin_id'] ?? 0), $request->ip());
            $_SESSION['flash'] = ['type' => 'ok', 'message' => "Erased data for {$vid}: " . json_encode($counts)];
        }
        Response::redirect(u('/admin/privacy'));
    }

    public function exportVisitor(Request $request): void
    {
        $vid = trim((string) $request->input('visitor_id', ''));
        if ($vid === '') {
            Response::redirect(u('/admin/privacy'));
            return;
        }
        $data = $this->compliance->export($vid, (int) ($_SESSION['admin_id'] ?? 0), $request->ip());
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="export-' . preg_replace('/[^a-z0-9\-]/i', '_', $vid) . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
