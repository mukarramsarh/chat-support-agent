<?php
/** @var array $sets @var ?array $selected @var array $cases @var ?array $latest @var array $results @var ?array $flash */
$pct = fn ($v) => $v === null ? '—' : number_format((float) $v * 100, 0) . '%';
?>
<?php if (!empty($flash)): ?>
  <div class="notice" style="margin-bottom:18px;<?= ($flash['type'] ?? '') === 'error' ? 'background:#fef2f2;border-color:#fecaca;color:#b91c1c' : '' ?>">
    <?= ($flash['type'] ?? '') === 'ok' ? '✓ ' : '⚠ ' ?><?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:260px 1fr;align-items:start">
  <!-- Sets sidebar -->
  <div class="card">
    <h3>Test sets</h3>
    <?php if (empty($sets)): ?><p style="color:var(--muted);font-size:13px">No sets yet.</p><?php endif; ?>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
      <?php foreach ($sets as $s): ?>
        <a href="/admin/evals?set=<?= (int) $s['id'] ?>"
           style="padding:9px 12px;border-radius:9px;text-decoration:none;font-size:14px;<?= ($selected && $selected['id'] == $s['id']) ? 'background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff' : 'color:var(--ink);background:#f8fafc' ?>">
          <?= e($s['name']) ?> <span style="opacity:.7;font-size:12px">(<?= (int) $s['case_count'] ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/admin/evals/set">
      <?= csrf_field() ?>
      <input type="text" name="name" placeholder="New set name" required style="margin-bottom:8px">
      <button class="btn" style="width:100%">Create set</button>
    </form>
  </div>

  <!-- Selected set -->
  <div>
    <?php if (!$selected): ?>
      <div class="card"><div class="empty"><div class="big">🧪</div>Create a test set, add golden questions, and run them to catch quality regressions.</div></div>
    <?php else: ?>
      <div class="card" style="margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <h3 style="margin:0"><?= e($selected['name']) ?></h3>
          <div style="display:flex;gap:8px">
            <form method="post" action="/admin/evals/run"><?= csrf_field() ?><input type="hidden" name="set_id" value="<?= (int) $selected['id'] ?>"><button class="btn" <?= empty($cases) ? 'disabled' : '' ?>>▶ Run</button></form>
            <form method="post" action="/admin/evals/set/delete" onsubmit="return confirm('Delete this set?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><button class="btn ghost">Delete</button></form>
          </div>
        </div>
        <?php if ($latest): ?>
          <div class="grid cards" style="margin-top:16px">
            <div class="stat"><div class="label">Avg score</div><div class="value"><?= $pct($latest['avg_score']) ?></div></div>
            <div class="stat"><div class="label">Retrieval hit</div><div class="value"><?= $pct($latest['hit_rate']) ?></div></div>
            <div class="stat"><div class="label">Grounded</div><div class="value"><?= $pct($latest['grounded_rate']) ?></div></div>
            <div class="stat"><div class="label">Last run</div><div class="value" style="font-size:15px;margin-top:12px"><?= e(substr((string) $latest['created_at'], 0, 16)) ?></div></div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Add case -->
      <div class="card" style="margin-bottom:18px">
        <h3>Add a test case</h3>
        <form method="post" action="/admin/evals/case">
          <?= csrf_field() ?><input type="hidden" name="set_id" value="<?= (int) $selected['id'] ?>">
          <div class="field"><label>Question</label><input type="text" name="question" required placeholder="How long is the refund window?"></div>
          <div class="row" style="margin-top:12px">
            <div class="field"><label>Expected answer (reference, optional)</label><input type="text" name="expected" placeholder="30 days"></div>
            <div class="field"><label>Must include (comma-separated keywords)</label><input type="text" name="must_include" placeholder="30 days, refund"></div>
          </div>
          <div style="margin-top:12px"><button class="btn ghost">Add case</button></div>
        </form>
      </div>

      <!-- Cases + latest results -->
      <div class="card">
        <h3>Cases &amp; latest results</h3>
        <?php if (empty($cases)): ?>
          <div class="empty">No cases yet — add one above.</div>
        <?php else:
          $byCase = [];
          foreach ($results as $r) { $byCase[(int) $r['eval_case_id']] = $r; }
        ?>
          <table>
            <thead><tr><th>Question</th><th>Score</th><th>Grounded</th><th>Notes</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($cases as $cse): $res = $byCase[(int) $cse['id']] ?? null;
                $sc = $res ? (float) $res['score'] : null;
                $cls = $sc === null ? 'mut' : ($sc >= 0.7 ? 'ok' : ($sc >= 0.4 ? 'warn' : 'mut'));
              ?>
                <tr>
                  <td><?= e(mb_substr((string) $cse['question'], 0, 70)) ?></td>
                  <td><?= $res ? '<span class="pill ' . $cls . '">' . number_format($sc * 100, 0) . '%</span>' : '<span style="color:#cbd5e1">—</span>' ?></td>
                  <td><?= $res ? ((int) $res['grounded'] ? '✓' : '·') : '' ?></td>
                  <td style="color:var(--muted);font-size:12px"><?= $res ? e((string) $res['notes']) : '' ?></td>
                  <td style="text-align:right">
                    <form method="post" action="/admin/evals/case/delete"><?= csrf_field() ?><input type="hidden" name="set_id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="id" value="<?= (int) $cse['id'] ?>"><button class="btn ghost" style="padding:5px 10px;font-size:12px">✕</button></form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
