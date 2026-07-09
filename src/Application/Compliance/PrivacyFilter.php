<?php

declare(strict_types=1);

namespace SupportAI\Application\Compliance;

use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Support\PiiRedactor;

/**
 * Single gate for "text about to leave for an external provider". When PII
 * redaction is enabled (compliance setting), personal data is stripped before
 * it is embedded or sent to the chat model. Originals are still stored locally
 * (on the KSA host) for the admin transcript — only the outbound copy is cleaned.
 *
 * The enabled flag is read once and cached for the request.
 */
final class PrivacyFilter
{
    private ?bool $enabled = null;

    public function __construct(
        private SettingsRepository $settings,
        private PiiRedactor $redactor,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled ??= (bool) ($this->settings->compliance()['pii_redaction'] ?? true);
    }

    /** Redact $text if outbound redaction is on; otherwise return it unchanged. */
    public function outbound(string $text): string
    {
        return $this->enabled() ? $this->redactor->clean($text) : $text;
    }
}
