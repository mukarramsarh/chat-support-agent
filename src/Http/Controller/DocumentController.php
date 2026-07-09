<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Application\Ingestion\IngestionService;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\ChunkRepository;
use SupportAI\Infrastructure\Persistence\DocumentRepository;
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

    public function __construct(
        private IngestionService $ingestion,
        private AgentRepository $agents,
        private DocumentRepository $documents,
        private ChunkRepository $chunks,
        private VectorStoreFactory $vectors,
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
        $url = trim((string) $request->input('url', ''));
        $this->run(fn () => $this->ingestion->ingest($agentId, 'url', ['url' => $url]));
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
        Response::redirect('/admin/knowledge');
    }
}
