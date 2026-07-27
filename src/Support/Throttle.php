<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * The abuse policy for the public chat API — the first line of defence for the
 * token budget. Every LLM-triggering request is gated on several keys at once so
 * no single trick (one hot IP, one visitor id, or a botnet of many IPs aimed at
 * one target) can run up the AI bill:
 *
 *   - global ceiling  — caps total traffic across ALL visitors (botnet backstop)
 *   - per-IP          — the common scripted-abuse case
 *   - per-visitor     — a burst from one embedded session
 *   - per-visitor/day — a slow drip that stays under the minute limits
 *
 * Offenders get EXPONENTIAL BACKOFF (a growing Retry-After) rather than a hard
 * lockout, so a genuine user who refreshes too fast recovers on their own while
 * a hammering bot is pushed further and further out. The daily spend circuit
 * breaker in ChatService is the final backstop if all of this is evaded.
 *
 * Backed by the rate_limits table (no Redis) so it runs on plain shared hosting.
 */
final class Throttle
{
    /** @var array{0:string,1:int,2:int}[]  [keyPrefix, max, windowSeconds] */
    private const CHAT_RULES = [
        ['chat:global', 240, 60],   // whole app: 240 msgs/min
        ['chat:ip',      30, 60],   // per IP:   30 msgs/min
        ['chat:vis',     20, 60],   // per visitor: 20 msgs/min
        ['chat:visday', 500, 86400], // per visitor: 500 msgs/day
    ];

    /** Lead-form submissions are cheaper to store but still worth bounding. */
    private const LEAD_RULES = [
        ['lead:global', 120, 60],
        ['lead:ip',      10, 60],
    ];

    private const BACKOFF_CAP = 300; // seconds — never wait more than 5 min

    public function __construct(private RateLimiter $limiter)
    {
    }

    /**
     * @return array{allowed:bool,retryAfter:int,reason:string}
     */
    public function chat(string $ip, string $visitorId): array
    {
        return $this->apply(self::CHAT_RULES, ['ip' => $ip, 'vis' => $visitorId, 'visday' => $visitorId, 'global' => '_'], $ip);
    }

    /**
     * @return array{allowed:bool,retryAfter:int,reason:string}
     */
    public function lead(string $ip): array
    {
        return $this->apply(self::LEAD_RULES, ['ip' => $ip, 'global' => '_'], $ip);
    }

    /**
     * @param array{0:string,1:int,2:int}[] $rules
     * @param array<string,string> $identity  suffix per rule scope
     * @return array{allowed:bool,retryAfter:int,reason:string}
     */
    private function apply(array $rules, array $identity, string $backoffId): array
    {
        foreach ($rules as [$prefix, $max, $window]) {
            $scope = substr($prefix, strpos($prefix, ':') + 1);
            $suffix = $identity[$scope] ?? '_';
            if ($this->limiter->tooMany($prefix . ':' . $suffix, $max, $window)) {
                return $this->deny($backoffId, $scope);
            }
        }
        return ['allowed' => true, 'retryAfter' => 0, 'reason' => ''];
    }

    /**
     * @return array{allowed:bool,retryAfter:int,reason:string}
     */
    private function deny(string $backoffId, string $reason): array
    {
        // Each breach within the hour raises the penalty: 2,4,8,…,capped.
        $penalty = $this->limiter->hit('backoff:' . $backoffId, 3600);
        $retryAfter = (int) min(self::BACKOFF_CAP, 2 ** min($penalty, 8));

        return ['allowed' => false, 'retryAfter' => max(2, $retryAfter), 'reason' => $reason];
    }
}
