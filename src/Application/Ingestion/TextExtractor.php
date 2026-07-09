<?php

declare(strict_types=1);

namespace SupportAI\Application\Ingestion;

use RuntimeException;
use SupportAI\Support\Http\HttpClient;
use Throwable;

/**
 * Pulls plain text out of a knowledge source. One method per source type, each
 * returning normalised text plus a best-effort title. Scanned/image PDFs yield
 * little/no text — we detect that and raise a clear error rather than ingesting
 * an empty document (OCR is out of scope for pure PHP).
 */
final class TextExtractor
{
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * @param array{content?:string,title?:string,url?:string,path?:string} $source
     * @return array{text:string,title:string,meta:array<string,mixed>}
     */
    public function extract(string $type, array $source): array
    {
        return match ($type) {
            'text' => [
                'text'  => trim($source['content'] ?? ''),
                'title' => $source['title'] ?? 'Pasted text',
                'meta'  => [],
            ],
            'url'  => $this->fromUrl($source['url'] ?? ''),
            'pdf'  => $this->fromPdf($source['path'] ?? '', $source['title'] ?? ''),
            'docx' => $this->fromDocx($source['path'] ?? '', $source['title'] ?? ''),
            default => throw new RuntimeException("Unsupported source type: {$type}"),
        };
    }

    private function fromUrl(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Please enter a valid URL.');
        }
        $res = $this->http->request('GET', $url, ['User-Agent' => 'support-ai-bot/1.0']);
        $res->throwIfError('Fetch URL');
        $html = $res->body;

        // Prefer Readability for clean article text; fall back to tag-stripping.
        $title = $url;
        $text = '';
        try {
            $config = new \fivefilters\Readability\Configuration(['fixRelativeURLs' => true, 'originalURL' => $url]);
            $readability = new \fivefilters\Readability\Readability($config);
            $readability->parse($html);
            $title = $readability->getTitle() ?: $url;
            $text = trim(html_entity_decode(strip_tags($readability->getContent() ?? '')));
        } catch (Throwable) {
            // Fallback: strip scripts/styles then tags.
            $stripped = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
            if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
                $title = trim(html_entity_decode($m[1]));
            }
            $text = trim(html_entity_decode(strip_tags($stripped)));
        }

        if (mb_strlen($text) < 20) {
            throw new RuntimeException('Could not extract readable text from that URL.');
        }
        return ['text' => $text, 'title' => $title, 'meta' => ['source_url' => $url]];
    }

    private function fromPdf(string $path, string $title): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Uploaded PDF could not be read.');
        }

        // Extraction order is chosen for SHARED HOSTING (see below):
        //   1) smalot/pdfparser — PURE PHP, the guaranteed baseline everywhere.
        //   2) poppler pdftotext — only if the binary + proc_open are available
        //      (dev/VPS); a nice enhancement, never a dependency. On shared
        //      hosting proc_open is usually disabled, so we simply never reach it.
        $pages = 0;
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
            $pages = count($pdf->getPages());
        } catch (Throwable) {
            $text = '';
        }

        // If pure PHP got little/nothing, try pdftotext where it exists.
        if (mb_strlen(trim($text)) < 20) {
            $viaBinary = $this->pdftotext($path);
            if (mb_strlen(trim($viaBinary)) >= 20) {
                $text = $viaBinary;
            }
        }

        if (mb_strlen(trim($text)) < 20) {
            throw new RuntimeException('No extractable text found in this PDF. If it is a scanned/image PDF, it needs OCR (not supported); a normal digital PDF should work.');
        }
        return ['text' => $text, 'title' => $title ?: 'PDF document', 'meta' => $pages ? ['pages' => $pages] : []];
    }

    /**
     * OPTIONAL enhancement: poppler's pdftotext. Returns '' whenever it isn't
     * usable — which on typical shared hosting is always, because proc_open is
     * commonly listed in disable_functions. Never throws; the caller has already
     * tried the pure-PHP path.
     */
    private function pdftotext(string $path): string
    {
        if (!self::procOpenAvailable()) {
            return '';
        }
        try {
            $cmd = 'pdftotext -q -enc UTF-8 -layout ' . escapeshellarg($path) . ' -';
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                return '';
            }
            $out = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $out;
        } catch (Throwable) {
            return '';
        }
    }

    /** True only if proc_open exists AND isn't in disable_functions. */
    private static function procOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    private function fromDocx(string $path, string $title): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Uploaded DOCX could not be read.');
        }
        // DOCX is a zip; word/document.xml holds the body. Reading it directly
        // is more robust (and lighter) than a full document-model load.
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Uploaded file is not a valid DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('DOCX is missing its document body.');
        }

        // Paragraph and break tags become newlines; strip the rest.
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml) ?? $xml;
        $text = trim(html_entity_decode(strip_tags($xml)));

        if (mb_strlen($text) < 20) {
            throw new RuntimeException('This DOCX has no extractable text.');
        }
        return ['text' => $text, 'title' => $title ?: 'Word document', 'meta' => []];
    }
}
