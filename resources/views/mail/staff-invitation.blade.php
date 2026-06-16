<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Приглашение в команду «{{ $marketName }}»</title>
</head>
<body style="margin:0;padding:0;background:#eef4fb;color:#132238;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#eef4fb;margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;border-collapse:separate;border-spacing:0;">
                    <tr>
                        <td align="center" style="padding:0 0 18px;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $marketName }}" width="180" style="display:block;max-width:180px;height:auto;border:0;outline:none;text-decoration:none;">
                            @else
                                <div style="display:inline-block;padding:10px 16px;border-radius:14px;background:#ffffff;border:1px solid #d8e4f2;color:#24446d;font-size:18px;font-weight:700;">
                                    {{ $marketName }}
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #d8e4f2;border-radius:18px;padding:34px 40px;box-shadow:0 10px 28px rgba(16,42,79,.08);">
                            <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#132238;font-weight:800;">Приглашение в команду «{{ $marketName }}»</h1>
                            <p style="margin:0 0 24px;font-size:17px;line-height:1.55;color:#51627a;">
                                Вас пригласили подключиться к сервису управления ярмаркой «{{ $marketName }}».
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 28px;">
                                <tr>
                                    <td align="center" bgcolor="#0ea5e9" style="border-radius:12px;">
                                        <a href="{{ $acceptUrl }}" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#0ea5e9;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;">
                                            Принять приглашение
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 12px;font-size:15px;line-height:1.55;color:#65758f;">
                                Если вы не ожидали это письмо, просто проигнорируйте его.
                            </p>
                            @if($expiresAt)
                                <p style="margin:0;font-size:15px;line-height:1.55;color:#65758f;">
                                    Ссылка действует до: <strong style="color:#132238;">{{ $expiresAt }}</strong>
                                </p>
                            @endif

                            <div style="height:1px;background:#dce6f2;margin:28px 0 18px;"></div>
                            <p style="margin:0;font-size:14px;line-height:1.55;color:#7a8aa3;">
                                С уважением,<br>
                                команда {{ $marketName }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 8px 0;text-align:center;color:#7a8aa3;font-size:12px;line-height:1.5;">
                            Если кнопка не открывается, скопируйте ссылку в браузер:<br>
                            <a href="{{ $acceptUrl }}" style="color:#2563eb;text-decoration:underline;word-break:break-all;">{{ $acceptUrl }}</a>
                            <br><br>
                            © {{ now()->year }} {{ $marketName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
