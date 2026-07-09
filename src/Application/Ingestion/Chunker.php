<?php

declare(strict_types=1);

namespace SupportAI\Application\Ingestion;

/**
 * Splits extracted text into retrievable chunks. Strategy: pack whole
 * paragraphs up to a target size (so we don't cut mid-sentence), with a small
 * overlap between consecutive chunks to preserve context across boundaries.
 *
 * Sizes are in characters (~4 chars ≈ 1 token), which keeps this dependency-free.
 */
final class Chunker
{
    public function __construct(
        private int $targetChars = 1200,   // ~300 tokens
        private int $overlapChars = 150,
    ) {
    }

    /**
     * @return array<int,array{content:string,tokens:int}>
     */
    public function chunk(string $text): array
    {
        $text = $this->normalise($text);
        if ($text === '') {
            return [];
        }

        // Split into paragraphs first; fall back to sentences for huge blocks.
        $paras = preg_split('/\n{2,}/', $text) ?: [$text];

        $chunks = [];
        $buffer = '';
        foreach ($paras as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            // A single oversized paragraph is hard-split by sentence.
            if (mb_strlen($para) > $this->targetChars) {
                foreach ($this->splitLong($para) as $piece) {
                    $buffer = $this->append($chunks, $buffer, $piece);
                }
                continue;
            }
            $buffer = $this->append($chunks, $buffer, $para);
        }
        if (trim($buffer) !== '') {
            $chunks[] = $this->make($buffer);
        }
        return $chunks;
    }

    /** Add $piece to the buffer, flushing a chunk (with overlap) when full. */
    private function append(array &$chunks, string $buffer, string $piece): string
    {
        $candidate = $buffer === '' ? $piece : $buffer . "\n\n" . $piece;
        if (mb_strlen($candidate) <= $this->targetChars) {
            return $candidate;
        }
        if ($buffer !== '') {
            $chunks[] = $this->make($buffer);
            $tail = mb_substr($buffer, -$this->overlapChars);
            return trim($tail) . "\n\n" . $piece;
        }
        return $piece;
    }

    /** @return string[] */
    private function splitLong(string $para): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $para) ?: [$para];
        $out = [];
        $buf = '';
        foreach ($sentences as $s) {
            if (mb_strlen($buf . ' ' . $s) > $this->targetChars && $buf !== '') {
                $out[] = $buf;
                $buf = $s;
            } else {
                $buf = $buf === '' ? $s : $buf . ' ' . $s;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    private function make(string $content): array
    {
        $content = trim($content);
        return ['content' => $content, 'tokens' => (int) ceil(mb_strlen($content) / 4)];
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
