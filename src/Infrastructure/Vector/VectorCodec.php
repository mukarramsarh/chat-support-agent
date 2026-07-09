<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Vector;

/**
 * Encoding + math for vectors on the portable path.
 *
 *  • pack/unpack: little-endian float32 ('g'), 4 bytes/dim — 4× smaller than
 *    JSON and fast to (de)serialise. This is what lands in the LONGBLOB column.
 *  • cosine: assumes callers may pass un-normalised vectors, so it divides by
 *    magnitudes. If you normalise at ingest you can switch to a plain dot product.
 */
final class VectorCodec
{
    /** @param float[] $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return float[] */
    public static function unpack(string $blob): array
    {
        if ($blob === '') {
            return [];
        }
        /** @var array<int,float> $vals */
        $vals = unpack('g*', $blob) ?: [];
        return array_values($vals);
    }

    /** JSON array form for native VECTOR columns / Pinecone payloads. */
    public static function toJson(array $vector): string
    {
        return '[' . implode(',', array_map(static fn ($f) => rtrim(sprintf('%.7f', $f), '0'), $vector)) . ']';
    }

    /**
     * Cosine similarity in the range [-1, 1]; returns 0 for degenerate vectors.
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
