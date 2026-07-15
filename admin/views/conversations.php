<?php
/** @var array $conversations @var array $counts @var string $filter */
$statusMeta = [
    'incomplete'      => ['Incomplete', 'mut'],
    'ai_answered'     => ['AI answered', 'ok'],
    'needs_attention' => ['Needs attention', 'warn'],
    'escalated'       => ['Escalated', 'warn'],
    'resolved'        => ['Resolved', 'info'],
    'abandoned'       => ['Abandoned', 'mut'],
];
$total = array_sum($counts);
?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <a href="<?= u('/admin/conversations') ?>" class="pill <?= $filter === '' ? 'info' : 'mut' ?>" style="text-decoration:none;padding:6px 12px">All (<?= (int) $total ?>)</a>
  <?php foreach ($statusMeta as $key => [$label, $cls]): if (empty($counts[$key])) continue; ?>
    <a href="<?= u('/admin/conversations?status=' . $key) ?>" class="pill <?= $filter === $key ? $cls : 'mut' ?>"
       style="text-decoration:none;padding:6px 12px"><?= e($label) ?> (<?= (int) $counts[$key] ?>)</a>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>Conversations</h3>
  <?php if (empty($conversations)): ?>
    <div class="empty"><div class="big">💬</div>No conversations<?= $filter ? ' with this status' : '' ?> yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Visitor</th><th>First message</th><th>Msgs</th><th>Cost</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
        <?php foreach ($conversations as $c):
          [$label, $cls] = $statusMeta[$c['status']] ?? [$c['status'], 'mut'];
        ?>
          <tr onclick="location.href='<?= u('/admin/conversations/' . (int) $c['id']) ?>'" style="cursor:pointer">
            <td><code><?= e(substr((string) $c['visitor_id'], 0, 12)) ?></code></td>
            <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= e(mb_substr((string) ($c['first_message'] ?? ''), 0, 80)) ?: '<span style="color:#94a3b8">—</span>' ?></td>
            <td><?= (int) $c['message_count'] ?></td>
            <td>$<?= number_format((float) $c['total_cost_usd'], 4) ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($label) ?></span></td>
            <td style="color:var(--muted)"><?= e(substr((string) $c['updated_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
