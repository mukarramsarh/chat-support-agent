<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\LLM;

/**
 * Turns a raw model id into a short buyer's-guide hint shown in the admin model
 * dropdown (e.g. "cheapest & fastest", "highest accuracy"). Heuristic, based on
 * the family words vendors use — good enough to guide a non-expert choosing a
 * model, without hardcoding a table that goes stale.
 */
final class ModelHint
{
    public static function for(string $modelId): string
    {
        $m = strtolower($modelId);

        // Cheapest / fastest tiers. NOTE: hyphen-prefixed needles for -mini/-lite
        // /-nano so they don't match inside words — e.g. "mini" ⊂ "geMINI".
        if (self::hasAny($m, ['flash-lite', '-lite', '-mini', '-nano', 'haiku', '-8b', 'small'])) {
            return 'cheapest & fastest';
        }
        // Balanced, low-cost tiers.
        if (self::hasAny($m, ['flash', 'turbo', 'fast'])) {
            return 'fast & low cost';
        }
        // Top accuracy tiers.
        if (self::hasAny($m, ['opus', 'ultra', 'pro', 'gpt-4o', 'gpt-4.1', 'gpt-5', 'o1', 'o3', 'o4', 'sonnet', 'large'])) {
            return 'highest accuracy';
        }
        return 'general purpose';
    }

    /**
     * Sort models newest/most-relevant first (best-effort): accuracy tier and
     * higher version numbers bubble up, alphabetical as a tiebreak.
     *
     * @param array<int,array{id:string,hint:string}> $models
     * @return array<int,array{id:string,hint:string}>
     */
    public static function sort(array $models): array
    {
        usort($models, static function ($a, $b) {
            // Higher embedded version number first (e.g. 2.5 before 1.5).
            $va = self::versionScore($a['id']);
            $vb = self::versionScore($b['id']);
            if ($va !== $vb) {
                return $vb <=> $va;
            }
            return strcmp($a['id'], $b['id']);
        });
        return $models;
    }

    private static function versionScore(string $id): float
    {
        return preg_match('/(\d+(?:\.\d+)?)/', $id, $m) ? (float) $m[1] : 0.0;
    }

    /** @param string[] $needles */
    private static function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }
}
