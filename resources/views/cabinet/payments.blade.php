<x-cabinet-layout :tenant="auth()->user()->tenant" title="Оплата">
    <section class="rounded-3xl bg-white border border-slate-200 p-5 shadow-sm space-y-4">
        <div class="h-12 w-12 rounded-2xl bg-slate-900 text-white flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m3 0h6M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z" />
            </svg>
        </div>

        <div>
            <h2 class="text-lg font-semibold text-slate-900">Онлайн-оплата скоро появится</h2>
            <p class="mt-2 text-sm text-slate-600">
                Здесь будет оплата через СБП и эквайринг. Сейчас используйте экран начислений и документы для сверки.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <a class="rounded-2xl bg-slate-900 text-white text-center px-4 py-3 text-sm font-semibold" href="{{ route('cabinet.accruals') }}">К начислениям</a>
            <a class="rounded-2xl border border-slate-300 bg-white text-center px-4 py-3 text-sm font-semibold text-slate-700" href="{{ route('cabinet.documents') }}">Документы</a>
        </div>
    </section>
</x-cabinet-layout>
