<?php /** @var bool $firstRun @var ?string $error */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $firstRun ? 'Create owner account' : 'Sign in' ?> · support-ai</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;
       background:radial-gradient(1200px 600px at 20% -10%,#e0e7ff,transparent),
                  radial-gradient(1000px 500px at 100% 110%,#f3e8ff,transparent),#f6f7fb}
  .box{background:#fff;border:1px solid #e8ebf1;border-radius:18px;padding:36px 34px;width:380px;
       box-shadow:0 20px 50px rgba(15,23,42,.12)}
  .logo{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4f46e5,#7c3aed);
        display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin-bottom:18px}
  h1{font-size:21px;margin:0 0 6px}p.sub{color:#64748b;margin:0 0 24px;font-size:14px}
  label{display:block;font-weight:600;font-size:13px;margin:14px 0 6px}
  input{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}
  input:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
  .btn{width:100%;margin-top:22px;border:0;border-radius:10px;padding:12px;font-size:15px;font-weight:600;
       cursor:pointer;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 13px;border-radius:9px;font-size:13px;margin-bottom:16px}
  .hint{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 13px;border-radius:9px;font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
  <form class="box" method="post" action="/admin/login">
    <div class="logo">◆</div>
    <h1><?= $firstRun ? 'Create your account' : 'Welcome back' ?></h1>
    <p class="sub"><?= $firstRun ? 'Set up the owner login for this install.' : 'Sign in to the support-ai admin.' ?></p>

    <?php if (!empty($error)): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <?php if ($firstRun): ?><div class="hint">First run detected — the credentials you enter become the owner account.</div><?php endif; ?>

    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus placeholder="you@example.com">

    <label for="password">Password</label>
    <input id="password" name="password" type="password" required placeholder="<?= $firstRun ? 'At least 8 characters' : '••••••••' ?>">

    <button class="btn" type="submit"><?= $firstRun ? 'Create account' : 'Sign in' ?></button>
  </form>
</body>
</html>
