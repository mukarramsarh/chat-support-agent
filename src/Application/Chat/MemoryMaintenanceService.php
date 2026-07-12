<?php

declare(strict_types=1);

namespace SupportAI\Application\Chat;

use SupportAI\Domain\LLM\Message;
use SupportAI\Infrastructure\LLM\ProviderFactory;
use SupportAI\Infrastructure\Persistence\ConversationRepository;
use SupportAI\Infrastructure\Persistence\MemoryRepository;
use SupportAI\Infrastructure\Persistence\MessageRepository;
use SupportAI\Infrastructure\Persistence\UsageRepository;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Long-term memory maintenance, run from CRON (never on the hot path, so it
 * costs nothing per chat turn and uses the cheap utility model):
 *
 *   • Rolling summary — compresses a long conversation into a few sentences
 *     stored on the conversation, so future turns send a summary instead of the
 *     whole history (smart-token usage).
 *   • Fact extraction — pulls durable facts about the visitor into `memories`,
 *     embedded, so later questions can semantically recall them.
 */
final class MemoryMaintenanceService
{
    private const MIN_MESSAGES = 6;   // don't bother with very short chats
    private const GROWTH = 4;         // re-process after this many new messages

    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private MemoryRepository $memories,
        private ProviderFactory $providers,
        private UsageRepository $usage,
        private Logger $logger,
    ) {
    }

    /** @return array{processed:int,facts:int} */
    public function process(int $limit = 5): array
    {
        $due = $this->conversations->findNeedingMaintenance(self::MIN_MESSAGES, self::GROWTH, $limit);
        $processed = 0;
        $factsAdded = 0;

        foreach ($due as $conv) {
            try {
                $factsAdded += $this->maintain($conv);
                $this->conversations->setMaintainedUpto((int) $conv['id'], (int) $conv['message_count']);
                $processed++;
            } catch (Throwable $e) {
                $this->logger->warning('Memory maintenance failed', ['conv' => $conv['id'], 'error' => $e->getMessage()]);
            }
        }
        return ['processed' => $processed, 'facts' => $factsAdded];
    }

    private function maintain(array $conv): int
    {
        $conversationId = (int) $conv['id'];
        $agentId = (int) $conv['agent_id'];
        $visitorId = $conv['visitor_id'] ?? null;

        $turns = $this->messages->allForConversation($conversationId);
        $transcript = '';
        foreach ($turns as $t) {
            if (!in_array($t['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $transcript .= ($t['role'] === 'assistant' ? 'Assistant: ' : 'User: ') . $t['content'] . "\n";
        }
        if (trim($transcript) === '') {
            return 0;
        }

        $provider = $this->providers->utility();
        $model = $this->providers->utilityModel();

        // 1) Rolling summary.
        try {
            $sum = $provider->complete([
                Message::system('Summarise this customer-support conversation in 2-3 sentences: the user\'s issue, key context, and any resolution. Neutral, factual.'),
                Message::user($transcript),
            ], ['model' => $model, 'temperature' => 0.2, 'max_tokens' => 200]);
            $this->usage->record($provider->name(), $model, 'summarize', $sum->usage, $agentId, $conversationId);
            if (trim($sum->text) !== '') {
                $this->conversations->setSummary($conversationId, trim($sum->text));
            }
        } catch (Throwable $e) {
            $this->logger->warning('Summarize failed', ['conv' => $conversationId, 'error' => $e->getMessage()]);
        }

        // 2) Fact extraction (durable, visitor-scoped).
        return $this->extractFacts($agentId, $visitorId, $conversationId, $transcript, $provider, $model);
    }

    private function extractFacts(int $agentId, ?string $visitorId, int $conversationId, string $transcript, $provider, string $model): int
    {
        try {
            $res = $provider->complete([
                Message::system(
                    'Extract DURABLE facts about the USER from this conversation as a JSON array of short strings '
                    . '(e.g. account plan, preferences, ongoing issues, locale). Only stable facts worth remembering next time — '
                    . 'NOT one-off questions or the assistant\'s replies. Respond with ONLY a JSON array; [] if none.'
                ),
                Message::user($transcript),
            ], ['model' => $model, 'temperature' => 0.1, 'max_tokens' => 300]);
            $this->usage->record($provider->name(), $model, 'memory', $res->usage, $agentId, $conversationId);

            $facts = $this->parseFacts($res->text);
            if ($facts === []) {
                return 0;
            }

            $existing = array_map('mb_strtolower', $this->memories->factTexts($agentId, $visitorId));
            $embedder = $this->providers->embeddings();
            $added = 0;
            foreach ($facts as $fact) {
                $fact = trim($fact);
                if ($fact === '' || in_array(mb_strtolower($fact), $existing, true)) {
                    continue;
                }
                $emb = $embedder->embed([$fact]);
                $this->usage->record($embedder->name(), $embedder->model(), 'embed', $emb['usage'], $agentId, $conversationId);
                $this->memories->addFact($agentId, $visitorId, $conversationId, $fact, $emb['vectors'][0] ?? [], 3);
                $existing[] = mb_strtolower($fact);
                $added++;
            }
            return $added;
        } catch (Throwable $e) {
            $this->logger->warning('Fact extraction failed', ['conv' => $conversationId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** @return string[] */
    private function parseFacts(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($f) => is_string($f) ? $f : (is_array($f) ? (string) ($f['fact'] ?? '') : ''),
            $decoded
        )));
    }
}
