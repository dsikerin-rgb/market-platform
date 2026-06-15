<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Приглашение в Market Platform</title>
    <style>
        body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7fb;color:#111827}
        .page{min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{width:min(100%,440px);background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;box-shadow:0 16px 48px rgba(15,23,42,.08)}
        h1{margin:0 0 12px;font-size:24px;line-height:1.2}
        p{margin:0 0 18px;color:#4b5563;line-height:1.5}
        label{display:block;margin:14px 0 6px;font-size:14px;font-weight:600}
        input{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:10px;padding:11px 12px;font:inherit}
        button{width:100%;margin-top:18px;border:0;border-radius:10px;background:#2563eb;color:#fff;padding:12px 14px;font:inherit;font-weight:700;cursor:pointer}
        .error{margin:8px 0 0;color:#b91c1c;font-size:13px}
        .meta{padding:12px;border-radius:10px;background:#f3f4f6;color:#374151;font-size:14px}
    </style>
</head>
<body>
<main class="page">
    <section class="card">
        <h1>Приглашение в Market Platform</h1>
        <p>Вы приглашены в команду рынка {{ $invitation->market?->name ?? 'Market Platform' }}.</p>

        <div class="meta">
            Email: {{ $invitation->email }}
        </div>

        <form method="post">
            @csrf

            @if ($existingUser)
                <p style="margin-top:18px">Пользователь с этим email уже существует. Нажмите кнопку ниже, чтобы принять приглашение.</p>
            @else
                <label for="name">Имя</label>
                <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                @error('name')
                    <div class="error">{{ $message }}</div>
                @enderror

                <label for="phone">Телефон <span style="font-weight:400;color:#6b7280">(необязательно)</span></label>
                <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" placeholder="+7 900 000-00-00">
                @error('phone')
                    <div class="error">{{ $message }}</div>
                @enderror

                <label for="password">Пароль</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror

                <label for="password_confirmation">Повторите пароль</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            @endif

            <button type="submit">Принять приглашение</button>
        </form>
    </section>
</main>
</body>
</html>
