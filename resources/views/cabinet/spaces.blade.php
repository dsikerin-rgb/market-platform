<x-cabinet-layout :tenant="$tenant" title="Торговые места">
    @if(!empty($canManageStaff))
        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <h2 class="text-base font-semibold text-slate-900">Добавить сотрудника</h2>
            <p class="text-xs text-slate-500">Сотрудник получит доступ к выбранным торговым местам.</p>

            <form method="POST" action="{{ route('cabinet.spaces.staff.store') }}" class="space-y-3">
                @csrf

                <div>
                    <label class="block text-sm text-slate-700 mb-1">Имя сотрудника</label>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        maxlength="255"
                        class="w-full rounded-xl border border-sky-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-300"
                        placeholder="Например: Продавец 1"
                    >
                </div>

                <div>
                    <label class="block text-sm text-slate-700 mb-1">Логин (email)</label>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        maxlength="255"
                        class="w-full rounded-xl border border-sky-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-300"
                        placeholder="employee@example.com"
                    >
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-700 mb-1">Пароль</label>
                        <input
                            type="password"
                            name="password"
                            required
                            minlength="8"
                            maxlength="255"
                            class="w-full rounded-xl border border-sky-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-300"
                        >
                    </div>
                    <div>
                        <label class="block text-sm text-slate-700 mb-1">Подтверждение пароля</label>
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                            minlength="8"
                            maxlength="255"
                            class="w-full rounded-xl border border-sky-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-300"
                        >
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-slate-700 mb-1">Торговые места</label>
                    <div class="grid grid-cols-1 gap-2">
                        @foreach($spaces as $space)
                            @php
                                $spaceId = (int) $space->id;
                                $oldSpaceIds = collect(old('space_ids', []))->map(fn ($id) => (int) $id)->all();
                                $checked = in_array($spaceId, $oldSpaceIds, true);
                                $spaceLabel = trim((string) ($space->number ?? $space->name ?? ('#' . $spaceId)));
                            @endphp
                            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                <input type="checkbox" name="space_ids[]" value="{{ $spaceId }}" @checked($checked) class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-400">
                                <span>{{ $spaceLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center rounded-xl border border-sky-600 bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700"
                >
                    Добавить сотрудника
                </button>
            </form>
        </section>
    @endif

    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-2">
        <h2 class="text-base font-semibold text-slate-900">Договор аренды</h2>
        @if($contract)
            <div class="text-sm text-slate-600 space-y-1">
                <p>Номер: <strong>{{ $contract->number ?? '—' }}</strong></p>
                <p>Срок: {{ $contract->starts_at?->format('d.m.Y') ?? '—' }} — {{ $contract->ends_at?->format('d.m.Y') ?? '—' }}</p>
                <p>Статус: {{ $contract->status ?? '—' }}</p>
            </div>
        @else
            <p class="text-sm text-slate-500">
                Данные договора пока не загружены. Можно использовать раздел «Документы» для просмотра договора.
            </p>
        @endif
    </section>

    <section class="space-y-3">
        @forelse($spaces as $space)
            <article class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-900">{{ $space->number ?? $space->name ?? 'Торговое место' }}</p>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-xl bg-slate-50 px-2.5 py-2 text-slate-600">
                        <p class="text-slate-400">Площадь</p>
                        <p class="font-semibold text-slate-800">{{ $space->area_sqm ?? '—' }} м²</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-2.5 py-2 text-slate-600">
                        <p class="text-slate-400">Ставка</p>
                        <p class="font-semibold text-slate-800">{{ $space->rent_rate_value ? number_format((float) $space->rent_rate_value, 0, '.', ' ') . ' ₽' : '—' }}</p>
                    </div>
                </div>

                @php
                    $staff = collect($spaceStaffMap[(int) $space->id] ?? []);
                @endphp
                <div class="mt-3 rounded-2xl border border-sky-200 bg-sky-50/50 p-3">
                    <p class="text-xs font-semibold text-slate-700">Сотрудники по этому месту</p>
                    @if($staff->isEmpty())
                        <p class="mt-1 text-xs text-slate-500">Назначенных сотрудников пока нет.</p>
                    @else
                        <div class="mt-2 space-y-1.5">
                            @foreach($staff as $member)
                                <div class="rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs text-slate-700">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $member['name'] }}</span>
                                        @if(!empty($member['telegram_linked']))
                                            <span class="inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                Telegram подключен
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">
                                                Telegram не подключен
                                            </span>
                                        @endif
                                    </div>
                                    @if(!empty($member['email']))
                                        <div class="mt-1 text-[11px] text-slate-500">{{ $member['email'] }}</div>
                                    @endif
                                    @if(!empty($member['telegram_username']))
                                        <div class="mt-1 text-[11px] text-sky-700">{{ '@' . ltrim((string) $member['telegram_username'], '@') }}</div>
                                    @endif
                                    @if(!empty($member['all_spaces']))
                                        <span class="ml-1 text-[11px] text-sky-700">(все места)</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-6 text-sm text-slate-500">
                Торговых мест пока нет.
            </div>
        @endforelse
    </section>
</x-cabinet-layout>
