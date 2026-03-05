<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Маркетплейс не инициализирован</title>
    <style>
        body { margin: 0; background: #f3f6fb; font-family: "Segoe UI", Arial, sans-serif; color: #11203b; }
        .box {
            max-width: 860px;
            margin: 48px auto;
            background: #fff;
            border: 1px solid #d7e6f7;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 8px 30px rgba(14, 44, 84, .08);
        }
        h1 { margin: 0 0 10px; font-size: 32px; }
        p { color: #526886; line-height: 1.6; }
        code, pre {
            background: #f6f9ff;
            border: 1px solid #dce8f7;
            border-radius: 12px;
            padding: 2px 6px;
            font-family: Consolas, "Courier New", monospace;
        }
        pre { display: block; padding: 12px; overflow: auto; }
        ul { color: #314e71; }
    </style>
</head>
<body>
<div class="box">
    <h1>Маркетплейс не инициализирован</h1>
    <p>Отсутствуют служебные таблицы маркетплейса. Нужно один раз применить миграции и загрузить стартовые данные.</p>

    <h3>Отсутствующие таблицы:</h3>
    <ul>
        @foreach($missingTables as $table)
            <li><code>{{ $table }}</code></li>
        @endforeach
    </ul>

    <h3>Команды инициализации:</h3>
    <pre>php artisan migrate --force
php artisan marketplace:bootstrap --refresh-announcements --seed-products=60 --force
php artisan optimize:clear</pre>
</div>
</body>
</html>

