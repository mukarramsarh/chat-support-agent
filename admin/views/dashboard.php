<?php
/** @var array $stats @var array|null $agent */
$driverLabels = [
    'mysql-vector' => ['MySQL 9 native vector', 'ok'],
    'pinecone'     => ['Pinecone', 'info'],
    'php-cosine'   => ['PHP cosine (portable)', 'mut'],
];
[$driverName, $driverClass] = $driverLabels[$stats['vector_driver']] ?? [$stats['vector_driver'], 'mut'];
$spend = number_format($stats['spend'], 4);
$budget = number_format($stats['budget'], 2);
$pct = $stats['budget_pct'];
$barClass = $pct >= 90 ? 'warn' : 'ok';
$appUrl = e($app_url ?? '');
$publicId = e($agent['public_id'] ?? '');

// Build a tiny inline sparkline for the last 14 days of spend.
$daily = $stats['daily'];
$max = 0.0;
foreach ($daily as $d) { $max = max($max, (float) $d['cost']); }
?>
<div class="grid cards">
  <div class="card stat">
    <div class="label">💵 <?= e(t('Spend this month')) ?></div>
    <div class="value">$<?= $spend ?></div>
    <div class="sub"><?= e(str_replace(':amount', '$' . $budget, t('of :amount budget'))) ?></div>
    <div class="bar"><span style="width:<?= round($pct, 1) ?>%;<?= $barClass==='warn'?'background:linear-gradient(90deg,#f59e0b,#ef4444)':'' ?>"></span></div>
  </div>
  <div class="card stat">
    <div class="label">💬 <?= e(t('Conversations')) ?></div>
    <div class="value"><?= number_format($stats['conversations']) ?></div>
    <div class="sub"><?= number_format($stats['messages']) ?> <?= e(t('assistant replies')) ?></div>
  </div>
  <a class="card stat" href="<?= u('/admin/conversations?status=needs_attention') ?>" style="text-decoration:none;color:inherit">
    <div class="label">⚠️ <?= e(t('Needs attention')) ?></div>
    <div class="value" style="<?= $stats['needs_attention'] > 0 ? 'color:#d97706' : '' ?>"><?= number_format($stats['needs_attention']) ?></div>
    <div class="sub"><?= e(t('sessions flagged for review')) ?></div>
  </a>
  <div class="card stat">
    <div class="label">📚 <?= e(t('Knowledge')) ?></div>
    <div class="value"><?= number_format($stats['documents']) ?></div>
    <div class="sub"><?= number_format($stats['chunks']) ?> <?= e(t('indexed chunks')) ?></div>
  </div>
  <div class="card stat">
    <div class="label">🧭 <?= e(t('Vector store')) ?></div>
    <div class="value" style="font-size:18px;margin-top:12px"><span class="pill <?= $driverClass ?>"><?= e($driverName) ?></span></div>
    <div class="sub"><?= e(t('auto-selected for this host')) ?></div>
  </div>
</div>

<div class="grid" style="grid-template-columns:1.4fr 1fr;margin-top:18px">
  <div class="card">
    <h3>Spend · last 14 days</h3>
    <?php if ($daily): ?>
      <svg viewBox="0 0 560 140" width="100%" height="140" preserveAspectRatio="none">
        <?php
          $n = count($daily); $w = $n > 1 ? 560 / ($n - 1) : 560; $pts = [];
          foreach (array_values($daily) as $i => $d) {
              $x = $i * $w;
              $y = $max > 0 ? 130 - (((float) $d['cost']) / $max) * 110 : 130;
              $pts[] = round($x, 1) . ',' . round($y, 1);
          }
          $line = implode(' ', $pts);
          $area = '0,140 ' . $line . ' ' . round(($n - 1) * $w, 1) . ',140';
        ?>
        <defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#7c3aed" stop-opacity=".28"/>
          <stop offset="1" stop-color="#7c3aed" stop-opacity="0"/>
        </linearGradient></defs>
        <polygon points="<?= $area ?>" fill="url(#g)"/>
        <polyline points="<?= $line ?>" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linejoin="round"/>
      </svg>
    <?php else: ?>
      <div class="empty"><div class="big">📈</div>No usage yet — spend will appear here after your first chats.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Install the widget</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:0">Paste this before <code>&lt;/body&gt;</code> on any site:</p>
    <pre style="background:#0b1020;color:#e2e8f0;border-radius:10px;padding:14px;font-size:12px;overflow:auto;white-space:pre-wrap;word-break:break-all"><code>&lt;script src="<?= $appUrl ?>/widget.js"
  data-agent="<?= $publicId ?>" defer&gt;&lt;/script&gt;</code></pre>
    <a class="btn ghost" href="<?= u('/admin/agent') ?>" style="margin-top:6px">Customize appearance →</a>
  </div>
</div>
