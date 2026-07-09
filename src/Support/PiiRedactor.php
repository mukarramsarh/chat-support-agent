<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Redacts personal data from free text before it is sent to an EXTERNAL LLM
 * (OpenAI/Anthropic/Gemini/Pinecone are outside KSA). Under Saudi PDPL,
 * cross-border transfer of personal data is restricted; redacting reduces what
 * leaves the Kingdom while keeping the message useful for support.
 *
 * Pattern-based and conservative — it favours over-redaction. Enable/disable via
 * the compliance settings. This is a mitigation, not a substitute for lawful
 * basis + consent.
 */
final class PiiRedactor
{
    /** @var array<string,string> label => regex */
    private const PATTERNS = [
        // Email addresses.
        '[EMAIL]'  => '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
        // IBAN (incl. Saudi SA..).
        '[IBAN]'   => '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
        // Credit-card-like 13-19 digit runs (allowing spaces/dashes).
        '[CARD]'   => '/\b(?:\d[ \-]?){13,19}\b/',
        // Saudi national id / iqama: 10 digits starting 1 or 2.
        '[ID]'     => '/\b[12]\d{9}\b/',
        // Phone numbers (Saudi +9665.., 05.., and generic international).
        '[PHONE]'  => '/(?:\+?966|00966|0)?5\d{8}\b|\+\d{7,15}\b/',
    ];

    /**
     * @return array{text:string,redacted:bool,counts:array<string,int>}
     */
    public function redact(string $text): array
    {
        $counts = [];
        $out = $text;
        foreach (self::PATTERNS as $label => $pattern) {
            $out = preg_replace_callback($pattern, function () use ($label, &$counts) {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
                return $label;
            }, $out) ?? $out;
        }
        return ['text' => $out, 'redacted' => $counts !== [], 'counts' => $counts];
    }

    /** Convenience: just the redacted string. */
    public function clean(string $text): string
    {
        return $this->redact($text)['text'];
    }
}
