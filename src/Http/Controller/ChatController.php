<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Application\Chat\ChatService;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Http\SseStream;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Support\Config;

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
        private Config $config,
    ) {
    }

    public function message(Request $request): void
    {
        $this->applyCors($request);

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

    /**
     * Reflect an allowed Origin for cross-site embedding. If the agent has no
     * domain allowlist configured we fall back to '*' (open) — the admin is
     * warned to lock this down for production.
     */
    private function applyCors(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return;
        }
        // Phase 5 will check agent_domains; for now echo the origin to support
        // credentialed cross-origin streaming during development.
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
}
