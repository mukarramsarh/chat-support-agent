<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Domain\LLM\Usage;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\LLM\Pricing;

/**
 * Records every billable call and answers the budget/dashboard queries. This is
 * the single source of truth for "how much have we spent this month".
 */
final class UsageRepository
{
    public function __construct(
        private Database $db,
        private Pricing $pricing,
    ) {
    }

    public function record(
        string $provider,
        string $model,
        string $operation,
        Usage $usage,
        ?int $agentId = null,
        ?int $conversationId = null,
        bool $cached = false,
    ): float {
        $cost = $this->pricing->costOf($model, $usage);
        $this->db->run(
            'INSERT INTO usage_log
                (agent_id, conversation_id, provider, model, operation, tokens_in, tokens_out, cost_usd, cached, usage_day)
             VALUES
                (:aid, :cid, :prov, :model, :op, :ti, :to, :cost, :cached, CURDATE())',
            [
                'aid' => $agentId, 'cid' => $conversationId, 'prov' => $provider,
                'model' => $model, 'op' => $operation,
                'ti' => $usage->inputTokens, 'to' => $usage->outputTokens,
                'cost' => $cost, 'cached' => $cached ? 1 : 0,
            ]
        );
        return $cost;
    }

    public function monthToDateSpend(?int $agentId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(cost_usd), 0) AS spend FROM usage_log
                 WHERE usage_day >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')';
        $params = [];
        if ($agentId !== null) {
            $sql .= ' AND agent_id = :aid';
            $params['aid'] = $agentId;
        }
        return (float) ($this->db->first($sql, $params)['spend'] ?? 0);
    }

    /** @return array<int,array{usage_day:string,cost:float,calls:int}> daily spend for a window */
    public function dailySpend(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->all(
            'SELECT usage_day, SUM(cost_usd) AS cost, COUNT(*) AS calls
               FROM usage_log
              WHERE usage_day >= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)
           GROUP BY usage_day ORDER BY usage_day ASC'
        );
    }

    /** @return array<int,array{operation:string,cost:float,calls:int}> */
    public function spendByOperation(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->all(
            'SELECT operation, SUM(cost_usd) AS cost, COUNT(*) AS calls
               FROM usage_log
              WHERE usage_day >= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)
           GROUP BY operation ORDER BY cost DESC'
        );
    }
}
