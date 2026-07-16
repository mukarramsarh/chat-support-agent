<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Support\Config;

/**
 * Serves the embeddable widget's runtime config and a demo page. The widget JS
 * itself is a static asset (public/widget.js) that self-configures from its own
 * <script> src, so a single shortcode is all a host site needs.
 */
final class WidgetController
{
    public function __construct(
        private AgentRepository $agents,
        private SettingsRepository $settings,
        private Config $config,
    ) {
    }

    /** Public theme + copy for the widget. Safe to expose (no secrets). */
    public function config(Request $request): void
    {
        $agent = $this->agents->find();
        if ($agent === null) {
            Response::error('No agent configured.', 404);
            return;
        }

        $theme = $agent['theme'] ?: [];
        $form = $this->settings->startupForm();
        $compliance = $this->settings->compliance();

        // Public form config: expose only enabled fields + consent copy. Both
        // languages ship in one payload so the widget can pick per visitor
        // without a second round-trip (and the response stays cacheable).
        $publicForm = null;
        if (!empty($form['enabled'])) {
            $publicForm = [
                'title'            => $form['title'] ?? 'Before we start',
                'title_ar'         => $form['title_ar'] ?? '',
                'subtitle'         => $form['subtitle'] ?? '',
                'subtitle_ar'      => $form['subtitle_ar'] ?? '',
                'consent_required' => (bool) ($form['consent_required'] ?? true),
                'consent_text'     => $form['consent_text'] ?? '',
                'consent_text_ar'  => $form['consent_text_ar'] ?? '',
                'privacy_url'      => $compliance['privacy_url'] ?? '',
                'fields'           => array_values(array_filter(
                    array_map(static fn ($f) => empty($f['enabled']) ? null : [
                        'key'      => $f['key'],
                        'label'    => $f['label'],
                        'label_ar' => $f['label_ar'] ?? '',
                        'required' => (bool) ($f['required'] ?? false),
                    ], $form['fields'] ?? []),
                )),
            ];
        }

        $rtl = (bool) ($compliance['rtl'] ?? false);

        Response::json([
            'agent' => [
                'public_id'          => $agent['public_id'],
                'name'               => $agent['name'],
                'welcome_message'    => $agent['welcome_message'],
                // Arabic twins of the agent's copy. They live in the theme JSON
                // because agents has no Arabic columns and shared hosting has no
                // CLI to run a migration — see the note in AdminController.
                'name_ar'            => $theme['name_ar'] ?? '',
                'welcome_message_ar' => $theme['welcome_ar'] ?? '',
                'theme'              => [
                    'primary'     => $theme['primary'] ?? '#4f46e5',
                    'accent'      => $theme['accent'] ?? '#7c3aed',
                    'position'    => $theme['position'] ?? 'right',
                    'avatar'      => $theme['avatar'] ?? null,
                    'launcher'    => $theme['launcher'] ?? '💬',
                    'title'       => $theme['title'] ?? $agent['name'],
                    'title_ar'    => $theme['title_ar'] ?? ($theme['name_ar'] ?? ''),
                    'subtitle'    => $theme['subtitle'] ?? 'Typically replies instantly',
                    'subtitle_ar' => $theme['subtitle_ar'] ?? 'يرد عادةً خلال لحظات',
                ],
            ],
            'startup_form' => $publicForm,
            'rtl'          => $rtl,
            // Used only when the host page and browser give no language signal.
            'default_lang' => $rtl ? 'ar' : 'en',
            'api_base'     => $this->config->string('app.url'),
        ], 200, ['Access-Control-Allow-Origin' => '*']);
    }

    /** Fallback dynamic serve of the widget script (static file normally wins). */
    public function script(Request $request): void
    {
        $path = dirname(__DIR__, 3) . '/public/widget.js';
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo is_readable($path) ? file_get_contents($path) : '/* widget.js missing */';
    }

    /**
     * A self-contained page to preview the widget during development.
     *
     * ?lang=ar flips the page's own lang/dir rather than configuring the widget:
     * that is exactly the signal a real bilingual site gives, so what you see
     * here is what a visitor on the Arabic side of the site gets.
     */
    public function demo(Request $request): void
    {
        $base = e($this->config->string('app.url'));
        $agent = $this->agents->find();
        $publicId = e($agent['public_id'] ?? '');

        $ar = strtolower((string) $request->input('lang', '')) === 'ar';
        $lang = $ar ? 'ar' : 'en';
        $dir = $ar ? 'rtl' : 'ltr';
        $demoUrl = u('/demo');

        $title = $ar ? 'معاينة أداة الدعم' : 'Support widget preview';
        $intro = $ar
            ? 'تُضمِّن هذه الصفحة الأداة تماماً كما يفعل موقع العميل، باستخدام الكود المختصر أدناه.'
            : "This page embeds the widget exactly as a customer's site would, using the shortcode below.";
        $hint = $ar
            ? 'اضغط على الأيقونة في الزاوية لبدء المحادثة، أو استخدم هذا الزر:'
            : 'Click the launcher in the corner to start chatting, or use this inline trigger:';
        $cta = $ar ? '💬 تحدّث معنا' : '💬 Chat with us';
        $note = $ar
            ? 'اللغة مأخوذة من سمة <code>lang</code> في الصفحة — لا إعداد إضافي.'
            : 'Language follows the page\'s <code>lang</code> attribute — nothing to configure.';
        $enOn = $ar ? '' : 'background:#4f46e5;color:#fff;border-color:#4f46e5';
        $arOn = $ar ? 'background:#4f46e5;color:#fff;border-color:#4f46e5' : '';

        Response::html(<<<HTML
        <!doctype html><html lang="{$lang}" dir="{$dir}"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Widget demo · support-ai</title>
        <style>
          body{font:16px/1.6 system-ui,sans-serif;margin:0;color:#0f172a;
               background:linear-gradient(135deg,#eef2ff,#faf5ff);min-height:100vh}
          .wrap{max-width:720px;margin:0 auto;padding:80px 24px}
          h1{font-size:2rem;margin:0 0 .5rem}p{color:#475569}
          code{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px}
          .langbar{display:flex;gap:8px;margin-bottom:28px}
          .langbar a{display:inline-block;padding:6px 16px;border:1px solid #cbd5e1;border-radius:999px;
                     background:#fff;color:#475569;text-decoration:none;font-size:14px;font-weight:600}
        </style></head><body>
          <div class="wrap">
            <div class="langbar">
              <a href="{$demoUrl}?lang=en" style="{$enOn}">English</a>
              <a href="{$demoUrl}?lang=ar" style="{$arOn}">العربية</a>
            </div>
            <h1>{$title}</h1>
            <p>{$intro}</p>
            <p><code>&lt;script src="{$base}/widget.js" data-agent="{$publicId}" defer&gt;&lt;/script&gt;</code></p>
            <p>{$hint}</p>
            <p><a href="#" data-support-ai-open style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:600">{$cta}</a></p>
            <p style="font-size:14px">{$note}</p>
          </div>
          <script src="{$base}/widget.js" data-agent="{$publicId}" defer></script>
        </body></html>
        HTML);
    }
}
