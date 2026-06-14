<x-filament-panels::page>
    @php($status = $this->mailStatus())

    <div style="display:grid; gap:16px; max-width:900px;">
        <x-filament::section
            heading="Почтовая конфигурация"
            description="Текущие значения берутся из config/mail.php и переменных MAIL_* окружения."
        >
            <dl style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                @foreach ($status as $label => $value)
                    <div style="padding:12px; border:1px solid rgba(148,163,184,.25); border-radius:8px;">
                        <dt style="font-size:.75rem; color:#64748b; text-transform:uppercase;">{{ $label }}</dt>
                        <dd style="margin-top:4px; font-weight:600; word-break:break-word;">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section
            heading="Smoke-test"
            description="Используйте кнопку вверху страницы или команду в терминале."
        >
            <div style="padding:12px; border-radius:8px; background:rgba(15,23,42,.04);">
                <code>php artisan mail:smoke-test admin@example.com</code>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
