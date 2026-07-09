<?php /** @var array $conversations */ ?>
<div class="card">
  <h3>Recent conversations</h3>
  <?php if (empty($conversations)): ?>
    <div class="empty"><div class="big">💬</div>No conversations yet. They'll appear here as visitors chat with your widget.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Visitor</th><th>First message</th><th>Msgs</th><th>Cost</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
        <?php foreach ($conversations as $c):
          $cls = ['open' => 'ok', 'closed' => 'mut', 'escalated' => 'warn'][$c['status']] ?? 'mut';
        ?>
          <tr>
            <td><code><?= e(substr((string) $c['visitor_id'], 0, 12)) ?></code></td>
            <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= e(mb_substr((string) ($c['first_message'] ?? ''), 0, 80)) ?: '<span style="color:#94a3b8">—</span>' ?></td>
            <td><?= (int) $c['message_count'] ?></td>
            <td>$<?= number_format((float) $c['total_cost_usd'], 4) ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($c['status']) ?></span></td>
            <td style="color:var(--muted)"><?= e(substr((string) $c['updated_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
