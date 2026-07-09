<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

use SupportAI\Domain\LLM\Usage;

/**
 * Per-model pricing in USD per 1,000,000 tokens.
 *
 * ⚠ These are ESTIMATES for cost accounting and the budget guardrail, not
 * billing. Verify against each provider's current price sheet and adjust here
 * (or override per-model in the admin settings). Matching is prefix-based so
 * versioned model ids ("gemini-2.5-flash-preview-xx") resolve to their family.
 */
final class Pricing
{
    /** @var array<string,array{in:float,out:float,cached_in?:float}> */
    private array $table;

    public function __construct(?array $overrides = null)
    {
        $this->table = $overrides ?? [
            // ── Gemini (default) ──
            'gemini-flash-lite' => ['in' => 0.10, 'out' => 0.40, 'cached_in' => 0.025],
            'gemini-flash'      => ['in' => 0.30, 'out' => 2.50, 'cached_in' => 0.075],
            'gemini-pro'        => ['in' => 1.25, 'out' => 10.00],
            // ── OpenAI ──
            'gpt-4o-mini'          => ['in' => 0.15, 'out' => 0.60, 'cached_in' => 0.075],
            'gpt-4o'               => ['in' => 2.50, 'out' => 10.00, 'cached_in' => 1.25],
            'text-embedding-3-small' => ['in' => 0.02, 'out' => 0.0],
            'text-embedding-3-large' => ['in' => 0.13, 'out' => 0.0],
            // ── Anthropic ──
            'claude-haiku'  => ['in' => 1.00, 'out' => 5.00, 'cached_in' => 0.10],
            'claude-sonnet' => ['in' => 3.00, 'out' => 15.00, 'cached_in' => 0.30],
            // ── Gemini embeddings ──
            'text-embedding-004' => ['in' => 0.0, 'out' => 0.0],
        ];
    }

    public function costOf(string $model, Usage $usage): float
    {
        $rate = $this->rateFor($model);
        $cachedIn = $usage->cachedInputTokens;
        $freshIn = max(0, $usage->inputTokens - $cachedIn);

        $cost = ($freshIn / 1_000_000) * $rate['in']
              + ($usage->outputTokens / 1_000_000) * $rate['out']
              + ($cachedIn / 1_000_000) * ($rate['cached_in'] ?? $rate['in']);

        return round($cost, 6);
    }

    /** @return array{in:float,out:float,cached_in?:float} */
    private function rateFor(string $model): array
    {
        $model = strtolower($model);
        // Longest matching prefix wins.
        $best = null;
        $bestLen = -1;
        foreach ($this->table as $prefix => $rate) {
            if (str_contains($model, $prefix) && strlen($prefix) > $bestLen) {
                $best = $rate;
                $bestLen = strlen($prefix);
            }
        }
        return $best ?? ['in' => 0.0, 'out' => 0.0];
    }
}
