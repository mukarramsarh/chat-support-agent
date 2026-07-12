<?php

declare(strict_types=1);

namespace SupportAI\Application\Eval;

use SupportAI\Application\Chat\ChatService;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\EvalRepository;
use Throwable;

/**
 * Offline golden-Q&A eval harness. Admin-triggered, so cost is occasional. Each
 * case is run through the SAME retrieval + evaluation pipeline as production
 * (ChatService::answerFor), then scored deterministically (no extra LLM judge —
 * budget-friendly): keyword coverage + groundedness + retrieval hit.
 */
final class EvalRunner
{
    public function __construct(
        private EvalRepository $evals,
        private AgentRepository $agents,
        private ChatService $chat,
    ) {
    }

    /** @return array{run_id:int,avg:float,hit:float,grounded:float,cases:int} */
    public function run(int $setId): array
    {
        $agent = $this->agents->findOrFail();
        $cases = $this->evals->cases($setId);
        $runId = $this->evals->createRun($setId);

        $scoreSum = 0.0;
        $hitSum = 0.0;
        $groundedSum = 0.0;
        $n = 0;

        foreach ($cases as $case) {
            $n++;
            try {
                $res = $this->chat->answerFor($agent, (string) $case['question']);
            } catch (Throwable $e) {
                $this->evals->addResult($runId, (int) $case['id'], 'ERROR: ' . $e->getMessage(), 0.0, false, false, 'generation failed');
                continue;
            }

            $must = $case['must_include'] ? (json_decode((string) $case['must_include'], true) ?: []) : [];
            [$score, $notes] = $this->score($res, $must);

            $scoreSum += $score;
            $hitSum += $res['retrieved'] ? 1 : 0;
            $groundedSum += $res['grounded'] ? 1 : 0;

            $this->evals->addResult(
                $runId, (int) $case['id'], $res['answer'], $score,
                $res['grounded'], $res['retrieved'], $notes
            );
        }

        $avg = $n ? $scoreSum / $n : 0.0;
        $hit = $n ? $hitSum / $n : 0.0;
        $grounded = $n ? $groundedSum / $n : 0.0;
        $this->evals->finishRun($runId, $avg, $hit, $grounded, 0.0);

        return ['run_id' => $runId, 'avg' => $avg, 'hit' => $hit, 'grounded' => $grounded, 'cases' => $n];
    }

    /**
     * Deterministic score in [0,1].
     * @param array{answer:string,grounded:bool,answered:bool,retrieved:bool} $res
     * @param string[] $must
     * @return array{0:float,1:string}
     */
    private function score(array $res, array $must): array
    {
        $answerLower = mb_strtolower($res['answer']);

        if ($must !== []) {
            $present = 0;
            foreach ($must as $term) {
                if ($term !== '' && str_contains($answerLower, mb_strtolower((string) $term))) {
                    $present++;
                }
            }
            $coverage = $present / count($must);
            $score = 0.4 * ($res['grounded'] ? 1 : 0) + 0.6 * $coverage;
            return [round($score, 3), "keywords {$present}/" . count($must) . ($res['grounded'] ? ', grounded' : '')];
        }

        // No keywords supplied → judge on grounded + answered.
        if ($res['grounded'] && $res['answered']) {
            return [0.9, 'grounded + answered'];
        }
        if ($res['answered']) {
            return [0.5, 'answered, not grounded'];
        }
        return [0.2, 'declined / no answer'];
    }
}
