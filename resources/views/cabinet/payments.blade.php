<x-cabinet-layout :tenant="auth()->user()->tenant" title="Оплата">
    <div class="bg-white rounded-2xl p-6 border shadow-sm space-y-3 text-center">
        <h2 class="text-lg font-semibold">Оплата будет доступна позже</h2>
        <p class="text-sm text-slate-500">Здесь появится оплата через СБП/эквайринг. Пока можно посмотреть начисления и скачать документы.</p>
        <a class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-4 py-2 text-sm" href="{{ route('cabinet.accruals') }}">Вернуться к начислениям</a>
    </div>
</x-cabinet-layout>
