<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Application\Chat\ChatService;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Http\SseStream;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Infrastructure\Persistence\LeadRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Support\Config;
use SupportAI\Support\RateLimiter;

/**
 * Public chat API consumed by the embedded widget. The main endpoint streams
 * the answer over SSE. CORS is scoped to the agent's domain allowlist so a
 * third party can't embed the widget and burn the budget.
 */
final class ChatController
{
    public function __construct(
        private AgentRepository $agents,
        private ConversationRepository $conversations,
        private ChatService $chat,
        private LeadRepository $leads,
        private SettingsRepository $settings,
        private RateLimiter $rateLimiter,
        private Config $config,
    ) {
    }

    public function message(Request $request): void
    {
        $this->applyCors($request);

        // Rate limit: cap messages per IP per minute (abuse + budget protection).
        if ($this->rateLimiter->tooMany('chat:' . $request->ip(), 30, 60)) {
            Response::error('Too many messages. Please slow down.', 429);
            return;
        }

        $text = trim((string) $request->input('message', ''));
        if ($text === '') {
            Response::error('Message is required.', 422);
            return;
        }
        if (mb_strlen($text) > 4000) {
            Response::error('Message is too long.', 422);
            return;
        }

        $agent = $this->agents->find();
        if ($agent === null || (int) $agent['is_active'] !== 1) {
            Response::error('Assistant is not available.', 503);
            return;
        }

        $visitorId = substr((string) $request->input('visitor_id', ''), 0, 64) ?: 'anon-' . bin2hex(random_bytes(6));
        $conversation = $this->conversations->resolve(
            (int) $agent['id'],
            (string) $request->input('conversation_id', ''),
            $visitorId,
            (string) $request->input('page_url', ''),
        );

        $sse = new SseStream();
        $sse->start();
        $this->chat->streamReply($agent, $conversation, $text, $sse);
    }

    public function feedback(Request $request): void
    {
        $this->applyCors($request);
        // Thumbs up/down is recorded against the message eval JSON in Phase 3.
        Response::json(['ok' => true]);
    }

    /** Startup-form submission → stored as an (encrypted) lead with consent. */
    public function lead(Request $request): void
    {
        $this->applyCors($request);
        if ($this->rateLimiter->tooMany('lead:' . $request->ip(), 10, 60)) {
            Response::error('Too many submissions. Please try again shortly.', 429);
            return;
        }

        $agent = $this->agents->find();
        if ($agent === null) {
            Response::error('Assistant is not available.', 503);
            return;
        }
        $form = $this->settings->startupForm();
        if (empty($form['enabled'])) {
            Response::json(['ok' => true]); // form disabled — nothing to store
            return;
        }

        // Collect only configured, enabled fields.
        $fields = [];
        foreach ($form['fields'] as $f) {
            if (empty($f['enabled'])) {
                continue;
            }
            $val = trim((string) $request->input($f['key'], ''));
            if (!empty($f['required']) && $val === '') {
                Response::error("Field '{$f['label']}' is required.", 422);
                return;
            }
            if ($f['key'] === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                Response::error('Please enter a valid email address.', 422);
                return;
            }
            if ($val !== '') {
                $fields[$f['key']] = mb_substr($val, 0, 300);
            }
        }

        $consent = (bool) $request->input('consent', false);
        if (!empty($form['consent_required']) && !$consent) {
            Response::error('Consent is required to continue.', 422);
            return;
        }

        $visitorId = substr((string) $request->input('visitor_id', ''), 0, 64) ?: 'anon-' . bin2hex(random_bytes(6));
        $conv = $this->conversations->resolve(
            (int) $agent['id'],
            (string) $request->input('conversation_id', ''),
            $visitorId,
            (string) $request->input('page_url', ''),
        );

        $this->leads->create(
            (int) $agent['id'], (int) $conv['id'], $visitorId, $fields,
            $consent, $consent ? (string) ($form['consent_text'] ?? '') : null,
        );

        Response::json(['ok' => true, 'conversation_id' => $conv['public_id']]);
    }

    /**
     * Reflect the Origin only if it is on the configured allowlist. When no
     * domains are configured we fall back to open ('*') for easy setup, but the
     * admin is encouraged to lock this down. Blocking = simply not sending the
     * CORS header, which makes the browser refuse the cross-site request.
     */
    private function applyCors(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return;
        }
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');

        $allowed = $this->allowedDomains();
        if ($allowed === []) {
            header("Access-Control-Allow-Origin: {$origin}"); // open until configured
            return;
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        foreach ($allowed as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                header("Access-Control-Allow-Origin: {$origin}");
                return;
            }
        }
        // Not allowed → no ACAO header; browser blocks the response.
    }

    /** @return string[] configured embed domains (hostnames, lowercased) */
    private function allowedDomains(): array
    {
        $raw = (string) $this->settings->get('allowed_domains', '');
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p === '') {
                continue;
            }
            // Accept full URLs or bare hosts.
            $host = parse_url($p, PHP_URL_HOST) ?: $p;
            $out[] = ltrim($host, '.');
        }
        return array_values(array_unique($out));
    }
}
