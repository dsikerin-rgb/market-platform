<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Приглашение недоступно</title>
    <style>
        body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7fb;color:#111827}
        .page{min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{width:min(100%,420px);background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;box-shadow:0 16px 48px rgba(15,23,42,.08)}
        h1{margin:0 0 12px;font-size:24px;line-height:1.2}
        p{margin:0;color:#4b5563;line-height:1.5}
        a{display:inline-block;margin-top:18px;color:#2563eb;font-weight:700;text-decoration:none}
    </style>
</head>
<body>
<main class="page">
    <section class="card">
        <h1>Приглашение недоступно</h1>
        <p>{{ $message }}</p>
        <a href="/admin/login">Перейти ко входу</a>
    </section>
</main>
</body>
</html>
