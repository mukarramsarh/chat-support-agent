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

        // Public form config: expose only enabled fields + consent copy.
        $publicForm = null;
        if (!empty($form['enabled'])) {
            $publicForm = [
                'title'            => $form['title'] ?? 'Before we start',
                'subtitle'         => $form['subtitle'] ?? '',
                'consent_required' => (bool) ($form['consent_required'] ?? true),
                'consent_text'     => $form['consent_text'] ?? '',
                'privacy_url'      => $compliance['privacy_url'] ?? '',
                'fields'           => array_values(array_filter(
                    array_map(static fn ($f) => empty($f['enabled']) ? null : [
                        'key' => $f['key'], 'label' => $f['label'], 'required' => (bool) ($f['required'] ?? false),
                    ], $form['fields'] ?? []),
                )),
            ];
        }

        Response::json([
            'agent' => [
                'public_id'       => $agent['public_id'],
                'name'            => $agent['name'],
                'welcome_message' => $agent['welcome_message'],
                'theme'           => [
                    'primary'   => $theme['primary'] ?? '#4f46e5',
                    'accent'    => $theme['accent'] ?? '#7c3aed',
                    'position'  => $theme['position'] ?? 'right',
                    'avatar'    => $theme['avatar'] ?? null,
                    'launcher'  => $theme['launcher'] ?? '💬',
                    'title'     => $theme['title'] ?? $agent['name'],
                    'subtitle'  => $theme['subtitle'] ?? 'Typically replies instantly',
                ],
            ],
            'startup_form' => $publicForm,
            'rtl'          => (bool) ($compliance['rtl'] ?? false),
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

    /** A self-contained page to preview the widget during development. */
    public function demo(Request $request): void
    {
        $base = e($this->config->string('app.url'));
        $agent = $this->agents->find();
        $publicId = e($agent['public_id'] ?? '');

        Response::html(<<<HTML
        <!doctype html><html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Widget demo · support-ai</title>
        <style>
          body{font:16px/1.6 system-ui,sans-serif;margin:0;color:#0f172a;
               background:linear-gradient(135deg,#eef2ff,#faf5ff);min-height:100vh}
          .wrap{max-width:720px;margin:0 auto;padding:80px 24px}
          h1{font-size:2rem;margin:0 0 .5rem}p{color:#475569}
          code{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px}
        </style></head><body>
          <div class="wrap">
            <h1>Support widget preview</h1>
            <p>This page embeds the widget exactly as a customer's site would, using the shortcode below.</p>
            <p><code>&lt;script src="{$base}/widget.js" data-agent="{$publicId}" defer&gt;&lt;/script&gt;</code></p>
            <p>Click the launcher in the corner to start chatting, or use this inline trigger:</p>
            <p><a href="#" data-support-ai-open style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:600">💬 Chat with us</a></p>
          </div>
          <script src="{$base}/widget.js" data-agent="{$publicId}" defer></script>
        </body></html>
        HTML);
    }
}
