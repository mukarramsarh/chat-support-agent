<?php /** @var array $documents @var ?array $flash @var ?string $lockedModel */ ?>

<?php if (!empty($flash)): ?>
  <div class="<?= ($flash['type'] ?? '') === 'ok' ? 'notice' : 'notice' ?>"
       style="margin-bottom:18px;<?= ($flash['type'] ?? '') === 'error' ? 'background:#fef2f2;border-color:#fecaca;color:#b91c1c' : '' ?>">
    <?= ($flash['type'] ?? '') === 'ok' ? '✓ ' : '⚠ ' ?><?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <h3>Add knowledge</h3>
  <p style="color:var(--muted);font-size:13px;margin-top:0">
    Upload a PDF/DOCX, add a web URL, or paste text. Content is parsed, chunked and embedded immediately.
    <?php if ($lockedModel): ?>
      <span class="pill mut">embeddings: <?= e($lockedModel) ?></span>
    <?php endif; ?>
  </p>

  <div class="tabs" style="display:flex;gap:8px;margin:8px 0 18px">
    <button class="tab-btn btn ghost on" data-tab="text">✍️ Paste text</button>
    <button class="tab-btn btn ghost" data-tab="url">🔗 Add URL</button>
    <button class="tab-btn btn ghost" data-tab="file">📄 Upload file</button>
  </div>

  <!-- Paste text -->
  <form class="tab-pane" data-pane="text" method="post" action="/admin/knowledge/text">
    <div class="field"><label>Title (optional)</label>
      <input type="text" name="title" placeholder="e.g. Refund policy"></div>
    <div class="field" style="margin-top:12px"><label>Text</label>
      <textarea name="content" required placeholder="Paste FAQ answers, policies, product details…" style="min-height:160px"></textarea></div>
    <div style="margin-top:14px"><button class="btn" type="submit">Add to knowledge</button></div>
  </form>

  <!-- Add URL -->
  <form class="tab-pane" data-pane="url" method="post" action="/admin/knowledge/url" style="display:none">
    <div class="field"><label>Page URL</label>
      <input type="text" name="url" required placeholder="https://example.com/help/article">
      <div class="hint">We extract the main article text (not navigation/ads).</div></div>
    <div style="margin-top:14px"><button class="btn" type="submit">Fetch &amp; add</button></div>
  </form>

  <!-- Upload file -->
  <form class="tab-pane" data-pane="file" method="post" action="/admin/knowledge/upload" enctype="multipart/form-data" style="display:none">
    <div class="field"><label>PDF or DOCX (max 10 MB)</label>
      <input type="file" name="file" accept=".pdf,.docx" required>
      <div class="hint">Scanned/image-only PDFs can't be read (no OCR).</div></div>
    <div style="margin-top:14px"><button class="btn" type="submit">Upload &amp; add</button></div>
  </form>
</div>

<div class="card">
  <h3>Sources</h3>
  <?php if (empty($documents)): ?>
    <div class="empty"><div class="big">📚</div>No knowledge yet. Add your first source above to give the assistant something to answer from.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Title</th><th>Type</th><th>Chunks</th><th>Status</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($documents as $d):
          $status = $d['status'];
          $cls = ['ready' => 'ok', 'processing' => 'info', 'pending' => 'mut', 'failed' => 'warn'][$status] ?? 'mut';
        ?>
          <tr>
            <td><strong><?= e($d['title'] ?: '(untitled)') ?></strong>
              <?php if ($status === 'failed' && $d['error_message']): ?>
                <div style="color:#b91c1c;font-size:12px"><?= e($d['error_message']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="pill mut"><?= e(strtoupper($d['source_type'])) ?></span></td>
            <td><?= (int) $d['chunk_count'] ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($status) ?></span></td>
            <td style="color:var(--muted)"><?= e(substr((string) $d['created_at'], 0, 10)) ?></td>
            <td style="text-align:right">
              <form method="post" action="/admin/knowledge/delete" onsubmit="return confirm('Remove this source?')">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn ghost" style="padding:6px 12px;font-size:13px">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
  (function () {
    var btns = document.querySelectorAll('.tab-btn');
    var panes = document.querySelectorAll('.tab-pane');
    btns.forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        btns.forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        panes.forEach(function (p) {
          p.style.display = p.getAttribute('data-pane') === b.getAttribute('data-tab') ? '' : 'none';
        });
      });
    });
  })();
</script>
<style>
  .tab-btn.on{background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;border-color:transparent}
</style>
