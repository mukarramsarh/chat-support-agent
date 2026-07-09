<?php
/** @var array $agent @var bool $saved */
$theme = $agent['theme'] ?: [];
$val = fn ($k, $d = '') => e((string) ($agent[$k] ?? $d));
$tval = fn ($k, $d = '') => e((string) ($theme[$k] ?? $d));
?>
<?php if (!empty($saved)): ?><div class="notice" style="margin-bottom:18px">✓ Settings saved.</div><?php endif; ?>

<form class="stack" method="post" action="/admin/agent" style="max-width:none">
  <div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">

    <!-- ── Persona & behaviour ── -->
    <div class="card">
      <h3>Persona &amp; behaviour</h3>
      <div class="field"><label>Assistant name</label>
        <input type="text" name="name" value="<?= $val('name') ?>"></div>
      <div class="field" style="margin-top:14px"><label>Persona / system prompt</label>
        <textarea name="persona" placeholder="You are a helpful support assistant for…"><?= $val('persona') ?></textarea>
        <div class="hint">Sets tone and boundaries. Kept stable so it can be prompt-cached.</div></div>
      <div class="field" style="margin-top:14px"><label>Welcome message</label>
        <input type="text" name="welcome_message" value="<?= $val('welcome_message') ?>"></div>
      <div class="field" style="margin-top:14px"><label>Fallback (when unsure / over budget)</label>
        <input type="text" name="fallback_message" value="<?= $val('fallback_message') ?>"></div>
    </div>

    <!-- ── Model & budget ── -->
    <div class="card">
      <h3>Model &amp; budget</h3>
      <div class="field"><label>Chat provider</label>
        <select name="chat_provider">
          <?php foreach (['gemini' => 'Gemini (Flash — lowest cost)', 'openai' => 'OpenAI', 'anthropic' => 'Anthropic (Claude)'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($agent['chat_provider'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" style="margin-top:14px"><label>Chat model</label>
        <input type="text" name="chat_model" value="<?= $val('chat_model') ?>" placeholder="gemini-flash-latest">
        <div class="hint">Leave blank to use the .env default. Resolved against the provider at runtime.</div></div>
      <div class="row" style="margin-top:14px">
        <div class="field"><label>Temperature</label>
          <input type="number" step="0.05" min="0" max="1" name="temperature" value="<?= $val('temperature', '0.30') ?>"></div>
        <div class="field"><label>Monthly budget (USD)</label>
          <input type="number" step="0.5" min="0" name="monthly_budget_usd" value="<?= $val('monthly_budget_usd', '2.00') ?>"></div>
      </div>
      <div class="hint" style="margin-top:12px">When the month's spend hits the budget, the assistant politely declines and offers a human hand-off instead of spending more.</div>
    </div>

    <!-- ── Appearance ── -->
    <div class="card">
      <h3>Widget appearance</h3>
      <div class="row">
        <div class="field"><label>Primary color</label>
          <input type="text" name="theme_primary" value="<?= $tval('primary', '#4f46e5') ?>"></div>
        <div class="field"><label>Accent color</label>
          <input type="text" name="theme_accent" value="<?= $tval('accent', '#7c3aed') ?>"></div>
      </div>
      <div class="row" style="margin-top:14px">
        <div class="field"><label>Launcher icon</label>
          <input type="text" name="theme_launcher" value="<?= $tval('launcher', '💬') ?>"></div>
        <div class="field"><label>Position</label>
          <select name="theme_position">
            <option value="right" <?= ($theme['position'] ?? 'right') === 'right' ? 'selected' : '' ?>>Bottom right</option>
            <option value="left" <?= ($theme['position'] ?? '') === 'left' ? 'selected' : '' ?>>Bottom left</option>
          </select></div>
      </div>
      <div class="field" style="margin-top:14px"><label>Header subtitle</label>
        <input type="text" name="theme_subtitle" value="<?= $tval('subtitle', 'Typically replies instantly') ?>"></div>
    </div>

    <!-- ── Embed ── -->
    <div class="card">
      <h3>Embed code</h3>
      <p style="color:var(--muted);font-size:13px;margin-top:0">Add to any page to go live:</p>
      <pre style="background:#0b1020;color:#e2e8f0;border-radius:10px;padding:14px;font-size:12px;overflow:auto;white-space:pre-wrap;word-break:break-all"><code>&lt;script src="/widget.js"
  data-agent="<?= $val('public_id') ?>" defer&gt;&lt;/script&gt;</code></pre>
      <a class="btn ghost" href="/demo" target="_blank" style="margin-top:8px">Open live preview ↗</a>
    </div>
  </div>

  <div><button class="btn" type="submit">Save settings</button></div>
</form>
