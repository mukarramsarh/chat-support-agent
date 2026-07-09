<?php /** @var array $documents */ ?>
<div class="card" style="margin-bottom:18px">
  <h3>Add knowledge</h3>
  <p style="color:var(--muted);font-size:13px;margin-top:0">
    Upload a PDF/DOCX, add a web URL, or paste text. Sources are parsed, chunked and embedded in the
    background (via cron) so large files never time out. <span class="pill info">Ingestion pipeline · Phase 2</span>
  </p>
  <div class="grid cards" style="margin-top:8px">
    <button class="card" style="cursor:pointer;text-align:left" disabled>
      <div style="font-size:22px">📄</div><strong>Upload file</strong>
      <div style="color:var(--muted);font-size:12px">PDF or DOCX</div></button>
    <button class="card" style="cursor:pointer;text-align:left" disabled>
      <div style="font-size:22px">🔗</div><strong>Add URL</strong>
      <div style="color:var(--muted);font-size:12px">Crawl an article/page</div></button>
    <button class="card" style="cursor:pointer;text-align:left" disabled>
      <div style="font-size:22px">✍️</div><strong>Paste text</strong>
      <div style="color:var(--muted);font-size:12px">FAQ, policy, notes</div></button>
  </div>
</div>

<div class="card">
  <h3>Sources</h3>
  <?php if (empty($documents)): ?>
    <div class="empty"><div class="big">📚</div>No knowledge yet. Add your first source above to give the assistant something to answer from.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Title</th><th>Type</th><th>Chunks</th><th>Status</th><th>Added</th></tr></thead>
      <tbody>
        <?php foreach ($documents as $d):
          $status = $d['status'];
          $cls = ['ready' => 'ok', 'processing' => 'info', 'pending' => 'mut', 'failed' => 'warn'][$status] ?? 'mut';
        ?>
          <tr>
            <td><strong><?= e($d['title'] ?: '(untitled)') ?></strong></td>
            <td><span class="pill mut"><?= e(strtoupper($d['source_type'])) ?></span></td>
            <td><?= (int) $d['chunk_count'] ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($status) ?></span></td>
            <td style="color:var(--muted)"><?= e(substr((string) $d['created_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
