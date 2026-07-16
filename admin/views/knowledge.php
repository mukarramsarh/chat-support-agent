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

  <div style="margin:10px 0 16px;padding:10px 14px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-size:13px;color:var(--muted)">First-time setup? Load the ProcurementHub knowledge base (company info + Saudi procurement rules, AR/EN).</span>
    <a class="btn ghost" style="padding:6px 12px;font-size:13px;margin-inline-start:auto"
       href="<?= u('/seed.php') ?>" target="_blank"
       onclick="return confirm('This REPLACES the current knowledge base with the ProcurementHub seed. Continue?')">⚡ Seed ProcurementHub data</a>
  </div>

  <div class="tabs" style="display:flex;gap:8px;margin:8px 0 18px">
    <button class="tab-btn btn ghost on" data-tab="text">✍️ Paste text</button>
    <button class="tab-btn btn ghost" data-tab="url">🔗 Add URL</button>
    <button class="tab-btn btn ghost" data-tab="file">📄 Upload file</button>
  </div>

  <!-- Paste text -->
  <form class="tab-pane" data-pane="text" method="post" action="<?= u('/admin/knowledge/text') ?>">
    <?= csrf_field() ?>
    <div class="field"><label>Title (optional)</label>
      <input type="text" name="title" placeholder="e.g. Refund policy"></div>
    <div class="field" style="margin-top:12px"><label>Text</label>
      <textarea name="content" required placeholder="Paste FAQ answers, policies, product details…" style="min-height:160px"></textarea></div>
    <div style="margin-top:14px"><button class="btn" type="submit">Add to knowledge</button></div>
  </form>

  <!-- Add URL -->
  <form class="tab-pane" data-pane="url" method="post" action="<?= u('/admin/knowledge/url') ?>" style="display:none">
    <?= csrf_field() ?>
    <div class="field"><label>Page URL(s)</label>
      <textarea name="url" required placeholder="https://example.com/help/article&#10;https://gov.example.sa/regulations" style="min-height:90px"></textarea>
      <div class="hint">One URL per line (up to 20). We extract the main article text, not navigation/ads.</div></div>
    <div class="field" style="margin-top:12px;max-width:280px"><label>Auto-refresh (recrawl on schedule)</label>
      <select name="refresh">
        <option value="off">Off — fetch once</option>
        <option value="hourly">Every hour</option>
        <option value="daily">Every day</option>
        <option value="weekly">Every week</option>
        <option value="monthly">Every month</option>
      </select>
      <div class="hint">Great for pages that change (e.g. government rules). Requires the cron job to be set up.</div></div>
    <div style="margin-top:14px"><button class="btn" type="submit">Fetch &amp; add</button></div>
  </form>

  <!-- Upload file -->
  <form class="tab-pane" data-pane="file" method="post" action="<?= u('/admin/knowledge/upload') ?>" enctype="multipart/form-data" style="display:none">
    <?= csrf_field() ?>
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
    <?php
      $intervalLabel = function (int $m): string {
        return [60 => 'hourly', 1440 => 'daily', 10080 => 'weekly', 43200 => 'monthly'][$m] ?? ($m > 0 ? $m . 'm' : '');
      };
    ?>
    <table>
      <thead><tr><th>Title</th><th>Type</th><th>Chunks</th><th>Refresh</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($documents as $d):
          $status = $d['status'];
          $cls = ['ready' => 'ok', 'processing' => 'info', 'pending' => 'mut', 'failed' => 'warn'][$status] ?? 'mut';
          $isUrl = $d['source_type'] === 'url';
          $interval = (int) ($d['refresh_interval_minutes'] ?? 0);
        ?>
          <tr>
            <td><strong><?= e($d['title'] ?: '(untitled)') ?></strong>
              <?php if ($isUrl && $d['source_uri']): ?>
                <div style="color:var(--muted);font-size:12px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) $d['source_uri']) ?></div>
              <?php endif; ?>
              <?php if ($status === 'failed' && $d['error_message']): ?>
                <div style="color:#b91c1c;font-size:12px"><?= e($d['error_message']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="pill mut"><?= e(strtoupper($d['source_type'])) ?></span></td>
            <td><?= (int) $d['chunk_count'] ?></td>
            <td>
              <?php if ($interval > 0): ?>
                <span class="pill info"><?= e($intervalLabel($interval)) ?></span>
                <?php if (!empty($d['next_refresh_at'])): ?><div style="color:var(--muted);font-size:11px">next: <?= e(substr((string) $d['next_refresh_at'], 5, 11)) ?></div><?php endif; ?>
              <?php else: ?><span style="color:#cbd5e1">—</span><?php endif; ?>
            </td>
            <td><span class="pill <?= $cls ?>"><?= e($status) ?></span></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if ($isUrl): ?>
                <form method="post" action="<?= u('/admin/knowledge/refresh') ?>" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn ghost" style="padding:6px 12px;font-size:13px" title="Re-fetch now">↻ Refresh</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= u('/admin/knowledge/delete') ?>" style="display:inline" onsubmit="return confirm('Remove this source?')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
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
