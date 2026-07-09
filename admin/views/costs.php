<?php
/** @var array $daily @var array $byOperation @var float $monthSpend */
$total30 = 0.0;
foreach ($daily as $d) { $total30 += (float) $d['cost']; }
$opColors = ['chat' => '#4f46e5', 'embed' => '#7c3aed', 'rerank' => '#0ea5e9', 'summarize' => '#f59e0b', 'eval' => '#10b981'];
$opTotal = 0.0;
foreach ($byOperation as $o) { $opTotal += (float) $o['cost']; }
?>
<div class="grid cards" style="margin-bottom:18px">
  <div class="card stat"><div class="label">💵 This month</div><div class="value">$<?= number_format($monthSpend, 4) ?></div></div>
  <div class="card stat"><div class="label">🗓 Last 30 days</div><div class="value">$<?= number_format($total30, 4) ?></div></div>
  <div class="card stat"><div class="label">🧾 Calls (30d)</div>
    <div class="value"><?= number_format(array_sum(array_map(fn ($d) => (int) $d['calls'], $daily))) ?></div></div>
</div>

<div class="grid" style="grid-template-columns:1.5fr 1fr">
  <div class="card">
    <h3>Daily spend · 30 days</h3>
    <?php if ($daily): $max = max(array_map(fn ($d) => (float) $d['cost'], $daily)) ?: 1; ?>
      <div style="display:flex;align-items:flex-end;gap:3px;height:160px;margin-top:10px">
        <?php foreach ($daily as $d): $h = max(2, ((float) $d['cost'] / $max) * 150); ?>
          <div title="<?= e($d['usage_day']) ?>: $<?= number_format((float) $d['cost'], 5) ?>"
               style="flex:1;height:<?= round($h) ?>px;border-radius:4px 4px 0 0;
                      background:linear-gradient(180deg,#7c3aed,#4f46e5)"></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty"><div class="big">📈</div>No billable usage recorded yet.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>By operation · 30 days</h3>
    <?php if ($byOperation): ?>
      <?php foreach ($byOperation as $o): $pct = $opTotal > 0 ? ((float) $o['cost'] / $opTotal) * 100 : 0;
        $color = $opColors[$o['operation']] ?? '#94a3b8'; ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
            <span style="font-weight:600"><?= e(ucfirst($o['operation'])) ?></span>
            <span style="color:var(--muted)">$<?= number_format((float) $o['cost'], 4) ?> · <?= (int) $o['calls'] ?> calls</span>
          </div>
          <div class="bar"><span style="width:<?= round($pct, 1) ?>%;background:<?= $color ?>"></span></div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty">No data yet.</div>
    <?php endif; ?>
    <p style="color:var(--muted);font-size:12px;margin-top:14px">
      Costs are estimated from the pricing table and may differ slightly from your provider invoice.
    </p>
  </div>
</div>
