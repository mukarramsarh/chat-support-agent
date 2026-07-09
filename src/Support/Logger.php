<?php

declare(strict_types=1);

namespace SupportAI\Support;

/**
 * Dead-simple append-only file logger. No PSR-3 dependency; shared hosting
 * rarely gives you a real log aggregator, so a rotating file is the pragmatic
 * choice. Rotation is size-based and lazy.
 */
final class Logger
{
    public function __construct(
        private string $file,
        private int $maxBytes = 5_242_880, // 5 MB
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_file($this->file) && filesize($this->file) > $this->maxBytes) {
            @rename($this->file, $this->file . '.' . date('YmdHis'));
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
