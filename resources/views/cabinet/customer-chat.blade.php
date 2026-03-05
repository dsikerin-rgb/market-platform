<x-cabinet-layout :tenant="$tenant" title="Общение с покупателями">
    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
        <div class="grid gap-4 md:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="rounded-2xl border border-slate-200 bg-slate-50 p-3 space-y-2 max-h-[70vh] overflow-auto">
                <h2 class="text-sm font-semibold text-slate-900 mb-2">Диалоги</h2>
                @forelse($chats as $chat)
                    @php
                        $isActive = (int) ($activeChat?->id ?? 0) === (int) $chat->id;
                        $spaceLabel = trim((string) ($chat->marketSpace?->display_name ?: ($chat->marketSpace?->number ?: $chat->marketSpace?->code)));
                    @endphp
                    <a href="{{ route('cabinet.customer-chat', ['chat' => $chat->id]) }}"
                       class="block rounded-xl border px-3 py-2 text-sm transition {{ $isActive ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-white hover:border-sky-300' }}">
                        <div class="font-semibold text-slate-900">
                            {{ $chat->buyer?->name ?: 'Покупатель' }}
                        </div>
                        <div class="text-slate-600 text-xs mt-0.5">
                            {{ $chat->subject ?: 'Диалог с покупателем' }}
                        </div>
                        <div class="text-slate-500 text-xs mt-1 flex items-center justify-between gap-2">
                            <span>{{ optional($chat->last_message_at)->format('d.m H:i') }}</span>
                            @if((int) $chat->tenant_unread_count > 0)
                                <span class="inline-flex items-center rounded-full bg-sky-100 text-sky-700 px-2 py-0.5 font-medium">
                                    {{ (int) $chat->tenant_unread_count }}
                                </span>
                            @endif
                        </div>
                        @if($spaceLabel !== '')
                            <div class="text-[11px] text-slate-500 mt-1">Место: {{ $spaceLabel }}</div>
                        @endif
                    </a>
                @empty
                    <div class="text-sm text-slate-500">Сообщений от покупателей пока нет.</div>
                @endforelse
            </aside>

            <div class="rounded-2xl border border-slate-200 bg-white p-3 flex flex-col">
                @if($activeChat)
                    <header class="pb-3 border-b border-slate-200">
                        <div class="text-sm font-semibold text-slate-900">
                            {{ $activeChat->buyer?->name ?: 'Покупатель' }}
                        </div>
                        <div class="text-xs text-slate-500 mt-0.5">
                            {{ $activeChat->subject ?: 'Диалог с покупателем' }}
                        </div>
                    </header>

                    <div class="py-3 space-y-2 max-h-[48vh] overflow-auto">
                        @forelse($activeMessages as $message)
                            @php $isTenant = (string) $message->sender_type === 'tenant'; @endphp
                            <div class="flex {{ $isTenant ? 'justify-end' : 'justify-start' }}">
                                <article class="max-w-[85%] rounded-2xl px-3 py-2 text-sm {{ $isTenant ? 'bg-sky-600 text-white' : 'bg-slate-100 border border-slate-200 text-slate-800' }}">
                                    <div class="text-[11px] {{ $isTenant ? 'text-sky-100' : 'text-slate-500' }}">
                                        {{ $isTenant ? 'Вы' : ($activeChat->buyer?->name ?: 'Покупатель') }}
                                        · {{ optional($message->created_at)->format('d.m.Y H:i') }}
                                    </div>
                                    <div class="mt-1 whitespace-pre-wrap">{{ $message->body }}</div>
                                </article>
                            </div>
                        @empty
                            <div class="text-sm text-slate-500">В этом диалоге пока нет сообщений.</div>
                        @endforelse
                    </div>

                    <form action="{{ route('cabinet.customer-chat.send', ['chatId' => $activeChat->id]) }}" method="post" class="mt-2">
                        @csrf
                        <label class="block text-xs text-slate-500 mb-1">Ответ покупателю</label>
                        <div class="flex gap-2 items-end">
                            <textarea name="message" rows="3" required placeholder="Введите сообщение..."
                                      class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-sky-400 focus:ring-sky-400"></textarea>
                            <button type="submit"
                                    class="inline-flex items-center rounded-xl bg-sky-600 text-white px-4 py-2 text-sm font-semibold hover:bg-sky-700">
                                Отправить
                            </button>
                        </div>
                    </form>
                @else
                    <div class="text-sm text-slate-500">Выберите диалог слева, чтобы открыть переписку.</div>
                @endif
            </div>
        </div>
    </section>
</x-cabinet-layout>

