<?php /** @var string $content @var string $title @var string $active */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Admin') ?> · support-ai</title>
<style>
  :root{
    --bg:#f6f7fb; --surface:#fff; --ink:#0f172a; --muted:#64748b; --line:#e8ebf1;
    --primary:#4f46e5; --accent:#7c3aed; --ok:#16a34a; --warn:#d97706; --danger:#dc2626;
    --radius:14px; --shadow:0 1px 3px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.05);
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  a{color:inherit;text-decoration:none}
  .app{display:grid;grid-template-columns:250px 1fr;min-height:100vh}

  /* Sidebar */
  .side{background:#0b1020;color:#c7cede;padding:22px 16px;display:flex;flex-direction:column;gap:6px}
  .brand{display:flex;align-items:center;gap:10px;padding:8px 10px 20px;font-weight:700;font-size:17px;color:#fff}
  .brand .logo{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--primary),var(--accent));
       display:flex;align-items:center;justify-content:center;font-size:17px}
  .nav a{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:10px;color:#aab2c5;font-weight:500;font-size:14px}
  .nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .nav a.on{background:linear-gradient(135deg,rgba(79,70,229,.9),rgba(124,58,237,.9));color:#fff}
  .nav .ic{width:18px;text-align:center}
  .side .foot{margin-top:auto;font-size:12px;color:#6b7488;padding:12px 10px}
  .side .foot a{color:#93c5fd}

  /* Main */
  .main{padding:0}
  .topbar{background:var(--surface);border-bottom:1px solid var(--line);padding:16px 32px;
          display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:5}
  .topbar h1{margin:0;font-size:19px;font-weight:650}
  .content{padding:28px 32px;max-width:1120px}

  /* Reusable */
  .grid{display:grid;gap:18px}
  .cards{grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px}
  .card h3{margin:0 0 14px;font-size:14px;font-weight:600}
  .stat .label{color:var(--muted);font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px}
  .stat .value{font-size:28px;font-weight:700;margin-top:8px;letter-spacing:-.02em}
  .stat .sub{font-size:12px;color:var(--muted);margin-top:4px}
  .pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px}
  .pill.ok{background:#dcfce7;color:#166534}.pill.warn{background:#fef3c7;color:#92400e}
  .pill.info{background:#e0e7ff;color:#3730a3}.pill.mut{background:#f1f5f9;color:#475569}
  .bar{height:9px;border-radius:6px;background:#eef1f6;overflow:hidden;margin-top:12px}
  .bar>span{display:block;height:100%;background:linear-gradient(90deg,var(--primary),var(--accent))}

  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--line)}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
  tr:last-child td{border-bottom:0}

  form.stack{display:grid;gap:16px;max-width:640px}
  .field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px}
  .field .hint{color:var(--muted);font-size:12px;margin-top:5px}
  input[type=text],input[type=email],input[type=number],input[type=password],textarea,select{
    width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;
    font-family:inherit;background:#fff;outline:none;transition:border .15s,box-shadow .15s}
  input:focus,textarea:focus,select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
  textarea{min-height:110px;resize:vertical}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:10px;padding:11px 18px;font-size:14px;
       font-weight:600;cursor:pointer;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff}
  .btn:hover{opacity:.94}
  .btn.ghost{background:#fff;border:1px solid var(--line);color:var(--ink)}
  .notice{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 16px;border-radius:10px;font-size:14px}
  .empty{text-align:center;color:var(--muted);padding:48px 20px}
  .empty .big{font-size:34px;margin-bottom:10px}
  code{background:#f1f5f9;border:1px solid var(--line);border-radius:6px;padding:2px 7px;font-size:13px}
  @media(max-width:860px){.app{grid-template-columns:1fr}.side{display:none}}
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><span class="logo">◆</span> support-ai</div>
    <nav class="nav">
      <?php
        $nav = [
          'dashboard'     => ['/admin', '▊', 'Dashboard'],
          'agent'         => ['/admin/agent', '⚙', 'Agent'],
          'knowledge'     => ['/admin/knowledge', '📚', 'Knowledge'],
          'conversations' => ['/admin/conversations', '💬', 'Conversations'],
          'costs'         => ['/admin/costs', '📈', 'Cost & usage'],
          'privacy'       => ['/admin/privacy', '🔒', 'Privacy & form'],
        ];
        foreach ($nav as $key => [$href, $icon, $label]):
      ?>
        <a href="<?= $href ?>" class="<?= ($active ?? '') === $key ? 'on' : '' ?>">
          <span class="ic"><?= $icon ?></span> <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="foot">
      v0.1 · <a href="/admin/logout">Sign out</a>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1><?= e($title ?? '') ?></h1>
      <a class="btn ghost" href="/demo" target="_blank">Preview widget ↗</a>
    </div>
    <div class="content"><?= $content ?></div>
  </main>
</div>
</body>
</html>
