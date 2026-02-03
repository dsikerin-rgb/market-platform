<x-cabinet-layout :tenant="$tenant" title="Главная">
    <div class="grid gap-4">
        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <p class="text-xs uppercase tracking-wide text-slate-400">Текущий долг</p>
            <div class="mt-2 text-2xl font-semibold">
                {{ number_format($totalDebt, 0, '.', ' ') }} ₽
            </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <p class="text-xs uppercase tracking-wide text-slate-400">Начисления за месяц</p>
            <div class="mt-2 text-2xl font-semibold">
                {{ number_format($monthAccruals, 0, '.', ' ') }} ₽
            </div>
            @if($latestPeriod)
                <p class="mt-1 text-xs text-slate-500">Период: {{ \Illuminate\Support\Carbon::parse($latestPeriod)->format('m.Y') }}</p>
            @endif
        </div>
    </div>

    <div class="grid gap-3">
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.accruals') }}">
            <div>
                <p class="text-sm font-medium">Начисления</p>
                <p class="text-xs text-slate-500">История начислений и заглушка оплаты</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.requests') }}">
            <div>
                <p class="text-sm font-medium">Мои заявки</p>
                <p class="text-xs text-slate-500">Создавайте обращения и ведите диалог</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.documents') }}">
            <div>
                <p class="text-sm font-medium">Документы</p>
                <p class="text-xs text-slate-500">Договоры, акты и прочее</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.spaces') }}">
            <div>
                <p class="text-sm font-medium">Торговые места</p>
                <p class="text-xs text-slate-500">Ваши точки и договор аренды</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.customer-chat') }}">
            <div>
                <p class="text-sm font-medium">Чат с покупателями</p>
                <p class="text-xs text-slate-500">Демо-экран с примером диалога</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
        <a class="bg-white rounded-2xl p-4 border shadow-sm flex items-center justify-between" href="{{ route('cabinet.showcase.edit') }}">
            <div>
                <p class="text-sm font-medium">Моя витрина</p>
                <p class="text-xs text-slate-500">Визитка арендатора и 5 фото</p>
            </div>
            <span class="text-slate-400">→</span>
        </a>
    </div>
</x-cabinet-layout>
