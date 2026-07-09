<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

use SupportAI\Domain\LLM\Message;
use SupportAI\Domain\LLM\Usage;
use SupportAI\Http\SseStream;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Infrastructure\Persistence\MessageRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Support\Config;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Orchestrates a single support turn end-to-end:
 *
 *   budget gate → assemble prompt (persona + knowledge + memory + history)
 *   → stream answer → persist message + usage → emit citations & done.
 *
 * RAG and the eval loop attach through the ContextRetriever seam and (later) an
 * Evaluator, so this class stays small and provider-agnostic.
 */
final class ChatService
{
    public function __construct(
        private ProviderFactory $providers,
        private ContextRetriever $retriever,
        private MemoryService $memory,
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private UsageRepository $usage,
        private Config $config,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $agent          hydrated agent row
     * @param array<string,mixed> $conversation    hydrated conversation row
     */
    public function streamReply(array $agent, array $conversation, string $userText, SseStream $sse): void
    {
        $agentId = (int) $agent['id'];
        $conversationId = (int) $conversation['id'];
        $startedAt = (int) (microtime(true) * 1000);

        // Persist the user's turn immediately so it is never lost.
        $this->messages->addUser($conversationId, $userText);

        // ── Budget gate: hard stop before spending anything over the ceiling ──
        $budget = (float) ($agent['monthly_budget_usd'] ?? $this->config->float('budget.monthly_usd', 2.0));
        if ($this->usage->monthToDateSpend($agentId) >= $budget) {
            $this->logger->warning('Monthly budget reached; declining', ['agent' => $agentId]);
            $this->declineForBudget($agent, $conversationId, $sse);
            return;
        }

        // ── Retrieve knowledge + memory (Phase 0: empty) ──
        $context = $this->retriever->retrieve($agentId, $conversation['visitor_id'] ?? null, $userText);
        $sse->event('meta', [
            'conversation_id' => $conversation['public_id'],
            'citations'       => $context->citations,
        ]);

        // ── Assemble the prompt within budget ──
        $messages = $this->buildMessages($agent, $conversation, $context, $userText);

        $provider = $this->providers->chat(
            $agent['chat_provider'] ?? null,
            $agent['chat_model'] ?: null,
        );
        $options = [
            'model'       => $agent['chat_model'] ?: $this->config->string('llm.chat_model'),
            'temperature' => (float) ($agent['temperature'] ?? 0.3),
            'max_tokens'  => (int) ($agent['max_answer_tokens'] ?? $this->config->int('budget.max_answer_tokens', 800)),
        ];

        // ── Stream tokens to the browser, buffering the full answer for storage ──
        $answer = '';
        try {
            $usage = $provider->streamChat($messages, $options, function (string $delta) use (&$answer, $sse): void {
                $answer .= $delta;
                $sse->token($delta);
            });
        } catch (Throwable $e) {
            $this->logger->error('Chat stream failed', ['error' => $e->getMessage()]);
            $sse->event('error', ['message' => 'The assistant is temporarily unavailable. Please try again.']);
            return;
        }

        if (trim($answer) === '') {
            $answer = (string) $agent['fallback_message'];
            $sse->token($answer);
        }

        // ── Persist + account ──
        $cost = $this->usage->record(
            $provider->name(),
            $options['model'],
            'chat',
            $usage,
            $agentId,
            $conversationId,
        );
        $latency = (int) (microtime(true) * 1000) - $startedAt;
        $this->messages->addAssistant(
            $conversationId, $answer, $options['model'], $usage, $cost,
            $context->citations,
            ['grounded' => $context->hasKnowledge, 'verdict' => 'sent'],
            $latency,
        );
        $this->conversations->touch($conversationId, $cost);

        $sse->event('done', [
            'usage' => [
                'tokens_in'  => $usage->inputTokens,
                'tokens_out' => $usage->outputTokens,
                'cost_usd'   => $cost,
            ],
            'latency_ms' => $latency,
        ]);
    }

    /**
     * Build the message list. Order matters for prompt caching: the stable
     * persona + knowledge go first (marked cacheable), volatile history last.
     *
     * @return Message[]
     */
    private function buildMessages(array $agent, array $conversation, RetrievedContext $context, string $userText): array
    {
        $persona = trim((string) ($agent['persona'] ?? ''));
        $system = $persona !== '' ? $persona : 'You are a helpful, concise support assistant.';

        $guard = "\n\nRules:\n"
            . "- Answer using the KNOWLEDGE below when relevant. If the answer is not there, say you don't know and offer to connect a human.\n"
            . "- Be concise and friendly. Never invent facts, prices, or policies.\n"
            . "- Cite sources inline as [1], [2] matching the KNOWLEDGE items when you use them.";

        $messages = [Message::system($system . $guard, cacheable: true)];

        if ($context->contextBlock !== '') {
            $messages[] = Message::system("KNOWLEDGE:\n" . $context->contextBlock, cacheable: true);
        }

        // Memory: relevant older messages (recall) + recent verbatim turns.
        $memory = $this->memory->build((int) $conversation['id'], $conversation['visitor_id'] ?? null, $userText);

        if ($memory['relevant'] !== []) {
            $recall = "Relevant earlier messages from this user (for context):\n";
            foreach ($memory['relevant'] as $m) {
                $recall .= '- ' . ($m['role'] === 'assistant' ? 'You' : 'User') . ': ' . $m['content'] . "\n";
            }
            $messages[] = Message::system(trim($recall));
        }
        if (!empty($conversation['summary'])) {
            $messages[] = Message::system('Conversation so far (summary): ' . $conversation['summary']);
        }

        // Recent verbatim window. The current user turn was persisted before this
        // call, so it is already the final entry — we must NOT append it again.
        foreach ($memory['recent'] as $turn) {
            $messages[] = $turn['role'] === 'assistant'
                ? Message::assistant($turn['content'])
                : Message::user($turn['content']);
        }

        return $messages;
    }

    private function declineForBudget(array $agent, int $conversationId, SseStream $sse): void
    {
        $msg = (string) $agent['fallback_message'];
        $sse->token($msg);
        $this->messages->addAssistant(
            $conversationId, $msg, 'none', new Usage(), 0.0, [],
            ['verdict' => 'declined', 'reason' => 'budget'],
        );
        $sse->event('done', ['usage' => ['cost_usd' => 0], 'budget_exceeded' => true]);
    }
}
