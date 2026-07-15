<?php
/** @var array $conversation @var array $messages @var array $statuses */
$statusMeta = [
    'incomplete'      => ['Incomplete', 'mut'],
    'ai_answered'     => ['AI answered', 'ok'],
    'needs_attention' => ['Needs attention', 'warn'],
    'escalated'       => ['Escalated', 'warn'],
    'resolved'        => ['Resolved', 'info'],
    'abandoned'       => ['Abandoned', 'mut'],
];
$curStatus = $conversation['status'];
[$label, $cls] = $statusMeta[$curStatus] ?? [$curStatus, 'mut'];
$fmtLabel = fn ($s) => ucfirst(str_replace('_', ' ', $s));
?>
<div style="display:flex;justify-content:space-between;align-items:center">
  <a href="<?= u('/admin/conversations') ?>" style="color:var(--muted);font-size:13px">← All conversations</a>
  <a class="btn ghost" href="<?= u('/admin/conversations/' . (int) $conversation['id'] . '/export') ?>" style="padding:6px 12px;font-size:13px">⬇ Export JSON</a>
</div>

<div class="card" style="margin:12px 0 18px">
  <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:13px;color:var(--muted)">Visitor</div>
      <div style="font-weight:600"><code><?= e((string) $conversation['visitor_id']) ?></code></div>
    </div>
    <div><div style="font-size:13px;color:var(--muted)">Messages</div><div style="font-weight:600"><?= (int) $conversation['message_count'] ?></div></div>
    <div><div style="font-size:13px;color:var(--muted)">Total cost</div><div style="font-weight:600">$<?= number_format((float) $conversation['total_cost_usd'], 5) ?></div></div>
    <div><div style="font-size:13px;color:var(--muted)">Started</div><div style="font-weight:600"><?= e(substr((string) $conversation['created_at'], 0, 16)) ?></div></div>
    <form method="post" action="<?= u('/admin/conversations/' . (int) $conversation['id'] . '/status') ?>" style="display:flex;gap:8px;align-items:center">
      <?= csrf_field() ?>
      <span class="pill <?= $cls ?>"><?= e($label) ?></span>
      <select name="status" onchange="this.form.submit()" style="width:auto">
        <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $s === $curStatus ? 'selected' : '' ?>><?= e($fmtLabel($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if (!empty($conversation['page_url'])): ?>
    <div style="margin-top:12px;font-size:13px;color:var(--muted)">Started on: <?= e((string) $conversation['page_url']) ?></div>
  <?php endif; ?>
  <?php if (!empty($conversation['summary'])): ?>
    <div style="margin-top:12px;font-size:13px"><strong>Summary:</strong> <?= e((string) $conversation['summary']) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Transcript</h3>
  <?php if (empty($messages)): ?>
    <div class="empty">No messages.</div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:14px">
      <?php foreach ($messages as $m):
        $isUser = $m['role'] === 'user';
        $eval = $m['eval'] ? json_decode((string) $m['eval'], true) : [];
        $cites = $m['citations'] ? json_decode((string) $m['citations'], true) : [];
      ?>
        <div style="align-self:<?= $isUser ? 'flex-end' : 'flex-start' ?>;max-width:78%">
          <div style="font-size:11px;color:var(--muted);margin-bottom:4px;<?= $isUser ? 'text-align:right' : '' ?>">
            <?= $isUser ? 'Visitor' : 'Assistant' ?> · <?= e(substr((string) $m['created_at'], 11, 5)) ?>
          </div>
          <div style="padding:11px 15px;border-radius:14px;font-size:14px;line-height:1.5;white-space:pre-wrap;
                      <?= $isUser ? 'background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;border-bottom-right-radius:4px'
                                  : 'background:#f8fafc;border:1px solid var(--line);border-bottom-left-radius:4px' ?>">
            <?= e((string) $m['content']) ?>
          </div>
          <?php if (!$isUser): ?>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;display:flex;gap:10px;flex-wrap:wrap">
              <?php if ($m['model']): ?><span>🧠 <?= e((string) $m['model']) ?></span><?php endif; ?>
              <?php if ((int) $m['tokens_in'] + (int) $m['tokens_out'] > 0): ?>
                <span>🎫 <?= (int) $m['tokens_in'] ?>→<?= (int) $m['tokens_out'] ?> tok</span><?php endif; ?>
              <span>💵 $<?= number_format((float) $m['cost_usd'], 6) ?></span>
              <?php if ($m['latency_ms']): ?><span>⚡ <?= (int) $m['latency_ms'] ?>ms</span><?php endif; ?>
              <?php if (!empty($eval['grounded'])): ?><span class="pill ok" style="padding:1px 8px">grounded</span><?php endif; ?>
              <?php if (($eval['verdict'] ?? '') === 'declined'): ?><span class="pill warn" style="padding:1px 8px">declined</span><?php endif; ?>
            </div>
            <?php if ($cites): ?>
              <div style="font-size:11px;color:var(--muted);margin-top:4px">
                Sources: <?php foreach ($cites as $i => $ct): ?><span class="pill mut" style="padding:1px 8px">[<?= $i + 1 ?>] <?= e($ct['title'] ?? '') ?></span> <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
