<?php

declare(strict_types=1);

namespace SupportAI\Infrastructure\Persistence;

use SupportAI\Infrastructure\Database\Database;

/**
 * Key/value app settings (embedding lock, kb_version, install flag, …).
 * Values are stored as strings; callers cast as needed.
 */
final class SettingsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->first('SELECT `value` FROM settings WHERE `key` = :k', ['k' => $key]);
        return $row['value'] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['k' => $key, 'v' => $value]
        );
    }

    /** @return array<string,mixed> */
    public function getJson(string $key, array $default = []): array
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /** @param array<string,mixed> $value */
    public function setJson(string $key, array $value): void
    {
        $this->set($key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Startup lead-form config (with safe defaults).
     *
     * Every visitor-facing string has an Arabic twin under the `_ar` suffix; the
     * widget picks one per visitor language. Arabic is shipped by default so an
     * Arabic visitor never falls back to English copy.
     *
     * @return array<string,mixed>
     */
    public function startupForm(): array
    {
        return $this->getJson('startup_form', [
            'enabled'          => false,
            'title'            => 'Before we start',
            'title_ar'         => 'قبل أن نبدأ',
            'subtitle'         => 'Tell us a little about you.',
            'subtitle_ar'      => 'أخبرنا قليلاً عنك.',
            'fields'           => [
                ['key' => 'name',    'label' => 'Name',    'label_ar' => 'الاسم',   'enabled' => true,  'required' => true],
                ['key' => 'email',   'label' => 'Email',   'label_ar' => 'البريد الإلكتروني', 'enabled' => true,  'required' => true],
                ['key' => 'phone',   'label' => 'Phone',   'label_ar' => 'رقم الجوال', 'enabled' => false, 'required' => false],
                ['key' => 'company', 'label' => 'Company', 'label_ar' => 'الشركة',  'enabled' => false, 'required' => false],
            ],
            'consent_required' => true,
            'consent_text'     => 'I agree to the processing of my personal data to receive support, in line with the privacy policy.',
            'consent_text_ar'  => 'أوافق على معالجة بياناتي الشخصية لتقديم الدعم، وفقاً لسياسة الخصوصية.',
        ]);
    }

    /** Compliance / privacy config. @return array<string,mixed> */
    public function compliance(): array
    {
        return $this->getJson('compliance', [
            'pii_redaction'  => true,   // redact PII before external LLM (PDPL cross-border)
            'retention_days' => 0,      // 0 = keep indefinitely
            'rtl'            => false,  // Arabic / right-to-left widget
            'privacy_url'    => '',
        ]);
    }

    public function kbVersion(): int
    {
        return (int) ($this->get('kb_version', '1'));
    }

    /** Bumped on any ingest so the answer cache invalidates automatically. */
    public function bumpKbVersion(): void
    {
        $this->set('kb_version', (string) ($this->kbVersion() + 1));
    }
}
