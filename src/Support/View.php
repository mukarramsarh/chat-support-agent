<?php

declare(strict_types=1);

namespace SupportAI\Support;

use RuntimeException;

/**
 * Minimal PHP-template renderer. A view file is plain PHP; its captured output
 * is injected into a layout as $content. No template engine, no compile step —
 * ideal for shared hosting.
 */
final class View
{
    public function __construct(private string $viewPath)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->capture($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $view, array $data): string
    {
        $file = $this->viewPath . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
