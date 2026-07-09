<?php
/** @var array $form @var array $compliance @var ?array $flash */
$fieldByKey = [];
foreach ($form['fields'] ?? [] as $f) { $fieldByKey[$f['key']] = $f; }
$fld = fn ($k, $prop, $def = '') => $fieldByKey[$k][$prop] ?? $def;
$comp = fn ($k, $def = '') => $compliance[$k] ?? $def;
?>
<?php if (!empty($flash)): ?>
  <div class="notice" style="margin-bottom:18px;<?= ($flash['type'] ?? '') === 'error' ? 'background:#fef2f2;border-color:#fecaca;color:#b91c1c' : '' ?>">
    <?= ($flash['type'] ?? '') === 'ok' ? '✓ ' : '⚠ ' ?><?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="notice" style="margin-bottom:18px;background:#eff6ff;border-color:#bfdbfe;color:#1e40af">
  🔒 These settings support <strong>KSA PDPL</strong> compliance (consent, data minimisation, cross-border safeguards, data-subject rights). They enable compliance but are not legal advice — have your DPO review.
</div>

<form class="stack" method="post" action="/admin/privacy" style="max-width:none">
  <div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">

    <!-- Startup form -->
    <div class="card">
      <h3>Startup form (pre-chat lead capture)</h3>
      <label style="display:flex;align-items:center;gap:8px;font-weight:600">
        <input type="checkbox" name="form_enabled" value="1" <?= !empty($form['enabled']) ? 'checked' : '' ?>> Enable the form before chat starts
      </label>
      <div class="field" style="margin-top:14px"><label>Title</label>
        <input type="text" name="form_title" value="<?= e($form['title'] ?? '') ?>"></div>
      <div class="field" style="margin-top:12px"><label>Subtitle</label>
        <input type="text" name="form_subtitle" value="<?= e($form['subtitle'] ?? '') ?>"></div>

      <div style="margin-top:16px">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;margin-bottom:8px">Fields</div>
        <?php foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company'] as $k => $lbl): ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <input type="text" name="label_<?= $k ?>" value="<?= e($fld($k, 'label', $lbl)) ?>" style="flex:1">
            <label style="display:flex;align-items:center;gap:5px;font-size:13px"><input type="checkbox" name="enabled_<?= $k ?>" value="1" <?= !empty($fld($k, 'enabled')) ? 'checked' : '' ?>> on</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px"><input type="checkbox" name="required_<?= $k ?>" value="1" <?= !empty($fld($k, 'required')) ? 'checked' : '' ?>> req</label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Consent & privacy -->
    <div class="card">
      <h3>Consent &amp; privacy</h3>
      <label style="display:flex;align-items:center;gap:8px;font-weight:600">
        <input type="checkbox" name="consent_required" value="1" <?= !empty($form['consent_required']) ? 'checked' : '' ?>> Require explicit consent before collecting data
      </label>
      <div class="field" style="margin-top:12px"><label>Consent text</label>
        <textarea name="consent_text" style="min-height:80px"><?= e($form['consent_text'] ?? '') ?></textarea></div>
      <div class="field" style="margin-top:12px"><label>Privacy policy URL</label>
        <input type="text" name="privacy_url" value="<?= e($comp('privacy_url')) ?>" placeholder="https://…"></div>

      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
      <label style="display:flex;align-items:center;gap:8px;font-weight:600">
        <input type="checkbox" name="pii_redaction" value="1" <?= !empty($comp('pii_redaction')) ? 'checked' : '' ?>> Redact PII before sending text to external AI providers
      </label>
      <div class="hint" style="margin-top:5px">Strips emails, phones, IDs, cards, IBANs from prompts/embeddings sent abroad. Originals stay on your server.</div>

      <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-top:14px">
        <input type="checkbox" name="rtl" value="1" <?= !empty($comp('rtl')) ? 'checked' : '' ?>> Right-to-left (Arabic) widget layout
      </label>

      <div class="field" style="margin-top:14px"><label>Data retention (days, 0 = keep forever)</label>
        <input type="number" min="0" name="retention_days" value="<?= (int) $comp('retention_days', 0) ?>">
        <div class="hint">Conversations &amp; leads older than this are auto-purged by cron.</div></div>
    </div>
  </div>

  <div><button class="btn" type="submit">Save privacy settings</button></div>
</form>

<!-- Data-subject rights -->
<div class="card" style="margin-top:18px">
  <h3>Data-subject rights (erase / export)</h3>
  <p style="color:var(--muted);font-size:13px;margin-top:0">Act on a visitor's request. The visitor id is shown on each conversation.</p>
  <div class="row" style="align-items:end">
    <form method="get" action="/admin/privacy/export" style="display:flex;gap:8px;align-items:end">
      <div class="field" style="margin:0;flex:1"><label>Export all data for visitor</label>
        <input type="text" name="visitor_id" placeholder="visitor id" required></div>
      <button class="btn ghost" type="submit">Export JSON</button>
    </form>
    <form method="post" action="/admin/privacy/erase" style="display:flex;gap:8px;align-items:end"
          onsubmit="return confirm('Permanently erase ALL data for this visitor? This cannot be undone.')">
      <div class="field" style="margin:0;flex:1"><label>Erase all data for visitor</label>
        <input type="text" name="visitor_id" placeholder="visitor id" required></div>
      <button class="btn" style="background:linear-gradient(135deg,#ef4444,#b91c1c)" type="submit">Erase</button>
    </form>
  </div>
</div>
