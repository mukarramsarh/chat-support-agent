<?php /** @var string $q @var ?array $result */ ?>
<div class="card" style="max-width:760px">
  <h3>Test chat</h3>
  <p style="color:var(--muted);font-size:13px;margin-top:0">Ask the live agent a question — same retrieval + evaluation as production, but nothing is saved.</p>
  <form method="post" action="/admin/test">
    <?= csrf_field() ?>
    <div class="field">
      <textarea name="q" placeholder="e.g. What is your refund policy?" style="min-height:70px"><?= e($q) ?></textarea>
    </div>
    <div style="margin-top:12px"><button class="btn" type="submit">Ask</button></div>
  </form>

  <?php if ($result !== null): ?>
    <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">
    <?php if (!empty($result['error'])): ?>
      <div class="notice" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">⚠ <?= e($result['error']) ?></div>
    <?php else: ?>
      <div style="background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:16px;font-size:14px;line-height:1.6;white-space:pre-wrap"><?= e($result['answer']) ?></div>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;font-size:12px">
        <span class="pill <?= $result['grounded'] ? 'ok' : 'mut' ?>"><?= $result['grounded'] ? 'grounded' : 'not grounded' ?></span>
        <span class="pill <?= $result['answered'] ? 'ok' : 'warn' ?>"><?= $result['answered'] ? 'answered' : 'declined' ?></span>
        <span class="pill mut">confidence <?= number_format((float) $result['confidence'] * 100, 0) ?>%</span>
        <span class="pill <?= $result['retrieved'] ? 'info' : 'mut' ?>"><?= $result['retrieved'] ? 'used knowledge' : 'no knowledge match' ?></span>
      </div>
      <?php if (!empty($result['citations'])): ?>
        <div style="margin-top:10px;font-size:12px;color:var(--muted)">Sources:
          <?php foreach ($result['citations'] as $i => $ct): ?><span class="pill mut" style="padding:1px 8px">[<?= $i + 1 ?>] <?= e($ct['title'] ?? '') ?></span> <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
