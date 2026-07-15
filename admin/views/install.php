<?php
/** @var array $checks @var ?string $error @var array $values @var ?string $manualEnv @var bool $ready */
$v = fn ($k, $d = '') => e((string) ($values[$k] ?? $d));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · support-ai</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;
       background:radial-gradient(1200px 600px at 20% -10%,#e0e7ff,transparent),radial-gradient(1000px 500px at 100% 110%,#f3e8ff,transparent),#f6f7fb}
  .wrap{max-width:640px;margin:0 auto;padding:48px 24px}
  .logo{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px}
  h1{font-size:24px;margin:16px 0 4px}p.sub{color:#64748b;margin:0 0 22px}
  .card{background:#fff;border:1px solid #e8ebf1;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06);margin-bottom:18px}
  .card h3{margin:0 0 14px;font-size:15px}
  .chk{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:14px}
  .chk .b{width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex:0 0 auto}
  .ok{background:#16a34a}.bad{background:#dc2626}
  .chk .d{color:#94a3b8;font-size:12px;margin-left:auto}
  label{display:block;font-weight:600;font-size:13px;margin:12px 0 6px}
  input,select{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none}
  input:focus,select:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .btn{width:100%;margin-top:20px;border:0;border-radius:10px;padding:13px;font-size:15px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:11px 14px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .muted{color:#64748b;font-size:12px;margin-top:5px}
  pre{background:#0b1020;color:#e2e8f0;border-radius:10px;padding:14px;font-size:12px;overflow:auto;white-space:pre-wrap;word-break:break-all}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">◆</div>
  <h1>Install support-ai</h1>
  <p class="sub">A couple of details and you're live. No shell or Composer needed.</p>

  <?php if (!empty($error)): ?><div class="err">⚠ <?= e($error) ?></div><?php endif; ?>

  <?php if (!empty($manualEnv)): ?>
    <div class="card">
      <h3>Almost done — save your config</h3>
      <p class="muted">The database is set up, but we couldn't write <code>.env</code> automatically. Create a file named <code>.env</code> in the project root with the contents below, then visit <a href="<?= u('/admin') ?>">/admin</a>.</p>
      <pre><?= e($manualEnv) ?></pre>
      <a class="btn" href="<?= u('/admin') ?>" style="display:block;text-align:center;text-decoration:none">I've saved it → Continue</a>
    </div>
  <?php else: ?>

  <div class="card">
    <h3>System checks</h3>
    <?php foreach ($checks as $c): ?>
      <div class="chk">
        <span class="b <?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '✓' : '✕' ?></span>
        <?= e($c['label']) ?><span class="d"><?= e($c['detail']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <form method="post" action="<?= u('/install') ?>">
    <div class="card">
      <h3>Database</h3>
      <div class="row">
        <div><label>Host</label><input name="db_host" value="<?= $v('db_host', '127.0.0.1') ?>"></div>
        <div><label>Port</label><input name="db_port" value="<?= $v('db_port', '3306') ?>"></div>
      </div>
      <label>Database name</label><input name="db_name" value="<?= $v('db_name') ?>" required>
      <div class="row">
        <div><label>User</label><input name="db_user" value="<?= $v('db_user') ?>" required></div>
        <div><label>Password</label><input name="db_pass" type="password" value="<?= $v('db_pass') ?>"></div>
      </div>
    </div>

    <div class="card">
      <h3>Site &amp; AI keys</h3>
      <label>Public URL</label><input name="app_url" value="<?= $v('app_url') ?>" placeholder="https://staging-dev.procurementhub.sa/chatbot">
      <label>Sub-directory path (optional)</label><input name="base_path" value="<?= $v('base_path') ?>" placeholder="/chatbot — leave blank if at a domain/subdomain root">
      <div class="muted">Set this ONLY if the app is served from a sub-folder (e.g. /chatbot). Leave blank if it's at the root of a domain or subdomain.</div>
      <div class="row">
        <div><label>Chat provider</label>
          <select name="chat_provider"><option value="gemini">Gemini</option><option value="openai">OpenAI</option><option value="anthropic">Anthropic</option></select></div>
        <div><label>Embedding provider</label>
          <select name="embedding_provider"><option value="openai">OpenAI</option><option value="gemini">Gemini</option></select></div>
      </div>
      <label>Gemini API key</label><input name="gemini_key" value="<?= $v('gemini_key') ?>">
      <label>OpenAI API key</label><input name="openai_key" value="<?= $v('openai_key') ?>">
      <label>Anthropic API key</label><input name="anthropic_key" value="<?= $v('anthropic_key') ?>">
      <div class="muted">You can add or change keys later in the admin. Embedding dimensions default to 1536 — set to match your vector index if using Pinecone.</div>
      <label>Embedding dimensions</label><input name="embedding_dims" value="<?= $v('embedding_dims', '1536') ?>">
    </div>

    <button class="btn" type="submit" <?= $ready ? '' : 'disabled title="Fix the failing checks first"' ?>>Install &amp; continue</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
