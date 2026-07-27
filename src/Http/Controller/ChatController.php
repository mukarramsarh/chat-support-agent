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
use SupportAI\Support\Throttle;
use SupportAI\Support\ValidationException;
use SupportAI\Support\Validator;

/**
 * Public chat API consumed by the embedded widget. The main endpoint streams
 * the answer over SSE. Three layers protect the token budget here: strict input
 * validation, the layered Throttle (per-IP + per-visitor + global with
 * exponential backoff), and — inside ChatService — the daily/monthly spend
 * circuit breaker. CORS is scoped to the agent's domain allowlist too, but note
 * CORS only stops browsers; the throttle is what stops a scripted bot.
 */
final class ChatController
{
    /** Hard cap on a single visitor message (also bounds input tokens). */
    private const MAX_MESSAGE_CHARS = 2000;

    public function __construct(
        private AgentRepository $agents,
        private ConversationRepository $conversations,
        private ChatService $chat,
        private LeadRepository $leads,
        private SettingsRepository $settings,
        private Throttle $throttle,
    ) {
    }

    public function message(Request $request): void
    {
        $this->applyCors($request);

        // Strict validation first (reject, don't sanitize). visitor_id is needed
        // for the throttle key, so it is validated up front inside the guard too.
        try {
            $visitorId = Validator::optionalId($request->input('visitor_id'), 'Session', 64)
                ?: 'anon-' . bin2hex(random_bytes(6));
            $text = Validator::string($request->input('message'), 'Message', 1, self::MAX_MESSAGE_CHARS);
            $conversationId = Validator::optionalId($request->input('conversation_id'), 'Conversation', 64);
            $pageUrl = Validator::optionalString($request->input('page_url'), 'Page URL', 2048);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

        // Layered abuse gate — the primary token-burn defence. Bots ignore CORS,
        // so this (not the CORS header) is what actually caps the spend.
        $decision = $this->throttle->chat($request->ip(), $visitorId);
        if (!$decision['allowed']) {
            Response::error('Too many messages. Please slow down.', 429, [
                'retry_after' => $decision['retryAfter'],
            ], ['Retry-After' => (string) $decision['retryAfter']]);
            return;
        }

        $agent = $this->agents->find();
        if ($agent === null || (int) $agent['is_active'] !== 1) {
            Response::error('Assistant is not available.', 503);
            return;
        }

        $conversation = $this->conversations->resolve(
            (int) $agent['id'],
            $conversationId,
            $visitorId,
            $pageUrl,
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
        $decision = $this->throttle->lead($request->ip());
        if (!$decision['allowed']) {
            Response::error('Too many submissions. Please try again shortly.', 429, [
                'retry_after' => $decision['retryAfter'],
            ], ['Retry-After' => (string) $decision['retryAfter']]);
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

        // Answer validation errors in the visitor's own language, like the rest
        // of the widget. The widget reports the language it rendered in.
        $ar = strtolower((string) $request->input('lang', '')) === 'ar';

        // Collect only configured, enabled fields.
        $fields = [];
        foreach ($form['fields'] as $f) {
            if (empty($f['enabled'])) {
                continue;
            }
            $val = trim((string) $request->input($f['key'], ''));
            if (!empty($f['required']) && $val === '') {
                $label = $ar ? (($f['label_ar'] ?? '') ?: $f['label']) : $f['label'];
                Response::error($ar ? "الحقل «{$label}» مطلوب." : "Field '{$label}' is required.", 422);
                return;
            }
            if ($f['key'] === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                Response::error($ar ? 'يرجى إدخال بريد إلكتروني صحيح.' : 'Please enter a valid email address.', 422);
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

        try {
            $visitorId = Validator::optionalId($request->input('visitor_id'), 'Session', 64)
                ?: 'anon-' . bin2hex(random_bytes(6));
            $conv = $this->conversations->resolve(
                (int) $agent['id'],
                Validator::optionalId($request->input('conversation_id'), 'Conversation', 64),
                $visitorId,
                Validator::optionalString($request->input('page_url'), 'Page URL', 2048),
            );
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 422);
            return;
        }

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
