<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Application\Ingestion\IngestionService;
use SupportAI\Application\Ingestion\RecrawlService;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\ChunkRepository;
use SupportAI\Infrastructure\Persistence\DocumentRepository;
use SupportAI\Infrastructure\Persistence\JobQueueRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Config;
use Throwable;

/**
 * Admin endpoints for building the knowledge base: paste text, add a URL, or
 * upload a PDF/DOCX. Ingestion runs synchronously and the result is flashed back
 * to the knowledge page. All routes are session-guarded by the admin middleware.
 */
final class DocumentController
{
    private const MAX_UPLOAD_BYTES = 10_485_760; // 10 MB
    private const ALLOWED = ['pdf' => 'pdf', 'docx' => 'docx'];

    /** Human-friendly recrawl interval options → minutes. */
    private const INTERVALS = [
        'off' => 0, 'hourly' => 60, 'daily' => 1440, 'weekly' => 10080, 'monthly' => 43200,
    ];

    public function __construct(
        private IngestionService $ingestion,
        private RecrawlService $recrawl,
        private AgentRepository $agents,
        private DocumentRepository $documents,
        private ChunkRepository $chunks,
        private VectorStoreFactory $vectors,
        private JobQueueRepository $jobs,
        private Config $config,
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function addText(Request $request): void
    {
        $agentId = (int) $this->agents->findOrFail()['id'];
        $title = trim((string) $request->input('title', '')) ?: 'Pasted text';
        $content = trim((string) $request->input('content', ''));

        if (mb_strlen($content) < 20) {
            $this->finish('error', 'Please paste at least a sentence or two of text.');
            return;
        }
        $this->run(fn () => $this->ingestion->ingest($agentId, 'text', ['title' => $title, 'content' => $content]));
    }

    public function addUrl(Request $request): void
    {
        $agentId = (int) $this->agents->findOrFail()['id'];

        // Accept one OR many URLs (one per line / comma) and an optional recrawl schedule.
        $raw = (string) $request->input('url', '');
        $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: [])));
        $urls = array_slice(array_unique($urls), 0, 20); // cap per request (sync ingest)
        if ($urls === []) {
            $this->finish('error', 'Please enter at least one URL.');
            return;
        }
        $minutes = self::INTERVALS[(string) $request->input('refresh', 'off')] ?? 0;

        // Background path (opt-in): queue each URL for the cron worker.
        if ($this->config->bool('app.ingest_async', false)) {
            foreach ($urls as $url) {
                $this->jobs->enqueue('ingest.url', ['agent_id' => $agentId, 'url' => $url, 'refresh_minutes' => $minutes]);
            }
            $this->finish('ok', 'Queued ' . count($urls) . ' URL(s) for background processing (runs on the next cron tick).');
            return;
        }

        $added = 0;
        $errors = [];
        foreach ($urls as $url) {
            try {
                $result = $this->ingestion->ingest($agentId, 'url', ['url' => $url]);
                if ($minutes > 0) {
                    $this->documents->setRefreshSchedule($result['document_id'], $minutes);
                }
                $added++;
            } catch (Throwable $e) {
                $errors[] = parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage();
            }
        }

        $msg = "Added {$added} of " . count($urls) . ' URL(s)' . ($minutes > 0 ? ' with auto-refresh' : '') . '.';
        if ($errors !== []) {
            $msg .= ' Skipped: ' . implode('; ', array_slice($errors, 0, 3));
        }
        $this->finish($added > 0 ? 'ok' : 'error', $msg);
    }

    /** Admin "Refresh now" for a URL source. */
    public function refresh(Request $request): void
    {
        $id = (int) $request->input('id', 0);
        try {
            $r = $this->recrawl->refreshOne($id);
            $this->finish('ok', $r['status'] === 'updated'
                ? "Refreshed “{$r['title']}” — re-indexed {$r['chunks']} chunks."
                : "“{$r['title']}” checked — no changes since last fetch.");
        } catch (Throwable $e) {
            $this->finish('error', $e->getMessage());
        }
    }

    public function upload(Request $request): void
    {
        $agentId = (int) $this->agents->findOrFail()['id'];
        $file = $request->files['file'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->finish('error', 'No file was uploaded (or it exceeded the size limit).');
            return;
        }
        if (($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            $this->finish('error', 'File is too large (max 10 MB).');
            return;
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $type = self::ALLOWED[$ext] ?? null;
        if ($type === null) {
            $this->finish('error', 'Only PDF and DOCX files are supported.');
            return;
        }

        // Move to our storage dir before parsing.
        $dir = base_path('storage/uploads');
        @mkdir($dir, 0775, true);
        $dest = $dir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // Fallback for non-HTTP-upload contexts (rare); copy if needed.
            if (!@rename($file['tmp_name'], $dest)) {
                $this->finish('error', 'Could not store the uploaded file.');
                return;
            }
        }

        // Content-based MIME check (defends against a renamed .exe etc.).
        if (!$this->mimeMatches($dest, $type)) {
            @unlink($dest);
            $this->finish('error', 'The file content does not look like a valid ' . strtoupper($type) . '.');
            return;
        }

        $title = pathinfo($file['name'], PATHINFO_FILENAME);

        // Background path (opt-in): queue for the cron worker, which unlinks the
        // stored file after processing. Good for large PDFs that could time out.
        if ($this->config->bool('app.ingest_async', false)) {
            $this->jobs->enqueue('ingest.file', [
                'agent_id' => $agentId, 'source_type' => $type, 'path' => $dest,
                'title' => $title, 'filename' => $file['name'],
            ]);
            $this->finish('ok', 'File uploaded and queued for background processing (runs on the next cron tick).');
            return;
        }

        try {
            $this->run(fn () => $this->ingestion->ingest($agentId, $type, [
                'path' => $dest, 'title' => $title, 'filename' => $file['name'],
            ]));
        } finally {
            @unlink($dest);
        }
    }

    public function delete(Request $request): void
    {
        $id = (int) $request->input('id', 0);
        $doc = $this->documents->find($id);
        if ($doc !== null) {
            // Remove vectors from the external store (MySQL/PHP cascade via FK).
            $chunkIds = $this->chunks->idsForDocument($id);
            if ($chunkIds !== []) {
                try { $this->vectors->make()->delete('chunks', $chunkIds); } catch (Throwable) {}
            }
            $this->documents->delete($id);
            $this->finish('ok', 'Source removed.');
            return;
        }
        $this->finish('error', 'Source not found.');
    }

    /** Verify the file's real MIME matches the claimed type (fileinfo optional). */
    private function mimeMatches(string $path, string $type): bool
    {
        if (!function_exists('finfo_open')) {
            return true; // ext-fileinfo absent on this host — rely on extension check
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path) ?: '';
        finfo_close($finfo);

        return match ($type) {
            'pdf'  => str_contains($mime, 'pdf'),
            // DOCX is a zip container; fileinfo usually reports zip or the OOXML type.
            'docx' => str_contains($mime, 'zip') || str_contains($mime, 'officedocument') || str_contains($mime, 'msword'),
            default => false,
        };
    }

    /** Run an ingest closure and flash a friendly result. */
    private function run(callable $fn): void
    {
        try {
            $result = $fn();
            $this->finish('ok', sprintf('Added “%s” — %d chunks indexed.', $result['title'], $result['chunks']));
        } catch (Throwable $e) {
            $this->finish('error', $e->getMessage());
        }
    }

    private function finish(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        Response::redirect(u('/admin/knowledge'));
    }
}
