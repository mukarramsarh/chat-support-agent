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

    /**
     * Resolve a rate by FAMILY KEYWORDS, not contiguous prefix — real model ids
     * carry version numbers ("gemini-2.5-flash", "claude-sonnet-5") that break
     * substring matching. Unknown models get a conservative non-zero default so
     * the budget guardrail still accrues spend rather than silently reading $0.
     *
     * @return array{in:float,out:float,cached_in?:float}
     */
    private function rateFor(string $model): array
    {
        $m = strtolower($model);
        $has = static fn (string ...$needles): bool => array_reduce(
            $needles, static fn (bool $c, string $x) => $c || str_contains($m, $x), false
        );

        // Embeddings.
        if ($has('embedding')) {
            return $this->table[$has('large') ? 'text-embedding-3-large' : 'text-embedding-3-small'];
        }
        // Anthropic families.
        if ($has('opus'))   { return ['in' => 15.00, 'out' => 75.00, 'cached_in' => 1.50]; }
        if ($has('sonnet')) { return $this->table['claude-sonnet']; }
        if ($has('haiku'))  { return $this->table['claude-haiku']; }
        // Gemini families.
        if ($has('gemini', 'gemma', 'flash')) {
            if ($has('lite'))  { return $this->table['gemini-flash-lite']; }
            if ($has('flash')) { return $this->table['gemini-flash']; }
            if ($has('pro'))   { return $this->table['gemini-pro']; }
            return $this->table['gemini-flash'];
        }
        // OpenAI families.
        if ($has('gpt') || preg_match('/\bo\d/', $m)) {
            return $has('mini', 'nano') ? $this->table['gpt-4o-mini'] : $this->table['gpt-4o'];
        }
        // Unknown → conservative estimate (never zero).
        return ['in' => 0.50, 'out' => 1.50];
    }
}
