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
        <select name="chat_provider" id="chat_provider">
          <?php foreach (['gemini' => 'Gemini (Flash — lowest cost)', 'openai' => 'OpenAI', 'anthropic' => 'Anthropic (Claude)'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($agent['chat_provider'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field" style="margin-top:14px"><label>Chat model</label>
        <select name="chat_model" id="chat_model" data-current="<?= $val('chat_model') ?>">
          <?php if ($val('chat_model') !== ''): ?>
            <option value="<?= $val('chat_model') ?>" selected><?= $val('chat_model') ?></option>
          <?php endif; ?>
        </select>
        <div class="hint" id="chat_model_hint">Models are fetched live from the provider using your API key.</div></div>
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
          <?php
            $currentIcon = $theme['launcher'] ?? '💬';
            $icons = ['💬', '💭', '🗨️', '🤖', '👋', '✨', '🎧', '📞', '💡', '❓', '🛎️', '😊', '🙋', '💁', '🧑‍💻'];
            if (!in_array($currentIcon, $icons, true)) { array_unshift($icons, $currentIcon); }
          ?>
          <select name="theme_launcher" id="theme_launcher" style="font-size:18px">
            <?php foreach ($icons as $ic): ?>
              <option value="<?= e($ic) ?>" <?= $ic === $currentIcon ? 'selected' : '' ?>><?= e($ic) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Shown as the floating chat button on your site.</div></div>
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
      <pre style="background:#0b1020;color:#e2e8f0;border-radius:10px;padding:14px;font-size:12px;overflow:auto;white-space:pre-wrap;word-break:break-all"><code>&lt;script src="<?= e($app_url ?? '') ?>/widget.js"
  data-agent="<?= $val('public_id') ?>" defer&gt;&lt;/script&gt;</code></pre>
      <a class="btn ghost" href="/demo" target="_blank" style="margin-top:8px">Open live preview ↗</a>
    </div>
  </div>

  <div><button class="btn" type="submit">Save settings</button></div>
</form>

<script>
(function () {
  var providerEl = document.getElementById('chat_provider');
  var modelEl = document.getElementById('chat_model');
  var hintEl = document.getElementById('chat_model_hint');
  var current = modelEl.getAttribute('data-current') || '';

  function opt(value, label, selected) {
    var o = document.createElement('option');
    o.value = value; o.textContent = label; if (selected) o.selected = true;
    return o;
  }

  function load() {
    var provider = providerEl.value;
    hintEl.textContent = 'Fetching ' + provider + ' models…';
    modelEl.innerHTML = '';
    modelEl.appendChild(opt('', 'Loading…', true));

    fetch('/admin/api/models?provider=' + encodeURIComponent(provider), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var models = data.models || [];
        modelEl.innerHTML = '';

        if (!models.length) {
          hintEl.textContent = data.error
            ? ('Could not list models (' + data.error + '). Check the ' + provider + ' API key in .env.')
            : 'No chat models returned for this key.';
          modelEl.appendChild(opt(current, current || '(add API key to list models)', true));
          return;
        }

        var found = false;
        models.forEach(function (m) {
          var label = m.hint ? (m.id + '  —  ' + m.hint) : m.id;
          var isCur = m.id === current;
          if (isCur) found = true;
          modelEl.appendChild(opt(m.id, label, isCur));
        });
        if (current && !found) {
          modelEl.insertBefore(opt(current, current + '  —  (current)', true), modelEl.firstChild);
        }
        hintEl.textContent = models.length + ' models available · hints are guidance, verify pricing with the provider.';
      })
      .catch(function () {
        modelEl.innerHTML = '';
        modelEl.appendChild(opt(current, current || '(manual entry)', true));
        hintEl.textContent = 'Network error fetching models.';
      });
  }

  // Switching provider invalidates the previously chosen model.
  providerEl.addEventListener('change', function () { current = ''; load(); });
  load();
})();
</script>
